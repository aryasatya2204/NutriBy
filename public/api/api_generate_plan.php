<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/kalkulator-gizi.php';
require_once __DIR__ . '/../../includes/summary-generator.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_child_id'])) {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak. Sesi tidak ditemukan.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$child_id = $_SESSION['active_child_id'];

// Ambil data kustom dari request, berikan nilai default jika tidak ada
$target_weekly_budget = $input['target_budget'] ?? 0;
$allergies = $input['allergies'] ?? [];
$preferences = $input['preferences'] ?? [];

try {
    // 1. Mengambil profil lengkap anak (tidak berubah)
    $stmt_profile = $pdo->prepare("
        SELECT u.gaji, c.gender, c.birth_date, gr.weight, gr.height
        FROM children c
        JOIN users u ON c.user_id = u.id
        JOIN growth_records gr ON c.id = gr.child_id
        WHERE c.id = ? ORDER BY gr.recorded_at DESC, gr.id DESC LIMIT 1
    ");
    $stmt_profile->execute([$child_id]);
    $profile = $stmt_profile->fetch(PDO::FETCH_ASSOC);

    if (!$profile) throw new Exception('Profil anak tidak ditemukan.');

    $age_in_months = (new DateTime($profile['birth_date']))->diff(new DateTime('today'))->y * 12 + (new DateTime($profile['birth_date']))->diff(new DateTime('today'))->m;

    // 2. Kalkulasi Gizi menggunakan Logika Baru
    $calculator = new WHONutritionCalculatorRevised();
    $gender_for_calc = ($profile['gender'] === 'laki-laki') ? 'boys' : 'girls';
    $calc_result = $calculator->calculateNutritionalStatus($gender_for_calc, $age_in_months, $profile['weight'], $profile['height']);
    if ($calc_result['is_special_case']) {
         throw new Exception('Status gizi anak memerlukan perhatian medis segera, perencanaan otomatis tidak dapat dilanjutkan.');
    }
    $nutrition_summary = generateNutritionSummary($calc_result['results_code']);
    
    // Tentukan tujuan gizi (menggunakan logika dari rule-engine.php)
    $gaji = (int)$profile['gaji'];
    $summary_text = $nutrition_summary['parent_summary'];
    $nutrition_tags = [];
    if ($summary_text === 'Malnutrisi Berat' || $summary_text === 'Risiko Gizi Kurang') {
        $nutrition_tags = ['penambah_berat_badan', 'tinggi_protein', 'tinggi_kalori', 'sumber_zat_besi'];
    } elseif ($summary_text === 'Gizi Lebih' || $summary_text === 'Obesitas / Gizi Lebih Parah') {
        $nutrition_tags = ['seimbang', 'rendah_lemak', 'variatif'];
    } else {
        $nutrition_tags = ['seimbang', 'variatif'];
    }

    // 3. Penentuan Budget & Filtering Menu
    $gaji = (int)$profile['gaji'];
    $budget_category_target = 'Sedang';
    if ($gaji < 1000000) $budget_category_target = 'Murah';
    elseif ($gaji > 5000000) $budget_category_target = 'Mahal';

    $stmt_menus = $pdo->query("SELECT * FROM menus");
    $all_menus = $stmt_menus->fetchAll(PDO::FETCH_ASSOC);

    $filtered_menus = [];
    foreach ($all_menus as $menu) {
        $age_range = explode('-', preg_replace('/[^0-9-]/', '', $menu['age_group']));
        if (count($age_range) !== 2 || $age_in_months < $age_range[0] || $age_in_months > $age_range[1]) continue;
        $menu_allergens = json_decode($menu['allergens'], true) ?: [];
        if (count(array_intersect($menu_allergens, $allergies)) > 0) continue;
        $filtered_menus[] = $menu;
    }

    // 4. Scoring Menu (sedikit modifikasi)
    $scored_menus = [];
    foreach ($filtered_menus as $menu) {
        $score = 1; // Skor dasar
        $tags = json_decode($menu['tags'], true) ?: [];
        if (count(array_intersect($tags, $nutrition_tags)) > 0) $score += 10;
        // Skor preferensi
        foreach ($preferences as $pref) {
            if (stripos(json_encode($menu['ingredients']), $pref) !== false) { $score += 2; break; }
        }
        $menu['final_score'] = $score;
        $scored_menus[] = $menu;
    }

    if (empty($scored_menus)) {
        throw new Exception('Sistem tidak menemukan menu yang cocok dengan profil anak Anda.');
    }

    // [PERUBAHAN KUNCI 1] Sorting berdasarkan skor tertinggi, lalu harga termurah
    usort($scored_menus, function($a, $b) {
        if ($b['final_score'] == $a['final_score']) {
            return $a['estimated_cost'] <=> $b['estimated_cost']; // Harga termurah didahulukan
        }
        return $b['final_score'] <=> $a['final_score']; // Skor tertinggi didahulukan
    });

    // [PERUBAHAN KUNCI 2] Susun Rencana dengan Algoritma Iteratif (Greedy)
    $plan = [];
    $current_total_cost = 0;
    $used_menu_ids = [];
    $portions_needed = 21; // 3 porsi x 7 hari

    for ($i = 0; $i < $portions_needed; $i++) {
        $menu_found_for_this_portion = false;
        foreach ($scored_menus as $menu) {
            // Cek apakah menu bisa ditambahkan
            $can_add = true;
            // Syarat 1: Biaya tidak melebihi budget
            if (($current_total_cost + $menu['estimated_cost']) > $target_weekly_budget) {
                $can_add = false;
            }
            // Syarat 2: Aturan variasi sederhana (tidak sama dengan menu sebelumnya)
            if (isset($plan[$i-1]) && $plan[$i-1]['id'] == $menu['id']) {
                $can_add = false;
            }
            
            if ($can_add) {
                $plan[] = $menu;
                $current_total_cost += $menu['estimated_cost'];
                $menu_found_for_this_portion = true;
                break; // Lanjut ke porsi makan berikutnya
            }
        }
        // Jika setelah loop semua menu tidak ada yang bisa ditambahkan, berarti gagal
        if (!$menu_found_for_this_portion) {
            throw new Exception('budget_unrealistic');
        }
    }

// Langkah 6: Simpan ke Database
$pdo->beginTransaction();
$stmt_delete = $pdo->prepare("DELETE FROM mpasi_plans WHERE child_id = ?");
$stmt_delete->execute([$child_id]);

$start_date = date('Y-m-d');
$total_weekly_cost = array_sum(array_column($plan, 'estimated_cost'));
$stmt_plan = $pdo->prepare("INSERT INTO mpasi_plans (child_id, week_start_date, budget_category, total_estimated_cost, generated_by) VALUES (?, ?, ?, ?, ?)");
// Ambil budget_category dari hasil scoring
$budget_category_final = $scored_menus[0]['budget_category'] ?? 'Standar';
$stmt_plan->execute([$child_id, $start_date, $budget_category_final, $total_weekly_cost, 'rule']);
$plan_id = $pdo->lastInsertId();

$days_of_week = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$meal_times = ['Sarapan', 'Makan Siang', 'Makan Malam'];
$stmt_item = $pdo->prepare("INSERT INTO mpasi_items (mpasi_plan_id, day, meal_time, menu_id, estimated_cost) VALUES (?, ?, ?, ?, ?)");

// PERBAIKAN: Gunakan tanggal dinamis untuk menentukan hari
$current_day_index = (new DateTime())->format('N') - 1; // 0=Senin, 6=Minggu

foreach ($plan as $index => $menu) {
    // Tentukan hari berdasarkan urutan, mulai dari hari ini
    $day_name = $days_of_week[($current_day_index + floor($index / 3)) % 7];
    $meal_time = $meal_times[$index % 3];
    $stmt_item->execute([$plan_id, $day_name, $meal_time, $menu['id'], $menu['estimated_cost']]);
}

$pdo->commit();
    // 7. Kirim Hasil ke Frontend
    echo json_encode(['success' => true, 'plan' => $plan, 'total_cost' => $total_weekly_cost, 'start_date' => $start_date]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getMessage() === 'budget_unrealistic') {
        $formatted_budget = 'Rp ' . number_format($target_weekly_budget, 0, ',', '.');
        echo json_encode(['success' => false, 'error' => 'budget_unrealistic', 'message' => "Budget ($formatted_budget) tidak realistis dengan perencanaan MPASI"]);
    } else {
        echo json_encode(['success' => false, 'error' => 'general', 'message' => $e->getMessage()]);
    }
    exit();
}
?>
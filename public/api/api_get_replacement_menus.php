<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_child_id'])) {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
    exit();
}

// Menerima parameter dari GET request
$child_id = $_SESSION['active_child_id'];
$max_cost = filter_input(INPUT_GET, 'max_cost', FILTER_VALIDATE_INT, ['options' => ['default' => 999999]]);
$current_menu_id = filter_input(INPUT_GET, 'current_menu_id', FILTER_VALIDATE_INT);
$existing_plan_menu_ids = isset($_GET['existing_ids']) ? explode(',', $_GET['existing_ids']) : [];

// Validasi input dasar
if (!$current_menu_id) {
    echo json_encode(['success' => false, 'error' => 'ID menu saat ini tidak valid.']);
    exit();
}

try {
    // LANGKAH 1: KUMPULKAN PROFIL ANAK (Usia dan Alergi)
    $stmt_child = $pdo->prepare("SELECT birth_date FROM children WHERE id = ?");
    $stmt_child->execute([$child_id]);
    $child_data = $stmt_child->fetch(PDO::FETCH_ASSOC);

    // [PERBAIKAN] Tambahkan validasi untuk birth_date sebelum digunakan
    if (!$child_data || empty($child_data['birth_date'])) {
        // Jangan biarkan script crash, kirim pesan error yang jelas
        throw new Exception("Data tanggal lahir anak tidak ditemukan atau tidak valid. Tidak dapat merekomendasikan menu.");
    }
    
    // Baris ini sekarang aman karena sudah divalidasi
    $age_in_months = (new DateTime($child_data['birth_date']))->diff(new DateTime('today'))->y * 12 + (new DateTime($child_data['birth_date']))->diff(new DateTime('today'))->m;

    $stmt_allergies = $pdo->prepare("SELECT food_name FROM allergies WHERE child_id = ?");
    $stmt_allergies->execute([$child_id]);
    $allergies = $stmt_allergies->fetchAll(PDO::FETCH_COLUMN);

    // LANGKAH 2: AMBIL SEMUA MENU DARI DATABASE
    $stmt_menus = $pdo->query("SELECT * FROM menus");
    $all_menus = $stmt_menus->fetchAll(PDO::FETCH_ASSOC);

    // LANGKAH 3: FILTER MENU BERDASARKAN ATURAN YANG DITETAPKAN
    $replacement_options = [];
    foreach ($all_menus as $menu) {
        // Aturan 1: Jangan tampilkan menu yang sedang diedit sebagai pengganti dirinya sendiri
        if ($menu['id'] == $current_menu_id) {
            continue;
        }

        // Aturan 2: Harga harus lebih rendah atau sama dengan
        if ($menu['estimated_cost'] > $max_cost) {
            continue;
        }

        // Aturan 3: Rentang usia harus sesuai
        $age_range = explode('-', preg_replace('/[^0-9-]/', '', $menu['age_group']));
        if (count($age_range) !== 2 || $age_in_months < $age_range[0] || $age_in_months > $age_range[1]) {
            continue;
        }

        // Aturan 4: Tidak boleh mengandung alergen
        $menu_allergens = json_decode($menu['allergens'], true) ?: [];
        if (count(array_intersect($menu_allergens, $allergies)) > 0) {
            continue;
        }

        // Jika lolos semua filter, tambahkan ke daftar opsi
        $replacement_options[] = $menu;
    }

    // LANGKAH 4: SORTING DAN PEMBERIAN LABEL (BONUS)
    usort($replacement_options, function ($a, $b) {
        return $a['estimated_cost'] <=> $b['estimated_cost'];
    });

    $final_menus = array_slice($replacement_options, 0, 10);

    echo json_encode(['success' => true, 'menus' => $final_menus]);

} catch (Exception $e) {
    // Blok catch ini sekarang akan menangani error validasi kita
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
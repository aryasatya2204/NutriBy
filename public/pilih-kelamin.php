<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/kalkulator-gizi.php';
require_once __DIR__ . '/../includes/summary-generator.php';
require_once __DIR__ . '/../includes/rule-engine.php';

// Validasi alur pendaftaran
if (!isset($_SESSION['registration_user_id']) || !isset($_SESSION['registration_child_id'])) {
    header('Location: signup.php');
    exit();
}

$user_id_reg = $_SESSION['registration_user_id'];
$child_id_reg = $_SESSION['registration_child_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gender'])) {
    $gender_input = $_POST['gender'];

   // Ganti blok ini di pilih-kelamin.php
try {
    $pdo->beginTransaction();

    $stmt_update = $pdo->prepare("UPDATE children SET gender = ? WHERE id = ?");
    $stmt_update->execute([$gender_input, $child_id_reg]);
    
     // =============================================================
        // [LOGIKA BARU DIMULAI DI SINI]
        // =============================================================

        // 2. Ambil data awal anak untuk kalkulasi
        $stmt_initial_data = $pdo->prepare("
            SELECT c.birth_date, u.gaji, gr.weight, gr.height
            FROM children c
            JOIN users u ON c.user_id = u.id
            JOIN growth_records gr ON c.id = gr.child_id
            WHERE c.id = ? ORDER BY gr.id DESC LIMIT 1
        ");
        $stmt_initial_data->execute([$child_id_reg]);
        $initial_data = $stmt_initial_data->fetch(PDO::FETCH_ASSOC);

        // Hitung umur
        $birthDate = new DateTime($initial_data['birth_date']);
        $age_in_months = $birthDate->diff(new DateTime('today'))->m + ($birthDate->diff(new DateTime('today'))->y * 12);

        // 3. Jalankan Kalkulator Gizi
        $calculator = new WHONutritionCalculatorRevised();
        $calc_result = $calculator->calculateNutritionalStatus($gender_input === 'laki-laki' ? 'boys' : 'girls', $age_in_months, $initial_data['weight'], $initial_data['height']);
        
        $popup_data = [];

        if ($calc_result['is_special_case']) {
            // Jika kasus khusus, siapkan data pop-up sederhana
            $popup_data = [
                'status_gizi' => $calc_result['summary']['final_summary'],
                'color' => $calc_result['summary']['color'],
                'budget_range' => 'Tidak dapat dihitung',
                'indicator_details' => [] // Kosongkan detail
            ];
        } else {
            // 4. Jika normal, jalankan Generator Ringkasan
            $nutrition_summary = generateNutritionSummary($calc_result['results_code']);

            // Ambil data alergi & preferensi yang baru saja diinput
            $stmt_allergies = $pdo->prepare("SELECT food_name FROM allergies WHERE child_id = ?");
            $stmt_allergies->execute([$child_id_reg]);
            $current_allergies = $stmt_allergies->fetchAll(PDO::FETCH_COLUMN);

            $stmt_prefs = $pdo->prepare("SELECT food_name FROM preferences WHERE child_id = ?");
            $stmt_prefs->execute([$child_id_reg]);
            $current_preferences = $stmt_prefs->fetchAll(PDO::FETCH_COLUMN);

            // 5. Jalankan Mesin Aturan Budget
            // [MODIFIKASI] Pastikan nama tabel di rule engine sesuai schema Anda ('child_recommendations')
            // Saya asumsikan Anda telah menyesuaikan nama tabel di file rule-engine.php
            $engine = new BudgetRuleEngine($pdo);
            $budget_recommendation = $engine->generateBudgetRecommendation(
                $child_id_reg,
                $age_in_months,
                $initial_data['gaji'],
                $nutrition_summary,
                $current_allergies,
                $current_preferences
            );
            
            // Siapkan data lengkap untuk pop-up
            $popup_data = [
                'status_gizi'       => $nutrition_summary['parent_summary'],
                'status_color'      => $nutrition_summary['color'],
                'budget_range'      => $budget_recommendation,
                'indicator_details' => $nutrition_summary['indicator_details']
            ];
        }
        
        // 6. Siapkan sesi untuk pop-up di main-menu.php
        $stmt_child_name = $pdo->prepare("SELECT name FROM children WHERE id = ?");
        $stmt_child_name->execute([$child_id_reg]);
        $child_name = $stmt_child_name->fetchColumn();
        
        $_SESSION['show_recommendation_popup'] = array_merge([
            'child_name'        => $child_name,
            'gender'            => $gender_input,
            'age'               => $age_in_months
        ], $popup_data);

        // =============================================================
        // [LOGIKA BARU SELESAI]
        // =============================================================
    
    // Proses login
    $stmt_user = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmt_user->execute([$user_id_reg]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['active_child_id'] = $child_id_reg;
    }

    $pdo->commit();
    unset($_SESSION['registration_user_id']);
    unset($_SESSION['registration_child_id']);
    header("Location: main-menu.php");
    exit();

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error menyelesaikan pendaftaran: " . $e->getMessage());
}
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Jenis Kelamin - NutriBy</title>
    <link href="./assets/styles/output.css" rel="stylesheet">
</head>
<body class="bg-white min-h-screen flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-sm mx-auto text-center">
        <h2 class="text-2xl font-semibold text-red-900 mb-8">Apa jenis kelamin anak Anda?</h2>
        <form action="pilih-kelamin.php" method="POST">
            <div class="flex justify-center space-x-6 mb-12">
                <label>
                    <input type="radio" name="gender" value="laki-laki" class="sr-only peer" required>
                    <div class="w-32 h-32 bg-blue-100 rounded-2xl flex items-center justify-center cursor-pointer transform transition-all duration-300 peer-checked:ring-4 peer-checked:ring-blue-500 peer-checked:scale-110">
                         <img src="assets/img/male.png" alt="Anak Laki-laki" class="mx-auto w-28 h-28 object-contain">
                    </div>
                </label>
                <label>
                    <input type="radio" name="gender" value="perempuan" class="sr-only peer">
                     <div class="w-32 h-32 bg-pink-100 rounded-2xl flex items-center justify-center cursor-pointer transform transition-all duration-300 peer-checked:ring-4 peer-checked:ring-pink-500 peer-checked:scale-110">
                         <img src="assets/img/female.png" alt="Anak Perempuan" class="mx-auto w-28 h-28 object-contain">
                    </div>
                </label>
            </div>
            <button type="submit" class="bg-red-900 text-white font-semibold py-3 px-12 rounded-full shadow-md transition hover:bg-red-800">
                Selesai
            </button>
        </form>
    </div>
</body>
</html>
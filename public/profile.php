<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/kalkulator-gizi.php';
require_once __DIR__ . '/../includes/summary-generator.php';
require_once __DIR__ . '/../includes/rule-engine.php';

$user_id = $_SESSION['user_id'] ?? 0;
$child_id = $_SESSION['active_child_id'] ?? 0;
$message = '';
$message_type = 'success';

if (isset($_GET['status']) && $_GET['status'] === 'updated') {
    $message = "Data dan rekomendasi berhasil diperbarui!";
}

if (!$user_id || !$child_id) {
    header('Location: login.php');
    exit();
}

// --- LOGIKA UPDATE DATA (BAGIAN YANG DIPERBAIKI) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validasi dan konversi input dengan filter yang lebih ketat
    $new_weight = filter_var($_POST['weight'] ?? 0, FILTER_VALIDATE_FLOAT);
    $new_height = filter_var($_POST['height'] ?? 0, FILTER_VALIDATE_FLOAT);
    $new_gaji = filter_var($_POST['gaji'] ?? 0, FILTER_VALIDATE_INT);

    error_log("Received POST data - Weight: " . ($new_weight !== false ? $new_weight : 'invalid') .
        ", Height: " . ($new_height !== false ? $new_height : 'invalid') .
        ", Gaji: " . ($new_gaji !== false ? $new_gaji : 'invalid'));

    $allergies_str = trim($_POST['allergy'] ?? '');
    $preferences_str = trim($_POST['preference'] ?? '');

    try {
        // Validasi input
        if ($new_weight === false || $new_weight <= 0 || $new_weight > 50) {
            throw new Exception("Berat badan tidak valid. Harus antara 0.1 - 50 kg");
        }
        if ($new_height === false || $new_height <= 0 || $new_height > 200) {
            throw new Exception("Tinggi badan tidak valid. Harus antara 0.1 - 200 cm");
        }
        if ($new_gaji === false || $new_gaji < 0) {
            throw new Exception("Pendapatan tidak valid. Tidak boleh negatif");
        }

        // PERBAIKAN: Start transaction dengan proper error handling
        $pdo->beginTransaction();

        // 1. Ambil data child untuk mendapatkan birth_date dan gender SEBELUM update
        $stmt_get_child = $pdo->prepare("SELECT birth_date, gender FROM children WHERE id = ?");
        $stmt_get_child->execute([$child_id]);
        $child_info = $stmt_get_child->fetch(PDO::FETCH_ASSOC);

        if (!$child_info) {
            throw new Exception("Data anak tidak ditemukan");
        }

        // 2. Hitung usia dalam bulan
        $birthDate = new DateTime($child_info['birth_date']);
        $today = new DateTime('today');
        $age_in_months = $birthDate->diff($today)->m + ($birthDate->diff($today)->y * 12);

        error_log("Child info - Birth: {$child_info['birth_date']}, Age: {$age_in_months} months");

        // 3. PERBAIKAN: Insert data pertumbuhan dengan explicit column names dan better error checking
        $stmt_growth = $pdo->prepare("
            INSERT INTO growth_records (child_id, month, weight, height, recorded_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $growth_result = $stmt_growth->execute([$child_id, $age_in_months, $new_weight, $new_height]);

        if (!$growth_result) {
            throw new Exception("Gagal menyimpan data pertumbuhan: " . implode(", ", $stmt_growth->errorInfo()));
        }

        $growth_record_id = $pdo->lastInsertId();
        error_log("Growth record inserted with ID: " . $growth_record_id);

        // 4. Update data user (gaji)
        $stmt_user = $pdo->prepare("UPDATE users SET gaji = ? WHERE id = ?");
        $user_result = $stmt_user->execute([$new_gaji, $user_id]);

        if (!$user_result) {
            throw new Exception("Gagal mengupdate data gaji");
        }

        // 5. Update allergies dengan DELETE-INSERT pattern
        $stmt_del_allergy = $pdo->prepare("DELETE FROM allergies WHERE child_id = ?");
        $stmt_del_allergy->execute([$child_id]);

        $allergies_arr = [];
        if (!empty($allergies_str)) {
            $allergies_arr = array_map('trim', explode(',', $allergies_str));
            $allergies_arr = array_filter($allergies_arr); // Remove empty elements

            if (!empty($allergies_arr)) {
                $stmt_ins_allergy = $pdo->prepare("INSERT INTO allergies (child_id, food_name) VALUES (?, ?)");
                foreach ($allergies_arr as $allergy_item) {
                    $stmt_ins_allergy->execute([$child_id, $allergy_item]);
                }
            }
        }

        // 6. Update preferences dengan DELETE-INSERT pattern
        $stmt_del_pref = $pdo->prepare("DELETE FROM preferences WHERE child_id = ?");
        $stmt_del_pref->execute([$child_id]);

        $preferences_arr = [];
        if (!empty($preferences_str)) {
            $preferences_arr = array_map('trim', explode(',', $preferences_str));
            $preferences_arr = array_filter($preferences_arr); // Remove empty elements

            if (!empty($preferences_arr)) {
                $stmt_ins_pref = $pdo->prepare("INSERT INTO preferences (child_id, food_name) VALUES (?, ?)");
                foreach ($preferences_arr as $pref_item) {
                    $stmt_ins_pref->execute([$child_id, $pref_item]);
                }
            }
        }

        // 7. Jalankan Kalkulator Gizi dengan data BARU
        $calculator = new WHONutritionCalculatorRevised();
        $calc_result = $calculator->calculateNutritionalStatus(
            $child_info['gender'] === 'laki-laki' ? 'boys' : 'girls',
            $age_in_months,
            $new_weight,
            $new_height
        );

        if ($calc_result['is_special_case']) {
            // Jika kasus khusus, update dengan pesan khusus
            $stmt_special = $pdo->prepare("
                INSERT INTO child_recommendations (child_id, nutrition_summary, monthly_budget_range, last_updated) 
                VALUES (?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                nutrition_summary = VALUES(nutrition_summary),
                monthly_budget_range = VALUES(monthly_budget_range),
                last_updated = VALUES(last_updated)
            ");
            $stmt_special->execute([$child_id, $calc_result['summary']['final_summary'], 'Perlu evaluasi medis']);
        } else {
            // 8. Jalankan Generator Ringkasan dan Rule Engine
            $nutrition_summary = generateNutritionSummary($calc_result['results_code']);

            $engine = new BudgetRuleEngine($pdo);
            $budget_recommendation = $engine->generateBudgetRecommendation(
                $child_id,
                $age_in_months,
                $new_gaji,
                $nutrition_summary,
                $allergies_arr,
                $preferences_arr
            );

            error_log("Budget recommendation generated: " . $budget_recommendation);
        }

        // PERBAIKAN: Explicit commit dengan error checking
        $commit_result = $pdo->commit();
        if (!$commit_result) {
            throw new Exception("Gagal melakukan commit transaksi");
        }

        error_log("Transaction committed successfully for child_id: $child_id");

        // PERBAIKAN: Bersihkan output buffer sebelum redirect
        while (ob_get_level()) {
            ob_end_clean();
        }

        // PERBAIKAN: Redirect dengan proper headers
        header('Location: profile.php?status=updated', true, 302);
        exit();
    } catch (Exception $e) {
        // PERBAIKAN: Better error handling
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Transaction error for child_id $child_id: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        $message = "Gagal memperbarui data: " . $e->getMessage();
        $message_type = 'error';

        // Jangan redirect jika ada error, tampilkan pesan error
    }
}

// --- LOGIKA MENGAMBIL SEMUA DATA UNTUK DITAMPILKAN (BAGIAN YANG DIPERBAIKI) ---
try {
    // Ambil semua opsi makanan untuk dropdown
    $stmt_foods = $pdo->query("SELECT DISTINCT allergens FROM menus WHERE allergens IS NOT NULL AND allergens != '[]'");
    $all_allergens_json = $stmt_foods->fetchAll(PDO::FETCH_COLUMN);
    $food_options = [];

    foreach ($all_allergens_json as $allergens_json) {
        $allergens_arr = json_decode($allergens_json, true);
        if (is_array($allergens_arr)) {
            foreach ($allergens_arr as $allergen) {
                if (!in_array($allergen, $food_options)) {
                    $food_options[] = $allergen;
                }
            }
        }
    }

    if (empty($food_options)) {
        $food_options = ['Telur', 'Susu Sapi', 'Gandum', 'Kedelai'];
    }
    sort($food_options);

    // Ambil data profil gabungan
    $stmt = $pdo->prepare("
        SELECT 
            u.name AS user_name, u.email, u.gaji,
            c.name AS child_name, c.birth_date, c.gender,
            rec.monthly_budget_range, rec.nutrition_summary, rec.last_updated
        FROM users u
        JOIN children c ON u.id = c.user_id
        LEFT JOIN child_recommendations rec ON c.id = rec.child_id
        WHERE u.id = ? AND c.id = ?
    ");
    $stmt->execute([$user_id, $child_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        throw new Exception("Data profil tidak ditemukan untuk user_id: $user_id, child_id: $child_id");
    }

    // PERBAIKAN: Ambil data pertumbuhan terakhir dengan query yang lebih spesifik
    $stmt_growth = $pdo->prepare("
        SELECT weight, height, recorded_at, id
        FROM growth_records 
        WHERE child_id = ? 
        ORDER BY recorded_at DESC, id DESC 
        LIMIT 1
    ");
    $stmt_growth->execute([$child_id]);
    $growth_data = $stmt_growth->fetch(PDO::FETCH_ASSOC);

    // PERBAIKAN: Pastikan data dari database, bukan dari POST
    if ($growth_data) {
        $data['weight'] = $growth_data['weight'];
        $data['height'] = $growth_data['height'];
        $data['growth_recorded_at'] = $growth_data['recorded_at'];
        error_log("Retrieved latest growth data from DB - Weight: {$growth_data['weight']}, Height: {$growth_data['height']}, Record ID: {$growth_data['id']}");
    } else {
        $data['weight'] = null;
        $data['height'] = null;
        $data['growth_recorded_at'] = null;
        error_log("No growth data found in database for child_id: $child_id");
    }

    // Ambil data alergi & preferensi TERBARU dari database
    $stmt_allergies = $pdo->prepare("SELECT food_name FROM allergies WHERE child_id = ? ORDER BY id");
    $stmt_allergies->execute([$child_id]);
    $current_allergies = $stmt_allergies->fetchAll(PDO::FETCH_COLUMN);

    $stmt_prefs = $pdo->prepare("SELECT food_name FROM preferences WHERE child_id = ? ORDER BY id");
    $stmt_prefs->execute([$child_id]);
    $current_preferences = $stmt_prefs->fetchAll(PDO::FETCH_COLUMN);

    // Hitung usia untuk ditampilkan
    if (!empty($data['birth_date'])) {
        try {
            $birthDate = new DateTime($data['birth_date']);
            $today = new DateTime('today');
            $age = $today->diff($birthDate);
            $age_display = "{$age->y} tahun, {$age->m} bulan, {$age->d} hari";
        } catch (Exception $e) {
            error_log("Error calculating age display: " . $e->getMessage());
            $age_display = "Tidak dapat menghitung usia";
        }
    } else {
        $age_display = "Tanggal lahir tidak valid";
    }

    // Tentukan warna status gizi
    $status_color = 'green'; // Default
    if (isset($data['nutrition_summary']) && !empty($data['nutrition_summary'])) {
        $nutrition_summary = strtolower($data['nutrition_summary']);
        if (
            strpos($nutrition_summary, 'berat') !== false ||
            strpos($nutrition_summary, 'kurang') !== false ||
            strpos($nutrition_summary, 'stunting') !== false
        ) {
            $status_color = 'red';
        } elseif (
            strpos($nutrition_summary, 'sedang') !== false ||
            strpos($nutrition_summary, 'risiko') !== false ||
            strpos($nutrition_summary, 'pendek') !== false
        ) {
            $status_color = 'yellow';
        }
    }

    error_log("Profile data loaded successfully for child: " . $data['child_name'] . " at " . date('Y-m-d H:i:s'));
} catch (PDOException $e) {
    error_log("Database error in profile.php: " . $e->getMessage());
    die("Gagal mengambil data profil: " . $e->getMessage());
} catch (Exception $e) {
    error_log("General error in profile.php: " . $e->getMessage());
    die("Terjadi kesalahan: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Pengguna - NutriBy</title>
    <link href="./assets/styles/output.css" rel="stylesheet">
    <style>
        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type=number] {
            -moz-appearance: textfield;
        }
    </style>
</head>

<body class="bg-gray-100 flex flex-col min-h-screen">

    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="flex-grow">
        <div class="container mx-auto p-4 sm:p-8 max-w-4xl">
            <div id="edit-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
                <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 md:p-8 relative max-h-[90vh] overflow-y-auto">
                    <button id="close-modal-btn" class="absolute top-2 right-2 w-8 h-8 bg-gray-300 text-gray-700 rounded-full flex items-center justify-center text-xl font-bold hover:bg-gray-400 transition">&times;</button>
                    <h3 class="text-xl font-semibold text-gray-700 border-b pb-2 mb-4">Perbarui Data Anak & Keluarga</h3>
                    <form action="profile.php" method="POST">
                        <div class="space-y-4 text-sm">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="weight" class="block text-gray-600 font-medium">Berat Badan Terbaru (kg)</label>
                                    <input type="number" step="0.1" id="weight" name="weight" value="<?= htmlspecialchars($data['weight'] ?? '') ?>" class="w-full mt-1 p-2 bg-white border rounded-md" required>
                                </div>
                                <div>
                                    <label for="height" class="block text-gray-600 font-medium">Tinggi Badan Terbaru (cm)</label>
                                    <input type="number" step="0.1" id="height" name="height" value="<?= htmlspecialchars($data['height'] ?? '') ?>" class="w-full mt-1 p-2 bg-white border rounded-md" required>
                                </div>
                            </div>
                            <div>
                                <label for="gaji" class="block text-gray-600 font-medium">Pendapatan Bulanan (Rp)</label>
                                <input type="number" id="gaji" name="gaji" value="<?= htmlspecialchars($data['gaji'] ?? '') ?>" class="w-full mt-1 p-2 bg-white border rounded-md" required>
                            </div>
                            <div class="relative" id="allergy-select-container">
                                <label class="block text-gray-600 font-medium mb-1">Perbarui Alergi Anak</label>
                                <div id="allergy-button" class="border rounded-md w-full p-2 flex flex-wrap gap-2 items-center cursor-pointer min-h-[44px] bg-white">
                                    <span class="text-gray-400">Pilih alergi...</span>
                                </div>
                                <div id="allergy-dropdown" class="absolute z-20 w-full bg-white rounded-md shadow-lg max-h-48 overflow-y-auto mt-1 hidden">
                                    <div class="p-2">
                                        <?php foreach ($food_options as $option): ?>
                                            <label class="flex items-center space-x-2 p-2 text-gray-800 rounded-md hover:bg-gray-100">
                                                <input type="checkbox" name="allergy_options[]" value="<?= htmlspecialchars($option) ?>" class="form-checkbox h-4 w-4 text-red-600" <?= in_array($option, $current_allergies) ? 'checked' : '' ?>>
                                                <span><?= htmlspecialchars($option) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="allergy" id="allergy-hidden-input" value="<?= htmlspecialchars(implode(',', $current_allergies)) ?>">
                            </div>
                            <div class="relative" id="preference-select-container">
                                <label class="block text-gray-600 font-medium mb-1">Perbarui Makanan Favorit</label>
                                <div id="preference-button" class="border rounded-md w-full p-2 flex flex-wrap gap-2 items-center cursor-pointer min-h-[44px] bg-white">
                                    <span class="text-gray-400">Pilih makanan favorit...</span>
                                </div>
                                <div id="preference-dropdown" class="absolute z-10 w-full bg-white rounded-md shadow-lg max-h-48 overflow-y-auto mt-1 hidden">
                                    <div class="p-2">
                                        <?php foreach ($food_options as $option): ?>
                                            <label class="flex items-center space-x-2 p-2 text-gray-800 rounded-md hover:bg-gray-100">
                                                <input type="checkbox" name="preference_options[]" value="<?= htmlspecialchars($option) ?>" class="form-checkbox h-4 w-4 text-red-600" <?= in_array($option, $current_preferences) ? 'checked' : '' ?>>
                                                <span><?= htmlspecialchars($option) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="preference" id="preference-hidden-input" value="<?= htmlspecialchars(implode(',', $current_preferences)) ?>">
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end items-center gap-4">
                            <button type="button" id="cancel-edit-btn" class="bg-gray-200 text-gray-700 font-semibold py-2 px-6 rounded-lg hover:bg-gray-300 transition">Batal</button>
                            <button type="submit" class="bg-green-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-green-500 transition">Simpan & Hitung Ulang Rekomendasi</button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="success-notification" class="fixed top-20 right-5 bg-green-500 text-white py-3 px-5 rounded-lg shadow-lg z-50 transform translate-x-[120%] transition-transform duration-500">
                <?= htmlspecialchars($message) ?>
            </div>
            <div class="bg-white rounded-lg shadow-lg p-6 md:p-8 grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="md:col-span-1 text-center border-r-0 md:border-r md:pr-8">
                    <div class="w-32 h-32 rounded-full bg-gray-200 mx-auto mb-4 flex items-center justify-center overflow-hidden">
                        <?php if (isset($data['gender']) && $data['gender'] === 'perempuan'): ?>
                            <img src="./assets/img/female.png" alt="Avatar Perempuan" class="w-full h-full object-cover">
                        <?php else: ?>
                            <img src="./assets/img/male.png" alt="Avatar Laki-laki" class="w-full h-full object-cover">
                        <?php endif; ?>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($data['child_name'] ?? 'Nama Anak') ?></h2>
                    <p class="text-gray-500 text-sm mb-6"><?= htmlspecialchars($age_display ?? '') ?></p>
                    <a href="tambah-anak.php" class="w-full bg-red-800 text-white font-semibold py-2 px-4 rounded-lg hover:bg-red-700 transition">
                        + Tambah Anak
                    </a>
                </div>
                <div class="md:col-span-2">
                    <div class="mb-8">
                        <h3 class="text-xl font-semibold text-gray-700 border-b pb-2 mb-4">Informasi Akun & Anak</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div><label class="block text-gray-500">Username</label><input type="text" value="<?= htmlspecialchars($data['user_name'] ?? '') ?>" class="w-full mt-1 p-2 bg-gray-100 border rounded-md" disabled></div>
                            <div><label class="block text-gray-500">Email</label><input type="text" value="<?= htmlspecialchars($data['email'] ?? '') ?>" class="w-full mt-1 p-2 bg-gray-100 border rounded-md" disabled></div>
                            <div><label class="block text-gray-500">Nama Anak</label><input type="text" value="<?= htmlspecialchars($data['child_name'] ?? '') ?>" class="w-full mt-1 p-2 bg-gray-100 border rounded-md" disabled></div>
                            <div><label class="block text-gray-500">Usia Anak</label><input type="text" value="<?= htmlspecialchars($age_display ?? '') ?>" class="w-full mt-1 p-2 bg-gray-100 border rounded-md" disabled></div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-gray-700 border-b pb-2 mb-4">Data Anak & Rekomendasi</h3>
                        <div class="space-y-4 text-sm">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div><label class="block text-gray-500">Berat Badan Terakhir (kg)</label><input type="text" value="<?= htmlspecialchars($data['weight'] ?? '') ?>" class="w-full mt-1 p-2 bg-gray-100 border rounded-md" disabled></div>
                                <div><label class="block text-gray-500">Tinggi Badan Terakhir (cm)</label><input type="text" value="<?= htmlspecialchars($data['height'] ?? '') ?>" class="w-full mt-1 p-2 bg-gray-100 border rounded-md" disabled></div>
                            </div>
                            <div><label class="block text-gray-500">Pendapatan Bulanan (Rp)</label><input type="text" value="Rp <?= number_format($data['gaji'] ?? 0, 0, ',', '.') ?>" class="w-full mt-1 p-2 bg-gray-100 border rounded-md" disabled></div>
                            <div class="p-4 rounded-lg bg-<?= $status_color ?>-100 text-center">
                                <p class="text-sm font-semibold text-<?= $status_color ?>-800">Status Gizi Saat Ini</p>
                                <p class="text-lg font-bold text-<?= $status_color ?>-900">
                                    <?= htmlspecialchars($data['nutrition_summary'] ?? 'Belum dihitung') ?>
                                </p>
                            </div>
                            <div class="p-4 rounded-lg bg-green-50 border border-green-200">
                                <label class="block text-green-800 font-semibold">Rekomendasi Budget MPASI Bulanan</label>
                                <p class="text-green-700 font-bold text-lg"><?= htmlspecialchars($data['monthly_budget_range'] ?? 'Belum dihitung') ?></p>
                                <span class="font-normal text-base">(rekomendasi budget sedang tahap optimalisasi)</span>
                            </div>
                            <div>
                                <label class="block text-gray-500">Alergi Anak</label>
                                <div class="w-full mt-1 p-2 bg-gray-100 border rounded-md min-h-[40px]"><?= !empty($current_allergies) ? htmlspecialchars(implode(', ', $current_allergies)) : '<i class="text-gray-400">Tidak ada</i>' ?></div>
                            </div>
                            <div>
                                <label class="block text-gray-500">Makanan Favorit</label>
                                <div class="w-full mt-1 p-2 bg-gray-100 border rounded-md min-h-[40px]"><?= !empty($current_preferences) ? htmlspecialchars(implode(', ', $current_preferences)) : '<i class="text-gray-400">Tidak ada</i>' ?></div>
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end items-center gap-4">
                            <a href="logout.php" class="bg-gray-200 text-gray-700 font-semibold py-2 px-6 rounded-lg hover:bg-gray-300 transition">Keluar</a>
                            <button type="button" id="edit-data-btn" class="bg-blue-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-blue-500 transition">Edit Data</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <!-- <script src="./assets/js/multi-select.js"></script> -->
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // assets/js/multi-select.js

            // Fungsi untuk menginisialisasi satu komponen multi-select
            function initMultiSelect(container) {
                if (!container) return; // Pengaman jika container tidak ditemukan

                const button = container.querySelector('div[id$="-button"]');
                const dropdown = container.querySelector('div[id$="-dropdown"]');
                let hiddenInput = container.querySelector('input[type="hidden"]');
                const checkboxes = container.querySelectorAll('input[type="checkbox"]');
                const placeholder = button.querySelector("span");

                // Buat hidden input jika belum ada
                if (!hiddenInput) {
                    hiddenInput = document.createElement("input");
                    hiddenInput.type = "hidden";
                    hiddenInput.id = container.id.replace("-container", "-hidden-input");
                    hiddenInput.name = container.id.replace("-container", "");
                    container.appendChild(hiddenInput);
                }

                // Buka/tutup dropdown
                button.addEventListener("click", (e) => {
                    e.stopPropagation();
                    dropdown.classList.toggle("hidden");
                });

                // Update pilihan saat checkbox diubah
                checkboxes.forEach((checkbox) => {
                    checkbox.addEventListener("change", () => {
                        updateSelections(container);
                        // Validasi silang hanya jika kedua container ada di halaman
                        const allergyContainer = document.getElementById(
                            "allergy-select-container"
                        );
                        const preferenceContainer = document.getElementById(
                            "preference-select-container"
                        );
                        if (allergyContainer && preferenceContainer) {
                            validateSelections(allergyContainer, preferenceContainer);
                        }
                    });
                });

                // Panggil update sekali di awal untuk menampilkan tag yang sudah ada
                updateSelections(container);
            }

            // Fungsi untuk memperbarui tampilan "tag" dan hidden input
            function updateSelections(container) {
                const button = container.querySelector('div[id$="-button"]');
                let hiddenInput = container.querySelector('input[type="hidden"]');
                const placeholder = button.querySelector("span");
                const selected = [];

                // Buat hidden input jika belum ada
                if (!hiddenInput) {
                    hiddenInput = document.createElement("input");
                    hiddenInput.type = "hidden";
                    hiddenInput.id = container.id.replace("-container", "-hidden-input");
                    hiddenInput.name = container.id.replace("-container", "");
                    container.appendChild(hiddenInput);
                }

                // Hapus semua tag yang ada kecuali placeholder
                button.querySelectorAll(".tag").forEach((tag) => tag.remove());

                container
                    .querySelectorAll('input[type="checkbox"]:checked')
                    .forEach((checkbox) => {
                        selected.push(checkbox.value);
                        const tag = document.createElement("div");
                        tag.className =
                            "tag bg-red-100 text-red-800 text-sm font-semibold px-2 py-1 rounded-md flex items-center mr-1 mb-1";
                        tag.textContent = checkbox.value;

                        const removeBtn = document.createElement("button");
                        removeBtn.type = "button";
                        removeBtn.className = "ml-2 text-red-800 hover:text-red-500 font-bold";
                        removeBtn.innerHTML = "&times;";
                        removeBtn.onclick = (e) => {
                            e.stopPropagation();
                            checkbox.checked = false;
                            checkbox.dispatchEvent(new Event("change"));
                        };

                        tag.appendChild(removeBtn);
                        // Sisipkan tag sebelum placeholder
                        button.insertBefore(tag, placeholder);
                    });

                hiddenInput.value = selected.join(",");
                placeholder.style.display = selected.length === 0 ? "inline" : "none";
            }

            // Fungsi untuk validasi silang
            function validateSelections(allergyContainer, preferenceContainer) {
                const allergyHiddenInput = allergyContainer.querySelector(
                    'input[type="hidden"]'
                );
                const preferenceHiddenInput = preferenceContainer.querySelector(
                    'input[type="hidden"]'
                );

                const selectedAllergies = (
                        allergyHiddenInput ? allergyHiddenInput.value : ""
                    )
                    .split(",")
                    .filter(Boolean);
                const selectedPreferences = (
                        preferenceHiddenInput ? preferenceHiddenInput.value : ""
                    )
                    .split(",")
                    .filter(Boolean);

                allergyContainer
                    .querySelectorAll('input[type="checkbox"]')
                    .forEach((cb) => {
                        cb.disabled = selectedPreferences.includes(cb.value);
                        cb.parentElement.classList.toggle("opacity-50", cb.disabled);
                        cb.parentElement.classList.toggle("cursor-not-allowed", cb.disabled);
                    });

                preferenceContainer
                    .querySelectorAll('input[type="checkbox"]')
                    .forEach((cb) => {
                        cb.disabled = selectedAllergies.includes(cb.value);
                        cb.parentElement.classList.toggle("opacity-50", cb.disabled);
                        cb.parentElement.classList.toggle("cursor-not-allowed", cb.disabled);
                    });
            }

            // Event listener global untuk menutup dropdown jika klik di luar
            document.addEventListener("click", (e) => {
                const allergyContainer = document.getElementById(
                    "allergy-select-container"
                );
                const preferenceContainer = document.getElementById(
                    "preference-select-container"
                );
                if (allergyContainer && !allergyContainer.contains(e.target)) {
                    const dropdown = allergyContainer.querySelector('div[id$="-dropdown"]');
                    if (dropdown) dropdown.classList.add("hidden");
                }
                if (preferenceContainer && !preferenceContainer.contains(e.target)) {
                    const dropdown = preferenceContainer.querySelector(
                        'div[id$="-dropdown"]'
                    );
                    if (dropdown) dropdown.classList.add("hidden");
                }
            });

            // Ekspor fungsi untuk digunakan di file lain
            window.initMultiSelect = initMultiSelect;

            // Inisialisasi otomatis jika container sudah ada saat DOM loaded
            const allergyContainer = document.getElementById("allergy-select-container");
            const preferenceContainer = document.getElementById(
                "preference-select-container"
            );
            if (allergyContainer) initMultiSelect(allergyContainer);
            if (preferenceContainer) initMultiSelect(preferenceContainer);
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('edit-modal');
            const openModalBtn = document.getElementById('edit-data-btn');
            const closeModalBtn = document.getElementById('close-modal-btn');
            const cancelModalBtn = document.getElementById('cancel-edit-btn');

            openModalBtn.addEventListener('click', () => {
                modal.classList.remove('hidden');
            });

            const closeModal = () => modal.classList.add('hidden');
            closeModalBtn.addEventListener('click', closeModal);
            cancelModalBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (event) => {
                if (event.target === modal) closeModal();
            });

            const notification = document.getElementById('success-notification');
            if (notification.textContent.trim() !== '') {
                setTimeout(() => {
                    notification.classList.remove('translate-x-[120%]');
                }, 100);
                setTimeout(() => {
                    notification.classList.add('translate-x-[120%]');
                }, 4000);
            }
        });
    </script>
</body>

</html>
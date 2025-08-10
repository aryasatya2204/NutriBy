<?php
// Memulai sesi untuk menyimpan ID anak antar halaman
session_start();

// Memanggil file koneksi database. Pastikan path ini benar.
require_once __DIR__ . '/../includes/db.php';

try {
    // Query untuk mengambil semua data alergen unik dari tabel menus
    $stmt_foods = $pdo->query("SELECT DISTINCT allergens FROM menus WHERE allergens IS NOT NULL AND allergens != '[]'");
    $all_allergens_json = $stmt_foods->fetchAll(PDO::FETCH_COLUMN);

    $food_options = [];
    foreach ($all_allergens_json as $allergens_json) {
        $allergens_arr = json_decode($allergens_json, true);
        if (is_array($allergens_arr)) {
            foreach ($allergens_arr as $allergen) {
                // Tambahkan hanya jika belum ada
                if (!in_array($allergen, $food_options)) {
                    $food_options[] = $allergen;
                }
            }
        }
    }

    // Jika data menu masih kosong, berikan opsi default
    if (empty($food_options)) {
        $food_options = ['Daging Ayam', 'Daging Sapi', 'Ikan Salmon', 'Telur', 'Susu Sapi', 'Keju', 'Gandum', 'Kedelai', 'Udang'];
    }
    sort($food_options);
} catch (PDOException $e) {
    // Jika query gagal, gunakan data default
    $food_options = ['Daging Ayam', 'Daging Sapi', 'Ikan Salmon', 'Telur', 'Susu Sapi', 'Keju', 'Gandum', 'Kedelai', 'Udang'];
    sort($food_options);
}

$error_message = '';

// Hanya proses jika form di-submit dengan metode POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Ambil dan bersihkan data dari semua form
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $child_name = trim($_POST['child_name'] ?? '');
    $child_birth_date = $_POST['child_birth_date'] ?? '';
    $height = trim($_POST['height'] ?? 0);
    $weight = trim($_POST['weight'] ?? 0);
    $gaji = trim($_POST['gaji'] ?? 0);
    $allergies_str = trim($_POST['allergy'] ?? '');
    $preferences_str = trim($_POST['preference'] ?? '');

    $tanggal_lahir_obj = null;
    if (!empty($child_birth_date)) {
        try {
            $tanggal_lahir_obj = new DateTime($child_birth_date);
        } catch (Exception $e) {
            $tanggal_lahir_obj = null;
        }
    }

    // 2. Validasi data
    if (empty($username) || empty($email) || empty($password) || empty($child_name)) {
        $error_message = "Username, email, password, dan nama anak wajib diisi.";
    } elseif ($tanggal_lahir_obj === null) {
        $error_message = "Tanggal lahir anak wajib diisi dan harus dalam format yang benar.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Format email tidak valid.";
    } else {

        // 3. Cek apakah email sudah terdaftar
        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check->execute([$email]);
        if ($stmt_check->fetch()) {
            $error_message = "Email ini sudah terdaftar. Silakan gunakan email lain atau login.";
        } else {
            // 4. Proses penyimpanan data ke database menggunakan Transaksi
            try {
                $pdo->beginTransaction();

                // Simpan ke tabel 'users'
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt_user = $pdo->prepare("INSERT INTO users (name, email, password, gaji) VALUES (?, ?, ?, ?)");
                $stmt_user->execute([$username, $email, $hashed_password, $gaji]);
                $new_user_id = $pdo->lastInsertId();

                // Simpan ke tabel 'children'
                $stmt_child = $pdo->prepare("INSERT INTO children (user_id, name, birth_date, gender) VALUES (?, ?, ?, ?)");
                $stmt_child->execute([$new_user_id, $child_name, $child_birth_date, 'laki-laki']); // Gender default, akan diupdate nanti
                $new_child_id = $pdo->lastInsertId();

                // Simpan data awal ke 'growth_records'
                $birthDate = new DateTime($child_birth_date);
                $today = new DateTime('today');
                $age_in_months = $birthDate->diff($today)->y * 12 + $birthDate->diff($today)->m;

                $stmt_growth = $pdo->prepare("INSERT INTO growth_records (child_id, month, weight, height, nutrition_status, recorded_at) VALUES (?, ?, ?, ?, ?, ?)");
                // Status gizi default, nanti bisa dihitung lebih akurat
                $stmt_growth->execute([$new_child_id, $age_in_months, $weight, $height, 'sesuai', date('Y-m-d')]);

                // Simpan data ke 'allergies' jika ada
                if (!empty($allergies_str)) {
                    $allergies_arr = array_map('trim', explode(',', $allergies_str));
                    $stmt_allergy = $pdo->prepare("INSERT INTO allergies (child_id, food_name) VALUES (?, ?)");
                    foreach ($allergies_arr as $allergy_item) {
                        $stmt_allergy->execute([$new_child_id, $allergy_item]);
                    }
                }

                // Simpan data ke 'preferences' jika ada
                if (!empty($preferences_str)) {
                    $preferences_arr = array_map('trim', explode(',', $preferences_str));
                    $stmt_preference = $pdo->prepare("INSERT INTO preferences (child_id, food_name) VALUES (?, ?)");
                    foreach ($preferences_arr as $preference_item) {
                        $stmt_preference->execute([$new_child_id, $preference_item]);
                    }
                }

                // Jika semua query berhasil, commit transaksi
                $pdo->commit();

                // Simpan ID anak ke sesi untuk digunakan di halaman berikutnya
                $_SESSION['registration_user_id'] = $new_user_id;
                $_SESSION['registration_child_id'] = $new_child_id;

                // Arahkan ke halaman pemilihan jenis kelamin
                header('Location: pilih-kelamin.php');
                exit();
            } catch (PDOException $e) {
                // Jika terjadi error, batalkan semua query yang sudah dijalankan
                $pdo->rollBack();
                $error_message = "Pendaftaran gagal, terjadi kesalahan pada database.";
                // Untuk debugging: error_log($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar - NutriBy</title>
    <link href="./assets/styles/output.css" rel="stylesheet">
    <style>
        .form-section {
            display: none;
        }

        .form-section.active {
            display: block;
        }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px #991b1b inset !important;
            -webkit-text-fill-color: white !important;
            caret-color: white !important;
        }

        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type=number] {
            -moz-appearance: textfield;
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }
    </style>
</head>

<body class="bg-red-900 min-h-screen flex flex-col items-center justify-center p-4 text-white">

    <div class="w-full max-w-sm mx-auto text-center">
        <div class="mb-4">
            <img src="assets/img/logo.png" alt="Logo NutriBy" class="mx-auto w-40 h-40 object-contain">
        </div>
        <h1 class="text-white text-4xl font-bold mb-2">NutriBy</h1>
        <p class="text-white text-lg mb-8">isi data anda dibawah</p>

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-500 border border-red-700 text-white px-4 py-3 rounded-lg relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <form action="signup.php" method="POST" id="signup-form">
            <div id="form-1" class="form-section active space-y-6">
                <input type="text" name="username" placeholder="Username" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
                <input type="email" name="email" placeholder="Email" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
                <input type="password" name="password" placeholder="Password" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
                <div class="text-left">
                    <label for="child_birth_date" class="text-sm text-white/80">Tanggal Lahir Bayi</label>
                    <input type="date" name="child_birth_date" id="child_birth_date" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
                </div>
            </div>

            <div id="form-2" class="form-section space-y-6">
                <input type="text" name="child_name" placeholder="Nama Anak" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
                <input type="number" step="0.1" name="height" placeholder="Tinggi Badan (cm)" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
                <input type="number" step="0.1" name="weight" placeholder="Berat Badan (kg)" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
                <input type="number" name="gaji" placeholder="Pendapatan per Bulan" class="bg-transparent border-b-2 border-white w-full text-white placeholder-white focus:outline-none py-2" required>
            </div>

            <div id="form-3" class="form-section space-y-8 text-left">

                <div class="relative" id="allergy-select-container">
                    <label class="block text-sm text-white/80 mb-2">Alergi (bisa pilih lebih dari satu)</label>
                    <div id="allergy-button" class="bg-transparent border-b-2 border-white w-full p-2 flex flex-wrap gap-2 items-center cursor-pointer min-h-[44px]">
                        <span class="text-white/50">Pilih alergi...</span>
                    </div>
                    <div id="allergy-dropdown" class="absolute z-10 w-full bg-white rounded-md shadow-lg max-h-60 overflow-y-auto mt-1 hidden">
                        <div class="p-2">
                            <?php foreach ($food_options as $option): ?>
                                <label class="flex items-center space-x-2 p-2 text-gray-800 rounded-md hover:bg-gray-100">
                                    <input type="checkbox" name="allergy_options[]" value="<?= htmlspecialchars($option) ?>" class="form-checkbox h-5 w-5 text-red-600">
                                    <span><?= htmlspecialchars($option) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" name="allergy" id="allergy-hidden-input">
                </div>

                <div class="relative" id="preference-select-container">
                    <label class="block text-sm text-white/80 mb-2">Makanan Favorit (bisa pilih lebih dari satu)</label>
                    <div id="preference-button" class="bg-transparent border-b-2 border-white w-full p-2 flex flex-wrap gap-2 items-center cursor-pointer min-h-[44px]">
                        <span class="text-white/50">Pilih makanan favorit...</span>
                    </div>
                    <div id="preference-dropdown" class="absolute z-10 w-full bg-white rounded-md shadow-lg max-h-60 overflow-y-auto mt-1 hidden">
                        <div class="p-2">
                            <?php foreach ($food_options as $option): ?>
                                <label class="flex items-center space-x-2 p-2 text-gray-800 rounded-md hover:bg-gray-100">
                                    <input type="checkbox" name="preference_options[]" value="<?= htmlspecialchars($option) ?>" class="form-checkbox h-5 w-5 text-red-600">
                                    <span><?= htmlspecialchars($option) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" name="preference" id="preference-hidden-input">
                </div>

            </div>

            <div class="mt-12 flex items-center justify-between">
                <a href="index.php" id="nav-back-home" class="text-white hover:underline">Kembali</a>
                <button type="button" id="nav-prev" class="text-white p-2 rounded-full hover:bg-white/20"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg></button>
                <button type="button" id="nav-next" class="text-white p-2 rounded-full hover:bg-white/20"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg></button>
                <button type="submit" id="nav-signup" class="bg-white text-red-900 font-semibold py-3 px-12 rounded-full shadow-md transition hover:bg-gray-200">Sign Up</button>
            </div>
        </form>

        <div class="flex justify-center space-x-2 mt-8">
            <div id="dot-1" class="w-3 h-3 bg-white rounded-full"></div>
            <div id="dot-2" class="w-3 h-3 bg-gray-400 rounded-full"></div>
            <div id="dot-3" class="w-3 h-3 bg-gray-400 rounded-full"></div>
        </div>
    </div>

    <!-- <script src="./assets/js/signup.js"></script> -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- LOGIKA NAVIGASI FORM (Sama seperti sebelumnya) ---
            let currentForm = 1;
            const totalForms = 3;
            const navBackHome = document.getElementById('nav-back-home');
            const navPrev = document.getElementById('nav-prev');
            const navNext = document.getElementById('nav-next');
            const navSignup = document.getElementById('nav-signup');

            function showForm(formNumber) {
                document.querySelectorAll('.form-section').forEach(section => section.classList.remove('active'));
                document.getElementById(`form-${formNumber}`).classList.add('active');
                updateDots(formNumber);
                updateNavButtons(formNumber);
            }

            function updateDots(activeDot) {
                document.querySelectorAll('[id^="dot-"]').forEach((dot, index) => {
                    dot.style.backgroundColor = (index + 1) === activeDot ? 'white' : '#9CA3AF';
                });
            }

            function updateNavButtons(formNumber) {
                navBackHome.style.display = 'none';
                navPrev.style.display = 'none';
                navNext.style.display = 'none';
                navSignup.style.display = 'none';

                if (formNumber === 1) {
                    navBackHome.style.display = 'block';
                    navNext.style.display = 'block';
                } else if (formNumber > 1 && formNumber < totalForms) {
                    navPrev.style.display = 'block';
                    navNext.style.display = 'block';
                } else if (formNumber === totalForms) {
                    navPrev.style.display = 'block';
                    navSignup.style.display = 'block';
                }
            }

            function changeForm(direction) {
                currentForm = Math.max(1, Math.min(totalForms, currentForm + direction));
                showForm(currentForm);
            }

            navPrev.addEventListener('click', () => changeForm(-1));
            navNext.addEventListener('click', () => changeForm(1));

            // --- LOGIKA BARU: MULTI-SELECT DROPDOWN ---

            const allergyContainer = document.getElementById('allergy-select-container');
            const preferenceContainer = document.getElementById('preference-select-container');

            // Fungsi untuk menginisialisasi satu komponen multi-select
            function initMultiSelect(container) {
                const button = container.querySelector('div[id$="-button"]');
                const dropdown = container.querySelector('div[id$="-dropdown"]');
                const hiddenInput = container.querySelector('input[type="hidden"]');
                const checkboxes = container.querySelectorAll('input[type="checkbox"]');
                const placeholder = button.querySelector('span');

                // Buka/tutup dropdown
                button.addEventListener('click', () => {
                    dropdown.classList.toggle('hidden');
                });

                // Update pilihan saat checkbox diubah
                checkboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', () => {
                        updateSelections(container);
                        validateSelections(); // Validasi silang setiap ada perubahan
                    });
                });

                // Event listener untuk menutup dropdown jika klik di luar
                document.addEventListener('click', (e) => {
                    if (!container.contains(e.target)) {
                        dropdown.classList.add('hidden');
                    }
                });
            }

            // Fungsi untuk memperbarui tampilan "tag" dan hidden input
            function updateSelections(container) {
                const button = container.querySelector('div[id$="-button"]');
                const hiddenInput = container.querySelector('input[type="hidden"]');
                const placeholder = button.querySelector('span');
                const selected = [];

                // Hapus semua tag yang ada
                button.querySelectorAll('.tag').forEach(tag => tag.remove());

                container.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
                    selected.push(checkbox.value);
                    const tag = document.createElement('div');
                    tag.className = 'tag bg-white text-red-900 text-sm font-semibold px-2 py-1 rounded-md flex items-center';
                    tag.textContent = checkbox.value;

                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'ml-2 text-red-900 hover:text-red-600';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.onclick = (e) => {
                        e.stopPropagation();
                        checkbox.checked = false;
                        checkbox.dispatchEvent(new Event('change'));
                    };

                    tag.appendChild(removeBtn);
                    button.appendChild(tag);
                });

                hiddenInput.value = selected.join(',');

                // Tampilkan/sembunyikan placeholder
                placeholder.style.display = selected.length === 0 ? 'inline' : 'none';
            }

            // Fungsi untuk validasi silang antara Alergi dan Makanan Favorit
            function validateSelections() {
                const selectedAllergies = (document.getElementById('allergy-hidden-input').value || '').split(',').filter(Boolean);
                const selectedPreferences = (document.getElementById('preference-hidden-input').value || '').split(',').filter(Boolean);

                // Validasi dropdown alergi
                allergyContainer.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    if (selectedPreferences.includes(cb.value)) {
                        cb.disabled = true;
                        cb.parentElement.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        cb.disabled = false;
                        cb.parentElement.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                });

                // Validasi dropdown preferensi
                preferenceContainer.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    if (selectedAllergies.includes(cb.value)) {
                        cb.disabled = true;
                        cb.parentElement.classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        cb.disabled = false;
                        cb.parentElement.classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                });
            }

            // Inisialisasi kedua komponen multi-select
            initMultiSelect(allergyContainer);
            initMultiSelect(preferenceContainer);

            // Inisialisasi form awal
            showForm(1);
        });
    </script>
</body>

</html>
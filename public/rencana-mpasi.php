<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$child_id = $_SESSION['active_child_id'];

// Ambil data awal untuk dasbor
$stmt_rec = $pdo->prepare("SELECT monthly_budget_range FROM child_recommendations WHERE child_id = ?");
$stmt_rec->execute([$child_id]);
$recommendation = $stmt_rec->fetch(PDO::FETCH_ASSOC);
$budget_bulanan_tersimpan = $recommendation['monthly_budget_range'] ?? 'Data belum ada';

// Ambil alergi yang sudah tersimpan
$stmt_allergies = $pdo->prepare("SELECT food_name FROM allergies WHERE child_id = ?");
$stmt_allergies->execute([$child_id]);
$current_allergies = $stmt_allergies->fetchAll(PDO::FETCH_COLUMN);

// Ambil preferensi yang sudah tersimpan
$stmt_prefs = $pdo->prepare("SELECT food_name FROM preferences WHERE child_id = ?");
$stmt_prefs->execute([$child_id]);
$current_preferences = $stmt_prefs->fetchAll(PDO::FETCH_COLUMN);

// Ambil semua opsi makanan dari allergens di menu - ini untuk pilihan dropdown
$stmt_foods = $pdo->query("SELECT DISTINCT allergens FROM menus WHERE allergens IS NOT NULL AND allergens != '[]' AND allergens != ''");
$all_allergens_json = $stmt_foods->fetchAll(PDO::FETCH_COLUMN);
$food_options = [];

foreach ($all_allergens_json as $allergens_json) {
    $allergens_arr = json_decode($allergens_json, true);
    if (is_array($allergens_arr)) {
        foreach ($allergens_arr as $allergen) {
            $allergen = trim($allergen);
            if (!empty($allergen) && !in_array($allergen, $food_options)) {
                $food_options[] = $allergen;
            }
        }
    }
}

// Jika tidak ada data dari menu, buat beberapa opsi default
if (empty($food_options)) {
    $food_options = [
        'Telur',
        'Susu',
        'Kacang',
        'Seafood',
        'Gluten',
        'Kedelai',
        'Ayam',
        'Daging Sapi',
        'Ikan',
        'Udang',
        'Kepiting',
        'Pisang',
        'Jeruk',
        'Strawberry',
        'Tomat',
        'Coklat'
    ];
}

sort($food_options);

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Perencanaan MPASI Interaktif - NutriBy</title>
    <link href="./assets/styles/output.css" rel="stylesheet">
    <style>
        .day-column.faded {
            opacity: 0.5;
            pointer-events: none;
        }

        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type=number] {
            -moz-appearance: textfield;
        }

        /* Styling untuk tag */
        .tag {
            display: inline-flex;
            align-items: center;
            margin: 2px;
        }

        /* Styling untuk dropdown */
        .form-checkbox {
            accent-color: #dc2626;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex flex-col">

    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <main class="flex-grow container mx-auto p-4 sm:p-8">
        <div id="app-container"></div>
    </main>

    <div id="alert-popup" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4 z-50">
    </div>

    <div id="menu-detail-popup" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4 z-40">
    </div>

    <div id="replace-menu-popup" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center p-4 z-50">
    </div>

    <div id="loading-popup" class="fixed inset-0 bg-black/50 flex flex-col items-center justify-center z-50 hidden">
        <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-white"></div>
        <p class="text-white text-xl mt-4">Membuat Rencana...</p>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <script>
        const initialData = {
            monthlyBudget: <?= json_encode($budget_bulanan_tersimpan) ?>,
            currentAllergies: <?= json_encode($current_allergies) ?>,
            currentPreferences: <?= json_encode($current_preferences) ?>,
            foodOptions: <?= json_encode($food_options) ?>
        };
        console.log('Initial data loaded:', initialData);
    </script>
    <!-- <script src="./assets/js/multi-select.js"></script> 
    <script src="./assets/js/rencana-mpasi.js"></script> -->
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
        document.addEventListener("DOMContentLoaded", () => {
            // State management sederhana
            let appState = {
                currentPlan: null,
                planStartDate: null,
                planId: null,
                isEditMode: false,
                childAllergies: [],
            };

            // DOM Elements
            const appContainer = document.getElementById("app-container");
            const loadingPopup = document.getElementById("loading-popup");
            const menuDetailPopup = document.getElementById("menu-detail-popup");
            const replaceMenuPopup = document.getElementById("replace-menu-popup");

            // =======================================================
            // FUNGSI UTAMA & EVENT HANDLERS
            // =======================================================

            async function init() {
                loadingPopup.classList.remove("hidden");
                try {
                    const response = await fetch("api/api_get_plan.php");
                    const result = await response.json();

                    if (result.success && result.plan) {
                        // Jika ada rencana di DB, langsung tampilkan
                        appState.currentPlan = result.plan;
                        appState.planStartDate = new Date(result.start_date);
                        appState.planId = result.plan_id;
                        renderPlanView();
                    } else {
                        // Jika tidak ada, tampilkan dasbor
                        renderDashboard();
                    }
                } catch (error) {
                    console.error("Initialization error:", error);
                    renderDashboard(); // Fallback ke dasbor jika API gagal
                } finally {
                    loadingPopup.classList.add("hidden");
                }
            }

            async function handleGeneratePlan() {
                loadingPopup.classList.remove("hidden");
                const targetBudget = document.getElementById("weekly-budget-input").value;
                const allergies = (
                        document.getElementById("allergy-hidden-input")?.value || ""
                    )
                    .split(",")
                    .filter(Boolean);
                const preferences = (
                        document.getElementById("preference-hidden-input")?.value || ""
                    )
                    .split(",")
                    .filter(Boolean);

                try {
                    // [MODIFIKASI] Langkah 1: Simpan profil alergi dan preferensi terbaru ke database
                    const profileUpdateResponse = await fetch(
                        "api/api_update_child_profile.php", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json"
                            },
                            body: JSON.stringify({
                                allergies,
                                preferences
                            }),
                        }
                    );

                    const profileUpdateResult = await profileUpdateResponse.json();
                    if (!profileUpdateResult.success) {
                        // Jika gagal menyimpan profil, hentikan proses dan beri tahu pengguna
                        throw new Error(
                            "Gagal menyimpan pembaruan profil alergi dan preferensi."
                        );
                    }

                    // [MODIFIKASI] Langkah 2: Lanjutkan untuk membuat rencana seperti biasa
                    const planResponse = await fetch("api/api_generate_plan.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json"
                        },
                        body: JSON.stringify({
                            target_budget: targetBudget,
                            allergies: allergies,
                            preferences: preferences,
                        }),
                    });
                    const planResult = await planResponse.json();

                    if (planResult.success) {
                        appState.currentPlan = planResult.plan;
                        appState.planStartDate = new Date(planResult.start_date);
                        appState.planId = planResult.plan_id;
                        renderPlanView();
                    } else {
                        // Logika untuk menampilkan error (tidak berubah)
                        if (planResult.error === "budget_unrealistic") {
                            const budgetIcon = `<svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V6.375c0-.621.504-1.125 1.125-1.125h.375m18 3.75v.75a.75.75 0 01-.75.75h-.75a.75.75 0 01-.75-.75v-.75m0 0h.375c.621 0 1.125.504 1.125 1.125v.75c0 .414-.336.75-.75.75h-.75a.75.75 0 01-.75-.75v-.75m-9 3.75h.008v.008h-.008v-.008z" /></svg>`;
                            renderAlertPopup(
                                "Budget Tidak Realistis",
                                planResult.message,
                                budgetIcon
                            );
                        } else {
                            const errorIcon = `<svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.007H12v-.007z" /></svg>`;
                            renderAlertPopup(
                                "Terjadi Kesalahan",
                                planResult.message || "Gagal membuat rencana.",
                                errorIcon
                            );
                        }
                    }
                } catch (error) {
                    console.error("Fetch error:", error);
                    alert("Terjadi kesalahan: " + error.message);
                } finally {
                    loadingPopup.classList.add("hidden");
                }
            }

            // Di dalam file rencana-mpasi.js

            function handleExitPlan() {
                // [PERUBAHAN KUNCI] Panggil fungsi renderAlertPopup untuk konfirmasi
                const questionIcon = `<svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>`;

                // Kita akan memodifikasi renderAlertPopup sedikit agar bisa menerima tombol konfirmasi
                renderConfirmationPopup(
                    "Keluar dari Rencana?",
                    "Apakah Anda yakin? Rencana makan mingguan ini akan dihapus secara permanen.",
                    questionIcon,
                    "Ya, Keluar", // Teks tombol konfirmasi
                    "Batal", // Teks tombol batal
                    async () => {
                        // Fungsi yang dijalankan jika tombol "Ya, Keluar" diklik
                        loadingPopup.classList.remove("hidden");
                        try {
                            const response = await fetch("api/api_delete_plan.php", {
                                method: "POST",
                            });
                            const result = await response.json();
                            if (result.success) {
                                appState.currentPlan = null;
                                appState.planStartDate = null;
                                appState.planId = null;
                                renderDashboard();
                            } else {
                                alert(result.error || "Gagal menghapus rencana.");
                            }
                        } catch (error) {
                            alert("Terjadi kesalahan jaringan saat menghapus rencana.");
                        } finally {
                            loadingPopup.classList.add("hidden");
                        }
                    }
                );
            }

            async function showReplacementOptions(menuIndex) {
                const originalMenu = appState.currentPlan[menuIndex];
                if (!originalMenu) return;

                const existingPlanMenuIds = appState.currentPlan.map((menu) => menu.id);
                const uniqueExistingIds = [...new Set(existingPlanMenuIds)];

                loadingPopup.classList.remove("hidden");
                replaceMenuPopup.classList.add("hidden");

                try {
                    const baseUrl = "api/api_get_replacement_menus.php";
                    const params = new URLSearchParams({
                        max_cost: originalMenu.estimated_cost,
                        current_menu_id: originalMenu.id,
                        existing_ids: uniqueExistingIds.join(","),
                        allergies: appState.childAllergies.join(","),
                    });

                    const response = await fetch(`${baseUrl}?${params.toString()}`);

                    // Cek jika respons tidak OK (misal: 404 atau 500) sebelum mencoba parse JSON
                    if (!response.ok) {
                        // Lemparkan error agar ditangkap oleh blok catch
                        throw new Error(
                            `Server responded with ${response.status}: ${response.statusText}`
                        );
                    }

                    const result = await response.json();

                    if (result.success && result.menus.length > 0) {
                        renderReplacementPopup(
                            result.menus,
                            menuIndex,
                            uniqueExistingIds,
                            originalMenu.estimated_cost
                        );
                    } else {
                        alert(
                            result.error ||
                            "Tidak ditemukan menu pengganti yang sesuai dengan kriteria (harga lebih murah dan bebas alergen)."
                        );
                    }
                } catch (error) {
                    console.error("Error fetching replacement menus:", error);
                    alert(
                        "Terjadi kesalahan jaringan saat mencari menu pengganti. Periksa konsol untuk detail."
                    );
                } finally {
                    loadingPopup.classList.add("hidden");
                }
            }

            function renderReplacementPopup(
                menus,
                originalIndex,
                existingPlanMenuIds = [],
                originalCost
            ) {
                const menuItemsHTML = menus
                    .map((menu) => {
                        // [LOGIKA BARU] Tambahkan label berdasarkan kondisi
                        let labels = "";
                        const isCheaper = menu.estimated_cost < originalCost;
                        const isInPlan = existingPlanMenuIds.includes(parseInt(menu.id));

                        if (isCheaper) {
                            labels += `<span class="bg-yellow-200 text-yellow-800 text-xs font-semibold mr-2 px-2.5 py-0.5 rounded-full">Lebih Murah</span>`;
                        }
                        if (isInPlan) {
                            labels += `<span class="bg-blue-200 text-blue-800 text-xs font-semibold mr-2 px-2.5 py-0.5 rounded-full">Ada di Rencana</span>`;
                        }

                        return `
            <div class="replacement-item flex justify-between items-center p-3 hover:bg-red-100 rounded-lg cursor-pointer transition-colors duration-200" data-menu='${JSON.stringify(
              menu
            )}'>
                <div>
                    <p class="font-semibold text-gray-800">${menu.menu_name}</p>
                    <p class="text-sm text-gray-600">Rp ${formatCost(
                      menu.estimated_cost
                    )}</p>
                    <div class="mt-2">
                        ${labels}
                    </div>
                </div>
                <div class="text-sm bg-green-200 text-green-800 font-bold px-3 py-1 rounded-full">PILIH</div>
            </div>
        `;
                    })
                    .join("");

                replaceMenuPopup.innerHTML = `
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
            <div class="bg-white rounded-xl shadow-lg max-w-md w-full max-h-[80vh] flex flex-col transform transition-all duration-300 scale-95 opacity-0" id="replace-popup-content">
                <div class="p-4 border-b flex justify-between items-center">
                    <h3 class="text-lg font-bold text-red-900">Pilih Menu Pengganti</h3>
                    <button id="close-replace-popup" class="text-gray-500 hover:text-gray-800 text-2xl leading-none">&times;</button>
                </div>
                <div class="p-2 space-y-1 overflow-y-auto">
                    ${
                      menuItemsHTML.length > 0
                        ? menuItemsHTML
                        : '<p class="text-center text-gray-500 p-4">Tidak ada opsi pengganti yang ditemukan.</p>'
                    }
                </div>
            </div>
        </div>
    `;
                replaceMenuPopup.classList.remove("hidden");

                // Efek animasi
                setTimeout(() => {
                    document
                        .getElementById("replace-popup-content")
                        ?.classList.remove("scale-95", "opacity-0");
                }, 10);

                // Event listener untuk memilih item (logika ini tidak perlu diubah, karena sudah benar)
                replaceMenuPopup.querySelectorAll(".replacement-item").forEach((item) => {
                    item.addEventListener("click", async () => {
                        const newMenu = JSON.parse(item.dataset.menu);
                        let indicesToUpdate = [originalIndex];

                        loadingPopup.classList.remove("hidden");
                        try {
                            const response = await fetch("api/api_update_plan_item.php", {
                                method: "POST",
                                headers: {
                                    "Content-Type": "application/json"
                                },
                                body: JSON.stringify({
                                    plan_id: appState.planId,
                                    item_indices: indicesToUpdate,
                                    new_menu_id: newMenu.id,
                                }),
                            });
                            const result = await response.json();

                            if (result.success) {
                                await init(); // Memuat ulang seluruh state dan render ulang
                            } else {
                                alert(
                                    "Gagal menyimpan perubahan: " +
                                    (result.error || "Error tidak diketahui")
                                );
                            }
                        } catch (error) {
                            alert("Kesalahan jaringan saat menyimpan perubahan.");
                        } finally {
                            loadingPopup.classList.add("hidden");
                            replaceMenuPopup.classList.add("hidden");
                        }
                    });
                });

                document
                    .getElementById("close-replace-popup")
                    .addEventListener("click", () => {
                        replaceMenuPopup.classList.add("hidden");
                    });
            }

            async function updatePlanItemInDB(itemIndex, newMenuId) {
                try {
                    const response = await fetch("api/api_update_plan_item.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json"
                        },
                        body: JSON.stringify({
                            plan_id: appState.planId,
                            item_index: itemIndex,
                            new_menu_id: newMenuId,
                        }),
                    });
                    const result = await response.json();
                    if (!result.success) {
                        // Jika gagal, beritahu user dan reload halaman agar data kembali sinkron
                        alert(
                            "Gagal menyimpan perubahan ke server. Halaman akan dimuat ulang. Error: " +
                            result.error
                        );
                        location.reload();
                    }
                    // Jika sukses, tidak perlu melakukan apa-apa karena UI sudah diupdate
                    console.log("Perubahan berhasil disimpan.");
                } catch (error) {
                    alert(
                        "Kesalahan jaringan saat menyimpan perubahan. Halaman akan dimuat ulang."
                    );
                    location.reload();
                }
            }

            function toggleEditMode() {
                appState.isEditMode = !appState.isEditMode;
                updateEditModeUI();
            }

            // =======================================================
            // 3. UI RENDERING FUNCTIONS (Fungsi untuk menampilkan/memperbarui UI)
            // =======================================================

            function renderDashboard() {
                const budgetStr = initialData.monthlyBudget
                    .replace(/[^0-9-]/g, "")
                    .split("-");
                const avgBudget = (Number(budgetStr[0]) + Number(budgetStr[1])) / 2;
                const defaultWeekly = isNaN(avgBudget) ?
                    70000 :
                    Math.round(avgBudget / 4.3 / 1000) * 1000;

                appContainer.innerHTML = dashboardTemplate({
                    monthlyBudget: initialData.monthlyBudget,
                    defaultWeeklyBudget: defaultWeekly,
                    foodOptions: initialData.foodOptions,
                    currentAllergies: initialData.currentAllergies,
                    currentPreferences: initialData.currentPreferences,
                });

                if (typeof window.initMultiSelect === "function") {
                    setTimeout(() => {
                        // Beri sedikit jeda agar DOM siap
                        initMultiSelect(document.getElementById("allergy-select-container"));
                        initMultiSelect(document.getElementById("preference-select-container"));
                    }, 0);
                }

                document
                    .getElementById("generate-plan-btn")
                    .addEventListener("click", handleGeneratePlan);
            }

            function renderPlanView() {
                // Panggil init() untuk memastikan data sinkron sebelum render
                fetch("api/api_get_plan.php")
                    .then((res) => res.json())
                    .then((result) => {
                        if (result.success && result.plan) {
                            appState.currentPlan = result.plan;
                            appState.planStartDate = new Date(result.start_date);
                            appState.planId = result.plan_id;

                            appContainer.innerHTML = planTemplate(
                                appState.currentPlan,
                                appState.planStartDate
                            );
                            document
                                .getElementById("export-pdf-btn")
                                .addEventListener("click", () => {
                                    alert("Fitur sedang dalam pengembangan.");
                                });
                            const exitButton = document.getElementById("exit-plan-btn");
                            const editButton = document.getElementById("edit-plan-btn");

                            if (exitButton) exitButton.addEventListener("click", handleExitPlan);
                            if (editButton) editButton.addEventListener("click", toggleEditMode);

                            const menuCards = document.querySelectorAll("#plan-view .menu-card");
                            menuCards.forEach((card) => {
                                card.addEventListener("click", () => {
                                    const menuDetails = JSON.parse(card.dataset.details);
                                    if (menuDetails) {
                                        renderMenuDetailPopup(menuDetails);
                                    }
                                });
                            });

                            updateEditModeUI();
                        } else {
                            renderDashboard();
                        }
                    });
            }

            function updateEditModeUI() {
                const menuCards = document.querySelectorAll("#plan-view .menu-card");
                const today = new Date(new Date().setHours(0, 0, 0, 0));
                const planStartDate = new Date(appState.planStartDate);

                menuCards.forEach((card, index) => {
                    const dayIndex = Math.floor(index / 3);
                    const currentDate = new Date(planStartDate);
                    currentDate.setDate(planStartDate.getDate() + dayIndex);
                    const isPast = currentDate < today;

                    // Hapus ikon edit yang mungkin ada
                    card
                        .querySelectorAll(".edit-icon-container")
                        .forEach((el) => el.remove());

                    if (appState.isEditMode && !isPast) {
                        const editIconContainer = document.createElement("div");
                        editIconContainer.className =
                            "edit-icon-container absolute top-2 right-2 cursor-pointer";
                        editIconContainer.innerHTML =
                            '<svg class="h-5 w-5 text-blue-500 hover:text-blue-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536-7.072 7.072-3.536-3.536a5.861 5.861 0 010-8.284 5.86 5.86 0 018.284 0zM12 17v.01m0 3v.01m0-6v.01"/></svg>';
                        card.style.position = "relative"; // Make card relative for absolute positioning of icon
                        card.appendChild(editIconContainer);

                        editIconContainer.addEventListener("click", (event) => {
                            event.stopPropagation(); // Mencegah klik pada kartu
                            const menuIndex = parseInt(card.dataset.index);
                            // Panggil fungsi untuk menampilkan opsi pengganti
                            showReplacementOptions(menuIndex);
                        });
                    } else {
                        card.style.position = ""; // Reset position if not in edit mode
                    }
                });
            }

            // Helper function untuk format allergens
            function formatAllergens(allergensStr) {
                if (!allergensStr) return "Tidak ada";

                try {
                    // Jika sudah dalam format array JSON
                    let allergens = JSON.parse(allergensStr);
                    if (Array.isArray(allergens)) {
                        return allergens.join(", ");
                    }
                } catch (e) {
                    // Jika bukan JSON, coba format string biasa
                    return allergensStr
                        .replace(/[\[\]"]/g, "")
                        .replace(/,/g, ", ")
                        .trim();
                }

                return allergensStr;
            }

            // Helper function untuk format ingredients
            function formatIngredients(ingredientsStr) {
                if (!ingredientsStr) return "<li>Data bahan tidak tersedia.</li>";

                let ingredients = [];
                try {
                    // Coba parse sebagai JSON
                    const parsed = JSON.parse(ingredientsStr);
                    if (Array.isArray(parsed)) {
                        ingredients = parsed;
                    }
                } catch (e) {
                    // Jika gagal, anggap sebagai string biasa yang dipisah koma
                    ingredients = ingredientsStr.split(",").filter((item) => item.trim());
                }

                if (ingredients.length === 0) return "<li>Data bahan tidak tersedia.</li>";

                return ingredients
                    .map((ingredient) => `<li>${ingredient.trim()}</li>`)
                    .join("");
            }

            // Ganti juga fungsi formatCookingSteps agar lebih robust
            function formatCookingSteps(stepsStr) {
                if (!stepsStr || stepsStr.trim() === "")
                    return "<p>Langkah memasak tidak tersedia.</p>";

                // Pisahkan langkah berdasarkan baris baru, lalu filter baris kosong
                const steps = stepsStr.split("\n").filter((step) => step.trim());

                return steps
                    .map((step) => `<p class="leading-relaxed">${step.trim()}</p>`)
                    .join("");
            }

            // Helper function untuk format estimated cost
            function formatCost(cost) {
                if (!cost) return "0";

                // Hapus semua karakter non-digit
                const numericCost = cost.toString().replace(/[^0-9]/g, "");
                const number = parseInt(numericCost);

                if (isNaN(number)) return "0";

                return number.toLocaleString("id-ID");
            }

            function renderConfirmationPopup(
                title,
                message,
                icon,
                confirmText,
                cancelText,
                onConfirm
            ) {
                const alertPopup = document.getElementById("alert-popup");

                const popupContent = `
    <div class="fixed inset-0 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm text-center p-8 transform transition-all duration-300 scale-95 opacity-0" id="alert-content">
            <div class="w-24 h-24 mx-auto bg-blue-100 rounded-full flex items-center justify-center text-blue-500">
                ${icon}
            </div>
            <h3 class="text-2xl font-bold text-gray-800 mt-6">${title}</h3>
            <p class="text-gray-500 mt-2">${message}</p>
            <div class="mt-8 flex justify-center gap-4">
                <button id="cancel-popup-btn" class="bg-gray-200 text-gray-700 font-semibold py-3 rounded-full shadow-md w-full hover:bg-gray-300 transition">
                    ${cancelText}
                </button>
                <button id="confirm-popup-btn" class="bg-red-600 text-white font-semibold py-3 rounded-full shadow-md w-full hover:bg-red-500 transition">
                    ${confirmText}
                </button>
            </div>
        </div>
      </div>
    `;

                alertPopup.innerHTML = popupContent;
                alertPopup.classList.remove("hidden");

                setTimeout(() => {
                    document
                        .getElementById("alert-content")
                        ?.classList.remove("scale-95", "opacity-0");
                }, 10);

                function closePopup() {
                    alertPopup.classList.add("hidden");
                }

                const confirmBtn = document.getElementById("confirm-popup-btn");
                const cancelBtn = document.getElementById("cancel-popup-btn");

                confirmBtn.addEventListener("click", () => {
                    closePopup();
                    onConfirm(); // Jalankan aksi konfirmasi
                });

                cancelBtn.addEventListener("click", closePopup);
            }

            function renderAlertPopup(title, message, icon) {
                const alertPopup = document.getElementById("alert-popup");

                const popupContent = `
    <div class="fixed inset-0 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm text-center p-8 transform transition-all duration-300 scale-95 opacity-0" id="alert-content">
            <div class="w-24 h-24 mx-auto bg-red-100 rounded-full flex items-center justify-center text-red-500">
                ${icon}
            </div>
            <h3 class="text-2xl font-bold text-gray-800 mt-6">${title}</h3>
            <p class="text-gray-500 mt-2">${message}</p>
            <button id="close-single-alert-popup" class="mt-8 bg-red-600 text-white font-semibold py-3 px-8 rounded-full shadow-md w-full hover:bg-red-500 transition">
                Coba Lagi
            </button>
        </div>
      </div>
    `;

                alertPopup.innerHTML = popupContent;
                alertPopup.classList.remove("hidden");

                // Efek animasi saat muncul
                setTimeout(() => {
                    const content = document.getElementById("alert-content");
                    if (content) {
                        content.classList.remove("scale-95", "opacity-0");
                    }
                }, 10);

                function closePopup() {
                    alertPopup.classList.add("hidden");
                }

                document;
                document
                    .getElementById("close-single-alert-popup")
                    .addEventListener("click", closePopup);
            }

            function renderMenuDetailPopup(menu) {
                // [DESAIN BARU] Menggunakan struktur HTML untuk tampilan buku kuno
                const popupContent = `
        <div class="book-container p-4">
            <div class="book-content bg-[#FDF5E6] text-[#4a2c2a] rounded-lg shadow-2xl flex flex-col md:flex-row relative w-full max-w-4xl">
                <button id="close-menu-popup" class="absolute -top-3 -right-3 bg-red-800 text-white rounded-full w-9 h-9 flex items-center justify-center text-xl font-bold hover:bg-red-700 z-20">&times;</button>
                
                <div class="page page-left w-full md:w-1/2 p-6 md:p-8 border-b-2 md:border-b-0 md:border-r-2 border-dashed border-[#d2b48c]">
                    <h3 class="text-3xl font-bold font-serif mb-6 text-center text-red-900">${
                      menu.menu_name
                    }</h3>
                    
                    <div class="space-y-5">
                        <div>
                            <h4 class="font-bold text-lg mb-1 border-b border-[#d2b48c] pb-1">Kandungan Alergi</h4>
                            <p class="italic text-sm">${formatAllergens(
                              menu.allergens
                            )}</p>
                        </div>
                        <div>
                            <h4 class="font-bold text-lg mb-1 border-b border-[#d2b48c] pb-1">Nilai Nutrisi</h4>
                            <p class="italic text-sm">Masih dalam pengembangan</p>
                        </div>
                        <div>
                            <h4 class="font-bold text-lg mb-1 border-b border-[#d2b48c] pb-1">Estimasi Biaya</h4>
                            <p class="font-semibold text-xl">Rp ${formatCost(
                              menu.estimated_cost
                            )}</p>
                        </div>
                    </div>
                </div>

                <div class="page page-right w-full md:w-1/2 p-6 md:p-8">
                    <div class="space-y-5">
                        <div>
                            <h4 class="font-bold text-lg mb-2">Bahan-bahan</h4>
                            <ul class="list-disc list-inside space-y-1 text-sm">
                                ${formatIngredients(menu.ingredients)}
                            </ul>
                        </div>
                         <div>
                            <h4 class="font-bold text-lg mb-2">Cara Memasak</h4>
                            <div class="space-y-3 text-sm leading-relaxed">
                                ${formatCookingSteps(menu.steps)}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

                menuDetailPopup.innerHTML = popupContent;
                menuDetailPopup.classList.remove("hidden");
                menuDetailPopup.classList.add("flex"); // Gunakan flex untuk centering

                // Event listener untuk tombol close (tidak berubah)
                document
                    .getElementById("close-menu-popup")
                    .addEventListener("click", () => {
                        menuDetailPopup.classList.add("hidden");
                        menuDetailPopup.classList.remove("flex");
                    });

                // Event listener untuk menutup saat klik di luar area buku (tidak berubah)
                menuDetailPopup.addEventListener("click", (event) => {
                    if (event.target === menuDetailPopup) {
                        menuDetailPopup.classList.add("hidden");
                        menuDetailPopup.classList.remove("flex");
                    }
                });
            }

            const dashboardTemplate = (data) => `
        <div id="planning-dashboard" class="max-w-3xl mx-auto space-y-6 min-h-screen flex flex-col">
            <h1 class="text-4xl font-bold text-red-900 mb-2">Buat Rencana MPASI</h1>
            <p class="text-gray-600 mb-8">Sesuaikan anggaran dan preferensi anak Anda untuk membuat rencana makan mingguan yang personal.</p>
            
            <div class="bg-white p-6 rounded-lg shadow-sm space-y-6">
                <div>
                    <label class="block text-gray-500 text-sm">Rekomendasi Budget Bulanan Anda</label>
                    <p class="font-semibold text-lg text-green-700">${
                      data.monthlyBudget
                    }</p>
                </div>
                <div>
                    <label for="weekly-budget-input" class="block text-gray-600 font-medium mb-1">Target Budget Mingguan (Rp)</label>
                    <input type="number" id="weekly-budget-input" class="w-full p-2 border rounded-md" value="${
                      data.defaultWeeklyBudget
                    }">
                </div>
                
                <div class="relative" id="allergy-select-container">
                    <label class="block text-gray-600 font-medium mb-1">Alergi Anak (opsional)</label>
                    <div id="allergy-button" class="border rounded-md w-full p-2 flex flex-wrap gap-2 items-center cursor-pointer min-h-[44px] bg-white">
                        <span class="text-gray-400">Pilih alergi...</span>
                    </div>
                    <div id="allergy-dropdown" class="absolute z-20 w-full bg-white rounded-md shadow-lg max-h-48 overflow-y-auto mt-1 hidden border">
                        <div class="p-2">
                            ${data.foodOptions
                              .map(
                                (option) => `
                                <label class="flex items-center space-x-2 p-2 text-gray-800 rounded-md hover:bg-gray-100 cursor-pointer">
                                    <input type="checkbox" value="${option}" class="form-checkbox h-4 w-4 text-red-600" ${
                                  data.currentAllergies.includes(option)
                                    ? "checked"
                                    : ""
                                }>
                                    <span>${option}</span>
                                </label>
                            `
                              )
                              .join("")}
                        </div>
                    </div>
                    <input type="hidden" id="allergy-hidden-input" name="allergy" value="${data.currentAllergies.join(
                      ","
                    )}">
                </div>

                <div class="relative" id="preference-select-container">
                    <label class="block text-gray-600 font-medium mb-1">Makanan Favorit (opsional)</label>
                    <div id="preference-button" class="border rounded-md w-full p-2 flex flex-wrap gap-2 items-center cursor-pointer min-h-[44px] bg-white">
                        <span class="text-gray-400">Pilih makanan favorit...</span>
                    </div>
                    <div id="preference-dropdown" class="absolute z-10 w-full bg-white rounded-md shadow-lg max-h-48 overflow-y-auto mt-1 hidden border">
                       <div class="p-2">
                            ${data.foodOptions
                              .map(
                                (option) => `
                                <label class="flex items-center space-x-2 p-2 text-gray-800 rounded-md hover:bg-gray-100 cursor-pointer">
                                    <input type="checkbox" value="${option}" class="form-checkbox h-4 w-4 text-red-600" ${
                                  data.currentPreferences.includes(option)
                                    ? "checked"
                                    : ""
                                }>
                                    <span>${option}</span>
                                </label>
                            `
                              )
                              .join("")}
                        </div>
                    </div>
                    <input type="hidden" id="preference-hidden-input" name="preference" value="${data.currentPreferences.join(
                      ","
                    )}">
                </div>
                <button id="generate-plan-btn" class="w-full bg-red-800 text-white font-bold py-3 px-6 rounded-lg hover:bg-red-700 transition">Buat Rencana MPASI</button>
            </div>
        </div>
    `;

            const planTemplate = (planData, startDate) => {
                const days = [
                    "Minggu",
                    "Senin",
                    "Selasa",
                    "Rabu",
                    "Kamis",
                    "Jumat",
                    "Sabtu",
                ];
                let dayContainers = "";
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                // Buat 7 container, dimulai dari startDate
                for (let i = 0; i < 7; i++) {
                    const currentDate = new Date(startDate);
                    currentDate.setDate(currentDate.getDate() + i);

                    const dayName = days[currentDate.getDay()];
                    const isPast = currentDate < today;

                    // Ambil 3 menu untuk hari ini
                    const dayPlan = planData.slice(i * 3, i * 3 + 3);

                    const mealCards = dayPlan
                        .map((menu, index) => {
                            if (!menu) return ""; // Pengaman jika data menu kurang dari 21
                            return `
        <div class="menu-card bg-white rounded-lg shadow-sm p-3 mb-2 cursor-pointer hover:bg-gray-100 transition duration-200" 
             data-details='${JSON.stringify(menu)}' 
             data-index="${i * 3 + index}"> 
            <p class="font-semibold text-sm text-red-800">${menu.menu_name}</p>
            <p class="text-xs text-gray-500 mt-1">Rp ${Number(
              menu.estimated_cost
            ).toLocaleString("id-ID")}</p>
        </div>
    `;
                        })
                        .join("");

                    dayContainers += `
            <div class="day-container bg-gray-50 rounded-lg p-4 ${
              isPast ? "opacity-60" : ""
            }">
                <h3 class="text-lg font-bold text-gray-800 mb-3 text-center">${dayName}, ${currentDate.toLocaleDateString(
        "id-ID",
        { day: "2-digit", month: "2-digit" }
      )}</h3>
                <div class="meal-cards-container space-y-2">
                    ${mealCards}
                </div>
            </div>
        `;
                }

                return `
        <div id="plan-view">
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <h2 class="text-3xl font-bold text-red-900">Rencana Menu Anda</h2>
                <div class="flex items-center gap-2">
                    <button id="export-pdf-btn" class="bg-green-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-green-700 transition">Export PDF</button>
                    <button id="edit-plan-btn" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition">Edit</button>
                    <button id="exit-plan-btn" class="bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded-lg hover:bg-gray-400 transition">Keluar</button>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-4">
                ${dayContainers}
            </div>
        </div>
    `;
            };
            // Panggil fungsi init untuk memulai aplikasi
            init();
        });
    </script>
</body>

</html>
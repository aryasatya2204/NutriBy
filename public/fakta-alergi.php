<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fakta Alergi Makanan - NutriBy</title>
    <link href="./assets/styles/output.css" rel="stylesheet">
    <style>
        .card-container {
            position: relative;
            height: 12rem;
            /* 192px */
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            display: flex;
            align-items: flex-end;
            padding: 1rem;
            color: white;
            font-weight: bold;
            font-size: 1.5rem;
            line-height: 2rem;
            overflow: hidden;
            transition: transform 0.3s ease-in-out;
        }

        .card-container:hover {
            transform: scale(1.05);
        }

        .card-background {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 1;
        }

        /* Gradient overlay untuk membuat teks lebih terbaca */
        .card-background::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to top,
                    rgba(0, 0, 0, 0.8) 0%,
                    rgba(0, 0, 0, 0.4) 50%,
                    rgba(0, 0, 0, 0.2) 100%);
            z-index: 2;
        }

        /* Style untuk nama makanan */
        .food-name {
            position: relative;
            z-index: 3;
            color: white;
            font-weight: bold;
            font-size: 1.5rem;
            line-height: 1.3;
            text-shadow: 2px 2px 8px rgba(0, 0, 0, 0.9);
            margin: 0;
            padding: 0;
            word-wrap: break-word;
            hyphens: auto;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col">

    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>

    <div id="book-popup" class="fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 z-50 hidden">
        <div class="w-full max-w-4xl h-[600px] bg-transparent perspective-1000">
            <div id="book-content" class="relative w-full h-full transform-style-3d transition-transform duration-700">
                <button id="close-popup-btn" class="absolute -top-3 -left-3 w-9 h-9 bg-red-800 text-white rounded-full flex items-center justify-center text-xl font-bold hover:bg-red-700 z-20">&times;</button>

                <div class="absolute w-full h-full flex transform-style-3d">
                    <div class="w-1/2 h-full bg-[#fdfaf3] p-8 shadow-lg overflow-y-auto">
                        <div id="left-page-content"></div>
                    </div>
                    <div class="w-1/2 h-full bg-[#fdfaf3] p-8 shadow-lg overflow-y-auto">
                        <div id="right-page-content"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="container mx-auto p-4 sm:p-8">
        <div class="max-w-3xl mx-auto">
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-gray-800">Fakta Alergi Makanan</h1>
                <p class="text-gray-500 mt-2">Cari tahu informasi lengkap mengenai alergi pada anak.</p>
            </div>

            <form id="search-form" class="bg-white p-6 rounded-lg shadow-md">
                <div class="flex items-center space-x-4 mb-4">
                    <label class="flex items-center space-x-2"><input type="radio" name="search_type" value="makanan" class="form-radio text-red-600" checked><span>Cari berdasarkan Makanan</span></label>
                    <label class="flex items-center space-x-2"><input type="radio" name="search_type" value="gejala" class="form-radio text-red-600"><span>Cari berdasarkan Gejala</span></label>
                </div>
                <div class="relative">
                    <input type="text" id="search-input" name="query" class="w-full p-4 border border-gray-300 rounded-full focus:ring-2 focus:ring-red-500" placeholder="Ketik nama makanan atau gejala..." autocomplete="off">
                    <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 bg-red-800 text-white font-semibold py-2.5 px-8 rounded-full hover:bg-red-700 transition">Cari</button>
                    <div id="suggestions-container" class="absolute z-10 w-full bg-white border mt-1 rounded-lg shadow-lg hidden"></div>
                </div>
            </form>

            <div id="results-container" class="mt-8"></div>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <!-- <script src="./assets/js/fakta-alergi.js"></script> -->
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const searchForm = document.getElementById("search-form");
            const searchInput = document.getElementById("search-input");
            const suggestionsContainer = document.getElementById("suggestions-container");
            const resultsContainer = document.getElementById("results-container");
            const bookPopup = document.getElementById("book-popup");
            const closePopupBtn = document.getElementById("close-popup-btn");

            // --- LOGIKA LIVE SEARCH (YANG DIPERBAIKI) ---
            searchInput.addEventListener("input", async () => {
                const query = searchInput.value;
                // PERUBAHAN 4: Selalu tentukan tipe dari radio button yang aktif
                const type = document.querySelector(
                    'input[name="search_type"]:checked'
                ).value;

                if (query.length < 1) {
                    suggestionsContainer.innerHTML = "";
                    suggestionsContainer.classList.add("hidden");
                    return;
                }

                // Panggil API suggestions dengan tipe yang sesuai
                const response = await fetch(
                    `api/api_suggestions.php?type=${type}&query=${query}`
                );
                const suggestions = await response.json();

                suggestionsContainer.innerHTML = "";
                if (suggestions.length > 0) {
                    suggestions.forEach((suggestion) => {
                        const div = document.createElement("div");
                        div.textContent = suggestion;
                        div.className = "p-2 hover:bg-gray-100 cursor-pointer";
                        div.onclick = () => {
                            searchInput.value = suggestion;
                            suggestionsContainer.classList.add("hidden");
                            searchForm.requestSubmit();
                        };
                        suggestionsContainer.appendChild(div);
                    });
                    suggestionsContainer.classList.remove("hidden");
                } else {
                    suggestionsContainer.classList.add("hidden");
                }
            });

            // --- LOGIKA PENCARIAN UTAMA ---
            searchForm.addEventListener("submit", async (e) => {
                e.preventDefault();
                const query = searchInput.value;
                const type = document.querySelector(
                    'input[name="search_type"]:checked'
                ).value;

                if (!query) return;

                suggestionsContainer.classList.add("hidden");
                resultsContainer.innerHTML = '<p class="text-center">Mencari...</p>';

                const response = await fetch(
                    `api/api_search_results.php?type=${type}&query=${query}`
                );
                const result = await response.json();

                renderResults(result);
            });

            function renderResults(result) {
                resultsContainer.innerHTML = "";
                if (!result || result.error || (result.data && result.data.length === 0)) {
                    resultsContainer.innerHTML = `<div class="bg-white rounded-lg shadow-md p-8 text-center"><h3 class="text-xl font-semibold text-gray-700">Pencarian Tidak Ditemukan</h3><p class="text-gray-500 mt-2">Maaf, kami tidak dapat menemukan informasi untuk pencarian Anda.</p></div>`;
                    return;
                }

                if (result.type === "food_details") {
                    showBookPopup(result.data);
                } else if (result.type === "food_list") {
                    const grid = document.createElement("div");
                    grid.className = "grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6";

                    result.data.forEach((foodData) => {
                        const card = document.createElement("div");
                        // Menggunakan class card-container yang sudah ada di CSS
                        card.className = "card-container";

                        // Gunakan image_url dari database. Jika kosong, berikan fallback.
                        const imageUrl = foodData.image_url || `https://source.unsplash.com/400x300/?food,${foodData.food_name}`;

                        // Buat elemen background image
                        const bgImage = document.createElement('div');
                        bgImage.className = 'card-background';
                        bgImage.style.backgroundImage = `url('${imageUrl}')`;

                        // Buat elemen untuk nama makanan
                        const foodNameElement = document.createElement('h3');
                        foodNameElement.className = 'food-name';
                        foodNameElement.textContent = foodData.food_name;

                        // Simpan seluruh data di dalam 'dataset' kartu
                        card.dataset.details = JSON.stringify(foodData);

                        // Susun struktur: background dulu, kemudian nama makanan
                        card.appendChild(bgImage);
                        card.appendChild(foodNameElement);

                        card.onclick = () => {
                            const details = JSON.parse(card.dataset.details);
                            showBookPopup(details);
                        };
                        grid.appendChild(card);
                    });
                    resultsContainer.appendChild(grid);
                }
            }

            function showBookPopup(data) {
                const leftPage = document.getElementById("left-page-content");
                const rightPage = document.getElementById("right-page-content");

                if (data === null) {
                    // Tampilkan status loading
                    leftPage.innerHTML = `<h3 class="text-2xl font-serif text-gray-800">Memuat...</h3>`;
                    rightPage.innerHTML = `<p class="text-gray-600">Silakan tunggu sebentar.</p>`;
                } else {
                    // Isi dengan data asli
                    const symptomsList = (data.symptoms || [])
                        .map((symptom) => `<li>${symptom}</li>`)
                        .join("");
                    leftPage.innerHTML = `
            <h3 class="text-2xl font-serif text-gray-800 border-b-2 border-gray-300 pb-2 mb-4">Gejala & Penyebab</h3>
            <div class="space-y-4">
                <div>
                    <h4 class="font-bold text-gray-700">Gejala Umum:</h4>
                    <ul class="list-disc list-inside text-gray-600">${symptomsList}</ul>
                </div>
                <div>
                    <h4 class="font-bold text-gray-700">Penyebab:</h4>
                    <p class="text-gray-600">${
                      data.cause || "Informasi tidak tersedia."
                    }</p>
                </div>
            </div>`;

                    rightPage.innerHTML = `
            <h3 class="text-2xl font-serif text-gray-800 border-b-2 border-gray-300 pb-2 mb-4">Efek & Penanganan</h3>
            <div class="space-y-4">
                <div>
                    <h4 class="font-bold text-gray-700">Efek yang Dihasilkan:</h4>
                    <p class="text-gray-600">${
                      data.effects || "Informasi tidak tersedia."
                    }</p>
                </div>
                <div>
                    <h4 class="font-bold text-gray-700">Penanganan:</h4>
                    <p class="text-gray-600">${
                      data.handling || "Informasi tidak tersedia."
                    }</p>
                </div>
            </div>`;
                }

                bookPopup.classList.remove("hidden");
            }

            closePopupBtn.addEventListener("click", () =>
                bookPopup.classList.add("hidden")
            );
            bookPopup.addEventListener("click", (e) => {
                if (e.target === bookPopup) {
                    bookPopup.classList.add("hidden");
                }
            });
        });
    </script>
    </bodmin-h-screen>

</html>
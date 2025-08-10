<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Proteksi halaman: jika tidak login, tendang ke halaman login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// --- LOGIKA PENGAMBILAN DATA ANAK AKTIF ---
$child_age_display = 'Data anak tidak ditemukan';
if (isset($_SESSION['active_child_id'])) {
    $stmt = $pdo->prepare("SELECT birth_date FROM children WHERE id = ?");
    $stmt->execute([$_SESSION['active_child_id']]);
    $child = $stmt->fetch();

    if ($child) {
        $birthDate = new DateTime($child['birth_date']);
        $today = new DateTime('today');
        $age = $today->diff($birthDate);
        $age_months = $age->y * 12 + $age->m;
        $age_days = $age->d;
        $child_age_display = "{$age_months} bulan {$age_days} hari";
    }
}

// --- BACA DATA FAQ DARI FILE JSON (DENGAN PENGAMAN) ---
$faq_data = [];
$json_path = __DIR__ . '/../data/informasi.json';
if (file_exists($json_path)) {
    $json_content = file_get_contents($json_path);
    $faq_data = json_decode($json_content); // Biarkan jadi objek agar sesuai kode lama
}

// --- LOGIKA UNTUK MENAMPILKAN POP-UP ---
$show_popup = false;
$rekomendasi = [];
if (isset($_SESSION['show_recommendation_popup'])) {
    $show_popup = true;
    $rekomendasi = $_SESSION['show_recommendation_popup'];
    // Hapus sesi setelah data diambil agar tidak muncul lagi saat refresh
    unset($_SESSION['show_recommendation_popup']);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - NutriBy</title>
    <link href="./assets/styles/output.css" rel="stylesheet">
</head>

<body class="bg-white text-gray-800">

    <?php if ($show_popup && !empty($rekomendasi)): ?>
        <div id="recommendation-popup" class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center p-4 z-50 transition-opacity duration-300">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6 md:p-8 transform transition-all duration-300 scale-95 opacity-0" id="popup-content">

                <div class="text-center">
                    <?php
                    $panggilan = ($rekomendasi['gender'] ?? 'laki-laki') === 'laki-laki' ? 'Pangeran' : 'Putri';
                    ?>
                    <h2 class="text-2xl font-bold text-gray-800">
                        Sang <?= $panggilan ?>, <span class="text-red-700"><?= htmlspecialchars($rekomendasi['child_name'] ?? 'Anak Hebat') ?></span> telah tiba!
                    </h2>
                    <p class="text-gray-500 mt-1">Berikut adalah analisis gizi awal untuknya.</p>
                </div>

                <div class="mt-6 border-t pt-6 space-y-4 text-sm">
                    <h3 class="font-semibold text-gray-700 text-base mb-2">Informasi Umum</h3>

                    <ul class="space-y-2">
                        <li class="flex items-start">
                            <span class="mr-2 mt-1">⚖️</span>
                            <div>
                                Berat badan (BB/U) anak Anda pada usia <?= htmlspecialchars($rekomendasi['age'] ?? '?') ?> bulan dinilai
                                <strong class="font-semibold"><?= htmlspecialchars($rekomendasi['detailed_results']['weight_for_age']['status'] ?? 'Normal') ?></strong>.
                            </div>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2 mt-1">📏</span>
                            <div>
                                Tinggi badan (TB/U) anak Anda pada usia <?= htmlspecialchars($rekomendasi['age'] ?? '?') ?> bulan dinilai
                                <strong class="font-semibold"><?= htmlspecialchars($rekomendasi['detailed_results']['height_for_age']['status'] ?? 'Normal') ?></strong>.
                            </div>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2 mt-1">❤️</span>
                            <div>
                                Proporsi berat dan tinggi badan (BB/TB) anak Anda adalah
                                <strong class="font-semibold"><?= htmlspecialchars($rekomendasi['detailed_results']['weight_for_height']['status'] ?? 'Normal') ?></strong>.
                            </div>
                        </li>
                    </ul>
                    <div class="p-4 rounded-lg bg-<?= $rekomendasi['status_color'] ?? 'green' ?>-100 text-center mt-4">
                        <p class="text-xs font-semibold uppercase text-<?= $rekomendasi['status_color'] ?? 'green' ?>-800">Rangkuman Status Gizi</p>
                        <p class="text-lg font-bold text-<?= $rekomendasi['status_color'] ?? 'green' ?>-900">
                            <?= htmlspecialchars($rekomendasi['status_gizi'] ?? 'Status Gizi Baik') ?>
                        </p>
                    </div>

                    <div class="p-4 rounded-lg bg-green-50 border border-green-200">
                        <label class="block text-green-800 font-semibold">Rekomendasi Budget MPASI Bulanan</label>
                        <p class="text-green-700 font-bold text-lg"><?= htmlspecialchars($rekomendasi['budget_range'] ?? 'Belum dihitung') ?></p>
                        <span class="font-normal text-base">(rekomendasi budget sedang tahap optimalisasi)</span>
                    </div>
                </div>

                <button id="close-popup-btn" class="mt-8 bg-red-900 text-white font-semibold py-3 rounded-full shadow-md transition hover:bg-red-800 w-full">
                    Mulai Jelajahi
                </button>
            </div>
        </div>
    <?php endif; ?>
    <div class="flex flex-col min-h-screen">
        <!-- HEADER / NAVBAR -->
        <header class="bg-white sticky top-0 z-40 border-b">
            <nav class="container mx-auto px-4 sm:px-6 py-3 flex justify-between items-center">
                <!-- Kiri: Profil & Usia Anak -->
                <div class="flex items-center space-x-3">
                    <a href="profile.php" class="p-2 rounded-full hover:bg-gray-100">
                        <svg class="h-8 w-8 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                    </a>
                    <div class="hidden sm:block">
                        <div class="text-sm font-semibold text-red-800"><?= htmlspecialchars($child_age_display) ?></div>
                    </div>
                </div>
                <!-- Tengah: Logo -->
                <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 flex items-center space-x-2">
                    <div class="w-10 h-10 bg-red-800 rounded-full flex items-center justify-center">
                        <svg src="./assets/img/logo.jpg" class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75s.168-.75.375-.75S9.75 9.336 9.75 9.75zm4.5 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75z" />
                        </svg>
                    </div>
                    <span class="font-bold text-2xl text-red-800 hidden md:block">NutriBy</span>
                </div>
                <!-- Kanan: Placeholder -->
                <div class="w-10 h-10"></div>
            </nav>
        </header>

        <!-- KONTEN UTAMA (Fleksibel) -->
        <main class="flex-grow flex flex-col bg-gradient-to-br from-red-50 via-white to-orange-50">
            <!-- Navigasi Tab -->
            <div class="">
                <div class="container mx-auto flex justify-center space-x-10 font-semibold text-gray-500 ">
                    <a href="#" id="info-tab" class="tab-link py-3 px-2">INFORMATION</a>
                    <a href="#" id="features-tab" class="tab-link py-3 px-2">FEATURES</a>
                </div>
            </div>

            <!-- Tampilan "Information" (Default) -->
            <section id="information-section" class="page-section flex-grow flex flex-col ">
                <!-- Hero Section Slider dengan Glass Effect -->
                <div class="container mx-auto px-4 sm:px-6 py-12 md:py-16">
                    <div class="text-center mb-8">
                        <h2 class="text-3xl md:text-4xl font-bold text-gray-800 mb-2">
                            Informasi
                            <span class="text-transparent bg-clip-text bg-gradient-to-r from-red-600 to-orange-600">
                                Terpercaya
                            </span>
                        </h2>
                        <p class="text-gray-600 text-lg">Dapatkan informasi nutrisi terbaru untuk si kecil</p>
                    </div>

                    <div id="hero-slider" class="relative w-full max-w-4xl mx-auto">
                        <?php
                        // Ambil 7 slide secara acak dari database
                        $stmt_slides = $pdo->query("SELECT * FROM informational_slides ORDER BY RAND() LIMIT 7");
                        $slides = $stmt_slides->fetchAll(PDO::FETCH_ASSOC);

                        // Jika ada slide, tampilkan
                        if ($slides):
                            foreach ($slides as $index => $slide):
                        ?>
                                <div class="slide cursor-pointer <?= $index > 0 ? 'hidden' : '' ?> transform transition-all duration-500 hover:scale-[1.02]"
                                    data-question="<?= htmlspecialchars($slide['title']) ?>"
                                    data-answer="<?= htmlspecialchars($slide['full_answer']) ?>">

                                    <div class="relative group overflow-hidden rounded-3xl shadow-2xl bg-white/80 backdrop-blur-sm border border-white/20">
                                        <!-- Background Pattern -->
                                        <div class="absolute inset-0 bg-gradient-to-br from-red-100/50 to-orange-100/50"></div>
                                        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-red-200/30 to-transparent rounded-full blur-2xl"></div>
                                        <div class="absolute bottom-0 left-0 w-24 h-24 bg-gradient-to-tr from-orange-200/30 to-transparent rounded-full blur-xl"></div>

                                        <div class="relative p-8 md:p-12">
                                            <div class="flex flex-col md:flex-row items-center gap-8">
                                                <!-- Content Side -->
                                                <div class="flex-1 text-center md:text-left">
                                                    <div class="inline-flex items-center px-4 py-2 rounded-full bg-gradient-to-r from-red-500 to-orange-500 text-white text-sm font-medium mb-4 shadow-lg">
                                                        <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                                        </svg>
                                                        Info Terpercaya
                                                    </div>

                                                    <h3 class="text-2xl md:text-3xl font-bold text-gray-800 mb-4 leading-tight">
                                                        <?= htmlspecialchars($slide['title']) ?>
                                                    </h3>

                                                    <p class="text-gray-600 text-lg mb-6 leading-relaxed">
                                                        <?= htmlspecialchars(substr($slide['full_answer'], 0, 120)) ?>...
                                                    </p>

                                                    <div class="inline-flex items-center text-red-600 font-semibold group-hover:text-red-700 transition-colors">
                                                        Baca Selengkapnya
                                                        <svg class="w-5 h-5 ml-2 transform group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                                        </svg>
                                                    </div>
                                                </div>

                                                <!-- Image Side -->
                                                <div class="flex-shrink-0">
                                                    <?php if ($slide['image_url']): ?>
                                                        <div class="relative">
                                                            <div class="w-64 h-48 md:w-80 md:h-60 rounded-2xl overflow-hidden shadow-xl ring-4 ring-white/50">
                                                                <img src="<?= htmlspecialchars($slide['image_url']) ?>"
                                                                    alt="<?= htmlspecialchars($slide['title']) ?>"
                                                                    class="w-full h-full object-cover transform group-hover:scale-110 transition-transform duration-500">
                                                            </div>
                                                            <!-- Floating Elements -->
                                                            <div class="absolute -top-4 -right-4 w-12 h-12 bg-gradient-to-br from-yellow-400 to-orange-500 rounded-full flex items-center justify-center shadow-lg animate-bounce">
                                                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd" />
                                                                </svg>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="w-64 h-48 md:w-80 md:h-60 bg-gradient-to-br from-gray-200 to-gray-300 rounded-2xl flex items-center justify-center text-gray-500 shadow-xl ring-4 ring-white/50">
                                                            <div class="text-center">
                                                                <svg class="w-16 h-16 mx-auto mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                                </svg>
                                                                <span class="text-sm">Gambar Segera Hadir</span>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                        <?php
                            endforeach;
                        endif;
                        ?>

                        <!-- Navigation Buttons -->
                        <button class="slider-nav prev absolute top-1/2 -left-6 md:-left-12 transform -translate-y-1/2 w-12 h-12 bg-white/90 backdrop-blur-sm border border-gray-200 rounded-full shadow-lg text-gray-600 hover:text-red-600 hover:border-red-200 transition-all duration-300 hover:scale-110">
                            <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <button class="slider-nav next absolute top-1/2 -right-6 md:-right-12 transform -translate-y-1/2 w-12 h-12 bg-white/90 backdrop-blur-sm border border-gray-200 rounded-full shadow-lg text-gray-600 hover:text-red-600 hover:border-red-200 transition-all duration-300 hover:scale-110">
                            <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>

                        <!-- Dots Indicator -->
                        <div class="slider-dots absolute -bottom-8 left-1/2 -translate-x-1/2 flex space-x-3"></div>
                    </div>
                </div>

                <!-- Accordion Section dengan Modern Card Design -->
                <div class="bg-gradient-to-br from-amber-400 via-yellow-400 to-orange-400 py-16 px-4 sm:px-6 mt-auto relative overflow-hidden">
                    <!-- Background Pattern -->
                    <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=" 60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg" %3E%3Cg fill="none" fill-rule="evenodd" %3E%3Cg fill="%23ffffff" fill-opacity="0.1" %3E%3Ccircle cx="30" cy="30" r="2" /%3E%3C/g%3E%3C/g%3E%3C/svg%3E')] opacity-30"></div>

                    <div class="container mx-auto text-center relative z-10">
                        <div class="mb-12">
                            <div class="inline-flex items-center px-6 py-3 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 text-gray-800 font-medium mb-4">
                                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 13V5a2 2 0 00-2-2H4a2 2 0 00-2 2v8a2 2 0 002 2h3l3 3 3-3h3a2 2 0 002-2zM5 7a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1zm1 3a1 1 0 100 2h3a1 1 0 100-2H6z" clip-rule="evenodd" />
                                </svg>
                                FAQ Terpopuler
                            </div>
                            <h3 class="text-3xl md:text-4xl font-bold text-gray-800 mb-4">
                                Kenali
                                <span class="text-white drop-shadow-lg">Malnutrisi</span>
                                Pada Anak
                            </h3>
                            <p class="text-gray-700 text-lg max-w-2xl mx-auto">
                                Temukan jawaban untuk pertanyaan-pertanyaan penting seputar nutrisi dan kesehatan anak
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-7xl mx-auto" id="faq-accordion">
                            <?php if (!empty($faq_data)): ?>
                                <?php foreach ($faq_data as $index => $item): ?>
                                    <button class="accordion-button group bg-white/90 backdrop-blur-sm rounded-2xl p-6 shadow-lg hover:shadow-xl transition-all duration-300 hover:-translate-y-2 border border-white/50 text-left"
                                        data-question="<?= htmlspecialchars($item->question) ?>"
                                        data-answer="<?= htmlspecialchars($item->answer) ?>">

                                        <!-- Icon -->
                                        <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-orange-500 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform shadow-lg">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>

                                        <!-- Content -->
                                        <div class="flex-1">
                                            <h4 class="font-bold text-gray-800 text-lg mb-2 group-hover:text-red-600 transition-colors">
                                                <?= htmlspecialchars($item->question) ?>
                                            </h4>
                                            <p class="text-gray-600 text-sm leading-relaxed">
                                                Klik untuk membaca penjelasan lengkap
                                            </p>
                                        </div>

                                        <!-- Arrow Icon -->
                                        <div class="flex items-center justify-center w-8 h-8 rounded-full bg-gray-100 group-hover:bg-red-100 transition-colors mt-4 ml-auto">
                                            <svg class="w-4 h-4 text-gray-500 group-hover:text-red-500 transform group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </div>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Call to Action -->
                        <div class="mt-12 text-center">
                            <p class="text-gray-700 text-lg mb-4">Masih ada pertanyaan lain?</p>
                            <button class="inline-flex items-center px-8 py-4 bg-white text-gray-800 font-semibold rounded-full shadow-lg hover:shadow-xl transition-all duration-300 hover:-translate-y-1 border border-white/50">
                                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" />
                                </svg>
                                Hubungi Ahli Gizi Terdekat
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tampilan "Features" (Tersembunyi) -->
            <section id="features-section" class="page-section hidden flex-grow flex items-center justify-center">
                <div class="container mx-auto py-12 px-4 sm:px-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8 max-w-5xl mx-auto">
                        <!-- Card 1 -->
                        <a href="rekomendasi-nutrisi.php" class="feature-card group relative block h-64 md:h-80 rounded-2xl overflow-hidden shadow-lg transition-transform duration-300 hover:-translate-y-2">
                            <img src="./assets/img/main/rekomendasi.jpg" alt="Rekomendasi Nutrisi Anak" class="absolute inset-0 object-cover w-full h-full z-0">
                            <div class="absolute inset-0 bg-black/40"></div>
                            <div class="absolute inset-0 flex items-center justify-center p-4">
                                <h3 class="text-white text-2xl md:text-3xl font-bold text-center">Rekomendasi<br>Nutrisi Anak</h3>
                            </div>
                        </a>
                        <!-- Card 2 -->
                        <a href="fakta-alergi.php" class="feature-card group relative block h-64 md:h-80 rounded-2xl overflow-hidden shadow-lg transition-transform duration-300 hover:-translate-y-2">
                            <img src="./assets/img/main/alergi.jpg" alt="Alergi" class="absolute inset-0 object-cover w-full h-full z-0">
                            <div class="absolute inset-0 bg-black/40"></div>
                            <div class="absolute inset-0 flex items-center justify-center p-4">
                                <h3 class="text-white text-2xl md:text-3xl font-bold text-center">Fakta<br>Alergi</h3>
                            </div>
                        </a>
                        <!-- Card 3 -->
                        <a href="rencana-mpasi.php" class="feature-card group relative block h-64 md:h-80 rounded-2xl overflow-hidden shadow-lg transition-transform duration-300 hover:-translate-y-2">
                            <img src="./assets/img//main/mpasi.jpg" alt="MPASI" class="absolute inset-0 object-cover w-full h-full z-0">
                            <div class="absolute inset-0 bg-black/40"></div>
                            <div class="absolute inset-0 flex items-center justify-center p-4">
                                <h3 class="text-white text-2xl md:text-3xl font-bold text-center">Rencana<br>MPASI</h3>
                            </div>
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <!-- FOOTER -->
        <footer class="bg-gray-800 text-white py-3">
            <div class="container mx-auto text-center text-xs sm:text-sm">
                &copy; Copyright by NutriBy
            </div>
        </footer>
    </div>

    <!-- POP-UP / MODAL (Tersembunyi) -->
    <div id="faq-modal" class="fixed inset-0 bg-black/60 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-gray-200 rounded-lg shadow-xl w-full max-w-lg relative p-6 md:p-8">
            <button id="modal-close-btn" class="absolute -top-3 -right-3 w-9 h-9 bg-red-800 text-white rounded-full flex items-center justify-center text-xl font-bold hover:bg-red-700">&times;</button>
            <h3 id="modal-question" class="text-xl md:text-2xl font-bold text-gray-800 mb-4"></h3>
            <p id="modal-answer" class="text-gray-600 leading-relaxed"></p>
        </div>
    </div>

    <!-- <script src="./assets/js/main-menu.js"></script> -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- PENGONTROL TAB (INFORMATION / FEATURES) ---
            const infoTab = document.getElementById('info-tab');
            const featuresTab = document.getElementById('features-tab');
            const infoSection = document.getElementById('information-section');
            const featuresSection = document.getElementById('features-section');

            function switchTab(activeTab) {
                // Atur style link tab
                infoTab.classList.remove('text-red-800', 'border-b-2', 'border-red-800');
                featuresTab.classList.remove('text-red-800', 'border-b-2', 'border-red-800');
                activeTab.classList.add('text-red-800', 'border-b-2', 'border-red-800');

                // Tampilkan section yang relevan
                if (activeTab === infoTab) {
                    infoSection.classList.remove('hidden');
                    featuresSection.classList.add('hidden');
                } else {
                    infoSection.classList.add('hidden');
                    featuresSection.classList.remove('hidden');
                }
            }

            infoTab.addEventListener('click', (e) => {
                e.preventDefault();
                switchTab(infoTab);
            });
            featuresTab.addEventListener('click', (e) => {
                e.preventDefault();
                switchTab(featuresTab);
            });

            // Inisialisasi tab pertama
            switchTab(infoTab);


            // --- LOGIKA HERO SLIDER ---
            const sliderContainer = document.getElementById('hero-slider');
            if (sliderContainer) {
                const slides = sliderContainer.querySelectorAll('.slide');
                const dotsContainer = sliderContainer.querySelector('.slider-dots');
                let currentSlide = 0;
                let slideInterval;

                if (slides.length > 0) {
                    slides.forEach((_, i) => {
                        const dot = document.createElement('button');
                        dot.classList.add('w-2.5', 'h-2.5', 'rounded-full', 'bg-gray-400', 'hover:bg-red-800');
                        dot.addEventListener('click', () => {
                            showSlide(i);
                            resetInterval();
                        });
                        dotsContainer.appendChild(dot);
                    });
                    const dots = dotsContainer.querySelectorAll('button');

                    function showSlide(index) {
                        slides.forEach((slide, i) => {
                            slide.classList.toggle('hidden', i !== index);
                        });
                        dots.forEach((dot, i) => {
                            dot.classList.toggle('bg-red-800', i === index);
                            dot.classList.toggle('bg-gray-400', i !== index);
                        });
                        currentSlide = index;
                    }

                    function nextSlide() {
                        showSlide((currentSlide + 1) % slides.length);
                    }

                    function resetInterval() {
                        clearInterval(slideInterval);
                        slideInterval = setInterval(nextSlide, 5000);
                    }

                    sliderContainer.querySelector('.slider-nav.next').addEventListener('click', () => {
                        nextSlide();
                        resetInterval();
                    });
                    sliderContainer.querySelector('.slider-nav.prev').addEventListener('click', () => {
                        showSlide((currentSlide - 1 + slides.length) % slides.length);
                        resetInterval();
                    });

                    slideInterval = setInterval(nextSlide, 5000);
                    showSlide(0);
                }
            }


            // --- LOGIKA POP-UP (MODAL) ---
            const modal = document.getElementById('faq-modal');
            const modalQuestion = document.getElementById('modal-question');
            const modalAnswer = document.getElementById('modal-answer');
            const modalCloseBtn = document.getElementById('modal-close-btn');

            function openModal(question, answer) {
                modalQuestion.textContent = question;
                modalAnswer.textContent = answer;
                modal.classList.remove('hidden');
            }

            function closeModal() {
                modal.classList.add('hidden');
            }

            // Event listener untuk tombol accordion
            document.querySelectorAll('.accordion-button').forEach(button => {
                button.addEventListener('click', function() {
                    openModal(this.dataset.question, this.dataset.answer);
                });
            });

            // Event listener untuk kartu slider yang bisa diklik
            document.querySelectorAll('#hero-slider .slide').forEach(slideCard => {
                slideCard.addEventListener('click', function() {
                    openModal(this.dataset.question, this.dataset.answer);
                });
            });

            // Event listener untuk menutup modal
            modalCloseBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
        });
    </script>

    <script>
        // JavaScript sederhana untuk menampilkan dan menutup pop-up dengan animasi
        document.addEventListener('DOMContentLoaded', function() {
            const popup = document.getElementById('recommendation-popup');
            const popupContent = document.getElementById('popup-content');
            const closeBtn = document.getElementById('close-popup-btn');

            // Tampilkan pop-up dengan animasi
            setTimeout(() => {
                popup.classList.add('opacity-100');
                popupContent.classList.remove('scale-95', 'opacity-0');
                popupContent.classList.add('scale-100', 'opacity-100');
            }, 100);

            function closeModal() {
                popup.classList.remove('opacity-100');
                setTimeout(() => {
                    popup.style.display = 'none';
                }, 300);
            }

            closeBtn.addEventListener('click', closeModal);
        });
    </script>
</body>

</html>
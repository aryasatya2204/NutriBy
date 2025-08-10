<?php
/**
 * =====================================================================
 * GENERATOR RINGKASAN GIZI - VERSI REVISI
 * =====================================================================
 * Versi: 2.0 (Revised Logic)
 * * Menerima input kode standar (K1/T1/WH1) dari kalkulator.
 * * Menggunakan lookup table untuk 27 kombinasi diagnosis detail.
 * * Menghasilkan 5 kategori ringkasan yang mudah dipahami orang tua.
 */

/**
 * Menerjemahkan kode status gizi menjadi diagnosis lengkap dan ringkasan sederhana.
 * @param array $results_code Output dari WHONutritionCalculatorRevised, berisi ['wfa', 'hfa', 'wfh'].
 * @return array Berisi 'detailed_diagnosis' dan 'parent_summary'.
 */
function generateNutritionSummary($results_code) {
    $wfa = $results_code['wfa']; // Kode Berat Badan per Umur
    $hfa = $results_code['hfa']; // Kode Tinggi Badan per Umur
    $wfh = $results_code['wfh']; // Kode Berat Badan per Tinggi

    // =================================================================
    // LANGKAH 1: TERJEMAHKAN KOMBINASI KODE MENJADI DIAGNOSIS DETAIL
    // =================================================================
    $combinationKey = "{$wfa}_{$hfa}_{$wfh}"; // Contoh: "K1_T1_WH1"

    // Lookup table untuk 27 kemungkinan kombinasi
    $lookupTable = [
        // Kombinasi BB Kurang (K1)
        'K1_T1_WH1' => 'Malnutrisi Berat (Severe Malnutrition)',
        'K1_T1_WH2' => 'Stunting dengan Berat Badan Rendah',
        'K1_T1_WH3' => 'Kasus Jarang (Indikasi Gangguan Medis/Edema)',
        'K1_T2_WH1' => 'Gizi Kurang Akut (Moderate Wasting)',
        'K1_T2_WH2' => 'Berat Badan Kurang (Underweight)',
        'K1_T2_WH3' => 'Kasus Jarang (Kemungkinan Edema/Error Data)',
        'K1_T3_WH1' => 'Gagal Tumbuh Akut pada Anak Tinggi (Perlu Perhatian Medis)',
        'K1_T3_WH2' => 'Anak Tinggi dengan Berat Badan Kurang',
        'K1_T3_WH3' => 'Kasus Sangat Jarang (Indikasi Medis)',

        // Kombinasi BB Normal (K2)
        'K2_T1_WH1' => 'Stunting dengan Gizi Kurang Akut (Stunted & Wasted)',
        'K2_T1_WH2' => 'Stunting (Tinggi Kurang, Berat Proporsional)',
        'K2_T1_WH3' => 'Stunting dengan Risiko Gizi Lebih',
        'K2_T2_WH1' => 'Gizi Kurang (Wasted)',
        'K2_T2_WH2' => 'Normal Sehat',
        'K2_T2_WH3' => 'Risiko Gizi Lebih (Overweight)',
        'K2_T3_WH1' => 'Anak Tinggi dan Kurus',
        'K2_T3_WH2' => 'Anak Tinggi Sehat',
        'K2_T3_WH3' => 'Anak Tinggi dengan Risiko Gizi Lebih',

        // Kombinasi BB Lebih (K3)
        'K3_T1_WH1' => 'Data Anomali (Cek Ulang Pengukuran)',
        'K3_T1_WH2' => 'Stunting dengan Gizi Lebih (Stunted & Overweight)',
        'K3_T1_WH3' => 'Stunting dengan Obesitas',
        'K3_T2_WH1' => 'Data Anomali (Cek Ulang Pengukuran)',
        'K3_T2_WH2' => 'Gizi Lebih (Overweight)',
        'K3_T2_WH3' => 'Obesitas',
        'K3_T3_WH1' => 'Data Anomali (Anak Tinggi Tidak Mungkin Kurus dengan BB Lebih)',
        'K3_T3_WH2' => 'Anak Tinggi dengan Gizi Lebih',
        'K3_T3_WH3' => 'Anak Tinggi dengan Obesitas'
    ];

    $detailed_diagnosis = $lookupTable[$combinationKey] ?? 'Kondisi Tidak Terdefinisi';

    // =================================================================
    // LANGKAH 2: KELOMPOKKAN DIAGNOSIS DETAIL KE 5 KATEGORI SEDERHANA
    // =================================================================
    $parent_summary = '';
    
    // Logika pengelompokan berdasarkan prioritas dari yang paling parah
    if ($wfa === 'K1' && $hfa === 'T1' && $wfh === 'WH1') {
        $parent_summary = 'Malnutrisi Berat';
    } elseif ($wfh === 'WH1' || $hfa === 'T1' || $wfa === 'K1') {
        $parent_summary = 'Risiko Gizi Kurang';
    } elseif ($wfh === 'WH3') {
        $parent_summary = ($wfa === 'K3') ? 'Obesitas / Gizi Lebih Parah' : 'Gizi Lebih';
    } elseif ($wfa === 'K3') {
        $parent_summary = 'Gizi Lebih';
    } else {
        $parent_summary = 'Normal Sehat';
    }

    $indicator_details = [
        'weight_for_age' => [
            'status' => ($wfa === 'K1' ? 'Berat Badan Kurang' : ($wfa === 'K3' ? 'Berat Badan Lebih' : 'Normal'))
        ],
        'height_for_age' => [
            'status' => ($hfa === 'T1' ? 'Pendek (Stunting)' : ($hfa === 'T3' ? 'Tinggi' : 'Normal'))
        ],
        'weight_for_height' => [
            'status' => ($wfh === 'WH1' ? 'Kurus (Wasted)' : ($wfh === 'WH3' ? 'Gemuk (Overweight/Obese)' : 'Normal'))
        ]
    ];

    return [
        'detailed_diagnosis' => $detailed_diagnosis,
        'parent_summary'     => $parent_summary,
        'color'              => getColorForSummary($parent_summary),
        'indicator_details'  => $indicator_details
    ];
}

/**
 * Helper function untuk memberikan kode warna berdasarkan ringkasan.
 */
function getColorForSummary($summary) {
    switch ($summary) {
        case 'Malnutrisi Berat':
        case 'Obesitas / Gizi Lebih Parah':
            return 'danger'; // Merah
        case 'Risiko Gizi Kurang':
        case 'Gizi Lebih':
            return 'warning'; // Kuning
        case 'Normal Sehat':
            return 'success'; // Hijau
        default:
            return 'info'; // Biru
    }
}

?>
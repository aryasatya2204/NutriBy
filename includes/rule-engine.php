<?php

class BudgetRuleEngine
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function generateBudgetRecommendation($child_id, $ageMonths, $gaji, $nutrition_summary, $allergies, $preferences)
    {
        try {
            // Langkah 1: Tentukan tujuan dan BATAS ATAS BUDGET yang realistis
            $goalsAndCap = $this->determineGoalsAndBudgetCap($gaji, $nutrition_summary);
            $monthly_budget_cap = $goalsAndCap['monthly_budget_cap'];

            // Langkah 2: Dapatkan daftar menu yang sudah difilter
            $menu_pool = $this->getFilteredMenuPool($ageMonths, $allergies);
            if (empty($menu_pool)) {
                throw new Exception("Tidak ada menu yang cocok setelah filter usia dan alergi.");
            }

            // Langkah 3: Beri skor pada setiap menu dengan sistem scoring cerdas (ada penalti)
            $scored_menus = $this->scoreMenus($menu_pool, $goalsAndCap, $preferences);
            
            // Urutkan menu berdasarkan skor tertinggi, lalu harga termurah
            usort($scored_menus, function($a, $b) {
                if ($b['final_score'] == $a['final_score']) {
                    return $a['estimated_cost'] <=> $b['estimated_cost'];
                }
                return $b['final_score'] <=> $a['final_score'];
            });

            // Langkah 4: Lakukan simulasi terkendali yang tidak akan melebihi budget cap
            $simulated_plan = $this->runConstrainedSimulation($scored_menus, 90, $monthly_budget_cap);

            // Langkah 5: Hitung total biaya dari hasil simulasi
            $total_monthly_cost = array_sum(array_column($simulated_plan, 'estimated_cost'));

            // Langkah 6: Buat rentang budget yang rapi berdasarkan hasil simulasi
            // Rentang ini akan selalu di bawah atau sama dengan budget cap
            $range_min = floor(($total_monthly_cost * 0.95) / 1000) * 1000;
            $range_max = ceil($total_monthly_cost / 1000) * 1000;
            $budget_string = "Rp " . number_format($range_min, 0, ',', '.') . " - Rp " . number_format($range_max, 0, ',', '.');

            // Langkah 7: Simpan rekomendasi ke database
            $this->saveRecommendation($child_id, $budget_string, $nutrition_summary['parent_summary']);

            return $budget_string;

        } catch (Exception $e) {
            error_log("BudgetRuleEngine Error: " . $e->getMessage());
            // Jika simulasi gagal, berikan rekomendasi berdasarkan budget cap
            if (isset($monthly_budget_cap)) {
                $safe_budget = floor($monthly_budget_cap / 10000) * 10000;
                return "Estimasi di bawah Rp " . number_format($safe_budget, 0, ',', '.');
            }
            return "Gagal membuat rekomendasi budget.";
        }
    }
    
    /**
     * [BARU] Menentukan tujuan gizi dan MENGHITUNG BUDGET CAP.
     */
    private function determineGoalsAndBudgetCap($gaji, $nutrition_summary)
    {
        $budget_tag = 'standar';
        $budget_cap_percentage = 0.20; // Default 20%

        if ($gaji < 2500000) {
            $budget_tag = 'murah';
            $budget_cap_percentage = 0.25; // Alokasi 25% untuk gaji rendah
        } elseif ($gaji > 10000000) {
            $budget_tag = 'mahal';
            $budget_cap_percentage = 0.15; // Alokasi 15% untuk gaji tinggi
        }
        
        $monthly_budget_cap = $gaji * $budget_cap_percentage;

        // Tentukan Tujuan Gizi (tidak berubah)
        $nutrition_tags = [];
        $summary = $nutrition_summary['parent_summary'];
        if ($summary === 'Malnutrisi Berat' || $summary === 'Risiko Gizi Kurang') {
            $nutrition_tags = ['penambah_berat_badan', 'tinggi_protein', 'tinggi_kalori', 'sumber_zat_besi'];
        } elseif ($summary === 'Gizi Lebih' || $summary === 'Obesitas / Gizi Lebih Parah') {
            $nutrition_tags = ['seimbang', 'rendah_lemak', 'variatif'];
        } else {
            $nutrition_tags = ['seimbang', 'variatif'];
        }

        return [
            'budget_tag' => $budget_tag, 
            'nutrition_tags' => $nutrition_tags,
            'monthly_budget_cap' => $monthly_budget_cap,
            'is_malnourished' => ($summary === 'Malnutrisi Berat' || $summary === 'Risiko Gizi Kurang')
        ];
    }
    
    /**
     * [DIUBAH] Memberi skor pada menu dengan sistem penalti yang cerdas.
     */
    private function scoreMenus($menu_pool, $goals, $preferences)
    {
        $scored_menus = [];
        foreach ($menu_pool as $menu) {
            $score = 5; // Skor dasar
            $menu_tags = json_decode($menu['tags'], true) ?: [];

            // Skor Gizi (Prioritas tertinggi)
            if (count(array_intersect($menu_tags, $goals['nutrition_tags'])) > 0) {
                $score += 10;
            }

            // Skor & Penalti Budget
            $budget_diff = $this->getBudgetDiff($menu['budget_category'], $goals['budget_tag']);
            if ($budget_diff == 0) {
                $score += 5; // Sesuai budget, dapat bonus
            } elseif ($budget_diff > 0) { // Menu lebih mahal dari budget
                $penalty = $budget_diff * 5; // Penalti -5 untuk 1 tingkat, -10 untuk 2 tingkat
                // Jika anak kurang gizi dan menu ini punya tag gizi penting, kurangi penalti
                if ($goals['is_malnourished'] && count(array_intersect($menu_tags, $goals['nutrition_tags'])) > 0) {
                    $penalty /= 2; // Penalti dipotong setengah
                }
                $score -= $penalty;
            }

            // Skor Preferensi
            foreach ($preferences as $pref) {
                if (stripos(json_encode($menu['ingredients']), $pref) !== false) {
                    $score += 2;
                    break;
                }
            }
            
            // Pastikan skor tidak negatif
            $menu['final_score'] = max(1, $score);
            $scored_menus[] = $menu;
        }
        return $scored_menus;
    }
    
    /**
     * [BARU] Helper untuk menghitung perbedaan level budget.
     */
    private function getBudgetDiff($menu_category, $target_category) {
        $levels = ['murah' => 1, 'sedang' => 2, 'mahal' => 3];
        return ($levels[strtolower($menu_category)] ?? 2) - ($levels[strtolower($target_category)] ?? 2);
    }

    /**
     * [DIROMBAK TOTAL] Menjalankan simulasi terkendali yang sadar-budget.
     */
    private function runConstrainedSimulation($scored_menus, $num_of_portions, $monthly_budget_cap)
    {
        $plan = [];
        $current_total_cost = 0;
        $last_two_menus = [];

        for ($i = 0; $i < $num_of_portions; $i++) {
            $menu_found_for_this_portion = false;
            foreach ($scored_menus as $menu) {
                // Syarat 1: Biaya tidak akan melebihi budget cap
                if (($current_total_cost + $menu['estimated_cost']) > $monthly_budget_cap) {
                    continue; // Coba menu lain yang lebih murah
                }

                // Syarat 2: Aturan variasi
                if (in_array($menu['id'], $last_two_menus)) {
                    continue;
                }

                // Jika semua syarat terpenuhi, pilih menu ini
                $plan[] = $menu;
                $current_total_cost += $menu['estimated_cost'];
                
                // Update data untuk aturan variasi
                $last_two_menus[] = $menu['id'];
                if (count($last_two_menus) > 2) {
                    array_shift($last_two_menus);
                }

                $menu_found_for_this_portion = true;
                break; // Lanjut ke porsi makan berikutnya
            }

            // Jika setelah memeriksa semua menu tidak ada yang bisa ditambahkan, simulasi gagal.
            if (!$menu_found_for_this_portion) {
                throw new Exception("Simulasi gagal: Budget cap tidak mencukupi untuk memenuhi $num_of_portions porsi makan dengan menu yang tersedia.");
            }
        }

        return $plan;
    }

    private function getFilteredMenuPool($ageMonths, $allergies)
    {
        $stmt = $this->pdo->query("SELECT * FROM menus");
        $all_menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filtered_menus = [];
        foreach ($all_menus as $menu) {
            // Filter Usia
            $age_range = explode('-', preg_replace('/[^0-9-]/', '', $menu['age_group']));
            if (count($age_range) !== 2 || $ageMonths < $age_range[0] || $ageMonths > $age_range[1]) continue;

            // Filter Alergi
            $menu_allergens = json_decode($menu['allergens'], true) ?: [];
            if (count(array_intersect($menu_allergens, $allergies)) > 0) continue;

            $filtered_menus[] = $menu;
        }
        return $filtered_menus;
    }

    private function determineGoals($gaji, $nutrition_summary)
    {
        // Tentukan Level Belanja
        $budget_tag = 'standar';
        if ($gaji < 2500000) $budget_tag = 'murah';
        elseif ($gaji > 10000000) $budget_tag = 'mahal';

        // Tentukan Tujuan Gizi berdasarkan ringkasan
        $nutrition_tags = [];
        $summary = $nutrition_summary['parent_summary'];

        if ($summary === 'Malnutrisi Berat' || $summary === 'Risiko Gizi Kurang') {
            $nutrition_tags = ['penambah_berat_badan', 'tinggi_protein', 'tinggi_kalori', 'sumber_zat_besi'];
        } elseif ($summary === 'Gizi Lebih' || $summary === 'Obesitas / Gizi Lebih Parah') {
            $nutrition_tags = ['seimbang', 'rendah_lemak', 'variatif'];
        } else { // Normal Sehat
            $nutrition_tags = ['seimbang', 'variatif'];
        }

        return ['budget_tag' => $budget_tag, 'nutrition_tags' => $nutrition_tags];
    }

    /**
     * Menyimpan hasil rekomendasi ke database.
     */
    private function saveRecommendation($child_id, $budget_string, $nutrition_summary)
    {
        $stmt_delete = $this->pdo->prepare("DELETE FROM child_recommendations WHERE child_id = ?");
        $stmt_delete->execute([$child_id]);

        $sql = "INSERT INTO child_recommendations (child_id, last_updated, monthly_budget_range, nutrition_summary) 
            VALUES (?, NOW(), ?, ?)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$child_id, $budget_string, $nutrition_summary]);
    }
}

<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_child_id'])) {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
    exit();
}

$child_id = $_SESSION['active_child_id'];
$response = ['success' => false, 'plan' => null, 'start_date' => null, 'plan_id' => null];

try {
    // Cari rencana aktif untuk anak ini
    $stmt_plan = $pdo->prepare("SELECT id, week_start_date FROM mpasi_plans WHERE child_id = ? LIMIT 1");
    $stmt_plan->execute([$child_id]);
    $plan = $stmt_plan->fetch(PDO::FETCH_ASSOC);


    if ($plan) {
        // Cek apakah rencana masih valid (kurang dari 7 hari)
        $start_date = new DateTime($plan['week_start_date']);
        $end_date = (new DateTime($plan['week_start_date']))->modify('+7 days');
        $today = new DateTime();

        if ($today < $end_date) {
            // Jika valid, ambil semua item menu untuk rencana ini
            $stmt_items = $pdo->prepare("
    SELECT mi.day, mi.meal_time, m.* FROM mpasi_items mi
    JOIN menus m ON mi.menu_id = m.id
    WHERE mi.mpasi_plan_id = ?
    ORDER BY 
        FIELD(mi.day, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), 
        FIELD(mi.meal_time, 'Sarapan', 'Makan Siang', 'Makan Malam')
");
            $stmt_items->execute([$plan['id']]);
            $plan_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
            $stmt_allergies = $pdo->prepare("SELECT food_name FROM allergies WHERE child_id = ?");
            $stmt_allergies->execute([$child_id]);
            $allergies = $stmt_allergies->fetchAll(PDO::FETCH_COLUMN);

            if ($plan_items) {
                $response = [
                    'success' => true,
                    'plan' => $plan_items,
                    'start_date' => $start_date->format('Y-m-d'),
                    'plan_id' => $plan['id'],
                    'allergies' => $allergies
                ];
            }
        }
    }

    echo json_encode($response);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

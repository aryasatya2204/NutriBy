<?php
// file: api/api_update_plan_items.php (FILE BARU)

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_child_id'])) {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$plan_id = $input['plan_id'] ?? null;
$item_indices = $input['item_indices'] ?? []; 
$new_menu_id = $input['new_menu_id'] ?? null;

if ($plan_id === null || empty($item_indices) || $new_menu_id === null) {
    echo json_encode(['success' => false, 'error' => 'Data tidak lengkap.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Dapatkan detail menu baru untuk mengambil biayanya
    $stmt_new_menu = $pdo->prepare("SELECT estimated_cost FROM menus WHERE id = ?");
    $stmt_new_menu->execute([$new_menu_id]);
    $new_menu = $stmt_new_menu->fetch(PDO::FETCH_ASSOC);
    if (!$new_menu) throw new Exception("Menu baru tidak ditemukan.");
    $new_cost = $new_menu['estimated_cost'];

    // 2. Ambil semua item dari rencana untuk pemetaan indeks ke ID
    $stmt_items = $pdo->prepare("
        SELECT id, estimated_cost FROM mpasi_items 
        WHERE mpasi_plan_id = ? 
        ORDER BY FIELD(day, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), 
                 FIELD(meal_time, 'Sarapan', 'Makan Siang', 'Makan Malam')
    ");
    $stmt_items->execute([$plan_id]);
    $all_plan_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $total_cost_change = 0;

    // 3. Loop melalui setiap indeks yang akan diupdate
    foreach ($item_indices as $index) {
        if (!isset($all_plan_items[$index])) continue; // Lewati jika indeks tidak valid

        $item_to_update = $all_plan_items[$index];
        $old_cost = $item_to_update['estimated_cost'];

        // Update item di tabel mpasi_items
        $stmt_update = $pdo->prepare("UPDATE mpasi_items SET menu_id = ?, estimated_cost = ? WHERE id = ?");
        $stmt_update->execute([$new_menu_id, $new_cost, $item_to_update['id']]);

        // Akumulasi perubahan biaya
        $total_cost_change += ($new_cost - $old_cost);
    }

    // 4. Update total biaya di tabel mpasi_plans dengan total perubahan
    $stmt_update_total = $pdo->prepare("UPDATE mpasi_plans SET total_estimated_cost = total_estimated_cost + ? WHERE id = ?");
    $stmt_update_total->execute([$total_cost_change, $plan_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Rencana berhasil diperbarui.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
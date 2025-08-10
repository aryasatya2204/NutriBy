<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_child_id'])) {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
    exit();
}

$child_id = $_SESSION['active_child_id'];

try {
    // Cari ID rencana berdasarkan child_id lalu hapus.
    // ON DELETE CASCADE akan otomatis menghapus item di mpasi_items.
    $stmt = $pdo->prepare("DELETE FROM mpasi_plans WHERE child_id = ?");
    $stmt->execute([$child_id]);

    echo json_encode(['success' => true, 'message' => 'Rencana berhasil dihapus.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
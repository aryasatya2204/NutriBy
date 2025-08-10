<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../includes/db.php';

// Pastikan pengguna sudah login dan ada anak yang aktif
if (!isset($_SESSION['user_id']) || !isset($_SESSION['active_child_id'])) {
    echo json_encode(['success' => false, 'error' => 'Akses ditolak, sesi tidak valid.']);
    exit();
}

// Ambil data dari request POST
$input = json_decode(file_get_contents('php://input'), true);
$child_id = $_SESSION['active_child_id'];

// Ambil data alergi dan preferensi, default ke array kosong jika tidak ada
$allergies = $input['allergies'] ?? [];
$preferences = $input['preferences'] ?? [];

try {
    // Mulai transaksi untuk memastikan semua operasi berhasil atau tidak sama sekali
    $pdo->beginTransaction();

    // 1. Update Alergi (Hapus yang lama, sisipkan yang baru)
    $stmt_delete_allergies = $pdo->prepare("DELETE FROM allergies WHERE child_id = ?");
    $stmt_delete_allergies->execute([$child_id]);

    if (!empty($allergies)) {
        $stmt_insert_allergy = $pdo->prepare("INSERT INTO allergies (child_id, food_name) VALUES (?, ?)");
        foreach ($allergies as $allergy) {
            $stmt_insert_allergy->execute([$child_id, $allergy]);
        }
    }

    // 2. Update Preferensi (Hapus yang lama, sisipkan yang baru)
    $stmt_delete_prefs = $pdo->prepare("DELETE FROM preferences WHERE child_id = ?");
    $stmt_delete_prefs->execute([$child_id]);

    if (!empty($preferences)) {
        $stmt_insert_pref = $pdo->prepare("INSERT INTO preferences (child_id, food_name) VALUES (?, ?)");
        foreach ($preferences as $preference) {
            $stmt_insert_pref->execute([$child_id, $preference]);
        }
    }

    // Jika semua berhasil, commit transaksi
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Profil alergi dan preferensi anak berhasil diperbarui.']);

} catch (Exception $e) {
    // Jika terjadi error, batalkan semua perubahan
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
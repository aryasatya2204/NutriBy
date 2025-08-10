<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db.php';

$type = $_GET['type'] ?? 'makanan';
$query = $_GET['query'] ?? '';

if (empty($query)) {
    echo json_encode(['error' => 'Query tidak boleh kosong']);
    exit();
}

$response = [];
$search_pattern = '[[:<:]]' . preg_quote($query, '/');

// Query sekarang mengambil semua kolom yang relevan, termasuk image_url
$sql = "SELECT food_name, cause, symptoms, effects, handling, image_url FROM allergy_facts";

if ($type === 'makanan') {
    $stmt = $pdo->prepare("$sql WHERE food_name REGEXP ?");
} else { // 'gejala'
    $stmt = $pdo->prepare("$sql WHERE symptoms REGEXP ?");
}

$stmt->execute([$search_pattern]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Memproses setiap hasil untuk mendekode JSON symptoms
foreach ($results as $key => $row) {
    $results[$key]['symptoms'] = json_decode($row['symptoms'], true);
}

if (count($results) === 1) {
    // Jika hanya 1, langsung kirim sebagai detail
    $response = ['type' => 'food_details', 'data' => $results[0]];
} else {
    // Jika lebih dari 1, kirim seluruh data sebagai list
    $response = ['type' => 'food_list', 'data' => $results];
}

echo json_encode($response);
?>
<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db.php';

$type = $_GET['type'] ?? 'makanan';
$query = $_GET['query'] ?? '';

if (empty($query)) {
    echo json_encode([]);
    exit();
}

$results = [];
$search_pattern = '[[:<:]]' . preg_quote($query, '/');

if ($type === 'makanan') {
    $stmt = $pdo->prepare("SELECT DISTINCT food_name FROM allergy_facts WHERE food_name REGEXP ? ORDER BY food_name LIMIT 5");
    $stmt->execute([$search_pattern]);
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    // Menggunakan REGEXP untuk mencari di dalam string JSON
    // Ini akan mencocokkan gejala seperti "Ruam Merah" jika keyword-nya "rua"
    $stmt = $pdo->prepare("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(symptoms, '$[0]')) AS symptom FROM allergy_facts WHERE symptoms REGEXP ? LIMIT 5");
    $stmt->execute([$search_pattern]);
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

echo json_encode($results);
?>
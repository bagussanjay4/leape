<?php
header('Content-Type: application/json');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'book_management_system';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed']));
}

$categoryId = $_GET['category_id'] ?? null;

if (!$categoryId) {
    die(json_encode(['error' => 'Category ID is required']));
}

$stmt = $db->prepare("SELECT id, level FROM book_levels WHERE category_id = ? ORDER BY level");
$stmt->execute([$categoryId]);
$levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($levels);
?>
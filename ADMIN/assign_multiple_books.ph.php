<?php
session_start();
header('Content-Type: application/json');

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'book_management_system';

try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

if (!isset($_SESSION['admin_logged_in'])) {
    die(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

if (!isset($_POST['book_level_id']) || !isset($_POST['student_ids'])) {
    die(json_encode(['success' => false, 'message' => 'Missing required data']));
}

$bookLevelId = $_POST['book_level_id'];
$studentIds = $_POST['student_ids'];

try {
    // Get category from book level
    $stmt = $db->prepare("SELECT category_id FROM book_levels WHERE id = ?");
    $stmt->execute([$bookLevelId]);
    $bookLevel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bookLevel) {
        throw new Exception("Book level not found");
    }
    
    $categoryId = $bookLevel['category_id'];
    
    // Begin transaction
    $db->beginTransaction();
    
    // First delete any existing assignments in the same category
    $deleteStmt = $db->prepare("DELETE ub FROM user_books ub 
                              JOIN book_levels bl ON ub.book_level_id = bl.id 
                              WHERE ub.user_id = ? AND bl.category_id = ?");
    
    // Then insert new assignments
    $insertStmt = $db->prepare("INSERT INTO user_books (user_id, book_level_id) VALUES (?, ?)");
    
    foreach ($studentIds as $studentId) {
        // Delete existing assignments in same category
        $deleteStmt->execute([$studentId, $categoryId]);
        
        // Insert new assignment
        $insertStmt->execute([$studentId, $bookLevelId]);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Books assigned successfully to ' . count($studentIds) . ' students'
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
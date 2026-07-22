<?php
header("Content-Type: application/json");
require_once __DIR__ . "/db.php";

$res = [];
try {
    $stmt = $db->query("SELECT * FROM users LIMIT 10");
    $res['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $db->query("SELECT * FROM pending_topups LIMIT 10");
    $res['pending_topups'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if 5048 exists
    $stmt3 = $db->prepare("SELECT * FROM pending_topups WHERE unique_amount = ?");
    $stmt3->execute([5048]);
    $res['matching_5048'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $res['error'] = $e->getMessage();
}

echo json_encode($res, JSON_PRETTY_PRINT);

<?php
// api/topup.php
require_once __DIR__ . "/db.php";

$data = json_decode(file_get_contents("php://input"), true);
$telegram_id = isset($data['telegram_id']) ? (int)$data['telegram_id'] : 0;
$amount = isset($data['amount']) ? (int)$data['amount'] : 0;

if (!$telegram_id || !$amount) {
    echo json_encode(["error" => "telegram_id and amount are required"]);
    exit;
}

// Miqdor chegaralari: minimum 1000, maksimum 500000 so'm
if ($amount < 1000 || $amount > 500000) {
    echo json_encode(["error" => "To'lov miqdori 1,000 dan 500,000 so'mgacha bo'lishi kerak"]);
    exit;
}

try {
    // Delete expired pending topups (older than 15 minutes)
    $db->exec("DELETE FROM pending_topups WHERE status='pending' AND datetime(created_at) < datetime('now', '-15 minutes')");
    
    $unique_amount = $amount;
    $attempts = 0;
    while ($attempts < 100) {
        $offset = rand(1, 99);
        $test_amount = $amount + $offset;
        
        // Check if this amount is already pending
        $stmt = $db->prepare("SELECT id FROM pending_topups WHERE unique_amount = ? AND status='pending'");
        $stmt->execute([$test_amount]);
        if (!$stmt->fetch()) {
            $unique_amount = $test_amount;
            break;
        }
        $attempts++;
    }
    
    // Insert pending topup
    $stmt = $db->prepare("INSERT INTO pending_topups (telegram_id, unique_amount, base_amount) VALUES (?, ?, ?)");
    $stmt->execute([$telegram_id, $unique_amount, $amount]);
    
    echo json_encode([
        "status" => "success",
        "unique_amount" => $unique_amount,
        "card_number" => "9860 1201 4794 6905",
        "card_owner" => "Sobirjanov Asadbek",
        "expires_in_minutes" => 15,
        "message" => "Iltimos, kartaga aniq " . number_format($unique_amount, 0, '.', ' ') . " so'm o'tkazing. Pul tushishi bilan balans to'ldiriladi!"
    ]);
} catch (PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => $e->getMessage()]);
}

<?php
// api/get-transactions.php — Tranzaksiya tarixini qaytaradi
require_once __DIR__ . "/db.php";

$telegram_id = isset($_GET['telegram_id']) ? (int)$_GET['telegram_id'] : 0;
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;

if (!$telegram_id) {
    echo json_encode(["error" => "telegram_id majburiy"]);
    exit;
}

try {
    $stmt = $db->prepare(
        "SELECT id, type, amount, description, created_at
         FROM transactions
         WHERE telegram_id = ?
         ORDER BY created_at DESC
         LIMIT ?"
    );
    $stmt->execute([$telegram_id, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format amount with sign and type icons
    $formatted = array_map(function($r) {
        $type_icons = [
            'topup'  => '💰',
            'image'  => '🖼️',
            'video'  => '🎬',
            'chat'   => '💬',
        ];
        $icon = $type_icons[$r['type']] ?? '📋';
        $sign = $r['amount'] > 0 ? '+' : '';
        return [
            'id'          => $r['id'],
            'type'        => $r['type'],
            'icon'        => $icon,
            'amount'      => $r['amount'],
            'amount_fmt'  => $sign . number_format($r['amount'], 0, '.', ' ') . " so'm",
            'description' => $r['description'],
            'created_at'  => $r['created_at'],
        ];
    }, $rows);

    echo json_encode(["status" => "success", "transactions" => $formatted]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

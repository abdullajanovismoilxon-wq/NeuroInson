<?php
// api/webhook.php — StarKerak to'lov webhook qabul qiluvchi
require_once __DIR__ . "/db.php";

$data = json_decode(file_get_contents("php://input"), true);

// ============================================
// 1. SECRET TOKEN TEKSHIRUVI (XAVFSIZLIK)
// ============================================
$config_file = __DIR__ . "/config.json";
$WEBHOOK_SECRET = "default_secret_change_me";
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    if (isset($config['WEBHOOK_SECRET'])) {
        $WEBHOOK_SECRET = $config['WEBHOOK_SECRET'];
    }
}

$received_secret = isset($data['secret_token']) ? $data['secret_token'] : "";
if ($received_secret !== $WEBHOOK_SECRET) {
    http_response_code(403);
    echo json_encode(["error" => "Ruxsat yo'q! Noto'g'ri token."]);
    exit;
}

// ============================================
// 2. SUMMA VA TO'LOV ID OLISH
// ============================================
$amount  = isset($data['amount'])  ? (int)$data['amount']     : 0;
$pay_id  = isset($data['pay_id'])  ? (string)$data['pay_id']  : "";

if (!$amount || !$pay_id) {
    echo json_encode(["error" => "amount va pay_id majburiy"]);
    exit;
}

try {
    // Duplicate xabardan himoya — bir xil pay_id ikki marta qabul qilinmasin
    $stmt = $db->prepare("SELECT id FROM paid_transactions WHERE pay_id = ?");
    $stmt->execute([$pay_id]);
    if ($stmt->fetch()) {
        echo json_encode(["status" => "already_processed", "message" => "Bu to'lov allaqachon qabul qilingan."]);
        exit;
    }

    // ============================================
    // 3. PENDING TOPUP ni UNIQUE AMOUNT bo'yicha TOPISH
    // ============================================
    $stmt = $db->prepare("SELECT id, telegram_id, base_amount, unique_amount FROM pending_topups WHERE unique_amount = ? AND status='pending'");
    $stmt->execute([$amount]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $topup_id     = $row['id'];
        $user_id      = $row['telegram_id'];
        $base_amount  = $row['base_amount'];  // Foydalanuvchi kiritgan asl summa (masalan 10000)

        // Foydalanuvchi balansini unikal to'lov summasi ($amount) bilan oshirish
        $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?");
        $stmt->execute([$amount, $user_id]);

        // Pending topupni yopish
        $stmt = $db->prepare("UPDATE pending_topups SET status='completed' WHERE id = ?");
        $stmt->execute([$topup_id]);

        // Duplicate himoyasi — pay_id ni yozib qo'yish
        $stmt = $db->prepare("INSERT INTO paid_transactions (pay_id, telegram_id, amount) VALUES (?, ?, ?)");
        $stmt->execute([$pay_id, $user_id, $amount]);

        // Tranzaksiya tarixi
        $stmt = $db->prepare("INSERT INTO transactions (telegram_id, type, amount, description) VALUES (?, 'topup', ?, ?)");
        $stmt->execute([$user_id, $amount, "Balans to'ldirildi (P2P)"]);
        $transaction_seq_id = $db->lastInsertId();

        // ============================================
        // TELEGRAM BOT BILDIRISHNOMASI YUBORISH
        // ============================================
        $config_file = __DIR__ . "/config.json";
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true);
            $bot_token = isset($config['BOT_TOKEN']) ? $config['BOT_TOKEN'] : "";
            
            if ($bot_token && $user_id) {
                $formatted_amount = number_format($amount, 0, '.', ' ');
                $text = "💰 <b>Hisobingiz muvaffaqiyatli to'ldirildi!</b>\n\n"
                      . "📈 <b>To'lov summasi:</b> {$formatted_amount} so'm\n"
                      . "🔄 Tranzaksiya ID: <code>#{$transaction_seq_id}</code>\n\n"
                      . "Neuroinson AI xizmatlaridan foydalanishingiz mumkin! 😊";
                
                $telegram_url = "https://api.telegram.org/bot" . $bot_token . "/sendMessage";
                $payload = [
                    'chat_id' => $user_id,
                    'text' => $text,
                    'parse_mode' => 'HTML'
                ];
                
                $ch = curl_init($telegram_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        echo json_encode([
            "status"       => "success",
            "message"      => "Balans muvaffaqiyatli to'ldirildi!",
            "telegram_id"  => $user_id,
            "credited"     => $amount
        ]);
    } else {
        // Mos pending topup topilmadi — noto'g'ri summa yoki muddati o'tgan
        echo json_encode(["status" => "ignored", "message" => "Mos pending topup topilmadi. Summa: $amount"]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

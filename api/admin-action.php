<?php
// api/admin-action.php
require_once __DIR__ . "/db.php";

$data = json_decode(file_get_contents("php://input"), true);
$telegram_id = isset($data['admin_id']) ? (int)$data['admin_id'] : 0;
$action = isset($data['action']) ? $data['action'] : '';

// Load admins from config and env
$config_file = __DIR__ . "/config.json";
$admins = [799317334];
$bot_token = "";
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true);
    if (isset($config['ADMIN_IDS'])) {
        $admins = is_array($config['ADMIN_IDS']) ? $config['ADMIN_IDS'] : [$config['ADMIN_IDS']];
    }
}
require_once __DIR__ . "/env.php";
$env_token = loadEnv('BOT_TOKEN');
$bot_token = $env_token ?: ($config['BOT_TOKEN'] ?? "");

if (!in_array($telegram_id, $admins)) {
    $stmt = $db->prepare("SELECT google_email FROM users WHERE telegram_id=?");
    $stmt->execute([$telegram_id]);
    $admin_email = $stmt->fetchColumn();
    if ($admin_email !== 'abdullajanovismoilxon@gmail.com') {
        http_response_code(403);
        echo json_encode(["error" => "Ruxsat etilmagan!"]);
        exit;
    }
}

try {
    if ($action === 'adjust_balance' || $action === 'update_balance') {
        $target_user_id = isset($data['target_id']) ? (int)$data['target_id'] : (isset($data['user_id']) ? (int)$data['user_id'] : 0);
        $amount = isset($data['amount']) ? (int)$data['amount'] : 0;
        $reason = isset($data['reason']) ? trim($data['reason']) : '';

        if (!$target_user_id || !$amount) {
            echo json_encode(["error" => "target_id va amount majburiy"]);
            exit;
        }

        $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
        $stmt->execute([$target_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(["error" => "Foydalanuvchi topilmadi!"]);
            exit;
        }

        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?");
        $stmt->execute([$amount, $target_user_id]);
        $tx_type = $amount > 0 ? 'topup' : 'spent';
        $desc = $reason ?: ($amount > 0 ? "Admin tomonidan to'ldirildi" : "Admin tomonidan yechib olindi");
        $stmt = $db->prepare("INSERT INTO transactions (telegram_id, type, amount, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$target_user_id, $tx_type, abs($amount), $desc]);
        $db->commit();

        if ($bot_token) {
            $formatted_amount = number_format(abs($amount), 0, '.', ' ');
            $msg_text = $amount > 0 
                ? "🎁 <b>Hisobingiz admin tomonidan to'ldirildi!</b>\n\n📈 <b>Summa:</b> {$formatted_amount} so'm"
                : "⚠️ <b>Hisobingizdan mablag' yechib olindi!</b>\n\n📉 <b>Summa:</b> {$formatted_amount} so'm";
            $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$target_user_id,'text'=>$msg_text,'parse_mode'=>'HTML']), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>5, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false]);
            curl_exec($ch);
            curl_close($ch);
        }

        echo json_encode(["status" => "success", "message" => "Balans o'zgartirildi"]);
    }
    elseif ($action === 'delete_user') {
        $target_id = isset($data['target_id']) ? (int)$data['target_id'] : 0;
        if (!$target_id) {
            echo json_encode(["error" => "target_id kerak"]);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM users WHERE telegram_id=?");
        $stmt->execute([$target_id]);
        echo json_encode(["status" => "success", "message" => "Foydalanuvchi o'chirildi"]);
    } 
    elseif ($action === 'broadcast') {
        $message_text = isset($data['message']) ? trim($data['message']) : (isset($data['message_text']) ? trim($data['message_text']) : '');
        if ($message_text === '') {
            echo json_encode(["error" => "Xabar matni bo'sh bo'lishi mumkin emas!"]);
            exit;
        }

        if (!$bot_token) {
            echo json_encode(["error" => "Bot token sozlanmagan!"]);
            exit;
        }

        // Get all users
        $users = $db->query("SELECT telegram_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        
        $success_count = 0;
        $fail_count = 0;

        // Loop and send
        foreach ($users as $uid) {
            $telegram_url = "https://api.telegram.org/bot" . $bot_token . "/sendMessage";
            $payload = [
                'chat_id' => $uid,
                'text' => $message_text,
                'parse_mode' => 'HTML'
            ];
            
            $ch = curl_init($telegram_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $res = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                $success_count++;
            } else {
                $fail_count++;
            }
            // Small sleep to prevent Telegram spam limits
            usleep(50000); // 50ms
        }

        echo json_encode([
            "status" => "success",
            "message" => "Xabar tarqatildi!",
            "success_count" => $success_count,
            "fail_count" => $fail_count
        ]);
    } elseif ($action === 'add_expense') {
        $description = isset($data['description']) ? trim($data['description']) : '';
        $amount = isset($data['amount']) ? (int)$data['amount'] : 0;
        
        if ($description === '' || $amount <= 0) {
            echo json_encode(["error" => "Tavsif va musbat summa bo'lishi shart"]);
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO platform_expenses (description, amount) VALUES (?, ?)");
        $stmt->execute([$description, $amount]);
        
        echo json_encode(["status" => "success", "message" => "Xarajat muvaffaqiyatli qo'shildi!"]);
    } elseif ($action === 'delete_expense') {
        $expense_id = isset($data['expense_id']) ? (int)$data['expense_id'] : 0;
        if (!$expense_id) {
            echo json_encode(["error" => "Xarajat IDsi kiritilmagan"]);
            exit;
        }
        
        $stmt = $db->prepare("DELETE FROM platform_expenses WHERE id = ?");
        $stmt->execute([$expense_id]);
        
        echo json_encode(["status" => "success", "message" => "Xarajat o'chirildi!"]);
    } else {
        echo json_encode(["error" => "Noma'lum amal!"]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

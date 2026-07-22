<?php
require_once __DIR__ . "/db.php";

$input = json_decode(file_get_contents("php://input"), true);
$email = isset($input['email']) ? trim(strtolower($input['email'])) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Noto'g'ri email format"]);
    exit;
}

try {
    // Rate limit: 1 request per 60 seconds per email
    $stmt = $db->prepare("SELECT MAX(created_at) as last FROM email_otp_codes WHERE email = ? AND created_at > datetime('now', '-60 seconds')");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['last']) {
        http_response_code(429);
        echo json_encode(["error" => "Iltimos, biroz kuting. Kodni 1 daqiqada 1 marta yuborish mumkin."]);
        exit;
    }

    // Generate 6-digit code
    $code = strval(random_int(100000, 999999));
    $expires_at = date('Y-m-d H:i:s', time() + 300);

    $stmt = $db->prepare("INSERT INTO email_otp_codes (email, code, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$email, $code, $expires_at]);

    // PHPMailer send (requires composer install)
    $smtp_configured = false;
    $config_file = __DIR__ . "/config.json";
    if (file_exists($config_file)) {
        $config = json_decode(file_get_contents($config_file), true);
        $smtp_host = $config['SMTP_HOST'] ?? 'smtp.gmail.com';
        $smtp_port = (int)($config['SMTP_PORT'] ?? 587);
        $smtp_user = $config['SMTP_USER'] ?? '';
        $smtp_pass = $config['SMTP_PASS'] ?? '';

        if ($smtp_user && $smtp_pass && file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $smtp_port;
                $mail->CharSet = 'UTF-8';
                $mail->setFrom($smtp_user, 'Neuroinson AI');
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Neuroinson AI — Tasdiqlash kodi';

                $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>';
                $html .= '<body style="margin:0;padding:0;background:#1a1a1f;font-family:Arial,sans-serif;">';
                $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#1a1a1f;padding:40px 20px;"><tr><td align="center">';
                $html .= '<table width="480" cellpadding="0" cellspacing="0" style="max-width:480px;">';
                $html .= '<tr><td style="text-align:center;padding-bottom:8px;"><span style="color:#7C6FF0;font-size:26px;font-weight:800;letter-spacing:1px;">NEUROINSON AI</span></td></tr>';
                $html .= '<tr><td style="text-align:center;padding-bottom:30px;"><span style="color:#A1A1AA;font-size:14px;letter-spacing:2px;text-transform:uppercase;">Sun\'iy Intellekt Platformasi</span></td></tr>';
                $html .= '<tr><td style="background:#0D0D10;border-radius:16px;padding:40px 32px;text-align:center;">';
                $html .= '<h2 style="color:#F4F4F5;font-size:18px;margin:0 0 20px 0;font-weight:600;">Hisobingizga kirish uchun<br>tasdiqlash kodi</h2>';
                $html .= '<div style="font-size:36px;font-weight:700;letter-spacing:10px;color:#7C6FF0;background:rgba(124,111,240,0.08);border-radius:12px;padding:20px 16px;margin:0 0 24px 0;font-family:monospace;">' . $code . '</div>';
                $html .= '<p style="color:#71717A;font-size:13px;line-height:1.6;margin:0;">Ushbu kod <strong style="color:#A78BFA;">5 daqiqa</strong> davomida amal qiladi.<br>Kodni hech kimga bermang.</p>';
                $html .= '</td></tr>';
                $html .= '<tr><td style="text-align:center;padding-top:24px;"><span style="color:#52525B;font-size:11px;">&copy; 2026 Neuroinson AI. Barcha huquqlar himoyalangan.</span></td></tr>';
                $html .= '</table></td></tr></table></body></html>';

                $mail->Body = $html;
                $mail->send();
                $smtp_configured = true;
            } catch (Exception $e) {
                $smtp_configured = false;
            }
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Kod emailingizga yuborildi",
        "email" => $email,
        "expires_in" => 300,
        "smtp_configured" => $smtp_configured
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Server xatolik"]);
}

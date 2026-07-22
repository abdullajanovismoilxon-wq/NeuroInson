<?php
// api/generate-image.php
require_once __DIR__ . "/db.php";
ignore_user_abort(true);
set_time_limit(120);


$data = json_decode(file_get_contents("php://input"), true);
$telegram_id = isset($data['telegram_id']) ? (int)$data['telegram_id'] : 0;
$prompt      = isset($data['prompt'])      ? trim($data['prompt'])      : "";
$price       = isset($data['price'])       ? (int)$data['price']        : 0;

if (!$telegram_id || !$prompt) {
    echo json_encode(["error" => "telegram_id and prompt are required"]);
    exit;
}

if ($price === 0) {
    echo json_encode(["error" => "Bepul rasm chizish o'chirildi. Iltimos, boshqa tariflardan foydalaning!"]);
    exit;
}

try {
    // 1. Check user balance
    $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['balance'] < $price) {
        echo json_encode(["error" => "Balansda mablag' yetarli emas!"]);
        exit;
    }

    // 2. Load API config
    $config  = json_decode(file_get_contents(__DIR__ . "/config.json"), true);
    $fal_key = isset($config['FAL_KEY']) ? $config['FAL_KEY'] : "";

    if (!$fal_key) {
        echo json_encode(["error" => "Fal.ai API kaliti topilmadi."]);
        exit;
    }

    // 3. Prompt enhancement via Gemini (translate + enhance, prefer unblocked free keys)
    $enhanced_prompt = $prompt;
    $blocked_file = __DIR__ . "/blocked_keys.json";
    $blocked_keys = file_exists($blocked_file) ? (json_decode(file_get_contents($blocked_file), true) ?: []) : [];
    $now_ts = time();
    $all_gem_keys = array_merge($config['GEMINI_FREE_KEYS'] ?? [], $config['GEMINI_PAID_KEYS'] ?? []);
    $gemini_keys = array_filter($all_gem_keys, fn($k) => !isset($blocked_keys[$k]) || $now_ts > $blocked_keys[$k]);
    if (empty($gemini_keys)) $gemini_keys = $all_gem_keys; // fallback: try all if all blocked
    if (!empty($gemini_keys)) {
        $selected_key = $gemini_keys[array_rand($gemini_keys)];
        $url_gemini   = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=" . $selected_key;
        $payload_gemini = [
            "systemInstruction" => [
                "parts" => [["text" => "You are an expert AI image prompt engineer. Convert the user's short text (any language) into a highly detailed, professional English prompt for AI image generators. Focus on lighting, composition, style. Return ONLY the prompt text."]]
            ],
            "contents"          => [["role" => "user", "parts" => [["text" => $prompt]]]],
            "generationConfig"  => ["maxOutputTokens" => 300]
        ];
        $ch = curl_init($url_gemini);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload_gemini),
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);
        $r = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $r) {
            $rj = json_decode($r, true);
            $txt = $rj['candidates'][0]['content']['parts'][0]['text'] ?? "";
            if ($txt) $enhanced_prompt = trim($txt);
        }
        curl_close($ch);
    }

    // Aspect ratio mapping for Fal.ai
    $aspect_ratio = isset($data['aspect_ratio']) ? $data['aspect_ratio'] : "1:1";
    if ($aspect_ratio === "16:9") {
        $image_size = "landscape_16_9";
    } elseif ($aspect_ratio === "9:16") {
        $image_size = "portrait_16_9";
    } else {
        $image_size = "square";
    }

    // 4. Model selection based on price
    // Standard/Fast (499 and 666) -> Flux Schnell
    // Premium/Business (1999) -> Flux Dev
    if ($price >= 1900) {
        $model_id = "fal-ai/flux/dev";
        $model_name = "Flux Dev";
    } else {
        $model_id = "fal-ai/flux/schnell";
        $model_name = "Flux Schnell";
    }

    $model_url = "https://queue.fal.run/" . $model_id;

    // 5. Request to Fal.ai (Queue method)
    $payload = json_encode([
        "prompt" => $enhanced_prompt,
        "image_size" => $image_size,
        "sync_mode" => true
    ]);

    $ch = curl_init($model_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Key " . $fal_key
        ]
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code < 200 || $http_code >= 300 || !$response) {
        echo json_encode(["error" => "Fal.ai chizishda xatolik ($http_code): " . $response]);
        exit;
    }

    $res = json_decode($response, true);
    $image_url = $res['images'][0]['url'] ?? "";
    $response_url = $res['response_url'] ?? "";

    // Poll if queued using the response_url provided by Fal.ai
    if (!$image_url && $response_url) {
        for ($i = 0; $i < 20; $i++) {
            sleep(2);
            $ch2 = curl_init($response_url);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Key " . $fal_key
                ]
            ]);
            $sr = curl_exec($ch2);
            curl_close($ch2);
            $sj = json_decode($sr, true);
            
            // Check for queue errors
            $status = $sj['status'] ?? "";
            if ($status === "ERROR" || $status === "FAILED") {
                echo json_encode(["error" => "Fal.ai chizishda xatolik yuz berdi: " . $sr]);
                exit;
            }
            
            $image_url = $sj['images'][0]['url'] ?? "";

            if ($image_url) break;
        }
    }

    if (!$image_url) {
        echo json_encode(["error" => "Rasm chizib bo'lmadi. Server javobi: " . $response]);
        exit;
    }

    // Download the generated image to local output folder
    $ch_dl = curl_init($image_url);
    curl_setopt_array($ch_dl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $img_content = curl_exec($ch_dl);
    $dl_http = curl_getinfo($ch_dl, CURLINFO_HTTP_CODE);
    curl_close($ch_dl);
    if ($dl_http !== 200 || !$img_content) {
        echo json_encode(["error" => "Chizilgan rasmni yuklab olishda xatolik (HTTP $dl_http)."]);
        exit;
    }

    $output_dir = __DIR__ . "/../output";
    if (!is_dir($output_dir)) mkdir($output_dir, 0755, true);
    $filename   = "img_" . time() . "_" . rand(1000, 9999) . ".jpg";
    $local_path = $output_dir . "/" . $filename;
    file_put_contents($local_path, $img_content);

    // Deduct user balance atomically
    $db->beginTransaction();
    $stmt = $db->prepare("UPDATE users SET balance = balance - ? WHERE telegram_id = ?");
    $stmt->execute([$price, $telegram_id]);
    $stmt = $db->prepare("INSERT INTO transactions (telegram_id, type, amount, description) VALUES (?, 'image', ?, ?)");
    $stmt->execute([$telegram_id, -$price, "Rasm chizish ({$model_name})"]);
    $today = date("Y-m-d");
    $db->prepare("UPDATE users SET extra_prompt_limits = CASE WHEN last_prompt_date = ? THEN extra_prompt_limits + 2 ELSE 2 END, last_prompt_date = ? WHERE telegram_id = ?")
       ->execute([$today, $today, $telegram_id]);
    $db->commit();

    // Re-fetch balance for accurate display
    $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $new_balance = (int)$stmt->fetchColumn();

    // Log API usage
    try {
        $stmt_u = $db->prepare("SELECT google_email FROM users WHERE telegram_id=?");
        $stmt_u->execute([$telegram_id]);
        $u_email = $stmt_u->fetchColumn() ?: '';
        $stmt_l = $db->prepare("INSERT INTO api_usage_log (user_email, api_name, endpoint, model_name, prompt_length) VALUES (?, 'fal', 'image', ?, ?)");
        $stmt_l->execute([$u_email, $model_name, strlen($prompt)]);
    } catch (Exception $e) {}

    echo json_encode([
        "status"      => "success",
        "image_url"   => "output/" . $filename,
        "model"       => $model_name,
        "new_balance" => $new_balance
    ]);
    exit;

} catch (Exception $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => $e->getMessage()]);
}

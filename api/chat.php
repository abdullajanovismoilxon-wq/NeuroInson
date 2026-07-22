<?php
// api/chat.php
require_once __DIR__ . "/db.php";

$data = json_decode(file_get_contents("php://input"), true);
$telegram_id = isset($data['telegram_id']) ? (int)$data['telegram_id'] : 0;
$prompt = isset($data['prompt']) ? trim($data['prompt']) : "";

// Whitelist: faqat ruxsat etilgan chat turlari
$allowed_types = ['antigravity', 'prompt', 'project-chat'];
$chat_type = isset($data['chat_type']) && in_array($data['chat_type'], $allowed_types)
    ? $data['chat_type'] : 'antigravity';

if (!$telegram_id || !$prompt) {
    echo json_encode(["error" => "telegram_id and prompt are required"]);
    exit;
}

// Prompt uzunligi cheklovi
if (strlen($prompt) > 8000) {
    echo json_encode(["error" => "Xabar juda uzun (maksimum 8000 belgi)"]);
    exit;
}

// === NARXLAR SERVER TOMONIDA BELGILANADI (mijoz manipulyatsiyasidan himoya) ===
// Loyiha narxlari (index.html dagi orderProject() bilan mos bo'lishi kerak)
$VALID_PROJECT_PRICES = [14999, 35555, 49999];

try {
    // 1. Fetch user data
    $stmt = $db->prepare("SELECT balance, daily_chat_count, last_chat_date, daily_prompt_count, extra_prompt_limits, last_prompt_date, project_chat_remaining, active_project_type FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Auto-register user with default balance if not exists
        $stmt = $db->prepare("INSERT INTO users (telegram_id, balance) VALUES (?, 10000)");
        $stmt->execute([$telegram_id]);
        $user = [
            "balance" => 10000,
            "daily_chat_count" => 0,
            "last_chat_date" => "",
            "daily_prompt_count" => 0,
            "extra_prompt_limits" => 0,
            "last_prompt_date" => "",
            "project_chat_remaining" => 0
        ];
    }

    // Determine cost and validate project-chat limits
    $chat_cost = 0;
    if ($chat_type === 'project-chat') {
        $client_price = isset($data['project_price']) ? (int)$data['project_price'] : 0;
        if ($client_price > 0 && in_array($client_price, $VALID_PROJECT_PRICES)) {
            $chat_cost = $client_price; // First purchase / reactivation request
        } else {
            // Follow-up message: check remaining limit
            $remaining_credits = isset($user['project_chat_remaining']) ? (int)$user['project_chat_remaining'] : 0;
            if ($remaining_credits <= 0) {
                echo json_encode(["error" => "Ushbu loyiha uchun suhbat limitlaringiz (50 ta xabar) tugadi! Davom ettirish uchun iltimos loyihani qayta xarid qiling (balansingizdan yechiladi)."]);
                exit;
            }
            $chat_cost = 0; // Follow-up messages within the 50-credit package are free
        }
    }

    if ($user['balance'] < $chat_cost) {
        echo json_encode(["error" => "Balansda mablag' yetarli emas! To'ldirib oling."]);
        exit;
    }

    $today = date("Y-m-d");

    // 2. Check Daily Limits based on chat type
    if ($chat_type === 'antigravity') {
        // Free chatbot: 10 queries per day limit
        $daily_chat_count = ($user['last_chat_date'] === $today) ? (int)$user['daily_chat_count'] : 0;
        
        if ($daily_chat_count >= 10) {
            echo json_encode(["error" => "Sizning kunlik bepul chat limitingiz (10 ta xabar) tugadi! Ertaga bemalol davom ettirishingiz yoki pullik paketlarimizdan xarid qilishingiz mumkin."]);
            exit;
        }
    } 
    elseif ($chat_type === 'prompt') {
        // Prompt assistant: 5 free queries per day + extra limits earned from media generation
        $daily_prompt_count = ($user['last_prompt_date'] === $today) ? (int)$user['daily_prompt_count'] : 0;
        $extra_limits = ($user['last_prompt_date'] === $today) ? (int)$user['extra_prompt_limits'] : 0;
        
        $allowed_limit = 5 + $extra_limits;
        
        if ($daily_prompt_count >= $allowed_limit) {
            echo json_encode(["error" => "Sizning bugungi bepul prompt limitingiz ($allowed_limit ta xabar) tugadi! Ko'proq rasm yoki video generatsiya qilib, qo'shimcha limitlar yutib oling."]);
            exit;
        }
    }

    // 3. Load config and keys
    $config = json_decode(file_get_contents(__DIR__ . "/config.json"), true);
    $current_date_info = "Bugungi sana: 2026-yil 13-iyul. Amerika Qo'shma Shtatlarining (AQSH) hozirgi (47-chi) prezidenti — Donald Trump.";

    // Define model names and load balance API keys
    if ($chat_type === 'antigravity' || $chat_type === 'prompt') {
        // Chatbot and Prompt helper use both free and paid keys for high reliability
        $gemini_keys = array_merge(
            isset($config['GEMINI_FREE_KEYS']) ? $config['GEMINI_FREE_KEYS'] : [],
            isset($config['GEMINI_PAID_KEYS']) ? $config['GEMINI_PAID_KEYS'] : []
        );
        $model_name = "gemini-3.1-flash-lite";

        
        if ($chat_type === 'antigravity') {
            $system_prompt = "Siz 'Neuroinson' platformasining rasmiy aqlli yordamchi chatbotisiz.\n" .
                "Muloqot boshlanganda yoki o'zingizni tanishtirganda, har doim 'Men Neuroinson AI loyihasining rasmiy yordamchi chatbotiman' deb javob bering.\n" .
                "Foydalanuvchi — o'zbekistonlik talaba, frilanser yoki yosh tadbirkor.\n\n" .
                "Qoidalar:\n" .
                "1. Oddiy salomlashuvlarda (masalan: 'salom', 'assalomu alaykum' deb yozilganda) har doim quyidagi formatda muloyim javob bering: 'Salom! (yoki Assalomu alaykum!) Yaxshimisiz? Men Neuroinson AI loyihasining rasmiy yordamchi chatbotiman. Sizga bugun qanday yordam bera olaman?'. Birinchi bo'lib suhbatni cho'zib 'ishlar qalay', 'o'zingizda nima yangiliklar' deb so'ramang.\n" .
                "2. Agar foydalanuvchining o'zi do'stona hol-ahvol yoki norasmiy savol so'rasa (masalan: 'ishlar qalay', 'nima gap uka', 'ishlar qanaqa'), siz ham xuddi shunday do'stona, samimiy va o'zbekona ohangda hurmat saqlab javob qaytaring ('Rahmat aka, tinchlik, ishlar yaxshi. O\'zingizda nima gaplar?'). Agar foydalanuvchi rasmiy yozsa, siz ham rasmiy javob bering.\n" .
                "3. Tizim, narxlar va imkoniyatlar haqida samimiy va o'zbekona tilda maslahat bering.\n" .
                "4. QAT'IY CHEKLOVLAR (RESTRICTIONS): Siz foydalanuvchi uchun veb-sayt yoki bot yaratib bermaysiz, dasturlash kodlari yozmaysiz, kurs ishi yoki diplom ishi yozmaysiz, slayd (taqdimot) tayyorlamaysiz.\n" .
                "5. Agar foydalanuvchi sizdan kod yozishni, bot yaratishni, diplom yoki kurs ishi yozishni so'rasa, muloyimlik bilan rad eting va quyidagicha javob bering:\n" .
                "   'Men faqat maslahat beraman. Professional bot/sayt yaratish, slayd qilish yoki diplom yozish uchun iltimos platformamizdagi maxsus pullik bo\'limlar yoki \"Loyihalar Markazi\" (Projects) bo\'limidan foydalaning.'\n\n" .
                "Kontekst: " . $current_date_info;
        } else {
            $system_prompt = "Siz 'Neuroinson AI Prompt Assistant' (Gemini Flash) mutaxassisiz.\n" .
                "Foydalanuvchi sizga rasm yoki video generatsiya qilish uchun o'zbekcha g'oya yuboradi.\n" .
                "Sizning vazifangiz: o'sha g'oyani Midjourney, Stable Diffusion yoki FLUX modellari tushunadigan, ingliz tilidagi mukammal, yuqori sifatli promptga aylantirib berish.\n" .
                "Javobingizda promptni ingliz tilida `<code>` formatida yozing va o'zbek tilida uning qisqacha mazmunini tushuntiring. Boshqa soxta va ortiqcha gaplarni ishlatmang.\n\n" .
                "Kontekst: " . $current_date_info;
        }
    }
    else {
        // Premium purchased project chat ('project-chat') uses only the paid key pool with Gemini 3.1 Pro Preview
        $active_project = isset($user['active_project_type']) ? trim($user['active_project_type']) : "";
        $project_restriction = "";
        if (!empty($active_project)) {
            $project_restriction = "DIQQAT: Foydalanuvchi premium darajada faqatgina '" . $active_project . "' loyihasini sotib olgan. Siz faqatgina ushbu loyihaga (masalan, taqdimot bo'lsa faqat slaydlar/ma'ruza matni, sayt/kod bo'lsa faqat sayt kodi) tegishli buyruqlarni bajarishingiz shart! Agar foydalanuvchi boshqa turdagi loyihani so'rasa (masalan, taqdimot sotib olib, unga mutlaqo alohida murakkab Telegram bot yoki Web App sayt yasab berishni so'rasa), unga faqat o'zining '" . $active_project . "' loyihasi bo'yicha yordam bera olishingizni o'ta muloyimlik bilan eslatib o'ting va so'ralgan boshqa mutlaqo aloqasiz loyihani yasab bermang!\n";
        }

        $system_prompt = "Siz 'Neuroinson Project Editor AI' (Gemini 3.1 Pro Premium) mutaxassisiz.\n" .
            "Siz foydalanuvchi sotib olgan premium loyiha ustida professional yordam berasiz.\n" .
            "Vazifangiz: Foydalanuvchining so'rovi asosida o'ta mukammal, xatosiz, professional dasturlash kodlarini yozish yoki ilmiy matnlarni (diplom, kurs ishi, insho) batafsil va chuqur shakllantirish.\n" .
            $project_restriction .
            "Javoblaringizni doimo chiroyli o'zbek tilida yozing. Kodlarni `<code>` yoki tegishli dasturlash tili bloklarida (`python`, `html`, `css`) formatlang. Hech qanday cheklovlarsiz ishlang.\n" .
            "QAT'IY QOIDA: Quyida berilgan kontekstdagi sanani yoki AQSH prezidenti ma'lumotlarini aslo o'zingiz yozayotgan dastur kodiga (HTML/CSS/JS), hujjat matniga yoki sayt footer qismiga qo'shmang! Ular faqat ichki tizim ma'lumoti xolos.\n\n" .
            "Kontekst: " . $current_date_info;
            
        $gemini_keys = isset($config['GEMINI_PAID_KEYS']) ? $config['GEMINI_PAID_KEYS'] : [];
        $model_name = "gemini-3.1-pro-preview";
    }
    
    if (empty($gemini_keys)) {
        echo json_encode(["error" => "API keys for this model are not configured."]);
        exit;
    }
    
    // Cap response lengths: 4096 tokens for first premium project purchase, but 2048 tokens for follow-up to optimize cost
    $max_tokens = ($chat_type === 'project-chat') ? (($chat_cost > 0) ? 4096 : 2048) : 1024;

    // Build multi-turn chat history contents array with consecutive role merging
    $contents = [];
    $history = isset($data['history']) ? $data['history'] : [];
    $last_role = null;

    foreach ($history as $msg) {
        $role = (isset($msg['role']) && $msg['role'] === 'user') ? 'user' : 'model';
        $msg_text = isset($msg['text']) ? trim($msg['text']) : "";
        if ($msg_text) {
            if ($role === $last_role) {
                // Merge text for consecutive identical roles
                $contents[count($contents) - 1]['parts'][0]['text'] .= "\n" . $msg_text;
            } else {
                $contents[] = [
                    "role" => $role,
                    "parts" => [
                        ["text" => $msg_text]
                    ]
                ];
                $last_role = $role;
            }
        }
    }

    // Append current prompt (merge if last message in history was also 'user')
    if ($last_role === 'user') {
        $contents[count($contents) - 1]['parts'][0]['text'] .= "\n" . $prompt;
    } else {
        $contents[] = [
            "role" => "user",
            "parts" => [
                ["text" => $prompt]
            ]
        ];
    }

    $payload = [
        "systemInstruction" => [
            "parts" => [
                ["text" => $system_prompt]
            ]
        ],
        "contents" => $contents,
        "generationConfig" => [
            "maxOutputTokens" => $max_tokens
        ]
    ];

    // Read blocked keys from local file
    $blocked_file = __DIR__ . "/blocked_keys.json";
    $blocked_keys = [];
    if (file_exists($blocked_file)) {
        $blocked_keys = json_decode(file_get_contents($blocked_file), true) ?: [];
    }

    $now = time();
    $active_keys = [];
    foreach ($gemini_keys as $k) {
        if (!isset($blocked_keys[$k]) || $now > $blocked_keys[$k]) {
            $active_keys[] = $k;
        }
    }

    // Fallback: if all are blocked, try paid keys or fallback to all keys
    if (empty($active_keys)) {
        $active_keys = isset($config['GEMINI_PAID_KEYS']) ? $config['GEMINI_PAID_KEYS'] : $gemini_keys;
    }

    shuffle($active_keys);
    $success = false;
    $reply = "";
    $last_error_msg = "Barcha Gemini kalitlari band yoki cheklovga uchragan.";

    foreach ($active_keys as $selected_key) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model_name . ":generateContent?key=" . $selected_key;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $timeout = ($chat_type === 'project-chat') ? 45 : 12;
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // High timeout for slow Pro model, fast for Flash

        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $response) {
            $res = json_decode($response, true);
            if (isset($res['candidates'][0]['content']['parts'][0]['text'])) {
                $reply = trim($res['candidates'][0]['content']['parts'][0]['text']);
                $success = true;
                // Log API usage
                try {
                    $stmt_u = $db->prepare("SELECT google_email FROM users WHERE telegram_id=?");
                    $stmt_u->execute([$telegram_id]);
                    $u_email = $stmt_u->fetchColumn() ?: '';
                    $stmt_l = $db->prepare("INSERT INTO api_usage_log (user_email, api_name, endpoint, model_name, prompt_length) VALUES (?, 'gemini', 'chat', ?, ?)");
                    $stmt_l->execute([$u_email, $model_name, strlen($prompt)]);
                } catch (Exception $e) {}
                break;
            }
        } else {
            // Block the key for 5 minutes if rate-limited
            if ($http_code === 429 || strpos($response, "RESOURCE_EXHAUSTED") !== false || strpos($response, "quota") !== false) {
                $blocked_keys[$selected_key] = time() + 300;
                file_put_contents($blocked_file, json_encode($blocked_keys));
            }
        }
        $last_error_msg = "Xatolik kod: $http_code. Javob: " . substr($response, 0, 200);
    }


    if (!$success) {
        echo json_encode(["error" => "Gemini API bandligi: " . $last_error_msg]);
        exit;
    }

        
        // Deduct balance (if cost > 0, which is true for project purchases)
        if ($chat_cost > 0) {
            $stmt = $db->prepare("UPDATE users SET balance = balance - ? WHERE telegram_id = ?");
            $stmt->execute([$chat_cost, $telegram_id]);

            // Tranzaksiya tarixi
            $stmt = $db->prepare("INSERT INTO transactions (telegram_id, type, amount, description) VALUES (?, 'chat', ?, 'Loyiha xaridi')");
            $stmt->execute([$telegram_id, -$chat_cost]);
        }

        // Handle project-chat limits
        $new_remaining = 0;
        if ($chat_type === 'project-chat') {
            if ($chat_cost > 0) {
                // Reset limit to 50 on new purchase
                $new_remaining = 50;
                $active_project = isset($data['project_name']) ? trim($data['project_name']) : "";
                $stmt = $db->prepare("UPDATE users SET project_chat_remaining = ?, active_project_type = ? WHERE telegram_id = ?");
                $stmt->execute([$new_remaining, $active_project, $telegram_id]);
            } else {
                // Decrement limit on follow-up
                $new_remaining = max(0, $remaining_credits - 1);
                $stmt = $db->prepare("UPDATE users SET project_chat_remaining = ? WHERE telegram_id = ?");
                $stmt->execute([$new_remaining, $telegram_id]);
            }
        }

        // Increment daily count for free chatbot
        if ($chat_type === 'antigravity') {
            $new_count = ($user['last_chat_date'] === $today) ? (int)$user['daily_chat_count'] + 1 : 1;
            $stmt = $db->prepare("UPDATE users SET daily_chat_count = ?, last_chat_date = ? WHERE telegram_id = ?");
            $stmt->execute([$new_count, $today, $telegram_id]);
        }
        // Increment daily count for prompt assistant
        elseif ($chat_type === 'prompt') {
            $new_prompt_count = ($user['last_prompt_date'] === $today) ? (int)$user['daily_prompt_count'] + 1 : 1;
            $extra_limits = ($user['last_prompt_date'] === $today) ? (int)$user['extra_prompt_limits'] : 0;
            $stmt = $db->prepare("UPDATE users SET daily_prompt_count = ?, extra_prompt_limits = ?, last_prompt_date = ? WHERE telegram_id = ?");
            $stmt->execute([$new_prompt_count, $extra_limits, $today, $telegram_id]);
        }
        
        // Calculate remaining daily messages for standard chats
        $daily_remaining = null;
        if ($chat_type === 'antigravity') {
            $new_count = isset($new_count) ? $new_count : ((isset($user['last_chat_date']) && $user['last_chat_date'] === $today) ? (int)$user['daily_chat_count'] + 1 : 1);
            $daily_remaining = max(0, 10 - $new_count);
        } elseif ($chat_type === 'prompt') {
            $new_prompt_count = isset($new_prompt_count) ? $new_prompt_count : 1;
            $allowed_limit = 5 + (isset($extra_limits) ? $extra_limits : 0);
            $daily_remaining = max(0, $allowed_limit - $new_prompt_count);
        }
        
        $stmt = $db->prepare("SELECT balance FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $fresh_balance = (int)$stmt->fetchColumn();

        $file_url = null;
        $file_label = null;
        if ($chat_type === 'project-chat') {
            $proj_name_saved = !empty($data['project_name']) ? $data['project_name'] : $active_project;
            $py_cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? "python" : "python3";
            $tmp_payload = json_encode([
                "reply" => $reply,
                "user_id" => $telegram_id,
                "project_name" => $proj_name_saved
            ]);
            $tmp_file = sys_get_temp_dir() . "/gen_input_" . time() . "_" . rand(1000, 9999) . ".json";
            file_put_contents($tmp_file, $tmp_payload);
            $py_script = __DIR__ . "/../file_generator.py";
            if (file_exists($py_script)) {
                $cmd = $py_cmd . " " . escapeshellarg($py_script) . " --json " . escapeshellarg($tmp_file);
                $out = shell_exec($cmd);
                if ($out) {
                    $res_gen = json_decode($out, true);
                    if ($res_gen && !empty($res_gen['file_url'])) {
                        $file_url = $res_gen['file_url'];
                        $file_label = $res_gen['file_label'];
                    }
                }
            }
            @unlink($tmp_file);
        }

        $resp = [
            "status" => "success",
            "reply" => $reply,
            "new_balance" => $fresh_balance
        ];
        if ($file_url) {
            $resp['file_url'] = $file_url;
            $resp['file_label'] = $file_label;
        }
        if ($daily_remaining !== null) {
            $resp['daily_remaining'] = $daily_remaining;
        }
        if ($chat_type === 'project-chat') {
            $resp['project_chat_remaining'] = $new_remaining;
        }
        echo json_encode($resp);

    
} catch (PDOException $e) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => $e->getMessage()]);
}

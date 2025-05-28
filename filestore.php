<?php

$botToken = "7854494775:AAEFTJIpwmZ7VYb0_o7gvgxwJaXZu-XRF9o";
$channelId = "-1002646820169"; // Your private storage channel ID
$botUsername = "tbcfilestoringbot"; // Without @
$api = "https://api.telegram.org/bot$botToken/";

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!isset($update["message"])) exit;

$message = $update["message"];
$chat_id = $message["chat"]["id"];
$message_id = $message["message_id"];
$first_name = $message["from"]["first_name"] ?? '';
$text = $message["text"] ?? '';
$caption_text = $message["caption"] ?? ''; // Capture caption

function sendMessage($chat_id, $text, $parse_mode = null) {
    global $api;
    $url = $api . "sendMessage?chat_id=$chat_id&text=" . urlencode($text);
    if ($parse_mode) $url .= "&parse_mode=$parse_mode";
    file_get_contents($url);
}

function forwardToStorage($from_chat, $msg_id) {
    global $api, $channelId;
    $url = $api . "forwardMessage?chat_id=$channelId&from_chat_id=$from_chat&message_id=$msg_id";
    return json_decode(file_get_contents($url), true);
}

function saveStorage($data) {
    file_put_contents("storage.json", json_encode($data, JSON_PRETTY_PRINT));
}

function loadStorage() {
    return file_exists("storage.json") ? json_decode(file_get_contents("storage.json"), true) : [];
}

function generateRandomId($length = 10) {
    return substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ", 5)), 0, $length);
}

$storage = loadStorage();

// START COMMAND
if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text);
    if (count($parts) > 1) {
        $random_id = $parts[1];
        if (isset($storage[$random_id])) {
            $data = $storage[$random_id];
            $file_id = $data["file_id"];
            $caption = "📎 File Name: " . $data["file_name"] . "\n📦 File Size: " . $data["file_size"];
            if (!empty($data["caption"])) {
                $caption .= "\n📝 Caption: " . $data["caption"];
            }

            $params = [
                "chat_id" => $chat_id,
                "caption" => $caption
            ];

            $media_type = $data["type"];
            $params[$media_type] = $file_id;

            $send_url = $api . "send" . ucfirst($media_type);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $send_url);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        } else {
            sendMessage($chat_id, "❌ Invalid file link or the file does not exist.");
        }
    } else {
        $welcome = "👋 Welcome to the Secure File Storage Bot!\n\n";
        $welcome .= "📥 *Instructions:*\n";
        $welcome .= "1. Send me any file, photo, video, audio, or sticker.\n";
        $welcome .= "2. I will securely store it and generate a unique link for access.\n";
        $welcome .= "3. Use the link to retrieve the file anytime.\n";
        $welcome .= "🔒 Your files are stored securely and privately.";
        sendMessage($chat_id, $welcome, "Markdown");
    }
    exit;
}

// HANDLE FILES
$allowed_types = ["document", "photo", "video", "audio", "sticker"];
foreach ($allowed_types as $type) {
    if (isset($message[$type])) {
        $forwarded = forwardToStorage($chat_id, $message_id);
        if (!$forwarded["ok"]) {
            sendMessage($chat_id, "❌ Failed to store your file.");
            exit;
        }

        $forwarded_msg = $forwarded["result"];
        $random_id = generateRandomId();
        $file_id = "";
        $file_name = ucfirst($type);
        $file_size = "Unknown";

        switch ($type) {
            case "document":
                $file_id = $forwarded_msg["document"]["file_id"];
                $file_name = $message["document"]["file_name"];
                $file_size = $message["document"]["file_size"];
                break;
            case "photo":
                $photos = $forwarded_msg["photo"];
                $file_id = end($photos)["file_id"];
                break;
            case "video":
                $file_id = $forwarded_msg["video"]["file_id"];
                $file_name = $message["video"]["file_name"] ?? "Video";
                $file_size = $message["video"]["file_size"];
                break;
            case "audio":
                $file_id = $forwarded_msg["audio"]["file_id"];
                $file_name = $message["audio"]["file_name"] ?? "Audio";
                $file_size = $message["audio"]["file_size"];
                break;
            case "sticker":
                $file_id = $forwarded_msg["sticker"]["file_id"];
                break;
        }

        if (is_numeric($file_size)) {
            $file_size = round($file_size / 1024, 2) . " KB";
        }

        $storage[$random_id] = [
            "file_id" => $file_id,
            "file_name" => $file_name,
            "file_size" => $file_size,
            "type" => $type,
        ];

        // Only save caption for photo and video
        if (in_array($type, ["photo", "video"]) && !empty($caption_text)) {
            $storage[$random_id]["caption"] = $caption_text;
        }

        saveStorage($storage);

        $file_link = "https://t.me/$botUsername?start=$random_id";

        $msg = "✅ *Your file has been securely saved!*\n\n";
        $msg .= "📎 *File Name:* $file_name\n";
        $msg .= "📦 *Size:* $file_size\n";
        if (!empty($caption_text) && in_array($type, ["photo", "video"])) {
            $msg .= "📝 *Caption:* $caption_text\n";
        }
        $msg .= "🔗 *Access Link:* [Click Here]($file_link)\n\n";
        $msg .= "⭕ *Permanent Link:* $file_link";
        sendMessage($chat_id, $msg, "Markdown");
        exit;
    }
}

// Unknown command fallback
sendMessage($chat_id, "⚠️ Invalid command. Use /start to begin.");

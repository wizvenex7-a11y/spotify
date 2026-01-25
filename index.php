<?php

// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8336071481:AAG91IOKs6r3b5SGIrC_gR4tmfjOnV_dQE8"; // YOUR TOKEN
$apiUrl   = "https://api.telegram.org/bot{$botToken}";

// ==========================================
// 2. FUNCTIONS
// ==========================================
function isHexBase16($s) {
    return $s !== "" && ctype_xdigit($s) && strlen($s) % 2 === 0;
}

// ==========================================
// 3. READ UPDATE
// ==========================================
$update = json_decode(file_get_contents("php://input"), true);

if (!isset($update["message"]["text"])) {
    exit;
}

$chat_id    = $update["message"]["chat"]["id"];
$message_id = $update["message"]["message_id"];
$text       = trim($update["message"]["text"]);

// ==========================================
// 4. IGNORE /start OR EMPTY
// ==========================================
if ($text === "/start" || $text === "") {
    exit;
}

// ==========================================
// 5. INVALID INPUT → DELETE
// ==========================================
if (!isHexBase16($text) || strlen($text) < 10) {
    file_get_contents(
        "{$apiUrl}/deleteMessage?chat_id={$chat_id}&message_id={$message_id}"
    );
    exit;
}

// ==========================================
// 6. DECODE HEX
// ==========================================
$decoded = hex2bin($text);

if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
    file_get_contents(
        "{$apiUrl}/deleteMessage?chat_id={$chat_id}&message_id={$message_id}"
    );
    exit;
}

// ==========================================
// 7. DELETE USER MESSAGE
// ==========================================
file_get_contents(
    "{$apiUrl}/deleteMessage?chat_id={$chat_id}&message_id={$message_id}"
);

// ==========================================
// 8. FORMAT MESSAGE
// ==========================================
$msg = "<b>" . htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8') . "</b>";

// ==========================================
// 9. SEND BOT MESSAGE
// ==========================================
$data = [
    'chat_id'    => $chat_id,
    'text'       => $msg,
    'parse_mode' => 'HTML'
];

$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded",
        'content' => http_build_query($data)
    ]
];

file_get_contents(
    "{$apiUrl}/sendMessage",
    false,
    stream_context_create($options)
);

?>

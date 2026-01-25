<?php

// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8100882870:AAEIEyLNU1dYh7KiUWDr4HATQyWVlA6jc-s"; // Put your token here
$apiUrl   = "https://api.telegram.org/bot$botToken";


/* ================= HEX CHECK ================= */
function isHexBase16($s) {
    return $s !== "" && ctype_xdigit($s) && strlen($s) % 2 === 0;
}

/* ================= READ UPDATE ================= */
$update = json_decode(file_get_contents("php://input"), true);
if (!isset($update["message"]["text"])) exit;

$chat_id    = $update["message"]["chat"]["id"];
$message_id = $update["message"]["message_id"];
$text       = trim($update["message"]["text"]);

/* ================= IGNORE /START ================= */
if ($text === "/start" || $text === "") exit;

/* ================= INVALID CONDITIONS ================= */
if (!isHexBase16($text) || strlen($text) < 10) {
    // delete invalid message
    @file_get_contents(
        "$api/deleteMessage?chat_id=$chat_id&message_id=$message_id"
    );
    exit;
}

/* ================= DECODE HEX ================= */
$decoded = hex2bin($text);
if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
    @file_get_contents(
        "$api/deleteMessage?chat_id=$chat_id&message_id=$message_id"
    );
    exit;
}

/* ================= DELETE VALID USER MESSAGE ================= */
@file_get_contents(
    "$api/deleteMessage?chat_id=$chat_id&message_id=$message_id"
);

/* ================= FORMAT MESSAGE ================= */
$msg = "<b>" . htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8') . "</b>";

/* ================= SEND BOT MESSAGE ================= */
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
    "$api/sendMessage",
    false,
    stream_context_create($options)
);

?>



<?php

// ==========================================
// 1. CONFIGURATION
// ==========================================
$botToken = "8336071481:AAG91IOKs6r3b5SGIrC_gR4tmfjOnV_dQE8"; // YOUR TOKEN
$apiUrl   = "https://api.telegram.org/bot$botToken";

// ==========================================
// 2. SPOTDL LOGIC CLASS
// ==========================================
class SpotDL {
    private $cookieFile;
    private $csrfToken = null;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36';

    public function __construct() {
        // Create a unique temporary file to store cookies for this session
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'spotdl_' . uniqid());
    }

    public function __destruct() {
        // Clean up cookie file after execution
        if (file_exists($this->cookieFile)) @unlink($this->cookieFile);
    }

    // REQUEST 1: Visit v2 to get CSRF Token and Cookies
    public function initSession() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://spotdl.io/v2');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile); // Save cookies
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Handle gzip
        
        // precise headers from your curl
        $headers = [
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'accept-language: en-GB,en-US;q=0.9,en;q=0.8,ar;q=0.7',
            'cache-control: max-age=0',
            'priority: u=0, i',
            'referer: https://spotdl.io/v2',
            'sec-ch-ua: "Not(A:Brand";v="8", "Chromium";v="144", "Google Chrome";v="144"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: document',
            'sec-fetch-mode: navigate',
            'sec-fetch-site: same-origin',
            'sec-fetch-user: ?1',
            'upgrade-insecure-requests: 1',
            'user-agent: ' . $this->userAgent
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) return false;

        // Extract CSRF Token
        if (preg_match('/<meta\s+name="csrf-token"\s+content="(.*?)"/', $html, $matches)) {
            $this->csrfToken = $matches[1];
            return true;
        }
        return false;
    }

    // REQUEST 2: Get Metadata
    public function getTrackData($spotifyUrl) {
        if (!$this->csrfToken) $this->initSession();

        return $this->postRequest('https://spotdl.io/getTrackData', [
            'spotify_url' => $spotifyUrl
        ]);
    }

    // REQUEST 3: Convert/Get MP3
    public function convert($spotifyUrl) {
        // Ensure session exists
        if (!$this->csrfToken) $this->initSession();

        return $this->postRequest('https://spotdl.io/convert', [
            'urls' => $spotifyUrl
        ]);
    }

    private function postRequest($url, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile); // Keep using same cookies
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        
        // Headers for API calls
        $headers = [
            'accept: */*',
            'accept-language: en-GB,en-US;q=0.9,en;q=0.8,ar;q=0.7',
            'content-type: application/json',
            'origin: https://spotdl.io',
            'priority: u=1, i',
            'referer: https://spotdl.io/v2',
            'sec-ch-ua: "Not(A:Brand";v="8", "Chromium";v="144", "Google Chrome";v="144"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'user-agent: ' . $this->userAgent,
            'x-csrf-token: ' . $this->csrfToken
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}

// ==========================================
// 3. TELEGRAM BOT HANDLER
// ==========================================

$content = file_get_contents("php://input");
$update  = json_decode($content, true);

if (isset($update["message"]["text"])) {
    $chatId = $update["message"]["chat"]["id"];
    $text   = trim($update["message"]["text"]);
    $msgId  = $update["message"]["message_id"];

    // 1. /start
    if ($text === "/start") {
        sendMessage($chatId, "👋 Send a Spotify link (Track, Artist, or Album).", $msgId);
        exit;
    }

    // 2. Validate Link
    if (!preg_match('/open\.spotify\.com\/(track|artist|album|playlist)\/[a-zA-Z0-9]+/', $text)) {
        sendMessage($chatId, "❌ Invalid Spotify Link.", $msgId);
        exit;
    }

    // 3. Send Status
    file_get_contents("$apiUrl/sendChatAction?chat_id=$chatId&action=upload_voice");

    try {
        $spotDl = new SpotDL();

        // REQ 1: Init Session (Implicitly called by getTrackData)
        
        // REQ 2: Get Metadata
        $meta = $spotDl->getTrackData($text);
        
        if (!isset($meta['data'])) {
            // If failed, try initialization explicitly and try again
            if (!$spotDl->initSession()) {
                throw new Exception("Failed to bypass Cloudflare/Init session.");
            }
            $meta = $spotDl->getTrackData($text);
            if (!isset($meta['data'])) throw new Exception("Could not fetch metadata.");
        }

        // Prepare Metadata
        $title  = $meta['data']['name'];
        $artist = $meta['data']['artists'][0]['name'] ?? 'Unknown';
        $cover  = $meta['data']['album']['images'][0]['url'] ?? '';
        $dur    = floor(($meta['data']['duration_ms'] ?? 0) / 1000);

        // REQ 3: Convert/Download
        $convert = $spotDl->convert($text);

        if (!isset($convert['file_url'])) {
            throw new Exception("Conversion failed or still processing.");
        }

        $mp3Url = $convert['file_url'];

        // Send Audio
        $postData = [
            'chat_id'   => $chatId,
            'audio'     => $mp3Url,
            'caption'   => "🎧 <b>$title</b>\n👤 $artist",
            'parse_mode'=> 'HTML',
            'title'     => $title,
            'performer' => $artist,
            'duration'  => $dur,
            'thumb'     => $cover,
            'reply_to_message_id' => $msgId
        ];

        $ch = curl_init("$apiUrl/sendAudio");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);

    } catch (Exception $e) {
        sendMessage($chatId, "⚠️ Error: " . $e->getMessage(), $msgId);
    }
}

function sendMessage($chatId, $text, $replyId) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId, 
        'text' => $text, 
        'parse_mode' => 'HTML', 
        'reply_to_message_id' => $replyId
    ];
    file_get_contents("$apiUrl/sendMessage?" . http_build_query($data));
}
?>

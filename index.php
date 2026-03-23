<?php
// ==========================================
// CONFIGURATION
// ==========================================

// Batch & Delay Settings
$batch_size = 5;      // Number of tracks to process per request. You requested 5.
$sleep_time = 2;      // Seconds to sleep after EACH track

// Paths
$csv_file = __DIR__ . '/ids.csv';          // CSV file containing track metadata
$failed_log = __DIR__ . '/failed.txt';     // Log for failed tracks
$success_log = __DIR__ . '/success.txt';   // Log for successful tracks
$download_dir = __DIR__ . '/downloads/';   // Where MP3s will be saved

// Column Mapping (0-indexed, based on your CSV header)
define('COL_TRACK_URI', 0);
define('COL_TRACK_NAME', 1);
define('COL_ARTIST_NAME', 3);
define('COL_ALBUM_NAME', 5);
define('COL_ALBUM_IMAGE_URL', 9);
define('COL_TRACK_DURATION', 12);

// ==========================================
// SCRIPT INITIALIZATION
// ==========================================

// Ensure download directory exists
if (!file_exists($download_dir)) {
    mkdir($download_dir, 0777, true);
}

// Ensure CSV file exists
if (!file_exists($csv_file)) {
    die("[ERROR] The CSV file does not exist at: {$csv_file}\n");
}

// ==========================================
// HELPERS
// ==========================================

/**
 * Reads the first N rows from a CSV, removes them, and rewrites the file.
 * This is a critical function to ensure the queue is processed correctly.
 * @param string $filePath Path to the CSV file
 * @param int $batchSize Number of data rows to pop from the top
 * @return array The rows that were popped (for processing)
 */
function popRowsFromCsvQueue($filePath, $batchSize) {
    $poppedRows = [];
    $remainingLines = [];
    
    // Use file locking to prevent race conditions if script is run multiple times
    $fp = fopen($filePath, 'r+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return []; // Failed to get a lock
    }

    // Read all lines into memory
    $header = fgets($fp); // Read and keep the header
    $allLines = [];
    while (($line = fgets($fp)) !== false) {
        $allLines[] = $line;
    }

    // Separate the batch to process from the ones to keep
    $batchToProcess = array_slice($allLines, 0, $batchSize);
    $linesToKeep = array_slice($allLines, $batchSize);

    // Rewrite the file with the remaining lines
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $header); // Write header back
    foreach ($linesToKeep as $line) {
        fwrite($fp, $line);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
    
    // Parse the popped lines from raw CSV string to array
    foreach ($batchToProcess as $csvString) {
        $poppedRows[] = str_getcsv(trim($csvString));
    }

    return $poppedRows;
}

function logFailedTrack($id, $reason) {
    global $failed_log;
    file_put_contents($failed_log, date('[Y-m-d H:i:s] ') . $id . " | ERROR: " . $reason . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function logSuccessfulTrack($id, $title, $artist) {
    global $success_log;
    file_put_contents($success_log, date('[Y-m-d H:i:s] ') . $id . " | " . $title . " - " . $artist . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function sanitizeFilename($filename) {
    $filename = preg_replace('/[<>:"\/\\|?*]/', '', $filename);
    return rtrim($filename, ' .');
}

// ==========================================
// SPOTISAVER DOWNLOADER (No changes needed here)
// ==========================================
class SpotiSaverDownloader {
    public $lastError = '';

    public function downloadTrack($meta) {
        if (!$meta) return false;

        $payload = [
            'track' => [
                'name' => $meta['title'], 
                'artists' => [$meta['artist']], 
                'album' => $meta['album'],
                'image' => [ 'url' => $meta['thumbnail'], 'width' => 640, 'height' => 640 ],
                'id' => $meta['id'], 
                'external_url' => $meta['url'], 
                'duration_ms' => (int)$meta['duration_ms'],
            ],
            'download_dir' => 'downloads', 
            'filename_tag' => 'SPOTISAVER', 
            'user_ip' => '127.0.0.100' 
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://spotisaver.net/api/download_track.php', 
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true, 
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'content-type: application/json', 
                'referer: https://spotisaver.net/',
                'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        if (curl_errno($ch)) {
            $this->lastError = 'Download cURL Error: ' . curl_error($ch);
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            $this->lastError = "SpotiSaver returned HTTP {$httpCode}.";
            return false;
        }

        if (strpos($contentType, 'audio/') === false && strpos($contentType, 'application/octet-stream') === false) {
            $this->lastError = "SpotiSaver returned invalid content type: {$contentType}";
            return false;
        }

        return $response; 
    }
}


// ==========================================
// MAIN PROCESSING LOOP
// ==========================================
header('Content-Type: text/plain; charset=utf-8');
mb_internal_encoding("UTF-8");
set_time_limit(0); 

echo "[*] SPOTIFY CSV DOWNLOADER STARTED\n";
echo "[*] Batch Limit: {$batch_size} Tracks per request.\n";
echo "[*] Reading from: {$csv_file}\n\n";

// Get the batch of tracks and remove them from the CSV file
$tracksToProcess = popRowsFromCsvQueue($csv_file, $batch_size);

if (empty($tracksToProcess)) {
    echo "[*] QUEUE IS EMPTY. NOTHING TO PROCESS.\n";
    exit;
}

$downloader = new SpotiSaverDownloader();
$processed_count = 0;

foreach ($tracksToProcess as $csvRow) {
    $processed_count++;

    // Extract Track ID from URI (e.g., "spotify:track:2n3qid...")
    $trackId = str_replace('spotify:track:', '', $csvRow[COL_TRACK_URI]);

    echo "[{$processed_count}/" . count($tracksToProcess) . "] [+] Processing Track ID: {$trackId}\n";
    
    // Map CSV data to the metadata array required by the downloader
    $meta = [
        'id'          => $trackId,
        'title'       => $csvRow[COL_TRACK_NAME],
        'artist'      => $csvRow[COL_ARTIST_NAME],
        'album'       => $csvRow[COL_ALBUM_NAME],
        'thumbnail'   => $csvRow[COL_ALBUM_IMAGE_URL],
        'url'         => "https://open.spotify.com/track/" . $trackId,
        'duration_ms' => $csvRow[COL_TRACK_DURATION]
    ];
    
    echo "   -> Meta: {$meta['artist']} - {$meta['title']}\n";

    $safeName = sanitizeFilename($meta['artist'] . " - " . $meta['title']);
    $savePath = $download_dir . $safeName . ".mp3";

    if (file_exists($savePath)) {
        echo "   -> [SKIP] File already exists.\n\n";
        sleep($sleep_time);
        continue;
    }

    $fileData = $downloader->downloadTrack($meta);
    if (!$fileData) {
        echo "   -> [ERROR] Download Failed: {$downloader->lastError}\n\n";
        logFailedTrack($trackId, "Download Error: " . $downloader->lastError);
        sleep($sleep_time);
        continue;
    }

    file_put_contents($savePath, $fileData);

    echo "   -> [SUCCESS] Downloaded successfully!\n\n";
    logSuccessfulTrack($trackId, $meta['title'], $meta['artist']);

    sleep($sleep_time);
}

echo "[*] PROCESSED {$processed_count} TRACKS. SCRIPT EXITING NATURALLY.\n";
exit;
?>

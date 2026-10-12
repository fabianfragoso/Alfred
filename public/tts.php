<?php
/**
 * Alfred natural-voice relay.
 * Turns text into speech with ElevenLabs, OpenAI or Microsoft Azure.
 * The service keys live in ~/alfred-config.php on the server, outside the website folder,
 * so they are never sent to the browser or stored on GitHub.
 */

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fail($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

$configFile = dirname(__DIR__) . '/alfred-config.php';
if (!is_file($configFile)) fail(500, 'Voice services are not set up on the server yet (alfred-config.php is missing).');
$cfg = require $configFile;
if (!is_array($cfg) || empty($cfg['passcode']) || $cfg['passcode'] === 'CHANGE-ME') {
    fail(500, 'Set a passcode in alfred-config.php first.');
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$passcode = (string)($in['passcode'] ?? '');
if (!hash_equals((string)$cfg['passcode'], $passcode)) {
    usleep(400000); // slow down guessing
    fail(401, 'Wrong access code. Check it in Alfred\'s Settings.');
}

$has = [
    'elevenlabs' => !empty($cfg['elevenlabs_key']),
    'openai'     => !empty($cfg['openai_key']),
    'azure'      => !empty($cfg['azure_key']) && !empty($cfg['azure_region']),
];

// Status check: which services are ready
if (!empty($in['status'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'providers' => $has]);
    exit;
}

$provider = (string)($in['provider'] ?? '');
$voice    = trim((string)($in['voice'] ?? ''));
$text     = trim((string)($in['text'] ?? ''));
if ($text === '') fail(400, 'Nothing to say.');
if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);
if (!isset($has[$provider])) fail(400, 'Unknown voice service.');
if (!$has[$provider]) fail(400, 'No key for ' . $provider . ' in alfred-config.php.');
if ($voice === '' || !preg_match('/^[A-Za-z0-9_\-:.]{1,80}$/', $voice)) fail(400, 'Invalid voice.');

switch ($provider) {
    case 'elevenlabs':
        $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voice) . '?output_format=mp3_44100_128';
        $headers = ['xi-api-key: ' . $cfg['elevenlabs_key'], 'Content-Type: application/json', 'Accept: audio/mpeg'];
        $body = json_encode([
            'text' => $text,
            'model_id' => $cfg['elevenlabs_model'] ?? 'eleven_turbo_v2_5',
        ]);
        break;

    case 'openai':
        $url = 'https://api.openai.com/v1/audio/speech';
        $headers = ['Authorization: Bearer ' . $cfg['openai_key'], 'Content-Type: application/json'];
        $body = json_encode([
            'model' => $cfg['openai_model'] ?? 'gpt-4o-mini-tts',
            'voice' => $voice,
            'input' => $text,
            'instructions' => $cfg['openai_style'] ?? 'Speak like a calm, refined, warm British butler. Natural pace, light dry wit.',
            'response_format' => 'mp3',
        ]);
        break;

    case 'azure':
        $region = preg_replace('/[^a-z0-9]/', '', strtolower($cfg['azure_region']));
        $url = 'https://' . $region . '.tts.speech.microsoft.com/cognitiveservices/v1';
        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $cfg['azure_key'],
            'Content-Type: application/ssml+xml',
            'X-Microsoft-OutputFormat: audio-24khz-96kbitrate-mono-mp3',
            'User-Agent: Alfred',
        ];
        $lang = implode('-', array_slice(explode('-', $voice), 0, 2));
        $body = '<speak version="1.0" xml:lang="' . htmlspecialchars($lang, ENT_XML1) . '">'
              . '<voice name="' . htmlspecialchars($voice, ENT_XML1) . '">'
              . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
              . '</voice></speak>';
        break;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$audio = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($audio === false) fail(502, 'Could not reach ' . $provider . ': ' . $err);
if ($status < 200 || $status >= 300) {
    $msg = $audio;
    $j = json_decode($audio, true);
    if (is_array($j)) {
        $msg = $j['detail']['message'] ?? $j['error']['message'] ?? $j['detail'] ?? $j['message'] ?? $audio;
        if (is_array($msg)) $msg = json_encode($msg);
    }
    fail(502, ucfirst($provider) . ' error ' . $status . ': ' . mb_substr((string)$msg, 0, 300));
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($audio));
echo $audio;

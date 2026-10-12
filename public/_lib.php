<?php
/**
 * Shared helpers for Alfred's server side. Not reachable from the web (.htaccess blocks files starting with "_").
 * Secrets live in ~/alfred-config.php; mail tokens live in ~/alfred-data/, both outside the website folder.
 */

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const ALFRED_URL = 'https://alfred.fwsolutions.ca';

function alfred_home() { return dirname(__DIR__); }

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function fail($code, $msg) { json_out(['error' => $msg], $code); }

function config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = alfred_home() . '/alfred-config.php';
    if (!is_file($file)) fail(500, 'Server settings missing: create alfred-config.php in your home folder.');
    $cfg = require $file;
    if (!is_array($cfg) || empty($cfg['passcode']) || $cfg['passcode'] === 'CHANGE-ME') {
        fail(500, 'Set an access code (passcode) in alfred-config.php first.');
    }
    return $cfg;
}

function check_passcode($given) {
    $cfg = config();
    if (!hash_equals((string)$cfg['passcode'], (string)$given)) {
        usleep(400000);
        fail(401, 'Wrong access code. Check it in Alfred\'s Settings.');
    }
}

function read_json_body() {
    $in = json_decode(file_get_contents('php://input'), true);
    return is_array($in) ? $in : [];
}

/* ---------- token storage ---------- */

function data_dir() {
    $d = alfred_home() . '/alfred-data';
    if (!is_dir($d)) { mkdir($d, 0700, true); }
    return $d;
}

function token_file() { return data_dir() . '/microsoft-token.json'; }

function load_token() {
    $f = token_file();
    if (!is_file($f)) return null;
    $t = json_decode(file_get_contents($f), true);
    return is_array($t) ? $t : null;
}

function save_token($t) {
    $f = token_file();
    file_put_contents($f, json_encode($t), LOCK_EX);
    chmod($f, 0600);
}

/* ---------- Microsoft sign-in ---------- */

function ms_settings() {
    $cfg = config();
    foreach (['ms_client_id', 'ms_client_secret', 'ms_tenant_id'] as $k) {
        if (empty($cfg[$k])) fail(500, 'Email is not set up yet: add ' . $k . ' to alfred-config.php.');
    }
    return $cfg;
}

const MS_SCOPES = 'offline_access User.Read Mail.ReadWrite Mail.Send';

function ms_token_request($fields) {
    $cfg = ms_settings();
    $url = 'https://login.microsoftonline.com/' . rawurlencode($cfg['ms_tenant_id']) . '/oauth2/v2.0/token';
    $fields += [
        'client_id' => $cfg['ms_client_id'],
        'client_secret' => $cfg['ms_client_secret'],
        'scope' => MS_SCOPES,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$res, true);
    if ($code !== 200 || empty($j['access_token'])) {
        $msg = $j['error_description'] ?? $j['error'] ?? ('HTTP ' . $code);
        return ['error' => strtok($msg, "\r\n")];
    }
    return $j;
}

function store_new_tokens($j, $account = null) {
    $old = load_token() ?: [];
    save_token([
        'access_token' => $j['access_token'],
        'refresh_token' => $j['refresh_token'] ?? ($old['refresh_token'] ?? null),
        'expires_at' => time() + (int)($j['expires_in'] ?? 3600) - 120,
        'account' => $account ?? ($old['account'] ?? null),
    ]);
}

function access_token() {
    $t = load_token();
    if (!$t || empty($t['refresh_token'])) fail(409, 'Email is not connected. Open Alfred Settings and tap Connect Microsoft 365.');
    if (time() < (int)$t['expires_at']) return $t['access_token'];
    $j = ms_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
    if (isset($j['error'])) fail(409, 'Email sign-in expired, please reconnect in Settings. (' . $j['error'] . ')');
    store_new_tokens($j);
    return $j['access_token'];
}

/* ---------- Microsoft Graph ---------- */

function graph($method, $path, $body = null, $extraHeaders = []) {
    $token = access_token();
    $ch = curl_init('https://graph.microsoft.com/v1.0' . $path);
    $headers = array_merge(['Authorization: Bearer ' . $token, 'Accept: application/json'], $extraHeaders);
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) fail(502, 'Could not reach Microsoft: ' . $err);
    $j = $res === '' ? [] : json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        fail(502, 'Microsoft error ' . $code . ': ' . ($j['error']['message'] ?? substr($res, 0, 200)));
    }
    return is_array($j) ? $j : [];
}

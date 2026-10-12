<?php
/**
 * Step 2 of connecting Microsoft 365: Microsoft sends the user back here with a one-time code,
 * which is exchanged for tokens stored privately in ~/alfred-data/.
 */
require __DIR__ . '/_lib.php';
session_start();

function page($title, $msg, $ok) {
    header('Content-Type: text/html; charset=utf-8');
    $color = $ok ? '#5cc8c0' : '#e2685a';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . ' · Alfred</title>'
       . '<style>:root{color-scheme:dark}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0d1117;color:#e6e1d6;font:15px/1.5 system-ui,-apple-system,sans-serif;padding:16px;box-sizing:border-box}'
       . 'div{max-width:380px;background:#151b24;border:1px solid #263040;border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:12px}'
       . 'h1{font-size:15px;letter-spacing:.2em;margin:0;color:' . $color . '}p{margin:0}a{background:#e0a64a;color:#1a1307;border-radius:10px;padding:12px;text-align:center;font-weight:600;text-decoration:none}</style></head>'
       . '<body><div><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($msg) . '</p><a href="' . ALFRED_URL . '/">Back to Alfred</a></div></body></html>';
    exit;
}

if (!empty($_GET['error'])) {
    page('Not connected', 'Microsoft said: ' . ($_GET['error_description'] ?? $_GET['error']), false);
}
$state = (string)($_GET['state'] ?? '');
if ($state === '' || empty($_SESSION['alfred_state']) || !hash_equals($_SESSION['alfred_state'], $state)) {
    page('Not connected', 'This sign-in link expired. Please start again from Alfred Settings.', false);
}
unset($_SESSION['alfred_state']);

$code = (string)($_GET['code'] ?? '');
if ($code === '') page('Not connected', 'Microsoft did not return a sign-in code.', false);

$j = ms_token_request([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => ALFRED_URL . '/callback.php',
]);
if (isset($j['error'])) page('Not connected', $j['error'], false);

store_new_tokens($j);
$me = graph('GET', '/me?$select=displayName,mail,userPrincipalName');
$account = $me['mail'] ?? $me['userPrincipalName'] ?? 'your account';
store_new_tokens($j, $account);

page('Email connected', 'Alfred can now read and draft email for ' . $account . '. He will always ask before sending.', true);

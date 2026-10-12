<?php
/**
 * Step 1 of connecting Microsoft 365: ask for the access code, then send the user to Microsoft's sign-in page.
 */
require __DIR__ . '/_lib.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = config();
    if (!hash_equals((string)$cfg['passcode'], (string)($_POST['passcode'] ?? ''))) {
        usleep(400000);
        $error = 'Wrong access code.';
    } else {
        $ms = ms_settings();
        session_start();
        $_SESSION['alfred_state'] = bin2hex(random_bytes(16));
        $q = http_build_query([
            'client_id' => $ms['ms_client_id'],
            'response_type' => 'code',
            'redirect_uri' => ALFRED_URL . '/callback.php',
            'response_mode' => 'query',
            'scope' => MS_SCOPES,
            'state' => $_SESSION['alfred_state'],
            'prompt' => 'select_account',
        ]);
        header('Location: https://login.microsoftonline.com/' . rawurlencode($ms['ms_tenant_id']) . '/oauth2/v2.0/authorize?' . $q);
        exit;
    }
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connect email · Alfred</title>
<style>
:root{color-scheme:dark}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0d1117;color:#e6e1d6;font:15px/1.5 system-ui,-apple-system,sans-serif;padding:16px;box-sizing:border-box}
form{width:100%;max-width:380px;background:#151b24;border:1px solid #263040;border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:12px}
h1{font-size:15px;letter-spacing:.3em;margin:0}
p{margin:0;color:#8a93a3;font-size:13px}
input{background:#0d1117;border:1px solid #263040;color:#e6e1d6;border-radius:10px;padding:12px;font:15px system-ui}
button{background:#e0a64a;border:0;color:#1a1307;border-radius:10px;padding:12px;font:600 15px system-ui;cursor:pointer}
.err{color:#e2685a}
</style></head><body>
<form method="post">
  <h1>ALFRED · EMAIL</h1>
  <p>Enter your Alfred access code, then sign in with your Microsoft 365 account to let Alfred read your mail and prepare drafts. He never sends anything without your confirmation.</p>
  <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <input type="password" name="passcode" placeholder="Access code" autocomplete="current-password" required autofocus>
  <button type="submit">Continue to Microsoft sign-in</button>
</form>
</body></html>

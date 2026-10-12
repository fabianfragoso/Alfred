<?php
/**
 * Alfred's email actions (Microsoft 365 via Microsoft Graph).
 * Every call needs the access code. Drafts are only sent by the "send" action,
 * which Alfred's page calls only after the user taps Send or says "send it".
 */
require __DIR__ . '/_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Use POST.');
$in = read_json_body();
check_passcode($in['passcode'] ?? '');
$action = (string)($in['action'] ?? '');

function clean_id($id) {
    $id = (string)$id;
    if ($id === '' || strlen($id) > 300 || !preg_match('/^[A-Za-z0-9_\-=+\/]+$/', $id)) fail(400, 'Invalid message id.');
    return rawurlencode($id);
}

function person($r) {
    $e = $r['emailAddress'] ?? [];
    $name = $e['name'] ?? '';
    $addr = $e['address'] ?? '';
    return $name && $name !== $addr ? "$name <$addr>" : $addr;
}

function summary($m) {
    return [
        'id' => $m['id'],
        'from' => person($m['from'] ?? []),
        'subject' => $m['subject'] ?? '(no subject)',
        'received' => $m['receivedDateTime'] ?? '',
        'unread' => isset($m['isRead']) ? !$m['isRead'] : null,
        'preview' => mb_substr(trim(preg_replace('/\s+/', ' ', $m['bodyPreview'] ?? '')), 0, 160),
    ];
}

function draft_out($d) {
    return [
        'draft_id' => $d['id'],
        'to' => array_map('person', $d['toRecipients'] ?? []),
        'cc' => array_map('person', $d['ccRecipients'] ?? []),
        'subject' => $d['subject'] ?? '',
        'body' => mb_substr(trim(strip_tags(html_entity_decode($d['body']['content'] ?? '', ENT_QUOTES))), 0, 4000),
    ];
}

function recipients($list) {
    if (!is_array($list)) $list = [$list];
    $out = [];
    foreach ($list as $a) {
        $a = trim((string)$a);
        if (!filter_var($a, FILTER_VALIDATE_EMAIL)) fail(400, 'Not a valid email address: ' . $a);
        $out[] = ['emailAddress' => ['address' => $a]];
    }
    if (!$out) fail(400, 'Who should it go to? Give an email address.');
    return $out;
}

switch ($action) {
    case 'status':
        $t = load_token();
        json_out(['connected' => (bool)($t['refresh_token'] ?? null), 'account' => $t['account'] ?? null]);

    case 'disconnect':
        @unlink(token_file());
        json_out(['connected' => false]);

    case 'list':
        $count = max(1, min(20, (int)($in['count'] ?? 8)));
        $select = 'id,subject,from,receivedDateTime,isRead,bodyPreview';
        $search = trim((string)($in['search'] ?? ''));
        if ($search !== '') {
            $q = '/me/messages?$top=' . $count . '&$select=' . $select . '&$search=' . rawurlencode('"' . str_replace('"', '', $search) . '"');
        } elseif (!empty($in['unread_only'])) {
            $q = '/me/mailFolders/inbox/messages?$top=' . $count . '&$select=' . $select . '&$filter=' . rawurlencode('isRead eq false');
        } else {
            $q = '/me/mailFolders/inbox/messages?$top=' . $count . '&$select=' . $select . '&$orderby=' . rawurlencode('receivedDateTime desc');
        }
        $r = graph('GET', $q);
        json_out(['messages' => array_map('summary', $r['value'] ?? [])]);

    case 'read':
        $id = clean_id($in['id'] ?? '');
        $m = graph('GET', '/me/messages/' . $id . '?$select=id,subject,from,toRecipients,ccRecipients,receivedDateTime,body,hasAttachments',
                   null, ['Prefer: outlook.body-content-type="text"']);
        $body = trim(preg_replace("/\n{3,}/", "\n\n", $m['body']['content'] ?? ''));
        if (!empty($in['mark_read'])) graph('PATCH', '/me/messages/' . $id, ['isRead' => true]);
        json_out([
            'id' => $m['id'],
            'from' => person($m['from'] ?? []),
            'to' => array_map('person', $m['toRecipients'] ?? []),
            'cc' => array_map('person', $m['ccRecipients'] ?? []),
            'subject' => $m['subject'] ?? '',
            'received' => $m['receivedDateTime'] ?? '',
            'has_attachments' => $m['hasAttachments'] ?? false,
            'body' => mb_substr($body, 0, 6000),
            'truncated' => mb_strlen($body) > 6000,
        ]);

    case 'draft_reply':
        $id = clean_id($in['id'] ?? '');
        $text = trim((string)($in['body'] ?? ''));
        if ($text === '') fail(400, 'The reply is empty.');
        $path = !empty($in['reply_all']) ? '/createReplyAll' : '/createReply';
        $d = graph('POST', '/me/messages/' . $id . $path, ['comment' => $text]);
        json_out(['draft' => draft_out($d) + ['body' => mb_substr($text, 0, 4000)]]);

    case 'draft_new':
        $text = trim((string)($in['body'] ?? ''));
        if ($text === '') fail(400, 'The email is empty.');
        $msg = [
            'subject' => mb_substr(trim((string)($in['subject'] ?? '')), 0, 250),
            'body' => ['contentType' => 'Text', 'content' => $text],
            'toRecipients' => recipients($in['to'] ?? []),
        ];
        if (!empty($in['cc'])) $msg['ccRecipients'] = recipients($in['cc']);
        $d = graph('POST', '/me/messages', $msg);
        json_out(['draft' => draft_out($d)]);

    case 'send':
        $id = clean_id($in['draft_id'] ?? '');
        $d = graph('GET', '/me/messages/' . $id . '?$select=isDraft');
        if (empty($d['isDraft'])) fail(400, 'That message is not a draft.');
        graph('POST', '/me/messages/' . $id . '/send');
        json_out(['sent' => true]);

    case 'discard':
        $id = clean_id($in['draft_id'] ?? '');
        $d = graph('GET', '/me/messages/' . $id . '?$select=isDraft');
        if (empty($d['isDraft'])) fail(400, 'That message is not a draft.');
        graph('DELETE', '/me/messages/' . $id);
        json_out(['discarded' => true]);

    default:
        fail(400, 'Unknown action.');
}

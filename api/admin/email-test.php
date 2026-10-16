<?php
// ================================================================
//  CANAOPTICALCLINIC — api/admin/email-test.php
//  Admin-only email diagnostics. Open it in the browser while signed in
//  as an admin:  https://<site>/api/admin/email-test.php
//  Shows which mail settings this server is actually using (password
//  hidden), whether each SMTP route connects and logs in, and — with
//  ?send=1 — sends one real test email to the signed-in admin's address.
//  OTP send failures are otherwise only written to the server error log,
//  so this is the quickest way to see the exact reason on the live host.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';
require_once '../../config/smtp.php';
require_once __DIR__ . '/../../vendor/autoload.php';

startSession();
if (($_SESSION['role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Sign in as an admin to run the email test.'], 403);
}
@set_time_limit(90);

$out = [
    'success'  => true,
    'settings' => [
        'SMTP_HOST'      => SMTP_HOST,
        'SMTP_PORT'      => SMTP_PORT,
        'SMTP_USERNAME'  => SMTP_USERNAME,
        'SMTP_PASSWORD'  => SMTP_PASSWORD ? '(set, ' . strlen(SMTP_PASSWORD) . ' chars)' : '(EMPTY)',
        'SMTP_FROM'      => SMTP_FROM,
        'BREVO_API_KEY'  => BREVO_API_KEY ? '(set)' : '(not set)',
        'php'            => PHP_VERSION,
        'openssl'        => extension_loaded('openssl') ? 'loaded' : 'MISSING',
        'max_execution_time' => ini_get('max_execution_time'),
    ],
    'dns'    => ['ipv4' => gethostbyname(SMTP_HOST)],
    'routes' => [],
];

// Brevo (used first whenever BREVO_API_KEY is set): is the key accepted,
// how many emails are left on the plan, and is SMTP_FROM a verified sender?
if (BREVO_API_KEY) {
    $brevo = function (string $path) {
        $ch = curl_init('https://api.brevo.com/v3' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['api-key: ' . BREVO_API_KEY, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        return [$code, $err, json_decode((string)$body, true) ?: $body];
    };
    [$code, $err, $acct] = $brevo('/account');
    if ($err || $code !== 200) {
        $out['brevo'] = ['account' => "FAILED (HTTP {$code}) " . ($err ?: (is_array($acct) ? ($acct['message'] ?? json_encode($acct)) : $acct))];
    } else {
        $plans = array_map(fn($p) => ($p['type'] ?? '?') . ': ' . ($p['credits'] ?? '?') . ' ' . ($p['creditsType'] ?? 'credits') . ' left', $acct['plan'] ?? []);
        $out['brevo'] = ['account' => 'OK — ' . ($acct['email'] ?? ''), 'plan' => $plans];
    }
    [$code, $err, $senders] = $brevo('/senders');
    if (!$err && $code === 200 && is_array($senders)) {
        $list = array_map(fn($s) => ($s['email'] ?? '?') . (!empty($s['active']) ? ' (verified)' : ' (NOT verified)'), $senders['senders'] ?? []);
        $match = array_filter($senders['senders'] ?? [], fn($s) => strcasecmp($s['email'] ?? '', SMTP_FROM) === 0 && !empty($s['active']));
        $out['brevo']['senders'] = $list;
        $out['brevo']['SMTP_FROM_is_verified_sender'] = $match ? 'YES' : 'NO — ' . SMTP_FROM . ' must be added and verified in Brevo → Senders';
    }
}

$ipv4 = $out['dns']['ipv4'] !== SMTP_HOST ? $out['dns']['ipv4'] : null;
foreach ([587, 465] as $port) {
    foreach (array_filter([$ipv4, SMTP_HOST]) as $host) {
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = $host; $m->Port = $port; $m->Timeout = 6;
        $m->SMTPAuth = true; $m->Username = SMTP_USERNAME; $m->Password = SMTP_PASSWORD;
        $m->SMTPSecure = $port === 465 ? 'ssl' : 'tls';
        $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $debug = '';
        $m->SMTPDebug = 2;
        // Keep only what the SERVER replied — client lines include the
        // (base64) login credentials and must never be shown.
        $m->Debugoutput = function ($str) use (&$debug) {
            if (str_starts_with(ltrim($str), 'SERVER -> CLIENT:')) $debug .= trim(substr(ltrim($str), 17)) . "\n";
        };
        $t = microtime(true);
        try {
            $ok = $m->smtpConnect();
            $res = $ok ? 'OK — connected and logged in' : 'FAILED';
            $m->smtpClose();
        } catch (\Throwable $e) {
            $res = 'FAILED — ' . $e->getMessage();
        }
        $out['routes'][] = [
            'route'   => "{$host}:{$port}",
            'result'  => $res,
            'seconds' => round(microtime(true) - $t, 2),
            // Last few server replies — shows e.g. "535 Username and Password not accepted".
            'server_said' => implode(' | ', array_slice(array_filter(array_map('trim', explode("\n", $debug))), -4)),
        ];
    }
}

if (!empty($_GET['send'])) {
    $pdo = getDB();
    $q = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $q->execute([(int)$_SESSION['user_id']]);
    $to = $q->fetchColumn();
    $t = microtime(true);
    try {
        sendEmail($to, '', 'Cana Optical Clinic — email test', '<p>This is a test email from the Cana Optical Clinic system. Email sending works.</p>', 'This is a test email from the Cana Optical Clinic system. Email sending works.');
        $out['test_send'] = "SENT to {$to} in " . round(microtime(true) - $t, 2) . 's';
    } catch (\Throwable $e) {
        $out['test_send'] = 'FAILED — ' . $e->getMessage();
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

<?php
// ================================================================
//  CANAOPTICALCLINIC — api/helpers.php
//  Shared utilities for all API endpoints.
// ================================================================

// __DIR__-relative (not a plain relative path) so this resolves correctly
// no matter which script required helpers.php in the first place — needed
// by _emailPatientNotice() below (sendEmail()/systemEmailBody()).
require_once __DIR__ . '/../config/smtp.php';

// The clinic operates in the Philippines — without this, PHP falls back to
// UTC (the default on Railway's containers), so timestamps shown in the app
// end up 8 hours behind the actual local time.
date_default_timezone_set('Asia/Manila');

// ── Default registration Terms & Conditions ────────────────────────
// Served whenever clinic_settings.terms_content is NULL (fresh install,
// not yet migrated, or admin hasn't customized it) so the registration
// page never shows blank legal text. Convention parsed by the shared
// renderTermsMarkdown() (db.js): "## " = heading, blank line = new
// paragraph, "- " = bullet list item. Keep this in sync with the
// content an admin would see pre-filled in Settings > Terms & Conditions.
const DEFAULT_TERMS_MD = <<<'TERMS'
## 1. Acceptance of Terms
By creating a patient account with Cana Optical Clinic ("the Clinic"), you agree to be bound by these Terms & Conditions. If you do not agree, please do not proceed with registration.

## 2. Account Registration
You must provide accurate, current, and complete information during registration.

> You are responsible for keeping your password confidential and for all activity under your account.

Notify the Clinic immediately if you suspect unauthorized use.

## 3. Your Patient QR Code
Upon registration, a unique QR code is generated and linked to your patient record. Present this QR code at the clinic for fast, accurate check-in.

> Do not share your QR code with anyone else, since it provides access to your health information.

## 4. Appointments
Appointment requests submitted through this system are subject to review and approval by clinic staff. The Clinic reserves the right to reschedule or decline requests when necessary.

## 5. Use of the Platform
You agree to use this system only for legitimate healthcare purposes related to your own care. Misuse, including attempting to access another patient's records or interfering with the system's normal operation, may result in account suspension.

## 6. Changes to these Terms
The Clinic may update these Terms & Conditions from time to time. Continued use of your account after changes are posted constitutes acceptance of the revised terms.
TERMS;

// ── Default registration Data Privacy Act Notice ───────────────────
// Served whenever clinic_settings.privacy_content is NULL. Split out of
// DEFAULT_TERMS_MD into its own document/checkbox — a Data Privacy Act
// consent bundled inside a generic "I agree to the Terms" checkbox didn't
// read as the patient having actually consented to it specifically, which
// is the whole point of calling it out under RA 10173. Keep in sync with
// auth.js's DEFAULT_PRIVACY_MD.
const DEFAULT_PRIVACY_MD = <<<'PRIVACY'
## 1. Overview
This Data Privacy Act Notice explains how Cana Optical Clinic ("the Clinic") collects, uses, stores, and protects your personal and sensitive personal information, in compliance with the Data Privacy Act of 2012 (Republic Act No. 10173) and its Implementing Rules and Regulations.

## 2. Information We Collect and Why
The Clinic collects the personal and sensitive personal information you provide during registration (e.g. name, date of birth, address, contact details) and through your subsequent care (e.g. examination results, diagnoses, prescriptions). This information is collected and processed solely for:
- Creating and maintaining your patient record
- Scheduling and managing appointments
- Providing optical examination, diagnosis, and treatment
- Generating your patient identification QR code
- Communicating with you regarding your account, appointments, or care
- Complying with legal and regulatory requirements

## 3. Storage and Security
Your data is stored on secured servers with access restricted to authorized clinic personnel (admin, staff, and your attending doctor) who need it to perform their duties. We apply reasonable organizational, physical, and technical safeguards to protect your information against unauthorized access, alteration, disclosure, or destruction.

## 4. Data Sharing
> The Clinic does not sell or rent your personal information.

Your data may only be shared with third parties when required by law, when necessary to provide your care (e.g. referrals), or with your explicit consent.

## 5. Data Retention
Your personal and health records are retained for as long as your account is active, and for the period required by applicable healthcare record-keeping regulations afterward, after which they are securely disposed of.

## 6. Your Rights as a Data Subject
Under the Data Privacy Act, you have the right to:
- Be informed of how your data is collected and processed
- Access the personal data the Clinic holds about you
- Request correction of inaccurate or outdated data
- Object to or withdraw consent for processing, subject to legal or contractual restrictions
- Request deletion of your data, where applicable
- File a complaint with the National Privacy Commission (NPC)

Account deletion is handled by request instead of happening instantly. You can send a request from Settings > My Profile, and clinic staff will review it. This is because your examination, diagnosis, and prescription records are health records the Clinic must keep for the period required by healthcare record-keeping regulations, and the review confirms that the request really comes from you. Your account stays as it is until staff act on the request, and you can cancel it any time before then. Once it is processed, your account is closed and your records are kept only for the required period, then securely disposed of.

> To exercise any of these rights, please contact the clinic directly using the contact details on our website.

## 7. Consent and Changes to this Notice
> By checking "I agree" and completing registration, you acknowledge that you have read and understood this notice, and you consent to the collection, use, storage, and processing of your personal and sensitive personal information as described above, for the purpose of receiving care from Cana Optical Clinic, and you are entrusting your credentials and personal information to the Clinic on that basis.

The Clinic may update this notice from time to time; continued use of your account after changes are posted constitutes acceptance of the revised notice.
PRIVACY;

// ── Default booking-wizard Appointment Policy ──────────────────────
// Served whenever clinic_settings.appointment_policy_content is NULL.
// Same renderTermsMarkdown() convention as DEFAULT_TERMS_MD above. Section 4
// uses "> " so it keeps rendering as the original amber callout box instead
// of a plain paragraph.
// A function rather than a const so section 4 always states the *actual*
// configured reminder times (Clinic Settings → Scheduling Rules) instead of
// hardcoded ones that could silently drift out of sync with the cron.
// Section 2 states the live Max Upcoming Appointments Per Patient setting;
// apptPolicyUpcomingPhrase() is also what settings.php matches to keep a
// saved copy of the policy in sync when that number changes.
function apptPolicyUpcomingPhrase(int $maxOpen): string {
    return $maxOpen <= 1 ? 'one upcoming appointment' : "{$maxOpen} upcoming appointments";
}
function defaultApptPolicyMd(string $reminderTime, string $followupTime = '12:00 PM', int $maxOpen = 1): string {
    $upcoming = apptPolicyUpcomingPhrase($maxOpen);
    // "> " lines render as the amber callout box — used for the key rule
    // of each section that patients most need to notice.
    return <<<POLICY
## 1. Appointment Requests
> Appointment requests submitted through this system are subject to review and approval by clinic staff.

The clinic reserves the right to reschedule or decline requests when necessary.

## 2. Upcoming Appointment Limit
> You can only have {$upcoming} booked online at a time.

You can book another once an appointment is completed, cancelled, or disapproved, or its date has passed. To change the date or time, use Request Reschedule on your current appointment instead of booking a new one. Appointments the clinic books for you, such as follow-ups, don't count toward this limit.

## 3. Cancellations
If you can no longer make it to your appointment, please cancel as early as possible. This keeps the slot open for someone else who may need it.

## 4. Repeated No-Shows
> If you miss multiple approved appointments without cancelling, online booking may be temporarily restricted for your account.

In that case, please contact the clinic directly by phone or in person to schedule your next visit.

## 5. Appointment Reminders and Confirmation
> For approved appointments, we send a reminder at {$reminderTime} the day before your visit, and another at {$followupTime} if you haven't confirmed yet. Please confirm that you'll be attending so the clinic can prepare for your visit. If you can no longer make it, please cancel instead so the slot can go to another patient.

## 6. Arrival Time
> Please arrive 5 to 10 minutes before your scheduled appointment time.

This gives the clinic time to check you in so your consultation can start on time.
POLICY;
}

// The policy as it read before section 2 (Upcoming Appointment Limit) was
// added. A saved copy that is still exactly this — saved from Terms &
// Policies without edits — counts as "not customized" and is served as the
// current default instead (settings.php), so the new section shows up.
function legacyApptPolicyMd(string $reminderTime, string $followupTime): string {
    return <<<POLICY
## 1. Appointment Requests
Appointment requests submitted through this system are subject to review and approval by clinic staff. The clinic reserves the right to reschedule or decline requests when necessary.

## 2. Cancellations
If you can no longer make it to your appointment, please cancel as early as possible. This keeps the slot open for someone else who may need it.

## 3. Repeated No-Shows
If you miss multiple approved appointments without cancelling, online booking may be temporarily restricted for your account. In that case, please contact the clinic directly by phone or in person to schedule your next visit.

## 4. Appointment Reminders and Confirmation
> For approved appointments, we send a reminder at {$reminderTime} the day before your visit, and another at {$followupTime} if you haven't confirmed yet. Please confirm that you'll be attending so the clinic can prepare for your visit. If you can no longer make it, please cancel instead so the slot can go to another patient.

## 5. Arrival Time
Please arrive 5 to 10 minutes before your scheduled appointment time. This gives the clinic time to check you in so your consultation can start on time.
POLICY;
}

// Middle initial as displayed on formal names/documents ("Juan D. Dela Cruz") —
// the standard PH convention — leading space included so callers can just
// concatenate it between first and last name.
// Appointments store the patient's name as it was when booked
// (appointments.patient_name) — after a rename, bring every one of that
// patient's appointments up to date, in the same "First M. Last" format the
// booking flow uses. Returns how many appointments changed.
// ── Patient source ───────────────────────────────────────────────
// How a patient came to the clinic, shown as a badge on Patient Records
// (replacing the old Active/Inactive status): 'online' (self-registered),
// 'walk-in' (added by clinic staff), 'transferred' (migrated from another
// optical clinic), 'referral'. source_note holds the previous clinic's name
// or who referred them. Columns added by database/patient_source.sql —
// until that has run, callers just skip them (patientSourceColumns()).
const PATIENT_SOURCES = ['online', 'walk-in', 'transferred', 'referral'];
function patientSourceColumns(PDO $pdo): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)$pdo->query("SHOW COLUMNS FROM patients LIKE 'source'")->fetch(); }
        catch (PDOException $e) { $has = false; }
    }
    return $has;
}
function cleanPatientSource($v, string $default = 'walk-in'): string {
    $v = strtolower(trim((string)$v));
    return in_array($v, PATIENT_SOURCES, true) ? $v : $default;
}

function syncPatientNameEverywhere(PDO $pdo, string $patientId): int {
    if ($patientId === '') return 0;
    $s = $pdo->prepare('SELECT first_name, middle_name, last_name FROM patients WHERE id = ? LIMIT 1');
    $s->execute([$patientId]);
    $p = $s->fetch();
    if (!$p) return 0;
    $name = trim($p['first_name'] . _mi($p['middle_name'] ?? '') . ' ' . $p['last_name']);
    $u = $pdo->prepare('UPDATE appointments SET patient_name = ? WHERE patient_id = ? AND (patient_name IS NULL OR patient_name <> ?)');
    $u->execute([$name, $patientId, $name]);
    return $u->rowCount();
}

function _mi(?string $middleName): string {
    $middleName = trim((string)$middleName);
    return $middleName !== '' ? ' ' . mb_strtoupper(mb_substr($middleName, 0, 1)) . '.' : '';
}

// ── Password strength policy ───────────────────────────────────────
// Mirrors the client-side checklist (auth.js's PW_POLICY_RULES) so a direct
// API call can't bypass what the UI already promises. Returns an error
// message string when the password fails, or null when it passes.
function validatePasswordPolicy(string $password): ?string {
    if (strlen($password) < 8)        return 'Password must be at least 8 characters.';
    if (!preg_match('/[a-z]/', $password)) return 'Password must include at least one lowercase letter.';
    if (!preg_match('/[A-Z]/', $password)) return 'Password must include at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) return 'Password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'Password must include at least one special character.';
    return null;
}

// ── Contact number / address validity ──────────────────────────────
// Mirrors isValidContact()/looksLikeAddress() in auth.js so a direct API
// call can't bypass what the UI already enforces. Callers only invoke
// these when a value was actually submitted — contact/address stay
// optional wherever they already were; this only rejects a BAD value,
// never a missing one.
function isValidContact(string $contact): bool {
    // Hyphens stripped first — the Edit User field (User Management)
    // allows them (e.g. "0921-245-2834"), and some existing stored numbers
    // predate that field's own digit-only stripping. Either format
    // validates the same way once hyphens are out of the count.
    return (bool)preg_match('/^\d{11}$/', str_replace('-', '', trim($contact)));
}

// No geocoding involved (that's the only way to truly verify an address
// exists, e.g. Google's Address Validation API — not something this app
// has a key for) — just the same lightweight fallback most address-
// validation guides recommend absent that: an allowed-character set plus
// a minimum length, tuned loose enough that a genuinely short-but-real
// address (e.g. "Purok 3, Sto. Tomas") never gets rejected.
function looksLikeAddress(string $addr): bool {
    $a = trim($addr);
    if (mb_strlen($a) < 8) return false;
    if (!preg_match('/[a-zA-Z]/', $a)) return false;
    if (preg_match('/^(.)\1+$/', preg_replace('/\s+/', '', $a))) return false;
    // Letters (incl. accented, e.g. "Biñan"), digits, and the punctuation
    // that actually shows up in a real PH address (. , ' # - / &) — reject
    // anything built from characters an address would never contain.
    if (!preg_match('/^[\p{L}\p{N}\s.,#\'\-\/&]+$/u', $a)) return false;
    return true;
}

// ── Password reuse prevention ──────────────────────────────────────
// Standard practice (a new password must differ from recent past ones,
// not just the current one) backed by the `password_history` table.
const PASSWORD_HISTORY_LIMIT = 5;

// True if $newPassword matches the user's current password or any of
// their last PASSWORD_HISTORY_LIMIT passwords.
function passwordWasUsedBefore(PDO $pdo, int $userId, string $newPassword): bool {
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $current = $stmt->fetchColumn();
    if ($current && password_verify($newPassword, $current)) return true;

    try {
        $stmt = $pdo->prepare(
            'SELECT password_hash FROM password_history
              WHERE users_id = ? ORDER BY created_at DESC LIMIT ' . PASSWORD_HISTORY_LIMIT
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldHash) {
            if (password_verify($newPassword, $oldHash)) return true;
        }
    } catch (PDOException $e) { /* table not yet migrated — skip history check */ }

    return false;
}

// Archives the password hash being replaced, then prunes anything past
// PASSWORD_HISTORY_LIMIT so the table doesn't grow unbounded per user.
function recordPasswordHistory(PDO $pdo, int $userId, string $oldHash): void {
    try {
        $pdo->prepare('INSERT INTO password_history (users_id, password_hash) VALUES (?, ?)')
            ->execute([$userId, $oldHash]);
        $pdo->prepare(
            'DELETE FROM password_history WHERE users_id = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM password_history WHERE users_id = ?
                    ORDER BY created_at DESC LIMIT ' . PASSWORD_HISTORY_LIMIT . '
                ) keep
            )'
        )->execute([$userId, $userId]);
    } catch (PDOException $e) { /* table not yet migrated — non-critical */ }
}

// ── IP-based rate limiting ────────────────────────────────────────
// Reads hits from the `rate_limits` table keyed by IP + endpoint.
// Fails open if the table doesn't exist yet so no legitimate request
// is ever blocked by a missing migration.
function clientIp(): string {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd) {
        $ip = trim(explode(',', $fwd)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rateLimit(string $endpoint, int $maxHits, int $windowSeconds): void {
    try {
        $pdo         = getDB();
        $ip          = clientIp();
        $now         = time();
        $windowStart = $now - $windowSeconds;

        // Probabilistic GC — cleans rows older than 24 h, 1-in-10 requests
        if (mt_rand(1, 10) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE created_at < ?')
                ->execute([$now - 86400]);
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND endpoint = ? AND created_at >= ?'
        );
        $stmt->execute([$ip, $endpoint, $windowStart]);

        if ((int)$stmt->fetchColumn() >= $maxHits) {
            header('Retry-After: ' . $windowSeconds);
            jsonResponse([
                'success' => false,
                'message' => 'Too many requests. Please wait a moment and try again.',
            ], 429);
        }

        $pdo->prepare('INSERT INTO rate_limits (ip, endpoint, created_at) VALUES (?, ?, ?)')
            ->execute([$ip, $endpoint, $now]);

    } catch (PDOException) {
        // Fail open — never block users due to a missing table or DB hiccup
    }
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireMethod(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
}

function getBody(): array {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── DB-backed session storage ──────────────────────────────────────
// Railway's container filesystem isn't a reliable place for PHP's
// default file-based sessions (ephemeral/non-shared across restarts
// or replicas), which is why logins "succeed" but later requests
// can't see the session. Storing sessions in MySQL makes them as
// durable as everything else in the app.
class DbSessionHandler implements SessionHandlerInterface {
    public function __construct(private PDO $pdo) {}

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    // Ended sessions are KEPT as a record (signed_out_at set, data wiped)
    // instead of deleted — see endSessions(). A signed-out row reads as
    // empty and can never be written back to life, even if a browser still
    // holds its old cookie; logging in again always gets a fresh session id
    // (session_regenerate_id() in completeLogin()).
    public function read(string $id): string {
        $stmt = $this->pdo->prepare('SELECT data, signed_out_at FROM sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return ($row && $row['signed_out_at'] === null) ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, data, last_access) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = IF(signed_out_at IS NULL, VALUES(data), \'\'),
                                     last_access = IF(signed_out_at IS NULL, VALUES(last_access), last_access)'
        );
        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool {
        // session_destroy() — the device's own Log Out
        $stmt = $this->pdo->prepare(
            'UPDATE sessions SET data = \'\', signed_out_at = NOW()
              WHERE id = ? AND signed_out_at IS NULL'
        );
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false {
        // "Remember me" logins use a longer cookie lifetime than the default
        // session, but PHP's gc_maxlifetime is a single global ini value —
        // whichever request happens to trigger garbage collection sets it.
        // Floor it at 30 days so a short-lived (non "remember me") session's
        // cleanup pass never prunes another user's still-valid remembered one.
        $threshold = max($max_lifetime, 30 * 86400);
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_access < ?');
        $stmt->execute([time() - $threshold]);
        return $stmt->rowCount();
    }
}

// Like a Google account: a signed-in browser stays signed in for 30 days
// from its LAST activity (not from login) — every request from a signed-in
// session renews the cookie (see startSession()), and the DB row is only
// garbage-collected after 30 idle days (DbSessionHandler::gc()). Applies to
// every login, whether or not "Remember me" was ticked (that now just
// remembers the email on the login form). Logging out, removing the device
// from Sessions & Sign-in, or a password change still ends it right away.
const SESSION_DAYS = 30;

// $days controls both the session cookie lifetime and how long the DB-backed
// session row survives.
function startSession(int $days = SESSION_DAYS): void {
    if (session_status() === PHP_SESSION_NONE) {
        $lifetimeSeconds = $days * 86400;
        ini_set('session.gc_maxlifetime', (string)$lifetimeSeconds);

        // Railway terminates TLS at its edge proxy and forwards plain HTTP,
        // so $_SERVER['HTTPS'] is never set — check the forwarded proto too,
        // otherwise the session cookie never gets marked Secure in production.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => $lifetimeSeconds,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $isHttps,
        ]);

        try {
            session_set_save_handler(new DbSessionHandler(getDB()), true);
        } catch (Throwable $e) {
            // DB unreachable — fall back to PHP's default file handler
            // rather than fatal-erroring the whole request. Railway's own
            // container filesystem doesn't survive a restart and isn't
            // shared across replicas, so a session that falls back here
            // even once can look randomly signed-out later with nothing
            // in the sessions table to explain why. Logged specifically
            // because that failure mode was previously invisible — this
            // is the one place to confirm from Railway's log viewer
            // whether it's actually happening, and how often.
            error_log('[startSession] DB save handler unavailable, falling back to file sessions: ' . $e->getMessage());
        }

        // Grab this BEFORE session_start() runs — session_start() consumes/
        // rewrites $_COOKIE's session entry as part of resuming, so it has
        // to be captured here to still mean "did the browser send one" below.
        $hadCookie = !empty($_COOKIE[session_name()]);

        session_start();

        // The one signal none of the logging so far could actually catch:
        // the browser sent a session cookie (it believes it's still logged
        // in) but PHP came back with nothing for it — meaning the server
        // lost track of a session the client never gave up on itself. A
        // request with NO cookie at all is completely normal (a public
        // page, a fresh visitor, someone who already properly logged out)
        // and logged nowhere near this often — only the "should be valid
        // but isn't" case is worth a line, right at the moment it happens
        // rather than reconstructed after the fact from a stale row.
        if ($hadCookie && empty($_SESSION)) {
            $uri = $_SERVER['REQUEST_URI'] ?? '(unknown)';
            error_log("[startSession] browser sent a session cookie but PHP found no session data for it — request: {$uri}");
        }

        // Rolling expiry: PHP only sends the session cookie when a session is
        // first created, so on its own the cookie would still die $days after
        // LOGIN however active the person is. Re-issue it on each signed-in
        // request so it's always $days from the last activity instead.
        if ($hadCookie && !empty($_SESSION['user_id']) && !headers_sent()) {
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $lifetimeSeconds,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $isHttps,
            ]);
        }
    }
}

// ── Active Sessions (multi-device sign-in, not one-device-at-a-time) ──
// Every login is tracked, not restricted — the same account can stay
// signed in on several devices/browsers at once, same as Facebook/Google.
// What's new is visibility and control: a user can see every device
// currently signed in to their account (Settings > Active Sessions) and
// revoke any one of them individually; a password change auto-revokes
// every OTHER session on the account as a security measure.

// A rough, human-readable device description parsed from the browser's
// User-Agent string — not meant to be precise, just enough for someone to
// recognize "that's my phone" vs "that's not me" at a glance. Split into
// os/browser/type (not just the combined label) so listSessions() can
// group sessions by device the way Google's "Your devices" page does ("2
// sessions on Windows computer(s)"), not just list them flat.
function parseDeviceParts(string $ua): array {
    $ua = trim($ua);
    if ($ua === '') return ['os' => 'Unknown device', 'browser' => '', 'type' => 'desktop', 'label' => 'Unknown device'];

    $os   = 'Unknown OS';
    $type = 'desktop';
    if (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (preg_match('/iPad/i', $ua)) {
        // iPad — checked before the generic iPhone/iPod branch so it isn't
        // swallowed by that pattern and mislabeled as a phone.
        $os = 'iOS'; $type = 'tablet';
    } elseif (preg_match('/iPhone|iPod/i', $ua)) {
        $os = 'iOS'; $type = 'phone';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
        // Google's own UA convention: an Android phone's UA includes the
        // "Mobile" token, a tablet's doesn't — there's no separate
        // "Tablet" marker to check for instead.
        $type = (stripos($ua, 'Mobile') !== false) ? 'phone' : 'tablet';
    } elseif (stripos($ua, 'Mac OS X') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    $browser = 'Unknown Browser';
    // Order matters — Edge/Opera/Chrome all include "Chrome" in their own
    // UA string (chained-engine spoofing for site compatibility), and
    // Chrome itself includes "Safari"; check the more specific tokens first.
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'EdgA/') !== false || stripos($ua, 'EdgiOS/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false)  $browser = 'Opera';
    elseif (stripos($ua, 'Firefox') !== false || stripos($ua, 'FxiOS') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'CriOS') !== false || stripos($ua, 'Chrome') !== false)  $browser = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false)                       $browser = 'Safari';

    return ['os' => $os, 'browser' => $browser, 'type' => $type, 'label' => "{$browser} on {$os}"];
}

// A Google-style device name for the Sessions & Sign-in list — "Samsung
// Galaxy S23 Ultra", "Google Pixel 7", "iPhone", "Windows 11 PC" — rather
// than just "Chrome on Android". $model / $platformVersion come from the
// browser's User-Agent Client Hints (api/auth/device-info.php), since
// modern Chrome on Android hides the model in its plain UA ("Android 10; K").
// Falls back to whatever the UA itself says, then to a generic name.
function deviceDisplayName(string $ua, ?string $model, ?string $platformVersion, string $os, string $type): string {
    $model = trim((string)$model);
    // Older Android UAs still carry the model: "...; Android 13; SM-A546E Build/..."
    if ($model === '' && preg_match('/Android [\d.]+;\s*([^;)]+?)(?:\s+Build\/|\))/i', $ua, $m) && strtoupper(trim($m[1])) !== 'K') {
        $model = trim($m[1]);
    }

    if ($os === 'iOS')   return $type === 'tablet' ? 'iPad' : 'iPhone';
    if ($os === 'macOS') return 'Mac';
    if ($os === 'Windows') {
        // Windows 11 reports platformVersion 13.0.0 or higher; 10 reports 1–10.x.
        $major = (int)explode('.', (string)$platformVersion)[0];
        return $platformVersion !== null && $platformVersion !== ''
            ? ($major >= 13 ? 'Windows 11 PC' : 'Windows 10 PC')
            : 'Windows PC';
    }
    if (stripos($ua, 'CrOS') !== false) return 'Chromebook';
    if ($os === 'Linux') return 'Linux PC';

    if ($os === 'Android') {
        if ($model === '') return $type === 'tablet' ? 'Android Tablet' : 'Android Phone';

        // Popular Samsung model codes → marketing names (Philippine market);
        // anything not listed still reads "Samsung Galaxy … (SM-XXXX)".
        $samsung = [
            'SM-S928' => 'Galaxy S24 Ultra', 'SM-S926' => 'Galaxy S24+', 'SM-S921' => 'Galaxy S24',
            'SM-S918' => 'Galaxy S23 Ultra', 'SM-S916' => 'Galaxy S23+', 'SM-S911' => 'Galaxy S23', 'SM-S711' => 'Galaxy S23 FE',
            'SM-S908' => 'Galaxy S22 Ultra', 'SM-S906' => 'Galaxy S22+', 'SM-S901' => 'Galaxy S22',
            'SM-G998' => 'Galaxy S21 Ultra', 'SM-G991' => 'Galaxy S21', 'SM-G990' => 'Galaxy S21 FE',
            'SM-F946' => 'Galaxy Z Fold5', 'SM-F731' => 'Galaxy Z Flip5', 'SM-F956' => 'Galaxy Z Fold6', 'SM-F741' => 'Galaxy Z Flip6',
            'SM-A556' => 'Galaxy A55', 'SM-A546' => 'Galaxy A54', 'SM-A536' => 'Galaxy A53', 'SM-A356' => 'Galaxy A35',
            'SM-A346' => 'Galaxy A34', 'SM-A256' => 'Galaxy A25', 'SM-A255' => 'Galaxy A25', 'SM-A155' => 'Galaxy A15',
            'SM-A156' => 'Galaxy A15 5G', 'SM-A145' => 'Galaxy A14', 'SM-A057' => 'Galaxy A05s', 'SM-A055' => 'Galaxy A05',
            'SM-A245' => 'Galaxy A24', 'SM-A235' => 'Galaxy A23', 'SM-A135' => 'Galaxy A13', 'SM-A045' => 'Galaxy A04',
            'SM-M156' => 'Galaxy M15', 'SM-X110' => 'Galaxy Tab A9', 'SM-X210' => 'Galaxy Tab A9+', 'SM-X200' => 'Galaxy Tab A8',
        ];
        if (stripos($model, 'SM-') === 0) {
            $code = strtoupper(substr($model, 0, 7));
            if (isset($samsung[$code])) return 'Samsung ' . $samsung[$code];
            $series = ['S' => 'Galaxy S', 'G' => 'Galaxy', 'A' => 'Galaxy A', 'M' => 'Galaxy M', 'N' => 'Galaxy Note',
                       'F' => 'Galaxy Z', 'E' => 'Galaxy F', 'X' => 'Galaxy Tab', 'T' => 'Galaxy Tab', 'P' => 'Galaxy Tab'];
            $s = $series[strtoupper($model[3] ?? '')] ?? 'Galaxy';
            return "Samsung {$s} ({$model})";
        }
        // Model hints that already read like a name ("Pixel 8", "Redmi Note 13").
        $brands = [
            '/^Pixel/i'                 => 'Google ',
            '/^(Redmi|POCO|Mi )/i'      => 'Xiaomi ',
            '/^(CPH|OPPO)/i'            => 'OPPO ',
            '/^RMX/i'                   => 'realme ',
            '/^(V2\d{3}|vivo)/i'        => 'vivo ',
            '/^(TECNO|KG|KI|CK|BG|LH)\d/i' => 'TECNO ',
            '/^Infinix|^X6\d{2}/i'      => 'Infinix ',
            '/^(moto|motorola)/i'       => 'Motorola ',
            '/^Nokia/i'                 => '',
            '/^(HUAWEI|[A-Z]{3}-[A-Z]{1,2}\d)/' => 'Huawei ',
            '/^itel/i'                  => '',
            '/^(\d{4,}[A-Z]{1,3}\d*[A-Z]?)$/' => 'Xiaomi ',   // e.g. 2201117TG, 23129RAA4G
        ];
        foreach ($brands as $re => $prefix) {
            if (preg_match($re, $model)) {
                // Don't double the brand ("OPPO OPPO A78").
                return (stripos($model, trim($prefix)) === 0 || $prefix === '') ? $model : $prefix . $model;
            }
        }
        return $model;
    }
    return $type === 'phone' ? 'Phone' : ($type === 'tablet' ? 'Tablet' : 'Computer');
}

// Thin wrapper kept for any caller that just wants the combined label.
// ── New-device OTP lockout (login_device_otps) ────────────────────
// After 10 wrong codes across resends (verify-login-device.php), the
// account's device-verification row is turned into a lock: its otp is
// replaced with a non-numeric marker no 6-digit entry can ever match, and
// expires_at is pushed an hour out. login.php, resend and verify all
// refuse while that row is live — the same 1-hour block forgot-password
// applies (verify-otp.php), without needing an extra column.
const LDO_BLOCK_MINUTES = 60;
const LDO_LOCK_MARKER   = 'LOCKED';

function ldoBlockRow(PDO $pdo, int $rowId): void {
    $pdo->prepare(
        'UPDATE login_device_otps
            SET otp = ?, attempts = 5, total_attempts = 10,
                expires_at = NOW() + INTERVAL ' . LDO_BLOCK_MINUTES . ' MINUTE
          WHERE id = ?'
    )->execute([LDO_LOCK_MARKER, $rowId]);
}

function ldoIsBlocked(array $row): bool {
    return ($row['otp'] ?? '') === LDO_LOCK_MARKER;
}

// $row must include secs_left (TIMESTAMPDIFF against the DB's own NOW(),
// so PHP/MySQL timezone differences can't skew it).
function ldoBlockedMessage(array $row): string {
    $mins = max(1, (int)ceil(((int)($row['secs_left'] ?? LDO_BLOCK_MINUTES * 60)) / 60));
    return 'Too many failed verification attempts. For your security, sign-in from a new device is locked. Please try again in '
        . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
}

function parseDeviceLabel(string $ua): string {
    return parseDeviceParts($ua)['label'];
}

// Called right after a successful login (password or the auto-login
// right after verifying a registration OTP) — tags the *current* PHP
// session's row with who it belongs to, plus a device description/IP for
// display. INSERT ... ON DUPLICATE KEY UPDATE rather than a plain UPDATE:
// DbSessionHandler::write() only actually persists the session row at
// request shutdown, so at this point in the request the row may not exist
// in the DB yet — this creates it if needed (an empty `data` placeholder,
// immediately overwritten by the real write() at request end) or just
// updates the ownership columns if it's already there.
// "Known device" check, shared by tagSessionOwner() below (decides
// whether a just-completed login is worth a "New Sign-in Detected"
// notification) and login.php (decides whether a login gets to complete
// at all, or has to clear an emailed OTP first — see checkKnownDevice()'s
// own call site there). Two separate reasons a device counts as known:
//   1. Same User-Agent already among this account's OTHER sessions —
//      this is the same browser/device signing in again (a fresh login
//      after the old session expired or was signed out, "remember me"
//      renewing, etc.), not a new device.
//   2. No session exists at all yet — this is the account's very
//      first-ever sign-in. There's nothing yet for it to be "new"
//      relative to, and nothing for the owner to have missed or need to
//      review/verify — Google/Facebook don't gate an account's first
//      login on anything extra either, only an additional device
//      joining one that's already established.
// Deliberately NOT the basis for auto-revoking anything: a User-Agent
// match can't tell "the same browser logging in again" apart from "a
// second tab of the same still-open session." Not a perfect device
// fingerprint (a cleared/GC'd session for that same device reads as
// "new" again), but a reasonable one without adding a separate
// persistent devices table — sessions already survive 30+ days (see
// DbSessionHandler::gc() above), which doubles as this check's own
// trust window.
// $excludeSid: tagSessionOwner() passes the current request's own
// session id so a row it may have already written for itself this same
// request doesn't just match against itself; login.php's pre-login gate
// has no session row yet to worry about, so it omits this entirely.
// ── Device identity ───────────────────────────────────────────────
// Each browser gets a random, long-lived, httponly device cookie the first
// time it completes a login. That token — not the user-agent string, which
// every identical browser shares — is what makes a device "known". It
// works like a Google account's remembered devices: once a device has passed
// the new-device OTP it stays remembered — after Log Out, after being signed
// out from another device, and after a password change/reset. Those end its
// session (email + password needed again), but never ask for the OTP again.
const DEVICE_COOKIE = 'cana_dev';

function deviceToken(bool $create = false): ?string {
    $tok = $_COOKIE[DEVICE_COOKIE] ?? '';
    if (preg_match('/^[a-f0-9]{64}$/', $tok)) return $tok;
    if (!$create) return null;
    $tok = bin2hex(random_bytes(32));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(DEVICE_COOKIE, $tok, [
        'expires'  => time() + 400 * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    $_COOKIE[DEVICE_COOKIE] = $tok;
    return $tok;
}

function checkKnownDevice(PDO $pdo, int $userId, string $ua): array {
    $known     = false;
    $firstEver = false;
    try {
        // Reads `known_devices`, not `sessions` — sessions are deleted on
        // logout (see logout.php), so a device check backed by that table
        // would "forget" a device the moment its owner signed out. Rows are
        // added by rememberKnownDevice() (completeLogin()) and never removed
        // by a sign-out of any kind.
        // "First ever" = the account has genuinely never signed in, not just
        // "no trusted devices on record" — an account that has signed in
        // before (on devices since removed from the table by hand, say) still
        // gets the OTP on a new device instead of being waved through.
        $cnt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM known_devices WHERE users_id = ?) = 0
                AND (SELECT last_login_at IS NULL FROM users WHERE id = ?)'
        );
        $cnt->execute([$userId, $userId]);
        $firstEver = (bool)$cnt->fetchColumn();

        $tok = deviceToken();
        if ($tok) {
            $chk = $pdo->prepare('SELECT 1 FROM known_devices WHERE users_id = ? AND device_token = ? LIMIT 1');
            $chk->execute([$userId, $tok]);
            $known = (bool)$chk->fetchColumn();
        }
        // Legacy rows from before device cookies existed (device_token NULL)
        // still match by user-agent once — rememberKnownDevice() then binds
        // that row to this browser's cookie, so it can't match any other
        // device with the same browser afterwards.
        if (!$known && $ua !== '') {
            $chk = $pdo->prepare('SELECT 1 FROM known_devices WHERE users_id = ? AND device_token IS NULL AND user_agent = ? LIMIT 1');
            $chk->execute([$userId, $ua]);
            $known = (bool)$chk->fetchColumn();
        }
    } catch (PDOException) {
        // Fail open — worst case a login gets treated as "new" when it
        // wasn't (an extra notification, or login.php's OTP gate firing
        // once more than strictly necessary), never the other way round.
    }
    return ['known' => $known, 'firstEver' => $firstEver];
}

// Records (or refreshes) this device as trusted for future logins. Called
// once per completed login (completeLogin() below) — after tagSessionOwner()
// has already made its own checkKnownDevice() call for the "New Sign-in
// Detected" notification, so writing here first would never suppress that
// notification for a genuinely new device.
function rememberKnownDevice(PDO $pdo, int $userId, string $ua): void {
    $tok = deviceToken(true);
    if (!$tok) return;
    try {
        // Bind one legacy (pre-cookie) row for this browser to the cookie
        // instead of leaving it around to keep matching by user-agent.
        if ($ua !== '') {
            $pdo->prepare(
                'UPDATE known_devices SET device_token = ? WHERE users_id = ? AND device_token IS NULL AND user_agent = ? LIMIT 1'
            )->execute([$tok, $userId, $ua]);
        }
        // The device's record: browser, IP, its current session and when it
        // last signed in (last_logout_at is filled by logout.php). Kept after
        // Log Out, so this device only needs email + password next time.
        $pdo->prepare(
            'INSERT INTO known_devices (users_id, user_agent, device_token, ip_address, last_session_id, first_seen_at, last_seen_at, last_login_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW(), last_login_at = NOW(), user_agent = VALUES(user_agent),
                                     ip_address = VALUES(ip_address), last_session_id = VALUES(last_session_id)'
        )->execute([$userId, $ua, $tok, clientIp(), session_id() ?: null]);
    } catch (PDOException) {
        // Non-critical — worst case this device gets re-verified once more
        // than strictly necessary on its next login.
    }
}

function tagSessionOwner(PDO $pdo, int $userId): void {
    $sid = session_id();
    if (!$sid) return;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $ip = clientIp();

    $device              = checkKnownDevice($pdo, $userId, $ua);
    $knownDevice         = $device['known'];
    $isFirstEverSession  = $device['firstEver'];

    // Which device this session belongs to — lets a remote sign-out end all
    // of that device's sessions (endDeviceSessions()).
    $tok = deviceToken(true);
    $stmt = $pdo->prepare(
        'INSERT INTO sessions (id, data, last_access, user_id, user_agent, ip_address, device_token, created_at)
         VALUES (?, \'\', ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), user_agent = VALUES(user_agent),
                                  ip_address = VALUES(ip_address), device_token = VALUES(device_token),
                                  created_at = VALUES(created_at)'
    );
    $stmt->execute([$sid, time(), $userId, $ua, $ip, $tok]);

    if ($knownDevice || $isFirstEverSession) return;

    // A genuinely new device/browser signing in — surface it as a
    // notification so the account owner notices a sign-in they didn't
    // make and can review/revoke it from Active Sessions, same spirit as
    // Google/Facebook's own "new sign-in" alerts. No relatedId: there's no
    // specific record to jump to, just the Active Sessions page itself
    // (see the 'new_login' branch in _notifNavTarget(), router.js).
    $deviceLabel = parseDeviceLabel($ua);
    createNotification($pdo, $userId, 'new_login', 'New Sign-in Detected',
        "A new sign-in to your account was detected on {$deviceLabel} (IP: {$ip}). If this wasn't you, review your sessions in Sessions &amp; Sign-in and change your password."
    );
}

// Everything that happens once a login is actually allowed to succeed —
// shared by login.php (device already known/first-ever, completes
// immediately) and verify-login-device.php (device wasn't known,
// completes only after the emailed OTP is confirmed), so the two paths
// can never drift into returning differently-shaped responses or
// skipping one of these steps. $user is a full row from `users`
// (whatever SELECT * FROM users ... already fetched). Caller is
// expected to have already called startSession() with the right
// lifetime for this login — this only sets the session's own contents.
function completeLogin(PDO $pdo, array $user): array {
    $role   = $user['role'];
    $result = loadUserProfile($pdo, (int)$user['id'], $role);
    if (!$result) {
        return ['success' => false, 'message' => 'User profile not found. Contact support.'];
    }

    $userObj = buildUserObject($role, $result['profile'], $user['email'], $result['days'], (int)$user['id']);

    // Every successful login gets a brand-new session id. Ended sessions are
    // kept as signed-out records and can't be reused (DbSessionHandler), so a
    // browser still holding an old cookie must not land back on that row —
    // and it's the standard defence against session fixation anyway. The
    // old row is left as-is (false = don't delete it).
    if (session_status() === PHP_SESSION_ACTIVE) {
        $oldSid = session_id();
        session_regenerate_id(false);
        // A previously signed-in session on this same browser is superseded
        // by this login — kept as a record, but no longer usable.
        try {
            $pdo->prepare(
                'UPDATE sessions SET data = \'\', signed_out_at = NOW()
                  WHERE id = ? AND user_id IS NOT NULL AND signed_out_at IS NULL'
            )->execute([$oldSid]);
        } catch (PDOException) { /* non-critical */ }
    }

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['profile_id'] = $result['profile']['id'];
    $_SESSION['role']       = $role;

    // Tag this session row with its owner + device/IP so it shows up in
    // that user's own Settings > Active Sessions list.
    tagSessionOwner($pdo, (int)$user['id']);

    // Record this device as trusted going forward. Must run AFTER
    // tagSessionOwner() above — that call makes its own "is this a new
    // device" check for the sign-in notification, and this would
    // overwrite the answer to that if it ran first.
    rememberKnownDevice($pdo, (int)$user['id'], substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));

    // Record this login time (used for the 1-year inactivity check)
    try {
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int)$user['id']]);
    } catch (PDOException $e) { /* non-critical */ }

    // Persist login to activity log
    try {
        $fullName = ($role === 'doctor' ? 'Dr. ' : '') . trim(($result['profile']['first_name'] ?? '') . ' ' . ($result['profile']['last_name'] ?? ''));
        $logId    = 'L' . date('YmdHis') . substr((string)microtime(true), -3);
        $pdo->prepare(
            'INSERT IGNORE INTO activity_log (id, users_id, user_name, role, action, timestamp, type, ip_address)
             VALUES (?,?,?,?,\'Logged in to the system\',NOW(),\'login\',?)'
        )->execute([substr($logId, 0, 20), (int)$user['id'], $fullName, ucfirst($role), clientIp()]);
    } catch (PDOException $e) { /* non-critical */ }

    return ['success' => true, 'role' => $role, 'user' => $userObj];
}

// A short, opaque, non-sensitive handle for a session row — returned to
// the client instead of the real PHP session id. The session id itself is
// a live bearer credential (whoever holds it IS that session, cookie or
// not); listSessions()'s JSON response is exactly the kind of place a
// leak (XSS, a logged response, etc.) would be most damaging, so the raw
// id never leaves the server. revokeSessionByShortId() below re-derives
// this same hash per-row rather than reversing it, which is why lookup is
// a short scan over one user's own (always small) session list, not a
// hash-to-id table.
function _sessionShortId(string $sid): string {
    return substr(hash('sha256', $sid), 0, 16);
}

// Every active session for one account, newest first — the current
// request's own session marked isCurrent so the UI can label it
// "This device" and skip offering it a Log Out button.
function listSessions(PDO $pdo, int $userId): array {
    // Like Google's "Your devices": every device that has signed in to this
    // account at least once (known_devices), whether it's signed in right
    // now or signed out — a normal Log Out keeps the device here (and
    // trusted). Only removing it from another device takes it off this list
    // (removeTrustedDevice()). Older sessions from before device cookies
    // existed are still listed as plain active sessions underneath.
    $currentSid = session_id();
    $myTok      = deviceToken();

    $act = $pdo->prepare(
        'SELECT id, user_agent, device_model, platform_version, ip_address, device_token, created_at, last_access FROM sessions
          WHERE user_id = ? AND signed_out_at IS NULL ORDER BY last_access DESC'
    );
    $act->execute([$userId]);
    $active = $act->fetchAll();
    $activeByTok = [];
    foreach ($active as $r) {
        if (!empty($r['device_token']) && !isset($activeByTok[$r['device_token']])) $activeByTok[$r['device_token']] = $r;
    }

    $dev = $pdo->prepare(
        'SELECT id, user_agent, device_model, platform_version, device_token, ip_address, first_seen_at, last_seen_at, last_login_at, last_logout_at
           FROM known_devices WHERE users_id = ? AND device_token IS NOT NULL'
    );
    $dev->execute([$userId]);

    $out = [];
    foreach ($dev->fetchAll() as $d) {
        $sess  = $activeByTok[$d['device_token']] ?? null;
        $uaStr = $sess['user_agent'] ?? $d['user_agent'] ?? '';
        $parts = parseDeviceParts($uaStr);
        $name  = deviceDisplayName($uaStr,
            $sess['device_model'] ?? $d['device_model'] ?? null,
            $sess['platform_version'] ?? $d['platform_version'] ?? null,
            $parts['os'], $parts['type']);
        $lastActive = $sess && $sess['last_access']
            ? date('Y-m-d H:i:s', (int)$sess['last_access'])
            : ($d['last_logout_at'] ?: $d['last_seen_at']);
        $out[] = [
            'id'         => 'd' . $d['id'],
            'name'       => $name,
            'os'         => $parts['os'],
            'type'       => $parts['type'],
            'browser'    => $parts['browser'],
            'device'     => $parts['label'],
            'ip'         => $sess['ip_address'] ?? $d['ip_address'],
            'createdAt'  => $d['first_seen_at'],
            'lastActive' => $lastActive,
            'status'     => $sess ? 'active' : 'signed_out',
            'isCurrent'  => $myTok !== null && $d['device_token'] === $myTok,
        ];
    }

    // Legacy: active sessions with no device cookie (signed in before this
    // existed) — one row per browser, as before.
    $seen = [];
    foreach ($active as $r) {
        if (!empty($r['device_token'])) continue;
        $ua = $r['user_agent'] ?? '';
        $isCurrent = $r['id'] === $currentSid;
        if (!$isCurrent && $ua !== '' && isset($seen[$ua])) continue;
        if ($ua !== '') $seen[$ua] = true;
        $parts = parseDeviceParts($ua);
        $out[] = [
            'id'         => _sessionShortId($r['id']),
            'name'       => deviceDisplayName($ua, $r['device_model'] ?? null, $r['platform_version'] ?? null, $parts['os'], $parts['type']),
            'os'         => $parts['os'],
            'type'       => $parts['type'],
            'browser'    => $parts['browser'],
            'device'     => $parts['label'],
            'ip'         => $r['ip_address'] ?? null,
            'createdAt'  => $r['created_at'],
            'lastActive' => $r['last_access'] ? date('Y-m-d H:i:s', (int)$r['last_access']) : null,
            'status'     => 'active',
            'isCurrent'  => $isCurrent,
        ];
    }

    // Current device first, then signed-in devices, then most recent
    usort($out, function ($a, $b) {
        if ($a['isCurrent'] !== $b['isCurrent']) return $a['isCurrent'] ? -1 : 1;
        if ($a['status'] !== $b['status']) return $a['status'] === 'active' ? -1 : 1;
        return strcmp((string)$b['lastActive'], (string)$a['lastActive']);
    });
    return $out;
}

// Removes a remembered device from the account — the "Sign Out" / "Remove"
// button on another device's Sessions & Sign-in page. Ends every session
// still open on it AND forgets it as trusted, so its next sign-in needs the
// emailed OTP again (and it drops off the device list). $deviceId is the
// known_devices.id. Returns sessions ended + 1 for the device, or 0 if not found.
function removeTrustedDevice(PDO $pdo, int $userId, int $deviceId): int {
    $d = $pdo->prepare('SELECT device_token FROM known_devices WHERE id = ? AND users_id = ? LIMIT 1');
    $d->execute([$deviceId, $userId]);
    $tok = $d->fetchColumn();
    if ($tok === false) return 0;
    $ended = 0;
    if ($tok) {
        $s = $pdo->prepare('UPDATE sessions SET data = \'\', signed_out_at = NOW()
                             WHERE signed_out_at IS NULL AND user_id = ? AND device_token = ?');
        $s->execute([$userId, $tok]);
        $ended = $s->rowCount();
    }
    $pdo->prepare('DELETE FROM known_devices WHERE id = ? AND users_id = ?')->execute([$deviceId, $userId]);
    return $ended + 1;
}

// Revokes one of $userId's own sessions, identified by the short id
// listSessions() handed the client — scoped to `WHERE user_id = ?`
// throughout, so there is no way to list or revoke a different account's
// session even by guessing/brute-forcing a short id. Returns the number of
// rows actually removed (0 if the short id didn't match anything).
//
// Also sweeps every OTHER session on the account that's the exact same
// browser AND the exact same IP as the one picked — in practice almost
// always just leftover duplicate rows from repeatedly logging back in on
// the same device (listSessions() already hides these from the list via
// its own dedup, but never deletes them — see that function's own
// comment). Deleting them here is safe specifically because this is a
// manual, explicit click on one particular row, not something that
// happens automatically on login — the exact distinction that made
// auto-revoking on a new sign-in unsafe (a still-open second tab could
// get killed with no warning) doesn't apply to an intentional Sign Out
// click. The current session is always excluded, so this can never sign
// the caller themselves out; device+IP matching only runs when the target
// row actually has both (an old row with a blank/unknown one never
// cascades, so it can't sweep unrelated rows that merely share the same
// blankness).
// Signing a device out (from another device's Sessions & Sign-in) ends every
// session still open on that same device — matched by its device cookie
// token. Like a Google account, the device itself stays REMEMBERED in
// known_devices: its next login asks for email + password again, but never
// the new-device OTP. Older sessions without a token have nothing extra to
// end here (revokeSessionByShortId() handles them by browser + IP). The
// caller's own current device is never touched.
function endDeviceSessions(PDO $pdo, int $userId, array $sessionRows): void {
    $myTok = deviceToken();
    foreach ($sessionRows as $row) {
        $tok = $row['device_token'] ?? null;
        if (!$tok || $tok === $myTok) continue;
        try {
            $pdo->prepare('UPDATE sessions SET data = \'\', signed_out_at = NOW() WHERE signed_out_at IS NULL AND user_id = ? AND device_token = ? AND id != ?')
                ->execute([$userId, $tok, session_id()]);
        } catch (PDOException) { /* best effort */ }
    }
}

function revokeSessionByShortId(PDO $pdo, int $userId, string $shortId): int {
    $stmt = $pdo->prepare('SELECT id, user_agent, ip_address, device_token FROM sessions WHERE user_id = ? AND signed_out_at IS NULL');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        if (_sessionShortId($row['id']) !== $shortId) continue;
        $currentSid = session_id();
        endDeviceSessions($pdo, $userId, [$row]);
        // Signed out from another device = no longer trusted: a pre-cookie
        // legacy trust record for this browser is forgotten too, so its next
        // sign-in needs the OTP (same rule as removeTrustedDevice()).
        if (empty($row['device_token']) && !empty($row['user_agent'])) {
            try {
                $pdo->prepare('DELETE FROM known_devices WHERE users_id = ? AND device_token IS NULL AND user_agent = ?')
                    ->execute([$userId, $row['user_agent']]);
            } catch (PDOException) {}
        }
        if (!empty($row['device_token'])) {
            // endDeviceSessions() already ended this device's other
            // sessions by token — only this exact device, never another one
            // that merely shares its browser + IP.
            $del = $pdo->prepare('UPDATE sessions SET data = \'\', signed_out_at = NOW() WHERE signed_out_at IS NULL AND id = ? AND user_id = ?');
            $del->execute([$row['id'], $userId]);
            return max(1, $del->rowCount());
        }
        if ($row['user_agent'] && $row['ip_address']) {
            $del = $pdo->prepare(
                'UPDATE sessions SET data = \'\', signed_out_at = NOW() WHERE signed_out_at IS NULL AND user_id = ? AND user_agent = ? AND ip_address = ? AND id != ?'
            );
            $del->execute([$userId, $row['user_agent'], $row['ip_address'], $currentSid]);
        } else {
            $del = $pdo->prepare('UPDATE sessions SET data = \'\', signed_out_at = NOW() WHERE signed_out_at IS NULL AND id = ? AND user_id = ?');
            $del->execute([$row['id'], $userId]);
        }
        return $del->rowCount();
    }
    return 0;
}

// Revokes every session on the account EXCEPT $exceptSessionId — used by
// a self-service password change, where the device making the change
// stays signed in (same convention as Facebook/Google), but every other
// open session is signed out as a security measure.
function revokeOtherSessions(PDO $pdo, int $userId, string $exceptSessionId): void {
    // Those devices are signed out (they'll need the password again) but stay
    // remembered as trusted devices — no new-device OTP on their next login.
    $pdo->prepare('UPDATE sessions SET data = \'\', signed_out_at = NOW() WHERE signed_out_at IS NULL AND user_id = ? AND id != ?')->execute([$userId, $exceptSessionId]);
}

// Revokes every session on the account, no exceptions — used by the
// forgot-password reset flow (the requester wasn't authenticated as this
// user anywhere during that flow, so there's no "current device" to
// spare) and by an admin resetting another user's password (the admin
// isn't the account owner, so none of that account's sessions are theirs
// to keep).
function revokeAllSessions(PDO $pdo, int $userId): void {
    // Every device is signed out (password needed again), but devices that
    // already passed the OTP stay remembered — no OTP on their next login.
    $pdo->prepare('UPDATE sessions SET data = \'\', signed_out_at = NOW() WHERE signed_out_at IS NULL AND user_id = ?')->execute([$userId]);
}

// Parses clinic_settings.min_advance_booking ('Same day','1 day','2 days','3 days')
// into the minimum number of days ahead a booking must be made.
function minAdvanceBookingDays(PDO $pdo): int {
    $val = $pdo->query('SELECT min_advance_booking FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
    if (!$val || stripos($val, 'same') !== false) return 0;
    if (preg_match('/(\d+)/', $val, $m)) return (int)$m[1];
    return 1;
}

// ── Appointment time helpers ──────────────────────────────────────
function apptTimeToMinutes(string $t): int {
    if (!preg_match('/^(\d+)(?::(\d+))?\s*(AM|PM)$/i', trim($t), $m)) return -1;
    $h = (int)$m[1]; $min = isset($m[2]) ? (int)$m[2] : 0;
    $ampm = strtoupper($m[3]);
    if ($ampm === 'PM' && $h !== 12) $h += 12;
    if ($ampm === 'AM' && $h === 12) $h = 0;
    return $h * 60 + $min;
}

// Returns the conflicting appointment time string, or null if clear.
// Pass $excludeId to skip the appointment being rescheduled.
function checkApptConflict(PDO $pdo, string $doctorId, string $date, string $time, int $durationMin, string $excludeId = ''): ?string {
    $q = "SELECT time FROM appointments
          WHERE doctor_id = ? AND date = ? AND status NOT IN ('cancelled','disapproved')"
       . ($excludeId ? ' AND id != ?' : '');
    $params = $excludeId ? [$doctorId, $date, $excludeId] : [$doctorId, $date];
    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    $newMins = apptTimeToMinutes($time);
    // Slots held by a clinic-proposed reschedule awaiting the patient's answer count as taken too.
    $times = array_merge($stmt->fetchAll(PDO::FETCH_COLUMN), heldProposalTimes($pdo, $doctorId, $date, $excludeId));
    foreach ($times as $existing) {
        $existMins = apptTimeToMinutes($existing);
        if ($existMins >= 0 && $newMins >= 0 && abs($newMins - $existMins) < $durationMin) {
            return $existing;
        }
    }
    return null;
}

// Times on $date held by a pending reschedule of this doctor's appointments,
// in either direction — a clinic-proposed new slot awaiting the patient
// (appointments.reschedule_proposal) or a patient's requested slot awaiting
// the clinic (appointments.reschedule_request, when it names a time) — so
// nobody else can book that slot in the meantime. Each entry is
// ['time' => …, 'patientId' => …] when $withPatient, else just the time.
function heldProposalTimes(PDO $pdo, string $doctorId, string $date, string $excludeId = '', bool $withPatient = false): array {
    try {
        $st = $pdo->prepare("SELECT id, patient_id, reschedule_proposal, reschedule_request FROM appointments
                              WHERE doctor_id = ? AND status IN ('pending','approved')
                                AND (reschedule_proposal IS NOT NULL OR reschedule_request IS NOT NULL)");
        $st->execute([$doctorId]);
    } catch (PDOException) { return []; }
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if ($excludeId !== '' && $r['id'] === $excludeId) continue;
        $slots = [];
        $p = json_decode($r['reschedule_proposal'] ?? '', true);
        if (is_array($p)) $slots[] = [$p['date'] ?? '', $p['time'] ?? ''];
        $q = json_decode($r['reschedule_request'] ?? '', true);
        if (is_array($q)) $slots[] = [$q['preferredDate'] ?? '', $q['preferredTime'] ?? ''];
        foreach ($slots as [$d, $t]) {
            if ($d !== $date || !$t) continue;
            $out[] = $withPatient ? ['time' => $t, 'patientId' => $r['patient_id']] : $t;
        }
    }
    return $out;
}
// Same overlap rule as checkApptConflict(), but for the PATIENT instead of
// the doctor: does this patient already hold an appointment (any doctor,
// booked online or by the clinic) overlapping this date+time? Returns the
// conflicting time string, or null if clear.
function checkPatientApptConflict(PDO $pdo, string $patientId, string $date, string $time, int $durationMin, string $excludeId = ''): ?string {
    if ($patientId === '') return null;
    $q = "SELECT time FROM appointments
          WHERE patient_id = ? AND date = ? AND status NOT IN ('cancelled','disapproved')"
       . ($excludeId ? ' AND id != ?' : '');
    $params = $excludeId ? [$patientId, $date, $excludeId] : [$patientId, $date];
    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    $newMins = apptTimeToMinutes($time);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        $existMins = apptTimeToMinutes($existing);
        if ($existMins >= 0 && $newMins >= 0 && abs($newMins - $existMins) < $durationMin) {
            return $existing;
        }
    }
    return null;
}

// Doctors who could plausibly see a patient on a given date — active,
// scheduled to work that weekday, and not individually blocked that date.
// Used for the "any available optometrist" booking path (no specific
// doctor chosen yet) to decide which time slots are even worth showing,
// and again server-side at submission to verify at least one of them is
// actually free before accepting a doctor-less request.
// With $time, also drops doctors whose own hours that weekday
// (doctor_days.start_time/end_time) don't cover it — NULL hours = the
// clinic's full day, so those always pass.
// ── Clinic-wide closures (api/clinic/closures.php) ───────────────
// The clinic_closures row for a date, or null. Table missing (not yet
// migrated) = never closed.
function clinicClosureOn(PDO $pdo, string $date): ?array {
    if ($date === '') return null;
    try {
        $s = $pdo->prepare('SELECT date, reason FROM clinic_closures WHERE date = ? LIMIT 1');
        $s->execute([$date]);
        return $s->fetch() ?: null;
    } catch (PDOException $e) { return null; }
}
function clinicClosedMessage(array $c): string {
    return 'The clinic is closed on ' . date('M j, Y', strtotime($c['date']))
         . ($c['reason'] ? " ({$c['reason']})" : '') . '. Please choose another date.';
}
// Upcoming closures as [date => reason], for merging into doctors'
// blocked dates (api/doctors/index.php, public.php).
function upcomingClinicClosures(PDO $pdo): array {
    try {
        $out = [];
        foreach ($pdo->query('SELECT date, reason FROM clinic_closures WHERE date >= CURDATE() ORDER BY date') as $r) $out[$r['date']] = $r['reason'] ?? '';
        return $out;
    } catch (PDOException $e) { return []; }
}

function eligibleDoctorsForDate(PDO $pdo, string $date, string $time = ''): array {
    if (clinicClosureOn($pdo, $date)) return [];   // clinic closed: nobody
    $dow = date('D', strtotime($date)); // 'Mon','Tue',… — matches doctor_days' ENUM
    $stmt = $pdo->prepare(
        "SELECT d.id, d.first_name, d.middle_name, d.last_name
           FROM doctors d
           JOIN doctor_days dd ON dd.doctor_id = d.id AND dd.day_of_week = ?
          WHERE d.status = 'active'
            AND NOT EXISTS (SELECT 1 FROM blocked_dates bd WHERE bd.doctor_id = d.id AND bd.date = ?)
          ORDER BY d.sort_order, d.id"
    );
    $stmt->execute([$dow, $date]);
    $rows = $stmt->fetchAll();

    if ($time !== '') {
        $toMin = fn(string $t) => (($ts = strtotime($t)) === false) ? null : ((int)date('G', $ts) * 60 + (int)date('i', $ts));
        $tMin  = $toMin($time);
        $hours = doctorDayHoursMap($pdo);
        if ($tMin !== null) {
            $rows = array_values(array_filter($rows, function ($r) use ($hours, $dow, $tMin, $toMin) {
                $h = $hours[$r['id']][$dow] ?? null;
                if (!$h) return true;
                $s = $toMin($h['start']); $e = $toMin($h['end']);
                return $s === null || $e === null || ($tMin >= $s && $tMin < $e);
            }));
        }
    }

    return array_map(fn($r) => [
        'id'   => $r['id'],
        'name' => 'Dr. ' . trim($r['first_name'] . _mi($r['middle_name']) . ' ' . $r['last_name']),
    ], $rows);
}

// ── Doctor's schedule vs. a requested time ───────────────────────
// Server-side check that $time on $date fits the doctor's CURRENT weekly
// schedule — they still work that weekday, and the time falls inside that
// day's hours (doctor_days.start_time/end_time, fitted to the clinic's own
// hours; NULL = full clinic hours). The booking page's slot list can be
// stale if the doctor's schedule changed after it loaded, so this is the
// real enforcement, same as blocked dates. Returns a patient-facing reason,
// or null when the time is fine.
function doctorScheduleProblem(PDO $pdo, string $doctorId, string $date, string $time): ?string {
    if ($doctorId === '' || $date === '' || $time === '') return null;
    if ($closed = clinicClosureOn($pdo, $date)) return clinicClosedMessage($closed);
    $dow      = date('D', strtotime($date));            // Mon, Tue, …
    $dowLong  = date('l', strtotime($date));            // Monday, …
    $toMin = function (?string $t): ?int {
        if (!$t || !preg_match('/^\s*(\d{1,2}):(\d{2})\s*(AM|PM)\s*$/i', $t, $m)) return null;
        return ((int)$m[1] % 12 + (strtoupper($m[3]) === 'PM' ? 12 : 0)) * 60 + (int)$m[2];
    };

    $dn = $pdo->prepare("SELECT CONCAT('Dr. ', first_name, ' ', last_name) FROM doctors WHERE id = ? LIMIT 1");
    $dn->execute([$doctorId]);
    $docName = $dn->fetchColumn() ?: 'The doctor';

    try {
        $s = $pdo->prepare('SELECT start_time, end_time FROM doctor_days WHERE doctor_id = ? AND day_of_week = ? LIMIT 1');
        $s->execute([$doctorId, $dow]);
        $day = $s->fetch();
    } catch (PDOException) {
        // Pre-migration database without start_time/end_time — days only.
        $s = $pdo->prepare('SELECT 1 FROM doctor_days WHERE doctor_id = ? AND day_of_week = ? LIMIT 1');
        $s->execute([$doctorId, $dow]);
        $day = $s->fetch() ? ['start_time' => null, 'end_time' => null] : false;
    }
    if (!$day) {
        return "{$docName} is no longer available on {$dowLong}s. The schedule was updated, so please choose another date.";
    }

    $cs = $pdo->query('SELECT morning_start, afternoon_end FROM clinic_settings WHERE id = 1 LIMIT 1')->fetch() ?: [];
    $open  = $toMin($cs['morning_start'] ?? '8:00 AM') ?? 480;
    $close = $toMin($cs['afternoon_end'] ?? '5:00 PM') ?? 1020;
    $start = max($open,  $toMin($day['start_time']) ?? $open);
    $end   = min($close, $toMin($day['end_time'])   ?? $close);

    $t = $toMin($time);
    if ($t === null || $t < $start || $t >= $end) {
        $fmt = fn(int $m) => date('g:i A', mktime(intdiv($m, 60), $m % 60));
        return "{$time} is not within {$docName}'s available time on {$dowLong}s ({$fmt($start)} – {$fmt($end)}). The schedule was updated, so please choose another time.";
    }
    return null;
}

// ── Upcoming appointments affected by a doctor-schedule change ───
// Pending/approved appointments of this doctor that haven't happened yet,
// optionally limited to one date. Used before saving a schedule, blocking a
// date, or marking a doctor unavailable, so staff see who'd be affected
// instead of the change silently going through.
function upcomingDoctorAppointments(PDO $pdo, string $doctorId, ?string $onDate = null): array {
    $sql = "SELECT id, patient_name, date, time, status, type FROM appointments
             WHERE doctor_id = ? AND status IN ('pending','approved') AND date >= CURDATE()"
         . ($onDate ? ' AND date = ?' : '') . ' ORDER BY date, time';
    $st = $pdo->prepare($sql);
    $st->execute($onDate ? [$doctorId, $onDate] : [$doctorId]);
    $now = time();
    return array_values(array_filter($st->fetchAll(), function ($r) use ($now) {
        $dt = DateTime::createFromFormat('Y-m-d g:i A', $r['date'] . ' ' . $r['time']);
        return !$dt || $dt->getTimestamp() > $now;   // drop ones already past today
    }));
}

// Shape sent to the browser for the "affected appointments" modal.
function _affectedAppt(array $r, string $why): array {
    return ['id' => $r['id'], 'patientName' => $r['patient_name'] ?? '', 'date' => $r['date'],
            'time' => $r['time'], 'status' => $r['status'], 'type' => $r['type'] ?? '', 'why' => $why];
}

// Which upcoming appointments would fall outside a NEW weekly schedule —
// a weekday no longer worked, or a time outside that day's new hours
// ($dayHours like api/doctors/update.php: day => ['start','end'] | null =
// full clinic hours, fitted to the clinic's own hours).
function scheduleChangeConflicts(PDO $pdo, string $doctorId, array $days, array $dayHours): array {
    $toMin = function (?string $t): ?int {
        if (!$t || !preg_match('/^\s*(\d{1,2}):(\d{2})\s*(AM|PM)\s*$/i', $t, $m)) return null;
        return ((int)$m[1] % 12 + (strtoupper($m[3]) === 'PM' ? 12 : 0)) * 60 + (int)$m[2];
    };
    $fmt = fn(int $m) => date('g:i A', mktime(intdiv($m, 60), $m % 60));
    $cs    = $pdo->query('SELECT morning_start, afternoon_end FROM clinic_settings WHERE id = 1 LIMIT 1')->fetch() ?: [];
    $open  = $toMin($cs['morning_start'] ?? '8:00 AM') ?? 480;
    $close = $toMin($cs['afternoon_end'] ?? '5:00 PM') ?? 1020;
    $out = [];
    foreach (upcomingDoctorAppointments($pdo, $doctorId) as $r) {
        $dow = date('D', strtotime($r['date']));
        if (!in_array($dow, $days, true)) {
            $out[] = _affectedAppt($r, 'No longer a working day (' . date('l', strtotime($r['date'])) . ')');
            continue;
        }
        $h     = $dayHours[$dow] ?? null;
        $start = max($open,  $toMin($h['start'] ?? null) ?? $open);
        $end   = min($close, $toMin($h['end']   ?? null) ?? $close);
        $t     = $toMin($r['time']);
        if ($t !== null && ($t < $start || $t >= $end)) {
            $out[] = _affectedAppt($r, "Outside the new hours ({$fmt($start)} – {$fmt($end)})");
        }
    }
    return $out;
}

// ── Unsaved examination drafts: reminders + expiry ───────────────
// An appointment with an auto-saved exam draft (exam_drafts) is never
// auto-marked a no-show (appointments/index.php) — but left alone it would
// sit "approved" for a past date forever. So:
//  1. Reminder — once its appointment date has passed, the appointment's
//     doctor and every admin get one notification to finish or discard it.
//  2. Expiry — a draft untouched for EXAM_DRAFT_EXPIRY_DAYS is deleted;
//     its appointment then follows the normal no-show rule (an admin can
//     still revert that). Doctor + admins are told it expired.
// Runs from the cron and on appointment loads. Returns counts.
const EXAM_DRAFT_EXPIRY_DAYS = 30;

function _examDraftRecipients(PDO $pdo, ?string $doctorId): array {
    $ids = array_map('intval', $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
    if ($doctorId) {
        $d = $pdo->prepare('SELECT d.user_id FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.id = ? AND u.is_active = 1 LIMIT 1');
        $d->execute([$doctorId]);
        if ($uid = $d->fetchColumn()) $ids[] = (int)$uid;
    }
    return array_values(array_unique($ids));
}

function processExamDrafts(PDO $pdo): array {
    $res = ['reminded' => 0, 'expired' => 0];
    try {
        // 1. Reminders — appointment date passed, still approved, not reminded yet.
        $due = $pdo->query(
            "SELECT d.id AS draft_id, d.saved_by, d.updated_at, a.id AS appt_id, a.patient_name, a.doctor_id, a.date, a.time
               FROM exam_drafts d
               JOIN appointments a ON a.id = d.appt_id AND a.patient_id = d.patient_id
              WHERE d.appt_id <> '' AND d.reminded_at IS NULL
                AND a.status = 'approved' AND a.date < CURDATE()"
        )->fetchAll();
        foreach ($due as $r) {
            $upd = $pdo->prepare('UPDATE exam_drafts SET reminded_at = NOW() WHERE id = ? AND reminded_at IS NULL');
            $upd->execute([$r['draft_id']]);
            if ($upd->rowCount() === 0) continue;   // another request already sent it
            $fmt     = date('M j, Y', strtotime($r['date']));
            $expires = date('M j, Y', strtotime($r['updated_at'] . ' +' . EXAM_DRAFT_EXPIRY_DAYS . ' days'));
            $body = "The examination for {$r['patient_name']}'s appointment on {$fmt} at {$r['time']} was started but never saved. "
                  . "Open the appointment to continue and save it, or discard the draft. "
                  . "If it's left untouched, the draft expires on {$expires} and the appointment will be treated as a no-show.";
            foreach (_examDraftRecipients($pdo, $r['doctor_id']) as $uid) {
                createNotification($pdo, $uid, 'exam_draft', 'Unsaved Examination', $body, $r['appt_id']);
            }
            $res['reminded']++;
        }

        // 2. Expiry — untouched for EXAM_DRAFT_EXPIRY_DAYS.
        $old = $pdo->prepare(
            "SELECT d.id AS draft_id, d.appt_id, a.patient_name, a.doctor_id, a.date, a.time, a.status
               FROM exam_drafts d
               LEFT JOIN appointments a ON a.id = d.appt_id AND a.patient_id = d.patient_id
              WHERE d.updated_at < NOW() - INTERVAL ? DAY"
        );
        $old->execute([EXAM_DRAFT_EXPIRY_DAYS]);
        foreach ($old->fetchAll() as $r) {
            $del = $pdo->prepare('DELETE FROM exam_drafts WHERE id = ?');
            $del->execute([$r['draft_id']]);
            if ($del->rowCount() === 0) continue;
            $res['expired']++;
            // Only worth telling anyone about when it was holding an appointment open.
            if ($r['appt_id'] === '' || $r['status'] !== 'approved') continue;
            $fmt  = date('M j, Y', strtotime($r['date']));
            $body = "The unsaved examination draft for {$r['patient_name']}'s appointment on {$fmt} at {$r['time']} expired after "
                  . EXAM_DRAFT_EXPIRY_DAYS . " days without being saved, so the appointment is now handled as a no-show. "
                  . "If the patient was seen, an admin can revert the no-show and record the examination.";
            foreach (_examDraftRecipients($pdo, $r['doctor_id']) as $uid) {
                createNotification($pdo, $uid, 'exam_draft_expired', 'Examination Draft Expired', $body, $r['appt_id']);
            }
        }
    } catch (PDOException) { /* exam_drafts not migrated yet — nothing to do */ }
    return $res;
}

// A patient's age right now, from their date of birth — computed at read
// time so it rolls over on their birthday by itself. patients.age is only
// written when the DOB is saved, so on its own it goes stale every year;
// it's used here only as a fallback for a record with no (valid) DOB.
function patientAge(?string $dob, $storedAge = 0): int {
    if ($dob) {
        try {
            $bd = new DateTime(substr($dob, 0, 10));
            return max(0, (int)$bd->diff(new DateTime('today'))->y);
        } catch (Exception) { /* malformed DOB — fall through */ }
    }
    return (int)($storedAge ?? 0);
}

// SQL condition: the appointment's own start time (date + "2:00 PM"-style
// time) is already in the past. Every connection runs on +08:00, same as PHP.
const APPT_START_PASSED_SQL =
    "(date < CURDATE() OR (date = CURDATE() AND STR_TO_DATE(time, '%l:%i %p') <= CURTIME()))";

// Requests still "pending" once their appointment time has arrived — staff
// or admin never approved or declined them, and nothing else would ever
// resolve them. They're declined (kept on record, never deleted) with a
// reason, and the patient gets an in-app + email notice. Called by the cron
// (api/cron/appointment_reminders.php) and on every appointments load
// (api/appointments/index.php), so it still happens without the cron.
// Returns how many were declined.
function expireUnactionedPending(PDO $pdo): int {
    $due = $pdo->query(
        "SELECT id, patient_id, doctor_name, date, time FROM appointments
         WHERE status = 'pending' AND " . APPT_START_PASSED_SQL
    )->fetchAll();

    $count = 0;
    foreach ($due as $r) {
        $upd = $pdo->prepare(
            "UPDATE appointments SET status = 'disapproved', disapproval_reason = ?, reschedule_request = NULL
             WHERE id = ? AND status = 'pending'"
        );
        $upd->execute(['Automatically declined. This request was not reviewed before its appointment time.', $r['id']]);
        if ($upd->rowCount() === 0) continue; // acted on (or expired by another request) in the meantime
        $count++;

        if (!$r['patient_id']) continue;
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$r['patient_id']]);
        $userId = $ps->fetchColumn();
        if (!$userId) continue;

        $fmtDate   = date('M j, Y', strtotime($r['date']));
        $withWho   = $r['doctor_name'] ? "with {$r['doctor_name']} " : '';
        $noticeMsg = "Your appointment request {$withWho}on {$fmtDate} at {$r['time']} has been automatically declined.";
        $reason    = 'The clinic wasn\'t able to review your request before its appointment time.';
        createNotification($pdo, (int)$userId, 'disapproved', 'Appointment Request Expired',
            "{$noticeMsg} {$reason} Please book a new appointment if you'd still like to be seen.", $r['id']);
        _emailPatientNotice($pdo, (int)$userId, 'Appointment Request Expired',
            $noticeMsg . ' Please book a new appointment if you\'d still like to be seen.',
            appointmentLinkUrl($r['id']), 'View Appointment', $r['date'], 'Reason', $reason);
    }
    return $count;
}

// Each doctor's own hours per working day, from doctor_days.start_time/
// end_time: [doctorId => ['Mon' => ['start' => '9:00 AM', 'end' => '12:00 PM'],
// 'Thu' => null, …]]. null = that day follows the clinic's full hours.
// Own try/catch so a database without the columns yet still loads doctors.
function doctorDayHoursMap(PDO $pdo, ?string $doctorId = null): array {
    $map = [];
    try {
        $sql = 'SELECT doctor_id, day_of_week, start_time, end_time FROM doctor_days'
             . ($doctorId !== null ? ' WHERE doctor_id = ?' : '');
        $st = $pdo->prepare($sql);
        $st->execute($doctorId !== null ? [$doctorId] : []);
        foreach ($st->fetchAll() as $r) {
            $map[$r['doctor_id']][$r['day_of_week']] = ($r['start_time'] && $r['end_time'])
                ? ['start' => $r['start_time'], 'end' => $r['end_time']]
                : null;
        }
    } catch (PDOException) { /* columns not yet migrated — every day = clinic hours */ }
    return $map;
}

// ── Build the frontend-compatible user object ─────────────────────
// Maps snake_case DB columns to camelCase keys expected by pages.js.
function buildUserObject(string $role, array $p, string $email, array $days = [], ?int $usersId = null): array {
    $middleName    = trim($p['middle_name'] ?? '');
    $middleInitial = ltrim(_mi($middleName));
    $fullName      = trim($p['first_name'] . _mi($middleName) . ' ' . $p['last_name']);

    $base = [
        'id'           => $p['id'],
        'dbId'         => $usersId,   // users.id integer — used for activity log photo join
        'firstName'    => $p['first_name'],
        'middleName'   => $middleName,
        'middleInitial'=> $middleInitial,
        'lastName'     => $p['last_name'],
        'name'         => ($role === 'doctor' ? 'Dr. ' : '') . $fullName,
        'email'        => $email,
        'contact'      => $p['contact'] ?? '',
        'status'       => $p['status']  ?? 'active',
        'role'         => $role,
        'photoUrl'     => $p['photo_url'] ?? null,
    ];

    if ($role === 'doctor') {
        return array_merge($base, [
            'specialization' => $p['specialization'] ?? 'Optometrist',
            'prcLicense'     => $p['prc_license'] ?? '',
            'available'      => (bool)($p['available'] ?? true),
            'days'           => $days,
            'hours'          => $p['work_hours'] ?? '',
            'dayHours'       => (object)(doctorDayHoursMap(getDB(), (string)$p['id'])[$p['id']] ?? []),
        ]);
    }

    if ($role === 'patient') {
        return array_merge($base, [
            'gender'         => $p['gender']          ?? '',
            'dob'            => $p['dob']              ?? '',
            'age'            => patientAge($p['dob'] ?? null, $p['age'] ?? 0),
            'address'        => $p['address']          ?? '',
            'occupation'     => $p['occupation']       ?? '',
            'medicalHistory' => $p['medical_history']  ?? '',
            'medicalHistoryByPatient' => !empty($p['medical_history_by_patient']),
            'medicalHistoryPatientSubmitted' => !empty($p['medical_history_patient_at']),
            'qrData'         => $p['qr_data']          ?? '',
            'registeredDate' => $p['registered_date']  ?? '',
            'lastVisit'      => $p['last_visit'] ?: '—',
            'noShowCount'    => (int)($p['no_show_count'] ?? 0),
            'bookingRestricted' => (bool)($p['booking_restricted'] ?? false),
            'deletionRequestedAt' => $p['deletion_requested_at']   ?? null,
            'deletionRequestReason' => $p['deletion_request_reason'] ?? '',
            'consultations'  => [],
            'examinations'   => [],
            'prescriptions'  => [],
        ]);
    }

    return $base;
}

// ── No-show tracking ───────────────────────────────────────────────
// Threshold: 3 no-shows (this constant). Consequence: booking_restricted
// gets set on the patient record, which blocks self-service ONLINE
// booking only — api/appointments/create.php rejects a patient-submitted
// request with "Online booking is currently unavailable for your account
// due to repeated missed appointments. Please contact the clinic directly
// to schedule."
//
// Takes effect immediately, the instant the 3rd no-show is recorded —
// whether that's the automatic day-passed detection (appointments/index.php)
// or a staff member manually marking one (appointments/update.php). No
// grace period, no separate approval step.
//
// Lasts indefinitely — there's no automatic expiry, it doesn't wear off
// after any period of good behavior. The only way it's lifted is an
// admin/staff manually clicking "Clear Restriction" in the Edit Patient
// modal (api/patients/clear_restriction.php). Clearing it only resets the
// restriction FLAG, not the no-show counter itself — so if the patient
// no-shows even once more right after being unblocked, they're already
// back at/above the threshold and get re-restricted instantly (see the
// !$row['booking_restricted'] guard below: it only ever fires again once
// the flag has been cleared, but the count is never what's checked for
// "how far past 3" — just whether it's >= 3 at all).
//
// Can admin/staff bypass this for a walk-in? Yes, entirely — the
// restriction check only ever runs when the request comes from the
// patient role (self-service; see create.php's `if ($role === 'patient')`
// gate). If admin or staff creates the appointment on the patient's
// behalf instead, that check is skipped completely — a walk-in, phone
// booking, or staff manually scheduling a blocked patient all work
// normally, no override needed.
const NO_SHOW_RESTRICTION_THRESHOLD = 3;

// Increments a patient's no-show count by one and applies the booking
// restriction once they cross the threshold. Returns true if this call is
// what pushed them over the threshold (i.e. the restriction is new), so
// callers can mention it in a notification/log without re-mentioning it
// on every subsequent no-show.
function recordNoShow(PDO $pdo, string $patientId): bool {
    if (!$patientId) return false;
    try {
        $pdo->prepare('UPDATE patients SET no_show_count = no_show_count + 1 WHERE id = ?')->execute([$patientId]);
        $stmt = $pdo->prepare('SELECT no_show_count, booking_restricted FROM patients WHERE id = ? LIMIT 1');
        $stmt->execute([$patientId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        if ((int)$row['no_show_count'] >= NO_SHOW_RESTRICTION_THRESHOLD && !$row['booking_restricted']) {
            $pdo->prepare('UPDATE patients SET booking_restricted = 1 WHERE id = ?')->execute([$patientId]);
            return true;
        }
    } catch (PDOException) { /* non-critical */ }
    return false;
}

// ── Appointment reminders & confirmation ─────────────────────────
// The reminder is sent at the configured reminder time the day before the
// appointment, asking the patient to confirm they're coming. Confirming is
// informational only (it tells the front desk to expect them) — nothing is
// ever auto-cancelled for not confirming. Checked against the clinic's
// local time (Asia/Manila, set in this file above). Admin-configurable via
// Clinic Settings → Scheduling Rules (clinic_settings.reminder_time — same
// "H:MM AM/PM" string format as the rest of clinic_settings' time fields).
function reminderTimeSetting(PDO $pdo): string {
    return $pdo->query('SELECT reminder_time FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn() ?: '12:00 AM';
}
// Second reminder, sent the same day only to patients who still haven't
// confirmed by then (Clinic Settings → Scheduling Rules, default noon).
function followupReminderTimeSetting(PDO $pdo): string {
    return $pdo->query('SELECT followup_reminder_time FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn() ?: '12:00 PM';
}
// Converts a clinic_settings "H:MM AM/PM" time string into "HH:MM:SS" for
// direct comparison against CURTIME() in the cron's SQL.
function settingTimeTo24h(string $t): string {
    $ts = strtotime($t);
    return $ts !== false ? date('H:i:s', $ts) : '00:00:00';
}

// ── Per-patient upcoming-appointment limit ───────────────────────
// Clinic Settings → Scheduling Rules → Max Upcoming Appointments Per
// Patient (default 1): how many open appointments a patient may book for
// themselves at once. Open = booked online by the patient, pending or
// approved, dated today or later. Completed / cancelled / disapproved /
// no-show / past ones free the patient to book again. Admin/staff
// bookings (source 'walk-in', e.g. follow-ups) neither count nor are
// limited. Returns the message to show, or null.
//
// Stored in clinic_settings.max_appts_per_patient_per_day — the column of
// the per-day limit this replaced, reused under the new meaning so no
// database migration was needed.
function maxUpcomingApptsPerPatient(PDO $pdo): int {
    try {
        $v = (int)$pdo->query('SELECT max_appts_per_patient_per_day FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
    } catch (PDOException $e) {
        $v = 0;   // column missing on an older database → the default
    }
    return $v > 0 ? $v : 1;
}
function patientOpenAppointments(PDO $pdo, string $patientId): array {
    $s = $pdo->prepare(
        "SELECT id, date, time FROM appointments
          WHERE patient_id = ? AND source = 'online' AND status IN ('pending','approved') AND date >= CURDATE()
          ORDER BY date, time"
    );
    $s->execute([$patientId]);
    return $s->fetchAll();
}
function patientUpcomingLimitError(PDO $pdo, string $patientId): ?string {
    if ($patientId === '') return null;
    $max  = maxUpcomingApptsPerPatient($pdo);
    $open = patientOpenAppointments($pdo, $patientId);
    if (count($open) < $max) return null;
    if ($max === 1) {
        $when = date('M j, Y', strtotime($open[0]['date'])) . " at {$open[0]['time']}";
        return "You already have an upcoming appointment on {$when}. You can book another once it's done, or reschedule or cancel it from My Appointments.";
    }
    return "You already have {$max} upcoming appointments, the most you can book at once. You can book another once one is done, or reschedule or cancel one from My Appointments.";
}

// ── Links back into the system (used in emails) ──────────────────
// The site's base address, e.g. https://canaoptical.com, in this order:
//   1. APP_URL in .env, if set (most reliable on any host);
//   2. worked out from the current request — correct on localhost
//      (http://localhost/canaopticalclinic) and on the live site alike,
//      including when the reminder job is triggered over HTTP;
//   3. no request at all (the job run from the command line): a local
//      XAMPP install under htdocs → http://localhost/<folder>; anything
//      else → the live domain.
function appBaseUrl(): string {
    $env = getenv('APP_URL');
    if ($env) return rtrim($env, '/');
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $pos    = strpos($script, '/api/');
        $path   = $pos !== false ? substr($script, 0, $pos) : '';
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $path;
    }
    $root = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    if (preg_match('#/htdocs/(.+)$#i', $root, $m)) {
        return 'http://localhost/' . implode('/', array_map('rawurlencode', explode('/', $m[1])));
    }
    return 'https://canaoptical.com';
}
// Opens the patient portal straight on one appointment (app.html handles
// #appt=ID): the confirm prompt when it's waiting for confirmation,
// otherwise its details. Signing in first if needed.
function appointmentLinkUrl(string $apptId): string {
    return appBaseUrl() . '/app.html#appt=' . rawurlencode($apptId);
}

// Builds a Google Calendar "add event" link for an approved appointment —
// Google's own URL-based /calendar/render?action=TEMPLATE endpoint, not
// the OAuth Calendar API, so no account connection or credentials are
// needed on either side; it just opens Google Calendar pre-filled and the
// patient hits Save. From there Google's own reminder options apply on top
// of this app's own email/in-app reminders. Shown as the secondary "Add to
// Google Calendar" button in the approval, reschedule and first reminder
// emails (systemEmailBody(), config/smtp.php).
// Times are sent as plain local wall-clock numbers with an explicit
// ctz=Asia/Manila (the timezone this whole app runs in) so Google renders
// them at the correct local time regardless of the recipient's device.
function googleCalendarUrl(PDO $pdo, string $date, string $time, ?string $doctorName, ?string $apptType): string {
    $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
    preg_match('/(\d+)/', $durStr ?: '45', $dm);
    $durationMin = isset($dm[1]) ? (int)$dm[1] : 45;

    $startTs = strtotime("$date $time");
    if ($startTs === false) $startTs = strtotime($date) ?: time();
    $endTs = $startTs + $durationMin * 60;
    $stamp = fn(int $ts): string => date('Ymd\THis', $ts);

    $address = $pdo->query('SELECT address FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
    $details = trim(($doctorName ? "Doctor: {$doctorName}\n" : '') . 'Booked through the Cana Optical Clinic patient portal.');

    $params = http_build_query([
        'action'   => 'TEMPLATE',
        'text'     => ($apptType ?: 'Eye Consultation') . ' — Cana Optical Clinic',
        'dates'    => $stamp($startTs) . '/' . $stamp($endTs),
        'details'  => $details,
        'location' => $address ?: '',
        'ctz'      => 'Asia/Manila',
    ]);
    return "https://calendar.google.com/calendar/render?{$params}";
}

// One-tap "Confirm Appointment" link for the reminder emails — confirms
// without signing in (api/appointments/confirm-link.php). The token is an
// HMAC of the appointment id, so a link only ever works for its own
// appointment and can't be guessed or edited into another one. The key is
// APP_KEY from .env if set, otherwise derived from this server's own DB
// credentials (private to the server, and stable across requests).
function appSigningKey(): string {
    $k = getenv('APP_KEY');
    return $k ?: hash('sha256', 'cana-appt-confirm|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS);
}
function appointmentConfirmToken(string $apptId): string {
    return hash_hmac('sha256', 'confirm|' . $apptId, appSigningKey());
}
function appointmentConfirmUrl(string $apptId): string {
    return appBaseUrl() . '/api/appointments/confirm-link.php?id=' . rawurlencode($apptId)
         . '&t=' . appointmentConfirmToken($apptId);
}

// Patients can cancel or request a reschedule online up to this many hours
// before the appointment (mirrors pages.js's CANCEL_DEADLINE_HOURS /
// RESCHEDULE_DEADLINE_HOURS and api/appointments/update.php's checks).
// 0 = right up to the appointment time: no down payment to protect, and
// cancelling beats a no-show.
const PATIENT_CHANGE_CUTOFF_HOURS = 0;

// Unix timestamp of an appointment's date+time, or null if unparseable.
function apptTimestamp(array $appt): ?int {
    $dt = DateTime::createFromFormat('Y-m-d g:i A', $appt['date'] . ' ' . $appt['time']);
    return $dt ? $dt->getTimestamp() : null;
}

// "If you can't make it" guidance that's actually true at the moment it's
// read: while online cancellation is still open, say until when; once the
// appointment time has passed, point to the clinic instead.
// $now overrides the current time (for previewing a reminder as it would
// read when sent later); defaults to now.
function apptChangeGuidance(PDO $pdo, array $appt, ?int $now = null): string {
    $now      = $now ?? time();
    $ts       = apptTimestamp($appt);
    $cutoffTs = $ts !== null ? $ts - PATIENT_CHANGE_CUTOFF_HOURS * 3600 : null;
    if ($cutoffTs !== null && $now < $cutoffTs) {
        $when = date('Y-m-d', $cutoffTs) === date('Y-m-d', $now) ? 'today at ' . date('g:i A', $cutoffTs) : date('M j, g:i A', $cutoffTs);
        return "If you can no longer make it, you can cancel or request a reschedule from your account until {$when}.";
    }
    $phone = trim((string)$pdo->query('SELECT phone FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn());
    return 'If you can no longer make it, please contact the clinic' . ($phone ? " at {$phone}" : '') . '.';
}

// In-app + email notice for a missed appointment — shared by the manual
// "Mark No-Show" (api/appointments/update.php) and the automatic
// next-day transition (api/appointments/index.php). When this no-show
// just triggered the booking restriction, that's spelled out in the
// email's highlighted box rather than buried in the sentence.
function notifyPatientNoShow(PDO $pdo, int $userId, array $appt, bool $justRestricted): void {
    $fmtDate = date('M j, Y', strtotime($appt['date']));
    $doctor  = $appt['doctor_name'] ?: 'your doctor';
    $base    = "You were marked as a no-show for your appointment with {$doctor} on {$fmtDate} at {$appt['time']}.";
    $restrictMsg = 'Due to repeated missed appointments, online booking has been temporarily restricted for your account. Please contact the clinic directly to schedule your next visit.';
    createNotification($pdo, $userId, 'no_show', 'Missed Appointment', $base . ($justRestricted ? " {$restrictMsg}" : ''), $appt['id']);
    _emailPatientNotice($pdo, $userId, 'Missed Appointment',
        $base . ' If this is a mistake, please contact the clinic.',
        appointmentLinkUrl($appt['id']), 'View Appointment', $appt['date'],
        $justRestricted ? 'Online Booking Restricted' : '', $justRestricted ? $restrictMsg : '');
}

// Title + message for the day-before reminders (api/cron/
// appointment_reminders.php). $which: 1 = first reminder, 2 = second
// reminder (sent only if still unconfirmed). Confirming is informational —
// nothing is cancelled for not confirming.
function buildReminderNotice(PDO $pdo, array $appt, int $which, ?int $now = null): array {
    $doctor  = $appt['doctor_name'] ?: 'your doctor';
    $fmtDate = date('M j, Y', strtotime($appt['date']));
    $when    = "tomorrow ({$fmtDate}) at {$appt['time']}";
    $guide   = apptChangeGuidance($pdo, $appt, $now);
    if ($which === 2) {
        return ['Please Confirm Your Appointment',
            "This is a second reminder: you haven't confirmed your appointment with {$doctor} {$when} yet. "
          . "Please tap Confirm Appointment so the clinic knows to expect you. {$guide}"];
    }
    return ['Confirm Your Appointment',
        "You have an appointment with {$doctor} {$when}. "
      . "Please tap Confirm Appointment to let the clinic know you'll be attending. {$guide}"];
}

// Marks an approved, reminded appointment as confirmed and tells
// admin/staff. Shared by the in-app button (confirm.php) and the email
// link (confirm-link.php) so both follow exactly the same rules. Returns
// [ok, message]; already-confirmed counts as ok.
function confirmAppointmentAttendance(PDO $pdo, array $appt): array {
    if ($appt['status'] !== 'approved') {
        return [false, $appt['status'] === 'cancelled'
            ? 'This appointment has already been cancelled, so it can no longer be confirmed.'
            : 'Only approved appointments can be confirmed.'];
    }
    if (!$appt['reminder_sent_at']) return [false, 'There is no pending confirmation for this appointment yet.'];
    if ($appt['confirmed_at'])      return [true, 'already'];

    $upd = $pdo->prepare("UPDATE appointments SET confirmed_at = NOW() WHERE id = ? AND confirmed_at IS NULL AND status = 'approved'");
    $upd->execute([$appt['id']]);
    if ($upd->rowCount() === 0) return [true, 'already']; // confirmed a moment ago from elsewhere

    // Let admin/staff know — so the front desk isn't left guessing whether
    // tomorrow's slot is still a go.
    $fmtDate = date('M j, Y', strtotime($appt['date']));
    notifyAdminStaff($pdo, 'appointment_confirmed', 'Patient Confirmed Attendance',
        "{$appt['patient_name']} confirmed attendance for their appointment with {$appt['doctor_name']} on {$fmtDate} at {$appt['time']}."
    );
    return [true, 'confirmed'];
}

// Builds the next A00N id and inserts the appointment row
// (api/appointments/create.php).
function createAppointmentRecord(PDO $pdo, array $data): string {
    $last = $pdo->query("SELECT id FROM appointments ORDER BY id DESC LIMIT 1")->fetchColumn();
    $next = 1;
    if ($last && preg_match('/^A(\d+)$/i', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    $newId = 'A' . str_pad($next, 3, '0', STR_PAD_LEFT);
    // Ensure uniqueness in case of gaps
    $dup = $pdo->prepare('SELECT id FROM appointments WHERE id = ?');
    while (true) {
        $dup->execute([$newId]);
        if (!$dup->fetch()) break;
        $next++;
        $newId = 'A' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    // 'online' unless the caller explicitly says 'walk-in'.
    $source = ($data['source'] ?? 'online') === 'walk-in' ? 'walk-in' : 'online';

    $pdo->prepare(
        'INSERT INTO appointments
           (id, patient_id, patient_name, doctor_id, doctor_name, date, time, type, status, source, notes, terms_agreed)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $newId, $data['patientId'], $data['patientName'], $data['doctorId'], $data['doctorName'],
        $data['date'], $data['time'], $data['type'], $data['status'], $source, $data['notes'] ?? '',
        !empty($data['termsAgreed']) ? 1 : 0,
    ]);

    return $newId;
}

// ── Notification helpers ─────────────────────────────────────────
function createNotification(PDO $pdo, int $userId, string $type, string $title, string $body, ?string $relatedId = null): void {
    try {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, related_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, $body, $relatedId]);
    } catch (PDOException) {
        // Non-critical — silent fail
    }
}

// $relatedId (optional) lets a click-through on this notification jump
// straight to the specific record it's about — e.g. reschedule_request
// notifications pass the appointment id, so clicking one opens that exact
// appointment's details instead of just a filtered list to scan (see
// openRescheduleRequestNotif() in main.js / _markNotifDropdown() in
// router.js). Every other caller today omits it and behaves exactly as
// before.
function notifyAdminStaff(PDO $pdo, string $type, string $title, string $body, ?string $relatedId = null): void {
    try {
        $ids  = $pdo->query("SELECT id FROM users WHERE role IN ('admin','staff') AND is_active = 1")->fetchAll();
        $stmt = $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, related_id) VALUES (?, ?, ?, ?, ?)');
        foreach ($ids as $row) {
            $stmt->execute([(int)$row['id'], $type, $title, $body, $relatedId]);
        }
    } catch (PDOException) {
        // Non-critical — silent fail
    }
}

// Companion to createNotification() — sends the same notice by email too,
// so a patient not actively logged in when it fires still finds out
// (appointment reminders, cancellations, etc). Shared
// here rather than duplicated per call site (originally lived only in
// api/cron/appointment_reminders.php). Its own try/catch so one failed
// delivery never interrupts whatever loop is calling it in a batch.
// $ctaUrl/$ctaLabel are optional (e.g. the "View Appointment" / "Confirm
// Appointment" link into the patient portal on the approval/reminder emails) and passed straight through to
// systemEmailBody() for the HTML button; the plain-text fallback gets the
// same link as a plain line since it can't render a styled button.
// $ctaDate ('Y-m-d', optional) drives that button's small calendar-icon
// preview — see systemEmailBody().
// $reasonLabel/$reasonText (optional) put a staff-entered reason (a
// cancellation or disapproval) in its own visually separate box instead
// of run into the same paragraph as the main sentence — mirrors how the
// in-app Appointment Details modal already shows a Cancellation/
// Disapproval Reason as its own highlighted block, not just appended
// text.
function _emailPatientNotice(PDO $pdo, int $userId, string $subject, string $message, string $ctaUrl = '', string $ctaLabel = '', string $ctaDate = '', string $reasonLabel = '', string $reasonText = '', string $reasonTone = 'negative', string $calendarUrl = ''): void {
    try {
        $s = $pdo->prepare(
            'SELECT u.email, p.first_name, p.last_name
               FROM users u JOIN patients p ON p.user_id = u.id
              WHERE u.id = ? LIMIT 1'
        );
        $s->execute([$userId]);
        $row = $s->fetch();
        if (!$row || empty($row['email'])) return;
        $name = trim($row['first_name'] . ' ' . $row['last_name']) ?: 'there';
        $text = "$subject\n\n$message"
            . ($reasonText ? "\n\n" . ($reasonLabel ?: 'Reason') . ": $reasonText" : '')
            . ($ctaUrl ? "\n\n" . ($ctaLabel ?: 'View Details') . ": $ctaUrl" : '')
            . ($calendarUrl ? "\n\nAdd to Google Calendar: $calendarUrl" : '');
        sendEmailLater($row['email'], $name, $subject, systemEmailBody($name, $subject, $message, $ctaUrl, $ctaLabel, $ctaDate, $reasonLabel, $reasonText, $reasonTone, $calendarUrl), $text);
    } catch (\Throwable $e) {
        error_log('[email] Notice "' . $subject . '" failed for user ' . $userId . ': ' . $e->getMessage());
    }
}

// ── Fetch profile row + build user object for a given user_id ────
function loadUserProfile(PDO $pdo, int $userId, string $role): ?array {
    $profile = null;
    $days    = [];

    switch ($role) {
        case 'admin':
            $s = $pdo->prepare('SELECT * FROM admins WHERE user_id = ? LIMIT 1');
            $s->execute([$userId]);
            $profile = $s->fetch() ?: null;
            break;

        case 'staff':
            $s = $pdo->prepare('SELECT * FROM staff WHERE user_id = ? LIMIT 1');
            $s->execute([$userId]);
            $profile = $s->fetch() ?: null;
            break;

        case 'doctor':
            $s = $pdo->prepare('SELECT * FROM doctors WHERE user_id = ? LIMIT 1');
            $s->execute([$userId]);
            $profile = $s->fetch() ?: null;
            if ($profile) {
                $ds = $pdo->prepare(
                    'SELECT day_of_week FROM doctor_days WHERE doctor_id = ?
                     ORDER BY FIELD(day_of_week,"Mon","Tue","Wed","Thu","Fri","Sat","Sun")'
                );
                $ds->execute([$profile['id']]);
                $days = array_column($ds->fetchAll(), 'day_of_week');
            }
            break;

        case 'patient':
            $s = $pdo->prepare('SELECT * FROM patients WHERE user_id = ? LIMIT 1');
            $s->execute([$userId]);
            $profile = $s->fetch() ?: null;
            break;
    }

    // Fetch photo_url separately so a missing column (pre-migration) never breaks auth
    if ($profile) {
        try {
            $ps = $pdo->prepare('SELECT photo_url FROM users WHERE id = ? LIMIT 1');
            $ps->execute([$userId]);
            $photoRow = $ps->fetch();
            $profile['photo_url'] = $photoRow['photo_url'] ?? null;
        } catch (PDOException) {
            $profile['photo_url'] = null;
        }
    }

    return $profile ? ['profile' => $profile, 'days' => $days] : null;
}

// Shared next-record-id generator (max existing id of this prefix, +1,
// with a collision-check loop) — used by api/examinations/create.php and
// update.php for consultations/examinations/prescriptions ids ('C001',
// 'E001', 'RX001'). Kept here rather than duplicated per file so all
// three id sequences get the same collision protection consistently.
function nextRecordId(PDO $pdo, string $table, string $prefix): string {
    $last = $pdo->query("SELECT id FROM `$table` ORDER BY id DESC LIMIT 1")->fetchColumn();
    $next = 1;
    if ($last && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    $id  = $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
    $dup = $pdo->prepare("SELECT id FROM `$table` WHERE id = ?");
    while (true) {
        $dup->execute([$id]);
        if (!$dup->fetch()) break;
        $next++;
        $id = $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
    }
    return $id;
}

// ════════════════════════════════════════════════════════════════
//  UPLOAD SIZE LIMITS — each just enough for what it holds, so nobody can
//  push a huge file onto the server (or onto every homepage visitor).
//  Mirrored in main.js (UPLOAD_LIMITS) so the browser stops a too-big
//  file before sending it. Previous Clinic Records: PREV_RECORD_* below.
// ════════════════════════════════════════════════════════════════
const UPLOAD_MAX_PROFILE_PHOTO = 1 * 1024 * 1024;     // a 480 px photo, cropped in the browser (~100 KB)
const UPLOAD_MAX_LOGO          = 2 * 1024 * 1024;     // resized to 1024 px in the browser first; an SVG as-is
const UPLOAD_MAX_HERO          = 5 * 1024 * 1024;     // one 1920 × 1080 photo
const UPLOAD_MAX_VIDEO         = 100 * 1024 * 1024;   // about 2 minutes of 1080p (streamed to visitors)
const UPLOAD_MAX_GALLERY       = 2 * 1024 * 1024;     // resized to 1600 px in the browser first
const UPLOAD_MAX_BACKUP        = 2048 * 1024 * 1024;  // the database plus every uploaded file, for years

function uploadSizeLabel(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    return $bytes >= 1048576 ? round($bytes / 1048576) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}
// The hosting's own PHP cap on one upload (upload_max_filesize / post_max_size,
// "40M" style; 0 = no cap), so a too-big message names the limit that really
// applied when the server's is lower than ours.
function serverUploadLimit(): int {
    $bytes = function (string $v): int {
        $n = (int)$v;
        return match (strtoupper(substr(trim($v), -1))) { 'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n };
    };
    $caps = array_filter([$bytes((string)ini_get('upload_max_filesize')), $bytes((string)ini_get('post_max_size'))]);
    return $caps ? min($caps) : PHP_INT_MAX;
}

// The file in $_FILES[$field], or a JSON error when it's missing, cut off or
// over $maxBytes — including when PHP itself refused it as too big (then
// $_FILES is empty and only the request size shows why). $what starts the
// message ("That video"); $hint is added to the too-big message.
function requireUploadedFile(string $field, int $maxBytes, string $what, string $hint = ''): array {
    $limit  = min($maxBytes, serverUploadLimit());
    $tooBig = "$what is too large. The limit is " . uploadSizeLabel($limit) . '.' . ($hint !== '' ? " $hint" : '');
    $f   = $_FILES[$field] ?? null;
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!$f || $err === UPLOAD_ERR_NO_FILE) {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) jsonResponse(['success' => false, 'message' => $tooBig]);
        jsonResponse(['success' => false, 'message' => 'No file uploaded.']);
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) jsonResponse(['success' => false, 'message' => $tooBig]);
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        jsonResponse(['success' => false, 'message' => 'The upload didn\'t finish. Please try again.']);
    }
    if ((int)filesize($f['tmp_name']) > $maxBytes) jsonResponse(['success' => false, 'message' => $tooBig]);
    return $f;
}

// ════════════════════════════════════════════════════════════════
//  PREVIOUS CLINIC RECORDS — exam results / prescriptions a patient
//  brings from another optical clinic (mostly Transfer patients): the
//  uploaded PDF / scanned images plus any values staff type in from
//  them. Files live in storage/previous_records/<patient id>/ (blocked
//  from direct web access by storage/.htaccess) and are only served by
//  api/previous_records/file.php after a login check.
//  Tables: database/previous_clinic_records.sql
// ════════════════════════════════════════════════════════════════
// Pages from another clinic: a PDF up to 20 MB (a 10-page colour scan at
// 300 dpi fits), photos up to 10 MB (the browser shrinks big phone photos to
// about 1-2 MB first), up to 10 files and 60 MB per record. Files travel in
// 2 MB pieces (api/previous_records/upload.php), so this doesn't depend on
// the server's PHP upload_max_filesize / post_max_size. Mirrored in main.js
// (PREV_REC_*).
const PREV_RECORD_MAX_BYTES       = 20 * 1024 * 1024;   // per PDF
const PREV_RECORD_MAX_IMAGE_BYTES = 10 * 1024 * 1024;   // per JPG / PNG
const PREV_RECORD_MAX_TOTAL       = 60 * 1024 * 1024;   // all files of one record
const PREV_RECORD_CHUNK_BYTES     = 2 * 1024 * 1024;    // per upload piece
const PREV_RECORD_MAX_FILES       = 10;                 // per record
const PREV_RECORD_TYPES       = ['exam', 'prescription', 'other'];
// Type detected from the file's content (finfo) => extension used on disk.
// Never the browser's claimed type or the uploaded file name.
const PREV_RECORD_MIME_EXT    = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
// archived_records.type of a record archived from the profile
// (api/previous_records/archive.php). Restored or deleted for good from
// Settings > Archives (api/archive/restore.php, delete.php).
const PREV_RECORD_ARCHIVE_TYPE = 'Previous Record';

// Pieces of a file being uploaded collect here (<id>.part + <id>.json)
// until the record is saved and the finished file is moved into the
// patient's folder. Under storage/ (not web-accessible), and outside
// previous_records/ so half-uploaded files never end up in a backup.
function prevRecordStagingDir(): string {
    return __DIR__ . '/../storage/upload_staging';
}
// The upload's bookkeeping (who, name, size, pieces received, done,
// detected type), or null for an unknown / malformed upload id.
function prevRecordStagedMeta(string $uploadId): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) return null;
    $f = prevRecordStagingDir() . "/$uploadId.json";
    if (!is_file($f)) return null;
    $m = json_decode((string)file_get_contents($f), true);
    return is_array($m) ? $m : null;
}
function prevRecordClearStaged(string $uploadId): void {
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) return;
    @unlink(prevRecordStagingDir() . "/$uploadId.part");
    @unlink(prevRecordStagingDir() . "/$uploadId.json");
}
// Uploads never saved to a record (form closed, browser gone) are
// dropped after a day.
function prevRecordCleanStaging(int $maxAgeSeconds = 86400): void {
    foreach (glob(prevRecordStagingDir() . '/*.{part,json}', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - $maxAgeSeconds) @unlink($f);
    }
}

function previousRecordsDir(string $patientId): string {
    // Patient IDs look like P001; anything else never reaches the filesystem.
    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $patientId)) {
        throw new InvalidArgumentException('Invalid patient id.');
    }
    return __DIR__ . '/../storage/previous_records/' . $patientId;
}

// Creates the tables when the database doesn't have them yet, so the first
// upload works even if database/previous_clinic_records.sql wasn't run.
// DDL commits implicitly in MySQL: call this before beginTransaction().
function ensurePreviousRecordTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `previous_clinic_records` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `patient_id` VARCHAR(10) NOT NULL,
      `clinic_name` VARCHAR(150) NOT NULL,
      `optometrist` VARCHAR(150) DEFAULT NULL,
      `record_date` DATE DEFAULT NULL,
      `record_type` VARCHAR(20) NOT NULL DEFAULT 'exam',
      `record_type_other` VARCHAR(60) DEFAULT NULL,
      `od_sph` VARCHAR(10) DEFAULT NULL, `od_cyl` VARCHAR(10) DEFAULT NULL, `od_axis` VARCHAR(10) DEFAULT NULL,
      `od_add` VARCHAR(10) DEFAULT NULL, `od_va` VARCHAR(10) DEFAULT NULL,
      `os_sph` VARCHAR(10) DEFAULT NULL, `os_cyl` VARCHAR(10) DEFAULT NULL, `os_axis` VARCHAR(10) DEFAULT NULL,
      `os_add` VARCHAR(10) DEFAULT NULL, `os_va` VARCHAR(10) DEFAULT NULL,
      `pd` VARCHAR(20) DEFAULT NULL,
      `diagnosis` VARCHAR(255) DEFAULT NULL,
      `notes` TEXT DEFAULT NULL,
      `created_by` VARCHAR(150) DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_by` VARCHAR(150) DEFAULT NULL,
      `updated_at` DATETIME DEFAULT NULL,
      `archived_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_pcr_patient` (`patient_id`),
      CONSTRAINT `fk_pcr_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `previous_clinic_files` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `record_id` INT UNSIGNED NOT NULL,
      `patient_id` VARCHAR(10) NOT NULL,
      `stored_name` VARCHAR(100) NOT NULL,
      `original_name` VARCHAR(255) NOT NULL,
      `mime_type` VARCHAR(60) NOT NULL,
      `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_pcf_record` (`record_id`),
      CONSTRAINT `fk_pcf_record` FOREIGN KEY (`record_id`) REFERENCES `previous_clinic_records`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Columns added after the table first shipped, each added here on first use
// when the database predates it (database/previous_records_columns.sql does
// the same by hand). DDL commits implicitly: call before beginTransaction().
//  - archived_at: a record removed from a profile goes to Archives first;
//    only an admin deletes it for good, from there.
//  - record_type_other: what an "Other" document is ("Referral letter").
// [column => bool]; false when the table is missing or that column couldn't
// be added — callers then skip it (every record counts as active).
function previousRecordExtraColumns(PDO $pdo): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    $want = ['archived_at' => 'DATETIME DEFAULT NULL', 'record_type_other' => 'VARCHAR(60) DEFAULT NULL'];
    $cols = array_fill_keys(array_keys($want), false);
    try {
        $have = $pdo->query('SHOW COLUMNS FROM previous_clinic_records')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($want as $c => $def) {
            if (!in_array($c, $have, true)) $pdo->exec("ALTER TABLE previous_clinic_records ADD COLUMN `$c` $def");
            $cols[$c] = true;
        }
    } catch (PDOException $e) {}
    return $cols;
}
function previousRecordArchiveColumn(PDO $pdo): bool {
    return previousRecordExtraColumns($pdo)['archived_at'];
}

// The JSON shape the frontend uses (patient.previousRecords[]).
function previousRecordShape(array $r, array $files): array {
    $eye = fn(string $s) => [
        'sph'  => $r[$s . '_sph']  ?? '',
        'cyl'  => $r[$s . '_cyl']  ?? '',
        'axis' => $r[$s . '_axis'] ?? '',
        'add'  => $r[$s . '_add']  ?? '',
        'va'   => $r[$s . '_va']   ?? '',
    ];
    return [
        'id'          => (int)$r['id'],
        'patientId'   => $r['patient_id'],
        'clinicName'  => $r['clinic_name'],
        'optometrist' => $r['optometrist'] ?? '',
        'date'        => $r['record_date'] ?? '',
        'type'        => in_array($r['record_type'] ?? '', PREV_RECORD_TYPES, true) ? $r['record_type'] : 'exam',
        // What an "Other" document is, as staff specified it ('' otherwise).
        'typeOther'   => ($r['record_type'] ?? '') === 'other' ? (string)($r['record_type_other'] ?? '') : '',
        'od'          => $eye('od'),
        'os'          => $eye('os'),
        'pd'          => $r['pd'] ?? '',
        'diagnosis'   => $r['diagnosis'] ?? '',
        'notes'       => $r['notes'] ?? '',
        'createdBy'   => $r['created_by'] ?? '',
        'createdAt'   => $r['created_at'] ?? '',
        'updatedBy'   => $r['updated_by'] ?? '',
        'updatedAt'   => $r['updated_at'] ?? '',
        'files'       => array_map(fn($f) => [
            'id'   => (int)$f['id'],
            'name' => $f['original_name'],
            'mime' => $f['mime_type'],
            'size' => (int)$f['size_bytes'],
        ], $files),
    ];
}

// Active (not archived) records with their files, grouped by patient id,
// newest exam date first (undated last). Only one patient's when $patientId
// is given. [] when the tables don't exist yet.
function previousRecordsByPatient(PDO $pdo, ?string $patientId = null): array {
    $active = previousRecordArchiveColumn($pdo) ? 'archived_at IS NULL' : '1';
    try {
        if ($patientId === null) {
            $records = $pdo->query("SELECT * FROM previous_clinic_records WHERE $active ORDER BY record_date IS NULL, record_date DESC, id DESC")->fetchAll();
            $files   = $records ? $pdo->query('SELECT id, record_id, original_name, mime_type, size_bytes FROM previous_clinic_files ORDER BY id')->fetchAll() : [];
        } else {
            $st = $pdo->prepare("SELECT * FROM previous_clinic_records WHERE patient_id = ? AND $active ORDER BY record_date IS NULL, record_date DESC, id DESC");
            $st->execute([$patientId]);
            $records = $st->fetchAll();
            $fs = $pdo->prepare('SELECT id, record_id, original_name, mime_type, size_bytes FROM previous_clinic_files WHERE patient_id = ? ORDER BY id');
            $fs->execute([$patientId]);
            $files = $fs->fetchAll();
        }
    } catch (PDOException) {
        return [];
    }
    $filesByRecord = [];
    foreach ($files as $f) $filesByRecord[(int)$f['record_id']][] = $f;
    $out = [];
    foreach ($records as $r) {
        $out[$r['patient_id']][] = previousRecordShape($r, $filesByRecord[(int)$r['id']] ?? []);
    }
    return $out;
}

// Deletes one archived record for good: its row (file rows go with it, ON
// DELETE CASCADE) and its uploaded files. Only reached from Settings >
// Archives (api/archive/delete.php). The record's patient id and clinic,
// or null when it was already gone.
function deletePreviousRecordPermanently(PDO $pdo, int $recordId): ?array {
    $st = $pdo->prepare('SELECT patient_id, clinic_name FROM previous_clinic_records WHERE id = ? LIMIT 1');
    $st->execute([$recordId]);
    $rec = $st->fetch();
    if (!$rec) return null;
    $fs = $pdo->prepare('SELECT stored_name FROM previous_clinic_files WHERE record_id = ?');
    $fs->execute([$recordId]);
    $stored = $fs->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM previous_clinic_records WHERE id = ?')->execute([$recordId]);
    try {
        $dir = previousRecordsDir($rec['patient_id']);
        foreach ($stored as $n) @unlink($dir . '/' . basename($n));
    } catch (InvalidArgumentException) {}
    return $rec;
}

// Removes every uploaded previous-clinic file of a patient. Used when the
// patient is permanently deleted (their rows go by ON DELETE CASCADE).
function deletePreviousRecordFiles(string $patientId): void {
    try { $dir = previousRecordsDir($patientId); } catch (InvalidArgumentException) { return; }
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($dir);
}

// Display name of whoever is signed in, e.g. "Dr. Lalaine Cana".
function sessionActorName(PDO $pdo): string {
    $role  = $_SESSION['role'] ?? '';
    $table = ['admin' => 'admins', 'staff' => 'staff', 'doctor' => 'doctors'][$role] ?? null;
    if ($table) {
        try {
            $st = $pdo->prepare("SELECT first_name, last_name FROM `$table` WHERE user_id = ? LIMIT 1");
            $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
            if ($r = $st->fetch()) return trim(($role === 'doctor' ? 'Dr. ' : '') . $r['first_name'] . ' ' . $r['last_name']);
        } catch (PDOException) {}
    }
    return ucfirst($role ?: 'staff');
}

// Writes one Activity Log entry for the signed-in user. Never fails the request.
function logActivity(PDO $pdo, string $action, string $type): void {
    try {
        $pdo->prepare('INSERT IGNORE INTO activity_log (id, users_id, user_name, role, action, timestamp, type, ip_address)
                       VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)')
            ->execute([
                substr('L' . date('YmdHis') . random_int(100, 999), 0, 20),
                ((int)($_SESSION['user_id'] ?? 0)) ?: null,
                sessionActorName($pdo),
                ucfirst($_SESSION['role'] ?? ''),
                $action,
                $type,
                clientIp(),
            ]);
    } catch (PDOException) {}
}

<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/update.php
//
//  POST { id, action, ...fields }
//
//  action:'status'             → { status, cancellationReason? }
//  action:'reschedule'         → { date, time, rescheduleNote? }
//  action:'request_reschedule' → { reason, preferredDate? }
//  action:'dismiss_reschedule' → (no extra fields)
//  action:'withdraw_reschedule_request' — patient takes back their own pending request
//  action:'respond_reschedule'   → { accept, reason? } — patient answers a clinic-proposed new slot
//  action:'withdraw_reschedule_proposal' — admin/staff cancel an unanswered proposal
//  action:'change_pending'     → { date, time, doctorId?, notes? } — patient edits their own still-pending request
//  action:'revert_no_show'     → { reason }  — admin/staff; no-show → approved
//  action:'assign_doctor'      → { doctorId }  — admin/staff only, for
//                                 "any available optometrist" requests
//                                 (doctor_id still NULL)
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role      = $_SESSION['role']       ?? '';
$profileId = $_SESSION['profile_id'] ?? '';
$userId    = (int)$_SESSION['user_id'];

$b      = getBody();
$id     = trim($b['id']     ?? '');
$action = trim($b['action'] ?? '');

if (!$id || !$action) {
    jsonResponse(['success' => false, 'message' => 'id and action are required.']);
}

try {
    $pdo = getDB();

    // Load current appointment
    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $appt = $stmt->fetch();
    if (!$appt) {
        jsonResponse(['success' => false, 'message' => 'Appointment not found.']);
    }

    // Access control: patients may only touch their own appointments
    if ($role === 'patient' && $appt['patient_id'] !== $profileId) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    }
    // Doctors may only touch their own appointments (for status changes)
    if ($role === 'doctor' && $appt['doctor_id'] !== $profileId) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    }

    // Helper: get patient's user_id for notification targeting
    $getPatientUserId = function() use ($pdo, $appt): ?int {
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$appt['patient_id']]);
        $row = $ps->fetch();
        return ($row && $row['user_id']) ? (int)$row['user_id'] : null;
    };

    // ── Admin/staff: revert a no-show ────────────────────────────────────
    // For when the patient DID attend but the exam wasn't saved before the
    // appointment's date passed, so it was auto-marked a no-show. Puts it
    // back to approved (exempt from the auto no-show via no_show_reverted_at,
    // so the exam can still be recorded against it — saving that exam then
    // completes it), takes the no-show back off the patient's tally, and
    // lifts a booking restriction that tally no longer justifies.
    if ($action === 'revert_no_show') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Only admin or staff can revert a no-show.'], 403);
        }
        if ($appt['status'] !== 'no-show') {
            jsonResponse(['success' => false, 'message' => 'Only appointments marked as a no-show can be reverted.']);
        }
        $reason = trim($b['reason'] ?? '');
        if ($reason === '') {
            jsonResponse(['success' => false, 'message' => 'Please enter a reason for reverting this no-show.']);
        }

        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE appointments SET status = 'approved', no_show_reverted_at = NOW(), no_show_revert_reason = ?
             WHERE id = ? AND status = 'no-show'"
        )->execute([$reason, $id]);

        $restrictionLifted = false;
        if ($appt['patient_id']) {
            $pdo->prepare('UPDATE patients SET no_show_count = GREATEST(no_show_count - 1, 0) WHERE id = ?')
                ->execute([$appt['patient_id']]);
            $ps = $pdo->prepare('SELECT no_show_count, booking_restricted FROM patients WHERE id = ? LIMIT 1');
            $ps->execute([$appt['patient_id']]);
            $pRow = $ps->fetch();
            if ($pRow && $pRow['booking_restricted'] && (int)$pRow['no_show_count'] < NO_SHOW_RESTRICTION_THRESHOLD) {
                $pdo->prepare('UPDATE patients SET booking_restricted = 0 WHERE id = ?')->execute([$appt['patient_id']]);
                $restrictionLifted = true;
            }
        }
        $pdo->commit();

        // The patient was told it was a no-show — tell them it's corrected.
        $patientUid = $getPatientUserId();
        if ($patientUid) {
            $fmtDate = date('M j, Y', strtotime($appt['date']));
            $msg = "Your appointment on {$fmtDate} at {$appt['time']} is no longer marked as a missed appointment (no-show). It has been removed from your record."
                 . ($restrictionLifted ? ' You can book appointments online again.' : '');
            createNotification($pdo, $patientUid, 'approved', 'No-Show Corrected', $msg, $id);
            _emailPatientNotice($pdo, $patientUid, 'No-Show Corrected', $msg,
                appointmentLinkUrl($id), 'View Appointment', $appt['date'], 'Note from the Clinic', $reason, 'neutral');
        }

        // The appointment's doctor is the one who records the missing exam
        // (staff can't) — let them know it's open again.
        if ($appt['doctor_id']) {
            $ds = $pdo->prepare('SELECT user_id FROM doctors WHERE id = ? LIMIT 1');
            $ds->execute([$appt['doctor_id']]);
            if ($docUid = (int)$ds->fetchColumn()) {
                $when = date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}";
                createNotification($pdo, $docUid, 'approved', 'No-Show Reverted',
                    "{$appt['patient_name']}'s appointment on {$when} was reverted from no-show to approved. You can now record the examination for this visit. Reason: {$reason}", $id);
            }
        }

        jsonResponse(['success' => true, 'restrictionLifted' => $restrictionLifted]);
    }

    // ── Status change (approve / cancel / disapprove / completed) ──
    if ($action === 'status') {
        $newStatus = trim($b['status'] ?? '');
        $allowed   = ['pending', 'approved', 'cancelled', 'disapproved', 'completed', 'no-show'];
        if (!in_array($newStatus, $allowed, true)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status.']);
        }
        $cancelReason      = trim($b['cancellationReason'] ?? '');
        $disapprovalReason = trim($b['disapprovalReason'] ?? '');

        // Patients may only cancel their own
        if ($role === 'patient' && !in_array($newStatus, ['cancelled'], true)) {
            jsonResponse(['success' => false, 'message' => 'Patients may only cancel appointments.'], 403);
        }

        // An "any available optometrist" request (doctor_id still NULL — see
        // appointments/create.php's anyDoctor path) needs an actual doctor
        // assigned (action=assign_doctor) before it can be approved — mirrors
        // the disabled Approve button in the frontend's appointment detail
        // modal, enforced here too so a direct API call can't skip it.
        if ($newStatus === 'approved' && !$appt['doctor_id']) {
            jsonResponse(['success' => false, 'message' => 'Please assign a doctor to this appointment before approving it.']);
        }

        // Only an approved appointment can be a no-show — a pending request
        // was never confirmed (and expires as "disapproved", not a no-show),
        // so it must never count against the patient's no-show tally.
        if ($newStatus === 'no-show' && $appt['status'] !== 'approved') {
            jsonResponse(['success' => false, 'message' => 'Only approved appointments can be marked as a no-show.']);
        }

        // A request can't be approved once its appointment time has passed —
        // it's auto-declined at that point (expireUnactionedPending()). Staff
        // can reschedule it to a new time instead, or book a fresh one.
        if ($newStatus === 'approved' && $appt['status'] === 'pending') {
            $apptDt = DateTime::createFromFormat('Y-m-d g:i A', $appt['date'] . ' ' . $appt['time']);
            if ($apptDt && time() >= $apptDt->getTimestamp()) {
                jsonResponse(['success' => false, 'message' => 'This request\'s appointment time has already passed, so it can no longer be approved. Book a new appointment for the patient instead.']);
            }
        }

        // Patients can cancel online right up to the appointment time — no
        // 24-hour rule (no down payment to protect; cancelling beats a
        // no-show). Only a visit whose time has passed is refused. Mirrors
        // pages.js's CANCEL_DEADLINE_HOURS so a direct API call can't bypass it.
        if ($role === 'patient' && $newStatus === 'cancelled') {
            $apptDt = DateTime::createFromFormat('Y-m-d g:i A', $appt['date'] . ' ' . $appt['time']);
            if ($apptDt && (time() > $apptDt->getTimestamp() - PATIENT_CHANGE_CUTOFF_HOURS * 3600)) {
                jsonResponse(['success' => false, 'message' => "This appointment's time has already passed, so it can no longer be cancelled online. Please contact the clinic directly."]);
            }
        }

        // Deciding the appointment's status (approve/disapprove/cancel/complete)
        // supersedes any reschedule request still sitting on it — otherwise the
        // "Reschedule Req." flag keeps showing after the appointment has already
        // moved on, even though nobody addressed the patient's actual ask.
        $pdo->prepare(
            'UPDATE appointments SET status = ?, cancellation_reason = ?, disapproval_reason = ?, reschedule_request = NULL, reschedule_proposal = NULL WHERE id = ?'
        )->execute([$newStatus, $cancelReason ?: null, $newStatus === 'disapproved' ? ($disapprovalReason ?: null) : null, $id]);

        // Update last_visit on patient if completed
        if ($newStatus === 'completed' && $appt['patient_id']) {
            $pdo->prepare('UPDATE patients SET last_visit = ? WHERE id = ?')
                ->execute([$appt['date'], $appt['patient_id']]);
        }

        // Manually marking a no-show (same-day, before the auto-transition in
        // appointments/index.php would otherwise catch it tomorrow) counts
        // against the patient the same way the automatic path does.
        $justRestricted = false;
        if ($newStatus === 'no-show' && $appt['patient_id']) {
            $justRestricted = recordNoShow($pdo, $appt['patient_id']);
        }

        // A patient cancelling their own appointment — tell the clinic (every
        // admin/staff account) and the assigned doctor, with the patient's
        // reason, so the freed slot doesn't go unnoticed.
        if ($role === 'patient' && $newStatus === 'cancelled') {
            $doctorLbl = $appt['doctor_name'] ?: 'no doctor assigned yet';
            $when      = date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}";
            $clinicMsg = "{$appt['patient_name']} cancelled their appointment ({$doctorLbl}) on {$when}."
                       . ($cancelReason ? " Reason: {$cancelReason}" : '');
            notifyAdminStaff($pdo, 'cancelled', 'Appointment Cancelled by Patient', $clinicMsg, $id);
            if ($appt['doctor_id']) {
                $ds = $pdo->prepare('SELECT user_id FROM doctors WHERE id = ? LIMIT 1');
                $ds->execute([$appt['doctor_id']]);
                if ($docUid = (int)$ds->fetchColumn()) {
                    createNotification($pdo, $docUid, 'cancelled', 'Appointment Cancelled by Patient', $clinicMsg, $id);
                }
            }
        }

        // Notify the patient of every clinic-side status change (not their own
        // cancel), in-app and by email. Every email names the doctor, date
        // AND time, carries a "View Appointment" link into the patient portal,
        // and puts any staff-entered reason in its own highlighted box
        // (systemEmailBody) — the in-app line keeps it inline since it has no
        // layout to separate it into. Approved also gets Add to Google Calendar.
        if ($role !== 'patient' && $patientUid = $getPatientUserId()) {
            $fmtDate = date('M j, Y', strtotime($appt['date']));
            $doctor  = $appt['doctor_name'] ?: 'your doctor';
            $when    = "{$fmtDate} at {$appt['time']}";
            $viewUrl = appointmentLinkUrl($appt['id']);
            if ($newStatus === 'approved') {
                $approveMsg = "Your appointment with {$doctor} on {$when} has been approved.";
                createNotification($pdo, $patientUid, 'approved', 'Appointment Approved', $approveMsg, $appt['id']);
                _emailPatientNotice($pdo, $patientUid, 'Appointment Approved', $approveMsg, $viewUrl, 'View Appointment', $appt['date'],
                    calendarUrl: googleCalendarUrl($pdo, $appt['date'], $appt['time'], $appt['doctor_name'], $appt['type'] ?? null));
            } elseif ($newStatus === 'cancelled') {
                $cancelMsg = "Your appointment with {$doctor} on {$when} has been cancelled by the clinic.";
                createNotification($pdo, $patientUid, 'cancelled', 'Appointment Cancelled',
                    $cancelMsg . ($cancelReason ? " Reason: {$cancelReason}" : ''), $appt['id']);
                _emailPatientNotice($pdo, $patientUid, 'Appointment Cancelled',
                    $cancelMsg . ' You can book a new appointment anytime from your account.',
                    $viewUrl, 'View Appointment', $appt['date'], 'Reason for Cancellation', $cancelReason);
            } elseif ($newStatus === 'disapproved') {
                $disapproveMsg = "Your appointment request with {$doctor} on {$when} could not be approved.";
                createNotification($pdo, $patientUid, 'disapproved', 'Appointment Not Approved',
                    $disapproveMsg . ($disapprovalReason ? " Reason: {$disapprovalReason}" : ''), $appt['id']);
                _emailPatientNotice($pdo, $patientUid, 'Appointment Not Approved',
                    $disapproveMsg . ' You can submit a new request for another date or time from your account.',
                    $viewUrl, 'View Appointment', $appt['date'], 'Reason for Disapproval', $disapprovalReason);
            } elseif ($newStatus === 'no-show') {
                notifyPatientNoShow($pdo, $patientUid, $appt, $justRestricted);
            }
        }

        jsonResponse(['success' => true]);
    }

    // ── Admin/staff reschedule (change date + time) ────────────────
    if ($action === 'reschedule') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $newDate = trim($b['date'] ?? '');
        $newTime = trim($b['time'] ?? '');
        $note    = trim($b['rescheduleNote'] ?? '');
        // True when this reschedule is fulfilling a specific patient-submitted
        // request (the "Accept & Reschedule" button), as opposed to an ad-hoc
        // reschedule the staff/admin initiated on their own.
        $fulfillRequest = !empty($b['fulfillRequest']);
        if (!$newDate || !$newTime) {
            jsonResponse(['success' => false, 'message' => 'Date and time are required.']);
        }
        if ($closed = clinicClosureOn($pdo, $newDate)) {
            jsonResponse(['success' => false, 'message' => clinicClosedMessage($closed)]);
        }
        // A clinic-initiated move needs a reason for the patient's email;
        // fulfilling the patient's own request already has theirs.
        if (!$fulfillRequest && $note === '') {
            jsonResponse(['success' => false, 'message' => 'Please enter a reason for rescheduling.']);
        }

        // Conflict check — exclude the appointment being rescheduled itself.
        $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
        preg_match('/(\d+)/', $durStr ?: '45', $dm);
        $durationMin = isset($dm[1]) ? (int)$dm[1] : 45;
        $conflict = checkApptConflict($pdo, $appt['doctor_id'], $newDate, $newTime, $durationMin, $id);
        if ($conflict !== null) {
            jsonResponse(['success' => false, 'message' =>
                "This time conflicts with an existing appointment at {$conflict}. "
              . "Please choose a different slot."]);
        }
        // The patient's own other appointments (any doctor) count too.
        $ownConflict = checkPatientApptConflict($pdo, (string)$appt['patient_id'], $newDate, $newTime, $durationMin, $id);
        if ($ownConflict !== null) {
            jsonResponse(['success' => false, 'message' =>
                "This patient already has another appointment at {$ownConflict} on that date. Please choose a different slot."]);
        }

        // Clinic-initiated reschedule of a patient who has an account: don't
        // move the appointment yet — propose the new slot and let the patient
        // accept or decline it (mirror of the patient's own reschedule
        // request, which the clinic approves). Applied right away instead
        // when fulfilling the patient's own request, when the patient has no
        // account to answer from, or when staff tick "Patient already agreed".
        $applyNow   = !empty($b['applyNow']);
        $patientUid = $getPatientUserId();
        if (!$fulfillRequest && !$applyNow && $patientUid) {
            $proposal = json_encode([
                'date' => $newDate,
                'time' => $newTime,
                'note' => $note,
                'by'   => _apptActorName($pdo, $role),
                'at'   => date('Y-m-d H:i'),
            ]);
            $pdo->prepare('UPDATE appointments SET reschedule_proposal = ?, reschedule_request = NULL WHERE id = ?')
                ->execute([$proposal, $id]);

            $oldWhen = date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}";
            $newWhen = date('M j, Y', strtotime($newDate)) . " at {$newTime}";
            $doctor  = $appt['doctor_name'] ?: 'your doctor';
            $base = "The clinic would like to move your appointment with {$doctor} from {$oldWhen} to {$newWhen}. "
                  . "Please accept or decline the new schedule in My Appointments. Until you answer, your appointment stays on {$oldWhen}.";
            createNotification($pdo, $patientUid, 'reschedule_proposed', 'New Schedule Proposed', $base . ($note ? " Reason: {$note}" : ''), $id);
            _emailPatientNotice(
                $pdo, $patientUid, 'New Schedule Proposed', $base,
                appointmentLinkUrl($id), 'Review New Schedule', $newDate, 'Reason for Reschedule', $note, 'neutral'
            );
            jsonResponse(['success' => true, 'proposed' => true]);
        }

        if ($fulfillRequest) {
            // Only apply if the request is still pending — guards against the
            // same request being accepted twice from two different sessions/tabs
            // (e.g. admin and staff both viewing it), which would otherwise let
            // a stale second click silently overwrite the already-applied change.
            $upd = $pdo->prepare(
                'UPDATE appointments SET date = ?, time = ?, reschedule_note = ?, reschedule_request = NULL, reschedule_proposal = NULL, rescheduled_at = NOW(),
                        reminder_sent_at = NULL, followup_reminder_sent_at = NULL, confirmed_at = NULL
                 WHERE id = ? AND reschedule_request IS NOT NULL'
            );
            $upd->execute([$newDate, $newTime, $note ?: null, $id]);
            if ($upd->rowCount() === 0) {
                jsonResponse(['success' => false, 'message' => 'This reschedule request was already handled.']);
            }
        } else {
            $pdo->prepare(
                'UPDATE appointments SET date = ?, time = ?, reschedule_note = ?, reschedule_request = NULL, reschedule_proposal = NULL, rescheduled_at = NOW(),
                        reminder_sent_at = NULL, followup_reminder_sent_at = NULL, confirmed_at = NULL WHERE id = ?'
            )->execute([$newDate, $newTime, $note ?: null, $id]);
        }

        // Notify patient of reschedule — email too, not just in-app, since
        // showing up at the old date/time (rather than just missing a
        // "good to know" update) is a real, tangible consequence of not
        // seeing this in time. Same reasoning as the cron-reminder emails.
        if ($patientUid = $getPatientUserId()) {
            $oldWhen = date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}";
            $newWhen = date('M j, Y', strtotime($newDate)) . " at {$newTime}";
            $doctor  = $appt['doctor_name'] ?: 'your doctor';
            $rescheduleBase = "Your appointment with {$doctor} has been rescheduled from {$oldWhen} to {$newWhen}"
                . ($fulfillRequest ? ', as you requested.' : '.');
            // In-app notification stays a single plain-text line (no layout
            // to separate a note into), so the note is appended inline there.
            // The email keeps it in its own highlighted box instead — same
            // treatment cancellation/disapproval reasons get — in a neutral
            // orange tone, since a reschedule note isn't bad news.
            createNotification($pdo, $patientUid, 'rescheduled', 'Appointment Rescheduled', $rescheduleBase . ($note ? ($fulfillRequest ? ' Note: ' : ' Reason: ') . $note : ''), $id);
            // View Appointment, plus Add to Google Calendar for the NEW
            // date/time (the old calendar entry, if saved, is now outdated).
            _emailPatientNotice(
                $pdo, $patientUid, 'Appointment Rescheduled',
                $rescheduleBase . ' If you added the old time to your calendar, please update it.',
                appointmentLinkUrl($id), 'View Appointment', $newDate, $fulfillRequest ? 'Note from the Clinic' : 'Reason for Reschedule', $note, 'neutral',
                googleCalendarUrl($pdo, $newDate, $newTime, $appt['doctor_name'], $appt['type'] ?? null)
            );
        }

        jsonResponse(['success' => true]);
    }

    // ── Patient requests a reschedule ──────────────────────────────
    // ── Patient: change the date/time of a still-PENDING request ────
    // Nothing has been approved yet, so there's no reschedule round-trip:
    // the new date/time simply replaces the old one and the request stays
    // pending for the clinic to review. Same checks as a new booking
    // (api/appointments/create.php), ignoring this appointment's own slot.
    if ($action === 'change_pending') {
        if ($role !== 'patient') {
            jsonResponse(['success' => false, 'message' => 'Only the patient can change their own pending request.'], 403);
        }
        if ($appt['status'] !== 'pending') {
            jsonResponse(['success' => false, 'message' => 'Only a pending request can be changed directly. For an approved appointment, request a reschedule instead.']);
        }
        $date = trim($b['date'] ?? '');
        $time = trim($b['time'] ?? '');
        $dt   = DateTime::createFromFormat('Y-m-d g:i A', $date . ' ' . $time);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            jsonResponse(['success' => false, 'message' => 'Please choose a valid date and time.']);
        }
        if ($dt->getTimestamp() <= time()) {
            jsonResponse(['success' => false, 'message' => 'Please choose a date and time that hasn\'t passed.']);
        }
        if ($closed = clinicClosureOn($pdo, $date)) {
            jsonResponse(['success' => false, 'message' => clinicClosedMessage($closed)]);
        }
        // Doctor: sent as '' for "Any available optometrist"; left out = unchanged.
        $newDocId = array_key_exists('doctorId', $b) ? trim((string)$b['doctorId']) : (string)($appt['doctor_id'] ?? '');
        $newDocName = null;
        if ($newDocId !== '') {
            $ds = $pdo->prepare("SELECT first_name, middle_name, last_name FROM doctors WHERE id = ? AND archived_at IS NULL AND status = 'active' LIMIT 1");
            $ds->execute([$newDocId]);
            $dr = $ds->fetch();
            if (!$dr) jsonResponse(['success' => false, 'message' => 'That doctor is not available for booking. Please choose another.']);
            $newDocName = trim('Dr. ' . $dr['first_name'] . _mi($dr['middle_name'] ?? '') . ' ' . $dr['last_name']);
        }
        $notes = array_key_exists('notes', $b) ? mb_substr(trim((string)$b['notes']), 0, 500) : (string)($appt['notes'] ?? '');

        $docChanged   = $newDocId !== (string)($appt['doctor_id'] ?? '');
        $whenChanged  = $date !== $appt['date'] || $time !== $appt['time'];
        $notesChanged = $notes !== (string)($appt['notes'] ?? '');
        if (!$docChanged && !$whenChanged && !$notesChanged) {
            jsonResponse(['success' => false, 'message' => 'Nothing was changed.']);
        }
        $minDays = minAdvanceBookingDays($pdo);
        $daysOut = (int)(new DateTime('today'))->diff(new DateTime($date))->format('%r%a');
        if ($daysOut < $minDays) {
            jsonResponse(['success' => false, 'message' => "Appointments must be booked at least {$minDays} day" . ($minDays === 1 ? '' : 's') . ' in advance.']);
        }

        $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
        preg_match('/(\d+)/', $durStr ?: '45', $dm);
        $durationMin = isset($dm[1]) ? (int)$dm[1] : 45;
        $maxPerDay   = (int)($pdo->query('SELECT max_appts_per_doctor_per_day FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn() ?: 12);
        $dayCount = function (string $docId) use ($pdo, $date, $id): int {
            $s = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND date = ? AND id <> ? AND status NOT IN ('cancelled','disapproved')");
            $s->execute([$docId, $date, $id]);
            return (int)$s->fetchColumn();
        };

        $own = checkPatientApptConflict($pdo, (string)$appt['patient_id'], $date, $time, $durationMin, $id);
        if ($own !== null) {
            jsonResponse(['success' => false, 'message' => "You already have an appointment at {$own} on this date. Please choose a different time."]);
        }

        if ($newDocId !== '') {
            $docId = $newDocId;
            $bs = $pdo->prepare('SELECT reason FROM blocked_dates WHERE doctor_id = ? AND date = ? LIMIT 1');
            $bs->execute([$docId, $date]);
            if ($blocked = $bs->fetch()) {
                jsonResponse(['success' => false, 'message' => 'This doctor is unavailable on the selected date' . ($blocked['reason'] ? " ({$blocked['reason']})" : '') . '. Please choose another date.']);
            }
            if ($problem = doctorScheduleProblem($pdo, $docId, $date, $time)) {
                jsonResponse(['success' => false, 'message' => $problem]);
            }
            if ($dayCount($docId) >= $maxPerDay) {
                jsonResponse(['success' => false, 'message' => 'This doctor is fully booked on the selected date. Please choose another date.']);
            }
            if (($conflict = checkApptConflict($pdo, $docId, $date, $time, $durationMin, $id)) !== null) {
                jsonResponse(['success' => false, 'message' => "This time conflicts with an existing appointment at {$conflict}. Please choose a different slot."]);
            }
        } else {
            // "Any available optometrist" — some doctor must be free then.
            $free = false;
            foreach (eligibleDoctorsForDate($pdo, $date, $time) as $doc) {
                if ($dayCount($doc['id']) >= $maxPerDay) continue;
                if (checkApptConflict($pdo, $doc['id'], $date, $time, $durationMin, $id) !== null) continue;
                $free = true; break;
            }
            if (!$free) {
                jsonResponse(['success' => false, 'message' => 'No doctor is available at that time. Please choose a different date or slot.']);
            }
        }

        $pdo->prepare("UPDATE appointments SET date = ?, time = ?, doctor_id = ?, doctor_name = ?, notes = ? WHERE id = ? AND status = 'pending'")
            ->execute([$date, $time, $newDocId !== '' ? $newDocId : null, $newDocName, $notes, $id]);

        // The clinic reviews pending requests — tell them exactly what changed.
        $fmt = fn($d, $t) => date('M j, Y', strtotime($d)) . " at {$t}";
        $anyLbl = 'Any available optometrist';
        $parts = [];
        if ($docChanged)   $parts[] = 'doctor: ' . (($appt['doctor_name'] ?? '') ?: $anyLbl) . ' → ' . ($newDocName ?: $anyLbl);
        if ($whenChanged)  $parts[] = 'schedule: ' . $fmt($appt['date'], $appt['time']) . ' → ' . $fmt($date, $time);
        if ($notesChanged) $parts[] = 'notes updated';
        notifyAdminStaff($pdo, 'new_appointment', 'Pending Request Updated',
            "{$appt['patient_name']} updated their pending request (" . implode('; ', $parts) . ').', $id);

        jsonResponse(['success' => true, 'date' => $date, 'time' => $time, 'doctorId' => $newDocId, 'doctorName' => $newDocName, 'notes' => $notes]);
    }

    if ($action === 'request_reschedule') {
        if ($role !== 'patient') {
            jsonResponse(['success' => false, 'message' => 'Only patients may submit reschedule requests.'], 403);
        }

        // Same as cancellation (see the 'cancelled' branch above): allowed
        // until the appointment time. Mirrors pages.js's RESCHEDULE_DEADLINE_HOURS.
        $apptDt = DateTime::createFromFormat('Y-m-d g:i A', $appt['date'] . ' ' . $appt['time']);
        if ($apptDt && (time() > $apptDt->getTimestamp() - PATIENT_CHANGE_CUTOFF_HOURS * 3600)) {
            jsonResponse(['success' => false, 'message' => "This appointment's time has already passed, so it can no longer be rescheduled online. Please contact the clinic directly."]);
        }

        $reason   = trim($b['reason']        ?? '');
        $prefDate = trim($b['preferredDate'] ?? '');
        $prefTime = trim($b['preferredTime'] ?? '');
        if (!$reason) {
            jsonResponse(['success' => false, 'message' => 'Please provide a reason.']);
        }
        if ($prefDate && ($closed = clinicClosureOn($pdo, $prefDate))) {
            jsonResponse(['success' => false, 'scheduleChanged' => true, 'message' => clinicClosedMessage($closed)]);
        }
        // The preferred slot must fit the doctor's current schedule (days,
        // hours, blocked dates) — the modal's slot list may be stale.
        if ($prefDate && $prefTime && $appt['doctor_id']) {
            $bs = $pdo->prepare('SELECT reason FROM blocked_dates WHERE doctor_id = ? AND date = ? LIMIT 1');
            $bs->execute([$appt['doctor_id'], $prefDate]);
            if ($blk = $bs->fetch()) {
                jsonResponse(['success' => false, 'scheduleChanged' => true, 'message' =>
                    'This doctor is unavailable on the selected date' . ($blk['reason'] ? " ({$blk['reason']})" : '') . '. Please choose another date.']);
            }
            if ($schedProblem = doctorScheduleProblem($pdo, (string)$appt['doctor_id'], $prefDate, $prefTime)) {
                jsonResponse(['success' => false, 'scheduleChanged' => true, 'message' => $schedProblem]);
            }
            // The requested slot is held for this patient while the clinic
            // decides (heldProposalTimes()), so it must be free right now —
            // including slots other pending reschedules are already holding.
            $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
            preg_match('/(\d+)/', $durStr ?: '45', $dm);
            $durationMin = isset($dm[1]) ? (int)$dm[1] : 45;
            if (($taken = checkApptConflict($pdo, (string)$appt['doctor_id'], $prefDate, $prefTime, $durationMin, $id)) !== null) {
                jsonResponse(['success' => false, 'scheduleChanged' => true, 'message' =>
                    "That time was just taken (conflicts with {$taken}). Please choose another preferred time."]);
            }
        }
        $payload = json_encode([
            'reason'        => $reason,
            'preferredDate' => $prefDate ?: null,
            'preferredTime' => $prefTime ?: null,
            'requestedAt'   => date('Y-m-d H:i'),
        ]);
        $pdo->prepare('UPDATE appointments SET reschedule_request = ? WHERE id = ?')
            ->execute([$payload, $id]);

        // Notify admin/staff of the reschedule request
        $patName = $appt['patient_name'] ?? 'A patient';
        $prefSlot = $prefDate ? (" Preferred: {$prefDate}" . ($prefTime ? " at {$prefTime}" : '') . '.') : '';
        notifyAdminStaff($pdo, 'reschedule_request',
            'Reschedule Request',
            "{$patName} has requested to reschedule appointment #{$id}.{$prefSlot}",
            $id
        );

        jsonResponse(['success' => true]);
    }

    // ── Patient withdraws their own reschedule request ─────────────
    // Before the clinic acts on it — the appointment simply stays as it is,
    // and the held preferred slot is released.
    if ($action === 'withdraw_reschedule_request') {
        if ($role !== 'patient') {
            jsonResponse(['success' => false, 'message' => 'Only the patient can withdraw their reschedule request.'], 403);
        }
        $upd = $pdo->prepare('UPDATE appointments SET reschedule_request = NULL WHERE id = ? AND reschedule_request IS NOT NULL');
        $upd->execute([$id]);
        if ($upd->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'This reschedule request was already handled.']);
        }
        notifyAdminStaff($pdo, 'reschedule_request', 'Reschedule Request Withdrawn',
            ($appt['patient_name'] ?? 'A patient') . " withdrew their reschedule request for appointment #{$id}. It stays on "
            . date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}.", $id);
        jsonResponse(['success' => true]);
    }

    // ── Patient answers a clinic-proposed new schedule ─────────────
    // accept → the appointment moves to the proposed slot (re-checked for
    // conflicts first, since time has passed); decline → the proposal is
    // dropped and the original slot stands. Either way the clinic is told.
    if ($action === 'respond_reschedule') {
        if ($role !== 'patient') {
            jsonResponse(['success' => false, 'message' => 'Only the patient can answer a proposed schedule.'], 403);
        }
        $prop = json_decode($appt['reschedule_proposal'] ?? '', true);
        if (!is_array($prop) || empty($prop['date']) || empty($prop['time'])) {
            jsonResponse(['success' => false, 'message' => 'This proposed schedule is no longer pending.']);
        }
        $accept   = !empty($b['accept']);
        $reason   = trim($b['reason'] ?? '');
        $patName  = $appt['patient_name'] ?? 'The patient';
        $oldWhen  = date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}";
        $newWhen  = date('M j, Y', strtotime($prop['date'])) . " at {$prop['time']}";

        if ($accept) {
            if ($closed = clinicClosureOn($pdo, $prop['date'])) {
                jsonResponse(['success' => false, 'message' => 'The clinic has since closed on that date' . ($closed['reason'] ? " ({$closed['reason']})" : '') . '. Please contact the clinic for a new time.']);
            }
            $propDt = DateTime::createFromFormat('Y-m-d g:i A', $prop['date'] . ' ' . $prop['time']);
            if ($propDt && $propDt->getTimestamp() <= time()) {
                $pdo->prepare('UPDATE appointments SET reschedule_proposal = NULL WHERE id = ?')->execute([$id]);
                jsonResponse(['success' => false, 'message' => 'That proposed time has already passed. Your appointment stays as it was — please contact the clinic for a new time.']);
            }
            $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
            preg_match('/(\d+)/', $durStr ?: '45', $dm);
            $durationMin = isset($dm[1]) ? (int)$dm[1] : 45;
            if (checkApptConflict($pdo, $appt['doctor_id'], $prop['date'], $prop['time'], $durationMin, $id) !== null
                || checkPatientApptConflict($pdo, (string)$appt['patient_id'], $prop['date'], $prop['time'], $durationMin, $id) !== null) {
                jsonResponse(['success' => false, 'message' => 'That proposed time is no longer available. Your appointment stays as it was — please contact the clinic.']);
            }
            $upd = $pdo->prepare(
                'UPDATE appointments SET date = ?, time = ?, reschedule_note = ?, reschedule_proposal = NULL, rescheduled_at = NOW(),
                        reminder_sent_at = NULL, followup_reminder_sent_at = NULL, confirmed_at = NULL
                 WHERE id = ? AND reschedule_proposal IS NOT NULL'
            );
            $upd->execute([$prop['date'], $prop['time'], ($prop['note'] ?? '') ?: null, $id]);
            if ($upd->rowCount() === 0) {
                jsonResponse(['success' => false, 'message' => 'This proposed schedule is no longer pending.']);
            }
            notifyAdminStaff($pdo, 'reschedule_accepted', 'Patient Accepted New Schedule',
                "{$patName} accepted the new schedule for appointment #{$id}: moved from {$oldWhen} to {$newWhen}.", $id);
            if ($patientUid = $getPatientUserId()) {
                _emailPatientNotice(
                    $pdo, $patientUid, 'Appointment Rescheduled',
                    "Your appointment with " . ($appt['doctor_name'] ?: 'your doctor') . " is now on {$newWhen}, as you accepted. If you added the old time to your calendar, please update it.",
                    appointmentLinkUrl($id), 'View Appointment', $prop['date'], '', '', 'neutral',
                    googleCalendarUrl($pdo, $prop['date'], $prop['time'], $appt['doctor_name'], $appt['type'] ?? null)
                );
            }
            jsonResponse(['success' => true, 'accepted' => true, 'date' => $prop['date'], 'time' => $prop['time']]);
        }

        $pdo->prepare('UPDATE appointments SET reschedule_proposal = NULL WHERE id = ?')->execute([$id]);
        notifyAdminStaff($pdo, 'reschedule_declined', 'Patient Declined New Schedule',
            "{$patName} declined moving appointment #{$id} to {$newWhen}. It stays on {$oldWhen}." . ($reason ? " Reason: {$reason}" : ''), $id);
        jsonResponse(['success' => true, 'accepted' => false]);
    }

    // ── Admin/staff withdraw a proposal the patient hasn't answered ──
    if ($action === 'withdraw_reschedule_proposal') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $pdo->prepare('UPDATE appointments SET reschedule_proposal = NULL WHERE id = ?')->execute([$id]);
        if ($patientUid = $getPatientUserId()) {
            createNotification($pdo, $patientUid, 'rescheduled', 'Proposed Schedule Withdrawn',
                'The clinic withdrew its proposed new schedule. Your appointment stays on '
                . date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}.", $id);
        }
        jsonResponse(['success' => true]);
    }

    // ── Admin/staff decline (dismiss) a patient's reschedule request ──
    // The appointment itself stays as-is; the patient is told, with the
    // staff-entered reason, so the request doesn't just silently vanish.
    if ($action === 'dismiss_reschedule') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $reason = trim($b['reason'] ?? '');
        $upd = $pdo->prepare('UPDATE appointments SET reschedule_request = NULL WHERE id = ? AND reschedule_request IS NOT NULL');
        $upd->execute([$id]);
        if ($upd->rowCount() > 0 && $patientUid = $getPatientUserId()) {
            $doctor = $appt['doctor_name'] ?: 'your doctor';
            $when   = date('M j, Y', strtotime($appt['date'])) . " at {$appt['time']}";
            $msg    = "Your request to reschedule your appointment with {$doctor} couldn't be accommodated. Your appointment stays on {$when}.";
            createNotification($pdo, $patientUid, 'reschedule_request', 'Reschedule Request Declined',
                $msg . ($reason ? " Reason: {$reason}" : ''), $id);
            _emailPatientNotice($pdo, $patientUid, 'Reschedule Request Declined',
                $msg . ' If you can no longer make it, please contact the clinic.',
                appointmentLinkUrl($id), 'View Appointment', $appt['date'], 'Reason', $reason);
        }
        jsonResponse(['success' => true]);
    }

    // ── Admin/staff assign a doctor to an "any available optometrist" ──
    // request (created with doctor_id NULL — see appointments/create.php's
    // anyDoctor path). Only valid while no doctor is assigned yet, so this
    // can't be used to silently swap a patient's already-chosen doctor
    // (that's what 'reschedule' plus picking a new doctor would be for).
    if ($action === 'assign_doctor') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        if ($appt['doctor_id']) {
            jsonResponse(['success' => false, 'message' => 'This appointment already has a doctor assigned.']);
        }
        $newDoctorId = trim($b['doctorId'] ?? '');
        if (!$newDoctorId) {
            jsonResponse(['success' => false, 'message' => 'Please choose a doctor.']);
        }
        $ds = $pdo->prepare('SELECT first_name, middle_name, last_name FROM doctors WHERE id = ? AND status = ? LIMIT 1');
        $ds->execute([$newDoctorId, 'active']);
        $doc = $ds->fetch();
        if (!$doc) {
            jsonResponse(['success' => false, 'message' => 'Doctor not found.']);
        }
        $newDoctorName = 'Dr. ' . trim($doc['first_name'] . _mi($doc['middle_name']) . ' ' . $doc['last_name']);

        $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
        preg_match('/(\d+)/', $durStr ?: '45', $dm);
        $durationMin = isset($dm[1]) ? (int)$dm[1] : 45;
        $conflict = checkApptConflict($pdo, $newDoctorId, $appt['date'], $appt['time'], $durationMin, $id);
        if ($conflict !== null) {
            jsonResponse(['success' => false, 'message' =>
                "This doctor already has an appointment at {$conflict}. Please choose a different doctor."]);
        }

        $pdo->prepare('UPDATE appointments SET doctor_id = ?, doctor_name = ? WHERE id = ?')
            ->execute([$newDoctorId, $newDoctorName, $id]);

        if ($patientUid = $getPatientUserId()) {
            $fmtDate = date('M j, Y', strtotime($appt['date']));
            $msg = "{$newDoctorName} has been assigned to your appointment on {$fmtDate} at {$appt['time']}.";
            createNotification($pdo, $patientUid, 'new_appointment', 'Doctor Assigned', $msg, $id);
            _emailPatientNotice($pdo, $patientUid, 'Doctor Assigned',
                $msg . ($appt['status'] === 'pending' ? ' Your request is still awaiting the clinic\'s approval.' : ''),
                appointmentLinkUrl($id), 'View Appointment', $appt['date']);
        }

        jsonResponse(['success' => true, 'doctorName' => $newDoctorName]);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}

// Display name of the admin/staff member acting (for "proposed by").
function _apptActorName(PDO $pdo, string $role): string {
    $table = $role === 'admin' ? 'admins' : ($role === 'staff' ? 'staff' : null);
    if (!$table) return 'Cana Optical Clinic';
    $s = $pdo->prepare("SELECT first_name, last_name FROM `{$table}` WHERE user_id = ? LIMIT 1");
    $s->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $r = $s->fetch();
    return $r ? trim($r['first_name'] . ' ' . $r['last_name']) : 'Cana Optical Clinic';
}

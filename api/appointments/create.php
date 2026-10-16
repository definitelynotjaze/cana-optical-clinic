<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/create.php
//  POST { patientId, patientName, doctorId, doctorName,
//         date, time, type, status, notes, termsAgreed }
//  → { success:true, id:'A001' }
//  → { success:false, message } when the slot is already taken.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role = $_SESSION['role'] ?? '';
// Patients book their own via requestAppointment; admin/staff create for any patient
if (!in_array($role, ['admin', 'staff', 'patient'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

// Scoped per logged-in account. Generous enough for staff booking several
// patients back-to-back, but stops a spam-clicked/scripted burst from
// flooding the appointments table or hammering a specific slot.
rateLimit('appt-create:' . $_SESSION['user_id'], 20, 300);

$b = getBody();

$patientId   = trim($b['patientId']   ?? '');
$patientName = trim($b['patientName'] ?? '');
$doctorId    = trim($b['doctorId']    ?? '');
$doctorName  = trim($b['doctorName']  ?? '');
$date        = trim($b['date']        ?? '');
$time        = trim($b['time']        ?? '');
$type        = trim($b['type']        ?? 'Comprehensive Eye Examination');
$notes       = trim($b['notes']       ?? '');
$status      = trim($b['status']      ?? 'pending');
$termsAgreed = !empty($b['termsAgreed']);

// Patient can only book for themselves
if ($role === 'patient') {
    $profileId = $_SESSION['profile_id'] ?? '';
    $patientId = $profileId;
    $status    = 'pending';

    if (!$termsAgreed) {
        jsonResponse(['success' => false, 'message' => 'Please agree to the appointment policy before submitting.']);
    }
}

// "Any available optometrist" — patient self-service only. Admin/staff
// creating on a patient's behalf always pick a specific doctor themselves,
// so this flag is ignored for them. The clinic assigns an actual doctor
// afterward (appointments/update.php action=assign_doctor).
$anyDoctor = !empty($b['anyDoctor']) && $role === 'patient';

if (!$patientId || !$date || !$time || (!$doctorId && !$anyDoctor)) {
    jsonResponse(['success' => false, 'message' => 'Patient, doctor, date and time are required.']);
}

// Validate status
$allowedStatus = ['pending', 'approved', 'cancelled', 'disapproved', 'completed'];
if (!in_array($status, $allowedStatus, true)) $status = 'pending';

try {
    $pdo = getDB();

    // Patients with repeated no-shows lose self-service booking — they have
    // to go through the clinic directly instead. Checked first since there's
    // no point validating anything else if this blocks the request outright.
    if ($role === 'patient') {
        $restricted = $pdo->prepare('SELECT booking_restricted FROM patients WHERE id = ? LIMIT 1');
        $restricted->execute([$patientId]);
        if ((int)$restricted->fetchColumn() === 1) {
            jsonResponse(['success' => false, 'message' =>
                'Online booking is currently unavailable for your account due to repeated missed appointments. Please contact the clinic directly to schedule.']);
        }
    }

    // Enforce the clinic's minimum-advance-booking rule for self-service patient
    // bookings (admin/staff retain discretion to schedule same-day walk-ins).
    if ($role === 'patient') {
        $minDays = minAdvanceBookingDays($pdo);
        $today   = new DateTime('today');
        $reqDate = new DateTime($date);
        $daysOut = (int)$today->diff($reqDate)->format('%r%a');
        if ($daysOut < $minDays) {
            $msg = $minDays === 0
                ? 'Please select a valid date.'
                : "Appointments must be booked at least {$minDays} day" . ($minDays > 1 ? 's' : '') . ' in advance.';
            jsonResponse(['success' => false, 'message' => $msg]);
        }

        // Reject self-service bookings on a date the doctor has explicitly
        // blocked, even if a stale frontend calendar let the request through.
        // Admin/staff keep discretion to book anyway (e.g. squeezing in an
        // urgent case), so this check is patient-only. Not applicable in
        // "any doctor" mode — eligibleDoctorsForDate() below already excludes
        // individually-blocked doctors.
        if (!$anyDoctor) {
            $bs = $pdo->prepare('SELECT reason FROM blocked_dates WHERE doctor_id = ? AND date = ? LIMIT 1');
            $bs->execute([$doctorId, $date]);
            $blockedRow = $bs->fetch();
            if ($blockedRow) {
                jsonResponse(['success' => false, 'message' =>
                    'This doctor is unavailable on the selected date' . ($blockedRow['reason'] ? " ({$blockedRow['reason']})" : '') . '. Please choose another date.']);
            }

            // Same idea for the doctor's weekly schedule: if their days or
            // hours changed after the booking page loaded, the stale slot
            // list could still offer a time they no longer work.
            if ($schedProblem = doctorScheduleProblem($pdo, $doctorId, $date, $time)) {
                jsonResponse(['success' => false, 'scheduleChanged' => true, 'message' => $schedProblem]);
            }

            // Enforce the clinic's max-appointments-per-doctor-per-day cap for
            // self-service patient bookings — cancelled/disapproved slots don't
            // count against it since they're not actually occupying the doctor's day.
            $maxPerDay = (int)($pdo->query('SELECT max_appts_per_doctor_per_day FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn() ?: 12);
            $cs = $pdo->prepare(
                "SELECT COUNT(*) FROM appointments
                 WHERE doctor_id = ? AND date = ? AND status NOT IN ('cancelled','disapproved')"
            );
            $cs->execute([$doctorId, $date]);
            if ((int)$cs->fetchColumn() >= $maxPerDay) {
                jsonResponse(['success' => false, 'message' =>
                    'This doctor is fully booked on the selected date. Please choose another date or doctor.']);
            }
        }
    }

    // Per-patient upcoming limit (default 1 open booking at a time) —
    // patients booking themselves only. Admin/staff keep discretion to book
    // more on a patient's behalf (e.g. a follow-up), and those don't count.
    if ($role === 'patient') {
        $limitMsg = patientUpcomingLimitError($pdo, $patientId);
        if ($limitMsg) jsonResponse(['success' => false, 'upcomingLimit' => true, 'message' => $limitMsg]);
    }

    // Reject if the requested time conflicts with an existing appointment
    // (same doctor, same date, within the clinic's default appointment duration).
    // Applies to all roles — admin/staff bookings are equally subject to gaps.
    $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
    preg_match('/(\d+)/', $durStr ?: '45', $dm);
    $durationMin = isset($dm[1]) ? (int)$dm[1] : 45;

    // A patient can't be in two places at once — reject if they already hold
    // an appointment at this date+time with any doctor, whether they booked
    // it themselves or the clinic booked it for them. All roles.
    $ownConflict = checkPatientApptConflict($pdo, (string)$patientId, $date, $time, $durationMin);
    if ($ownConflict !== null) {
        jsonResponse(['success' => false, 'message' => $role === 'patient'
            ? "You already have an appointment at {$ownConflict} on this date. Please choose a different time."
            : "This patient already has an appointment at {$ownConflict} on this date. Please choose a different time."]);
    }

    if ($anyDoctor) {
        // No specific doctor chosen — confirm at least one doctor who could
        // plausibly work this date is actually free at the requested time
        // (not over the max-per-day cap, no conflicting appointment). The
        // clinic assigns the actual doctor afterward; we just need SOME
        // doctor free.
        $eligible = eligibleDoctorsForDate($pdo, $date, $time);
        if (!$eligible) {
            jsonResponse(['success' => false, 'message' =>
                'No doctors are scheduled on the selected date. Please choose another date.']);
        }

        // Doctors who could still take on a NEW appointment this date at
        // all (under the daily cap) — separate from whether they're free at
        // THIS exact time, same as wizBuildTimeSlotsAnyDoctor()'s freeDocs
        // on the frontend.
        $maxPerDay = (int)($pdo->query('SELECT max_appts_per_doctor_per_day FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn() ?: 12);
        $underCap = [];
        foreach ($eligible as $doc) {
            $cs = $pdo->prepare(
                "SELECT COUNT(*) FROM appointments
                 WHERE doctor_id = ? AND date = ? AND status NOT IN ('cancelled','disapproved')"
            );
            $cs->execute([$doc['id'], $date]);
            if ((int)$cs->fetchColumn() < $maxPerDay) $underCap[] = $doc;
        }
        if (!$underCap) {
            jsonResponse(['success' => false, 'message' =>
                'All doctors are fully booked on the selected date. Please choose another date.']);
        }

        $freeFound = false;
        foreach ($underCap as $doc) {
            if (checkApptConflict($pdo, $doc['id'], $date, $time, $durationMin) !== null) continue;
            $freeFound = true;
            break;
        }

        if (!$freeFound) {
            jsonResponse(['success' => false, 'message' =>
                'No doctor is available at that time. Please choose a different slot.']);
        }

        $doctorId   = null;
        $doctorName = null;
    } else {
        $conflict = checkApptConflict($pdo, $doctorId, $date, $time, $durationMin);
        if ($conflict !== null) {
            jsonResponse(['success' => false, 'message' =>
                "This time conflicts with an existing appointment at {$conflict}. Please choose a different slot."]);
        }
    }

    // Patient booking themselves (or admin/staff assisting with an "any
    // doctor" request that still originated as their own online submission)
    // is 'online'; admin/staff creating one directly on a patient's behalf
    // is a 'walk-in' — they picked the doctor/date/time themselves for
    // someone standing in front of them (or calling in), not the patient.
    $source = $role === 'patient' ? 'online' : 'walk-in';

    $newId = createAppointmentRecord($pdo, [
        'patientId' => $patientId, 'patientName' => $patientName,
        'doctorId'  => $doctorId,  'doctorName'  => $doctorName,
        'date'      => $date,      'time'        => $time,
        'type'      => $type,      'status'      => $status,
        'source'    => $source,
        'notes'     => $notes,     'termsAgreed' => $termsAgreed,
    ]);

    // Send notifications about the new appointment
    $fmtDate = date('M j, Y', strtotime($date));
    if ($role === 'patient') {
        // Patient booked — notify admin/staff. "Any doctor" requests need a
        // doctor assigned before they can be approved, so call that out.
        $withWho = $doctorName
            ? "with {$doctorName} on {$fmtDate} at {$time}."
            : "on {$fmtDate} at {$time} with no preferred doctor — please assign one.";
        notifyAdminStaff($pdo, 'new_appointment',
            'New Appointment Request',
            "{$patientName} has requested an appointment {$withWho}",
            $newId
        );
        // Email receipt to the patient — what they asked for, and that it
        // still needs the clinic's approval (no calendar link until then).
        $selfUid = (int)($_SESSION['user_id'] ?? 0);
        if ($selfUid) {
            _emailPatientNotice($pdo, $selfUid, 'Appointment Request Received',
                "We've received your appointment request " . ($doctorName ? "with {$doctorName} " : '')
                . "on {$fmtDate} at {$time} ({$type}). The clinic will review it, and we'll email you as soon as it's approved.",
                appointmentLinkUrl($newId), 'View Appointment', $date);
        }
    } else {
        // Admin/staff booked — notify patient
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$patientId]);
        $pRow = $ps->fetch();
        if ($pRow && $pRow['user_id']) {
            // Match the type to the appointment's actual status so the
            // notification routes to the right filter on click (same
            // convention update.php uses for status-change notifications) —
            // 'info' had no appointment-aware routing and always dead-ended
            // on the dashboard.
            $notifType = $status === 'approved' ? 'approved' : 'new_appointment';
            $schedMsg  = "An appointment with {$doctorName} has been scheduled for you on {$fmtDate} at {$time} ({$type})."
                . ($status === 'approved' ? '' : ' It is pending confirmation from the clinic.');
            createNotification($pdo, (int)$pRow['user_id'], $notifType, 'Appointment Scheduled', $schedMsg, $newId);
            // Email too, with Add to Google Calendar once it's already approved.
            _emailPatientNotice($pdo, (int)$pRow['user_id'], 'Appointment Scheduled', $schedMsg,
                appointmentLinkUrl($newId), 'View Appointment', $date,
                calendarUrl: $status === 'approved' ? googleCalendarUrl($pdo, $date, $time, $doctorName, $type) : '');
        }
    }

    jsonResponse(['success' => true, 'id' => $newId]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}

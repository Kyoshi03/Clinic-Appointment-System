<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/doctor_schedule.php';
require_once __DIR__ . '/lab_services_seed_data.php';

function appointment_mask_email(string $email): string {
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }

    [$name, $domain] = explode('@', $email, 2);
    $visible = substr($name, 0, min(2, strlen($name)));
    return $visible . str_repeat('*', max(1, strlen($name) - strlen($visible))) . '@' . $domain;
}

function appointment_unit_price(array $service, string $channel): float {
    if (
        $channel === 'home'
        && array_key_exists('home_service_price', $service)
        && $service['home_service_price'] !== null
        && (float) $service['home_service_price'] > 0
    ) {
        return (float) $service['home_service_price'];
    }

    return (float) $service['opd_price'];
}

function appointment_fetch_services(mysqli $conn, array $serviceIds): array {
    $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), static fn ($id) => $id > 0)));
    if (empty($serviceIds)) {
        return [];
    }

    $idList = implode(',', $serviceIds);
    $result = $conn->query(
        "SELECT id, name, category, description, included_tests, opd_price,
                home_service_price, is_package
         FROM lab_services
         WHERE is_active = 1 AND id IN ($idList)"
    );

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function appointment_clinic_is_open_at(string $date, string $time): bool {
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return false;
    }

    $dayOfWeek = (int) date('N', $timestamp);
    if ($dayOfWeek < 1 || $dayOfWeek > 6) {
        return false;
    }

    $normalizedTime = substr(trim($time), 0, 5);
    return $normalizedTime >= '08:00' && $normalizedTime <= '17:00';
}

function appointment_validate_payload(mysqli $conn, int $patientId, array $payload): array {
    $type = (string) ($payload['type'] ?? '');
    if (!in_array($type, ['package', 'individual', 'consultation'], true)) {
        return ['ok' => false, 'error' => 'The selected appointment type is invalid. Please review your booking again.'];
    }

    $date = trim((string) ($payload['appointment_date'] ?? ''));
    $time = trim((string) ($payload['appointment_time'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
        return ['ok' => false, 'error' => 'The appointment schedule is incomplete. Please choose the date and time again.'];
    }

    try {
        $timezone = new DateTimeZone('Asia/Manila');
        $appointmentAt = new DateTimeImmutable($date . ' ' . $time, $timezone);
        $now = new DateTimeImmutable('now', $timezone);
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'The appointment schedule could not be read. Please choose it again.'];
    }

    if ($appointmentAt <= $now) {
        return ['ok' => false, 'error' => 'The selected appointment time has already passed. Please choose a future schedule.'];
    }
    if (!appointment_clinic_is_open_at($date, $time)) {
        return ['ok' => false, 'error' => 'The clinic is open Monday to Saturday, from 8:00 AM to 5:00 PM. Please choose a schedule within clinic hours.'];
    }

    $serviceIds = [];
    $services = [];
    if ($type !== 'consultation') {
        $serviceIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($payload['service_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));
        $services = appointment_fetch_services($conn, $serviceIds);
        if (empty($serviceIds) || count($services) !== count($serviceIds)) {
            return ['ok' => false, 'error' => 'One or more selected services are no longer available. Please review your booking again.'];
        }

        foreach ($services as $service) {
            if (!lab_booking_service_matches_type($service, $type)) {
                return ['ok' => false, 'error' => 'One of the selected services does not match this booking type.'];
            }
        }
    }

    $doctorId = (int) ($payload['doctor_id'] ?? 0);
    $doctorName = '';
    if ($type === 'consultation') {
        if ($doctorId <= 0) {
            return ['ok' => false, 'error' => 'Please choose a doctor for your consultation.'];
        }
        $doctorStmt = $conn->prepare(
            "SELECT full_name FROM users
             WHERE id = ? AND role = 'doctor' AND COALESCE(is_active, 1) = 1
             LIMIT 1"
        );
        $doctorStmt->bind_param('i', $doctorId);
        $doctorStmt->execute();
        $doctor = $doctorStmt->get_result()->fetch_assoc();
        $doctorStmt->close();
        if (!$doctor) {
            return ['ok' => false, 'error' => 'The selected doctor is no longer available. Please choose another doctor.'];
        }
        if (!user_is_doctor_available_at($conn, $doctorId, $date, $time)) {
            return ['ok' => false, 'error' => 'The selected time is outside this doctor\'s clinic schedule. Please choose an available time.'];
        }
        $doctorName = (string) $doctor['full_name'];

        $doctorConflict = $conn->prepare(
            "SELECT id FROM appointments
             WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?
               AND status != 'cancelled'
             LIMIT 1"
        );
        $doctorConflict->bind_param('iss', $doctorId, $date, $time);
        $doctorConflict->execute();
        $doctorConflictRow = $doctorConflict->get_result()->fetch_assoc();
        $doctorConflict->close();
        if ($doctorConflictRow) {
            return ['ok' => false, 'error' => 'This doctor already has an appointment at the selected time. Please choose another time.'];
        }
    } else {
        $doctorId = 0;
    }

    $duplicate = $conn->prepare(
        "SELECT id FROM appointments
         WHERE patient_id = ? AND appointment_date = ? AND appointment_time = ?
           AND status != 'cancelled'
         LIMIT 1"
    );
    $duplicate->bind_param('iss', $patientId, $date, $time);
    $duplicate->execute();
    $duplicateRow = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($duplicateRow) {
        return ['ok' => false, 'error' => 'You already have an appointment at this date and time.'];
    }

    $userStmt = $conn->prepare("SELECT full_name, email, phone FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $userStmt->bind_param('i', $patientId);
    $userStmt->execute();
    $patient = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$patient) {
        return ['ok' => false, 'error' => 'Your patient account could not be found. Please sign in again.'];
    }
    if (!filter_var((string) ($patient['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Add a valid email address to your patient profile before booking an appointment.'];
    }

    $channel = 'opd';
    $serviceNames = $type === 'consultation' ? ['Doctor consultation'] : [];
    $total = 0.0;
    foreach ($services as $service) {
        $serviceNames[] = (string) $service['name'];
        $total += appointment_unit_price($service, $channel);
    }

    return [
        'ok' => true,
        'booking' => [
            'type' => $type,
            'service_ids' => $serviceIds,
            'doctor_id' => $doctorId > 0 ? $doctorId : null,
            'price_channel' => $channel,
            'appointment_date' => $date,
            'appointment_time' => substr($time, 0, 5),
        ],
        'services' => $services,
        'service_names' => $serviceNames,
        'total' => $total,
        'patient' => $patient,
        'doctor_name' => $doctorName,
    ];
}

function appointment_issue_verification(mysqli $conn, int $patientId, array $payload): array {
    $validated = appointment_validate_payload($conn, $patientId, $payload);
    if (!$validated['ok']) {
        return $validated;
    }

    $json = json_encode($validated['booking'], JSON_UNESCAPED_SLASHES);
    $recent = $conn->prepare(
        'SELECT id, booking_payload FROM appointment_booking_verifications
         WHERE patient_id = ? AND last_sent_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
           AND used_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    $recent->bind_param('i', $patientId);
    $recent->execute();
    $recentRow = $recent->get_result()->fetch_assoc();
    $recent->close();
    if ($recentRow && hash_equals((string) $recentRow['booking_payload'], (string) $json)) {
        return [
            'ok' => true,
            'verification_id' => (int) $recentRow['id'],
            'email' => (string) $validated['patient']['email'],
            'already_sent' => true,
        ];
    }

    $invalidate = $conn->prepare(
        'UPDATE appointment_booking_verifications
         SET used_at = NOW()
         WHERE patient_id = ? AND used_at IS NULL'
    );
    $invalidate->bind_param('i', $patientId);
    $invalidate->execute();
    $invalidate->close();

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $email = (string) $validated['patient']['email'];

    $insert = $conn->prepare(
        'INSERT INTO appointment_booking_verifications
            (patient_id, email, code_hash, booking_payload, expires_at, last_sent_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())'
    );
    $insert->bind_param('isss', $patientId, $email, $hash, $json);
    $created = $insert->execute();
    $verificationId = (int) $conn->insert_id;
    $insert->close();
    if (!$created) {
        return ['ok' => false, 'error' => 'We could not prepare the appointment verification. Please try again.'];
    }

    $sent = clinic_send_otp_email(
        $email,
        (string) $validated['patient']['full_name'],
        $code,
        'appointment'
    );
    if (!$sent['ok']) {
        $disable = $conn->prepare('UPDATE appointment_booking_verifications SET used_at = NOW() WHERE id = ?');
        $disable->bind_param('i', $verificationId);
        $disable->execute();
        $disable->close();
        return ['ok' => false, 'error' => $sent['error']];
    }

    return [
        'ok' => true,
        'verification_id' => $verificationId,
        'email' => $email,
        'already_sent' => false,
    ];
}

function appointment_get_verification(mysqli $conn, int $patientId, int $verificationId): ?array {
    $stmt = $conn->prepare(
        'SELECT id, patient_id, email, code_hash, booking_payload, expires_at,
                attempts, last_sent_at, used_at
         FROM appointment_booking_verifications
         WHERE id = ? AND patient_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $verificationId, $patientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function appointment_resend_verification(mysqli $conn, int $patientId, int $verificationId): array {
    $row = appointment_get_verification($conn, $patientId, $verificationId);
    if (!$row || $row['used_at'] !== null) {
        return ['ok' => false, 'error' => 'This booking verification is no longer active. Please review the appointment again.'];
    }

    $waitStmt = $conn->prepare(
        'SELECT GREATEST(0, 60 - TIMESTAMPDIFF(SECOND, last_sent_at, NOW())) AS wait_seconds
         FROM appointment_booking_verifications WHERE id = ?'
    );
    $waitStmt->bind_param('i', $verificationId);
    $waitStmt->execute();
    $wait = (int) ($waitStmt->get_result()->fetch_assoc()['wait_seconds'] ?? 0);
    $waitStmt->close();
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'Please wait ' . $wait . ' seconds before requesting another code.'];
    }

    $payload = json_decode((string) $row['booking_payload'], true);
    $validated = appointment_validate_payload($conn, $patientId, is_array($payload) ? $payload : []);
    if (!$validated['ok']) {
        return $validated;
    }

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $update = $conn->prepare(
        'UPDATE appointment_booking_verifications
         SET code_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
             attempts = 0, last_sent_at = NOW()
         WHERE id = ? AND patient_id = ? AND used_at IS NULL'
    );
    $update->bind_param('sii', $hash, $verificationId, $patientId);
    $updated = $update->execute() && $update->affected_rows === 1;
    $update->close();
    if (!$updated) {
        return ['ok' => false, 'error' => 'We could not create a new verification code. Please try again.'];
    }

    $sent = clinic_send_otp_email(
        (string) $validated['patient']['email'],
        (string) $validated['patient']['full_name'],
        $code,
        'appointment'
    );
    return $sent['ok']
        ? ['ok' => true]
        : ['ok' => false, 'error' => $sent['error']];
}

function appointment_email_layout(string $title, string $intro, array $rows, string $footer): array {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
    $rowHtml = '';
    $textRows = [];
    foreach ($rows as $label => $value) {
        $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rowHtml .= '<tr><td style="padding:9px 10px;color:#5d7280;border-bottom:1px solid #e4eef4">'
            . $safeLabel . '</td><td style="padding:9px 10px;font-weight:700;color:#073b4c;border-bottom:1px solid #e4eef4">'
            . $safeValue . '</td></tr>';
        $textRows[] = $label . ': ' . $value;
    }

    $html = '<!DOCTYPE html><html><body style="margin:0;background:#eef7fc;font-family:Arial,sans-serif;color:#073b4c">'
        . '<div style="max-width:600px;margin:28px auto;background:#fff;border:1px solid #d5e9f3;border-radius:12px;padding:28px">'
        . '<h1 style="font-size:24px;color:#0b4f80;margin:0 0 14px">' . $safeTitle . '</h1>'
        . '<p style="line-height:1.65">' . $safeIntro . '</p>'
        . '<table style="width:100%;border-collapse:collapse;background:#f8fcfe;border:1px solid #e4eef4;border-radius:8px">'
        . $rowHtml . '</table>'
        . '<p style="line-height:1.65;margin:20px 0 0">' . htmlspecialchars($footer, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:13px;color:#718692;margin-top:22px">Globalife Medical Laboratory &amp; Polyclinic</p>'
        . '</div></body></html>';

    $text = $title . "\n\n" . $intro . "\n\n" . implode("\n", $textRows) . "\n\n" . $footer;
    return ['html' => $html, 'text' => $text];
}

function appointment_send_booking_email(array $details): array {
    $dateLabel = date('F j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $isConsultation = ($details['booking_type'] ?? '') === 'consultation';
    $rows = [
        'Reference number' => '#' . (int) $details['appointment_id'],
        'Date' => $dateLabel,
        'Time' => $timeLabel,
        'Services' => implode(', ', (array) $details['service_names']),
        'Status' => 'Pending clinic confirmation',
    ];
    $rows['Payment'] = $isConsultation
        ? 'Consultation fee confirmed at clinic'
        : 'Total: PHP ' . number_format((float) $details['total'], 2);
    if (!empty($details['doctor_name'])) {
        $rows['Doctor'] = (string) $details['doctor_name'];
    }

    $content = appointment_email_layout(
        'Appointment request received',
        'Hello ' . (string) $details['patient_name'] . '. Your email was verified and your appointment request was submitted successfully.',
        $rows,
        'The clinic will review your request. Payment is made at the clinic. You will receive a reminder before the appointment after the clinic confirms it.'
    );

    return clinic_send_email(
        (string) $details['email'],
        (string) $details['patient_name'],
        'Globalife appointment request #' . (int) $details['appointment_id'],
        $content['html'],
        $content['text']
    );
}

function appointment_send_reminder_email(array $details): array {
    $dateLabel = date('F j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $rows = [
        'Reference number' => '#' . (int) $details['appointment_id'],
        'Date' => $dateLabel,
        'Time' => $timeLabel,
        'Services' => (string) $details['services'],
    ];
    if (!empty($details['doctor_name'])) {
        $rows['Doctor'] = (string) $details['doctor_name'];
    }

    $content = appointment_email_layout(
        'Appointment reminder',
        'Hello ' . (string) $details['patient_name'] . '. This is a reminder that your confirmed Globalife appointment is approaching.',
        $rows,
        'Please arrive 10-15 minutes early and bring a valid ID, previous medical documents, and any request form you may have.'
    );

    return clinic_send_email(
        (string) $details['email'],
        (string) $details['patient_name'],
        'Reminder: Globalife appointment on ' . $dateLabel,
        $content['html'],
        $content['text']
    );
}

function appointment_fetch_email_details(mysqli $conn, int $appointmentId): ?array {
    $stmt = $conn->prepare(
        "SELECT a.id AS appointment_id, a.appointment_date, a.appointment_time,
                p.full_name AS patient_name, p.email,
                d.full_name AS doctor_name,
                COALESCE(
                    GROUP_CONCAT(DISTINCT ls.name ORDER BY ls.name SEPARATOR ', '),
                    'Clinic appointment'
                ) AS services
         FROM appointments a
         INNER JOIN users p ON p.id = a.patient_id
         LEFT JOIN users d ON d.id = a.doctor_id
         LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
         LEFT JOIN lab_services ls ON ls.id = aps.service_id
         WHERE a.id = ?
         GROUP BY a.id, a.appointment_date, a.appointment_time,
                  p.full_name, p.email, d.full_name
         LIMIT 1"
    );
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $details ?: null;
}

function appointment_send_clinic_confirmation_email(mysqli $conn, int $appointmentId): array {
    $details = appointment_fetch_email_details($conn, $appointmentId);
    if (!$details || !filter_var((string) ($details['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The patient does not have a valid email address.'];
    }

    $dateLabel = date('F j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $rows = [
        'Reference number' => '#' . (int) $details['appointment_id'],
        'Date' => $dateLabel,
        'Time' => $timeLabel,
        'Services' => (string) $details['services'],
        'Status' => 'Confirmed by the clinic',
    ];
    if (!empty($details['doctor_name'])) {
        $rows['Doctor'] = (string) $details['doctor_name'];
    }

    $content = appointment_email_layout(
        'Your appointment is confirmed',
        'Hello ' . (string) $details['patient_name'] . '. The clinic has confirmed your Globalife appointment.',
        $rows,
        'Please arrive 10-15 minutes early. We will also email you a reminder before your appointment.'
    );

    return clinic_send_email(
        (string) $details['email'],
        (string) $details['patient_name'],
        'Confirmed: Globalife appointment #' . (int) $details['appointment_id'],
        $content['html'],
        $content['text']
    );
}

function appointment_verify_and_create(mysqli $conn, int $patientId, int $verificationId, string $code): array {
    $code = preg_replace('/\D+/', '', trim($code));
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['ok' => false, 'error' => 'Enter the complete 6-digit verification code.'];
    }

    $row = appointment_get_verification($conn, $patientId, $verificationId);
    if (!$row || $row['used_at'] !== null) {
        return ['ok' => false, 'error' => 'This booking verification is no longer active. Please review the appointment again.'];
    }

    $expiryStmt = $conn->prepare('SELECT expires_at < NOW() AS is_expired FROM appointment_booking_verifications WHERE id = ?');
    $expiryStmt->bind_param('i', $verificationId);
    $expiryStmt->execute();
    $isExpired = (int) ($expiryStmt->get_result()->fetch_assoc()['is_expired'] ?? 1);
    $expiryStmt->close();
    if ($isExpired === 1) {
        return ['ok' => false, 'error' => 'This verification code has expired. Request a new code.'];
    }
    if ((int) $row['attempts'] >= 5) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new verification code.'];
    }
    if (!password_verify($code, (string) $row['code_hash'])) {
        $attempt = $conn->prepare('UPDATE appointment_booking_verifications SET attempts = attempts + 1 WHERE id = ?');
        $attempt->bind_param('i', $verificationId);
        $attempt->execute();
        $attempt->close();
        $remaining = max(0, 4 - (int) $row['attempts']);
        return [
            'ok' => false,
            'error' => 'Incorrect verification code. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.',
        ];
    }

    $payload = json_decode((string) $row['booking_payload'], true);
    $validated = appointment_validate_payload($conn, $patientId, is_array($payload) ? $payload : []);
    if (!$validated['ok']) {
        return $validated;
    }

    $booking = $validated['booking'];
    $serviceNames = $validated['service_names'];
    if (($booking['type'] ?? '') === 'consultation') {
        $notes = 'Doctor consultation with ' . (string) $validated['doctor_name']
            . ' | Consultation fee confirmed at clinic';
    } else {
        $notes = 'Services: ' . implode(', ', $serviceNames)
            . ' | Total: PHP ' . number_format((float) $validated['total'], 2);
    }

    $conn->begin_transaction();
    try {
        $doctorId = $booking['doctor_id'];
        $appointmentDate = (string) $booking['appointment_date'];
        $appointmentTime = (string) $booking['appointment_time'];
        $bookingType = (string) $booking['type'];
        $total = (float) $validated['total'];
        $priceChannel = (string) $booking['price_channel'];
        if ($doctorId === null) {
            $insert = $conn->prepare(
                "INSERT INTO appointments
                    (patient_id, doctor_id, appointment_date, appointment_time, notes,
                     status, booking_type, total_display_price, price_channel)
                 VALUES (?, NULL, ?, ?, ?, 'pending', ?, ?, ?)"
            );
            $insert->bind_param(
                'issssds',
                $patientId,
                $appointmentDate,
                $appointmentTime,
                $notes,
                $bookingType,
                $total,
                $priceChannel
            );
        } else {
            $insert = $conn->prepare(
                "INSERT INTO appointments
                    (patient_id, doctor_id, appointment_date, appointment_time, notes,
                     status, booking_type, total_display_price, price_channel)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
            );
            $insert->bind_param(
                'iissssds',
                $patientId,
                $doctorId,
                $appointmentDate,
                $appointmentTime,
                $notes,
                $bookingType,
                $total,
                $priceChannel
            );
        }

        if (!$insert->execute()) {
            throw new RuntimeException('Could not save the appointment.');
        }
        $appointmentId = (int) $conn->insert_id;
        $insert->close();

        $line = $conn->prepare(
            'INSERT INTO appointment_services (appointment_id, service_id, unit_price)
             VALUES (?, ?, ?)'
        );
        foreach ($validated['services'] as $service) {
            $serviceId = (int) $service['id'];
            $unitPrice = appointment_unit_price($service, (string) $booking['price_channel']);
            $line->bind_param('iid', $appointmentId, $serviceId, $unitPrice);
            if (!$line->execute()) {
                throw new RuntimeException('Could not save the selected appointment services.');
            }
        }
        $line->close();

        $timezone = new DateTimeZone('Asia/Manila');
        $appointmentAt = new DateTimeImmutable(
            $appointmentDate . ' ' . $appointmentTime,
            $timezone
        );
        $reminderAt = $appointmentAt->modify('-24 hours');
        $now = new DateTimeImmutable('now', $timezone);
        if ($reminderAt < $now) {
            $reminderAt = $now;
        }
        $scheduledFor = $reminderAt->format('Y-m-d H:i:s');
        $reminder = $conn->prepare(
            "INSERT INTO appointment_email_reminders
                (appointment_id, reminder_type, scheduled_for)
             VALUES (?, '24_hours', ?)"
        );
        $reminder->bind_param('is', $appointmentId, $scheduledFor);
        if (!$reminder->execute()) {
            throw new RuntimeException('Could not schedule the appointment reminder.');
        }
        $reminder->close();

        $used = $conn->prepare(
            'UPDATE appointment_booking_verifications
             SET used_at = NOW()
             WHERE id = ? AND patient_id = ? AND used_at IS NULL'
        );
        $used->bind_param('ii', $verificationId, $patientId);
        if (!$used->execute() || $used->affected_rows !== 1) {
            throw new RuntimeException('This booking verification was already used.');
        }
        $used->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $details = [
        'appointment_id' => $appointmentId,
        'appointment_date' => $booking['appointment_date'],
        'appointment_time' => $booking['appointment_time'],
        'service_names' => $serviceNames,
        'total' => $validated['total'],
        'patient_name' => (string) $validated['patient']['full_name'],
        'email' => (string) $validated['patient']['email'],
        'doctor_name' => (string) $validated['doctor_name'],
        'booking_type' => (string) $booking['type'],
    ];
    $emailResult = appointment_send_booking_email($details);

    return [
        'ok' => true,
        'appointment_id' => $appointmentId,
        'email_sent' => (bool) $emailResult['ok'],
        'email_error' => $emailResult['ok'] ? '' : (string) ($emailResult['error'] ?? ''),
    ];
}

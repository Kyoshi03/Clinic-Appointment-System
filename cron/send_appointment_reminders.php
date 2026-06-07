<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/appointment_booking.php';

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$lock = @fopen($logDir . '/appointment_reminders.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another reminder process is already running.\n");
}

$conn = getDBConnection();
$result = $conn->query(
    "SELECT r.id AS reminder_id, r.appointment_id,
            a.appointment_date, a.appointment_time,
            p.full_name AS patient_name, p.email,
            d.full_name AS doctor_name,
            COALESCE(
                GROUP_CONCAT(DISTINCT ls.name ORDER BY ls.name SEPARATOR ', '),
                'Clinic appointment'
            ) AS services
     FROM appointment_email_reminders r
     INNER JOIN appointments a ON a.id = r.appointment_id
     INNER JOIN users p ON p.id = a.patient_id
     LEFT JOIN users d ON d.id = a.doctor_id
     LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
     LEFT JOIN lab_services ls ON ls.id = aps.service_id
     WHERE r.sent_at IS NULL
       AND r.scheduled_for <= NOW()
       AND r.attempts < 5
       AND a.status = 'confirmed'
       AND TIMESTAMP(a.appointment_date, a.appointment_time) > NOW()
     GROUP BY r.id, r.appointment_id, a.appointment_date, a.appointment_time,
              p.full_name, p.email, d.full_name
     ORDER BY r.scheduled_for ASC
     LIMIT 50"
);

$sentCount = 0;
$failedCount = 0;
while ($result && ($row = $result->fetch_assoc())) {
    $sent = appointment_send_reminder_email($row);
    $reminderId = (int) $row['reminder_id'];

    if ($sent['ok']) {
        $update = $conn->prepare(
            'UPDATE appointment_email_reminders
             SET sent_at = NOW(), attempts = attempts + 1, last_error = NULL
             WHERE id = ? AND sent_at IS NULL'
        );
        $update->bind_param('i', $reminderId);
        $update->execute();
        $update->close();
        $sentCount++;
    } else {
        $message = substr((string) ($sent['error'] ?? 'Unknown email error'), 0, 500);
        $update = $conn->prepare(
            'UPDATE appointment_email_reminders
             SET attempts = attempts + 1, last_error = ?
             WHERE id = ? AND sent_at IS NULL'
        );
        $update->bind_param('si', $message, $reminderId);
        $update->execute();
        $update->close();
        $failedCount++;
    }
}

$conn->close();
flock($lock, LOCK_UN);
fclose($lock);

echo 'Appointment reminders: ' . $sentCount . ' sent, ' . $failedCount . " failed.\n";


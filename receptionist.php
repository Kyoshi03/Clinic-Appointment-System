<?php
require_once 'includes/session.php';
checkRole('receptionist');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/doctor_schedule.php';
require_once __DIR__ . '/includes/appointment_booking.php';

$pageTitle = 'Receptionist Dashboard | Globalife Medical Laboratory & Polyclinic';
$currentUser = getCurrentUser();
$allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $newStatus = strtolower(trim((string) ($_POST['status'] ?? '')));

    if ($appointmentId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        $_SESSION['error'] = 'Invalid appointment update.';
    } else {
        $conn = getDBConnection();
        $oldStatusStmt = $conn->prepare('SELECT status FROM appointments WHERE id = ? LIMIT 1');
        $oldStatusStmt->bind_param('i', $appointmentId);
        $oldStatusStmt->execute();
        $oldStatus = (string) ($oldStatusStmt->get_result()->fetch_assoc()['status'] ?? '');
        $oldStatusStmt->close();

        $updateStmt = $conn->prepare('UPDATE appointments SET status = ? WHERE id = ?');
        $updateStmt->bind_param('si', $newStatus, $appointmentId);

        if ($updateStmt->execute()) {
            $_SESSION['success'] = 'Appointment status updated.';
            if ($newStatus === 'confirmed' && $oldStatus !== 'confirmed') {
                $emailResult = appointment_send_clinic_confirmation_email($conn, $appointmentId);
                if (!$emailResult['ok']) {
                    $_SESSION['success'] .= ' The status was saved, but the confirmation email could not be delivered.';
                }
            }
        } else {
            $_SESSION['error'] = 'Error updating appointment status.';
        }

        $updateStmt->close();
        $conn->close();
    }

    header('Location: receptionist.php');
    exit();
}

function receptionist_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function receptionist_status(array $appointment): string {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function receptionist_status_label(string $status): string {
    return [
        'pending' => 'Needs confirmation',
        'confirmed' => 'Ready for nurse/doctor',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$status] ?? 'Needs confirmation';
}

function receptionist_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M d, Y', $stamp) : 'No date';
}

function receptionist_queue_timing_label(array $appointment, string $today, string $nowTime): string {
    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '');
    if ($date !== '') {
        if ($date < $today) {
            return 'Earlier appointment still open';
        }
        if ($date > $today) {
            return 'Upcoming on ' . receptionist_date_label($date);
        }
    }
    if ($time === '') {
        return 'Today';
    }
    if ($time < $nowTime) {
        return 'Earlier appointment still open';
    }
    if (substr($time, 0, 5) === substr($nowTime, 0, 5)) {
        return 'Due now';
    }
    return 'Upcoming today';
}

function receptionist_doctor_flow_label(array $appointment, mysqli $conn): array {
    $doctorId = (int) ($appointment['doctor_id'] ?? 0);
    if ($doctorId <= 0) {
        return [
            'class' => 'missing',
            'label' => 'No doctor assigned',
            'detail' => 'Assign doctor if this visit needs one',
        ];
    }

    $assignedRole = strtolower((string) ($appointment['doctor_role'] ?? ''));
    if ($assignedRole !== '' && $assignedRole !== 'doctor') {
        return [
            'class' => 'ok',
            'label' => 'Staff assigned',
            'detail' => 'Ready for nurse/doctor flow',
        ];
    }

    if ((int) ($appointment['doctor_is_active'] ?? 1) !== 1) {
        return [
            'class' => 'off',
            'label' => 'Doctor unavailable',
            'detail' => 'This doctor is currently off duty',
        ];
    }

    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '');
    if ($date !== '' && $time !== '' && !doctor_time_matches_clinic_slot($conn, $doctorId, $date, $time)) {
        return [
            'class' => 'off',
            'label' => 'Outside clinic hours',
            'detail' => 'Check the doctor schedule',
        ];
    }

    return [
        'class' => 'ok',
        'label' => 'Doctor scheduled',
        'detail' => 'Within clinic hours',
    ];
}

function receptionist_short_text(string $text, int $limit = 70): string {
    $text = trim($text);
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);
$today = date('Y-m-d');

$stmt = $conn->prepare("SELECT a.*,
                        p.full_name AS patient_name,
                        p.profile_photo,
                        p.profile_updated_at,
                        p.phone AS patient_phone,
                        d.full_name AS doctor_name,
                        d.role AS doctor_role,
                        COALESCE(d.is_active, 1) AS doctor_is_active
                        FROM appointments a
                        JOIN users p ON a.patient_id = p.id
                        LEFT JOIN users d ON a.doctor_id = d.id
                        ORDER BY a.appointment_date ASC, a.appointment_time ASC");
$stmt->execute();
$todayAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statusTotals = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$confirmedAppointments = [];
$nextAppointment = null;
$nowTime = date('H:i:s');
$patientIds = [];

foreach ($todayAppointments as $index => $appointment) {
    $status = receptionist_status($appointment);
    $todayAppointments[$index]['status_label'] = receptionist_status_label($status);
    $todayAppointments[$index]['queue_timing_label'] = receptionist_queue_timing_label($appointment, $today, $nowTime);
    $todayAppointments[$index]['doctor_flow'] = receptionist_doctor_flow_label($appointment, $conn);
    $appointment = $todayAppointments[$index];

    $statusTotals[$status]++;
    $patientIds[(int) $appointment['patient_id']] = true;

    if ($status === 'confirmed') {
        $confirmedAppointments[] = $appointment;
    }

    if ($nextAppointment === null && in_array($status, ['pending', 'confirmed'], true)) {
        $nextAppointment = $appointment;
    }
}

$staffCounts = ['nurse' => 0, 'doctor' => 0, 'doctor_available' => 0, 'doctor_unavailable' => 0];
$staffResult = $conn->query("SELECT
    SUM(CASE WHEN role = 'nurse' THEN 1 ELSE 0 END) AS nurses,
    SUM(CASE WHEN role = 'doctor' THEN 1 ELSE 0 END) AS doctors,
    SUM(CASE WHEN role = 'doctor' AND COALESCE(is_active, 1) = 1 THEN 1 ELSE 0 END) AS doctors_available,
    SUM(CASE WHEN role = 'doctor' AND COALESCE(is_active, 1) = 0 THEN 1 ELSE 0 END) AS doctors_unavailable
    FROM users
    WHERE role IN ('nurse', 'doctor')");
if ($staffResult) {
    $row = $staffResult->fetch_assoc();
    $staffCounts['nurse'] = (int) ($row['nurses'] ?? 0);
    $staffCounts['doctor'] = (int) ($row['doctors'] ?? 0);
    $staffCounts['doctor_available'] = (int) ($row['doctors_available'] ?? 0);
    $staffCounts['doctor_unavailable'] = (int) ($row['doctors_unavailable'] ?? 0);
}

$conn->close();

$totalToday = count($todayAppointments);
$activeQueue = $statusTotals['pending'] + $statusTotals['confirmed'];
$readyQueue = $statusTotals['confirmed'];
$todayLabel = date('F d, Y');

$additionalStyles = patientAvatarStyles() . '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.receptionist-dashboard {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 46px;
}

.receptionist-dashboard > section {
    padding-top: 0;
    padding-bottom: 0;
}

.desk-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
    gap: 18px;
    align-items: stretch;
    margin-bottom: 16px;
}

.hero-main,
.hero-side,
.metric-card,
.panel,
.appointment-row {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
}

.hero-main {
    background: #073b4c;
    color: #fff;
    padding: 26px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.eyebrow {
    margin: 0 0 8px;
    color: #8bd3e6;
    font-size: 0.78rem;
    font-weight: 900;
    letter-spacing: 0;
    text-transform: uppercase;
}

.hero-main h1 {
    margin: 0 0 10px;
    color: #fff;
    font-size: 2rem;
    line-height: 1.15;
}

.hero-main p {
    margin: 0;
    color: rgba(255, 255, 255, 0.82);
    line-height: 1.6;
}

.hero-side {
    padding: 20px;
    display: grid;
    gap: 12px;
    align-content: center;
    background: #f8fcff;
}

.hero-side span {
    color: #60727d;
    font-weight: 800;
    font-size: 0.84rem;
    text-transform: uppercase;
}

.hero-side strong {
    color: #073b4c;
    font-size: 1.55rem;
    line-height: 1.1;
}

.hero-side small {
    color: #60727d;
    font-weight: 700;
}

.success-message,
.error-message {
    border-radius: 8px;
    padding: 13px 14px;
    margin-bottom: 14px;
    font-weight: 800;
}

.success-message {
    background: #e7f7ed;
    color: #17643a;
    border: 1px solid #bfe6ce;
}

.error-message {
    background: #fff0f0;
    color: #9d1c2c;
    border: 1px solid #ffd0d5;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.metric-card {
    padding: 16px;
    display: grid;
    gap: 6px;
}

.metric-card span {
    color: #60727d;
    font-size: 0.82rem;
    font-weight: 900;
    text-transform: uppercase;
}

.metric-card strong {
    color: #073b4c;
    font-size: 1.85rem;
    line-height: 1;
}

.metric-card small {
    color: #60727d;
    font-weight: 700;
}

.metric-card.pending {
    border-color: #f2d58b;
    background: #fffaf0;
}

.metric-card.ready {
    border-color: #bfe6ce;
    background: #f5fbf7;
}

.workbench-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
    gap: 16px;
    margin-bottom: 16px;
}

.panel {
    padding: 20px;
}

.panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
}

.panel-head h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.22rem;
}

.panel-head p {
    margin: 4px 0 0;
    color: #60727d;
    font-size: 0.92rem;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 0.74rem;
    font-weight: 900;
    text-transform: uppercase;
    text-align: center;
    line-height: 1.15;
    white-space: nowrap;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.confirmed {
    background: #e7f7ed;
    color: #17643a;
}

.status-badge.completed {
    background: #e8f4f8;
    color: #0b4f80;
}

.status-badge.cancelled {
    background: #fff0f0;
    color: #9d1c2c;
}

.next-patient {
    display: grid;
    gap: 10px;
}

.next-patient-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 2px 0 4px;
}

.queue-note,
.doctor-flow {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 0.78rem;
    font-weight: 900;
}

.queue-note {
    background: #eef7ff;
    color: #0b4f80;
}

.doctor-flow.ok {
    background: #e7f7ed;
    color: #17643a;
}

.doctor-flow.missing {
    background: #fffaf0;
    color: #856404;
}

.doctor-flow.off {
    background: #fff0f0;
    color: #9d1c2c;
}

.next-patient strong {
    color: #073b4c;
    font-size: 1.35rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.detail-pill {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #f8fbff;
    padding: 10px;
}

.detail-pill span {
    display: block;
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.detail-pill strong {
    display: block;
    margin-top: 4px;
    color: #1f343d;
    font-size: 0.96rem;
}

.handoff-list {
    display: grid;
    gap: 8px;
}

.handoff-item {
    border-left: 3px solid #0f7cc2;
    background: #f8fbff;
    border-radius: 6px;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    gap: 12px;
}

.handoff-item strong {
    display: block;
    color: #073b4c;
}

.handoff-item span {
    color: #60727d;
    font-size: 0.88rem;
}

.staff-strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 12px;
}

.staff-mini {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 12px;
    background: #fff;
}

.staff-mini span {
    display: block;
    color: #60727d;
    font-size: 0.8rem;
    font-weight: 900;
    text-transform: uppercase;
}

.staff-mini strong {
    display: block;
    margin-top: 6px;
    color: #073b4c;
    font-size: 1.3rem;
}

.staff-mini small {
    display: block;
    margin-top: 4px;
    color: #60727d;
    font-weight: 700;
}

.schedule-card {
    padding: 20px;
}

.appointment-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(160px, 220px);
    gap: 10px;
    margin-bottom: 14px;
}

.appointment-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.appointment-summary span {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    border: 1px solid #e0ebf3;
    border-radius: 999px;
    background: #f8fbff;
    color: #364d58;
    padding: 5px 10px;
    font-size: 0.84rem;
    font-weight: 800;
}

input,
select {
    width: 100%;
    box-sizing: border-box;
    min-height: 40px;
    border: 1px solid #d4e6f5;
    border-radius: 8px;
    background: #fff;
    color: #1f343d;
    font: inherit;
    padding: 9px 10px;
}

input:focus,
select:focus {
    border-color: #0f7cc2;
    box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.1);
    outline: none;
}

.appointments-list {
    display: grid;
    gap: 10px;
}

.appointment-row {
    display: grid;
    grid-template-columns: 92px auto minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 14px;
}

.appointment-row.hidden {
    display: none;
}

.time-block {
    border-radius: 8px;
    background: #eef7ff;
    color: #0b4f80;
    padding: 10px;
    text-align: center;
    font-weight: 900;
}

.appointment-main {
    min-width: 0;
}

.appointment-patient {
    color: #073b4c;
    font-weight: 900;
    font-size: 1.05rem;
}

.appointment-details {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-top: 6px;
    color: #60727d;
    font-size: 0.9rem;
}

.appointment-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.btn-action,
.dashboard-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px 13px;
    cursor: pointer;
    font-weight: 900;
    text-decoration: none;
    transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.btn-action:hover,
.dashboard-btn:hover {
    transform: translateY(-1px);
}

.btn-confirm {
    background: #17643a;
    color: #fff;
}

.btn-complete {
    background: #0f7cc2;
    color: #fff;
}

.btn-cancel {
    background: #c1121f;
    color: #fff;
}

.status-action-modal {
    position: fixed;
    inset: 0;
    z-index: 3000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(8, 42, 58, 0.5);
    backdrop-filter: blur(3px);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.18s ease, visibility 0.18s ease;
}

.status-action-modal.open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}

.status-action-dialog {
    position: relative;
    width: min(100%, 460px);
    max-height: calc(100vh - 36px);
    overflow-y: auto;
    border: 1px solid #cfe0e9;
    border-top: 5px solid #17643a;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 24px 64px rgba(7, 43, 59, 0.28);
    transform: translateY(8px) scale(0.985);
    transition: transform 0.18s ease;
}

.status-action-modal.open .status-action-dialog {
    transform: translateY(0) scale(1);
}

.status-action-modal[data-action="completed"] .status-action-dialog {
    border-top-color: #0f7cc2;
}

.status-action-modal[data-action="cancelled"] .status-action-dialog {
    border-top-color: #c1121f;
}

.status-action-header {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 19px 54px 15px 20px;
    background: #fff;
    color: #073b4c;
}

.status-action-mark {
    position: relative;
    flex: 0 0 42px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #e8f5ed;
    color: #17643a;
}

.status-action-mark::before,
.status-action-mark::after {
    content: "";
    position: absolute;
}

.status-action-mark::before {
    width: 9px;
    height: 17px;
    left: 16px;
    top: 9px;
    border-right: 3px solid currentColor;
    border-bottom: 3px solid currentColor;
    transform: rotate(42deg);
}

.status-action-modal[data-action="completed"] .status-action-mark {
    background: #e8f4fb;
    color: #0f7cc2;
}

.status-action-modal[data-action="completed"] .status-action-mark::before {
    width: 15px;
    height: 15px;
    left: 13px;
    top: 13px;
    border: 3px solid currentColor;
    border-radius: 2px;
    transform: none;
}

.status-action-modal[data-action="completed"] .status-action-mark::after {
    width: 7px;
    height: 3px;
    left: 18px;
    top: 20px;
    background: currentColor;
}

.status-action-modal[data-action="cancelled"] .status-action-mark {
    background: #fdecee;
    color: #c1121f;
}

.status-action-modal[data-action="cancelled"] .status-action-mark::before,
.status-action-modal[data-action="cancelled"] .status-action-mark::after {
    width: 19px;
    height: 3px;
    left: 12px;
    top: 20px;
    border: 0;
    border-radius: 2px;
    background: currentColor;
}

.status-action-modal[data-action="cancelled"] .status-action-mark::before {
    transform: rotate(45deg);
}

.status-action-modal[data-action="cancelled"] .status-action-mark::after {
    transform: rotate(-45deg);
}

.status-action-kicker {
    display: block;
    margin-bottom: 3px;
    color: #6a7f8b;
    font-size: 0.7rem;
    font-weight: 900;
    text-transform: uppercase;
}

.status-action-header h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.28rem;
    line-height: 1.2;
}

.status-action-close {
    position: absolute;
    top: 15px;
    right: 15px;
    width: 34px;
    height: 34px;
    border: 1px solid #d7e5ed;
    border-radius: 50%;
    background: #f7fafc;
    color: #315b6d;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
}

.status-action-close:hover {
    border-color: #bdd3df;
    background: #edf4f7;
}

.status-action-body {
    padding: 0 20px 19px;
}

.status-action-message {
    margin: 0 0 15px;
    color: #526b7a;
    font-size: 0.95rem;
    line-height: 1.55;
}

.status-action-patient {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    margin-bottom: 14px;
    padding: 13px 0;
    border-top: 1px solid #e0ebf1;
    border-bottom: 1px solid #e0ebf1;
}

.status-action-patient strong,
.status-action-patient span {
    display: block;
}

.status-action-patient strong {
    color: #073b4c;
    font-size: 1.02rem;
}

.status-action-patient span {
    color: #526f80;
    font-size: 0.9rem;
    font-weight: 700;
    text-align: right;
}

.status-action-note {
    margin: 0;
    padding: 11px 13px;
    border-left: 3px solid #0f7cc2;
    border-radius: 6px;
    background: #eef7fd;
    color: #315b6d;
    font-size: 0.88rem;
    line-height: 1.5;
}

.status-action-modal[data-action="confirmed"] .status-action-note {
    border-left-color: #17643a;
    background: #edf8f1;
}

.status-action-modal[data-action="cancelled"] .status-action-note {
    border-left-color: #c1121f;
    background: #fff1f2;
}

.status-action-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 20px 18px;
    border-top: 1px solid #e3edf2;
    margin: 0;
    background: #fff;
    color: #073b4c;
    text-align: left;
}

.status-action-back,
.status-action-submit {
    min-height: 40px;
    border-radius: 8px;
    padding: 9px 15px;
    font: inherit;
    font-size: 0.92rem;
    font-weight: 900;
    cursor: pointer;
}

.status-action-back {
    border: 1px solid #cfdfe8;
    background: #fff;
    color: #315b6d;
}

.status-action-submit {
    border: 1px solid transparent;
    background: #17643a;
    color: #fff;
    min-width: 168px;
}

.status-action-submit:hover {
    filter: brightness(0.94);
}

.status-action-submit:disabled {
    cursor: wait;
    opacity: 0.72;
}

.status-action-modal[data-action="completed"] .status-action-submit {
    background: #0f7cc2;
}

.status-action-modal[data-action="cancelled"] .status-action-submit {
    background: #c1121f;
}

body.modal-open {
    overflow: hidden;
}

.dashboard-btn {
    background: #0f7cc2;
    color: #fff;
}

.dashboard-btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.empty-state {
    border: 1px dashed #bdd7ea;
    border-radius: 8px;
    padding: 20px;
    color: #60727d;
    background: #f8fbff;
}

.empty-state.hidden {
    display: none;
}

@media (max-width: 980px) {
    .desk-hero,
    .workbench-grid,
    .metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hero-main {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .receptionist-dashboard {
        padding: 18px 12px 36px;
    }

    .desk-hero,
    .workbench-grid,
    .metrics-grid,
    .appointment-toolbar,
    .detail-grid,
    .staff-strip {
        grid-template-columns: 1fr;
    }

    .appointment-row {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .appointment-actions {
        justify-content: stretch;
    }

    .btn-action,
    .dashboard-btn {
        width: 100%;
    }

    .status-action-footer {
        flex-direction: column-reverse;
    }

    .status-action-back,
    .status-action-submit {
        width: 100%;
    }

    .status-action-patient {
        grid-template-columns: 1fr;
        gap: 4px;
    }

    .status-action-patient span {
        text-align: left;
    }
}
';

include 'includes/header.php';
?>
<main class="receptionist-dashboard">
    <section class="desk-hero">
        <div class="hero-main">
            <p class="eyebrow">Reception desk</p>
            <h1>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?></h1>
            <p>Front desk board for patient bookings, ready queue, and doctor coordination.</p>
        </div>
        <aside class="hero-side" aria-label="Today">
            <span>Today</span>
            <strong><?php echo htmlspecialchars($todayLabel); ?></strong>
            <small><?php echo htmlspecialchars(date('l')); ?></small>
        </aside>
    </section>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="success-message"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="error-message"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <section class="metrics-grid" aria-label="Appointment summary">
        <div class="metric-card">
            <span>Booked appointments</span>
            <strong><?php echo $totalToday; ?></strong>
            <small><?php echo count($patientIds); ?> patient<?php echo count($patientIds) === 1 ? '' : 's'; ?></small>
        </div>
        <div class="metric-card pending">
            <span>Needs confirmation</span>
            <strong><?php echo $statusTotals['pending']; ?></strong>
            <small>Review before clinic flow</small>
        </div>
        <div class="metric-card ready">
            <span>Ready for nurse/doctor</span>
            <strong><?php echo $readyQueue; ?></strong>
            <small>Confirmed and waiting</small>
        </div>
        <div class="metric-card">
            <span>Completed</span>
            <strong><?php echo $statusTotals['completed']; ?></strong>
            <small><?php echo $activeQueue; ?> still active</small>
        </div>
    </section>

    <section class="workbench-grid">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Next patient</h2>
                    <p>Current queue priority</p>
                </div>
            </div>

            <?php if ($nextAppointment): ?>
                <?php $nextDoctorFlow = $nextAppointment['doctor_flow']; ?>
                <div class="next-patient">
                    <div style="margin-bottom:10px"><?php echo renderPatientAvatarWithName($nextAppointment, ['size' => 'lg', 'link' => true, 'patient_id' => (int) $nextAppointment['patient_id']]); ?></div>
                    <div class="next-patient-meta">
                        <span class="queue-note"><?php echo htmlspecialchars($nextAppointment['queue_timing_label']); ?></span>
                        <span class="doctor-flow <?php echo htmlspecialchars($nextDoctorFlow['class']); ?>"><?php echo htmlspecialchars($nextDoctorFlow['label']); ?></span>
                    </div>
                    <div class="detail-grid">
                        <div class="detail-pill">
                            <span>Time</span>
                            <strong><?php echo receptionist_time_label($nextAppointment['appointment_time']); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Doctor</span>
                            <strong><?php echo htmlspecialchars($nextAppointment['doctor_name'] ?: 'Not assigned'); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Doctor note</span>
                            <strong><?php echo htmlspecialchars($nextDoctorFlow['detail']); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Phone</span>
                            <strong><?php echo htmlspecialchars($nextAppointment['patient_phone'] ?: 'No phone'); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Notes</span>
                            <strong><?php echo htmlspecialchars(trim((string) ($nextAppointment['notes'] ?? '')) ?: 'None'); ?></strong>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">No pending or confirmed appointments in the queue.</div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Ready for nurse/doctor</h2>
                    <p>Confirmed patients ready for clinic flow</p>
                </div>
                <a class="dashboard-btn secondary" href="view_appointments.php">View appointments</a>
            </div>

            <?php if (empty($confirmedAppointments)): ?>
                <div class="empty-state">No confirmed patients waiting right now.</div>
            <?php else: ?>
                <div class="handoff-list">
                    <?php foreach (array_slice($confirmedAppointments, 0, 4) as $appointment): ?>
                        <div class="handoff-item" style="display:flex;align-items:center;gap:10px">
                            <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) $appointment['patient_id']]); ?>
                            <div>
                                <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                <span><?php echo htmlspecialchars($appointment['doctor_name'] ?: 'Not assigned'); ?></span>
                            </div>
                            <span><?php echo receptionist_time_label($appointment['appointment_time']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="staff-strip">
                <div class="staff-mini">
                    <span>Nurses</span>
                    <strong><?php echo $staffCounts['nurse']; ?></strong>
                </div>
                <div class="staff-mini">
                    <span>Doctors available</span>
                    <strong><?php echo $staffCounts['doctor_available']; ?></strong>
                    <small>of <?php echo $staffCounts['doctor']; ?> total</small>
                </div>
                <div class="staff-mini">
                    <span>Unavailable doctors</span>
                    <strong><?php echo $staffCounts['doctor_unavailable']; ?></strong>
                    <small>Off duty or inactive</small>
                </div>
            </div>
        </div>
    </section>

</main>

<?php include 'includes/footer.php'; ?>

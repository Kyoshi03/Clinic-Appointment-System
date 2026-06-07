<?php
require_once 'includes/session.php';
checkRole('nurse');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$pageTitle = 'Nurse Dashboard | Globalife Medical Laboratory & Polyclinic';
$currentUser = getCurrentUser();
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nurse_status_action'])) {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {
        $_SESSION['error'] = 'Invalid appointment.';
    } else {
        $conn = getDBConnection();
        $status = 'completed';
        $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ? AND status IN ('pending', 'confirmed')");
        $stmt->bind_param('si', $status, $appointmentId);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success'] = 'Patient visit marked as completed.';
        } else {
            $_SESSION['error'] = 'Unable to update appointment status.';
        }

        $stmt->close();
        $conn->close();
    }

    header('Location: nurse.php');
    exit();
}

function nurse_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function nurse_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M j, Y', $stamp) : '--';
}

function nurse_status(array $appointment): string {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function nurse_status_label(string $status): string {
    return [
        'pending' => 'Needs confirmation',
        'confirmed' => 'Ready for care',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$status] ?? 'Needs confirmation';
}

function nurse_queue_timing_label(array $appointment, string $today, string $nowTime): string {
    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '');
    if ($date !== '') {
        if ($date < $today) {
            return 'Earlier appointment still open';
        }
        if ($date > $today) {
            return 'Upcoming on ' . nurse_date_label($date);
        }
    }
    if ($time !== '' && $time < $nowTime) {
        return 'Earlier today';
    }
    return 'Scheduled today';
}
function nurse_short_text(?string $text, int $limit = 72): string {
    $text = trim((string) $text);
    if ($text === '') {
        return 'None';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function nurse_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

$conn = getDBConnection();
if (
    function_exists('initLabBookingSchema') &&
    (!nurse_table_exists($conn, 'medical_records') || !nurse_table_exists($conn, 'lab_result_entries'))
) {
    initLabBookingSchema($conn);
}

$stmt = $conn->prepare("SELECT a.*,
                        p.full_name AS patient_name,
                        p.profile_photo,
                        p.profile_updated_at,
                        p.phone AS patient_phone,
                        p.email AS patient_email,
                        p.gender AS patient_gender,
                        p.age AS patient_age,
                        d.full_name AS doctor_name
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
$nextPatient = null;
$nowTime = date('H:i:s');

foreach ($todayAppointments as $index => $appointment) {
    $status = nurse_status($appointment);
    $todayAppointments[$index]['status_label'] = nurse_status_label($status);
    $todayAppointments[$index]['queue_timing_label'] = nurse_queue_timing_label($appointment, $today, $nowTime);
    $appointment = $todayAppointments[$index];
    $statusTotals[$status]++;

    if ($nextPatient === null && in_array($status, ['pending', 'confirmed'], true)) {
        $nextPatient = $appointment;
    }
}
$patientCount = 0;
$patientResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'patient'");
if ($patientResult && ($row = $patientResult->fetch_assoc())) {
    $patientCount = (int) $row['total'];
}

$doctorCount = 0;
$doctorActiveCount = 0;
$doctorInactiveCount = 0;
$doctorStats = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN COALESCE(is_active, 1) = 1 THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN COALESCE(is_active, 1) = 0 THEN 1 ELSE 0 END) AS inactive_count
    FROM users WHERE role = 'doctor'");
if ($doctorStats && ($row = $doctorStats->fetch_assoc())) {
    $doctorCount = (int) ($row['total'] ?? 0);
    $doctorActiveCount = (int) ($row['active_count'] ?? 0);
    $doctorInactiveCount = (int) ($row['inactive_count'] ?? 0);
}

$todayRecordCount = 0;
$recordCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM medical_records WHERE DATE(created_at) = ?');
$recordCountStmt->bind_param('s', $today);
$recordCountStmt->execute();
if ($row = $recordCountStmt->get_result()->fetch_assoc()) {
    $todayRecordCount = (int) $row['total'];
}
$recordCountStmt->close();

$todayLabCount = 0;
$labCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM lab_result_entries WHERE DATE(created_at) = ?');
$labCountStmt->bind_param('s', $today);
$labCountStmt->execute();
if ($row = $labCountStmt->get_result()->fetch_assoc()) {
    $todayLabCount = (int) $row['total'];
}
$labCountStmt->close();

$conn->close();

$totalToday = count($todayAppointments);
$activeQueue = $statusTotals['pending'] + $statusTotals['confirmed'];
$todayLabel = date('F d, Y');

$additionalStyles = patientAvatarStyles() . '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.nurse-dashboard {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 46px;
}

.nurse-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 16px;
    align-items: stretch;
    margin-bottom: 16px;
}

.hero-main,
.privacy-card,
.metric-card,
.panel,
.queue-row,
.shortcut-card {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
}

.hero-main {
    background: #073b4c;
    color: #fff;
    padding: 26px;
    display: grid;
    align-content: center;
    gap: 8px;
}

.eyebrow {
    margin: 0;
    color: #8bd3e6;
    font-size: 0.78rem;
    font-weight: 900;
    letter-spacing: 0;
    text-transform: uppercase;
}

.hero-main h1 {
    margin: 0;
    color: #fff;
    font-size: 2rem;
    line-height: 1.15;
}

.hero-main p {
    margin: 0;
    color: rgba(255, 255, 255, 0.82);
    line-height: 1.6;
}

.privacy-card {
    padding: 18px;
    border-color: #f2d58b;
    background: #fffaf0;
    display: grid;
    gap: 8px;
}

.privacy-card span {
    color: #856404;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.privacy-card strong {
    color: #073b4c;
    font-size: 1.05rem;
}

.privacy-card p {
    margin: 0;
    color: #5d6b73;
    line-height: 1.45;
    font-size: 0.92rem;
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
    grid-template-columns: repeat(5, minmax(0, 1fr));
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
    font-size: 0.8rem;
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

.metric-card.queue {
    border-color: #bfe6ce;
    background: #f5fbf7;
}

.metric-card.records {
    border-color: #bdd7ea;
    background: #f8fbff;
}

.workbench-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.12fr);
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

.patient-search {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    margin-bottom: 14px;
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

.btn,
.shortcut-card {
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

.btn:hover,
.shortcut-card:hover {
    transform: translateY(-1px);
}

.btn.primary {
    background: #0f7cc2;
    color: #fff;
}

.btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.btn.complete {
    background: #17643a;
    color: #fff;
}

.next-card {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #f8fbff;
    padding: 14px;
    display: grid;
    gap: 12px;
}

.next-card h3 {
    margin: 0;
    color: #073b4c;
    font-size: 1.25rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.detail-pill {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #fff;
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
    color: #1f343d;
    margin-top: 4px;
    font-size: 0.95rem;
}

.activity-list {
    display: grid;
    gap: 9px;
}

.activity-item {
    border-left: 3px solid #0f7cc2;
    background: #f8fbff;
    border-radius: 6px;
    padding: 10px 12px;
    display: grid;
    gap: 4px;
    color: inherit;
    text-decoration: none;
}

.activity-item strong {
    color: #073b4c;
}

.activity-item span {
    color: #60727d;
    font-size: 0.88rem;
}

.queue-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(150px, 220px);
    gap: 10px;
    margin-bottom: 14px;
}

.queue-list {
    display: grid;
    gap: 10px;
}

.queue-row {
    display: grid;
    grid-template-columns: 90px auto minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 14px;
}

.queue-row.hidden {
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

.patient-name {
    color: #073b4c;
    font-weight: 900;
    font-size: 1.05rem;
}

.queue-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-top: 6px;
    color: #60727d;
    font-size: 0.9rem;
}

.queue-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.empty-state {
    border: 1px dashed #bdd7ea;
    border-radius: 8px;
    padding: 18px;
    color: #60727d;
    background: #f8fbff;
}

.empty-state.hidden {
    display: none;
}

.dash-quick-actions {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.qa-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 12px;
    align-items: center;
    padding: 16px 18px;
    border-radius: 12px;
    text-decoration: none;
    color: inherit;
    border: 1px solid #dce8ef;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.qa-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(25, 76, 110, 0.1);
}

.qa-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-weight: 900;
    font-size: 1.1rem;
    color: #fff;
}

.qa-medical .qa-icon { background: linear-gradient(135deg, #0077b6, #023e8a); }
.qa-lab .qa-icon { background: linear-gradient(135deg, #2a9d8f, #1d6f63); }
.qa-patients .qa-icon { background: linear-gradient(135deg, #e76f51, #c44532); }
.qa-doctors .qa-icon { background: linear-gradient(135deg, #6a4c93, #4a3468); }

.qa-card strong {
    display: block;
    color: #073b4c;
    font-size: 1rem;
    margin-bottom: 2px;
}

.qa-card small {
    color: #60727d;
    font-size: 0.82rem;
    line-height: 1.35;
}

.metric-card.clickable {
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.metric-card.clickable:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(25, 76, 110, 0.1);
}

.metric-card.doctors-metric {
    border-color: #d4c4e8;
    background: #f9f6fc;
}

.metric-card.doctors-metric:hover {
    border-color: #6a4c93;
}

.doctor-metric-split {
    display: flex;
    gap: 16px;
    margin: 6px 0 4px;
}

.doctor-metric-split > span {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 0.78rem;
    font-weight: 700;
    color: #60727d;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.doctor-metric-split strong {
    font-size: 1.65rem;
    line-height: 1;
    letter-spacing: -0.02em;
}

.doctor-metric-split .active-num strong {
    color: #17643a;
}

.doctor-metric-split .inactive-num strong {
    color: #9d1c2c;
}

.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

.hero-actions a {
    display: inline-flex;
    align-items: center;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 800;
    font-size: 0.88rem;
    text-decoration: none;
    transition: transform 0.2s ease, background 0.2s ease;
}

.hero-actions .hero-cta-primary {
    background: #fff;
    color: #073b4c;
}

.hero-actions .hero-cta-secondary {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.35);
}

.hero-actions a:hover {
    transform: translateY(-1px);
}

.shortcut-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 16px;
}

.shortcut-card {
    color: inherit;
    background: #fff;
    justify-content: flex-start;
    align-items: flex-start;
    flex-direction: column;
    padding: 16px;
    gap: 6px;
}

.shortcut-card span {
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.shortcut-card strong {
    color: #073b4c;
    font-size: 1.02rem;
}

.shortcut-card small {
    color: #60727d;
    line-height: 1.4;
}

@media (max-width: 980px) {
    .nurse-hero,
    .workbench-grid,
    .clinical-layout,
    .metrics-grid,
    .shortcut-grid,
    .dash-quick-actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .clinical-layout {
        grid-template-columns: 1fr;
    }

    .hero-main {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .nurse-dashboard {
        padding: 18px 12px 36px;
    }

    .nurse-hero,
    .workbench-grid,
    .clinical-layout,
    .metrics-grid,
    .shortcut-grid,
    .dash-quick-actions,
    .clinical-tabs,
    .patient-search,
    .queue-toolbar,
    .detail-grid {
        grid-template-columns: 1fr;
    }

    .queue-row {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .queue-actions {
        justify-content: stretch;
    }

    .btn {
        width: 100%;
    }
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("queueSearch");
    const statusFilter = document.getElementById("queueStatusFilter");
    const noMatches = document.getElementById("queueNoMatches");

    function filterQueue() {
        const query = (searchInput && searchInput.value ? searchInput.value : "").toLowerCase().trim();
        const status = statusFilter ? statusFilter.value : "";
        let visible = 0;

        document.querySelectorAll("[data-queue-row]").forEach(function (row) {
            const haystack = (row.getAttribute("data-search") || "").toLowerCase();
            const rowStatus = row.getAttribute("data-status") || "";
            const show = (!query || haystack.indexOf(query) !== -1) && (!status || rowStatus === status);
            row.classList.toggle("hidden", !show);
            if (show) {
                visible++;
            }
        });

        if (noMatches) {
            noMatches.classList.toggle("hidden", visible !== 0);
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterQueue);
    }
    if (statusFilter) {
        statusFilter.addEventListener("change", filterQueue);
    }
    filterQueue();
});
';

include 'includes/header.php';
?>
<main class="nurse-dashboard">
    <section class="nurse-hero">
        <div class="hero-main">
            <p class="eyebrow">Clinical dashboard</p>
            <h1>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?></h1>
            <p>Manage patient queue status, care notes, and visit completion from one dashboard.</p>
        </div>
        <aside class="privacy-card" aria-label="Privacy reminder">
            <span>Patient data</span>
            <strong>View only what is needed for care.</strong>
            <p>Use patient records for clinic work only. Keep screens attended and open the full chart before adding sensitive notes.</p>
        </aside>
    </section>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="success-message"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="error-message"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <section class="metrics-grid" aria-label="Nurse summary">
        <div class="metric-card">
            <span>Total patients</span>
            <strong><?php echo $patientCount; ?></strong>
            <small>Registered records</small>
        </div>
        <div class="metric-card queue">
            <span>Active queue</span>
            <strong><?php echo $activeQueue; ?></strong>
            <small><?php echo $totalToday; ?> booked appointments</small>
        </div>
        <a class="metric-card records clickable" href="nurse_medical.php">
            <span>Notes today</span>
            <strong><?php echo $todayRecordCount; ?></strong>
            <small>Created today</small>
        </a>
        <a class="metric-card records clickable" href="nurse_lab.php" style="border-color:#c8ebe6;background:#f2faf8">
            <span>Results today</span>
            <strong><?php echo $todayLabCount; ?></strong>
            <small>Created today</small>
        </a>
        <a class="metric-card doctors-metric clickable" href="nurse_doctors.php">
            <span>Provider status</span>
            <div class="doctor-metric-split">
                <span class="active-num"><strong><?php echo $doctorActiveCount; ?></strong> Active</span>
                <span class="inactive-num"><strong><?php echo $doctorInactiveCount; ?></strong> Inactive</span>
            </div>
            <small><?php echo $doctorCount; ?> total provider accounts</small>
        </a>
    </section>


    <section class="workbench-grid">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Patient lookup</h2>
                    <p>Search before opening sensitive records.</p>
                </div>
            </div>
            <form class="patient-search" method="get" action="nurse_patients.php">
                <input type="search" name="q" placeholder="Search name, username, phone, or email" autocomplete="off">
                <button class="btn primary" type="submit">Search</button>
            </form>

            <div class="panel-head" style="margin-top:18px">
                <div>
                    <h2>Next patient</h2>
                    <p>Current queue priority</p>
                </div>
                <?php if ($nextPatient): ?>
                    <span class="status-badge <?php echo nurse_status($nextPatient); ?>">
                        <?php echo htmlspecialchars($nextPatient['status_label']); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($nextPatient): ?>
                <div class="next-card">
                    <div style="margin-bottom:12px"><?php echo renderPatientAvatarWithName($nextPatient, ['size' => 'lg', 'link' => true, 'link_target' => 'nurse', 'patient_id' => (int) $nextPatient['patient_id']]); ?></div>
                    <div class="detail-grid">
                        <div class="detail-pill">
                            <span>Date</span>
                            <strong><?php echo htmlspecialchars(nurse_date_label($nextPatient['appointment_date'])); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Queue note</span>
                            <strong><?php echo htmlspecialchars($nextPatient['queue_timing_label']); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Time</span>
                            <strong><?php echo nurse_time_label($nextPatient['appointment_time']); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Doctor</span>
                            <strong><?php echo htmlspecialchars($nextPatient['doctor_name'] ?: 'Not assigned'); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Age / Gender</span>
                            <strong><?php echo htmlspecialchars(($nextPatient['patient_age'] ?: 'N/A') . ' / ' . ($nextPatient['patient_gender'] ?: 'N/A')); ?></strong>
                        </div>
                        <div class="detail-pill">
                            <span>Phone</span>
                            <strong><?php echo htmlspecialchars($nextPatient['patient_phone'] ?: 'No phone'); ?></strong>
                        </div>
                    </div>
                    <div class="queue-actions">
                        <a class="btn primary" href="nurse_patient.php?id=<?php echo (int) $nextPatient['patient_id']; ?>">Open record</a>
                        <a class="btn secondary" href="nurse_medical.php?patient=<?php echo (int) $nextPatient['patient_id']; ?>">Add note</a>
                        <a class="btn secondary" href="nurse_lab.php?patient=<?php echo (int) $nextPatient['patient_id']; ?>">Add lab</a>
                        <?php if (nurse_status($nextPatient) !== 'completed' && nurse_status($nextPatient) !== 'cancelled'): ?>
                            <form method="post">
                                <input type="hidden" name="nurse_status_action" value="complete">
                                <input type="hidden" name="appointment_id" value="<?php echo (int) $nextPatient['id']; ?>">
                                <button class="btn complete" type="submit" onclick="return confirm('Mark this visit as completed?')">Complete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">No pending or confirmed patient in the queue.</div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Queue at a glance</h2>
                    <p>Current appointment and documentation totals.</p>
                </div>
            </div>
            <div class="activity-list">
                <div class="activity-item">
                    <strong><?php echo $activeQueue; ?> active in queue</strong>
                    <span><?php echo $statusTotals['pending']; ?> needs confirmation · <?php echo $statusTotals['confirmed']; ?> ready for care</span>
                </div>
                <div class="activity-item">
                    <strong><?php echo $statusTotals['completed']; ?> completed</strong>
                    <span><?php echo $statusTotals['cancelled']; ?> cancelled · <?php echo $totalToday; ?> booked appointments</span>
                </div>
                <div class="activity-item">
                    <strong><?php echo $todayRecordCount; ?> notes today · <?php echo $todayLabCount; ?> results today</strong>
                    <span>Use the header links when you need to open those modules.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Patient queue</h2>
                <p>All booked appointments, including upcoming patient visits.</p>
            </div>
            <a class="btn secondary" href="view_appointments.php">All appointments</a>
        </div>

        <?php if (empty($todayAppointments)): ?>
            <div class="empty-state">No booked appointments yet.</div>
        <?php else: ?>
            <div class="queue-toolbar" role="search" aria-label="Filter patient queue">
                <input type="search" id="queueSearch" placeholder="Search patient, doctor, date, phone, or notes">
                <select id="queueStatusFilter" aria-label="Filter queue status">
                    <option value="">All statuses</option>
                    <option value="pending">Needs confirmation</option>
                    <option value="confirmed">Ready for care</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div id="queueNoMatches" class="empty-state hidden">No queue item matches your search.</div>

            <div class="queue-list">
                <?php foreach ($todayAppointments as $appointment): ?>
                    <?php
                    $status = nurse_status($appointment);
                    $doctorName = $appointment['doctor_name'] ?: 'Not assigned';
                    $phone = $appointment['patient_phone'] ?: 'No phone';
                    $notes = nurse_short_text($appointment['notes'] ?? '');
                    $searchText = trim(implode(' ', [
                        $appointment['patient_name'] ?? '',
                        $doctorName,
                        $appointment['appointment_date'] ?? '',
                        nurse_date_label($appointment['appointment_date'] ?? ''),
                        $phone,
                        $appointment['patient_email'] ?? '',
                        $appointment['patient_gender'] ?? '',
                        (string) ($appointment['patient_age'] ?? ''),
                        $appointment['notes'] ?? '',
                        $status,
                    ]));
                    ?>
                    <article class="queue-row" data-queue-row data-status="<?php echo htmlspecialchars($status); ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                        <div class="time-block"><?php echo nurse_time_label($appointment['appointment_time']); ?></div>
                        <?php echo renderPatientAvatar($appointment, ['size' => 'md', 'link' => true, 'link_target' => 'nurse', 'patient_id' => (int) $appointment['patient_id']]); ?>
                        <div>
                            <div class="patient-name"><?php echo htmlspecialchars($appointment['patient_name']); ?></div>
                            <div class="queue-meta">
                                <span>Date: <?php echo htmlspecialchars(nurse_date_label($appointment['appointment_date'])); ?></span>
                                <span>Doctor: <?php echo htmlspecialchars($doctorName); ?></span>
                                <span>Phone: <?php echo htmlspecialchars($phone); ?></span>
                                <span>Age/Gender: <?php echo htmlspecialchars(($appointment['patient_age'] ?: 'N/A') . ' / ' . ($appointment['patient_gender'] ?: 'N/A')); ?></span>
                                <?php if ($notes !== 'None'): ?>
                                    <span>Notes: <?php echo htmlspecialchars($notes); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="queue-actions">
                            <span class="status-badge <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($appointment['status_label']); ?></span>
                            <a class="btn primary" href="nurse_patient.php?id=<?php echo (int) $appointment['patient_id']; ?>">Open record</a>
                            <a class="btn secondary" href="nurse_medical.php?patient=<?php echo (int) $appointment['patient_id']; ?>">Add note</a>
                            <?php if ($status !== 'completed' && $status !== 'cancelled'): ?>
                                <form method="post">
                                    <input type="hidden" name="nurse_status_action" value="complete">
                                    <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                    <button class="btn complete" type="submit" onclick="return confirm('Mark this visit as completed?')">Complete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>
<?php include 'includes/footer.php'; ?>









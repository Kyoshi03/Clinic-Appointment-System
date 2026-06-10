<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/mailer.php';

$pageTitle = 'Admin Settings | Globalife Administration';
$conn = getDBConnection();

function admin_settings_count(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    return (int) ($row['total'] ?? 0);
}

function admin_settings_rows(mysqli $conn, string $sql): array {
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$roleCounts = [
    'admin' => admin_settings_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'"),
    'doctor' => admin_settings_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'doctor'"),
    'nurse' => admin_settings_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'nurse'"),
    'receptionist' => admin_settings_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'receptionist'"),
    'patient' => admin_settings_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'patient'"),
];

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$nextMonth = date('Y-m-d', strtotime('+1 month', strtotime($monthStart)));
$activeDoctors = admin_settings_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role='doctor' AND COALESCE(is_active,1)=1");
$scheduledDoctors = admin_settings_count($conn, 'SELECT COUNT(DISTINCT user_id) AS total FROM doctor_availability');
$activeServices = admin_settings_count($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active=1');
$inactiveServices = admin_settings_count($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active=0');
$pendingAppointments = admin_settings_count($conn, "SELECT COUNT(*) AS total FROM appointments WHERE status = 'pending'");
$confirmedAppointments = admin_settings_count($conn, "SELECT COUNT(*) AS total FROM appointments WHERE status = 'confirmed'");
$completedThisMonth = admin_settings_count($conn, "SELECT COUNT(*) AS total FROM appointments WHERE status = 'completed' AND appointment_date >= '{$monthStart}' AND appointment_date < '{$nextMonth}'");
$pendingReminders = admin_settings_count($conn, 'SELECT COUNT(*) AS total FROM appointment_email_reminders WHERE sent_at IS NULL');
$failedReminders = admin_settings_count($conn, 'SELECT COUNT(*) AS total FROM appointment_email_reminders WHERE sent_at IS NULL AND attempts > 0');
$activeOtpCodes = admin_settings_count($conn, 'SELECT COUNT(*) AS total FROM password_reset_codes WHERE used_at IS NULL AND expires_at > NOW()');
$activeBookingCodes = admin_settings_count($conn, 'SELECT COUNT(*) AS total FROM appointment_booking_verifications WHERE used_at IS NULL AND expires_at > NOW()');
$dbTimezoneRow = $conn->query('SELECT @@session.time_zone AS timezone_name')->fetch_assoc();
$dbTimezone = (string) ($dbTimezoneRow['timezone_name'] ?? '+08:00');

$recentAppointments = admin_settings_rows($conn, "SELECT a.id, a.status, a.appointment_date, a.created_at, p.full_name AS patient_name
    FROM appointments a
    JOIN users p ON p.id = a.patient_id
    ORDER BY a.created_at DESC
    LIMIT 5");
$recentUsers = admin_settings_rows($conn, "SELECT full_name, role, created_at FROM users ORDER BY created_at DESC LIMIT 5");

$conn->close();

$mailConfigured = clinic_mail_ready();
$isHosting = DB_ENVIRONMENT === 'hosting';
$staffTotal = $roleCounts['admin'] + $roleCounts['doctor'] + $roleCounts['nurse'] + $roleCounts['receptionist'];

$reportCards = [
    ['report' => 'appointments', 'title' => 'Appointment Reports', 'desc' => 'Status, patient, doctor, date, time, booking type, and totals.'],
    ['report' => 'patients', 'title' => 'Patient Reports', 'desc' => 'Registered patients, contact details, demographics, and account creation dates.'],
    ['report' => 'services', 'title' => 'Service Reports', 'desc' => 'Laboratory packages, individual tests, prices, and active/inactive status.'],
    ['report' => 'monthly', 'title' => 'Monthly Statistics', 'desc' => 'Monthly appointment status counts and booking totals.'],
];

$settingsCards = [
    ['tag' => 'Clinic Information', 'title' => 'Clinic profile and readiness', 'text' => 'Review environment, timezone, active doctors, and service availability.', 'href' => '#clinic-information', 'meta' => $isHosting ? 'Production hosting' : 'Local XAMPP'],
    ['tag' => 'Appointment Settings', 'title' => 'Appointment slot settings', 'text' => 'Manage doctor schedules, slot availability, and appointment flow.', 'href' => 'admin_doctors.php', 'meta' => $scheduledDoctors . ' doctors scheduled'],
    ['tag' => 'Email & OTP Settings', 'title' => 'Email and OTP delivery', 'text' => 'Check SMTP readiness, password reset OTPs, booking verification codes, and reminders.', 'href' => '#email-otp', 'meta' => $mailConfigured ? 'Email ready' : 'Needs setup'],
    ['tag' => 'Staff & Roles', 'title' => 'Staff role management', 'text' => 'Create staff accounts and review admin, nurse, receptionist, and doctor roles.', 'href' => 'admin_accounts.php', 'meta' => $staffTotal . ' staff accounts'],
    ['tag' => 'Security Settings', 'title' => 'Security controls', 'text' => 'Audit login roles, OTP usage, staff access, and database safety reminders.', 'href' => '#security-settings', 'meta' => $activeOtpCodes . ' active reset OTPs'],
    ['tag' => 'Backup & Restore', 'title' => 'Backup and restore database', 'text' => 'Download a SQL backup. Restore should be done manually through phpMyAdmin after review.', 'href' => '#backup-restore', 'meta' => 'SQL backup ready'],
    ['tag' => 'Activity Logs', 'title' => 'Activity logs / audit trail', 'text' => 'Review recent bookings and recently created user accounts.', 'href' => '#activity-logs', 'meta' => count($recentAppointments) + count($recentUsers) . ' recent items'],
    ['tag' => 'System Preferences', 'title' => 'System preferences', 'text' => 'Operational links for accounts, appointments, laboratory services, and doctors.', 'href' => '#system-preferences', 'meta' => 'Admin controls'],
];

$additionalStyles = '
body { background:#f4f8fb; color:#1f343d; }
.settings-page { max-width:1180px; margin:0 auto; padding:28px 20px 48px; }
.settings-hero, .settings-card, .settings-panel, .report-card, .activity-row { border:1px solid #dce8ef; border-radius:8px; background:#fff; box-shadow:0 10px 24px rgba(25,76,110,.06); }
.settings-hero { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:16px; align-items:stretch; margin-bottom:16px; }
.hero-main { background:#073b4c; color:#fff; border-radius:8px; padding:28px; display:grid; align-content:center; gap:8px; }
.hero-main span { color:#8bd3e6; font-size:.78rem; font-weight:900; text-transform:uppercase; }
.hero-main h1 { margin:0; color:#fff; font-size:2.05rem; line-height:1.15; }
.hero-main p { margin:0; color:rgba(255,255,255,.82); line-height:1.6; max-width:760px; }
.hero-status { padding:20px; display:grid; gap:10px; align-content:center; background:#f8fcff; }
.hero-status span { color:#60727d; font-size:.78rem; font-weight:900; text-transform:uppercase; }
.hero-status strong { color:#073b4c; font-size:1.28rem; }
.status-dot { display:inline-flex; align-items:center; gap:8px; }
.status-dot::before { content:""; width:10px; height:10px; border-radius:50%; background:#17643a; }
.status-dot.warn::before { background:#c88a12; }
.summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
.summary-card { border:1px solid #dce8ef; border-radius:8px; background:#fff; padding:16px; box-shadow:0 10px 24px rgba(25,76,110,.06); }
.summary-card span { display:block; color:#60727d; font-size:.78rem; font-weight:900; text-transform:uppercase; }
.summary-card strong { display:block; margin-top:7px; color:#073b4c; font-size:1.6rem; line-height:1; }
.settings-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
.settings-card { position:relative; display:grid; gap:7px; min-height:136px; padding:18px 18px 54px; color:inherit; text-decoration:none; }
.settings-card:hover { border-color:#9ecbe0; background:#fbfdfe; transform:translateY(-1px); }
.settings-card span { color:#0878b8; font-size:.76rem; font-weight:900; text-transform:uppercase; }
.settings-card strong { color:#073b4c; font-size:1.08rem; }
.settings-card p { margin:0; color:#60727d; line-height:1.45; }
.settings-card small { color:#17643a; font-weight:900; }
.settings-card em { position:absolute; left:18px; bottom:16px; display:inline-flex; align-items:center; justify-content:center; min-height:30px; min-width:88px; border-radius:8px; background:#eef7ff; border:1px solid #d4e6f5; color:#0b4f80; font-style:normal; font-size:.82rem; font-weight:900; }
.panel-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
.settings-panel { padding:20px; }
.settings-panel h2 { margin:0 0 6px; color:#073b4c; font-size:1.25rem; }
.settings-panel p { margin:0 0 14px; color:#60727d; line-height:1.5; }
.detail-list { display:grid; }
.detail-row { display:flex; justify-content:space-between; gap:16px; padding:11px 0; border-bottom:1px solid #e4edf2; }
.detail-row:last-child { border-bottom:0; }
.detail-row span { color:#60727d; }
.detail-row strong { color:#073b4c; text-align:right; }
.btn-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
.settings-btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; min-width:132px; border-radius:8px; border:1px solid transparent; padding:9px 14px; background:#0f7cc2; color:#fff; font-weight:900; text-decoration:none; cursor:pointer; text-align:center; }
.settings-btn.secondary { background:#eef7ff; color:#0b4f80; border-color:#d4e6f5; }
.settings-btn.warn { background:#fff0f0; color:#9d1c2c; border-color:#ffd0d5; }
.report-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.report-card { padding:18px; }
.report-card h3 { margin:0 0 6px; color:#073b4c; font-size:1.08rem; }
.report-card p { margin:0; color:#60727d; line-height:1.45; }
.activity-list { display:grid; gap:9px; }
.activity-row { padding:12px 14px; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
.activity-row strong { color:#073b4c; }
.activity-row span { color:#60727d; font-size:.9rem; }
@media(max-width:900px){.settings-hero,.settings-grid,.panel-grid,.report-grid{grid-template-columns:1fr}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.settings-page{padding:20px 12px 38px}.summary-grid{grid-template-columns:1fr}.hero-main{padding:22px}.hero-main h1{font-size:1.7rem}.detail-row,.activity-row{flex-direction:column}.detail-row strong{text-align:left}.settings-btn{width:100%}}
';

include 'includes/header.php';
?>
<main class="settings-page">
    <section class="settings-hero">
        <div class="hero-main">
            <span>System settings</span>
            <h1>Admin Settings</h1>
            <p>Manage clinic setup, appointment slots, email and OTP delivery, staff access, backups, audit trail, and operational reports from one clean admin page.</p>
        </div>
        <div class="hero-status">
            <span>System readiness</span>
            <strong class="status-dot <?php echo $mailConfigured && $scheduledDoctors > 0 ? '' : 'warn'; ?>"><?php echo $mailConfigured && $scheduledDoctors > 0 ? 'Ready for daily operation' : 'Needs review'; ?></strong>
            <small><?php echo $isHosting ? 'Hostinger / Production' : 'Local XAMPP'; ?> | DB timezone <?php echo htmlspecialchars($dbTimezone); ?></small>
        </div>
    </section>

    <section class="settings-grid" aria-label="Settings modules">
        <?php foreach ($settingsCards as $card): ?>
            <a class="settings-card" href="<?php echo htmlspecialchars($card['href']); ?>">
                <span><?php echo htmlspecialchars($card['tag']); ?></span>
                <strong><?php echo htmlspecialchars($card['title']); ?></strong>
                <p><?php echo htmlspecialchars($card['text']); ?></p>
                <small><?php echo htmlspecialchars($card['meta']); ?></small>
                <em>Open</em>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="summary-grid" aria-label="Admin settings summary">
        <div class="summary-card"><span>Open appointments</span><strong><?php echo $pendingAppointments + $confirmedAppointments; ?></strong></div>
        <div class="summary-card"><span>Staff accounts</span><strong><?php echo $staffTotal; ?></strong></div>
        <div class="summary-card"><span>Active services</span><strong><?php echo $activeServices; ?></strong></div>
        <div class="summary-card"><span>This month done</span><strong><?php echo $completedThisMonth; ?></strong></div>
    </section>

    <section class="panel-grid">
        <div class="settings-panel" id="clinic-information">
            <h2>Clinic Information</h2>
            <p>Deployment and readiness values used by the whole clinic system.</p>
            <div class="detail-list">
                <div class="detail-row"><span>Environment</span><strong><?php echo $isHosting ? 'Hostinger / Production' : 'Local XAMPP'; ?></strong></div>
                <div class="detail-row"><span>Clinic timezone</span><strong>Asia/Manila</strong></div>
                <div class="detail-row"><span>Active doctors</span><strong><?php echo $activeDoctors; ?></strong></div>
                <div class="detail-row"><span>Inactive lab services</span><strong><?php echo $inactiveServices; ?></strong></div>
            </div>
        </div>

        <div class="settings-panel" id="email-otp">
            <h2>Email &amp; OTP Settings</h2>
            <p>Monitor SMTP, password reset OTPs, appointment verification, and reminder queue.</p>
            <div class="detail-list">
                <div class="detail-row"><span>SMTP email delivery</span><strong class="status-dot <?php echo $mailConfigured ? '' : 'warn'; ?>"><?php echo $mailConfigured ? 'Configured' : 'Needs setup'; ?></strong></div>
                <div class="detail-row"><span>Active password reset OTPs</span><strong><?php echo $activeOtpCodes; ?></strong></div>
                <div class="detail-row"><span>Active booking OTPs</span><strong><?php echo $activeBookingCodes; ?></strong></div>
                <div class="detail-row"><span>Pending reminders</span><strong><?php echo $pendingReminders; ?></strong></div>
                <div class="detail-row"><span>Failed reminder attempts</span><strong><?php echo $failedReminders; ?></strong></div>
            </div>
        </div>
    </section>

    <section class="panel-grid">
        <div class="settings-panel" id="security-settings">
            <h2>Security Settings</h2>
            <p>Role visibility and access-control counts for staff management.</p>
            <div class="detail-list">
                <div class="detail-row"><span>Admins</span><strong><?php echo $roleCounts['admin']; ?></strong></div>
                <div class="detail-row"><span>Doctors</span><strong><?php echo $roleCounts['doctor']; ?></strong></div>
                <div class="detail-row"><span>Nurses</span><strong><?php echo $roleCounts['nurse']; ?></strong></div>
                <div class="detail-row"><span>Receptionists</span><strong><?php echo $roleCounts['receptionist']; ?></strong></div>
            </div>
            <div class="btn-row"><a class="settings-btn" href="admin_accounts.php">Staff Role Management</a></div>
        </div>

        <div class="settings-panel" id="backup-restore">
            <h2>Backup &amp; Restore Database</h2>
            <p>Download a SQL backup before deployment or before editing clinic data. Restore is intentionally manual through phpMyAdmin so the live database is not overwritten by accident.</p>
            <div class="btn-row">
                <a class="settings-btn" href="admin_report_export.php?report=backup&amp;format=sql">Download SQL Backup</a>
                <a class="settings-btn secondary" href="#activity-logs">Review activity first</a>
            </div>
        </div>
    </section>

    <section class="settings-panel" id="reports">
        <h2>Reports &amp; Exports</h2>
        <p>Download appointment, patient, service, and monthly reports as PDF or Excel-compatible files.</p>
        <div class="report-grid">
            <?php foreach ($reportCards as $report): ?>
                <div class="report-card">
                    <h3><?php echo htmlspecialchars($report['title']); ?></h3>
                    <p><?php echo htmlspecialchars($report['desc']); ?></p>
                    <div class="btn-row">
                        <a class="settings-btn" href="admin_report_export.php?report=<?php echo urlencode($report['report']); ?>&amp;format=pdf">Export PDF</a>
                        <a class="settings-btn secondary" href="admin_report_export.php?report=<?php echo urlencode($report['report']); ?>&amp;format=excel">Export Excel</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel-grid" style="margin-top:16px">
        <div class="settings-panel" id="activity-logs">
            <h2>Activity Logs / Audit Trail</h2>
            <p>Recent appointment and account activity.</p>
            <div class="activity-list">
                <?php foreach ($recentAppointments as $item): ?>
                    <div class="activity-row"><strong><?php echo htmlspecialchars($item['patient_name']); ?></strong><span>Appointment #<?php echo (int) $item['id']; ?> | <?php echo htmlspecialchars($item['status']); ?> | <?php echo htmlspecialchars($item['created_at']); ?></span></div>
                <?php endforeach; ?>
                <?php foreach ($recentUsers as $item): ?>
                    <div class="activity-row"><strong><?php echo htmlspecialchars($item['full_name']); ?></strong><span>New <?php echo htmlspecialchars($item['role']); ?> account | <?php echo htmlspecialchars($item['created_at']); ?></span></div>
                <?php endforeach; ?>
                <?php if (empty($recentAppointments) && empty($recentUsers)): ?><p class="empty">No recent activity yet.</p><?php endif; ?>
            </div>
        </div>

        <div class="settings-panel" id="system-preferences">
            <h2>System Preferences</h2>
            <p>Important admin shortcuts for daily clinic configuration.</p>
            <div class="btn-row">
                <a class="settings-btn" href="view_appointments.php">Appointment Settings</a>
                <a class="settings-btn secondary" href="admin_doctors.php">Doctor Slots</a>
                <a class="settings-btn secondary" href="admin_lab_services.php">Service Catalog</a>
                <a class="settings-btn secondary" href="admin_accounts.php">Staff &amp; Roles</a>
            </div>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>

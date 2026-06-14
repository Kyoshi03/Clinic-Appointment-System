<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/admin_notifications.php';

$pageTitle = 'Administrator Dashboard | Globalife Medical Laboratory & Polyclinic';
$currentUser = getCurrentUser();
$today = date('Y-m-d');

function admin_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function admin_column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function admin_count_query(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if ($result && ($row = $result->fetch_assoc())) {
        return (int) ($row['total'] ?? 0);
    }
    return 0;
}

function admin_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M j, Y', $stamp) : '--';
}

function admin_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function admin_short_text(?string $text, int $limit = 64): string {
    $text = trim((string) $text);
    if ($text === '') {
        return 'None';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

$conn = getDBConnection();
init_admin_notifications($conn);
if (
    function_exists('initLabBookingSchema') &&
    (
        !admin_table_exists($conn, 'lab_services') ||
        !admin_table_exists($conn, 'medical_records') ||
        !admin_column_exists($conn, 'users', 'is_active')
    )
) {
    initLabBookingSchema($conn);
}

$message = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$showAdminNotificationsPage = isset($_GET['notifications']) && $_GET['notifications'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_admin_notifications_read'])) {
    mark_admin_notifications_read($conn);
    $_SESSION['success'] = 'Notifications marked as read.';
    header('Location: ' . ($showAdminNotificationsPage ? 'admin.php?notifications=1' : 'admin.php'));
    exit();
}

$roleCounts = [
    'admin' => 0,
    'nurse' => 0,
    'receptionist' => 0,
    'patient' => 0,
    'doctor' => 0,
];
$roleResult = $conn->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role');
if ($roleResult) {
    while ($row = $roleResult->fetch_assoc()) {
        $role = (string) $row['role'];
        if (isset($roleCounts[$role])) {
            $roleCounts[$role] = (int) $row['total'];
        }
    }
}

$appointmentStatus = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$statusResult = $conn->query('SELECT status, COUNT(*) AS total FROM appointments GROUP BY status');
if ($statusResult) {
    while ($row = $statusResult->fetch_assoc()) {
        $status = strtolower((string) $row['status']);
        if (isset($appointmentStatus[$status])) {
            $appointmentStatus[$status] = (int) $row['total'];
        }
    }
}

$todayStatus = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$todayStmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM appointments WHERE appointment_date = ? GROUP BY status');
$todayStmt->bind_param('s', $today);
$todayStmt->execute();
$todayResult = $todayStmt->get_result();
while ($row = $todayResult->fetch_assoc()) {
    $status = strtolower((string) $row['status']);
    if (isset($todayStatus[$status])) {
        $todayStatus[$status] = (int) $row['total'];
    }
}
$todayStmt->close();

$recentAppointments = [];
$recentResult = $conn->query("SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
                              a.patient_id,
                              p.full_name AS patient_name,
                              p.profile_photo,
                              p.profile_updated_at,
                              d.full_name AS doctor_name
                              FROM appointments a
                              JOIN users p ON p.id = a.patient_id
                              LEFT JOIN users d ON d.id = a.doctor_id
                              ORDER BY a.appointment_date DESC, a.appointment_time DESC
                              LIMIT 6");
if ($recentResult) {
    $recentAppointments = $recentResult->fetch_all(MYSQLI_ASSOC);
}

$upcomingAppointments = [];
$upcomingStmt = $conn->prepare("SELECT a.id, a.appointment_date, a.appointment_time, a.status,
                                a.patient_id,
                                p.full_name AS patient_name,
                                p.profile_photo,
                                p.profile_updated_at,
                                d.full_name AS doctor_name
                                FROM appointments a
                                JOIN users p ON p.id = a.patient_id
                                LEFT JOIN users d ON d.id = a.doctor_id
                                WHERE a.appointment_date >= ?
                                  AND a.status IN ('pending', 'confirmed')
                                ORDER BY a.appointment_date ASC, a.appointment_time ASC
                                LIMIT 5");
$upcomingStmt->bind_param('s', $today);
$upcomingStmt->execute();
$upcomingAppointments = $upcomingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$upcomingStmt->close();

$activeDoctors = admin_count_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'doctor' AND COALESCE(is_active, 1) = 1");
$inactiveDoctors = max(0, $roleCounts['doctor'] - $activeDoctors);
$activeLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 1');
$inactiveLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 0');
$packages = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 1');
$individualTests = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 0');
$medicalRecordCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM medical_records');
$labResultCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_result_entries');

$adminNotifications = fetch_admin_notifications($conn, $showAdminNotificationsPage ? 50 : 8);
$unreadNotificationCount = count_unread_admin_notifications($conn);

$conn->close();

$totalUsers = array_sum($roleCounts);
$totalAppointments = array_sum($appointmentStatus);
$todayTotal = array_sum($todayStatus);
$openAppointments = $appointmentStatus['pending'] + $appointmentStatus['confirmed'];
$staffTotal = $roleCounts['admin'] + $roleCounts['doctor'] + $roleCounts['nurse'] + $roleCounts['receptionist'];

$additionalStyles = patientAvatarStyles() . '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.admin-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 46px;
}

.admin-wrap > section {
    padding-top: 0;
    padding-bottom: 0;
}

.admin-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 280px;
    gap: 16px;
    align-items: stretch;
    margin-bottom: 16px;
}

.hero-main,
.hero-side,
.metric-card,
.panel,
.activity-item {
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

.hero-side {
    padding: 20px;
    background: #f8fcff;
    display: grid;
    align-content: center;
    gap: 8px;
}

.hero-side span {
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.hero-side strong {
    color: #073b4c;
    font-size: 1.45rem;
    line-height: 1.15;
}

.hero-side small {
    color: #60727d;
    font-weight: 700;
}

.message {
    border-radius: 8px;
    padding: 13px 14px;
    margin-bottom: 14px;
    font-weight: 800;
}

.message.ok {
    background: #e7f7ed;
    color: #17643a;
    border: 1px solid #bfe6ce;
}

.message.error {
    background: #fff0f0;
    color: #9d1c2c;
    border: 1px solid #ffd0d5;
}

.notification-panel {
    margin-bottom: 16px;
    padding: 20px;
}

.notification-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    border-radius: 999px;
    background: #0f7cc2;
    color: #fff;
    padding: 0 8px;
    font-size: 0.8rem;
    font-weight: 900;
}

.notification-list {
    display: grid;
    gap: 8px;
    max-height: 260px;
    overflow-y: auto;
    padding-right: 4px;
}

.notification-item {
    border: 1px solid #dce8ef;
    border-left: 4px solid #9fb5c2;
    border-radius: 8px;
    background: #fff;
    padding: 12px 14px;
}

.notification-item.unread {
    border-left-color: #0f7cc2;
    background: #f3f9fd;
}

.notification-item strong {
    display: block;
    color: #073b4c;
}

.notification-item p {
    margin: 4px 0;
    color: #4f6672;
    line-height: 1.45;
}

.notification-item time {
    color: #71838d;
    font-size: 0.8rem;
    font-weight: 700;
}

.admin-notifications-page {
    display: grid;
    gap: 16px;
}

.admin-notifications-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    border: 1px solid #d7eaf4;
    border-radius: 8px;
    padding: 24px;
    background:
        radial-gradient(circle at 92% 18%, rgba(72, 202, 228, 0.22), transparent 30%),
        linear-gradient(135deg, #ffffff 0%, #eefaff 100%);
    box-shadow: 0 16px 34px rgba(25, 76, 110, 0.08);
}

.admin-notifications-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #0077b6;
    font-size: 0.86rem;
    font-weight: 950;
    text-transform: uppercase;
}

.admin-notifications-hero h1 {
    margin: 8px 0 6px;
    color: #073b4c;
    font-size: 2rem;
    line-height: 1.12;
}

.admin-notifications-hero p {
    margin: 0;
    color: #58707d;
    line-height: 1.55;
}

.admin-unread-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 120px;
    min-height: 46px;
    border-radius: 999px;
    background: #eaf8ff;
    color: #0077b6;
    font-weight: 950;
}

.admin-notification-feed {
    display: grid;
    gap: 12px;
}

.admin-notification-card {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    border: 1px solid #dce8ef;
    border-radius: 8px;
    padding: 16px;
    background: #ffffff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.05);
}

.admin-notification-card.unread {
    border-color: #8ed9ef;
    background: #f2fbff;
}

.admin-notification-icon {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #e7f7ed;
    color: #17643a;
}

.admin-notification-icon svg {
    width: 22px;
    height: 22px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.admin-notification-card h3 {
    margin: 0;
    color: #073b4c;
    font-size: 1.02rem;
}

.admin-notification-card p {
    margin: 5px 0 8px;
    color: #58707d;
    line-height: 1.45;
}

.admin-notification-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    color: #71838d;
    font-size: 0.84rem;
    font-weight: 800;
}

.admin-notification-status {
    display: inline-flex;
    border-radius: 999px;
    padding: 4px 9px;
    background: #eaf8ff;
    color: #0077b6;
    font-size: 0.72rem;
    font-weight: 950;
    text-transform: uppercase;
}

.admin-notification-open {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    border-radius: 8px;
    padding: 0 14px;
    background: #eef8ff;
    color: #0b4f80;
    font-weight: 950;
    text-decoration: none;
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

.metric-card.alert {
    border-color: #f2d58b;
    background: #fffaf0;
}

.metric-card.ok {
    border-color: #bfe6ce;
    background: #f5fbf7;
}

.main-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
    gap: 16px;
    margin-bottom: 16px;
}

.panel {
    padding: 20px;
}

.panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
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

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px 13px;
    background: #0f7cc2;
    color: #fff;
    cursor: pointer;
    font-weight: 900;
    text-decoration: none;
    transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.btn:hover {
    background: #0b4f80;
    transform: translateY(-1px);
}

.btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.status-grid {
    display: grid;
    gap: 8px;
}

.status-row {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr) 42px;
    gap: 10px;
    align-items: center;
    color: #364d58;
    font-weight: 800;
}

.bar {
    height: 10px;
    border-radius: 999px;
    background: #edf3f7;
    overflow: hidden;
}

.bar span {
    display: block;
    height: 100%;
    min-width: 4px;
    border-radius: inherit;
    background: #0f7cc2;
}

.bar.pending span {
    background: #d09b21;
}

.bar.confirmed span,
.bar.completed span {
    background: #17643a;
}

.bar.cancelled span {
    background: #c1121f;
}

.activity-list {
    display: grid;
    gap: 9px;
}

.activity-item {
    padding: 12px;
    display: grid;
    gap: 4px;
}

.activity-item strong {
    color: #073b4c;
}

.activity-item span {
    color: #60727d;
    font-size: 0.9rem;
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 0.74rem;
    font-weight: 900;
    text-transform: uppercase;
}

.badge.pending {
    background: #fff3cd;
    color: #856404;
}

.badge.confirmed,
.badge.active {
    background: #e7f7ed;
    color: #17643a;
}

.badge.completed {
    background: #e8f4f8;
    color: #0b4f80;
}

.badge.cancelled,
.badge.inactive {
    background: #fff0f0;
    color: #9d1c2c;
}

.health-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.health-box {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 12px;
    background: #f8fbff;
}

.health-box span {
    display: block;
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.health-box strong {
    display: block;
    color: #073b4c;
    margin-top: 5px;
    font-size: 1.3rem;
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

@media (max-width: 980px) {
    .admin-hero,
    .main-grid,
    .metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hero-main {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .admin-wrap {
        padding: 18px 12px 36px;
    }

    .admin-notifications-hero,
    .admin-notification-card {
        grid-template-columns: 1fr;
    }

    .admin-notifications-hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .admin-notification-open {
        width: 100%;
    }

    .admin-hero,
    .main-grid,
    .metrics-grid,
    .health-grid {
        grid-template-columns: 1fr;
    }

    .status-row {
        grid-template-columns: 92px minmax(0, 1fr) 34px;
    }

    .btn {
        width: 100%;
    }
}
';

include 'includes/header.php';
?>
<main class="admin-wrap">
    <section class="admin-hero">
        <div class="hero-main">
            <p class="eyebrow">System administration</p>
            <h1>Administrator Dashboard</h1>
            <p>Monitor users, appointments, doctors, lab services, and clinical records from one control board.</p>
        </div>
        <aside class="hero-side" aria-label="Current admin">
            <span>Signed in as</span>
            <strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong>
            <small><?php echo htmlspecialchars(date('F d, Y')); ?></small>
        </aside>
    </section>

    <?php if ($message): ?>
        <div class="message ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($showAdminNotificationsPage): ?>
        <section class="admin-notifications-page" aria-labelledby="adminNotificationsTitle">
            <div class="admin-notifications-hero">
                <div>
                    <span class="admin-notifications-kicker">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7M10 20a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Admin notifications
                    </span>
                    <h1 id="adminNotificationsTitle">Account and system notifications</h1>
                    <p>New patient accounts, reception desk QR registrations, and important admin updates appear here.</p>
                </div>
                <div class="admin-notifications-actions">
                    <span class="admin-unread-pill"><?php echo (int) $unreadNotificationCount; ?> unread</span>
                    <?php if ($unreadNotificationCount > 0): ?>
                        <form method="post">
                            <button class="btn secondary" type="submit" name="mark_admin_notifications_read" value="1">Mark all as read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($adminNotifications)): ?>
                <div class="empty-state">No admin notifications yet.</div>
            <?php else: ?>
                <div class="admin-notification-feed">
                    <?php foreach ($adminNotifications as $notification): ?>
                        <?php
                        $notificationId = (int) ($notification['id'] ?? 0);
                        $notificationType = strtolower((string) ($notification['notification_type'] ?? ''));
                        $notificationTime = strtotime((string) ($notification['created_at'] ?? ''));
                        $notificationDate = $notificationTime ? date('M d, Y g:i A', $notificationTime) : '';
                        $isUnread = empty($notification['read_at']);
                        $statusLabel = str_replace('_', ' ', $notificationType !== '' ? $notificationType : 'admin update');
                        ?>
                        <article id="notification-<?php echo $notificationId; ?>" class="admin-notification-card <?php echo $isUnread ? 'unread' : ''; ?>">
                            <span class="admin-notification-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><path d="M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M19 8v6M22 11h-6"/></svg>
                            </span>
                            <div>
                                <h3><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Admin notification')); ?></h3>
                                <p><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                                <span class="admin-notification-meta">
                                    <?php if ($notificationDate !== ''): ?>
                                        <span><?php echo htmlspecialchars($notificationDate); ?></span>
                                    <?php endif; ?>
                                    <span class="admin-notification-status"><?php echo htmlspecialchars($statusLabel); ?></span>
                                    <span><?php echo $isUnread ? 'Unread' : 'Read'; ?></span>
                                </span>
                            </div>
                            <a class="admin-notification-open" href="admin_accounts.php">Open Accounts</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php include 'includes/footer.php'; ?>
    <?php exit; ?>
    <?php endif; ?>

    <section class="panel notification-panel">
        <div class="panel-head">
            <div>
                <h2>Admin notifications <span class="notification-count"><?php echo $unreadNotificationCount; ?></span></h2>
                <p>Verified patient accounts created online or from the reception desk QR code.</p>
            </div>
            <?php if ($unreadNotificationCount > 0): ?>
                <form method="post">
                    <button class="btn secondary" type="submit" name="mark_admin_notifications_read" value="1">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (empty($adminNotifications)): ?>
            <div class="empty-state">No account notifications yet.</div>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($adminNotifications as $notification): ?>
                    <article class="notification-item <?php echo empty($notification['read_at']) ? 'unread' : ''; ?>">
                        <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                        <p><?php echo htmlspecialchars($notification['message']); ?></p>
                        <time datetime="<?php echo htmlspecialchars($notification['created_at']); ?>">
                            <?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($notification['created_at']))); ?>
                        </time>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="metrics-grid" aria-label="System overview">
        <div class="metric-card">
            <span>Total users</span>
            <strong><?php echo $totalUsers; ?></strong>
            <small><?php echo $staffTotal; ?> staff, <?php echo $roleCounts['patient']; ?> patients</small>
        </div>
        <div class="metric-card alert">
            <span>Open appointments</span>
            <strong><?php echo $openAppointments; ?></strong>
            <small><?php echo $appointmentStatus['pending']; ?> pending, <?php echo $appointmentStatus['confirmed']; ?> confirmed</small>
        </div>
        <div class="metric-card ok">
            <span>Today</span>
            <strong><?php echo $todayTotal; ?></strong>
            <small><?php echo $todayStatus['completed']; ?> completed today</small>
        </div>
        <div class="metric-card">
            <span>Clinical records</span>
            <strong><?php echo $medicalRecordCount + $labResultCount; ?></strong>
            <small><?php echo $medicalRecordCount; ?> notes, <?php echo $labResultCount; ?> labs</small>
        </div>
    </section>

    <section class="main-grid">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Appointment status</h2>
                    <p>All appointment records in the system.</p>
                </div>
                <a class="btn secondary" href="view_appointments.php">Open</a>
            </div>
            <div class="status-grid">
                <?php foreach ($appointmentStatus as $status => $count): ?>
                    <?php $percent = $totalAppointments > 0 ? max(4, round(($count / $totalAppointments) * 100)) : 0; ?>
                    <div class="status-row">
                        <span><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                        <div class="bar <?php echo htmlspecialchars($status); ?>">
                            <span style="width:<?php echo $percent; ?>%"></span>
                        </div>
                        <span><?php echo $count; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>System health</h2>
                    <p>Important setup counts for thesis demo.</p>
                </div>
            </div>
            <div class="health-grid">
                <div class="health-box">
                    <span>Active doctors</span>
                    <strong><?php echo $activeDoctors; ?></strong>
                </div>
                <div class="health-box">
                    <span>Inactive doctors</span>
                    <strong><?php echo $inactiveDoctors; ?></strong>
                </div>
                <div class="health-box">
                    <span>Active lab services</span>
                    <strong><?php echo $activeLabServices; ?></strong>
                </div>
                <div class="health-box">
                    <span>Inactive lab services</span>
                    <strong><?php echo $inactiveLabServices; ?></strong>
                </div>
                <div class="health-box">
                    <span>Package deals</span>
                    <strong><?php echo $packages; ?></strong>
                </div>
                <div class="health-box">
                    <span>Individual tests</span>
                    <strong><?php echo $individualTests; ?></strong>
                </div>
            </div>
        </div>
    </section>

    <section class="main-grid">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Upcoming attention</h2>
                    <p>Pending and confirmed appointments from today onward.</p>
                </div>
            </div>
            <?php if (empty($upcomingAppointments)): ?>
                <div class="empty-state">No open upcoming appointments.</div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($upcomingAppointments as $appointment): ?>
                        <div class="activity-item" style="display:flex;align-items:flex-start;gap:10px">
                            <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) ($appointment['patient_id'] ?? 0)]); ?>
                            <div>
                            <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                            <span><?php echo htmlspecialchars(admin_date_label($appointment['appointment_date']) . ' ' . admin_time_label($appointment['appointment_time'])); ?></span>
                            <span>Doctor: <?php echo htmlspecialchars($appointment['doctor_name'] ?: 'Not assigned'); ?></span>
                            <span class="badge <?php echo htmlspecialchars($appointment['status']); ?>"><?php echo htmlspecialchars(ucfirst($appointment['status'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Recent appointments</h2>
                    <p>Latest booking activity.</p>
                </div>
            </div>
            <?php if (empty($recentAppointments)): ?>
                <div class="empty-state">No appointment records yet.</div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($recentAppointments as $appointment): ?>
                        <?php
                        $adminBookingType = (string) ($appointment['booking_type'] ?? '');
                        $adminBookingLabel = [
                            'consultation' => 'Doctor consultation',
                            'package' => 'Laboratory package',
                            'individual' => 'Laboratory tests',
                        ][$adminBookingType] ?? 'General appointment';
                        ?>
                        <div class="activity-item" style="display:flex;align-items:flex-start;gap:10px">
                            <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) ($appointment['patient_id'] ?? 0)]); ?>
                            <div>
                            <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                            <span><?php echo htmlspecialchars(admin_date_label($appointment['appointment_date']) . ' ' . admin_time_label($appointment['appointment_time'])); ?></span>
                            <span><?php echo htmlspecialchars(admin_short_text($adminBookingLabel)); ?></span>
                            <span class="badge <?php echo htmlspecialchars($appointment['status']); ?>"><?php echo htmlspecialchars(ucfirst($appointment['status'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

</main>
<?php include 'includes/footer.php'; ?>

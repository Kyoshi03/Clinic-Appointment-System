<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_user_action'])) {
    $action = $_POST['admin_user_action'];

    if ($action === 'add_staff') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $allowedStaffRoles = ['admin', 'nurse', 'receptionist'];

        if ($username === '' || $password === '' || $fullName === '' || !in_array($role, $allowedStaffRoles, true)) {
            $_SESSION['error'] = 'Please complete username, password, full name, and staff role.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (username, password, full_name, role, email, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
            $stmt->bind_param('ssssss', $username, $hash, $fullName, $role, $email, $phone);

            if ($stmt->execute()) {
                $_SESSION['success'] = 'Staff account created.';
            } else {
                $_SESSION['error'] = $conn->errno === 1062 ? 'Username already exists.' : 'Failed to create staff account.';
            }

            $stmt->close();
        }
    }

    $conn->close();
    header('Location: admin.php#user-directory');
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

$users = [];
$userResult = $conn->query("SELECT id, username, full_name, role, email, phone, profile_photo, profile_updated_at, COALESCE(is_active, 1) AS is_active
                            FROM users
                            ORDER BY FIELD(role, 'admin', 'doctor', 'nurse', 'receptionist', 'patient'), full_name ASC
                            LIMIT 120");
if ($userResult) {
    $users = $userResult->fetch_all(MYSQLI_ASSOC);
}

$activeDoctors = admin_count_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'doctor' AND COALESCE(is_active, 1) = 1");
$inactiveDoctors = max(0, $roleCounts['doctor'] - $activeDoctors);
$activeLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 1');
$inactiveLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 0');
$packages = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 1');
$individualTests = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 0');
$medicalRecordCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM medical_records');
$labResultCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_result_entries');

$conn->close();

$totalUsers = array_sum($roleCounts);
$totalAppointments = array_sum($appointmentStatus);
$todayTotal = array_sum($todayStatus);
$openAppointments = $appointmentStatus['pending'] + $appointmentStatus['confirmed'];
$staffTotal = $roleCounts['admin'] + $roleCounts['doctor'] + $roleCounts['nurse'] + $roleCounts['receptionist'];

$additionalStyles = patientAvatarStyles() . '
.user-row{display:flex;align-items:center;gap:14px}
body {
    background: #f4f8fb;
    color: #1f343d;
}

.admin-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 46px;
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
.action-card,
.user-row,
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

.quick-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.action-card {
    padding: 16px;
    color: inherit;
    text-decoration: none;
    display: grid;
    gap: 6px;
}

.action-card span {
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.action-card strong {
    color: #073b4c;
    font-size: 1.02rem;
}

.action-card small {
    color: #60727d;
    line-height: 1.4;
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

.user-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(150px, 220px);
    gap: 10px;
    margin-bottom: 14px;
}

.staff-form {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
    padding: 14px;
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #f8fbff;
}

.staff-form .wide {
    grid-column: span 2;
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

.user-list {
    display: grid;
    gap: 9px;
    max-height: 520px;
    overflow: auto;
    padding-right: 4px;
}

.user-row {
    padding: 13px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
}

.user-row.hidden {
    display: none;
}

.user-name {
    color: #073b4c;
    font-weight: 900;
}

.user-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    color: #60727d;
    font-size: 0.88rem;
    margin-top: 5px;
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
    .metrics-grid,
    .quick-grid {
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

    .admin-hero,
    .main-grid,
    .metrics-grid,
    .quick-grid,
    .health-grid,
    .user-toolbar,
    .staff-form,
    .user-row {
        grid-template-columns: 1fr;
    }

    .staff-form .wide {
        grid-column: auto;
    }

    .status-row {
        grid-template-columns: 92px minmax(0, 1fr) 34px;
    }

    .btn {
        width: 100%;
    }
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("userSearch");
    const roleFilter = document.getElementById("userRoleFilter");
    const noMatches = document.getElementById("userNoMatches");

    function filterUsers() {
        const query = (searchInput && searchInput.value ? searchInput.value : "").toLowerCase().trim();
        const role = roleFilter ? roleFilter.value : "";
        let visible = 0;

        document.querySelectorAll("[data-user-row]").forEach(function (row) {
            const haystack = (row.getAttribute("data-search") || "").toLowerCase();
            const rowRole = row.getAttribute("data-role") || "";
            const show = (!query || haystack.indexOf(query) !== -1) && (!role || rowRole === role);
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
        searchInput.addEventListener("input", filterUsers);
    }
    if (roleFilter) {
        roleFilter.addEventListener("change", filterUsers);
    }
    filterUsers();
});
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

    <section class="quick-grid" aria-label="Quick admin actions">
        <a class="action-card" href="#user-directory">
            <span>Users</span>
            <strong>User directory</strong>
            <small>Search staff and patient accounts.</small>
        </a>
        <a class="action-card" href="view_appointments.php">
            <span>Appointments</span>
            <strong>Appointment control</strong>
            <small>Monitor and update booking statuses.</small>
        </a>
        <a class="action-card" href="admin_lab_services.php">
            <span>Laboratory</span>
            <strong>Lab services</strong>
            <small>Manage packages, prices, and active services.</small>
        </a>
        <a class="action-card" href="admin_doctors.php">
            <span>Doctors</span>
            <strong>Doctor accounts</strong>
            <small>Manage profiles, active status, and clinic hours.</small>
        </a>
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
                        <div class="activity-item" style="display:flex;align-items:flex-start;gap:10px">
                            <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) ($appointment['patient_id'] ?? 0)]); ?>
                            <div>
                            <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                            <span><?php echo htmlspecialchars(admin_date_label($appointment['appointment_date']) . ' ' . admin_time_label($appointment['appointment_time'])); ?></span>
                            <span><?php echo htmlspecialchars(admin_short_text($appointment['booking_type'] ?: 'General appointment')); ?></span>
                            <span class="badge <?php echo htmlspecialchars($appointment['status']); ?>"><?php echo htmlspecialchars(ucfirst($appointment['status'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel" id="user-directory">
        <div class="panel-head">
            <div>
                <h2>User directory</h2>
                <p>Create staff accounts, then search and review system users. Doctor profile edits are handled in Doctors.</p>
            </div>
        </div>

        <form class="staff-form" method="post">
            <input type="hidden" name="admin_user_action" value="add_staff">
            <input name="full_name" class="wide" placeholder="Full name" required>
            <select name="role" required>
                <option value="">Staff role</option>
                <option value="admin">Admin</option>
                <option value="nurse">Nurse</option>
                <option value="receptionist">Receptionist</option>
            </select>
            <input name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="email" name="email" placeholder="Email">
            <input name="phone" placeholder="Phone">
            <button class="btn" type="submit">Create staff</button>
        </form>

        <div class="user-toolbar" role="search" aria-label="Filter users">
            <input type="search" id="userSearch" placeholder="Search name, username, email, or phone">
            <select id="userRoleFilter" aria-label="Filter user role">
                <option value="">All roles</option>
                <option value="admin">Admin</option>
                <option value="doctor">Doctor</option>
                <option value="nurse">Nurse</option>
                <option value="receptionist">Receptionist</option>
                <option value="patient">Patient</option>
            </select>
        </div>
        <div id="userNoMatches" class="empty-state hidden">No users match your search.</div>

        <?php if (empty($users)): ?>
            <div class="empty-state">No user accounts found.</div>
        <?php else: ?>
            <div class="user-list">
                <?php foreach ($users as $user): ?>
                    <?php
                    $role = (string) $user['role'];
                    $active = (int) $user['is_active'] === 1;
                    $searchText = trim(implode(' ', [
                        $user['full_name'] ?? '',
                        $user['username'] ?? '',
                        $user['email'] ?? '',
                        $user['phone'] ?? '',
                        $role,
                    ]));
                    ?>
                    <article class="user-row" data-user-row data-role="<?php echo htmlspecialchars($role); ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                        <?php if ($role === 'patient'): ?>
                            <?php echo renderPatientAvatar($user, ['size' => 'md', 'link' => true, 'patient_id' => (int) $user['id']]); ?>
                        <?php endif; ?>
                        <div>
                            <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="user-meta">
                                <span><?php echo htmlspecialchars($user['username']); ?></span>
                                <?php if (!empty($user['email'])): ?><span><?php echo htmlspecialchars($user['email']); ?></span><?php endif; ?>
                                <?php if (!empty($user['phone'])): ?><span><?php echo htmlspecialchars($user['phone']); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="user-meta">
                            <span class="badge active"><?php echo htmlspecialchars(ucfirst($role)); ?></span>
                            <?php if ($role === 'doctor'): ?>
                                <span class="badge <?php echo $active ? 'active' : 'inactive'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
                                <a class="btn secondary" href="admin_doctors.php?edit=<?php echo (int) $user['id']; ?>">Edit</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php'; ?>

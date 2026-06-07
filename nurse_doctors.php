<?php
require_once 'includes/session.php';
checkRole('nurse');

require_once 'config/database.php';
require_once __DIR__ . '/includes/doctor_schedule.php';

$pageTitle = 'Doctors & schedules | Nurse';
$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);

$message = '';
$error = '';
$dayNames = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

function rd_normalize_time(string $time): string {
    $time = trim($time);
    return strlen($time) === 5 ? $time . ':00' : $time;
}

function rd_time_label(string $time): string {
    return doctor_format_time_hm(rd_normalize_time($time));
}

function rd_slot_label(array $slot, array $dayNames): string {
    $day = $dayNames[(int) ($slot['day_of_week'] ?? 0)] ?? 'Day';
    return $day . ', ' . rd_time_label((string) $slot['time_start']) . ' - ' . rd_time_label((string) $slot['time_end']);
}

function nd_parse_schedule_slots(): array {
    $days = $_POST['slot_day'] ?? [];
    $starts = $_POST['slot_start'] ?? [];
    $ends = $_POST['slot_end'] ?? [];
    $slots = [];
    $error = '';

    $rowCount = max(count($days), count($starts), count($ends));
    for ($i = 0; $i < $rowCount; $i++) {
        $day = (int) ($days[$i] ?? 0);
        $start = trim((string) ($starts[$i] ?? ''));
        $end = trim((string) ($ends[$i] ?? ''));

        if ($day < 1 && $start === '' && $end === '') {
            continue;
        }

        if ($day < 1 || $day > 7 || $start === '' || $end === '') {
            $error = 'Please complete every schedule row before saving.';
            break;
        }

        $startDb = rd_normalize_time($start);
        $endDb = rd_normalize_time($end);
        if (strtotime($startDb) === false || strtotime($endDb) === false || strtotime($startDb) >= strtotime($endDb)) {
            $error = 'Start time must be earlier than end time.';
            break;
        }

        $slots[] = [$day, $startDb, $endDb];
    }

    if ($error === '' && empty($slots)) {
        $error = 'Add at least one clinic schedule row.';
    }

    if ($error === '') {
        usort($slots, static function (array $a, array $b): int {
            return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
        });

        $lastByDay = [];
        foreach ($slots as $slot) {
            [$day, $startDb, $endDb] = $slot;
            if (isset($lastByDay[$day]) && strtotime($startDb) < strtotime($lastByDay[$day])) {
                $error = 'Schedule rows cannot overlap on the same day.';
                break;
            }
            $lastByDay[$day] = $endDb;
        }
    }

    return ['slots' => $slots, 'error' => $error];
}

function nd_doctor_is_active(mixed $value): bool {
    if ($value === null || $value === '') {
        return true;
    }
    return (int) $value === 1;
}

function nd_doctor_toggle_url(int $doctorId, int $setActive, bool $reopenEdit = false): string {
    $url = 'nurse_doctors.php?toggle_id=' . $doctorId . '&set_active=' . $setActive;
    if ($reopenEdit) {
        $url .= '&open_edit=1';
    }
    return $url;
}

function nd_doctor_status_counts(mysqli $conn): array {
    $stats = $conn->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(is_active, 1) = 1 THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN COALESCE(is_active, 1) = 0 THEN 1 ELSE 0 END) AS inactive_count
        FROM users WHERE role = 'doctor'");
    $row = $stats ? $stats->fetch_assoc() : null;
    return [
        'total' => (int) ($row['total'] ?? 0),
        'active' => (int) ($row['active_count'] ?? 0),
        'inactive' => (int) ($row['inactive_count'] ?? 0),
    ];
}

function nd_set_doctor_active_status(mysqli $conn, int $doctorId, int $newActive): array {
    if ($doctorId <= 0) {
        return ['ok' => false, 'error' => 'Invalid doctor.'];
    }
    if ($newActive !== 0 && $newActive !== 1) {
        return ['ok' => false, 'error' => 'Invalid status request.'];
    }

    if (!doctor_sched_column_exists($conn, 'users', 'is_active')) {
        $conn->query('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
    }

    $chk = $conn->prepare("SELECT id, is_active FROM users WHERE id = ? AND role = 'doctor' LIMIT 1");
    $chk->bind_param('i', $doctorId);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
        return ['ok' => false, 'error' => 'Doctor not found.'];
    }

    $upd = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'doctor'");
    $upd->bind_param('ii', $newActive, $doctorId);
    if (!$upd->execute()) {
        $upd->close();
        return ['ok' => false, 'error' => 'Database error while updating status.'];
    }
    $upd->close();

    $ver = $conn->prepare("SELECT is_active FROM users WHERE id = ? AND role = 'doctor' LIMIT 1");
    $ver->bind_param('i', $doctorId);
    $ver->execute();
    $saved = $ver->get_result()->fetch_assoc();
    $ver->close();

    if (!$saved || (int) $saved['is_active'] != $newActive) {
        return ['ok' => false, 'error' => 'Status was not saved. Please try again.'];
    }

    return ['ok' => true, 'new_active' => $newActive];
}

function nd_save_doctor_slots(mysqli $conn, int $doctorId, array $slots): bool {
    $delete = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
    $delete->bind_param('i', $doctorId);
    $delete->execute();
    $delete->close();

    if (empty($slots)) {
        return true;
    }

    $insert = $conn->prepare('INSERT INTO doctor_availability (user_id, day_of_week, time_start, time_end) VALUES (?, ?, ?, ?)');
    foreach ($slots as $slot) {
        [$day, $startDb, $endDb] = $slot;
        $insert->bind_param('iiss', $doctorId, $day, $startDb, $endDb);
        $insert->execute();
    }
    $insert->close();

    return true;
}

if (isset($_GET['toggle_id'], $_GET['set_active'])) {
    $toggleId = (int) $_GET['toggle_id'];
    $setActive = (int) $_GET['set_active'];
    if ($toggleId > 0 && ($setActive === 0 || $setActive === 1)) {
        $result = nd_set_doctor_active_status($conn, $toggleId, $setActive);
        if ($result['ok']) {
            $counts = nd_doctor_status_counts($conn);
            $_SESSION['nurse_doctor_flash'] = ($setActive === 1 ? 'Doctor activated.' : 'Doctor deactivated.')
                . ' Current count: ' . $counts['active'] . ' active, ' . $counts['inactive'] . ' inactive.';
            if (!empty($_GET['open_edit'])) {
                header('Location: nurse_doctors.php?open_edit=' . $toggleId);
            } else {
                header('Location: nurse_doctors.php?updated=' . $toggleId);
            }
        } else {
            $_SESSION['nurse_doctor_error'] = $result['error'];
            header('Location: nurse_doctors.php');
        }
        $conn->close();
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doctor_schedule_action'])) {
    $action = $_POST['doctor_schedule_action'];
    $doctorId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'save_slots') {
        if ($doctorId <= 0) {
            $error = 'Invalid doctor.';
        } else {
            $parsed = nd_parse_schedule_slots();
            $slots = $parsed['slots'];
            $error = $parsed['error'];

            if ($error === '') {
                $conn->begin_transaction();
                try {
                    nd_save_doctor_slots($conn, $doctorId, $slots);
                    $conn->commit();
                    $_SESSION['nurse_doctor_flash'] = 'Doctor schedule updated.';
                    header('Location: nurse_doctors.php?open_edit=' . $doctorId);
                    $conn->close();
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'Failed to update schedule.';
                }
            }
        }
    } elseif ($action === 'clear_slots') {
        if ($doctorId <= 0) {
            $error = 'Invalid doctor.';
        } else {
            $delete = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
            $delete->bind_param('i', $doctorId);
            if ($delete->execute()) {
                $_SESSION['nurse_doctor_flash'] = 'Doctor schedule cleared.';
                header('Location: nurse_doctors.php?open_edit=' . $doctorId);
                $conn->close();
                exit;
            }
            $error = 'Failed to clear schedule.';
            $delete->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nurse_doctor_action'])) {
    $action = (string) $_POST['nurse_doctor_action'];
    $doctorId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'add_doctor') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $specialty = trim((string) ($_POST['specialty'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $defaultPassword = 'password123';

        if ($fullName === '') {
            $error = 'Full name is required.';
        } else {
            $parsed = nd_parse_schedule_slots();
            if ($parsed['error'] !== '') {
                $error = $parsed['error'];
            } else {
                $slots = $parsed['slots'];
                $baseUsername = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '.', $fullName), '.'));
                if ($baseUsername === '') $baseUsername = 'doctor';
                if (strpos($baseUsername, 'dr.') !== 0 && strpos($baseUsername, 'dra.') !== 0) $baseUsername = 'dr.' . $baseUsername;
                $username = $baseUsername;
                $suffix = 2;
                $check = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                while (true) {
                    $check->bind_param('s', $username);
                    $check->execute();
                    if ($check->get_result()->num_rows === 0) break;
                    $username = $baseUsername . '.' . $suffix;
                    $suffix++;
                }
                $check->close();
                $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, specialty, email, phone, is_active) VALUES (?, ?, ?, 'doctor', ?, ?, ?, 1)");
                    $stmt->bind_param('ssssss', $username, $hash, $fullName, $specialty, $email, $phone);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('insert_failed');
                    }
                    $newId = (int) $conn->insert_id;
                    $stmt->close();

                    nd_save_doctor_slots($conn, $newId, $slots);

                    $conn->commit();
                    $_SESSION['nurse_doctor_flash'] = 'Doctor account and schedule created successfully. Username: ' . $username . ' | Default password: ' . $defaultPassword;
                    header('Location: nurse_doctors.php');
                    $conn->close();
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $_SESSION['nurse_doctor_error'] = 'Failed to create doctor account.';
                    header('Location: nurse_doctors.php?add=1');
                    $conn->close();
                    exit;
                }
            }
        }
    } elseif ($action === 'save_profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $specialty = trim((string) ($_POST['specialty'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        if ($doctorId <= 0) {
            $error = 'Invalid doctor.';
        } elseif ($fullName === '') {
            $error = 'Full name is required.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, specialty = ?, email = ?, phone = ? WHERE id = ? AND role = 'doctor'");
            $stmt->bind_param('ssssi', $fullName, $specialty, $email, $phone, $doctorId);
            if ($stmt->execute()) {
                $_SESSION['nurse_doctor_flash'] = 'Doctor profile updated.';
                header('Location: nurse_doctors.php?open_edit=' . $doctorId);
                $conn->close();
                exit;
            }
            $error = 'Failed to update doctor profile.';
            $stmt->close();
        }
    } elseif ($action === 'toggle_active') {
        $newActive = isset($_POST['set_active']) ? (int) $_POST['set_active'] : -1;
        $result = nd_set_doctor_active_status($conn, $doctorId, $newActive);

        if ($result['ok']) {
            $activated = (int) $result['new_active'] === 1;
            $counts = nd_doctor_status_counts($conn);
            $_SESSION['nurse_doctor_flash'] = ($activated ? 'Doctor activated.' : 'Doctor deactivated.')
                . ' Dashboard count: ' . $counts['active'] . ' active, ' . $counts['inactive'] . ' inactive (' . $counts['total'] . ' total).';
            if (!empty($_POST['stay_on_edit'])) {
                $redirect = 'nurse_doctors.php?open_edit=' . $doctorId;
            } else {
                $redirect = 'nurse_doctors.php?updated=' . $doctorId;
            }
            header('Location: ' . $redirect);
            $conn->close();
            exit;
        }

        $error = $result['error'];
        $_SESSION['nurse_doctor_error'] = $error;
        header('Location: nurse_doctors.php');
        $conn->close();
        exit;
    } elseif ($action === 'delete_doctor') {
        if ($doctorId <= 0) {
            $error = 'Invalid doctor.';
        } else {
            $nameStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'doctor' LIMIT 1");
            $nameStmt->bind_param('i', $doctorId);
            $nameStmt->execute();
            $nameRow = $nameStmt->get_result()->fetch_assoc();
            $nameStmt->close();
            $doctorName = trim((string) ($nameRow['full_name'] ?? 'Doctor'));

            $conn->begin_transaction();
            try {
                $clearAppt = $conn->prepare('UPDATE appointments SET doctor_id = NULL WHERE doctor_id = ?');
                $clearAppt->bind_param('i', $doctorId);
                $clearAppt->execute();
                $clearAppt->close();

                $deleteUser = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'doctor'");
                $deleteUser->bind_param('i', $doctorId);
                $deleteUser->execute();
                $deleted = $deleteUser->affected_rows > 0;
                $deleteUser->close();

                if ($deleted) {
                    $conn->commit();
                    $_SESSION['nurse_doctor_flash'] = 'Deleted: ' . $doctorName . '. Na-update na rin ang bilang sa dashboard.';
                    header('Location: nurse_doctors.php');
                    $conn->close();
                    exit;
                }

                $conn->rollback();
                $error = 'Doctor not found or could not be deleted.';
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Failed to delete doctor account.';
            }
            $_SESSION['nurse_doctor_error'] = $error;
            header('Location: nurse_doctors.php');
            $conn->close();
            exit;
        }
    }
}

if (!empty($_SESSION['nurse_doctor_flash'])) {
    $message = (string) $_SESSION['nurse_doctor_flash'];
    unset($_SESSION['nurse_doctor_flash']);
}
if (!empty($_SESSION['nurse_doctor_error'])) {
    $error = (string) $_SESSION['nurse_doctor_error'];
    unset($_SESSION['nurse_doctor_error']);
}

$openEditId = (int) ($_GET['open_edit'] ?? 0);

$doctors = [];
$result = $conn->query("SELECT id, username, full_name, specialty, email, phone, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY full_name ASC");
if ($result) {
    while ($doctor = $result->fetch_assoc()) {
        $doctor['is_active'] = nd_doctor_is_active($doctor['is_active'] ?? 1) ? 1 : 0;
        $doctor['slots'] = doctor_fetch_availability_slots($conn, (int) $doctor['id']);
        $doctors[] = $doctor;
    }
}

$highlightDoctorId = (int) ($_GET['updated'] ?? 0);

$activeCount = count(array_filter($doctors, static fn ($doctor) => nd_doctor_is_active($doctor['is_active'])));
$inactiveCount = count($doctors) - $activeCount;
$openAddDoctorModal = isset($_GET['add']) && $_GET['add'] === '1';
if (
    !$openAddDoctorModal &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['nurse_doctor_action']) &&
    (string) $_POST['nurse_doctor_action'] === 'add_doctor' &&
    $error !== ''
) {
    $openAddDoctorModal = true;
}

$addFormSlots = [['day_of_week' => 1, 'time_start' => '09:00:00', 'time_end' => '12:00:00']];
if ($openAddDoctorModal && isset($_POST['slot_day']) && is_array($_POST['slot_day'])) {
    $addFormSlots = [];
    $postDays = $_POST['slot_day'];
    $postStarts = $_POST['slot_start'] ?? [];
    $postEnds = $_POST['slot_end'] ?? [];
    $postRowCount = max(count($postDays), count($postStarts), count($postEnds));
    for ($i = 0; $i < $postRowCount; $i++) {
        $day = (int) ($postDays[$i] ?? 0);
        $start = trim((string) ($postStarts[$i] ?? ''));
        $end = trim((string) ($postEnds[$i] ?? ''));
        if ($day < 1 && $start === '' && $end === '') {
            continue;
        }
        $addFormSlots[] = [
            'day_of_week' => $day >= 1 && $day <= 7 ? $day : 1,
            'time_start' => $start !== '' ? rd_normalize_time($start) : '09:00:00',
            'time_end' => $end !== '' ? rd_normalize_time($end) : '12:00:00',
        ];
    }
    if ($addFormSlots === []) {
        $addFormSlots = [['day_of_week' => 1, 'time_start' => '09:00:00', 'time_end' => '12:00:00']];
    }
}

$conn->close();

$additionalStyles = '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.rd-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 44px;
}

.rd-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 18px;
    align-items: center;
    background: #073b4c;
    color: #fff;
    border-radius: 8px;
    padding: 26px;
    margin-bottom: 16px;
    box-shadow: 0 14px 34px rgba(7, 59, 76, 0.16);
}

.rd-hero h1 {
    margin: 0 0 8px;
    color: #fff;
    font-size: clamp(1.6rem, 3vw, 2.35rem);
}

.rd-hero p {
    margin: 0;
    color: rgba(255, 255, 255, 0.82);
    line-height: 1.6;
}

.rd-stats {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.rd-stat {
    min-width: 110px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    padding: 12px;
    background: rgba(255, 255, 255, 0.08);
}

.rd-stat strong {
    display: block;
    color: #fff;
    font-size: 1.35rem;
}

.rd-stat span {
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.82rem;
    font-weight: 700;
}

.rd-alert {
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 14px;
    font-weight: 700;
}

.rd-alert.ok {
    background: #e7f7ed;
    color: #17643a;
    border: 1px solid #bfe6ce;
}

.rd-alert.er {
    background: #fff0f0;
    color: #9d1c2c;
    border: 1px solid #ffd0d5;
}

.rd-card {
    background: #fff;
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    margin-bottom: 16px;
}

.rd-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}

.rd-card-head h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.25rem;
}

.rd-card-head p {
    margin: 4px 0 0;
    color: #60727d;
    font-size: 0.92rem;
}

.rd-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 9px 14px;
    background: #0f7cc2;
    color: #fff;
    cursor: pointer;
    font-weight: 800;
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
}

.rd-btn:hover {
    background: #0b4f80;
    transform: translateY(-1px);
}

.rd-btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.rd-btn.danger {
    background: #c1121f;
}

.doctor-toggle-form {
    display: inline-flex;
    margin: 0;
}

.doctor-toggle-form .rd-btn {
    width: 100%;
}

.nd-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2000;
    background: rgba(7, 59, 76, 0.55);
    padding: 20px;
    align-items: center;
    justify-content: center;
}

.nd-modal-overlay.active {
    display: flex;
}

.nd-modal {
    width: min(680px, 100%);
    max-height: 90vh;
    overflow-y: auto;
    background: #fff;
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 20px 50px rgba(7, 59, 76, 0.25);
}

.nd-modal.nd-modal-wide {
    width: min(920px, 100%);
}

.edit-panels-store {
    display: none !important;
}

.nd-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}

.nd-modal-head h2 {
    margin: 0 0 6px;
    color: #073b4c;
    font-size: 1.35rem;
}

.nd-modal-head p {
    margin: 0;
    color: #60727d;
    font-size: 0.92rem;
    line-height: 1.5;
}

.nd-modal-close {
    border: none;
    background: #eef7ff;
    color: #0b4f80;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    flex-shrink: 0;
}

.doctor-status-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2100;
    background: rgba(7, 59, 76, 0.55);
    padding: 20px;
    align-items: center;
    justify-content: center;
}

.doctor-status-overlay.active {
    display: flex;
}

.doctor-status-box {
    width: min(420px, 100%);
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 20px 50px rgba(7, 59, 76, 0.25);
    text-align: center;
}

.doctor-status-box h2 {
    margin: 0 0 10px;
    color: #023e8a;
    font-size: 1.25rem;
}

.doctor-status-box p {
    margin: 0 0 20px;
    color: #60727d;
    line-height: 1.5;
}

.doctor-status-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

.doctor-status-actions button {
    min-width: 130px;
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    font-size: 0.95rem;
}

.doctor-status-cancel {
    background: #eef7ff;
    color: #0b4f80;
    border: 1px solid #d4e6f5;
}

.doctor-status-cancel:hover {
    background: #dceefb;
}

.doctor-status-yes {
    background: #0f7cc2;
    color: #fff;
}

.doctor-status-yes:hover {
    background: #0b4f80;
}

.doctor-status-yes.danger {
    background: #c1121f;
}

.doctor-status-yes.danger:hover {
    background: #9d0e19;
}

#doctorDeleteYes.danger {
    background: #c1121f;
}

#doctorDeleteYes.danger:hover {
    background: #9d0e19;
}

.nd-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.nd-form-grid .nd-full {
    grid-column: 1 / -1;
}

.nd-schedule-section {
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid #e0ebf3;
}

.nd-schedule-section h3 {
    margin: 0 0 6px;
    color: #073b4c;
    font-size: 1.05rem;
}

.nd-schedule-hint {
    margin: 0 0 12px;
    color: #60727d;
    font-size: 0.88rem;
}

#newSlotRows .slot-row {
    display: grid;
    grid-template-columns: 1.1fr 1fr 1fr auto;
    gap: 10px;
    align-items: end;
    margin-bottom: 10px;
}

.lookup-form {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) minmax(160px, 1fr) auto;
    gap: 12px;
    align-items: end;
}

.field {
    display: grid;
    gap: 6px;
}

.field label {
    color: #60727d;
    font-size: 0.84rem;
    font-weight: 800;
}

input,
select {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #d4e6f5;
    border-radius: 8px;
    color: #1f343d;
    font: inherit;
    min-height: 40px;
    padding: 9px 10px;
    background: #fff;
}

input:focus,
select:focus {
    border-color: #0f7cc2;
    box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.1);
    outline: none;
}

.lookup-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
}

.mini-stat {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 12px;
    background: #f8fbff;
}

.mini-stat strong {
    display: block;
    color: #073b4c;
    font-size: 1.3rem;
    line-height: 1;
}

.mini-stat span {
    display: block;
    color: #60727d;
    font-size: 0.82rem;
    font-weight: 800;
    margin-top: 6px;
}

.doctor-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(160px, 220px);
    gap: 10px;
    margin-bottom: 14px;
}

.availability-grid,
.doctor-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.availability-card,
.doctor-card {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 16px;
    background: #fff;
}

.availability-card.available {
    background: #f4fbf7;
    border-color: #bfe6ce;
}

.availability-card.unavailable {
    background: #fff8f8;
    border-color: #ffd0d5;
}

.doctor-card.is-active {
    border: 2px solid #28a745;
    background: #f4fbf7;
    box-shadow: 0 4px 14px rgba(40, 167, 69, 0.12);
}

.doctor-card.is-inactive {
    border: 2px solid #dc3545;
    background: #fff8f8;
    box-shadow: 0 4px 14px rgba(220, 53, 69, 0.1);
}

.doctor-card.just-updated {
    animation: doctorCardPulse 1.2s ease 2;
}

@keyframes doctorCardPulse {
    0%, 100% { box-shadow: 0 4px 14px rgba(15, 124, 194, 0.35); }
    50% { box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.45); }
}

.doctor-status-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: -16px -16px 14px;
    padding: 11px 14px;
    border-radius: 6px 6px 0 0;
    font-size: 0.9rem;
    font-weight: 800;
    line-height: 1.35;
}

.doctor-card.is-active .doctor-status-banner {
    background: #d4edda;
    color: #155724;
    border-bottom: 1px solid #bfe6ce;
}

.doctor-card.is-inactive .doctor-status-banner {
    background: #f8d7da;
    color: #721c24;
    border-bottom: 1px solid #f1c0c6;
}

.doctor-status-dot {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    flex-shrink: 0;
}

.doctor-card.is-active .doctor-status-dot {
    background: #28a745;
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.25);
}

.doctor-card.is-inactive .doctor-status-dot {
    background: #dc3545;
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.2);
}

.doctor-status-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 14px;
    padding: 12px 14px;
    background: #f8fbff;
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 700;
    color: #364d58;
}

.doctor-status-legend span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.legend-dot.active {
    background: #28a745;
}

.legend-dot.inactive {
    background: #dc3545;
}

.doctor-toggle-wrap {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed #e0ebf3;
}

.doctor-action-hint {
    margin: 0 0 10px;
    font-size: 0.84rem;
    font-weight: 700;
    color: #60727d;
    line-height: 1.4;
}

.doctor-card.is-active .doctor-action-hint {
    color: #17643a;
}

.doctor-card.is-inactive .doctor-action-hint {
    color: #9d1c2c;
}

.editor-status-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-weight: 800;
    font-size: 0.95rem;
}

.editor-status-banner.is-active {
    background: #d4edda;
    color: #155724;
    border: 2px solid #28a745;
}

.editor-status-banner.is-inactive {
    background: #f8d7da;
    color: #721c24;
    border: 2px solid #dc3545;
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 0.76rem;
    font-weight: 900;
    text-transform: uppercase;
}

.badge.available,
.badge.active {
    background: #e7f7ed;
    color: #17643a;
}

.badge.unavailable,
.badge.inactive {
    background: #fff0f0;
    color: #9d1c2c;
}

.badge.specialty {
    background: #eef7ff;
    color: #0b4f80;
}

.doctor-title {
    margin: 10px 0 6px;
    color: #073b4c;
    font-size: 1.08rem;
}

.doctor-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    color: #60727d;
    font-size: 0.9rem;
    margin-bottom: 10px;
}

.schedule-list {
    display: grid;
    gap: 6px;
    margin: 10px 0 14px;
    color: #364d58;
    font-size: 0.9rem;
}

.schedule-line {
    border-left: 3px solid #0f7cc2;
    padding: 5px 0 5px 9px;
    background: #f7fbff;
    border-radius: 6px;
}

.empty-note {
    border: 1px dashed #bdd7ea;
    border-radius: 8px;
    padding: 14px;
    color: #60727d;
    background: #f8fbff;
}

.empty-note.hidden {
    display: none;
}

.doctor-card.hidden {
    display: none;
}

.editor-layout {
    display: grid;
    grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
    gap: 16px;
}

.slot-row {
    display: grid;
    grid-template-columns: 1.1fr 1fr 1fr auto;
    gap: 10px;
    align-items: end;
    margin-bottom: 10px;
}

.editor-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

@media (max-width: 900px) {
    .rd-hero,
    .lookup-form,
    .lookup-summary,
    .doctor-toolbar,
    .availability-grid,
    .doctor-grid,
    .editor-layout {
        grid-template-columns: 1fr;
    }

    .rd-stats {
        justify-content: flex-start;
    }
}

@media (max-width: 620px) {
    .rd-wrap {
        padding: 18px 12px 34px;
    }

    .slot-row {
        grid-template-columns: 1fr;
    }

    .nd-form-grid {
        grid-template-columns: 1fr;
    }

    #newSlotRows .slot-row {
        grid-template-columns: 1fr;
    }

    .rd-card,
    .rd-hero {
        padding: 18px;
    }

    .rd-btn {
        width: 100%;
    }

    .rd-card-head .rd-btn {
        width: 100%;
    }
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const addDoctorModal = document.getElementById("addDoctorModal");
    const openAddBtn = document.getElementById("btnOpenAddDoctor");
    const closeAddBtn = document.getElementById("closeAddDoctorModal");
    const cancelAddBtn = document.getElementById("cancelAddDoctorModal");

    function openAddDoctorModalFn() {
        if (!addDoctorModal) return;
        addDoctorModal.classList.add("active");
        addDoctorModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        const first = addDoctorModal.querySelector("input:not([type=hidden])");
        if (first) first.focus();
    }

    function closeAddDoctorModalFn() {
        if (!addDoctorModal) return;
        addDoctorModal.classList.remove("active");
        addDoctorModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    if (openAddBtn) openAddBtn.addEventListener("click", openAddDoctorModalFn);
    if (closeAddBtn) closeAddBtn.addEventListener("click", closeAddDoctorModalFn);
    if (cancelAddBtn) cancelAddBtn.addEventListener("click", closeAddDoctorModalFn);
    if (addDoctorModal) {
        addDoctorModal.addEventListener("click", function (e) {
            if (e.target === addDoctorModal) closeAddDoctorModalFn();
        });
    }

    const rows = document.getElementById("slotRows");
    const addButton = document.querySelector("[data-add-slot]");
    const searchInput = document.getElementById("doctorSearch");
    const statusFilter = document.getElementById("doctorStatusFilter");
    const noMatches = document.getElementById("doctorNoMatches");

    function filterDoctors() {
        const query = (searchInput && searchInput.value ? searchInput.value : "").toLowerCase().trim();
        const status = statusFilter ? statusFilter.value : "";
        let visible = 0;

        document.querySelectorAll("[data-doctor-card]").forEach(function (card) {
            const haystack = (card.getAttribute("data-search") || "").toLowerCase();
            const cardStatus = card.getAttribute("data-status") || "";
            const matchesQuery = !query || haystack.indexOf(query) !== -1;
            const matchesStatus = !status || status === cardStatus;
            const show = matchesQuery && matchesStatus;
            card.classList.toggle("hidden", !show);
            if (show) {
                visible++;
            }
        });

        if (noMatches) {
            noMatches.classList.toggle("hidden", visible !== 0);
        }
    }

    if (searchInput) {
        searchInput.addEventListener("input", filterDoctors);
    }
    if (statusFilter) {
        statusFilter.addEventListener("change", filterDoctors);
    }
    filterDoctors();

    if (rows && addButton) {
        addButton.addEventListener("click", function () {
            const first = rows.querySelector(".slot-row");
            if (!first) {
                return;
            }
            const clone = first.cloneNode(true);
            clone.querySelectorAll("input").forEach(function (input) {
                input.value = "";
            });
            clone.querySelectorAll("select").forEach(function (select) {
                select.selectedIndex = 0;
            });
            rows.appendChild(clone);
        });

        rows.addEventListener("click", function (event) {
            const button = event.target.closest("[data-remove-slot]");
            if (!button) {
                return;
            }
            const allRows = rows.querySelectorAll(".slot-row");
            if (allRows.length <= 1) {
                allRows[0].querySelectorAll("input").forEach(function (input) {
                    input.value = "";
                });
                return;
            }
            button.closest(".slot-row").remove();
        });

        document.querySelectorAll("[data-fill-hours]").forEach(function (button) {
            button.addEventListener("click", function () {
                const start = button.getAttribute("data-start") || "";
                const end = button.getAttribute("data-end") || "";
                rows.querySelectorAll(".slot-row").forEach(function (row) {
                    const startInput = row.querySelector("input[name=\"slot_start[]\"]");
                    const endInput = row.querySelector("input[name=\"slot_end[]\"]");
                    if (startInput && !startInput.value) {
                        startInput.value = start;
                    }
                    if (endInput && !endInput.value) {
                        endInput.value = end;
                    }
                });
            });
        });
    }

    function bindSlotRows(rowsEl, addBtnSelector) {
        const addBtn = addBtnSelector ? document.querySelector(addBtnSelector) : null;
        if (!rowsEl) return;

        if (addBtn) {
            addBtn.addEventListener("click", function () {
                const first = rowsEl.querySelector(".slot-row");
                if (!first) return;
                const clone = first.cloneNode(true);
                clone.querySelectorAll("input").forEach(function (input) {
                    input.value = "";
                });
                clone.querySelectorAll("select").forEach(function (select) {
                    select.selectedIndex = 0;
                });
                rowsEl.appendChild(clone);
            });
        }

        rowsEl.addEventListener("click", function (event) {
            const button = event.target.closest("[data-remove-slot]");
            if (!button) return;
            const allRows = rowsEl.querySelectorAll(".slot-row");
            if (allRows.length <= 1) {
                allRows[0].querySelectorAll("input").forEach(function (input) {
                    input.value = "";
                });
                return;
            }
            button.closest(".slot-row").remove();
        });
    }

    bindSlotRows(document.getElementById("newSlotRows"), "[data-add-new-slot]");

    const editDoctorModal = document.getElementById("editDoctorModal");
    const editDoctorModalBody = document.getElementById("editDoctorModalBody");
    const editPanelsStore = document.getElementById("editPanelsStore");
    const closeEditDoctorModalBtn = document.getElementById("closeEditDoctorModal");

    function bindSlotRowsInContainer(container) {
        if (!container) return;
        container.querySelectorAll("[data-add-slot-row]").forEach(function (addBtn) {
            if (addBtn.dataset.bound === "1") return;
            addBtn.dataset.bound = "1";
            const targetId = addBtn.getAttribute("data-target");
            const rowsEl = targetId ? document.getElementById(targetId) : null;
            if (!rowsEl) return;
            addBtn.addEventListener("click", function () {
                const first = rowsEl.querySelector(".slot-row");
                if (!first) return;
                const clone = first.cloneNode(true);
                clone.querySelectorAll("input").forEach(function (input) { input.value = ""; });
                clone.querySelectorAll("select").forEach(function (select) { select.selectedIndex = 0; });
                rowsEl.appendChild(clone);
            });
            rowsEl.addEventListener("click", function (event) {
                const button = event.target.closest("[data-remove-slot]");
                if (!button) return;
                const allRows = rowsEl.querySelectorAll(".slot-row");
                if (allRows.length <= 1) {
                    allRows[0].querySelectorAll("input").forEach(function (input) { input.value = ""; });
                    return;
                }
                button.closest(".slot-row").remove();
            });
        });
    }

    function wireDeleteFormsIn(root) {
        if (!root) return;
        root.querySelectorAll(".doctor-delete-form").forEach(function (form) {
            if (form.dataset.deleteBound === "1") return;
            form.dataset.deleteBound = "1";
            form.addEventListener("submit", function (e) {
                if (allowDoctorDeleteSubmit) {
                    allowDoctorDeleteSubmit = false;
                    return;
                }
                e.preventDefault();
                const btn = form.querySelector(".doctor-delete-btn");
                if (btn) openDoctorDeleteModal(form, btn);
            });
        });
    }

    function openEditDoctorModal(doctorId) {
        if (!editDoctorModal || !editDoctorModalBody || !editPanelsStore) return;
        const panel = document.getElementById("editPanel-" + doctorId);
        if (!panel) return;
        editDoctorModalBody.innerHTML = "";
        editDoctorModalBody.appendChild(panel);
        panel.hidden = false;
        const title = document.getElementById("editDoctorModalTitle");
        const nameEl = panel.querySelector(".doctor-title");
        if (title && nameEl) title.textContent = "Manage: " + nameEl.textContent.trim();
        bindSlotRowsInContainer(editDoctorModalBody);
        wireDeleteFormsIn(editDoctorModalBody);
        wireToggleButtonsIn(editDoctorModalBody);
        editDoctorModal.classList.add("active");
        editDoctorModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    function closeEditDoctorModalFn() {
        if (!editDoctorModal || !editDoctorModalBody || !editPanelsStore) return;
        const panel = editDoctorModalBody.querySelector(".edit-doctor-panel");
        if (panel) {
            panel.hidden = true;
            editPanelsStore.appendChild(panel);
        }
        editDoctorModalBody.innerHTML = "";
        editDoctorModal.classList.remove("active");
        editDoctorModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    if (closeEditDoctorModalBtn) closeEditDoctorModalBtn.addEventListener("click", closeEditDoctorModalFn);
    if (editDoctorModal) {
        editDoctorModal.addEventListener("click", function (e) {
            if (e.target === editDoctorModal) closeEditDoctorModalFn();
        });
    }

    document.querySelectorAll(".btn-open-edit").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const id = btn.getAttribute("data-doctor-id");
            if (id) openEditDoctorModal(id);
        });
    });

    const toggleConfirmModal = document.getElementById("toggleConfirmModal");
    const toggleConfirmTitle = document.getElementById("toggleConfirmTitle");
    const toggleConfirmMessage = document.getElementById("toggleConfirmMessage");
    const toggleConfirmYes = document.getElementById("toggleConfirmYes");
    const toggleConfirmCancel = document.getElementById("toggleConfirmCancel");
    let pendingToggleForm = null;
    let allowToggleSubmit = false;

    function closeToggleConfirmModal() {
        if (!toggleConfirmModal) return;
        toggleConfirmModal.classList.remove("active");
        toggleConfirmModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        pendingToggleForm = null;
        allowToggleSubmit = false;
    }

    function openToggleConfirmModal(form, name, isActive) {
        if (!toggleConfirmModal || !form) return;
        pendingToggleForm = form;
        if (toggleConfirmTitle) {
            toggleConfirmTitle.textContent = isActive === "1" ? "Deactivate doctor?" : "Activate doctor?";
        }
        if (toggleConfirmMessage) {
            toggleConfirmMessage.textContent = isActive === "1"
                ? name + " will be hidden from booking until activated again."
                : name + " will be available for booking again.";
        }
        if (toggleConfirmYes) {
            toggleConfirmYes.textContent = isActive === "1" ? "Deactivate" : "Activate";
            toggleConfirmYes.classList.toggle("danger", isActive === "1");
        }
        toggleConfirmModal.classList.add("active");
        toggleConfirmModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    function wireToggleButtonsIn(root) {
        if (!root) root = document;
        root.querySelectorAll(".doctor-toggle-form").forEach(function (form) {
            if (form.dataset.toggleBound === "1") return;
            form.dataset.toggleBound = "1";
            form.addEventListener("submit", function (event) {
                if (allowToggleSubmit) {
                    allowToggleSubmit = false;
                    return;
                }
                event.preventDefault();
                openToggleConfirmModal(
                    form,
                    form.getAttribute("data-doctor-name") || "This doctor",
                    form.getAttribute("data-is-active") || "0"
                );
            });
        });
    }

    wireToggleButtonsIn(document);

    if (toggleConfirmCancel) toggleConfirmCancel.addEventListener("click", closeToggleConfirmModal);
    if (toggleConfirmYes) {
        toggleConfirmYes.addEventListener("click", function () {
            if (pendingToggleForm) {
                allowToggleSubmit = true;
                pendingToggleForm.submit();
            }
        });
    }
    if (toggleConfirmModal) {
        toggleConfirmModal.addEventListener("click", function (e) {
            if (e.target === toggleConfirmModal) closeToggleConfirmModal();
        });
    }

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        if (toggleConfirmModal && toggleConfirmModal.classList.contains("active")) closeToggleConfirmModal();
        else if (editDoctorModal && editDoctorModal.classList.contains("active")) closeEditDoctorModalFn();
    });

    const doctorDeleteModal = document.getElementById("doctorDeleteModal");
    const doctorDeleteTitle = document.getElementById("doctorDeleteTitle");
    const doctorDeleteMessage = document.getElementById("doctorDeleteMessage");
    const doctorDeleteYes = document.getElementById("doctorDeleteYes");
    const doctorDeleteCancel = document.getElementById("doctorDeleteCancel");
    let pendingDeleteForm = null;
    let allowDoctorDeleteSubmit = false;

    function closeDoctorDeleteModal() {
        if (!doctorDeleteModal) return;
        doctorDeleteModal.classList.remove("active");
        doctorDeleteModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        pendingDeleteForm = null;
    }

    function openDoctorDeleteModal(form, btn) {
        if (!doctorDeleteModal || !form || !btn) return;
        pendingDeleteForm = form;
        const name = (btn.getAttribute("data-doctor-name") || "this doctor").trim();
        if (doctorDeleteTitle) {
            doctorDeleteTitle.textContent = "Delete doctor?";
        }
        if (doctorDeleteMessage) {
            doctorDeleteMessage.textContent = "Permanent delete: " + name + ". Schedule removed. Past appointments will show as unassigned.";
        }
        doctorDeleteModal.classList.add("active");
        doctorDeleteModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    if (doctorDeleteCancel) {
        doctorDeleteCancel.addEventListener("click", closeDoctorDeleteModal);
    }
    if (doctorDeleteYes) {
        doctorDeleteYes.addEventListener("click", function () {
            const form = pendingDeleteForm;
            if (!form) return;
            closeDoctorDeleteModal();
            allowDoctorDeleteSubmit = true;
            form.submit();
        });
    }
    if (doctorDeleteModal) {
        doctorDeleteModal.addEventListener("click", function (e) {
            if (e.target === doctorDeleteModal) closeDoctorDeleteModal();
        });
    }
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && doctorDeleteModal && doctorDeleteModal.classList.contains("active")) {
            closeDoctorDeleteModal();
        }
    });

    wireDeleteFormsIn(document);

    const openEditParam = new URLSearchParams(window.location.search).get("open_edit");
    if (openEditParam) {
        openEditDoctorModal(openEditParam);
    }

    const updatedDoctorId = new URLSearchParams(window.location.search).get("updated");
    if (updatedDoctorId && statusFilter) {
        statusFilter.value = "";
        filterDoctors();
        const updatedCard = document.querySelector("[data-doctor-id=\"" + updatedDoctorId + "\"]");
        if (updatedCard) {
            updatedCard.scrollIntoView({ behavior: "smooth", block: "center" });
            updatedCard.style.outline = "3px solid #0f7cc2";
            updatedCard.style.outlineOffset = "3px";
            setTimeout(function () {
                updatedCard.style.outline = "";
                updatedCard.style.outlineOffset = "";
            }, 3200);
        }
    }

    document.querySelectorAll("[data-fill-new-hours]").forEach(function (button) {
        button.addEventListener("click", function () {
            const targetId = button.getAttribute("data-target-rows") || "newSlotRows";
            const target = document.getElementById(targetId);
            if (!target) return;
            const start = button.getAttribute("data-start") || "";
            const end = button.getAttribute("data-end") || "";
            target.querySelectorAll(".slot-row").forEach(function (row) {
                const startInput = row.querySelector("input[name=\"slot_start[]\"]");
                const endInput = row.querySelector("input[name=\"slot_end[]\"]");
                if (startInput && !startInput.value) startInput.value = start;
                if (endInput && !endInput.value) endInput.value = end;
            });
        });
    });
' . ($openAddDoctorModal ? "\n    openAddDoctorModalFn();\n" : '') . '
});
';

include 'includes/header.php';
?>
<main class="rd-wrap">
    <section class="rd-hero">
        <div>
            <h1>Doctors &amp; Schedules</h1>
            <p>Create doctor accounts, set clinic hours, activate or deactivate doctors, and remove accounts when needed.</p>
            <p style="margin-top:8px"><a class="rd-btn secondary" href="nurse.php" style="margin-top:4px">Back to dashboard</a></p>
        </div>
        <div class="rd-stats" aria-label="Doctor summary">
            <div class="rd-stat">
                <strong><?php echo count($doctors); ?></strong>
                <span>Total doctors</span>
            </div>
            <div class="rd-stat">
                <strong><?php echo $activeCount; ?></strong>
                <span>Active</span>
            </div>
            <div class="rd-stat">
                <strong><?php echo $inactiveCount; ?></strong>
                <span>Inactive</span>
            </div>
        </div>
    </section>

    <?php if ($message): ?>
        <div class="rd-alert ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error && !$openAddDoctorModal): ?>
        <div class="rd-alert er"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <section class="rd-card">
        <div class="rd-card-head">
            <div>
                <h2>Doctor list</h2>
            <p><?php echo $activeCount; ?> active, <?php echo $inactiveCount; ?> inactive. Nurses can add, edit, activate, deactivate, and remove doctors and schedules.</p>
            </div>
            <button type="button" class="rd-btn" id="btnOpenAddDoctor">Add doctor account</button>
        </div>

        <?php if (empty($doctors)): ?>
            <div class="empty-note">No doctor accounts yet. Click <strong>Add doctor account</strong> to create one.</div>
        <?php else: ?>
            <div class="doctor-toolbar" role="search" aria-label="Filter doctors">
                <input type="search" id="doctorSearch" placeholder="Search doctor, specialty, email, or phone">
                <select id="doctorStatusFilter" aria-label="Filter doctor status">
                    <option value="">All doctors</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
            </div>
            <div id="doctorNoMatches" class="empty-note hidden">No doctors match your search.</div>
            <div class="doctor-status-legend" aria-label="Status legend">
                <span><span class="legend-dot active" aria-hidden="true"></span> Green = <strong>Active</strong> (available for booking)</span>
                <span><span class="legend-dot inactive" aria-hidden="true"></span> Red = <strong>Inactive</strong> (hidden from booking)</span>
            </div>
            <div class="doctor-grid">
                <?php foreach ($doctors as $doctor): ?>
                    <?php
                    $active = nd_doctor_is_active($doctor['is_active']);
                    $searchText = trim(implode(' ', array_filter([
                        $doctor['full_name'] ?? '',
                        $doctor['specialty'] ?? '',
                        $doctor['email'] ?? '',
                        $doctor['phone'] ?? '',
                    ])));
                    ?>
                    <article class="doctor-card <?php echo $active ? 'is-active' : 'is-inactive'; ?><?php echo $highlightDoctorId === (int) $doctor['id'] ? ' just-updated' : ''; ?>" data-doctor-card data-doctor-id="<?php echo (int) $doctor['id']; ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>" data-status="<?php echo $active ? 'active' : 'inactive'; ?>">
                        <div class="doctor-status-banner" role="status">
                            <span class="doctor-status-dot" aria-hidden="true"></span>
                            <?php if ($active): ?>
                            <span><strong>Active</strong> - available for appointments</span>
                        <?php else: ?>
                                <span><strong>Inactive</strong> - not available for booking</span>
                        <?php endif; ?>
                        </div>
                        <span class="badge specialty"><?php echo htmlspecialchars($doctor['specialty'] ?: 'No specialty'); ?></span>
                        <h3 class="doctor-title"><?php echo htmlspecialchars($doctor['full_name']); ?></h3>
                        <div class="doctor-meta">
                            <?php if (!empty($doctor['email'])): ?><span><?php echo htmlspecialchars($doctor['email']); ?></span><?php endif; ?>
                            <?php if (!empty($doctor['phone'])): ?><span><?php echo htmlspecialchars($doctor['phone']); ?></span><?php endif; ?>
                        </div>
                        <div class="schedule-list">
                            <?php if (empty($doctor['slots'])): ?>
                                <div class="schedule-line">No clinic hours set.</div>
                            <?php else: ?>
                                <?php foreach ($doctor['slots'] as $slot): ?>
                                    <div class="schedule-line"><?php echo htmlspecialchars(rd_slot_label($slot, $dayNames)); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="doctor-toggle-wrap">
                            <p class="doctor-action-hint">
                                <?php if ($active): ?>
                                    Current status: <strong>active</strong>. Use the button below to deactivate.
                                <?php else: ?>
                                    Current status: <strong>inactive</strong>. Use the button below to activate again.
                                <?php endif; ?>
                            </p>
                            <div class="editor-actions">
                                <button type="button" class="rd-btn secondary btn-open-edit" data-doctor-id="<?php echo (int) $doctor['id']; ?>">Manage / Edit</button>
                                <form
                                    method="post"
                                    action="nurse_doctors.php"
                                    class="doctor-toggle-form"
                                    data-doctor-name="<?php echo htmlspecialchars($doctor['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-is-active="<?php echo $active ? '1' : '0'; ?>"
                                >
                                    <input type="hidden" name="nurse_doctor_action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $doctor['id']; ?>">
                                    <input type="hidden" name="set_active" value="<?php echo $active ? 0 : 1; ?>">
                                    <button type="submit" class="rd-btn <?php echo $active ? 'danger' : ''; ?> btn-toggle-doctor">
                                        <?php echo $active ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <form method="post" class="doctor-delete-form" style="display:inline" action="nurse_doctors.php">
                                    <input type="hidden" name="nurse_doctor_action" value="delete_doctor">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $doctor['id']; ?>">
                                    <button
                                        type="submit"
                                        class="rd-btn danger doctor-delete-btn"
                                        data-doctor-name="<?php echo htmlspecialchars($doctor['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    >Delete doctor</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="nd-modal-overlay" id="addDoctorModal" role="dialog" aria-modal="true" aria-labelledby="addDoctorModalTitle" aria-hidden="true">
        <div class="nd-modal">
            <div class="nd-modal-head">
                <div>
                    <h2 id="addDoctorModalTitle">Add doctor account</h2>
                    <p>Create login, profile, and clinic schedule in one step.</p>
                </div>
                <button type="button" class="nd-modal-close" id="closeAddDoctorModal" aria-label="Close">&times;</button>
            </div>
            <?php if ($error !== '' && $openAddDoctorModal): ?>
                <div class="rd-alert er" style="margin-bottom:12px"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="nurse_doctor_action" value="add_doctor">
                <p class="nd-schedule-hint">Username is generated automatically. Default password: <strong>password123</strong>.</p>
                <div class="nd-form-grid">
                    <div class="field nd-full">
                        <label for="new_full_name">Full name</label>
                        <input type="text" id="new_full_name" name="full_name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="new_specialty">Specialty</label>
                        <input type="text" id="new_specialty" name="specialty" placeholder="e.g. Pediatrician" value="<?php echo htmlspecialchars($_POST['specialty'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="new_email">Email</label>
                        <input type="email" id="new_email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="new_phone">Phone</label>
                        <input type="text" id="new_phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                </div>

                <div class="nd-schedule-section">
                    <h3>Clinic schedule</h3>
                    <p class="nd-schedule-hint">Add at least one day with start and end time. You can add more rows for other days.</p>
                    <div id="newSlotRows">
                        <?php foreach ($addFormSlots as $slot): ?>
                            <?php
                            $selectedDay = (int) ($slot['day_of_week'] ?? 1);
                            $start = substr((string) ($slot['time_start'] ?? ''), 0, 5);
                            $end = substr((string) ($slot['time_end'] ?? ''), 0, 5);
                            ?>
                            <div class="slot-row">
                                <div class="field">
                                    <label>Day</label>
                                    <select name="slot_day[]">
                                        <?php foreach ($dayNames as $dayNumber => $dayLabel): ?>
                                            <option value="<?php echo $dayNumber; ?>" <?php echo $selectedDay === $dayNumber ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dayLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Start</label>
                                    <input type="time" name="slot_start[]" value="<?php echo htmlspecialchars($start); ?>" required>
                                </div>
                                <div class="field">
                                    <label>End</label>
                                    <input type="time" name="slot_end[]" value="<?php echo htmlspecialchars($end); ?>" required>
                                </div>
                                <button type="button" class="rd-btn secondary" data-remove-slot>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="editor-actions">
                        <button type="button" class="rd-btn secondary" data-add-new-slot>Add row</button>
                        <button type="button" class="rd-btn secondary" data-fill-new-hours data-target-rows="newSlotRows" data-start="08:00" data-end="12:00">Fill AM</button>
                        <button type="button" class="rd-btn secondary" data-fill-new-hours data-target-rows="newSlotRows" data-start="13:00" data-end="17:00">Fill PM</button>
                    </div>
                </div>

                <div class="editor-actions">
                    <button type="submit" class="rd-btn">Create doctor</button>
                    <button type="button" class="rd-btn secondary" id="cancelAddDoctorModal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editPanelsStore" class="edit-panels-store" aria-hidden="true">
        <?php foreach ($doctors as $doc): ?>
            <?php
            $panelDoctor = $doc;
            $panelSlots = $doc['slots'];
            include __DIR__ . '/includes/nurse_doctor_edit_panel.php';
            ?>
        <?php endforeach; ?>
    </div>

    <div class="nd-modal-overlay" id="editDoctorModal" role="dialog" aria-modal="true" aria-labelledby="editDoctorModalTitle" aria-hidden="true">
        <div class="nd-modal nd-modal-wide">
            <div class="nd-modal-head">
                <div>
                    <h2 id="editDoctorModalTitle">Manage doctor</h2>
                    <p>Edit profile, schedule, activate/deactivate, or delete.</p>
                </div>
                <button type="button" class="nd-modal-close" id="closeEditDoctorModal" aria-label="Close">&times;</button>
            </div>
            <div id="editDoctorModalBody"></div>
        </div>
    </div>

    <div class="doctor-status-overlay" id="toggleConfirmModal" role="dialog" aria-modal="true" aria-labelledby="toggleConfirmTitle" aria-hidden="true">
        <div class="doctor-status-box">
            <h2 id="toggleConfirmTitle">Are you sure?</h2>
            <p id="toggleConfirmMessage">Confirm this action.</p>
            <div class="doctor-status-actions">
                <button type="button" class="doctor-status-cancel" id="toggleConfirmCancel">Cancel</button>
                <button type="button" class="doctor-status-yes" id="toggleConfirmYes">Yes, continue</button>
            </div>
        </div>
    </div>

    <div class="doctor-status-overlay" id="doctorDeleteModal" role="dialog" aria-modal="true" aria-labelledby="doctorDeleteTitle" aria-hidden="true">
        <div class="doctor-status-box">
            <h2 id="doctorDeleteTitle">Delete doctor?</h2>
            <p id="doctorDeleteMessage">This cannot be undone.</p>
            <div class="doctor-status-actions">
                <button type="button" class="doctor-status-cancel" id="doctorDeleteCancel">Cancel</button>
                <button type="button" class="doctor-status-yes danger" id="doctorDeleteYes">Yes, delete</button>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>


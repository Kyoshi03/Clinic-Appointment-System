<?php
require_once 'includes/session.php';
checkRole('patient');

require_once 'config/database.php';
require_once 'includes/patient_profile_photo.php';
require_once 'includes/nurse_medical_fields.php';
require_once 'includes/password_reset.php';

$pageTitle = "Patient Dashboard | Globalife Medical Laboratory & Polyclinic";
$currentUser = getCurrentUser();
$pageMessage = $_SESSION['patient_dashboard_message'] ?? '';
$pageError = $_SESSION['patient_dashboard_error'] ?? '';
unset($_SESSION['patient_dashboard_message'], $_SESSION['patient_dashboard_error']);
$profileMessage = '';
$profileError = '';

$conn = getDBConnection();
ensurePatientProfilePhotoColumn($conn);
if (
    function_exists('initLabBookingSchema') &&
    (!patient_table_exists($conn, 'medical_records') || !patient_table_exists($conn, 'lab_result_entries'))
) {
    initLabBookingSchema($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['patient_action'] ?? '') === 'cancel_appointment') {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    if ($appointmentId <= 0) {
        $_SESSION['patient_dashboard_error'] = 'Invalid appointment.';
    } else {
        $cancelStatus = 'cancelled';
        $cancel = $conn->prepare("UPDATE appointments
            SET status = ?
            WHERE id = ? AND patient_id = ? AND status IN ('pending', 'confirmed')");
        $cancel->bind_param('sii', $cancelStatus, $appointmentId, $currentUser['id']);
        if ($cancel->execute() && $cancel->affected_rows > 0) {
            $_SESSION['patient_dashboard_message'] = 'Appointment cancelled.';
        } else {
            $_SESSION['patient_dashboard_error'] = 'This appointment cannot be cancelled.';
        }
        $cancel->close();
    }
    $conn->close();
    header('Location: patients.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['profile_action'] ?? '') === 'update_profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
    $civilStatus = trim($_POST['civil_status'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyRelationship = trim($_POST['emergency_contact_relationship'] ?? '');
    $emergencyNumber = trim($_POST['emergency_contact_number'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmNewPassword = $_POST['confirm_new_password'] ?? '';
    $allowedGenders = ['', 'Male', 'Female', 'Other'];
    $computedAge = '';

    if ($fullName === '') {
        $profileError = 'Full name is required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileError = 'Please enter a valid email address.';
    } elseif (!in_array($gender, $allowedGenders, true)) {
        $profileError = 'Please choose a valid gender.';
    } elseif ($dateOfBirth !== '' && strtotime($dateOfBirth) === false) {
        $profileError = 'Please enter a valid date of birth.';
    } elseif ($dateOfBirth !== '' && strtotime($dateOfBirth) > time()) {
        $profileError = 'Date of birth cannot be in the future.';
    } else {
        if ($newPassword !== '' || $confirmNewPassword !== '' || $currentPassword !== '') {
            if ($newPassword === '' || $confirmNewPassword === '') {
                $profileError = 'Enter and confirm your new password, or leave all password fields blank.';
            } elseif ($currentPassword === '') {
                $profileError = 'Enter your current password to change it.';
            } elseif ($newPassword !== $confirmNewPassword) {
                $profileError = 'New password and confirmation do not match.';
            } else {
                $pwStmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
                $pwStmt->bind_param('i', $currentUser['id']);
                $pwStmt->execute();
                $pwRow = $pwStmt->get_result()->fetch_assoc();
                $pwStmt->close();
                if (!$pwRow || !password_verify($currentPassword, $pwRow['password'])) {
                    $profileError = 'Current password is incorrect.';
                } else {
                    $passwordErrors = pw_reset_validate_password($newPassword);
                    if ($passwordErrors !== []) {
                        $profileError = implode(' ', $passwordErrors);
                    }
                }
            }
        }

        if ($profileError === '') {
            if ($dateOfBirth !== '') {
                $birthDate = new DateTime($dateOfBirth);
                $todayDate = new DateTime('today');
                $computedAge = (string) $birthDate->diff($todayDate)->y;
            }

        $photoStmt = $conn->prepare('SELECT profile_photo FROM users WHERE id = ?');
        $photoStmt->bind_param('i', $currentUser['id']);
        $photoStmt->execute();
        $photoRow = $photoStmt->get_result()->fetch_assoc();
        $photoStmt->close();
        $currentPhotoPath = $photoRow['profile_photo'] ?? null;

        $removePhoto = ($_POST['remove_profile_photo'] ?? '') === '1';
        $croppedPhoto = trim($_POST['profile_photo_cropped'] ?? '');
        $newPhotoPath = $currentPhotoPath;

        if ($removePhoto) {
            patientDeleteProfilePhotoFile($currentPhotoPath);
            $newPhotoPath = null;
        } elseif ($croppedPhoto !== '') {
            $savedPhoto = savePatientProfilePhotoFromBase64((int) $currentUser['id'], $croppedPhoto, $currentPhotoPath);
            if ($savedPhoto === null) {
                $profileError = 'Could not save profile photo. Please try a JPG or PNG under 3 MB.';
            } else {
                $newPhotoPath = $savedPhoto;
            }
        }

        if ($profileError === '') {
            $result = updatePatientUserProfile($conn, (int) $currentUser['id'], [
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'gender' => $gender,
                'date_of_birth' => $dateOfBirth,
                'age' => $computedAge,
                'civil_status' => $civilStatus,
                'address' => $address,
                'barangay' => $barangay,
                'city' => $city,
                'emergency_contact_name' => $emergencyName,
                'emergency_contact_relationship' => $emergencyRelationship,
                'emergency_contact_number' => $emergencyNumber,
            ], $newPhotoPath, $removePhoto);

            if ($result['ok'] && $newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pwUpdate = $conn->prepare('UPDATE users SET password = ? WHERE id = ? AND role = ?');
                $patientRole = 'patient';
                $pwUpdate->bind_param('sis', $passwordHash, $currentUser['id'], $patientRole);
                if (!$pwUpdate->execute() || $pwUpdate->affected_rows === 0) {
                    $profileError = 'Profile saved but password could not be updated. Please try again.';
                }
                $pwUpdate->close();
            }

            if ($result['ok'] && $profileError === '') {
                $_SESSION['full_name'] = $fullName;
                $_SESSION['patient_profile_message'] = $newPassword !== ''
                    ? 'Profile and password updated successfully.'
                    : 'Profile updated successfully.';
                $conn->close();
                header('Location: patients.php?saved=1');
                exit;
            }
            if (!$result['ok']) {
                $profileError = $result['error'];
            }
        }
        }
    }

    if ($profileError !== '') {
        $_SESSION['patient_profile_error'] = $profileError;
        $conn->close();
        header('Location: patients.php?profile=1');
        exit;
    }
}

if (!empty($_SESSION['patient_profile_message'])) {
    $profileMessage = (string) $_SESSION['patient_profile_message'];
    unset($_SESSION['patient_profile_message']);
}
if (!empty($_SESSION['patient_profile_error'])) {
    $profileError = (string) $_SESSION['patient_profile_error'];
    unset($_SESSION['patient_profile_error']);
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$userDetails = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$stmt = $conn->prepare("SELECT a.*, d.full_name AS doctor_name
    FROM appointments a
    LEFT JOIN users d ON d.id = a.doctor_id AND d.role = 'doctor'
    WHERE a.patient_id = ?
      AND a.status IN ('pending', 'confirmed')
      AND a.appointment_date >= CURDATE()
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 5");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT
        SUM(CASE WHEN status IN ('pending', 'confirmed') AND appointment_date >= CURDATE() THEN 1 ELSE 0 END) AS upcoming_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
        COUNT(*) AS total_count
    FROM appointments
    WHERE patient_id = ?");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$medicalRecords = [];
$labResults = [];
$medicalRecordTotal = 0;
$labResultTotal = 0;
if (patient_table_exists($conn, 'medical_records')) {
    $stmt = $conn->prepare("SELECT m.title, m.content, m.created_at, u.full_name AS author_name
        FROM medical_records m
        LEFT JOIN users u ON u.id = m.author_id
        WHERE m.patient_id = ?
        ORDER BY m.created_at DESC
        LIMIT 4");
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $medicalRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM medical_records WHERE patient_id = ?");
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $medicalRecordTotal = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
    $stmt->close();
}

if (patient_table_exists($conn, 'lab_result_entries')) {
    $stmt = $conn->prepare("SELECT l.test_name, l.result_text, l.result_date, l.created_at, u.full_name AS author_name
        FROM lab_result_entries l
        LEFT JOIN users u ON u.id = l.author_id
        WHERE l.patient_id = ?
        ORDER BY l.result_date DESC, l.created_at DESC
        LIMIT 4");
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $labResults = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM lab_result_entries WHERE patient_id = ?");
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $labResultTotal = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
    $stmt->close();
}

$conn->close();

$firstName = trim(explode(' ', $currentUser['full_name'])[0] ?? 'Patient');
$profilePhotoUrl = patientProfilePhotoUrl($userDetails['profile_photo'] ?? null, $userDetails['profile_updated_at'] ?? null);
$profileInitials = patientProfileInitials($userDetails['full_name'] ?? $currentUser['full_name']);
$headerPatientPhotoUrl = $profilePhotoUrl;
$headerPatientInitials = $profileInitials;
$headerPatientDisplayName = $userDetails['full_name'] ?? $currentUser['full_name'];
$showProfileSuccessPopup = $profileMessage !== '';
$showProfileErrorPopup = $profileError !== '';
$openProfileModal = $showProfileErrorPopup || (isset($_GET['profile']) && $_GET['profile'] === '1' && !$showProfileSuccessPopup);
$upcomingCount = (int) ($summary['upcoming_count'] ?? 0);
$completedCount = (int) ($summary['completed_count'] ?? 0);
$totalCount = (int) ($summary['total_count'] ?? 0);
$healthRecordTotal = $medicalRecordTotal + $labResultTotal;
$nextAppointment = $appointments[0] ?? null;

$isNewPatientWelcome = !empty($_SESSION['patient_welcome_new']);
unset($_SESSION['patient_welcome_new']);

if (!$isNewPatientWelcome && $totalCount === 0) {
    $createdAt = trim((string) ($userDetails['created_at'] ?? ''));
    if ($createdAt !== '' && ($createdStamp = strtotime($createdAt)) !== false && $createdStamp >= time() - (24 * 3600)) {
        $isNewPatientWelcome = true;
    }
}

$welcomeTitle = $isNewPatientWelcome
    ? 'Welcome, ' . $firstName . '!'
    : 'Welcome back, ' . $firstName;
$welcomeSubtitle = $isNewPatientWelcome
    ? 'Your patient account is ready. Book your first appointment below and bring a valid ID when you visit Globalife.'
    : 'Book appointments, check your upcoming schedule, review your health records, and keep your contact details ready for clinic updates.';
$showGetStartedPanel = $isNewPatientWelcome || ($totalCount === 0 && $upcomingCount === 0);

$hideProfileStepInGetStarted = trim((string) ($userDetails['full_name'] ?? '')) !== ''
    && trim((string) ($userDetails['email'] ?? '')) !== ''
    && trim((string) ($userDetails['phone'] ?? '')) !== ''
    && trim((string) ($userDetails['gender'] ?? '')) !== ''
    && trim((string) ($userDetails['date_of_birth'] ?? '')) !== ''
    && trim((string) ($userDetails['barangay'] ?? '')) !== ''
    && trim((string) ($userDetails['city'] ?? '')) !== '';

$profileFields = [
    'Full name' => $userDetails['full_name'] ?? '',
    'Email' => $userDetails['email'] ?? '',
    'Phone' => $userDetails['phone'] ?? '',
    'Gender' => $userDetails['gender'] ?? '',
    'Date of birth' => $userDetails['date_of_birth'] ?? '',
    'Civil status' => $userDetails['civil_status'] ?? '',
    'Address' => $userDetails['address'] ?? '',
    'Barangay' => $userDetails['barangay'] ?? '',
    'City' => $userDetails['city'] ?? '',
    'Emergency contact' => $userDetails['emergency_contact_number'] ?? '',
];
$filledProfileFields = count(array_filter($profileFields, static fn ($v) => trim((string) $v) !== ''));
$profileScore = count($profileFields) > 0 ? (int) round(($filledProfileFields / count($profileFields)) * 100) : 0;
$readinessItems = [
    [
        'label' => 'Email reminders',
        'detail' => trim((string) ($userDetails['email'] ?? '')) !== '' ? (string) $userDetails['email'] : 'Add an email address',
        'ready' => trim((string) ($userDetails['email'] ?? '')) !== '',
    ],
    [
        'label' => 'SMS/contact calls',
        'detail' => trim((string) ($userDetails['phone'] ?? '')) !== '' ? (string) $userDetails['phone'] : 'Add your phone number',
        'ready' => trim((string) ($userDetails['phone'] ?? '')) !== '',
    ],
    [
        'label' => 'Emergency contact',
        'detail' => trim((string) ($userDetails['emergency_contact_number'] ?? '')) !== '' ? (string) $userDetails['emergency_contact_number'] : 'Add emergency number',
        'ready' => trim((string) ($userDetails['emergency_contact_number'] ?? '')) !== '',
    ],
    [
        'label' => 'Complete address',
        'detail' => trim((string) ($userDetails['address'] ?? '')) !== '' ? 'Address saved' : 'Add your current address',
        'ready' => trim((string) ($userDetails['address'] ?? '')) !== '',
    ],
];

function patient_format_date(?string $date): string {
    if (!$date) {
        return 'Not set';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('M d, Y') : $date;
}

function patient_format_time(?string $time): string {
    if (!$time) {
        return 'Not set';
    }
    $dt = DateTime::createFromFormat('H:i:s', $time) ?: DateTime::createFromFormat('H:i', $time);
    return $dt ? $dt->format('g:i A') : substr($time, 0, 5);
}

function patient_booking_label(?string $type): string {
    if ($type === 'package') {
        return 'Package';
    }
    if ($type === 'individual') {
        return 'Laboratory tests';
    }
    return 'Clinic visit';
}

function patient_status_class(?string $status): string {
    $safe = strtolower((string) $status);
    return in_array($safe, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $safe : 'pending';
}

function patient_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function patient_format_datetime(?string $dateTime): string {
    if (!$dateTime) {
        return 'Not set';
    }
    $stamp = strtotime($dateTime);
    return $stamp ? date('M d, Y g:i A', $stamp) : $dateTime;
}

function patient_short_text(?string $text, int $limit = 90): string {
    $text = trim((string) $text);
    if ($text === '') {
        return 'No details posted.';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

$additionalStyles = '
    body {
        background: #f4f8fb;
        min-height: 100vh;
        color: #1f343d;
    }

    .patient-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 28px 20px 42px;
    }

    .patient-hero {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 24px;
        align-items: center;
        background: #073b4c;
        color: #fff;
        border-radius: 8px;
        padding: 28px;
        margin-bottom: 18px;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.18);
    }

    .patient-kicker {
        display: inline-flex;
        align-items: center;
        color: #a9edf2;
        font-size: 0.86rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .patient-hero h2 {
        margin: 0 0 8px;
        font-size: clamp(1.7rem, 3vw, 2.5rem);
        line-height: 1.08;
        color: #fff;
    }

    .patient-hero p {
        margin: 0;
        color: rgba(255, 255, 255, 0.82);
        line-height: 1.65;
    }

    .patient-hero.is-new-welcome {
        background: linear-gradient(135deg, #0077b6 0%, #073b4c 100%);
    }

    .new-welcome-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 10px;
        padding: 6px 12px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.16);
        color: #caf0f8;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .clinic-tips-bar {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .clinic-tip-card {
        background: #fff;
        border: 1px solid #dceef2;
        border-radius: 10px;
        padding: 14px 16px;
        box-shadow: 0 8px 18px rgba(7, 59, 76, 0.05);
    }

    .clinic-tip-card strong {
        display: block;
        color: #073b4c;
        font-size: 0.9rem;
        margin-bottom: 6px;
    }

    .clinic-tip-card p {
        margin: 0;
        color: #566872;
        font-size: 0.82rem;
        line-height: 1.5;
    }

    .get-started-panel {
        background: linear-gradient(135deg, #e8f8fc 0%, #f0faf9 100%);
        border: 1px solid rgba(72, 202, 228, 0.45);
        border-radius: 12px;
        padding: 20px 22px;
        margin-bottom: 18px;
    }

    .get-started-panel h3 {
        margin: 0 0 8px;
        color: #073b4c;
        font-size: 1.1rem;
    }

    .get-started-panel > p {
        margin: 0 0 16px;
        color: #566872;
        font-size: 0.92rem;
        line-height: 1.55;
    }

    .get-started-steps {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .get-started-steps li {
        background: #fff;
        border: 1px solid #d0ebf2;
        border-radius: 10px;
        padding: 14px;
        font-size: 0.88rem;
        color: #435761;
        line-height: 1.5;
    }

    .get-started-steps li strong {
        display: block;
        color: #0077b6;
        margin-bottom: 4px;
    }

    .get-started-steps a {
        color: #0077b6;
        font-weight: 700;
        text-decoration: none;
    }

    .get-started-steps a:hover {
        text-decoration: underline;
    }

    .hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
    }

    .primary-btn,
    .secondary-btn,
    .plain-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 42px;
        padding: 10px 16px;
        border-radius: 8px;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid transparent;
        transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    }

    .primary-btn {
        background: #0f7cc2;
        color: #fff;
        box-shadow: 0 10px 20px rgba(15, 124, 194, 0.24);
    }

    .primary-btn:hover {
        background: #0b4f80;
        transform: translateY(-1px);
    }

    .secondary-btn {
        background: #fff;
        color: #0b4f80;
        border-color: rgba(255, 255, 255, 0.45);
    }

    .secondary-btn:hover {
        border-color: #90e0ef;
        transform: translateY(-1px);
    }

    .plain-btn {
        background: #eef7ff;
        color: #0b4f80;
        border-color: #d4e6f5;
    }

    .plain-btn:hover {
        background: #dff1ff;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .summary-tile {
        background: #fff;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 16px;
    }

    .summary-label {
        display: block;
        color: #60727d;
        font-size: 0.84rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .summary-value {
        color: #073b4c;
        font-size: 1.55rem;
        font-weight: 800;
        line-height: 1;
    }

    .dashboard-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.75fr);
        gap: 16px;
        align-items: start;
    }

    .panel {
        background: #fff;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    }

    .panel + .panel {
        margin-top: 16px;
    }

    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
    }

    .panel-header h3 {
        margin: 0;
        color: #073b4c;
        font-size: 1.24rem;
    }

    .panel-header p {
        margin: 4px 0 0;
        color: #60727d;
        font-size: 0.92rem;
    }

    .next-card {
        border: 1px solid #d4e6f5;
        background: #f7fbff;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 14px;
    }

    .next-card-title {
        color: #0b4f80;
        font-weight: 800;
        margin-bottom: 8px;
    }

    .appointment-list {
        display: grid;
        gap: 10px;
    }

    .appointment-row {
        display: grid;
        grid-template-columns: 92px minmax(0, 1fr) auto;
        gap: 14px;
        align-items: center;
        border: 1px solid #e6eef5;
        border-radius: 8px;
        padding: 14px;
    }

    .date-box {
        text-align: center;
        background: #eef7ff;
        border: 1px solid #d4e6f5;
        border-radius: 8px;
        padding: 10px 8px;
        color: #0b4f80;
        font-weight: 800;
    }

    .date-box small {
        display: block;
        color: #60727d;
        font-size: 0.75rem;
        font-weight: 700;
        margin-top: 2px;
    }

    .appointment-main strong {
        display: block;
        color: #073b4c;
        margin-bottom: 5px;
    }

    .appointment-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 14px;
        color: #60727d;
        font-size: 0.91rem;
    }

    .appointment-side {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: flex-end;
    }

    .cancel-inline {
        border: 1px solid #ffd0d5;
        border-radius: 8px;
        background: #fff0f0;
        color: #9d1c2c;
        cursor: pointer;
        font-weight: 800;
        padding: 8px 11px;
    }

    .cancel-inline:hover {
        background: #ffe1e5;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 92px;
        border-radius: 999px;
        padding: 7px 12px;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .status-badge.pending {
        background: #fff7e3;
        color: #8a5a00;
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

    .empty-state {
        text-align: center;
        border: 1px dashed #bdd7ea;
        border-radius: 8px;
        padding: 28px 18px;
        background: #f8fbff;
    }

    .empty-state h3 {
        margin: 0 0 8px;
        color: #073b4c;
    }

    .empty-state p {
        margin: 0 0 16px;
        color: #60727d;
    }

    .profile-list {
        display: grid;
        gap: 10px;
    }

    .profile-alert {
        border-radius: 8px;
        padding: 11px 12px;
        margin-bottom: 14px;
        font-weight: 700;
        font-size: 0.9rem;
    }

    .profile-alert.success {
        background: #e7f7ed;
        color: #17643a;
        border: 1px solid #bfe6ce;
    }

    .profile-alert.error {
        background: #fff0f0;
        color: #9d1c2c;
        border: 1px solid #ffd0d5;
    }

    .profile-form {
        display: grid;
        gap: 12px;
    }

    .profile-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .profile-field {
        display: grid;
        gap: 6px;
    }

    .profile-field.full {
        grid-column: 1 / -1;
    }

    .profile-field label {
        color: #60727d;
        font-size: 0.84rem;
        font-weight: 800;
    }

    .profile-field input,
    .profile-field select,
    .profile-field textarea {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d4e6f5;
        border-radius: 8px;
        color: #1f343d;
        font: inherit;
        min-height: 42px;
        padding: 10px 11px;
        background: #fff;
    }

    .profile-field textarea {
        min-height: 74px;
        resize: vertical;
    }

    .profile-field input:focus,
    .profile-field select:focus,
    .profile-field textarea:focus {
        border-color: #0f7cc2;
        box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.1);
        outline: none;
    }

    .profile-password-section {
        margin-top: 20px;
        padding-top: 18px;
        border-top: 1px solid #e0ebf3;
    }

    .profile-password-section h4 {
        margin: 0 0 6px;
        color: #073b4c;
        font-size: 1rem;
    }

    .profile-password-section > p {
        margin: 0 0 14px;
        color: #60727d;
        font-size: 0.86rem;
        line-height: 1.5;
    }

    .record-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .record-column h4 {
        margin: 0 0 10px;
        color: #073b4c;
        font-size: 1rem;
    }

    .record-list {
        display: grid;
        gap: 10px;
    }

    .record-card {
        border: 1px solid #e0ebf3;
        border-left: 3px solid #0f7cc2;
        border-radius: 8px;
        background: #f8fbff;
        padding: 12px;
    }

    .record-card strong {
        display: block;
        color: #073b4c;
        margin-bottom: 4px;
    }

    .record-card small {
        display: block;
        color: #60727d;
        font-weight: 700;
        margin-bottom: 6px;
    }

    .record-card p {
        margin: 0;
        color: #364d58;
        line-height: 1.45;
        font-size: 0.92rem;
    }

    .readiness-list {
        display: grid;
        gap: 9px;
    }

    .readiness-item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 11px 12px;
        background: #fff;
    }

    .readiness-item strong {
        color: #073b4c;
    }

    .readiness-item span {
        color: #60727d;
        font-size: 0.88rem;
    }

    .readiness-status {
        color: #17643a;
        font-weight: 900;
        white-space: nowrap;
    }

    .readiness-status.missing {
        color: #9d1c2c;
    }

    .profile-item {
        display: grid;
        grid-template-columns: 112px minmax(0, 1fr);
        gap: 10px;
        color: #263f4a;
        font-size: 0.92rem;
    }

    .profile-item span {
        color: #60727d;
        font-weight: 700;
    }

    .progress-track {
        width: 100%;
        height: 8px;
        background: #e7f0f7;
        border-radius: 999px;
        overflow: hidden;
        margin: 10px 0 8px;
    }

    .progress-bar {
        height: 100%;
        width: var(--value);
        background: #0f7cc2;
    }

    .action-list {
        display: grid;
        gap: 10px;
    }

    .action-link {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 13px 14px;
        text-decoration: none;
        color: #073b4c;
        font-weight: 700;
        background: #fff;
    }

    .action-link:hover {
        background: #f7fbff;
        border-color: #bdd7ea;
    }

    .action-link small {
        display: block;
        color: #60727d;
        font-weight: 500;
        margin-top: 3px;
    }

    .patient-profile-trigger {
        border: none;
        background: transparent;
        padding: 0;
        cursor: pointer;
        border-radius: 50%;
        position: relative;
        flex-shrink: 0;
    }

    .patient-profile-trigger:focus-visible {
        outline: 3px solid #90e0ef;
        outline-offset: 4px;
    }

    .patient-profile-avatar {
        width: 88px;
        height: 88px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid rgba(255, 255, 255, 0.85);
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.2);
        display: block;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        font-size: 1.6rem;
        font-weight: 800;
        line-height: 88px;
        text-align: center;
    }

    .patient-profile-trigger .edit-badge {
        position: absolute;
        right: 0;
        bottom: 0;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #0f7cc2;
        color: #fff;
        border: 2px solid #073b4c;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        font-weight: 800;
    }

    .profile-card-mini {
        display: flex;
        align-items: center;
        gap: 14px;
        border: 1px solid #d4e6f5;
        background: #f7fbff;
        border-radius: 8px;
        padding: 14px;
        margin-bottom: 14px;
        cursor: pointer;
        text-align: left;
        width: 100%;
        font: inherit;
        color: inherit;
        transition: border-color 0.2s ease, background 0.2s ease;
    }

    .profile-card-mini:hover {
        border-color: #0f7cc2;
        background: #eef7ff;
    }

    .profile-card-mini .patient-profile-avatar {
        width: 64px;
        height: 64px;
        line-height: 64px;
        font-size: 1.2rem;
    }

    .profile-card-mini strong {
        display: block;
        color: #073b4c;
        margin-bottom: 4px;
    }

    .profile-card-mini span {
        color: #60727d;
        font-size: 0.88rem;
    }

    .profile-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(7, 59, 76, 0.55);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .profile-modal-overlay.active {
        display: flex;
    }

    .profile-modal {
        width: min(1080px, calc(100vw - 32px));
        max-height: 92vh;
        overflow: auto;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 24px 60px rgba(7, 59, 76, 0.28);
        border: 1px solid #e0ebf3;
    }

    .profile-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid #e0ebf3;
        background: #f7fbff;
    }

    .profile-modal-header h3 {
        margin: 0;
        color: #073b4c;
        font-size: 1.2rem;
    }

    .profile-modal-close {
        border: none;
        background: #eef7ff;
        color: #0b4f80;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        font-size: 1.4rem;
        cursor: pointer;
        line-height: 1;
    }

    .profile-modal-body {
        padding: 20px;
        display: grid;
        grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
        gap: 24px;
        align-items: start;
    }

    .photo-editor-panel {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d7e6f0;
        border-radius: 10px;
        padding: 16px;
        background: #fff;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.08);
    }

    .photo-editor-panel h4 {
        margin: 0 0 6px;
        color: #073b4c;
        font-size: 1rem;
    }

    .photo-help-text {
        margin: 0 0 14px;
        color: #60727d;
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .photo-preview-wrap,
    .crop-stage {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        aspect-ratio: 1;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 12px;
        position: relative;
    }

    .photo-preview-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #edf5fb;
        border: 1px solid #cfe0ec;
        cursor: pointer;
    }

    .photo-preview-wrap:hover .photo-preview-overlay {
        background: rgba(7, 59, 76, 0.94);
        transform: translateY(-2px);
    }

    .photo-preview-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .photo-preview-wrap .patient-profile-avatar {
        width: 100%;
        height: 100%;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: 0;
        box-shadow: none;
        font-size: 2.2rem;
    }

    .photo-preview-overlay {
        position: absolute;
        left: 10px;
        right: 10px;
        bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        border-radius: 8px;
        background: rgba(7, 59, 76, 0.88);
        color: #fff;
        text-align: left;
        box-sizing: border-box;
        transition: transform 0.2s ease, background 0.2s ease;
    }

    .photo-preview-icon {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff;
        color: #0077b6;
        font-size: 1.35rem;
        font-weight: 800;
    }

    .photo-preview-copy,
    .photo-preview-overlay strong,
    .photo-preview-copy span {
        display: block;
    }

    .photo-preview-overlay strong {
        font-size: 0.86rem;
        margin-bottom: 2px;
    }

    .photo-preview-copy span {
        font-size: 0.74rem;
        line-height: 1.35;
        color: #d9f5ff;
    }

    .crop-stage {
        display: none;
        background: #0f2730;
        border: 2px solid #0077b6;
        box-shadow: 0 10px 24px rgba(0, 119, 182, 0.18);
    }

    .crop-stage.active {
        display: block;
    }

    .crop-stage > img {
        display: block;
        max-width: none;
        width: 100%;
    }

    .photo-crop-tip {
        display: none;
        margin: 0 0 10px;
        padding: 9px 10px;
        box-sizing: border-box;
        border-radius: 8px;
        border: 1px solid #bce7f5;
        background: #f0fbff;
        color: #335d6b;
        font-size: 0.8rem;
        line-height: 1.4;
    }

    .photo-crop-tip.active {
        display: block;
    }

    .photo-file-name {
        display: none;
        margin: 0 0 10px;
        padding: 8px 10px;
        box-sizing: border-box;
        border-radius: 8px;
        background: #eef7ff;
        color: #0b4f80;
        font-size: 0.8rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .photo-file-name.active {
        display: block;
    }

    .photo-zoom-control {
        display: none;
        margin: 0 0 10px;
        padding: 10px;
        box-sizing: border-box;
        border-radius: 8px;
        background: #f8fbff;
        border: 1px solid #d4e6f5;
    }

    .photo-zoom-control.active {
        display: block;
    }

    .photo-zoom-control label {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        color: #0b4f80;
        font-size: 0.82rem;
        font-weight: 800;
        margin-bottom: 8px;
    }

    .photo-zoom-control input[type="range"] {
        width: 100%;
        accent-color: #0077b6;
    }

    .photo-toolbar {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 10px;
        width: 100%;
    }

    .photo-toolbar button,
    .photo-upload-label {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d4e6f5;
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 0.82rem;
        font-weight: 800;
        cursor: pointer;
        text-align: center;
        transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .photo-toolbar button {
        background: #fff;
        color: #0b4f80;
    }

    #resetCropBtn {
        grid-column: 1 / -1;
    }

    .photo-upload-label {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 42px;
        background: #0077b6;
        border-color: #0077b6;
        color: #fff;
    }

    .photo-upload-label input {
        display: none;
    }

    .photo-toolbar button:hover,
    .photo-upload-label:hover {
        transform: translateY(-1px);
    }

    .photo-toolbar button:hover {
        background: #eef7ff;
        border-color: #0f7cc2;
    }

    .photo-upload-label:hover {
        background: #005f93;
        border-color: #005f93;
    }

    .photo-remove-row {
        margin-top: 10px;
        padding: 9px 10px;
        box-sizing: border-box;
        border: 1px solid #ffd5d5;
        border-radius: 8px;
        background: #fff8f8;
        font-size: 0.82rem;
        color: #7a4141;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .photo-remove-row input {
        width: 16px;
        height: 16px;
        accent-color: #c1121f;
    }

    .profile-modal-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
    }

    @media (max-width: 900px) {
        .patient-hero,
        .dashboard-layout,
        .summary-grid,
        .record-grid,
        .clinic-tips-bar,
        .get-started-steps {
            grid-template-columns: 1fr;
        }

        .clinic-tips-bar {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .hero-actions {
            justify-content: flex-start;
        }

        .appointment-row {
            grid-template-columns: 1fr;
        }

        .appointment-side {
            align-items: flex-start;
        }

        .status-badge {
            width: fit-content;
        }
    }

    @media (max-width: 560px) {
        .patient-shell {
            padding: 18px 12px 34px;
        }

        .patient-hero,
        .panel {
            padding: 18px;
        }

        .hero-actions,
        .primary-btn,
        .secondary-btn,
        .plain-btn {
            width: 100%;
        }

        .profile-item {
            grid-template-columns: 1fr;
            gap: 2px;
        }

        .profile-form-grid {
            grid-template-columns: 1fr;
        }

        .profile-modal-body {
            grid-template-columns: 1fr;
        }

        .patient-hero {
            grid-template-columns: 1fr;
            text-align: center;
        }

        .patient-profile-trigger {
            margin: 0 auto;
        }
    }

    .profile-feedback-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(7, 59, 76, 0.55);
        z-index: 2500;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: profileFadeIn 0.25s ease;
    }
    .profile-feedback-overlay.active {
        display: flex;
    }
    @keyframes profileFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .profile-feedback-box {
        background: #fff;
        border-radius: 16px;
        padding: 32px 28px;
        max-width: 420px;
        width: 100%;
        text-align: center;
        box-shadow: 0 24px 60px rgba(7, 59, 76, 0.28);
        animation: profilePopIn 0.3s ease;
        border-top: 4px solid #48cae4;
    }
    @keyframes profilePopIn {
        from { opacity: 0; transform: scale(0.92) translateY(10px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .profile-feedback-box.success { border-top-color: #28a745; }
    .profile-feedback-box.error { border-top-color: #dc3545; }
    .profile-feedback-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 16px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
    }
    .profile-feedback-icon.success {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
    }
    .profile-feedback-icon.error {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
    }
    .profile-feedback-icon svg { width: 28px; height: 28px; }
    .profile-feedback-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #073b4c;
        margin: 0 0 10px;
    }
    .profile-feedback-message {
        color: #60727d;
        font-size: 0.95rem;
        margin: 0 0 24px;
        line-height: 1.5;
    }
    .profile-feedback-btn {
        width: 100%;
        padding: 12px 20px;
        border: none;
        border-radius: 10px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.2s ease;
    }
    .profile-feedback-btn.success {
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(72, 202, 228, 0.4);
    }
    .profile-feedback-btn.error {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: #fff;
    }
';

$additionalHeadLinks = '
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
';

include 'includes/header.php';
?>
    <main class="patient-shell">
        <section class="patient-hero<?php echo $isNewPatientWelcome ? ' is-new-welcome' : ''; ?>">
            <button type="button" class="patient-profile-trigger" id="openProfileFromHero" aria-label="Open my profile">
                <?php if ($profilePhotoUrl): ?>
                    <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile photo" class="patient-profile-avatar">
                <?php else: ?>
                    <span class="patient-profile-avatar"><?php echo htmlspecialchars($profileInitials); ?></span>
                <?php endif; ?>
                <span class="edit-badge" aria-hidden="true">✎</span>
            </button>
            <div>
                <span class="patient-kicker">My Health Dashboard</span>
                <?php if ($isNewPatientWelcome): ?>
                    <span class="new-welcome-badge">New account</span>
                <?php endif; ?>
                <h2><?php echo htmlspecialchars($welcomeTitle); ?></h2>
                <p><?php echo htmlspecialchars($welcomeSubtitle); ?></p>
            </div>
            <div class="hero-actions">
                <?php if ($isNewPatientWelcome || $upcomingCount === 0): ?>
                    <a href="book_appointment.php" class="primary-btn">Book First Appointment</a>
                <?php else: ?>
                    <a href="book_appointment.php" class="primary-btn">Book Appointment</a>
                <?php endif; ?>
                <a href="view_appointments.php" class="secondary-btn">View Appointments</a>
            </div>
        </section>

        <?php if ($showGetStartedPanel): ?>
        <section class="get-started-panel" aria-label="Getting started">
            <h3><?php echo $isNewPatientWelcome ? 'Get started with your clinic account' : 'Ready to schedule a visit?'; ?></h3>
            <p><?php echo $isNewPatientWelcome
                ? 'You are all set. Follow these steps for a smooth first visit at Globalife.'
                : 'You have no upcoming appointments. Book a slot when you are ready.'; ?></p>
            <ol class="get-started-steps">
                <?php $getStartedStep = 1; ?>
                <?php if (!$hideProfileStepInGetStarted): ?>
                <li>
                    <strong><?php echo $getStartedStep++; ?>. Complete your profile</strong>
                    Add phone, email, and emergency contact so the clinic can reach you.
                    <a href="#" id="openProfileFromGetStarted">Update profile</a>
                </li>
                <?php endif; ?>
                <li>
                    <strong><?php echo $getStartedStep++; ?>. Book online</strong>
                    Choose clinic consultation or laboratory services and pick a date and time.
                    <a href="book_appointment.php">Book now</a>
                </li>
                <li>
                    <strong><?php echo $getStartedStep++; ?>. Visit the clinic</strong>
                    Bring a valid ID, arrive on time, and pay at the front desk unless staff advises otherwise.
                </li>
            </ol>
        </section>
        <?php endif; ?>

        <section class="clinic-tips-bar" aria-label="Before your clinic visit">
            <div class="clinic-tip-card">
                <strong>Valid ID</strong>
                <p>Bring a government-issued ID for verification at the clinic.</p>
            </div>
            <div class="clinic-tip-card">
                <strong>Payment</strong>
                <p>Consultation and lab fees are paid at the clinic unless told otherwise.</p>
            </div>
            <div class="clinic-tip-card">
                <strong>On time</strong>
                <p>Arrive a few minutes early for your scheduled slot.</p>
            </div>
            <div class="clinic-tip-card">
                <strong>Documents</strong>
                <p>Bring prescriptions, referrals, or past lab results if you have them.</p>
            </div>
        </section>

        <?php if ($pageMessage): ?>
            <div class="profile-alert success"><?php echo htmlspecialchars($pageMessage); ?></div>
        <?php endif; ?>
        <?php if ($pageError): ?>
            <div class="profile-alert error"><?php echo htmlspecialchars($pageError); ?></div>
        <?php endif; ?>

        <div class="dashboard-layout">
            <div>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>Upcoming Appointments</h3>
                        <p>Your next clinic visits and booking status.</p>
                    </div>
                    <a href="view_appointments.php" class="plain-btn">See all</a>
                </div>

                <?php if ($nextAppointment): ?>
                    <div class="next-card">
                        <div class="next-card-title">Next appointment</div>
                        <div class="appointment-meta">
                            <span><?php echo patient_format_date($nextAppointment['appointment_date']); ?></span>
                            <span><?php echo patient_format_time($nextAppointment['appointment_time']); ?></span>
                            <span><?php echo htmlspecialchars(patient_booking_label($nextAppointment['booking_type'] ?? null)); ?></span>
                            <span>Ref #<?php echo (int) $nextAppointment['id']; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($appointments)): ?>
                    <div class="empty-state">
                        <h3>No upcoming appointments</h3>
                        <p>You have no scheduled visits yet. Start a booking when you are ready.</p>
                        <a href="book_appointment.php" class="primary-btn">Book Your First Appointment</a>
                    </div>
                <?php else: ?>
                    <div class="appointment-list">
                        <?php foreach ($appointments as $appointment): ?>
                            <?php
                            $statusClass = patient_status_class($appointment['status'] ?? '');
                            $doctorName = trim((string) ($appointment['doctor_name'] ?? ''));
                            $doctorText = $doctorName !== '' ? $doctorName : 'Clinic staff';
                            ?>
                            <article class="appointment-row">
                                <div class="date-box">
                                    <?php echo htmlspecialchars(patient_format_date($appointment['appointment_date'])); ?>
                                    <small><?php echo htmlspecialchars(patient_format_time($appointment['appointment_time'])); ?></small>
                                </div>
                                <div class="appointment-main">
                                    <strong><?php echo htmlspecialchars(patient_booking_label($appointment['booking_type'] ?? null)); ?></strong>
                                    <div class="appointment-meta">
                                        <span>Doctor: <?php echo htmlspecialchars($doctorText); ?></span>
                                        <span>Ref #<?php echo (int) $appointment['id']; ?></span>
                                        <?php if (isset($appointment['total_display_price']) && $appointment['total_display_price'] !== null): ?>
                                            <span>Est. PHP <?php echo number_format((float) $appointment['total_display_price'], 2); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="appointment-side">
                                    <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                        <?php echo htmlspecialchars(ucfirst($appointment['status'] ?? 'Pending')); ?>
                                    </span>
                                    <?php if (in_array($statusClass, ['pending', 'confirmed'], true)): ?>
                                        <form method="post" action="patients.php" onsubmit="return confirm('Cancel this appointment?');">
                                            <input type="hidden" name="patient_action" value="cancel_appointment">
                                            <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                            <button type="submit" class="cancel-inline">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel" id="health-records">
                <div class="panel-header">
                    <div>
                        <h3>My Health Records</h3>
                        <p>Recent notes and laboratory results posted by clinic staff.</p>
                    </div>
                </div>

                <div class="record-grid">
                    <div class="record-column">
                        <h4>Medical notes</h4>
                        <?php if (empty($medicalRecords)): ?>
                            <div class="empty-state">
                                <h3>No medical notes yet</h3>
                                <p>Clinic notes will appear here after staff adds them to your record.</p>
                            </div>
                        <?php else: ?>
                            <div class="record-list">
                                <?php foreach ($medicalRecords as $record): ?>
                                    <article class="record-card">
                                        <strong><?php echo htmlspecialchars(trim((string) ($record['diagnosis'] ?? '')) !== '' ? $record['diagnosis'] : $record['title']); ?></strong>
                                        <small><?php echo htmlspecialchars(patient_format_datetime($record['created_at'])); ?><?php echo !empty($record['author_name']) ? ' by ' . htmlspecialchars($record['author_name']) : ''; ?></small>
                                        <?php
                                        $sections = nurse_medical_sections_for_display($record);
                                        $shown = 0;
                                        foreach ($sections as $label => $text):
                                            if ($shown >= 3) {
                                                break;
                                            }
                                            $shown++;
                                        ?>
                                            <p><strong><?php echo htmlspecialchars($label); ?>:</strong> <?php echo htmlspecialchars(patient_short_text($text, 120)); ?></p>
                                        <?php endforeach; ?>
                                        <?php if ($shown === 0): ?>
                                            <p><?php echo htmlspecialchars(patient_short_text($record['content'] ?? '')); ?></p>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="record-column">
                        <h4>Lab results</h4>
                        <?php if (empty($labResults)): ?>
                            <div class="empty-state">
                                <h3>No lab results yet</h3>
                                <p>Your laboratory result entries will appear here once posted by clinic staff.</p>
                            </div>
                        <?php else: ?>
                            <div class="record-list">
                                <?php foreach ($labResults as $lab): ?>
                                    <article class="record-card">
                                        <strong><?php echo htmlspecialchars($lab['test_name']); ?></strong>
                                        <small>Result date: <?php echo htmlspecialchars(patient_format_date($lab['result_date'])); ?><?php echo !empty($lab['author_name']) ? ' by ' . htmlspecialchars($lab['author_name']) : ''; ?></small>
                                        <p><?php echo htmlspecialchars(patient_short_text($lab['result_text'] ?? '')); ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            </div>

            <aside>
                <section class="panel" id="profile">
                    <div class="panel-header">
                        <div>
                            <h3>My Profile</h3>
                            <p>Tap your photo to update profile and picture.</p>
                        </div>
                    </div>

                    <button type="button" class="profile-card-mini" id="openProfileFromCard">
                        <?php if ($profilePhotoUrl): ?>
                            <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile photo" class="patient-profile-avatar">
                        <?php else: ?>
                            <span class="patient-profile-avatar"><?php echo htmlspecialchars($profileInitials); ?></span>
                        <?php endif; ?>
                        <div>
                            <strong><?php echo htmlspecialchars($userDetails['full_name'] ?? $currentUser['full_name']); ?></strong>
                            <span>Update photo, crop, rotate, and edit details</span>
                        </div>
                    </button>

                    <button type="button" class="primary-btn" id="openProfileFromBtn" style="width:100%;">Edit My Profile</button>

                    <div class="progress-track" aria-label="Profile completeness" style="margin-top:14px;">
                        <div class="progress-bar" style="--value: <?php echo $profileScore; ?>%;"></div>
                    </div>
                    <p style="margin:0;color:#60727d;font-size:.9rem;"><?php echo $profileScore; ?>% complete</p>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Contact Readiness</h3>
                            <p>Details used for reminders and clinic follow-ups.</p>
                        </div>
                    </div>
                    <div class="readiness-list">
                        <?php foreach ($readinessItems as $item): ?>
                            <div class="readiness-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($item['label']); ?></strong>
                                    <span><?php echo htmlspecialchars($item['detail']); ?></span>
                                </div>
                                <div class="readiness-status <?php echo $item['ready'] ? '' : 'missing'; ?>">
                                    <?php echo $item['ready'] ? 'Ready' : 'Missing'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>
    </main>

    <div class="profile-modal-overlay" id="patientProfileModal" role="dialog" aria-modal="true" aria-labelledby="patientProfileModalTitle">
        <div class="profile-modal">
            <div class="profile-modal-header">
                <h3 id="patientProfileModalTitle">My Profile</h3>
                <button type="button" class="profile-modal-close" id="closeProfileModal" aria-label="Close profile editor">&times;</button>
            </div>
            <form method="post" action="patients.php?profile=1" class="profile-modal-body" id="patientProfileForm">
                <input type="hidden" name="profile_action" value="update_profile">
                <input type="hidden" name="profile_photo_cropped" id="profile_photo_cropped" value="">

                <div class="photo-editor-panel">
                    <h4>Profile picture</h4>
                    <p class="photo-help-text">Add or change your picture, then crop and zoom it before saving.</p>
                    <label class="photo-preview-wrap" id="photoPreviewWrap" for="profilePhotoInput" aria-label="Add or change profile picture">
                        <?php if ($profilePhotoUrl): ?>
                            <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Current profile photo">
                        <?php else: ?>
                            <span class="patient-profile-avatar"><?php echo htmlspecialchars($profileInitials); ?></span>
                        <?php endif; ?>
                        <span class="photo-preview-overlay">
                            <span class="photo-preview-icon">+</span>
                            <span class="photo-preview-copy">
                                <strong><?php echo $profilePhotoUrl ? 'Change photo' : 'Add photo'; ?></strong>
                                <span>Choose a photo, then crop and zoom it here.</span>
                            </span>
                        </span>
                    </label>
                    <div class="crop-stage" id="cropStage">
                        <img src="" alt="Crop preview" id="cropImage">
                    </div>
                    <p class="photo-crop-tip" id="photoCropTip">Drag the photo to crop it. Use zoom until the profile picture looks right.</p>
                    <div class="photo-file-name" id="photoFileName"></div>
                    <div class="photo-zoom-control" id="photoZoomControl">
                        <label for="photoZoomRange">
                            <span>Zoom</span>
                            <span id="photoZoomValue">0%</span>
                        </label>
                        <input type="range" id="photoZoomRange" min="-30" max="70" value="0" step="1">
                    </div>
                    <div class="photo-toolbar" id="photoToolbar" style="display:none;">
                        <button type="button" id="zoomInBtn">Zoom In</button>
                        <button type="button" id="zoomOutBtn">Zoom Out</button>
                        <button type="button" id="resetCropBtn">Reset Crop</button>
                    </div>
                    <label class="photo-upload-label">
                        Add / Change Photo
                        <input type="file" id="profilePhotoInput" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <?php if ($profilePhotoUrl): ?>
                        <div class="photo-remove-row">
                            <input type="checkbox" name="remove_profile_photo" id="remove_profile_photo" value="1">
                            <label for="remove_profile_photo">Remove current photo</label>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="profile-form">
                        <div class="profile-form-grid">
                            <div class="profile-field full">
                                <label for="modal_full_name">Full name</label>
                                <input type="text" id="modal_full_name" name="full_name" value="<?php echo htmlspecialchars($userDetails['full_name'] ?? $currentUser['full_name']); ?>" required>
                            </div>
                            <div class="profile-field">
                                <label for="modal_email">Email</label>
                                <input type="email" id="modal_email" name="email" value="<?php echo htmlspecialchars($userDetails['email'] ?? ''); ?>" placeholder="name@example.com">
                            </div>
                            <div class="profile-field">
                                <label for="modal_phone">Phone</label>
                                <input type="text" id="modal_phone" name="phone" value="<?php echo htmlspecialchars($userDetails['phone'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                            </div>
                            <div class="profile-field">
                                <label for="modal_gender">Gender</label>
                                <select id="modal_gender" name="gender">
                                    <option value="">Select gender</option>
                                    <?php foreach (['Male', 'Female', 'Other'] as $genderOption): ?>
                                        <option value="<?php echo htmlspecialchars($genderOption); ?>" <?php echo (($userDetails['gender'] ?? '') === $genderOption) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($genderOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="profile-field">
                                <label for="modal_date_of_birth">Date of birth</label>
                                <input type="date" id="modal_date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($userDetails['date_of_birth'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_civil_status">Civil status</label>
                                <input type="text" id="modal_civil_status" name="civil_status" value="<?php echo htmlspecialchars($userDetails['civil_status'] ?? ''); ?>" placeholder="Single, Married, etc.">
                            </div>
                            <div class="profile-field">
                                <label>Age</label>
                                <input type="text" value="<?php echo htmlspecialchars($userDetails['age'] ?? 'Auto-calculated'); ?>" readonly>
                            </div>
                            <div class="profile-field full">
                                <label for="modal_address">Address</label>
                                <textarea id="modal_address" name="address" placeholder="House no., street, subdivision"><?php echo htmlspecialchars($userDetails['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="profile-field">
                                <label for="modal_barangay">Barangay</label>
                                <input type="text" id="modal_barangay" name="barangay" value="<?php echo htmlspecialchars($userDetails['barangay'] ?? ''); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_city">City</label>
                                <input type="text" id="modal_city" name="city" value="<?php echo htmlspecialchars($userDetails['city'] ?? ''); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_emergency_contact_name">Emergency contact</label>
                                <input type="text" id="modal_emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($userDetails['emergency_contact_name'] ?? ''); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_emergency_contact_relationship">Relationship</label>
                                <input type="text" id="modal_emergency_contact_relationship" name="emergency_contact_relationship" value="<?php echo htmlspecialchars($userDetails['emergency_contact_relationship'] ?? ''); ?>">
                            </div>
                            <div class="profile-field full">
                                <label for="modal_emergency_contact_number">Emergency number</label>
                                <input type="text" id="modal_emergency_contact_number" name="emergency_contact_number" value="<?php echo htmlspecialchars($userDetails['emergency_contact_number'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="profile-password-section">
                            <h4>Change password (optional)</h4>
                            <p>Leave blank to keep your current password. Use at least 8 characters with upper, lower, number, and special character.</p>
                            <div class="profile-form-grid">
                                <div class="profile-field full">
                                    <label for="modal_current_password">Current password</label>
                                    <input type="password" id="modal_current_password" name="current_password" autocomplete="current-password" placeholder="Required only if changing password">
                                </div>
                                <div class="profile-field">
                                    <label for="modal_new_password">New password</label>
                                    <input type="password" id="modal_new_password" name="new_password" autocomplete="new-password" placeholder="New password">
                                </div>
                                <div class="profile-field">
                                    <label for="modal_confirm_new_password">Confirm new password</label>
                                    <input type="password" id="modal_confirm_new_password" name="confirm_new_password" autocomplete="new-password" placeholder="Confirm new password">
                                </div>
                            </div>
                        </div>

                        <div class="profile-modal-actions">
                            <button type="submit" class="primary-btn">Save Profile</button>
                            <button type="button" class="plain-btn" id="cancelProfileModal">Cancel</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="profile-feedback-overlay" id="profileSuccessPopup" role="dialog" aria-modal="true" aria-labelledby="profileSuccessTitle">
        <div class="profile-feedback-box success">
            <div class="profile-feedback-icon success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h3 class="profile-feedback-title" id="profileSuccessTitle">Profile Saved!</h3>
            <p class="profile-feedback-message">Your profile has been saved successfully. All changes including your photo are now updated.</p>
            <button type="button" class="profile-feedback-btn success" id="profileSuccessOk">OK</button>
        </div>
    </div>

    <div class="profile-feedback-overlay" id="profileErrorPopup" role="dialog" aria-modal="true" aria-labelledby="profileErrorTitle">
        <div class="profile-feedback-box error">
            <div class="profile-feedback-icon error">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h3 class="profile-feedback-title" id="profileErrorTitle">Could Not Save Profile</h3>
            <p class="profile-feedback-message" id="profileErrorMessage"><?php echo htmlspecialchars($profileError ?: 'Please check your details and try again.'); ?></p>
            <button type="button" class="profile-feedback-btn error" id="profileErrorOk">Try Again</button>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
    (function() {
        function showProfileFeedback(id) {
            const overlay = document.getElementById(id);
            if (overlay) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function hideProfileFeedback(id) {
            const overlay = document.getElementById(id);
            if (overlay) {
                overlay.classList.remove('active');
                const modal = document.getElementById('patientProfileModal');
                const modalOpen = modal && modal.classList.contains('active');
                if (!modalOpen) {
                    document.body.style.overflow = '';
                }
            }
        }

        const modal = document.getElementById('patientProfileModal');
        const form = document.getElementById('patientProfileForm');
        const photoInput = document.getElementById('profilePhotoInput');
        const cropStage = document.getElementById('cropStage');
        const cropImage = document.getElementById('cropImage');
        const photoToolbar = document.getElementById('photoToolbar');
        const photoPreviewWrap = document.getElementById('photoPreviewWrap');
        const croppedField = document.getElementById('profile_photo_cropped');
        const removePhotoCheckbox = document.getElementById('remove_profile_photo');
        const photoCropTip = document.getElementById('photoCropTip');
        const photoFileName = document.getElementById('photoFileName');
        const photoZoomControl = document.getElementById('photoZoomControl');
        const photoZoomRange = document.getElementById('photoZoomRange');
        const photoZoomValue = document.getElementById('photoZoomValue');
        let cropper = null;
        let pendingCrop = false;
        let zoomLevel = 0;

        function openProfileModal() {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProfileModal() {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        ['openProfileFromHero', 'openProfileFromCard', 'openProfileFromBtn', 'openProfileFromAction', 'openProfileFromGetStarted'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', function(e) {
                    if (el.tagName === 'A') {
                        e.preventDefault();
                    }
                    openProfileModal();
                });
            }
        });

        function cancelProfileEditor() {
            destroyCropper();
            pendingCrop = false;
            croppedField.value = '';
            photoInput.value = '';
            closeProfileModal();
        }

        document.getElementById('closeProfileModal').addEventListener('click', cancelProfileEditor);
        document.getElementById('cancelProfileModal').addEventListener('click', cancelProfileEditor);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                cancelProfileEditor();
            }
        });

        function cleanupCropStage() {
            cropStage.querySelectorAll('.cropper-container').forEach(function(container) {
                container.remove();
            });
            cropImage.className = '';
            cropImage.removeAttribute('style');
        }

        function destroyCropper() {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            cleanupCropStage();
            cropImage.onload = null;
            cropImage.src = '';
            cropStage.classList.remove('active');
            photoToolbar.style.display = 'none';
            photoPreviewWrap.style.display = 'flex';
            hideCropTools();
        }

        function setZoomUi(value) {
            zoomLevel = Math.max(-30, Math.min(70, Number(value) || 0));
            if (photoZoomRange) {
                photoZoomRange.value = String(zoomLevel);
            }
            if (photoZoomValue) {
                photoZoomValue.textContent = (zoomLevel > 0 ? '+' : '') + zoomLevel + '%';
            }
        }

        function changeZoom(nextValue) {
            if (!cropper) {
                return;
            }
            const nextZoom = Math.max(-30, Math.min(70, Number(nextValue) || 0));
            const zoomChange = nextZoom - zoomLevel;
            if (zoomChange !== 0) {
                cropper.zoom(zoomChange / 100);
            }
            setZoomUi(nextZoom);
        }

        function showCropTools(file) {
            if (photoFileName) {
                photoFileName.textContent = file ? 'Selected: ' + file.name : '';
                photoFileName.classList.toggle('active', !!file);
            }
            if (photoCropTip) {
                photoCropTip.classList.add('active');
            }
            if (photoZoomControl) {
                photoZoomControl.classList.add('active');
            }
            setZoomUi(0);
        }

        function hideCropTools() {
            if (photoFileName) {
                photoFileName.textContent = '';
                photoFileName.classList.remove('active');
            }
            if (photoCropTip) {
                photoCropTip.classList.remove('active');
            }
            if (photoZoomControl) {
                photoZoomControl.classList.remove('active');
            }
            setZoomUi(0);
        }

        function startCropper(file) {
            if (!file || !file.type.match(/^image\/(jpeg|png|webp)$/)) {
                alert('Please choose a JPG, PNG, or WebP image.');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                alert('Image must be 5 MB or smaller.');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(event) {
                destroyCropper();
                pendingCrop = true;
                if (removePhotoCheckbox) {
                    removePhotoCheckbox.checked = false;
                }
                photoPreviewWrap.style.display = 'none';
                cropStage.classList.add('active');
                photoToolbar.style.display = 'flex';
                showCropTools(file);
                cropImage.onload = function() {
                    cleanupCropStage();
                    cropper = new Cropper(cropImage, {
                        aspectRatio: 1,
                        viewMode: 2,
                        dragMode: 'move',
                        autoCropArea: 0.9,
                        responsive: true,
                        background: false,
                        movable: true,
                        zoomable: true,
                        zoomOnWheel: true,
                        wheelZoomRatio: 0.04,
                        minContainerWidth: 250,
                        minContainerHeight: 250,
                        ready: function() {
                            setZoomUi(0);
                        }
                    });
                    cropImage.onload = null;
                };
                cropImage.src = event.target.result;
            };
            reader.readAsDataURL(file);
        }

        photoInput.addEventListener('change', function() {
            if (photoInput.files && photoInput.files[0]) {
                startCropper(photoInput.files[0]);
            }
        });

        document.getElementById('zoomInBtn').addEventListener('click', function() {
            changeZoom(zoomLevel + 10);
        });
        document.getElementById('zoomOutBtn').addEventListener('click', function() {
            changeZoom(zoomLevel - 10);
        });
        document.getElementById('resetCropBtn').addEventListener('click', function() {
            if (cropper) {
                cropper.reset();
                setZoomUi(0);
            }
        });
        if (photoZoomRange) {
            photoZoomRange.addEventListener('input', function() {
                changeZoom(photoZoomRange.value);
            });
        }
        if (removePhotoCheckbox) {
            removePhotoCheckbox.addEventListener('change', function() {
                if (removePhotoCheckbox.checked) {
                    destroyCropper();
                    pendingCrop = false;
                    croppedField.value = '';
                    photoInput.value = '';
                }
            });
        }

        form.addEventListener('submit', function() {
            if (cropper) {
                const canvas = cropper.getCroppedCanvas({
                    width: 400,
                    height: 400,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });
                if (canvas) {
                    croppedField.value = canvas.toDataURL('image/jpeg', 0.9);
                }
            } else if (!pendingCrop) {
                croppedField.value = '';
            }
        });

        document.getElementById('profileSuccessOk').addEventListener('click', function() {
            hideProfileFeedback('profileSuccessPopup');
        });
        document.getElementById('profileErrorOk').addEventListener('click', function() {
            hideProfileFeedback('profileErrorPopup');
        });
        document.getElementById('profileSuccessPopup').addEventListener('click', function(e) {
            if (e.target === this) hideProfileFeedback('profileSuccessPopup');
        });
        document.getElementById('profileErrorPopup').addEventListener('click', function(e) {
            if (e.target === this) hideProfileFeedback('profileErrorPopup');
        });

        <?php if ($showProfileSuccessPopup): ?>
        showProfileFeedback('profileSuccessPopup');
        <?php endif; ?>
        <?php if ($showProfileErrorPopup): ?>
        showProfileFeedback('profileErrorPopup');
        openProfileModal();
        <?php elseif ($openProfileModal): ?>
        openProfileModal();
        <?php endif; ?>
    })();
    </script>
<?php include 'includes/footer.php'; ?>

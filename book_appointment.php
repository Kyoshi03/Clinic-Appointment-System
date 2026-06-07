<?php
require_once 'includes/session.php';
checkRole('patient');

require_once 'config/database.php';
require_once 'includes/appointment_booking.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';
require_once __DIR__ . '/includes/doctor_schedule.php';

$currentUser = getCurrentUser();

if (isset($_GET['reset'])) {
    unset($_SESSION['lab_booking']);
    header('Location: book_appointment.php');
    exit;
}

if (!isset($_SESSION['lab_booking']) || !is_array($_SESSION['lab_booking'])) {
    $_SESSION['lab_booking'] = [];
}
$bk =& $_SESSION['lab_booking'];
$defaults = [
    'step' => 1,
    'type' => null,
    'service_ids' => [],
    'doctor_id' => null,
    'price_channel' => 'opd',
    'appointment_date' => '',
    'appointment_time' => '',
];
foreach ($defaults as $k => $v) {
    if (!array_key_exists($k, $bk)) {
        $bk[$k] = $v;
    }
}

if (isset($_GET['step_back'])) {
    $cur = (int) ($bk['step'] ?? 1);
    if ($cur > 1) {
        $bk['step'] = $cur - 1;
        if ($bk['step'] === 1) {
            $bk['type'] = null;
            $bk['service_ids'] = [];
            $bk['doctor_id'] = null;
            $bk['price_channel'] = 'opd';
            $bk['appointment_date'] = '';
            $bk['appointment_time'] = '';
        } elseif ($bk['step'] <= 3) {
            $bk['appointment_date'] = '';
            $bk['appointment_time'] = '';
            $bk['doctor_id'] = null;
        }
    }
    header('Location: book_appointment.php');
    exit;
}

$conn = getDBConnection();

$headerStmt = $conn->prepare("SELECT full_name, profile_photo, profile_updated_at FROM users WHERE id = ?");
$headerStmt->bind_param("i", $currentUser['id']);
$headerStmt->execute();
$patientHeaderDetails = $headerStmt->get_result()->fetch_assoc() ?: [];
$headerStmt->close();
$headerPatientPhotoUrl = patientProfilePhotoUrl($patientHeaderDetails['profile_photo'] ?? null, $patientHeaderDetails['profile_updated_at'] ?? null);
$headerPatientInitials = patientProfileInitials($patientHeaderDetails['full_name'] ?? $currentUser['full_name']);
$headerPatientDisplayName = $patientHeaderDetails['full_name'] ?? $currentUser['full_name'];

$error = '';
$bookedId = isset($_GET['booked']) ? (int) $_GET['booked'] : 0;
$appointmentEmailWarning = (string) ($_SESSION['appointment_email_warning'] ?? '');
unset($_SESSION['appointment_email_warning']);

/**
 * Package deals in booking: first price sheet only (OPD pre-employment, sanitary permit, CVSU).
 *
 * @return array<int,array<string,mixed>>
 */
function fetchLabBookingPackages(mysqli $conn): array {
    $cats = lab_booking_package_only_categories();
    $placeholders = implode(',', array_fill(0, count($cats), '?'));
    $types = str_repeat('s', count($cats));
    $sql = "SELECT id, name, category, description, included_tests, opd_price, home_service_price, is_package FROM lab_services WHERE is_active = 1 AND is_package = 1 AND category IN ($placeholders) ORDER BY category, name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$cats);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Individual tests: second price sheet (excludes sheet-1 categories even if mis-tagged).
 *
 * @return array<int,array<string,mixed>>
 */
function fetchLabBookingIndividuals(mysqli $conn): array {
    $cats = lab_booking_package_only_categories();
    $placeholders = implode(',', array_fill(0, count($cats), '?'));
    $types = str_repeat('s', count($cats));
    $sql = "SELECT id, name, category, description, included_tests, opd_price, home_service_price, is_package FROM lab_services WHERE is_active = 1 AND is_package = 0 AND category NOT IN ($placeholders) ORDER BY category, name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$cats);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * @param int[] $ids
 * @return array<int,array<string,mixed>>
 */
function fetchServicesByIds(mysqli $conn, array $ids): array {
    if (empty($ids)) {
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn ($i) => $i > 0);
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, name, category, description, included_tests, opd_price, home_service_price, is_package FROM lab_services WHERE is_active = 1 AND id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function serviceUnitPrice(array $svc, string $channel): float {
    if ($channel === 'home' && isset($svc['home_service_price']) && $svc['home_service_price'] !== null && (float) $svc['home_service_price'] > 0) {
        return (float) $svc['home_service_price'];
    }
    return (float) $svc['opd_price'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['booking_action'] ?? '';

    if ($action === 'select_type') {
        $t = $_POST['booking_type'] ?? '';
        if ($t !== 'package' && $t !== 'individual') {
            $error = 'Please choose a service type.';
        } else {
            $bk['type'] = $t;
            $bk['service_ids'] = [];
            $bk['doctor_id'] = null;
            $bk['step'] = 2;
            header('Location: book_appointment.php');
            exit;
        }
    }

    if ($action === 'choose_services') {
        if ($bk['type'] === 'package') {
            $pid = (int) ($_POST['package_id'] ?? 0);
            if ($pid <= 0) {
                $error = 'Please select a package.';
            } else {
                $bk['service_ids'] = [$pid];
                $bk['doctor_id'] = null;
                $bk['step'] = 3;
                header('Location: book_appointment.php');
                exit;
            }
        } elseif ($bk['type'] === 'individual') {
            $ids = $_POST['service_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_map('intval', $ids);
            $ids = array_values(array_filter($ids, fn ($i) => $i > 0));
            if (empty($ids)) {
                $error = 'Select at least one laboratory test.';
            } else {
                $bk['service_ids'] = $ids;
                $bk['doctor_id'] = null;
                $bk['step'] = 3;
                header('Location: book_appointment.php');
                exit;
            }
        } else {
            $bk['step'] = 1;
            header('Location: book_appointment.php');
            exit;
        }
    }

    if ($action === 'set_channel') {
        if (($bk['type'] ?? '') === 'package') {
            $bk['price_channel'] = 'opd';
        } else {
            $ch = $_POST['price_channel'] ?? 'opd';
            $bk['price_channel'] = ($ch === 'home') ? 'home' : 'opd';
        }
        if (empty($bk['service_ids'])) {
            $bk['step'] = 2;
            header('Location: book_appointment.php');
            exit;
        }
        $bk['step'] = 4;
        header('Location: book_appointment.php');
        exit;
    }

    if ($action === 'refresh_schedule') {
        $d = trim($_POST['appointment_date'] ?? '');
        $t = trim($_POST['appointment_time'] ?? '');
        if ($d !== '') {
            $bk['appointment_date'] = $d;
        }
        if ($t !== '') {
            $bk['appointment_time'] = $t;
        }
        if (($bk['type'] ?? '') === 'individual' && !empty($bk['doctor_id'])) {
            $docId = (int) $bk['doctor_id'];
            if ($d === '' || $t === '' || !user_is_doctor_available_at($conn, $docId, $d, $t)) {
                $bk['doctor_id'] = null;
            }
        }
        $bk['step'] = 4;
        header('Location: book_appointment.php');
        exit;
    }

    if ($action === 'set_schedule') {
        $d = trim($_POST['appointment_date'] ?? '');
        $t = trim($_POST['appointment_time'] ?? '');
        $docId = (int) ($_POST['doctor_id'] ?? 0);
        $bk['step'] = 4;
        if ($d !== '') {
            $bk['appointment_date'] = $d;
        }
        if ($t !== '') {
            $bk['appointment_time'] = $t;
        }
        if ($d === '' || $t === '') {
            $error = 'Please choose date and time.';
            $bk['doctor_id'] = null;
        } else {
            $selected_date = new DateTime($d);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            if ($selected_date < $today) {
                $error = 'Appointment date cannot be in the past.';
                $bk['doctor_id'] = null;
            } elseif (($bk['type'] ?? '') === 'individual') {
                if ($docId <= 0) {
                $error = 'Please choose an available doctor for your selected date and time.';
                    $bk['doctor_id'] = null;
                } elseif (!user_is_doctor_available_at($conn, $docId, $d, $t)) {
                    $error = 'That doctor is not available for the selected schedule. Please change the date/time or choose another doctor.';
                    $bk['doctor_id'] = null;
                } else {
                    $bk['doctor_id'] = $docId;
                }
            } else {
                $bk['doctor_id'] = null;
            }
            if ($error === '') {
                $bk['appointment_date'] = $d;
                $bk['appointment_time'] = $t;
                $bk['step'] = 5;
                header('Location: book_appointment.php');
                exit;
            }
        }
    }

    if ($action === 'confirm_booking') {
        if ($bk['step'] < 5 || empty($bk['service_ids']) || $bk['type'] === null) {
            $bk['step'] = 1;
            header('Location: book_appointment.php');
            exit;
        }
        if ($bk['type'] === 'individual') {
            $docId = (int) ($bk['doctor_id'] ?? 0);
            if ($docId <= 0) {
                $error = 'No doctor was selected. Please go back to the schedule step and choose an available doctor.';
                $bk['step'] = 4;
            } elseif (!user_is_doctor_available_at($conn, $docId, $bk['appointment_date'], $bk['appointment_time'])) {
                $error = 'The selected doctor is no longer available for this schedule. Please choose another date, time, or doctor.';
                $bk['doctor_id'] = null;
                $bk['step'] = 4;
            }
        } else {
            $bk['doctor_id'] = null;
        }

        if ($error === '') {
            $verification = appointment_issue_verification(
                $conn,
                (int) $currentUser['id'],
                [
                    'type' => $bk['type'],
                    'service_ids' => $bk['service_ids'],
                    'doctor_id' => $bk['doctor_id'],
                    'price_channel' => $bk['price_channel'],
                    'appointment_date' => $bk['appointment_date'],
                    'appointment_time' => $bk['appointment_time'],
                ]
            );

            if (!$verification['ok']) {
                $error = (string) $verification['error'];
            } else {
                $_SESSION['pending_appointment_verification_id'] = (int) $verification['verification_id'];
                $conn->close();
                $sentQuery = empty($verification['already_sent']) ? '?sent=1' : '';
                header('Location: verify_appointment.php' . $sentQuery);
                exit;
            }
        }
    }
}

$step = (int) ($bk['step'] ?? 1);
if ($step < 1) {
    $step = 1;
}
if ($step > 5) {
    $step = 5;
}

if ($step === 2 && empty($bk['type'])) {
    $step = 1;
    $bk['step'] = 1;
}
if ($step >= 3 && empty($bk['service_ids'])) {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 4 && empty($bk['service_ids'])) {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 5 && ($bk['appointment_date'] === '' || $bk['appointment_time'] === '')) {
    $step = 4;
    $bk['step'] = 4;
}
if ($step >= 5 && ($bk['type'] ?? '') === 'individual' && empty($bk['doctor_id'])) {
    $step = 4;
    $bk['step'] = 4;
}

$packageServices = [];
$individualServices = [];
if ($bk['type'] === 'package') {
    $packageServices = lab_order_package_services(fetchLabBookingPackages($conn));
}
if ($bk['type'] === 'individual') {
    $individualServices = fetchLabBookingIndividuals($conn);
}

$groupedPackages = !empty($packageServices) ? lab_group_services_list($packageServices) : [];
$groupedIndividual = !empty($individualServices) ? lab_group_services_list($individualServices) : [];

$selectedServices = [];
$displayTotal = 0;
if (!empty($bk['service_ids']) && ($bk['type'] ?? '') !== '') {
    $selectedServices = fetchServicesByIds($conn, $bk['service_ids']);
    if (count($selectedServices) !== count($bk['service_ids'])) {
        $selectedServices = [];
        $bk['service_ids'] = [];
        $bk['step'] = 2;
        $step = 2;
    } else {
        foreach ($selectedServices as $s) {
            if (!lab_booking_service_matches_type($s, $bk['type'])) {
                $selectedServices = [];
                $bk['service_ids'] = [];
                $bk['step'] = 2;
                $step = 2;
                break;
            }
        }
    }
    if (!empty($selectedServices)) {
        if (($bk['type'] ?? '') === 'package') {
            $bk['price_channel'] = 'opd';
            $ch = 'opd';
        } else {
            $ch = $bk['price_channel'] === 'home' ? 'home' : 'opd';
        }
        foreach ($selectedServices as $s) {
            $displayTotal += serviceUnitPrice($s, $ch);
        }
    }
}

$doctorBookingCards = [];
$nBookableDoctors = 0;
if ($step === 4 && ($bk['type'] ?? '') === 'individual') {
    $schedD = trim((string) ($bk['appointment_date'] ?? ''));
    $schedT = trim((string) ($bk['appointment_time'] ?? ''));
    if ($schedD !== '' && $schedT !== '') {
        $doctorBookingCards = fetch_doctors_for_booking_display($conn, $schedD, $schedT);
    } else {
        $doctorBookingCards = fetch_doctors_schedule_reference($conn);
    }
    $nBookableDoctors = count(array_filter($doctorBookingCards, fn ($d) => !empty($d['can_book'])));
}

$selectedDoctorName = '';
if ($step === 5 && ($bk['type'] ?? '') === 'individual' && !empty($bk['doctor_id'])) {
    $did = (int) $bk['doctor_id'];
    $st = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'doctor'");
    $st->bind_param('i', $did);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $selectedDoctorName = (string) $row['full_name'];
    }
}

$conn->close();

$pageTitle = "Book Appointment | Globalife Medical Laboratory & Polyclinic";
$additionalStyles = '
    body { background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%); min-height: 100vh; }
    .booking-container { max-width: 720px; margin: 40px auto; padding: 40px; background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
    .booking-container.book-wide { max-width: 980px; }
    .booking-header { text-align: center; margin-bottom: 28px; }
    .booking-header h2 { color: #0077b6; font-size: 2rem; margin-bottom: 8px; }
    .stepper { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-bottom: 28px; font-size: 0.8rem; color: #555; }
    .stepper span { padding: 6px 12px; border-radius: 20px; background: #f0f0f0; }
    .stepper span.on { background: #0077b6; color: #fff; font-weight: 600; }
    .choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px; }
    @media (max-width: 600px) { .choice-grid { grid-template-columns: 1fr; } }
    .choice-card { border: 2px solid #e0e0e0; border-radius: 16px; padding: 28px; text-align: center; cursor: pointer; transition: all 0.2s; background: #fafafa; }
    .choice-card:hover { border-color: #0077b6; box-shadow: 0 6px 20px rgba(0,119,182,0.15); }
    .choice-card h3 { margin: 0 0 10px; color: #023e8a; font-size: 1.25rem; }
    .choice-card p { margin: 0; color: #666; font-size: 0.95rem; }
    .svc-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
    .svc-toolbar label { font-weight: 600; font-size: 0.9rem; display: block; margin-bottom: 4px; }
    .svc-toolbar input, .svc-toolbar select { padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 1rem; min-width: 200px; box-sizing: border-box; }
    .svc-summary { background: linear-gradient(135deg, #e3f2fd, #f0f7fa); border: 1px solid #90caf9; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; font-size: 0.95rem; }
    .svc-summary strong { color: #023e8a; }
    .cat-block { margin-bottom: 18px; }
    .cat-heading { font-size: 1.05rem; font-weight: 700; color: #023e8a; margin: 0 0 10px; padding: 8px 0; border-bottom: 2px solid #caf0f8; }
    .service-list { max-height: 520px; overflow-y: auto; border: 1px solid #e8e8e8; border-radius: 12px; padding: 12px; }
    .service-row { display: flex; gap: 10px; align-items: flex-start; padding: 10px; border-radius: 8px; margin-bottom: 6px; }
    .service-row:hover { background: #f5fbff; }
    .service-row.hidden { display: none !important; }
    .service-row label { flex: 1; cursor: pointer; min-width: 0; }
    .btn-add-svc { flex-shrink: 0; padding: 6px 12px; font-size: 0.85rem; border-radius: 8px; border: 1px solid #0077b6; background: #fff; color: #0077b6; font-weight: 600; cursor: pointer; margin-top: 2px; }
    .btn-add-svc:hover { background: #0077b6; color: #fff; }
    .price-cols { display: flex; gap: 12px; flex-shrink: 0; font-size: 0.85rem; color: #555; }
    .price-cols span { min-width: 72px; text-align: right; }
    .price-tag { color: #0077b6; font-weight: 700; white-space: nowrap; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 1rem; box-sizing: border-box; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
    .btn-primary { width: 100%; background: linear-gradient(135deg, #0077b6, #023e8a); color: #fff; padding: 14px; border: none; border-radius: 10px; font-size: 1.05rem; font-weight: 600; cursor: pointer; margin-top: 8px; }
    .btn-secondary { display: inline-block; padding: 10px 18px; border-radius: 8px; background: #e3f2fd; color: #023e8a; text-decoration: none; font-weight: 600; margin-right: 10px; border: none; cursor: pointer; font-size: 1rem; }
    .error-message { background: #fee; color: #c1121f; padding: 14px; border-radius: 10px; margin-bottom: 18px; border-left: 4px solid #c1121f; }
    .success-banner { background: #d4edda; color: #155724; padding: 20px; border-radius: 12px; margin-bottom: 22px; border-left: 4px solid #28a745; }
    .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 14px; border-radius: 8px; margin-bottom: 20px; color: #1565c0; font-size: 0.95rem; }
    .clinic-reminder-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 16px 0 22px; }
    .clinic-reminder { background: #fff; border: 1px solid #dce9f4; border-radius: 10px; padding: 12px; box-shadow: 0 8px 18px rgba(20,79,123,.07); }
    .clinic-reminder strong { display: block; color: #0b4f80; margin-bottom: 5px; }
    .clinic-reminder span { color: #4a6072; font-size: .9rem; line-height: 1.45; }
    .review-check { display: flex; align-items: flex-start; gap: 10px; background: #f8fbff; border: 1px solid #dce9f4; border-radius: 10px; padding: 13px 14px; margin-bottom: 14px; color: #26495f; font-weight: 600; }
    .review-check input { margin-top: 3px; width: 18px; height: 18px; accent-color: #0077b6; }
    @media (max-width: 820px) { .clinic-reminder-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 520px) { .clinic-reminder-grid { grid-template-columns: 1fr; } }
    .detail-block { background: #f8fafc; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
    .detail-block h4 { margin: 0 0 8px; color: #0077b6; font-size: 1rem; }
    .detail-block p { margin: 0; color: #444; line-height: 1.5; }
    .channel-opt { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px; }
    .channel-opt label { font-weight: 500; cursor: pointer; }
    .back-link { display: inline-flex; align-items: center; gap: 8px; color: #0077b6; text-decoration: none; font-weight: 500; margin-bottom: 18px; }
    .summary-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
    .summary-table td { padding: 8px 0; border-bottom: 1px solid #eee; }
    .summary-table td:last-child { text-align: right; font-weight: 600; color: #0077b6; }
    .doc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px; }
    @media (max-width: 640px) { .doc-grid { grid-template-columns: 1fr; } }
    .doc-card { border-radius: 16px; padding: 18px; border: 2px solid #e0e0e0; background: #fafafa; transition: border-color 0.2s, box-shadow 0.2s; }
    .doc-card.theme-intern { border-color: #2196f3; background: linear-gradient(145deg, #e3f2fd 0%, #fff 55%); }
    .doc-card.theme-peds { border-color: #fbc02d; background: linear-gradient(145deg, #fffde7 0%, #fff 55%); }
    .doc-card-disabled { opacity: 0.72; filter: grayscale(0.15); pointer-events: none; }
    .doc-card.doc-selected { box-shadow: 0 6px 22px rgba(0,119,182,0.2); border-color: #0077b6; }
    .doc-badge { display: inline-block; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em; padding: 6px 12px; border-radius: 8px; margin-bottom: 10px; }
    .doc-badge.intern { background: #1976d2; color: #fff; }
    .doc-badge.peds { background: #f9a825; color: #1a1a1a; }
    .doc-card h4 { margin: 0 0 8px; color: #023e8a; font-size: 1.1rem; line-height: 1.3; }
    .doc-hours { font-size: 0.88rem; color: #444; white-space: pre-line; line-height: 1.45; margin: 0 0 10px; }
    .doc-status { font-size: 0.82rem; font-weight: 600; margin-top: 8px; }
    .doc-status.ok { color: #1b5e20; }
    .doc-status.no { color: #c62828; }
    .doc-pick { margin-top: 10px; display: flex; align-items: center; gap: 10px; }
    .doc-pick input[type=radio] { width: 20px; height: 20px; accent-color: #0077b6; }
';

include 'includes/header.php';

$stepLabels = [
    1 => 'Service type',
    2 => 'Choose service',
    3 => 'View details',
    4 => 'Schedule & doctor',
    5 => 'Confirm',
];
?>

<div class="container">
    <div class="booking-container<?php echo ($step === 2 || $step === 5 || ($step === 4 && ($bk['type'] ?? '') === 'individual')) ? ' book-wide' : ''; ?>">
        <a href="patients.php" class="back-link">Back to Dashboard</a>

        <?php if ($bookedId > 0): ?>
            <div class="success-banner">
                <strong>Appointment request successfully submitted.</strong><br>
                Reference #<?php echo $bookedId; ?>.
                <?php if ($appointmentEmailWarning === ''): ?>
                    We sent the booking details to your verified email.
                <?php else: ?>
                    Your booking is saved and visible in My Appointments.
                <?php endif; ?>
                The clinic will review and confirm your request.
            </div>
            <?php if ($appointmentEmailWarning !== ''): ?>
                <div class="error-message"><?php echo htmlspecialchars($appointmentEmailWarning); ?></div>
            <?php endif; ?>
            <a href="view_appointments.php" class="btn-primary" style="display:inline-block;width:auto;padding:12px 24px;text-decoration:none;text-align:center;">View my appointments</a>
            <a href="book_appointment.php" class="btn-secondary" style="margin-top:12px;">Book another</a>
        <?php else: ?>

        <div class="booking-header">
            <h2>Book an appointment</h2>
            <p>Follow the steps below. Payment is made at the clinic after staff confirms your request.</p>
        </div>


        <div class="clinic-reminder-grid" aria-label="Clinic appointment reminders">
            <div class="clinic-reminder"><strong>Clinic visit</strong><span>Arrive 10-15 minutes early for verification.</span></div>
            <div class="clinic-reminder"><strong>Payment</strong><span>Pay at the clinic after staff confirms your request.</span></div>
            <div class="clinic-reminder"><strong>Bring documents</strong><span>Bring a valid ID and any request form, if needed.</span></div>
            <div class="clinic-reminder"><strong>Email verification</strong><span>Enter the code sent to your email before the request is saved.</span></div>
        </div>
        <div class="stepper">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="<?php echo $i === $step ? 'on' : ''; ?>"><?php echo $i; ?>. <?php echo htmlspecialchars($stepLabels[$i]); ?></span>
            <?php endfor; ?>
        </div>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <div class="info-box">
                <strong>Step 1.</strong> Choose what you want to book. Use <strong>Package deals</strong> for clinic packages such as OPD pre-employment, sanitary permit, and CVSU. Use <strong>Individual laboratory tests</strong> for single tests and other lab categories.
            </div>
            <form method="post" action="book_appointment.php">
                <input type="hidden" name="booking_action" value="select_type">
                <div class="choice-grid">
                    <button type="submit" name="booking_type" value="package" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Package deals</h3>
                        <p>Best for OPD pre-employment, sanitary permit, and CVSU packages. Home service is not available for packages.</p>
                    </button>
                    <button type="submit" name="booking_type" value="individual" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Individual laboratory tests</h3>
                        <p>Choose one or more lab tests. If needed, you will select a schedule and an available doctor later.</p>
                    </button>
                </div>
            </form>

        <?php elseif ($step === 2): ?>
            <div class="info-box">
                <strong>Step 2.</strong>
                <?php echo $bk['type'] === 'package' ? 'Choose one package. Package bookings are OPD only and do not include home service.' : 'Choose one or more individual tests. Use search or category filter to find services faster.'; ?>
            </div>
            <form method="post" action="book_appointment.php" id="formChooseServices">
                <input type="hidden" name="booking_action" value="choose_services">
                <?php if ($bk['type'] === 'package'): ?>
                    <?php if (empty($groupedPackages)): ?>
                        <p>No packages are available yet. Please contact the clinic staff.</p>
                    <?php else: ?>
                        <div class="svc-toolbar">
                            <div>
                                <label for="pkgSearch">Search</label>
                                <input type="search" id="pkgSearch" placeholder="Search package..." autocomplete="off">
                            </div>
                            <div>
                                <label for="pkgCatFilter">Category</label>
                                <select id="pkgCatFilter">
                                    <option value="">All categories</option>
                                    <?php foreach (array_keys($groupedPackages) as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="svc-summary" id="pkgSummary" aria-live="polite">
                            <strong>Summary:</strong> <span id="pkgSummaryText">No package selected</span>
                        </div>
                        <div class="service-list" id="pkgListWrap">
                            <?php foreach ($groupedPackages as $catName => $svcs): ?>
                                <div class="cat-block" data-category="<?php echo htmlspecialchars($catName); ?>">
                                    <h3 class="cat-heading"><?php echo htmlspecialchars($catName); ?></h3>
                                    <?php foreach ($svcs as $svc):
                                        $sid = (int) $svc['id'];
                                        $needle = strtolower($svc['name'] . ' ' . $catName . ' ' . ($svc['included_tests'] ?? ''));
                                        ?>
                                        <div class="service-row pkg-row" data-search="<?php echo htmlspecialchars($needle, ENT_QUOTES, 'UTF-8'); ?>" data-cat="<?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="radio" name="package_id" value="<?php echo $sid; ?>" id="pkg<?php echo $sid; ?>" class="pkg-radio"
                                                data-name="<?php echo htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-opd="<?php echo (float) $svc['opd_price']; ?>"
                                                <?php echo (count($bk['service_ids']) === 1 && (int) $bk['service_ids'][0] === $sid) ? 'checked' : ''; ?>>
                                            <label for="pkg<?php echo $sid; ?>">
                                                <strong><?php echo htmlspecialchars($svc['name']); ?></strong>
                                                <?php if (!empty($svc['included_tests'])): ?>
                                                    <br><small style="color:#666;"><?php echo htmlspecialchars($svc['included_tests']); ?></small>
                                                <?php endif; ?>
                                            </label>
                                            <span class="price-tag">PHP <?php echo number_format((float) $svc['opd_price'], 0); ?> OPD</span>
                                            <button type="button" class="btn-add-svc pkg-add" data-target="pkg<?php echo $sid; ?>">Add</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (empty($groupedIndividual)): ?>
                        <p>No individual tests are available yet. Please contact the clinic staff.</p>
                    <?php else: ?>
                        <div class="svc-toolbar">
                            <div>
                                <label for="indSearch">Search</label>
                                <input type="search" id="indSearch" placeholder="Search test or category..." autocomplete="off">
                            </div>
                            <div>
                                <label for="indCatFilter">Category</label>
                                <select id="indCatFilter">
                                    <option value="">All categories</option>
                                    <?php foreach (array_keys($groupedIndividual) as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="svc-summary" id="indSummary" aria-live="polite">
                            <strong>Summary:</strong> <span id="indCount">0</span> test(s) | OPD subtotal <span id="indSubOpd">PHP 0</span> | Home subtotal <span id="indSubHome">PHP 0</span>
                        </div>
                        <div class="service-list" id="indListWrap">
                            <?php foreach ($groupedIndividual as $catName => $svcs): ?>
                                <div class="cat-block" data-category="<?php echo htmlspecialchars($catName); ?>">
                                    <h3 class="cat-heading"><?php echo htmlspecialchars($catName); ?></h3>
                                    <?php foreach ($svcs as $svc):
                                        $sid = (int) $svc['id'];
                                        $home = $svc['home_service_price'];
                                        $homeNum = ($home !== null && $home !== '') ? (float) $home : '';
                                        $needle = strtolower($svc['name'] . ' ' . $catName);
                                        ?>
                                        <div class="service-row ind-row" data-search="<?php echo htmlspecialchars($needle, ENT_QUOTES, 'UTF-8'); ?>" data-cat="<?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="checkbox" name="service_ids[]" value="<?php echo $sid; ?>" id="t<?php echo $sid; ?>" class="ind-cb"
                                                data-opd="<?php echo (float) $svc['opd_price']; ?>"
                                                data-home="<?php echo $homeNum !== '' ? htmlspecialchars((string) $homeNum, ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                <?php echo in_array($sid, array_map('intval', $bk['service_ids']), true) ? 'checked' : ''; ?>>
                                            <label for="t<?php echo $sid; ?>">
                                                <strong><?php echo htmlspecialchars($svc['name']); ?></strong>
                                            </label>
                                            <div class="price-cols">
                                                <span>OPD PHP <?php echo number_format((float) $svc['opd_price'], 0); ?></span>
                                                <span><?php echo $homeNum !== '' ? 'Home PHP ' . number_format($homeNum, 0) : 'Home N/A'; ?></span>
                                            </div>
                                            <button type="button" class="btn-add-svc ind-add" data-target="t<?php echo $sid; ?>">Add</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="submit" class="btn-primary">Next: view details</button>
            </form>
            <script>
            (function() {
                function norm(s) { return (s || '').toLowerCase().trim(); }
                function filterRows(searchEl, catEl, rowSel, catBlockSel) {
                    var q = norm(searchEl && searchEl.value);
                    var cat = catEl && catEl.value;
                    document.querySelectorAll(rowSel).forEach(function(row) {
                        var ok = true;
                        if (cat && row.getAttribute('data-cat') !== cat) ok = false;
                        if (ok && q && row.getAttribute('data-search').indexOf(q) === -1) ok = false;
                        row.classList.toggle('hidden', !ok);
                    });
                    document.querySelectorAll(catBlockSel).forEach(function(block) {
                        var vis = !!block.querySelector(rowSel + ':not(.hidden)');
                        block.style.display = vis ? '' : 'none';
                    });
                }
                var pkgSearch = document.getElementById('pkgSearch');
                var pkgCat = document.getElementById('pkgCatFilter');
                if (pkgSearch && pkgCat) {
                    function updPkgSummary() {
                        var r = document.querySelector('.pkg-radio:checked');
                        var el = document.getElementById('pkgSummaryText');
                        if (!el) return;
                        if (!r) { el.textContent = 'No package selected'; return; }
                        var opd = r.getAttribute('data-opd');
                        el.textContent = r.getAttribute('data-name') + ' - PHP ' + Number(opd).toLocaleString() + ' OPD';
                    }
                    pkgSearch.addEventListener('input', function() { filterRows(pkgSearch, pkgCat, '.pkg-row', '.cat-block'); });
                    pkgCat.addEventListener('change', function() { filterRows(pkgSearch, pkgCat, '.pkg-row', '.cat-block'); });
                    document.querySelectorAll('.pkg-add').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var id = btn.getAttribute('data-target');
                            var inp = document.getElementById(id);
                            if (inp) { inp.checked = true; updPkgSummary(); }
                        });
                    });
                    document.querySelectorAll('.pkg-radio').forEach(function(r) { r.addEventListener('change', updPkgSummary); });
                    updPkgSummary();
                }
                var indSearch = document.getElementById('indSearch');
                var indCat = document.getElementById('indCatFilter');
                if (indSearch && indCat) {
                    function indTotals() {
                        var opd = 0, home = 0, n = 0;
                        document.querySelectorAll('.ind-cb:checked').forEach(function(cb) {
                            n++;
                            opd += parseFloat(cb.getAttribute('data-opd')) || 0;
                            var h = cb.getAttribute('data-home');
                            if (h !== '') home += parseFloat(h) || 0;
                        });
                        var c = document.getElementById('indCount');
                        var o = document.getElementById('indSubOpd');
                        var hEl = document.getElementById('indSubHome');
                        if (c) c.textContent = n;
                        if (o) o.textContent = 'PHP ' + opd.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                        if (hEl) hEl.textContent = 'PHP ' + home.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                    }
                    indSearch.addEventListener('input', function() { filterRows(indSearch, indCat, '.ind-row', '.cat-block'); });
                    indCat.addEventListener('change', function() { filterRows(indSearch, indCat, '.ind-row', '.cat-block'); });
                    document.querySelectorAll('.ind-add').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var id = btn.getAttribute('data-target');
                            var inp = document.getElementById(id);
                            if (inp) { inp.checked = !inp.checked; indTotals(); }
                        });
                    });
                    document.querySelectorAll('.ind-cb').forEach(function(cb) { cb.addEventListener('change', indTotals); });
                    indTotals();
                }
            })();
            </script>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>
            <a href="book_appointment.php?reset=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Start over</a>

        <?php elseif ($step === 3): ?>
            <div class="info-box">
                <strong>Step 3.</strong> Review the service details. Prices shown are estimates only. Final payment is made at the clinic.
                <?php if (($bk['type'] ?? '') === 'package'): ?>
                    <strong>Package deals:</strong> OPD only. Home service is not available for packages.
                <?php else: ?>
                    Choose whether to show OPD or home service pricing, if available.
                <?php endif; ?>
            </div>
            <?php foreach ($selectedServices as $svc): ?>
                <div class="detail-block">
                    <h4><?php echo htmlspecialchars($svc['name']); ?></h4>
                    <p><?php echo nl2br(htmlspecialchars($svc['description'] ?? '')); ?></p>
                    <?php if (!empty($svc['included_tests'])): ?>
                        <p><strong>Included tests:</strong> <?php echo htmlspecialchars($svc['included_tests']); ?></p>
                    <?php endif; ?>
                    <p class="price-tag">OPD: PHP <?php echo number_format((float) $svc['opd_price'], 2); ?>
                        <?php if (!empty($svc['home_service_price'])): ?>
                            &nbsp;|&nbsp; Home service: PHP <?php echo number_format((float) $svc['home_service_price'], 2); ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>

            <form method="post" action="book_appointment.php">
                <input type="hidden" name="booking_action" value="set_channel">
                <?php if (($bk['type'] ?? '') !== 'package'): ?>
                <div class="form-group">
                    <span style="font-weight:600;">Price to show in your summary</span>
                    <div class="channel-opt">
                        <label><input type="radio" name="price_channel" value="opd" <?php echo $bk['price_channel'] !== 'home' ? 'checked' : ''; ?>> OPD (clinic)</label>
                        <label><input type="radio" name="price_channel" value="home" <?php echo $bk['price_channel'] === 'home' ? 'checked' : ''; ?>> Home service (if priced)</label>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="price_channel" value="opd">
                <?php endif; ?>
                <p style="font-size:1.1rem;margin:16px 0;"><strong>Estimated total:</strong> <span class="price-tag">PHP <?php echo number_format($displayTotal, 2); ?></span></p>
                <button type="submit" class="btn-primary">Next: choose date and time</button>
            </form>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>

        <?php elseif ($step === 4): ?>
            <div class="info-box">
                <strong>Step 4 - Schedule<?php echo ($bk['type'] ?? '') === 'individual' ? ' &amp; doctor' : ''; ?>.</strong>
                <?php if (($bk['type'] ?? '') === 'individual'): ?>
                    Choose your visit date and time, then select an available doctor.
                <?php else: ?>
                    Choose your preferred visit date and time.
                <?php endif; ?>
            </div>
            <form method="post" action="book_appointment.php" id="scheduleForm">
                <input type="hidden" name="booking_action" id="booking_action_field" value="set_schedule">
                <div class="form-row">
                    <div class="form-group">
                        <label for="appointment_date">Date</label>
                        <input type="date" name="appointment_date" id="appointment_date" required min="<?php echo date('Y-m-d'); ?>"
                            value="<?php echo htmlspecialchars($bk['appointment_date']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="appointment_time">Time</label>
                        <input type="time" name="appointment_time" id="appointment_time" required min="08:00" max="17:00"
                            value="<?php echo htmlspecialchars($bk['appointment_time']); ?>">
                    </div>
                </div>
                <?php if (($bk['type'] ?? '') === 'individual'): ?>
                    <p style="margin:0 0 12px;color:#555;font-size:0.92rem;">If you change the date or time, the doctor list will refresh automatically. Choose an available doctor, then click Next.</p>
                <?php endif; ?>
                <?php if (($bk['type'] ?? '') === 'individual'): ?>
                    <div class="form-group" style="margin-top:14px;">
                        <strong style="display:block;margin-bottom:10px;color:#023e8a;">Choose a doctor</strong>
                        <?php if (empty($doctorBookingCards)): ?>
                            <p class="error-message" style="margin-top:8px;">No doctors are available in the system yet. Please contact the clinic staff.</p>
                        <?php else: ?>
                            <div class="doc-grid" id="docGrid">
                                <?php
                                $firstRadioReq = $nBookableDoctors > 0;
                                foreach ($doctorBookingCards as $doc):
                                    $dc = $doc['theme']['class'] === 'theme-peds' ? 'theme-peds' : 'theme-intern';
                                    $bd = $doc['theme']['class'] === 'theme-peds' ? 'peds' : 'intern';
                                    $dis = !$doc['can_book'];
                                    $sel = (int) ($bk['doctor_id'] ?? 0) === (int) $doc['id'];
                                    $radioChecked = $sel || ($nBookableDoctors === 1 && !empty($doc['can_book']) && empty($bk['doctor_id']));
                                    ?>
                                    <div class="doc-card <?php echo htmlspecialchars($dc); ?><?php echo $dis ? ' doc-card-disabled' : ''; ?>">
                                        <span class="doc-badge <?php echo htmlspecialchars($bd); ?>"><?php echo htmlspecialchars($doc['theme']['label']); ?></span>
                                        <h4><?php echo htmlspecialchars($doc['full_name']); ?></h4>
                                        <p class="doc-hours"><?php echo nl2br(htmlspecialchars($doc['clinic_hours'])); ?></p>
                                        <?php if ($doc['can_book']): ?>
                                            <p class="doc-status ok">Available for your selected schedule</p>
                                            <div class="doc-pick">
                                                <?php
                                                $req = $firstRadioReq ? ' required' : '';
                                                $firstRadioReq = false;
                                                ?>
                                                <input type="radio" name="doctor_id" value="<?php echo (int) $doc['id']; ?>" id="doc<?php echo (int) $doc['id']; ?>" class="doc-radio"<?php echo $req; ?> <?php echo $radioChecked ? 'checked' : ''; ?>>
                                                <label for="doc<?php echo (int) $doc['id']; ?>" style="cursor:pointer;font-weight:600;color:#0077b6;">Choose this doctor</label>
                                            </div>
                                        <?php else: ?>
                                            <p class="doc-status no"><?php echo htmlspecialchars($doc['unavailable_reason']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <script>
                            (function() {
                                var grid = document.getElementById('docGrid');
                                if (!grid) return;
                                function updSel() {
                                    grid.querySelectorAll('.doc-card').forEach(function(c) {
                                        c.classList.remove('doc-selected');
                                        var inp = c.querySelector('.doc-radio');
                                        if (inp && inp.checked) c.classList.add('doc-selected');
                                    });
                                }
                                grid.querySelectorAll('.doc-radio').forEach(function(r) {
                                    r.addEventListener('change', updSel);
                                });
                                updSel();
                            })();
                            </script>
                            <?php if ($nBookableDoctors === 0 && trim((string) ($bk['appointment_date'] ?? '')) !== '' && trim((string) ($bk['appointment_time'] ?? '')) !== ''): ?>
                                <p class="error-message" style="margin-top:12px;">No doctor is available for the selected time. Please change the date or time above.</p>
                            <?php elseif ($nBookableDoctors === 0): ?>
                                <p class="error-message" style="margin-top:12px;">Choose a date and time first so we can show available doctors.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn-primary" id="btnScheduleNext">Next: review and confirm</button>
            </form>
            <script>
            (function() {
                var form = document.getElementById('scheduleForm');
                var actionField = document.getElementById('booking_action_field');
                var dateInp = document.getElementById('appointment_date');
                var timeInp = document.getElementById('appointment_time');
                var btnNext = document.getElementById('btnScheduleNext');
                if (!form || !actionField || !dateInp || !timeInp) return;

                var refreshTimer = null;
                function refreshDoctorList() {
                    if (!dateInp.value || !timeInp.value) return;
                    actionField.value = 'refresh_schedule';
                    form.submit();
                }
                function queueRefresh() {
                    if (refreshTimer) clearTimeout(refreshTimer);
                    refreshTimer = setTimeout(refreshDoctorList, 400);
                }
                dateInp.addEventListener('change', queueRefresh);
                timeInp.addEventListener('change', queueRefresh);

                if (btnNext) {
                    btnNext.addEventListener('click', function() {
                        actionField.value = 'set_schedule';
                    });
                }
                form.addEventListener('submit', function() {
                    if (actionField.value !== 'refresh_schedule') {
                        actionField.value = 'set_schedule';
                    }
                });
            })();
            </script>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>

        <?php elseif ($step === 5): ?>
            <div class="info-box">
                <strong>Step 5.</strong> Review your appointment summary.
                <?php if (($bk['type'] ?? '') === 'individual'): ?>
                    Your doctor was selected in the schedule step.
                <?php else: ?>
                    Click Confirm when everything looks correct.
                <?php endif; ?>
            </div>
            <table class="summary-table">
                <tr><td>Service type</td><td><?php echo $bk['type'] === 'package' ? 'Package' : 'Individual'; ?></td></tr>
                <tr><td>Channel</td><td><?php echo ($bk['type'] === 'package') ? 'OPD (package only)' : ($bk['price_channel'] === 'home' ? 'Home service' : 'OPD'); ?></td></tr>
                <tr><td>Date</td><td><?php echo htmlspecialchars($bk['appointment_date']); ?></td></tr>
                <tr><td>Time</td><td><?php echo htmlspecialchars($bk['appointment_time']); ?></td></tr>
                <?php if (($bk['type'] ?? '') === 'individual' && $selectedDoctorName !== ''): ?>
                    <tr><td>Doctor</td><td><?php echo htmlspecialchars($selectedDoctorName); ?></td></tr>
                <?php endif; ?>
                <tr><td><strong>Estimated total (pay at clinic)</strong></td><td><strong>PHP <?php echo number_format($displayTotal, 2); ?></strong></td></tr>
            </table>
            <h4 style="margin:16px 0 8px;color:#023e8a;">Selected services</h4>
            <ul style="margin:0;padding-left:20px;color:#444;">
                <?php foreach ($selectedServices as $svc): ?>
                    <li><?php echo htmlspecialchars($svc['name']); ?> - PHP <?php echo number_format(serviceUnitPrice($svc, $bk['price_channel'] === 'home' ? 'home' : 'opd'), 2); ?></li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="book_appointment.php" style="margin-top:24px;">
                <input type="hidden" name="booking_action" value="confirm_booking">
                <label class="review-check">
                    <input type="checkbox" required>
                    <span>I reviewed the details and understand that I must verify the code sent to my email before this request is submitted.</span>
                </label>
                <button type="submit" class="btn-primary">Send confirmation code</button>
            </form>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>




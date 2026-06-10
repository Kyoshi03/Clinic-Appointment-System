<?php
require_once 'includes/session.php';
checkRole('patient');

require_once 'config/database.php';
require_once 'includes/appointment_booking.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';
require_once __DIR__ . '/includes/doctor_schedule.php';

$currentUser = getCurrentUser();

if (isset($_GET['reset']) || isset($_GET['start'])) {
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
    'calendar_ready' => false,
];
foreach ($defaults as $k => $v) {
    if (!array_key_exists($k, $bk)) {
        $bk[$k] = $v;
    }
}
$bk['price_channel'] = 'opd';

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
            $bk['calendar_ready'] = false;
        } elseif ($bk['step'] <= 3) {
            $bk['appointment_time'] = '';
        }
    }
    header('Location: book_appointment.php');
    exit;
}

$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);

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

    if ($action === 'begin_booking') {
        $bk['appointment_date'] = '';
        $bk['appointment_time'] = '';
        $bk['doctor_id'] = null;
        $bk['calendar_ready'] = true;
        $bk['step'] = 1;
        header('Location: book_appointment.php');
        exit;
    }

    if ($action === 'select_type') {
        $t = $_POST['booking_type'] ?? '';
        if (!in_array($t, ['package', 'individual', 'consultation'], true)) {
            $error = 'Please choose a service type.';
        } else {
            $preferredDate = trim($_POST['preferred_date'] ?? '');
            if ($preferredDate !== '') {
                try {
                    $pickedDate = new DateTime($preferredDate);
                    $todayDate = new DateTime();
                    $todayDate->setTime(0, 0, 0);
                    if ($pickedDate >= $todayDate) {
                        $bk['appointment_date'] = $preferredDate;
                    }
                } catch (Exception $e) {
                    $bk['appointment_date'] = '';
                }
            }
            $bk['type'] = $t;
            $bk['service_ids'] = [];
            $bk['doctor_id'] = null;
            $bk['calendar_ready'] = true;
            $bk['step'] = 2;
            header('Location: book_appointment.php');
            exit;
        }
    }

    if ($action === 'choose_services') {
        if ($bk['type'] === 'consultation') {
            $doctorId = (int) ($_POST['doctor_id'] ?? 0);
            if ($doctorId <= 0 || !doctor_user_is_active($conn, $doctorId)) {
                $error = 'Please choose an available doctor.';
            } else {
                $bk['doctor_id'] = $doctorId;
                $bk['service_ids'] = [];
                $bk['step'] = 3;
                header('Location: book_appointment.php');
                exit;
            }
        } elseif ($bk['type'] === 'package') {
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
        $bk['price_channel'] = 'opd';
        if ($bk['type'] !== 'consultation') {
            $bk['doctor_id'] = null;
        }
        if ($bk['type'] !== 'consultation' && empty($bk['service_ids'])) {
            $bk['step'] = 2;
            header('Location: book_appointment.php');
            exit;
        }
        if ($bk['type'] === 'consultation' && empty($bk['doctor_id'])) {
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
        if ($bk['type'] !== 'consultation') {
            $bk['doctor_id'] = null;
        }
        $bk['step'] = 4;
        header('Location: book_appointment.php');
        exit;
    }

    if ($action === 'set_schedule') {
        $d = trim($_POST['appointment_date'] ?? '');
        $t = trim($_POST['appointment_time'] ?? '');
        $bk['step'] = 4;
        if ($d !== '') {
            $bk['appointment_date'] = $d;
        }
        if ($t !== '') {
            $bk['appointment_time'] = $t;
        }
        if ($d === '' || $t === '') {
            $error = 'Please choose date and time.';
            if ($bk['type'] !== 'consultation') {
                $bk['doctor_id'] = null;
            }
        } else {
            $selected_date = new DateTime($d);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            if ($selected_date < $today) {
                $error = 'Appointment date cannot be in the past.';
                if ($bk['type'] !== 'consultation') {
                    $bk['doctor_id'] = null;
                }
            } elseif (!appointment_clinic_is_open_at($d, $t)) {
                $error = 'The clinic is open Monday to Saturday, from 8:00 AM to 5:00 PM. Please choose a schedule within clinic hours.';
            } else {
                if (
                    $bk['type'] === 'consultation'
                    && !user_is_doctor_available_at($conn, (int) $bk['doctor_id'], $d, $t)
                ) {
                    $error = 'Choose a date and time within the selected doctor\'s clinic schedule.';
                } elseif ($bk['type'] !== 'consultation') {
                    $bk['doctor_id'] = null;
                }
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
        $verificationChannel = strtolower(trim((string) ($_POST['verification_channel'] ?? 'email')));
        if (!in_array($verificationChannel, ['email', 'sms'], true)) {
            $verificationChannel = 'email';
        }
        $hasBookingSelection = $bk['type'] === 'consultation'
            ? !empty($bk['doctor_id'])
            : !empty($bk['service_ids']);
        if ($bk['step'] < 5 || !$hasBookingSelection || $bk['type'] === null) {
            $bk['step'] = 1;
            header('Location: book_appointment.php');
            exit;
        }
        if ($bk['type'] !== 'consultation') {
            $bk['doctor_id'] = null;
        }

        if ($error === '') {
            $verification = appointment_issue_verification(
                $conn,
                (int) $currentUser['id'],
                [
                    'type' => $bk['type'],
                    'service_ids' => $bk['service_ids'],
                    'doctor_id' => $bk['type'] === 'consultation' ? (int) $bk['doctor_id'] : null,
                    'price_channel' => $bk['price_channel'],
                    'appointment_date' => $bk['appointment_date'],
                    'appointment_time' => $bk['appointment_time'],
                ],
                $verificationChannel
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
if ($step >= 3 && $bk['type'] !== 'consultation' && empty($bk['service_ids'])) {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 3 && $bk['type'] === 'consultation' && empty($bk['doctor_id'])) {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 4 && $bk['type'] !== 'consultation' && empty($bk['service_ids'])) {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 5 && ($bk['appointment_date'] === '' || $bk['appointment_time'] === '')) {
    $step = 4;
    $bk['step'] = 4;
}
if ($step === 1 && empty($bk['calendar_ready'])) {
    $bk['appointment_date'] = '';
    $bk['appointment_time'] = '';
    $bk['doctor_id'] = null;
}

$packageServices = [];
$individualServices = [];
$bookingDoctors = [];
if ($bk['type'] === 'package') {
    $packageServices = lab_order_package_services(fetchLabBookingPackages($conn));
}
if ($bk['type'] === 'individual') {
    $individualServices = fetchLabBookingIndividuals($conn);
}
if ($bk['type'] === 'consultation') {
    $bookingDoctors = array_values(array_filter(
        fetch_doctors_schedule_reference($conn),
        static fn (array $doctor): bool => (int) ($doctor['is_active'] ?? 0) === 1
    ));
}

$groupedPackages = !empty($packageServices) ? lab_group_services_list($packageServices) : [];
$groupedIndividual = !empty($individualServices) ? lab_group_services_list($individualServices) : [];

$selectedServices = [];
$displayTotal = 0;
if ($bk['type'] !== 'consultation' && !empty($bk['service_ids']) && ($bk['type'] ?? '') !== '') {
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

$calendarSelected = trim((string) ($bk['appointment_date'] ?? ''));
$calendarRequestedMonth = trim($_GET['calendar_month'] ?? '');
$calendarBaseDate = preg_match('/^\d{4}-\d{2}$/', $calendarRequestedMonth) ? ($calendarRequestedMonth . '-01') : ($calendarSelected !== '' ? $calendarSelected : date('Y-m-d'));
try {
    $calendarBase = new DateTime($calendarBaseDate);
} catch (Exception $e) {
    $calendarBase = new DateTime();
}
$calendarMonthStart = (clone $calendarBase)->modify('first day of this month');
$calendarMonthLabel = $calendarMonthStart->format('F Y');
$calendarFirstWeekday = (int) $calendarMonthStart->format('w');
$calendarDaysInMonth = (int) $calendarMonthStart->format('t');
$calendarToday = date('Y-m-d');
$calendarMonthEnd = (clone $calendarMonthStart)->modify('first day of next month')->format('Y-m-d');
$calendarPrevMonth = (clone $calendarMonthStart)->modify('-1 month')->format('Y-m');
$calendarNextMonth = (clone $calendarMonthStart)->modify('+1 month')->format('Y-m');
$calendarDayCounts = [];
$calendarCountStmt = $conn->prepare("SELECT appointment_date, COUNT(*) AS total FROM appointments WHERE appointment_date >= ? AND appointment_date < ? AND status <> 'cancelled' GROUP BY appointment_date");
$calendarStartValue = $calendarMonthStart->format('Y-m-d');
$calendarCountStmt->bind_param('ss', $calendarStartValue, $calendarMonthEnd);
$calendarCountStmt->execute();
$calendarRows = $calendarCountStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$calendarCountStmt->close();
foreach ($calendarRows as $calendarRow) {
    $calendarDayCounts[(string) $calendarRow['appointment_date']] = (int) $calendarRow['total'];
}
$calendarDoctorSlotsByDow = [];
for ($dow = 1; $dow <= 7; $dow++) {
    $calendarDoctorSlotsByDow[$dow] = [];
}
$selectedDoctor = null;
if ($bk['type'] === 'consultation' && !empty($bk['doctor_id'])) {
    $selectedDoctorStmt = $conn->prepare(
        "SELECT id, full_name, specialty FROM users
         WHERE id = ? AND role = 'doctor' AND COALESCE(is_active, 1) = 1 LIMIT 1"
    );
    $selectedDoctorId = (int) $bk['doctor_id'];
    $selectedDoctorStmt->bind_param('i', $selectedDoctorId);
    $selectedDoctorStmt->execute();
    $selectedDoctor = $selectedDoctorStmt->get_result()->fetch_assoc() ?: null;
    $selectedDoctorStmt->close();
    if ($selectedDoctor) {
        $selectedDoctor['clinic_hours'] = doctor_format_clinic_hours_lines(
            doctor_fetch_availability_slots($conn, (int) $selectedDoctor['id'])
        );
    }
}
$doctorSlotSql = "SELECT u.id, u.full_name, u.specialty, da.day_of_week, da.time_start, da.time_end
    FROM doctor_availability da
    INNER JOIN users u ON u.id = da.user_id
    WHERE u.role = 'doctor' AND COALESCE(u.is_active, 1) = 1
      AND da.time_start < da.time_end";
if ($selectedDoctor) {
    $doctorSlotSql .= ' AND u.id = ' . (int) $selectedDoctor['id'];
}
$doctorSlotSql .= ' ORDER BY da.day_of_week, da.time_start, u.full_name';
$doctorSlotResult = $conn->query($doctorSlotSql);
if ($doctorSlotResult) {
    while ($slot = $doctorSlotResult->fetch_assoc()) {
        $dow = (int) ($slot['day_of_week'] ?? 0);
        if ($dow < 1 || $dow > 7) {
            continue;
        }
        $calendarDoctorSlotsByDow[$dow][] = [
            'id' => (int) $slot['id'],
            'doctor' => (string) $slot['full_name'],
            'specialty' => (string) ($slot['specialty'] ?? ''),
            'start' => substr((string) $slot['time_start'], 0, 5),
            'end' => substr((string) $slot['time_end'], 0, 5),
            'time' => doctor_format_time_hm((string) $slot['time_start']) . ' - ' . doctor_format_time_hm((string) $slot['time_end']),
        ];
    }
}
$conn->close();

$pageTitle = "Book Appointment | Globalife Medical Laboratory & Polyclinic";
$additionalStyles = '
    body { background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%); min-height: 100vh; }
    .container { max-width: 1320px; }
    .booking-container { max-width: 720px; margin: 40px auto; padding: 40px; background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
    .booking-container.book-wide { max-width: 1240px; }
    .booking-header { text-align: center; margin-bottom: 28px; }
    .booking-header h2 { color: #0077b6; font-size: 2rem; margin-bottom: 8px; }
    .stepper { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-bottom: 28px; font-size: 0.8rem; color: #555; }
    .stepper span { padding: 6px 12px; border-radius: 20px; background: #f0f0f0; }
    .stepper span.on { background: #0077b6; color: #fff; font-weight: 600; }
    .choice-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 20px; }
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
    .consultation-fee-note { white-space: normal; overflow-wrap: anywhere; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 1rem; box-sizing: border-box; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
    .schedule-card { border: 1px solid #d8e8f2; border-radius: 18px; background: #f8fcff; padding: 18px; margin-bottom: 16px; box-shadow: 0 10px 24px rgba(20,79,123,.06); }
    .schedule-layout { display: grid; grid-template-columns: minmax(280px, 1.15fr) minmax(240px, .85fr); gap: 18px; align-items: start; }
    .schedule-controls { display: grid; gap: 14px; }
    .schedule-note { margin: 0; color: #4f6776; line-height: 1.5; font-size: .95rem; }
    .booking-calendar-panel { border: 1px solid #d8e8f2; border-radius: 18px; background: #fff; padding: 18px; margin: 0 0 18px; box-shadow: 0 10px 24px rgba(20,79,123,.06); }
    .booking-calendar-panel .schedule-layout { grid-template-columns: 1fr; }
    .calendar-actions { display: flex; justify-content: flex-end; align-items: center; gap: 14px; margin-top: 14px; }
    .calendar-actions .btn-primary { width: auto; min-width: 230px; margin-top: 0; padding: 13px 28px; }
    .calendar-actions .btn-primary:disabled { opacity: .48; cursor: not-allowed; background: #b8cad8; box-shadow: none; }
    .selected-date-card { display: grid; gap: 6px; padding: 16px; border: 1px solid #cfe3f0; border-radius: 14px; background: #fff; }
    .selected-date-card span { color: #60727d; font-size: .78rem; font-weight: 800; text-transform: uppercase; }
    .selected-date-card strong { color: #073b4c; font-size: 1.15rem; }
    .selected-date-card.is-invalid { border-color:#c9303e; box-shadow:0 0 0 3px rgba(201,48,62,.1); }
    .time-picker-field { display:grid; gap:7px; }
    .time-picker-field > label { color:#263f4d; font-size:.92rem; font-weight:800; }
    .time-picker-trigger { width:100%; min-height:58px; display:flex; align-items:center; justify-content:space-between; gap:14px; padding:11px 14px; border:1px solid #bcd8e8; border-radius:12px; background:#fff; color:#073b4c; font:inherit; text-align:left; cursor:pointer; box-shadow:0 5px 14px rgba(20,79,123,.05); }
    .time-picker-trigger:hover { border-color:#69add0; background:#fbfeff; }
    .time-picker-trigger:focus-visible { outline:3px solid rgba(15,124,194,.18); border-color:#0f7cc2; }
    .time-picker-trigger.is-invalid { border-color:#c9303e; box-shadow:0 0 0 3px rgba(201,48,62,.12); }
    .time-picker-copy { display:grid; gap:2px; min-width:0; }
    .time-picker-copy strong { color:#073b4c; font-size:1.02rem; }
    .time-picker-copy small { color:#607786; font-size:.78rem; line-height:1.35; }
    .time-picker-clock { display:grid; place-items:center; flex:0 0 auto; width:34px; height:34px; border-radius:50%; background:#e8f5fc; color:#0878b5; font-size:1.05rem; font-weight:900; }
    .time-picker-trigger:disabled { cursor:not-allowed; background:#f2f5f7; border-color:#d9e1e6; color:#87959e; box-shadow:none; }
    .time-picker-trigger:disabled .time-picker-clock { background:#e4e9ec; color:#87959e; }
    .time-picker-overlay { position:fixed; inset:0; z-index:5200; display:grid; place-items:center; padding:18px; background:rgba(4,35,52,.58); }
    .time-picker-overlay[hidden] { display:none; }
    .time-picker-dialog { width:min(430px, calc(100vw - 32px)); border:1px solid #c9dfea; border-radius:16px; background:#fff; box-shadow:0 26px 70px rgba(4,35,52,.28); overflow:hidden; }
    .time-picker-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; padding:20px 20px 14px; border-bottom:1px solid #e0ebf1; }
    .time-picker-head h3 { margin:0 0 5px; color:#073b4c; font-size:1.2rem; }
    .time-picker-head p { margin:0; color:#607786; font-size:.84rem; line-height:1.45; }
    .time-picker-close { display:grid; place-items:center; flex:0 0 36px; width:36px; height:36px; padding:0; border:0; border-radius:8px; background:#edf6fc; color:#075f91; font:inherit; font-size:1.35rem; cursor:pointer; }
    .time-entry { display:grid; grid-template-columns:minmax(0, 1fr) 18px minmax(0, 1fr) 78px; align-items:start; gap:10px; padding:22px 20px 16px; }
    .time-entry-part { display:grid; gap:7px; }
    .time-entry-part input, .time-entry-part select { width:100%; height:76px; box-sizing:border-box; border:2px solid #bcd8e8; border-radius:10px; background:#fff; color:#073b4c; font:inherit; font-size:2rem; font-weight:700; text-align:center; }
    .time-entry-part select { padding:0 8px; }
    .time-entry-part label { color:#607786; font-size:.78rem; font-weight:800; text-align:center; text-transform:uppercase; }
    .time-colon { padding-top:18px; color:#073b4c; font-size:2rem; font-weight:900; text-align:center; }
    .time-period { display:grid; overflow:hidden; border:2px solid #bcd8e8; border-radius:10px; }
    .time-period button { height:38px; border:0; background:#fff; color:#506a79; font:inherit; font-weight:900; cursor:pointer; }
    .time-period button + button { border-top:1px solid #cfe0e9; }
    .time-period button.is-active { background:#dff2fb; color:#066c9f; }
    .time-picker-error { min-height:22px; margin:0; padding:0 20px; color:#b42332; font-size:.84rem; font-weight:700; }
    .time-picker-actions { display:flex; justify-content:flex-end; gap:10px; padding:14px 20px 20px; }
    .time-picker-actions button { min-height:42px; padding:9px 18px; border-radius:9px; font:inherit; font-weight:800; cursor:pointer; }
    .time-picker-cancel { border:1px solid #c8dce7; background:#fff; color:#315469; }
    .time-picker-apply { border:0; background:#0f7cc2; color:#fff; }
    .mini-calendar { border: 1px solid #d8e8f2; border-radius: 16px; background: #fff; overflow: hidden; }
    .mini-calendar-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; background: #fff; color: #073b4c; border-bottom: 1px solid #d8e8f2; }
    .mini-calendar-head strong { font-size: 1.22rem; }
    .mini-calendar-head span { display: inline-flex; align-items:center; min-height:34px; padding:0 14px; border-radius:999px; background:#eaf4ff; border:1px solid #cfe3f0; color:#0b4f80; font-size:.82rem; font-weight:900; }
    .calendar-nav { display: flex; align-items: center; gap: 8px; }
    .calendar-nav a { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; border-radius: 999px; border: 1px solid #d8e8f2; color: #0b4f80; text-decoration: none; font-weight: 900; background: #fff; }
    .calendar-nav a:hover { background: #eef7ff; }
    .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0; padding: 0; background: #d8e8f2; border-top: 1px solid #d8e8f2; }
    .cal-dow { background: #f8fcff; color: #60727d; font-size: .72rem; font-weight: 900; text-align: center; text-transform: uppercase; padding: 9px 4px; }
    .cal-empty { min-height: 148px; background: #f7fafc; border-top: 1px solid #d8e8f2; }
    .cal-day { min-height: 148px; border: 0; border-top: 1px solid #d8e8f2; background: #fff; color: #073b4c; font-family: inherit; font-size: .92rem; font-weight: 800; cursor: pointer; display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 6px; padding: 9px; text-align: left; position: relative; }
    .cal-date-number { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; }
    .calendar-events { display: grid; gap: 5px; }
    .cal-day small { display: block; max-width: 100%; min-height: 20px; padding: 4px 6px; border-radius: 6px; font-weight: 800; line-height: 1.2; white-space: normal; overflow: visible; text-overflow: clip; }
    .doctor-event { background:#e8f8f4; color:#073f38; border:1px solid #a9ddd1; border-left:4px solid #159a83; box-shadow:0 2px 5px rgba(21,154,131,.08); }
    .clinic-hours-event { background:#eaf5ff; color:#174f73; border:1px solid #bfdcf2; border-left:4px solid #3188c8; pointer-events:none; box-shadow:0 2px 5px rgba(49,136,200,.07); }
    .clinic-hours-event span { display:block; }
    .clinic-hours-event .clinic-hours-label { font-size:.54rem; color:#31719c; font-weight:900; text-transform:uppercase; }
    .clinic-hours-event .clinic-hours-time { font-size:.64rem; color:#123f5c; font-weight:900; margin-top:1px; }
    .clinic-closed-event { background:#f3f5f7; color:#71808a; border:1px solid #dfe5e9; border-left:4px solid #9aa8b1; }
    .clinic-hours-summary { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:14px; padding:12px 14px; border:1px solid #cfe3f0; border-radius:12px; background:#f6fbff; }
    .clinic-hours-summary strong { color:#073b4c; font-size:.92rem; }
    .clinic-hours-summary span { color:#526b7a; font-size:.86rem; text-align:right; }
    .availability-label { display:block; color:#137e6c; font-size:.52rem; font-weight:900; letter-spacing:.02em; text-transform:uppercase; }
    .availability-doctor { display:block; color:#073f38; font-size:.67rem; font-weight:900; line-height:1.18; }
    .availability-time { display:block; color:#356b61; font-size:.6rem; font-weight:800; line-height:1.18; margin-top:1px; }
    .more-event { background:#f0faf7; color:#137e6c; border:1px solid #c6e8df; }
    .cal-day.is-clinic-open { background:#fbfdff; }
    .cal-day.has-doctor { background:#f6fcfa; box-shadow:inset 0 3px 0 #6dc7b3; }
    .cal-day.has-doctor .cal-date-number { color:#126e60; }
    .cal-day:hover { background: #f8fcff; outline: 2px solid #8ec7e2; outline-offset: -2px; }
    .cal-day.calendar-readonly { cursor: default; }
    .cal-day.calendar-readonly:hover { background:#fbfdff; outline:none; }
    .cal-day.calendar-readonly.has-doctor:hover { background:#f6fcfa; }
    .cal-day.calendar-readonly.is-past:hover { background: #f7fafc; }
    .cal-day.is-today .cal-date-number { background: #eaf7ff; color: #0b65a0; }
    .cal-day.is-selected { background:#eaf5ff; outline:3px solid #0f7cc2; outline-offset:-3px; box-shadow:none; }
    .cal-day.is-selected .cal-date-number { background: #0f7cc2; color: #fff; }
    .cal-day:disabled { cursor:not-allowed; color:#a4b1b8; background:#f7fafc; box-shadow:none; }
    .cal-day:disabled small { opacity: .7; }
    .cal-day.is-closed { background:#f7f8f9; }
    @media (max-width: 760px) { .booking-container { margin: 22px auto; padding: 24px 14px; } .booking-calendar-panel { padding: 12px; } .schedule-layout, .booking-calendar-panel .schedule-layout { grid-template-columns: 1fr; } .mini-calendar { overflow-x: auto; } .cal-grid { min-width: 760px; } .cal-day, .cal-empty { min-height: 132px; } .calendar-actions { align-items:stretch; flex-direction:column; position: sticky; bottom: 0; z-index: 5; padding: 10px 0 0; background: linear-gradient(180deg, rgba(255,255,255,.65), #fff 45%); } .calendar-actions .btn-primary{width:100%; min-width:0;} }
    @media (max-width: 520px) { .cal-grid { min-width: 720px; } .cal-day, .cal-empty { min-height: 124px; } .cal-day { padding: 6px; } .cal-day small { display: block; padding: 4px 5px; } .availability-label { font-size: .5rem; } .availability-doctor { font-size: .62rem; } .availability-time { font-size: .56rem; } .clinic-hours-summary { align-items:flex-start; flex-direction:column; } .clinic-hours-summary span { text-align:left; } .time-entry { grid-template-columns:minmax(0, 1fr) 14px minmax(0, 1fr) 68px; gap:7px; padding-inline:14px; } .time-entry-part input, .time-entry-part select { height:68px; font-size:1.65rem; } .time-picker-head, .time-picker-actions { padding-left:14px; padding-right:14px; } }
    .btn-primary { width: 100%; background: linear-gradient(135deg, #0077b6, #023e8a); color: #fff; padding: 14px; border: none; border-radius: 10px; font-size: 1.05rem; font-weight: 600; cursor: pointer; margin-top: 8px; }
    .btn-secondary { display: inline-block; padding: 10px 18px; border-radius: 8px; background: #e3f2fd; color: #023e8a; text-decoration: none; font-weight: 600; margin-right: 10px; border: none; cursor: pointer; font-size: 1rem; }
    .error-message { background: #fee; color: #c1121f; padding: 14px; border-radius: 10px; margin-bottom: 18px; border-left: 4px solid #c1121f; }
    .success-banner { background: #d4edda; color: #155724; padding: 20px; border-radius: 12px; margin-bottom: 22px; border-left: 4px solid #28a745; }
    .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 14px; border-radius: 8px; margin-bottom: 20px; color: #1565c0; font-size: 0.95rem; }
    .clinic-reminder-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 16px 0 22px; }
    .clinic-reminder-heading { grid-column:1 / -1; margin-bottom:2px; }
    .clinic-reminder-heading h3 { margin:0 0 4px; color:#073b4c; font-size:1.15rem; }
    .clinic-reminder-heading p { margin:0; color:#60727d; font-size:.88rem; line-height:1.45; }
    .clinic-reminder { background: #fff; border: 1px solid #dce9f4; border-radius: 10px; padding: 15px; box-shadow: 0 8px 18px rgba(20,79,123,.07); }
    .clinic-reminder-number { display:inline-grid; place-items:center; width:32px; height:32px; margin-bottom:10px; border-radius:50%; background:#e5f5fb; color:#006b9f; font-size:.9rem; font-weight:900; }
    .clinic-reminder strong { display: block; color: #0b4f80; margin-bottom: 5px; }
    .clinic-reminder span { color: #4a6072; font-size: .9rem; line-height: 1.45; }
    .review-check { display: flex; align-items: flex-start; gap: 10px; background: #f8fbff; border: 1px solid #dce9f4; border-radius: 10px; padding: 13px 14px; margin-bottom: 14px; color: #26495f; font-weight: 600; }
    .review-check input { margin-top: 3px; width: 18px; height: 18px; accent-color: #0077b6; }
    .verification-methods { margin:18px 0; padding:16px; border:1px solid #d8e8f2; border-radius:12px; background:#f8fcfe; }
    .verification-methods > strong { display:block; color:#073b4c; margin-bottom:5px; }
    .verification-methods > p { margin:0 0 12px; color:#607784; font-size:.88rem; line-height:1.45; }
    .verification-method-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .verification-method { display:flex; align-items:center; gap:10px; min-width:0; padding:12px 14px; border:2px solid #d8e8f0; border-radius:10px; background:#fff; cursor:pointer; }
    .verification-method:has(input:checked) { border-color:#0c83c3; background:#eaf7fd; box-shadow:0 0 0 3px rgba(12,131,195,.1); }
    .verification-method input { width:18px; height:18px; margin:0; accent-color:#0c83c3; }
    .verification-method span { min-width:0; color:#466171; font-size:.84rem; line-height:1.35; }
    .verification-method strong { display:block; color:#073b4c; font-size:.92rem; }
    @media (max-width: 820px) { .clinic-reminder-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 520px) { .clinic-reminder-grid, .verification-method-grid { grid-template-columns: 1fr; } }
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
    1 => 'Appointment type',
    2 => 'Choose doctor/service',
    3 => 'Review details',
    4 => 'Schedule',
    5 => 'Confirm',
];
?>

<div class="container">
    <div class="booking-container<?php echo in_array($step, [1, 2, 4, 5], true) ? ' book-wide' : ''; ?>">
        <a href="patients.php" class="back-link">Back to Dashboard</a>

        <?php if ($bookedId > 0): ?>
            <div class="success-banner">
                <strong>Appointment request successfully submitted.</strong><br>
                Reference #<?php echo $bookedId; ?>.
                <?php if ($appointmentEmailWarning === ''): ?>
                    We sent the booking details to your registered email and mobile number.
                <?php else: ?>
                    Your booking is saved and visible in My Appointments.
                <?php endif; ?>
                The clinic will review and confirm your request.
            </div>
            <?php if ($appointmentEmailWarning !== ''): ?>
                <div class="error-message"><?php echo htmlspecialchars($appointmentEmailWarning); ?></div>
            <?php endif; ?>
            <a href="view_appointments.php" class="btn-primary" style="display:inline-block;width:auto;padding:12px 24px;text-decoration:none;text-align:center;">View my appointments</a>
            <a href="book_appointment.php?start=1" class="btn-secondary" style="margin-top:12px;">Book another</a>
        <?php else: ?>

        <div class="booking-header">
            <h2>Book an appointment</h2>
            <p><?php echo $step === 1 && empty($bk['calendar_ready'])
                ? 'Review doctor availability, then continue to booking.'
                : 'Follow the steps below. Payment is made at the clinic after staff confirms your request.'; ?></p>
        </div>

        <?php if ($step === 1 && empty($bk['calendar_ready'])): ?>
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post" action="book_appointment.php" id="calendarFirstForm">
                <input type="hidden" name="booking_action" value="begin_booking">
                <section class="booking-calendar-panel" aria-label="Appointment availability calendar">
                <div class="clinic-hours-summary" aria-label="Clinic operating hours">
                    <strong>Clinic operating hours</strong>
                    <span>Monday to Saturday, 8:00 AM - 5:00 PM &bull; Sunday closed</span>
                </div>
                <div class="schedule-layout">
                    <div class="mini-calendar">
                        <div class="mini-calendar-head">
                            <div class="calendar-nav">
                                <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarPrevMonth); ?>" aria-label="Previous month">&lsaquo;</a>
                                <strong><?php echo htmlspecialchars($calendarMonthLabel); ?></strong>
                                <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarNextMonth); ?>" aria-label="Next month">&rsaquo;</a>
                            </div>
                            <span>Doctor schedule</span>
                        </div>
                        <div class="cal-grid" id="startAppointmentCalendar">
                            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                                <div class="cal-dow"><?php echo $dow; ?></div>
                            <?php endforeach; ?>
                            <?php for ($blank = 0; $blank < $calendarFirstWeekday; $blank++): ?>
                                <div class="cal-empty"></div>
                            <?php endfor; ?>
                            <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++): ?>
                                <?php
                                $dateValue = $calendarMonthStart->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                                $dateDayOfWeek = (int) date('N', strtotime($dateValue));
                                $clinicOpen = $dateDayOfWeek >= 1 && $dateDayOfWeek <= 6;
                                $doctorSlots = $calendarDoctorSlotsByDow[$dateDayOfWeek] ?? [];
                                $classes = ['cal-day', 'calendar-readonly'];
                                if ($dateValue === $calendarToday) {
                                    $classes[] = 'is-today';
                                }
                                if ($dateValue === $calendarSelected) {
                                    $classes[] = 'is-selected';
                                }
                                if ($clinicOpen) {
                                    $classes[] = 'is-clinic-open';
                                }
                                if (!empty($doctorSlots)) {
                                    $classes[] = 'has-doctor';
                                }
                                if (!$clinicOpen) {
                                    $classes[] = 'is-closed';
                                }
                                $disabled = $dateValue < $calendarToday;
                                if ($disabled) {
                                    $classes[] = 'is-past';
                                }
                                ?>
                                <div class="<?php echo implode(' ', $classes); ?>">
                                    <span class="cal-date-number"><?php echo $day; ?></span>
                                    <div class="calendar-events">
                                        <?php if (!$disabled): ?>
                                            <?php if ($clinicOpen): ?>
                                                <small class="clinic-hours-event">
                                                    <span class="clinic-hours-label">Clinic open</span>
                                                    <span class="clinic-hours-time">8:00 AM - 5:00 PM</span>
                                                </small>
                                            <?php else: ?>
                                                <small class="clinic-closed-event">Clinic closed</small>
                                            <?php endif; ?>
                                            <?php if ($clinicOpen): ?>
                                            <?php foreach (array_slice($doctorSlots, 0, 2) as $slot): ?>
                                                <?php $doctorEventText = $slot['doctor'] . ' - ' . $slot['time']; ?>
                                                <small class="doctor-event" title="<?php echo htmlspecialchars($doctorEventText); ?>">
                                                    <span class="availability-label"><?php echo htmlspecialchars($slot['specialty'] !== '' ? $slot['specialty'] : 'Available'); ?></span>
                                                    <span class="availability-doctor"><?php echo htmlspecialchars($slot['doctor']); ?></span>
                                                    <span class="availability-time"><?php echo htmlspecialchars($slot['time']); ?></span>
                                                </small>
                                            <?php endforeach; ?>
                                            <?php if (count($doctorSlots) > 2): ?>
                                                <small class="more-event">+<?php echo count($doctorSlots) - 2; ?> more doctor slots</small>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <div class="calendar-actions">
                    <button type="submit" class="btn-primary" id="continueFromCalendar">Continue</button>
                </div>
                </section>
            </form>
        <?php endif; ?>

        <?php if ($step !== 1 || !empty($bk['calendar_ready'])): ?>
        <div class="clinic-reminder-grid" aria-labelledby="bookingProcedureTitle">
            <div class="clinic-reminder-heading">
                <h3 id="bookingProcedureTitle">How to book your appointment</h3>
                <p>Complete these steps to submit and attend your clinic appointment.</p>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">1</span>
                <strong>Choose an appointment</strong>
                <span>Select a doctor consultation, laboratory package, or individual laboratory tests.</span>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">2</span>
                <strong>Select your schedule</strong>
                <span>Choose a date and time within the clinic or selected doctor's available hours.</span>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">3</span>
                <strong>Verify your request</strong>
                <span>Choose email or SMS, then enter the 6-digit confirmation code.</span>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">4</span>
                <strong>Visit the clinic</strong>
                <span>Bring a valid ID, arrive 10-15 minutes early, and pay at the front desk.</span>
            </div>
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
                <strong>Step 1.</strong> Choose between a <strong>Doctor Consultation</strong>, a clinic <strong>Package Deal</strong>, or <strong>Individual Laboratory Tests</strong>.
            </div>
            <form method="post" action="book_appointment.php">
                <input type="hidden" name="booking_action" value="select_type">
                <input type="hidden" name="preferred_date" id="preferredDateInput" value="<?php echo htmlspecialchars($bk['appointment_date']); ?>">
                <div class="choice-grid">
                    <button type="submit" name="booking_type" value="consultation" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Doctor consultation</h3>
                        <p>Choose an available doctor based on specialty and clinic schedule.</p>
                    </button>
                    <button type="submit" name="booking_type" value="package" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Package deals</h3>
                        <p>Best for clinic packages such as pre-employment, sanitary permit, and CVSU.</p>
                    </button>
                    <button type="submit" name="booking_type" value="individual" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Individual laboratory tests</h3>
                        <p>Choose one or more lab tests, then select your preferred appointment schedule.</p>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 2): ?>
            <div class="info-box">
                <strong>Step 2.</strong>
                <?php
                if ($bk['type'] === 'consultation') {
                    echo 'Choose your preferred doctor. Review the specialty and clinic hours before continuing.';
                } elseif ($bk['type'] === 'package') {
                    echo 'Choose one package. The final payment is made at the clinic.';
                } else {
                    echo 'Choose one or more individual tests. Use search or category filter to find services faster.';
                }
                ?>
            </div>
            <form method="post" action="book_appointment.php" id="formChooseServices">
                <input type="hidden" name="booking_action" value="choose_services">
                <?php if ($bk['type'] === 'consultation'): ?>
                    <?php if (empty($bookingDoctors)): ?>
                        <p>No active doctors are available yet. Please contact the clinic.</p>
                    <?php else: ?>
                        <div class="doc-grid">
                            <?php foreach ($bookingDoctors as $doctor): ?>
                                <?php
                                $doctorId = (int) $doctor['id'];
                                $themeClass = (string) ($doctor['theme']['class'] ?? 'theme-intern');
                                $themeLabel = (string) ($doctor['theme']['label'] ?? 'DOCTOR');
                                ?>
                                <label class="doc-card <?php echo htmlspecialchars($themeClass); ?>">
                                    <span class="doc-badge <?php echo strpos($themeClass, 'peds') !== false ? 'peds' : 'intern'; ?>">
                                        <?php echo htmlspecialchars($themeLabel); ?>
                                    </span>
                                    <h4><?php echo htmlspecialchars((string) $doctor['full_name']); ?></h4>
                                    <p class="doc-hours"><?php echo htmlspecialchars((string) $doctor['clinic_hours']); ?></p>
                                    <span class="doc-pick">
                                        <input
                                            type="radio"
                                            name="doctor_id"
                                            value="<?php echo $doctorId; ?>"
                                            <?php echo (int) ($bk['doctor_id'] ?? 0) === $doctorId ? 'checked' : ''; ?>
                                            required
                                        >
                                        Select this doctor
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($bk['type'] === 'package'): ?>
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
                                            <span class="price-tag">PHP <?php echo number_format((float) $svc['opd_price'], 0); ?></span>
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
                            <strong>Summary:</strong> <span id="indCount">0</span> test(s) | Subtotal <span id="indSubOpd">PHP 0</span>
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
                                                <span>PHP <?php echo number_format((float) $svc['opd_price'], 0); ?></span>
                                            </div>
                                            <button type="button" class="btn-add-svc ind-add" data-target="t<?php echo $sid; ?>">Add</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="submit" class="btn-primary">
                    <?php echo $bk['type'] === 'consultation' ? 'Next: review doctor' : 'Next: view details'; ?>
                </button>
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
                        el.textContent = r.getAttribute('data-name') + ' - PHP ' + Number(opd).toLocaleString();
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
                <strong>Step 3.</strong>
                <?php echo $bk['type'] === 'consultation'
                    ? 'Review your selected doctor and clinic hours before choosing an appointment schedule.'
                    : 'Review the selected services and prices. Payment is made at the clinic.'; ?>
            </div>
            <?php if ($bk['type'] === 'consultation' && $selectedDoctor): ?>
                <div class="detail-block">
                    <h4><?php echo htmlspecialchars((string) $selectedDoctor['full_name']); ?></h4>
                    <p><strong>Specialty:</strong> <?php echo htmlspecialchars((string) ($selectedDoctor['specialty'] ?: 'General consultation')); ?></p>
                    <p><strong>Clinic hours:</strong><br><?php echo nl2br(htmlspecialchars((string) $selectedDoctor['clinic_hours'])); ?></p>
                    <p class="price-tag consultation-fee-note">Consultation fee will be confirmed and paid at the clinic.</p>
                </div>
            <?php else: ?>
                <?php foreach ($selectedServices as $svc): ?>
                    <div class="detail-block">
                        <h4><?php echo htmlspecialchars($svc['name']); ?></h4>
                        <p><?php echo nl2br(htmlspecialchars($svc['description'] ?? '')); ?></p>
                        <?php if (!empty($svc['included_tests'])): ?>
                            <p><strong>Included tests:</strong> <?php echo htmlspecialchars($svc['included_tests']); ?></p>
                        <?php endif; ?>
                        <p class="price-tag">Price: PHP <?php echo number_format((float) $svc['opd_price'], 2); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="book_appointment.php">
                <input type="hidden" name="booking_action" value="set_channel">
                <input type="hidden" name="price_channel" value="opd">
                <?php if ($bk['type'] !== 'consultation'): ?>
                    <p style="font-size:1.1rem;margin:16px 0;"><strong>Total:</strong> <span class="price-tag">PHP <?php echo number_format($displayTotal, 2); ?></span></p>
                <?php endif; ?>
                <button type="submit" class="btn-primary">Next: choose date and time</button>
            </form>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>

        <?php elseif ($step === 4): ?>
            <div class="info-box">
                <strong>Step 4 - Appointment schedule.</strong>
                <?php if ($bk['type'] === 'consultation' && $selectedDoctor): ?>
                    Choose a date with <?php echo htmlspecialchars((string) $selectedDoctor['full_name']); ?> available, then select a time.
                <?php else: ?>
                    Choose an open clinic date, then select your preferred laboratory visit time.
                <?php endif; ?>
            </div>
            <form method="post" action="book_appointment.php" id="scheduleForm">
                <input type="hidden" name="booking_action" id="booking_action_field" value="set_schedule">
                <input type="hidden" name="appointment_date" id="appointment_date" value="<?php echo htmlspecialchars($bk['appointment_date']); ?>">
                <input type="hidden" name="appointment_time" id="appointment_time" value="<?php echo htmlspecialchars($bk['appointment_time']); ?>">
                <div class="schedule-card">
                    <div class="clinic-hours-summary" aria-label="Clinic operating hours">
                        <strong>Clinic operating hours</strong>
                        <span>Monday to Saturday, 8:00 AM - 5:00 PM &bull; Sunday closed</span>
                    </div>
                    <div class="schedule-layout">
                        <div class="mini-calendar" aria-label="Appointment calendar">
                            <div class="mini-calendar-head">
                                <div class="calendar-nav">
                                    <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarPrevMonth); ?>" aria-label="Previous month">&lsaquo;</a>
                                    <strong><?php echo htmlspecialchars($calendarMonthLabel); ?></strong>
                                    <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarNextMonth); ?>" aria-label="Next month">&rsaquo;</a>
                                </div>
                                <span><?php echo $bk['type'] === 'consultation' ? 'Doctor availability' : 'Pick a visit date'; ?></span>
                            </div>
                            <div class="cal-grid" id="appointmentCalendar">
                                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                                    <div class="cal-dow"><?php echo $dow; ?></div>
                                <?php endforeach; ?>
                                <?php for ($blank = 0; $blank < $calendarFirstWeekday; $blank++): ?>
                                    <div class="cal-empty"></div>
                                <?php endfor; ?>
                                <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++): ?>
                                    <?php
                                    $dateValue = $calendarMonthStart->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                                    $dateDayOfWeek = (int) date('N', strtotime($dateValue));
                                    $clinicOpen = $dateDayOfWeek >= 1 && $dateDayOfWeek <= 6;
                                    $doctorSlots = $calendarDoctorSlotsByDow[$dateDayOfWeek] ?? [];
                                    $classes = ['cal-day'];
                                    if ($dateValue === $calendarToday) {
                                        $classes[] = 'is-today';
                                    }
                                    if ($dateValue === $calendarSelected) {
                                        $classes[] = 'is-selected';
                                    }
                                    if ($clinicOpen) {
                                        $classes[] = 'is-clinic-open';
                                    }
                                    if ($bk['type'] === 'consultation' && !empty($doctorSlots)) {
                                        $classes[] = 'has-doctor';
                                    }
                                    if (!$clinicOpen) {
                                        $classes[] = 'is-closed';
                                    }
                                    $disabled = $dateValue < $calendarToday
                                        || !$clinicOpen
                                        || ($bk['type'] === 'consultation' && empty($doctorSlots));
                                    $timeWindows = $bk['type'] === 'consultation'
                                        ? array_map(
                                            static fn (array $slot): string => $slot['start'] . '-' . $slot['end'],
                                            $doctorSlots
                                        )
                                        : ['08:00-17:00'];
                                    ?>
                                    <button
                                        type="button"
                                        class="<?php echo implode(' ', $classes); ?>"
                                        data-date="<?php echo htmlspecialchars($dateValue); ?>"
                                        data-time-windows="<?php echo htmlspecialchars(implode(',', $timeWindows)); ?>"
                                        <?php echo $disabled ? ' disabled' : ''; ?>
                                    >
                                        <span class="cal-date-number"><?php echo $day; ?></span>
                                        <div class="calendar-events">
                                            <?php if ($clinicOpen && $dateValue >= $calendarToday): ?>
                                                <small class="clinic-hours-event">
                                                    <span class="clinic-hours-label">Clinic open</span>
                                                    <span class="clinic-hours-time">8:00 AM - 5:00 PM</span>
                                                </small>
                                                <?php if ($bk['type'] === 'consultation'): ?>
                                                <?php foreach (array_slice($doctorSlots, 0, 2) as $slot): ?>
                                                    <?php $doctorEventText = $slot['doctor'] . ' - ' . $slot['time']; ?>
                                                    <small class="doctor-event" title="<?php echo htmlspecialchars($doctorEventText); ?>">
                                                        <span class="availability-label"><?php echo htmlspecialchars($slot['specialty'] !== '' ? $slot['specialty'] : 'Available'); ?></span>
                                                        <span class="availability-doctor"><?php echo htmlspecialchars($slot['doctor']); ?></span>
                                                        <span class="availability-time"><?php echo htmlspecialchars($slot['time']); ?></span>
                                                    </small>
                                                <?php endforeach; ?>
                                                <?php if (count($doctorSlots) > 2): ?>
                                                    <small class="more-event">+<?php echo count($doctorSlots) - 2; ?> more doctor slots</small>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            <?php elseif (!$clinicOpen && $dateValue >= $calendarToday): ?>
                                                <small class="clinic-closed-event">Clinic closed</small>
                                            <?php endif; ?>
                                        </div>
                                    </button>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="schedule-controls">
                            <div class="selected-date-card">
                                <span>Selected date</span>
                                <strong id="selectedDateText"><?php echo $bk['appointment_date'] !== '' ? date('M d, Y', strtotime($bk['appointment_date'])) : 'Choose a date from the calendar'; ?></strong>
                            </div>
                            <div class="time-picker-field">
                                <label for="appointmentTimeButton">Preferred time</label>
                                <button type="button" class="time-picker-trigger" id="appointmentTimeButton">
                                    <span class="time-picker-copy">
                                        <strong id="appointmentTimeLabel"><?php echo $bk['appointment_time'] !== '' ? date('g:i A', strtotime($bk['appointment_time'])) : 'Choose appointment time'; ?></strong>
                                        <small id="appointmentTimeWindow">
                                            <?php echo $bk['appointment_date'] !== ''
                                                ? ($bk['type'] === 'consultation' ? 'Use the doctor hours shown on the selected date.' : 'Clinic hours: 8:00 AM - 5:00 PM')
                                                : 'Choose a date first to see available hours.'; ?>
                                        </small>
                                    </span>
                                    <span class="time-picker-clock" aria-hidden="true">&#9716;</span>
                                </button>
                            </div>
                            <p class="schedule-note">
                                <?php if ($bk['type'] === 'consultation' && $selectedDoctor): ?>
                                    Only dates and times within <?php echo htmlspecialchars((string) $selectedDoctor['full_name']); ?>'s availability can be selected.
                                <?php else: ?>
                                    Laboratory appointments are available Monday to Saturday during clinic hours.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-primary" id="btnScheduleNext">Next: review and confirm</button>
            </form>

            <div class="time-picker-overlay" id="appointmentTimePicker" hidden>
                <section class="time-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="appointmentTimePickerTitle">
                    <div class="time-picker-head">
                        <div>
                            <h3 id="appointmentTimePickerTitle">Select appointment time</h3>
                            <p id="timePickerAvailability">Choose a date first to see available hours.</p>
                        </div>
                        <button type="button" class="time-picker-close" id="closeTimePicker" aria-label="Close time picker">&times;</button>
                    </div>
                    <div class="time-entry">
                        <div class="time-entry-part">
                            <input type="number" id="timePickerHour" min="1" max="12" value="8" inputmode="numeric" aria-label="Hour">
                            <label for="timePickerHour">Hour</label>
                        </div>
                        <div class="time-colon" aria-hidden="true">:</div>
                        <div class="time-entry-part">
                            <input type="text" id="timePickerMinute" value="00" inputmode="numeric" maxlength="2" pattern="[0-9]{1,2}" aria-label="Minute">
                            <label for="timePickerMinute">Minute</label>
                        </div>
                        <div class="time-period" aria-label="AM or PM">
                            <button type="button" id="timePickerAm" class="is-active" data-period="AM">AM</button>
                            <button type="button" id="timePickerPm" data-period="PM">PM</button>
                        </div>
                    </div>
                    <p class="time-picker-error" id="timePickerError" role="alert"></p>
                    <div class="time-picker-actions">
                        <button type="button" class="time-picker-cancel" id="cancelTimePicker">Cancel</button>
                        <button type="button" class="time-picker-apply" id="applyTimePicker">Use this time</button>
                    </div>
                </section>
            </div>
            <script>
            (function() {
                var form = document.getElementById('scheduleForm');
                var actionField = document.getElementById('booking_action_field');
                var dateInp = document.getElementById('appointment_date');
                var timeInp = document.getElementById('appointment_time');
                var btnNext = document.getElementById('btnScheduleNext');
                var calendar = document.getElementById('appointmentCalendar');
                var selectedDateCard = document.querySelector('.selected-date-card');
                var selectedDateText = document.getElementById('selectedDateText');
                var timeButton = document.getElementById('appointmentTimeButton');
                var timeLabel = document.getElementById('appointmentTimeLabel');
                var timeWindow = document.getElementById('appointmentTimeWindow');
                var picker = document.getElementById('appointmentTimePicker');
                var pickerAvailability = document.getElementById('timePickerAvailability');
                var pickerHour = document.getElementById('timePickerHour');
                var pickerMinute = document.getElementById('timePickerMinute');
                var pickerAm = document.getElementById('timePickerAm');
                var pickerPm = document.getElementById('timePickerPm');
                var pickerError = document.getElementById('timePickerError');
                var closePickerButton = document.getElementById('closeTimePicker');
                var cancelPickerButton = document.getElementById('cancelTimePicker');
                var applyPickerButton = document.getElementById('applyTimePicker');
                var activePeriod = 'AM';
                var bodyOverflow = '';
                if (!form || !actionField || !dateInp || !timeInp || !calendar || !timeButton || !picker) return;

                function formatDateLabel(value) {
                    if (!value) return 'Choose a date from the calendar';
                    var parts = value.split('-');
                    if (parts.length !== 3) return value;
                    var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
                    return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                }
                function formatTimeLabel(value) {
                    if (!value) return 'Choose appointment time';
                    var parts = value.split(':');
                    var hour = Number(parts[0] || 0);
                    var minute = parts[1] || '00';
                    var suffix = hour >= 12 ? 'PM' : 'AM';
                    var displayHour = hour % 12 || 12;
                    return displayHour + ':' + minute + ' ' + suffix;
                }
                function toMinutes(value) {
                    var parts = (value || '').split(':');
                    return Number(parts[0] || 0) * 60 + Number(parts[1] || 0);
                }
                function selectedDay() {
                    return calendar.querySelector('.cal-day[data-date="' + dateInp.value + '"]');
                }
                function selectedWindows() {
                    var day = selectedDay();
                    if (!day) return [];
                    return (day.getAttribute('data-time-windows') || '').split(',').map(function(windowText) {
                        var parts = windowText.split('-');
                        return parts.length === 2 ? { start: parts[0], end: parts[1] } : null;
                    }).filter(Boolean);
                }
                function isTimeAllowed(value) {
                    if (!value) return false;
                    var candidate = toMinutes(value);
                    return selectedWindows().some(function(windowItem) {
                        return candidate >= toMinutes(windowItem.start) && candidate <= toMinutes(windowItem.end);
                    });
                }
                function windowLabel() {
                    var windows = selectedWindows();
                    if (!dateInp.value) return 'Choose a date first to see available hours.';
                    if (!windows.length) return 'No available time on this date.';
                    return 'Available: ' + windows.map(function(windowItem) {
                        return formatTimeLabel(windowItem.start) + ' - ' + formatTimeLabel(windowItem.end);
                    }).join(', ');
                }
                function updatePeriod(period) {
                    activePeriod = period;
                    pickerAm.classList.toggle('is-active', period === 'AM');
                    pickerPm.classList.toggle('is-active', period === 'PM');
                }
                function loadPickerValue() {
                    var value = timeInp.value;
                    if (!value || !isTimeAllowed(value)) {
                        var windows = selectedWindows();
                        value = windows.length ? windows[0].start : '08:00';
                    }
                    var parts = value.split(':');
                    var hour24 = Number(parts[0] || 8);
                    pickerHour.value = String(hour24 % 12 || 12);
                    pickerMinute.value = String(Number(parts[1] || 0)).padStart(2, '0');
                    updatePeriod(hour24 >= 12 ? 'PM' : 'AM');
                }
                function updateView() {
                    if (selectedDateText) {
                        selectedDateText.textContent = formatDateLabel(dateInp.value);
                    }
                    calendar.querySelectorAll('.cal-day').forEach(function(day) {
                        day.classList.toggle('is-selected', day.getAttribute('data-date') === dateInp.value);
                    });
                    timeButton.disabled = !dateInp.value;
                    timeLabel.textContent = formatTimeLabel(timeInp.value);
                    timeWindow.textContent = windowLabel();
                    timeButton.classList.toggle('is-invalid', dateInp.value !== '' && !timeInp.value);
                }
                function openPicker() {
                    if (!dateInp.value) return;
                    loadPickerValue();
                    pickerError.textContent = '';
                    pickerAvailability.textContent = windowLabel();
                    bodyOverflow = document.body.style.overflow;
                    document.body.style.overflow = 'hidden';
                    picker.hidden = false;
                    pickerHour.focus();
                }
                function closePicker() {
                    picker.hidden = true;
                    document.body.style.overflow = bodyOverflow;
                    timeButton.focus();
                }
                function enteredTime() {
                    var hour = Number(pickerHour.value);
                    var minuteText = String(pickerMinute.value || '').trim();
                    var minute = Number(minuteText);
                    if (!Number.isInteger(hour) || hour < 1 || hour > 12 || !/^\d{1,2}$/.test(minuteText) || !Number.isInteger(minute) || minute < 0 || minute > 59) {
                        return '';
                    }
                    var hour24 = hour % 12;
                    if (activePeriod === 'PM') hour24 += 12;
                    return String(hour24).padStart(2, '0') + ':' + String(minute).padStart(2, '0');
                }

                calendar.querySelectorAll('.cal-day').forEach(function(day) {
                    day.addEventListener('click', function() {
                        if (day.disabled) return;
                        dateInp.value = day.getAttribute('data-date') || '';
                        if (!isTimeAllowed(timeInp.value)) {
                            timeInp.value = '';
                        }
                        selectedDateCard.classList.remove('is-invalid');
                        updateView();
                    });
                });

                timeButton.addEventListener('click', openPicker);
                pickerAm.addEventListener('click', function() { updatePeriod('AM'); });
                pickerPm.addEventListener('click', function() { updatePeriod('PM'); });
                closePickerButton.addEventListener('click', closePicker);
                cancelPickerButton.addEventListener('click', closePicker);
                picker.addEventListener('click', function(event) {
                    if (event.target === picker) closePicker();
                });
                applyPickerButton.addEventListener('click', function() {
                    var value = enteredTime();
                    if (!value) {
                        pickerError.textContent = 'Enter a valid hour and minute.';
                        return;
                    }
                    if (!isTimeAllowed(value)) {
                        pickerError.textContent = 'Choose a time inside the available hours shown above.';
                        return;
                    }
                    timeInp.value = value;
                    timeButton.classList.remove('is-invalid');
                    updateView();
                    closePicker();
                });
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && !picker.hidden) closePicker();
                });

                if (btnNext) {
                    btnNext.addEventListener('click', function() {
                        actionField.value = 'set_schedule';
                    });
                }
                form.addEventListener('submit', function(event) {
                    actionField.value = 'set_schedule';
                    if (!dateInp.value) {
                        event.preventDefault();
                        selectedDateCard.classList.add('is-invalid');
                        selectedDateText.textContent = 'Choose a date from the calendar';
                        calendar.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }
                    if (!timeInp.value || !isTimeAllowed(timeInp.value)) {
                        event.preventDefault();
                        timeButton.classList.add('is-invalid');
                        openPicker();
                    }
                });
                if (timeInp.value && !isTimeAllowed(timeInp.value)) {
                    timeInp.value = '';
                }
                updateView();
            })();
            </script>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>

        <?php elseif ($step === 5): ?>
            <div class="info-box">
                <strong>Step 5.</strong> Review your appointment summary.
                Click Confirm when everything looks correct.
            </div>
            <table class="summary-table">
                <tr>
                    <td>Appointment type</td>
                    <td>
                        <?php
                        echo $bk['type'] === 'consultation'
                            ? 'Doctor consultation'
                            : ($bk['type'] === 'package' ? 'Laboratory package' : 'Individual laboratory tests');
                        ?>
                    </td>
                </tr>
                <?php if ($bk['type'] === 'consultation' && $selectedDoctor): ?>
                    <tr><td>Doctor</td><td><?php echo htmlspecialchars((string) $selectedDoctor['full_name']); ?></td></tr>
                    <tr><td>Specialty</td><td><?php echo htmlspecialchars((string) ($selectedDoctor['specialty'] ?: 'General consultation')); ?></td></tr>
                <?php endif; ?>
                <tr><td>Date</td><td><?php echo htmlspecialchars($bk['appointment_date']); ?></td></tr>
                <tr><td>Time</td><td><?php echo htmlspecialchars($bk['appointment_time']); ?></td></tr>
                <?php if ($bk['type'] === 'consultation'): ?>
                    <tr><td>Payment</td><td>Consultation fee confirmed at clinic</td></tr>
                <?php else: ?>
                    <tr><td><strong>Total (pay at clinic)</strong></td><td><strong>PHP <?php echo number_format($displayTotal, 2); ?></strong></td></tr>
                <?php endif; ?>
            </table>
            <?php if ($bk['type'] !== 'consultation'): ?>
                <h4 style="margin:16px 0 8px;color:#023e8a;">Selected services</h4>
                <ul style="margin:0;padding-left:20px;color:#444;">
                    <?php foreach ($selectedServices as $svc): ?>
                        <li><?php echo htmlspecialchars($svc['name']); ?> - PHP <?php echo number_format(serviceUnitPrice($svc, 'opd'), 2); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="book_appointment.php" style="margin-top:24px;">
                <input type="hidden" name="booking_action" value="confirm_booking">
                <div class="verification-methods">
                    <strong>Where should we send the confirmation code?</strong>
                    <p>Choose one method before submitting your appointment request.</p>
                    <div class="verification-method-grid">
                        <label class="verification-method">
                            <input type="radio" name="verification_channel" value="email" checked>
                            <span><strong>Email</strong>Send the code to your registered email.</span>
                        </label>
                        <label class="verification-method">
                            <input type="radio" name="verification_channel" value="sms">
                            <span><strong>SMS</strong>Text the code to your registered mobile number.</span>
                        </label>
                    </div>
                </div>
                <label class="review-check">
                    <input type="checkbox" required>
                    <span>I reviewed the details and understand that I must verify the code before this request is submitted.</span>
                </label>
                <button type="submit" class="btn-primary">Send confirmation code</button>
            </form>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>
        <?php endif; ?>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

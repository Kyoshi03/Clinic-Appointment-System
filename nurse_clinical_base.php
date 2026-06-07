<?php
require_once 'includes/session.php';
checkRole('nurse');

require_once 'config/database.php';
require_once __DIR__ . '/includes/nurse_clinical.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';
require_once __DIR__ . '/includes/nurse_clinical_styles.php';

if (!isset($clinicalTab) || !in_array($clinicalTab, ['medical', 'lab'], true)) {
    $clinicalTab = 'medical';
}
if (empty($clinicalReturnUrl)) {
    $clinicalReturnUrl = $clinicalTab === 'lab' ? 'nurse_lab.php' : 'nurse_medical.php';
}

$currentUser = getCurrentUser();
$today = date('Y-m-d');

function nc_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M j, Y', $stamp) : '--';
}

function nc_short_text(?string $text, int $limit = 100): string {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nurse_clinical_action'])) {
    $clinicalAction = (string) $_POST['nurse_clinical_action'];
    $patientId = (int) ($_POST['clinical_patient_id'] ?? 0);
    $postTab = (string) ($_POST['clinical_tab'] ?? $clinicalTab);
    $conn = getDBConnection();
 if (function_exists('nurse_clinical_ensure_schema')) {
    nurse_clinical_ensure_schema($conn);
}

    if (!nurse_clinical_patient_exists($conn, $patientId)) {
        $_SESSION['error'] = 'Please select a valid patient.';
    } elseif ($clinicalAction === 'add_medical') {
        $fields = nurse_medical_fields_from_post($_POST);
        $result = nurse_clinical_save_medical($conn, $patientId, (int) $currentUser['id'], $fields);
        if ($result['ok']) {
            $_SESSION['success'] = $result['message'];
            $_SESSION['nurse_last_record'] = ['type' => 'medical', 'id' => $result['id']];
        } else {
            $_SESSION['error'] = $result['error'];
            $_SESSION['nurse_clinical_form'] = nurse_medical_form_session_from_post($_POST, $patientId);
        }
    } elseif ($clinicalAction === 'add_lab_result') {
        $result = nurse_clinical_save_lab($conn, $patientId, (int) $currentUser['id'], (string) ($_POST['lr_test_name'] ?? ''), (string) ($_POST['lr_result_text'] ?? ''), (string) ($_POST['lr_result_date'] ?? ''), (int) ($_POST['lr_lab_service_id'] ?? 0));
        if ($result['ok']) {
            $_SESSION['success'] = $result['message'];
            $_SESSION['nurse_last_record'] = ['type' => 'lab', 'id' => $result['id']];
        } else {
            $_SESSION['error'] = $result['error'];
            $_SESSION['nurse_clinical_form'] = [
                'tab' => 'lab',
                'patient_id' => $patientId,
                'lr_test_name' => $_POST['lr_test_name'] ?? '',
                'lr_result_text' => $_POST['lr_result_text'] ?? '',
                'lr_result_date' => $_POST['lr_result_date'] ?? date('Y-m-d'),
                'lr_lab_service_id' => (int) ($_POST['lr_lab_service_id'] ?? 0),
            ];
        }
    } else {
        $_SESSION['error'] = 'Invalid action.';
    }

    $conn->close();
    $redirectTab = $postTab === 'lab' ? 'nurse_lab.php' : 'nurse_medical.php';
    header('Location: ' . $redirectTab);
    exit;
}

$conn = getDBConnection();
if (function_exists('nurse_clinical_ensure_schema')) {
    nurse_clinical_ensure_schema($conn);
}
$todayRecordCount = 0;
$todayLabCount = 0;
$rc = $conn->prepare('SELECT COUNT(*) AS total FROM medical_records WHERE DATE(created_at) = ?');
$rc->bind_param('s', $today);
$rc->execute();
if ($row = $rc->get_result()->fetch_assoc()) {
    $todayRecordCount = (int) $row['total'];
}
$rc->close();
$lc = $conn->prepare('SELECT COUNT(*) AS total FROM lab_result_entries WHERE DATE(created_at) = ?');
$lc->bind_param('s', $today);
$lc->execute();
if ($row = $lc->get_result()->fetch_assoc()) {
    $todayLabCount = (int) $row['total'];
}
$lc->close();

$todayPatients = [];
$ap = $conn->prepare("SELECT DISTINCT a.patient_id, p.full_name AS patient_name FROM appointments a JOIN users p ON p.id = a.patient_id WHERE a.appointment_date = ? ORDER BY p.full_name");
$ap->bind_param('s', $today);
$ap->execute();
$todayPatients = $ap->get_result()->fetch_all(MYSQLI_ASSOC);
$ap->close();

$clinicalForm = $_SESSION['nurse_clinical_form'] ?? [];
unset($_SESSION['nurse_clinical_form']);
if (!empty($clinicalForm['tab'])) {
    $clinicalTab = (string) $clinicalForm['tab'];
}
$lastSavedRecord = $_SESSION['nurse_last_record'] ?? null;
unset($_SESSION['nurse_last_record']);

$patientOptions = [];
$patientSeen = [];
foreach ($todayPatients as $appointment) {
    $pid = (int) ($appointment['patient_id'] ?? 0);
    if ($pid > 0 && !isset($patientSeen[$pid])) {
        $patientSeen[$pid] = true;
        $patientOptions[] = ['id' => $pid, 'label' => (string) ($appointment['patient_name'] ?? '') . ' (today)'];
    }
}
$allPatientsResult = $conn->query("SELECT id, full_name, username FROM users WHERE role = 'patient' ORDER BY full_name ASC LIMIT 400");
if ($allPatientsResult) {
    while ($pRow = $allPatientsResult->fetch_assoc()) {
        $pid = (int) $pRow['id'];
        if (!isset($patientSeen[$pid])) {
            $patientSeen[$pid] = true;
            $patientOptions[] = ['id' => $pid, 'label' => (string) $pRow['full_name'] . ' (' . (string) $pRow['username'] . ')'];
        }
    }
}

$labServicesList = $conn->query("SELECT id, name, category, is_package FROM lab_services WHERE is_active = 1 ORDER BY is_package DESC, category, name")->fetch_all(MYSQLI_ASSOC);

$recentRecords = $conn->query("SELECT m.id AS record_id, m.patient_id, m.title, m.content, m.diagnosis, m.doctor_notes, m.treatment, m.medical_history, m.vital_signs, m.allergies, m.patient_progress, m.created_at, p.full_name AS patient_name FROM medical_records m JOIN users p ON p.id = m.patient_id ORDER BY m.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$recentLabs = $conn->query("SELECT l.id AS record_id, l.patient_id, l.test_name, l.result_text, l.result_date, p.full_name AS patient_name FROM lab_result_entries l JOIN users p ON p.id = l.patient_id ORDER BY l.created_at DESC, l.result_date DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$conn->close();

$selectedPatientId = (int) ($clinicalForm['patient_id'] ?? ($_GET['patient'] ?? 0));
$pageTitle = $clinicalTab === 'lab' ? 'Lab results | Nurse' : 'Medical records | Nurse';
$pageHeading = $clinicalTab === 'lab' ? 'Laboratory results' : 'Medical records';
$pageDesc = $clinicalTab === 'lab'
    ? 'Enter test results, then print or download for the patient.'
    : 'Document diagnosis, treatment, vitals, allergies, and patient progress, then print or download.';

$additionalStyles = nurse_clinical_styles();
$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const patientSelect = document.getElementById("clinicalPatientSelect");
    const medHidden = document.getElementById("medicalPatientId");
    const labHidden = document.getElementById("labPatientId");
    function syncPatient() {
        const v = patientSelect ? patientSelect.value : "";
        if (medHidden) medHidden.value = v;
        if (labHidden) labHidden.value = v;
    }
    if (patientSelect) {
        patientSelect.addEventListener("change", syncPatient);
        syncPatient();
    }
    document.querySelectorAll("form[id^=nurse]").forEach(function (form) {
        form.addEventListener("submit", function (e) {
            syncPatient();
            if (!patientSelect || !patientSelect.value) {
                e.preventDefault();
                alert("Please select a patient first.");
                patientSelect.focus();
            }
        });
    });
    const lrSvc = document.getElementById("nurseLrSvc");
    const lrName = document.getElementById("nurseLrName");
    if (lrSvc && lrName) {
        lrSvc.addEventListener("change", function () {
            const opt = lrSvc.options[lrSvc.selectedIndex];
            if (opt && opt.value !== "0") lrName.value = opt.getAttribute("data-name") || "";
        });
    }
    const recentTabs = document.querySelectorAll("[data-recent-tab]");
    const recentPanes = document.querySelectorAll("[data-recent-pane]");
    recentTabs.forEach(function (btn) {
        btn.addEventListener("click", function () {
            const tab = btn.getAttribute("data-recent-tab");
            recentTabs.forEach(function (b) { b.classList.toggle("active", b === btn); });
            recentPanes.forEach(function (p) { p.classList.toggle("active", p.getAttribute("data-recent-pane") === tab); });
        });
    });
' . ($clinicalTab === 'lab' ? '
    recentTabs.forEach(function (btn) { if (btn.getAttribute("data-recent-tab") === "lab") btn.click(); });
' : '') . '
});
';

include 'includes/header.php';
require __DIR__ . '/includes/nurse_clinical_view.php';
include 'includes/footer.php';

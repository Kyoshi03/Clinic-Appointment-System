<?php
/**
 * Read-only patient profile for admin, receptionist, nurse, and doctor.
 */
require_once 'includes/session.php';
require_once 'config/database.php';
require_once 'includes/patient_profile_photo.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$allowedRoles = ['nurse', 'admin', 'receptionist', 'doctor'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles, true)) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['user_role'];
$patientId = (int) ($_GET['id'] ?? 0);
if ($patientId <= 0) {
    header('Location: ' . ($role === 'nurse' ? 'nurse_patients.php' : ($role === 'admin' ? 'admin.php' : 'receptionist.php')));
    exit;
}

$conn = getDBConnection();
ensurePatientProfilePhotoColumn($conn);

$st = $conn->prepare("SELECT id, full_name, username, email, phone, gender, age, date_of_birth,
    civil_status, address, barangay, city, profile_photo, profile_updated_at,
    emergency_contact_name, emergency_contact_relationship, emergency_contact_number
    FROM users WHERE id = ? AND role = 'patient'");
$st->bind_param('i', $patientId);
$st->execute();
$patient = $st->get_result()->fetch_assoc();
$st->close();

if (!$patient) {
    $conn->close();
    header('Location: ' . ($role === 'nurse' ? 'nurse_patients.php' : ($role === 'admin' ? 'admin.php' : 'receptionist.php')));
    exit;
}

$ap = $conn->prepare("SELECT id, appointment_date, appointment_time, status, booking_type FROM appointments WHERE patient_id = ? ORDER BY appointment_date DESC, appointment_time DESC LIMIT 20");
$ap->bind_param('i', $patientId);
$ap->execute();
$appointments = $ap->get_result()->fetch_all(MYSQLI_ASSOC);
$ap->close();
$conn->close();

if ($role === 'nurse') {
    $backUrl = 'nurse_patients.php';
} elseif ($role === 'admin') {
    $backUrl = 'admin.php#user-directory';
} elseif ($role === 'doctor') {
    $backUrl = 'view_appointments.php';
} else {
    $backUrl = 'receptionist.php';
}

$pageTitle = 'Patient profile | Staff';
$additionalStyles = patientAvatarStyles() . '
.sp-wrap{max-width:900px;margin:28px auto;padding:0 20px 40px}
.sp-head{background:linear-gradient(135deg,#0077b6,#023e8a);color:#fff;border-radius:16px;padding:22px 24px;margin-bottom:18px}
.sp-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 18px rgba(0,0,0,.06);margin-bottom:14px}
.sp-grid{display:grid;grid-template-columns:140px 1fr;gap:8px 14px;font-size:.92rem}
.sp-grid span{color:#60727d;font-weight:700}
.sp-t{width:100%;border-collapse:collapse}.sp-t th,.sp-t td{padding:8px 6px;border-bottom:1px solid #eee;text-align:left}
.sp-t th{background:#f8f9fa;color:#023e8a}
a.sp-back{display:inline-block;margin-bottom:10px;color:#0077b6;font-weight:700;text-decoration:none}
';

include 'includes/header.php';
?>
<div class="sp-wrap">
    <a class="sp-back" href="<?php echo htmlspecialchars($backUrl); ?>">← Back</a>
    <div class="sp-head">
        <div class="pv-head-profile">
            <?php echo renderPatientAvatar($patient, ['size' => 'xl']); ?>
            <div>
                <h1 style="margin:0 0 6px"><?php echo htmlspecialchars($patient['full_name']); ?></h1>
                <p style="margin:0;opacity:.95"><?php echo htmlspecialchars($patient['username']); ?><?php echo !empty($patient['phone']) ? ' · ' . htmlspecialchars($patient['phone']) : ''; ?><?php echo !empty($patient['email']) ? ' · ' . htmlspecialchars($patient['email']) : ''; ?></p>
            </div>
        </div>
    </div>

    <div class="sp-card">
        <h2 style="margin:0 0 12px;color:#0077b6;font-size:1.1rem">Patient details</h2>
        <div class="sp-grid">
            <span>Gender</span><div><?php echo htmlspecialchars($patient['gender'] ?: '—'); ?></div>
            <span>Age</span><div><?php echo htmlspecialchars($patient['age'] ?: '—'); ?></div>
            <span>Date of birth</span><div><?php echo htmlspecialchars($patient['date_of_birth'] ?: '—'); ?></div>
            <span>Civil status</span><div><?php echo htmlspecialchars($patient['civil_status'] ?: '—'); ?></div>
            <span>Address</span><div><?php echo htmlspecialchars($patient['address'] ?: '—'); ?></div>
            <span>Barangay / City</span><div><?php echo htmlspecialchars(trim(($patient['barangay'] ?? '') . ', ' . ($patient['city'] ?? ''), ', ')); ?></div>
            <span>Emergency</span><div><?php echo htmlspecialchars($patient['emergency_contact_name'] ?: '—'); ?><?php echo !empty($patient['emergency_contact_number']) ? ' · ' . htmlspecialchars($patient['emergency_contact_number']) : ''; ?></div>
        </div>
    </div>

    <div class="sp-card">
        <h2 style="margin:0 0 12px;color:#0077b6;font-size:1.1rem">Appointments</h2>
        <?php if (empty($appointments)): ?>
            <p style="color:#666;margin:0">No appointments.</p>
        <?php else: ?>
            <table class="sp-t"><thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($appointments as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['appointment_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr((string) $a['appointment_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($a['booking_type'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($a['status']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </div>

    <?php if ($role === 'nurse'): ?>
        <a href="nurse_patient.php?id=<?php echo $patientId; ?>" style="display:inline-block;background:#0077b6;color:#fff;padding:12px 18px;border-radius:8px;font-weight:700;text-decoration:none">Open full nurse record</a>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

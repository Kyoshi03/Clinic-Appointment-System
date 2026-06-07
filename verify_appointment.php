<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'includes/session.php';
checkRole('patient');
require_once 'includes/appointment_booking.php';
require_once 'includes/patient_profile_photo.php';

$currentUser = getCurrentUser();
$verificationId = (int) ($_SESSION['pending_appointment_verification_id'] ?? 0);
if ($verificationId <= 0) {
    header('Location: book_appointment.php');
    exit();
}

$conn = getDBConnection();
$verification = appointment_get_verification($conn, (int) $currentUser['id'], $verificationId);
if (!$verification || $verification['used_at'] !== null) {
    unset($_SESSION['pending_appointment_verification_id']);
    $conn->close();
    header('Location: book_appointment.php');
    exit();
}

$error = '';
$success = isset($_GET['sent']) && $_GET['sent'] === '1'
    ? 'We sent a 6-digit appointment confirmation code to your email.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'cancel') {
        $cancel = $conn->prepare(
            'UPDATE appointment_booking_verifications
             SET used_at = NOW()
             WHERE id = ? AND patient_id = ? AND used_at IS NULL'
        );
        $patientId = (int) $currentUser['id'];
        $cancel->bind_param('ii', $verificationId, $patientId);
        $cancel->execute();
        $cancel->close();
        unset($_SESSION['pending_appointment_verification_id']);
        $conn->close();
        header('Location: book_appointment.php');
        exit();
    }

    if ($action === 'resend') {
        $result = appointment_resend_verification(
            $conn,
            (int) $currentUser['id'],
            $verificationId
        );
        if ($result['ok']) {
            $success = 'A new appointment confirmation code was sent to your email.';
            $verification = appointment_get_verification($conn, (int) $currentUser['id'], $verificationId);
        } else {
            $error = (string) $result['error'];
        }
    }

    if ($action === 'verify') {
        $result = appointment_verify_and_create(
            $conn,
            (int) $currentUser['id'],
            $verificationId,
            (string) ($_POST['code'] ?? '')
        );
        if (!$result['ok']) {
            $error = (string) $result['error'];
        } else {
            unset($_SESSION['pending_appointment_verification_id'], $_SESSION['lab_booking']);
            if (!$result['email_sent']) {
                $_SESSION['appointment_email_warning'] = 'Your appointment was saved, but the confirmation email could not be delivered. You can still view it in My Appointments.';
            }
            $appointmentId = (int) $result['appointment_id'];
            $conn->close();
            header('Location: book_appointment.php?booked=' . $appointmentId);
            exit();
        }
    }
}

$maskedEmail = appointment_mask_email((string) $verification['email']);
$conn->close();

$pageTitle = 'Confirm Appointment | Globalife Medical Laboratory & Polyclinic';
$additionalStyles = '
    body { background:#eef7fc; min-height:100vh; }
    .verify-booking-page { min-height:calc(100vh - 92px); display:grid; place-items:center; padding:34px 20px; box-sizing:border-box; }
    .verify-booking-card { width:min(100%,520px); background:#fff; border:1px solid #d8eaf3; border-radius:8px; padding:32px; box-shadow:0 18px 42px rgba(20,79,123,.14); text-align:center; box-sizing:border-box; }
    .verify-booking-logo { width:72px; height:72px; border-radius:50%; object-fit:cover; border:3px solid #48cae4; margin-bottom:14px; }
    .verify-booking-card h2 { margin:0 0 8px; color:#073b4c; font-size:1.7rem; }
    .verify-booking-card > p { color:#5e7380; line-height:1.6; margin:0 0 20px; }
    .email-chip { display:inline-block; background:#edf8fd; color:#00699b; border:1px solid #cbe8f5; border-radius:999px; padding:8px 14px; font-weight:700; margin-bottom:20px; }
    .notice { padding:12px 14px; border-radius:8px; margin-bottom:16px; text-align:left; line-height:1.45; }
    .notice.error { background:#fff0f0; border:1px solid #ffd0d0; color:#8c1d2b; }
    .notice.success { background:#eefaf2; border:1px solid #c7ead2; color:#17652b; }
    .code-input { width:100%; box-sizing:border-box; padding:15px; border:2px solid #cfe2ed; border-radius:8px; text-align:center; font-size:1.5rem; font-weight:800; letter-spacing:.35em; margin-bottom:14px; }
    .code-input:focus { outline:none; border-color:#0077b6; box-shadow:0 0 0 4px rgba(0,119,182,.12); }
    .primary-btn { width:100%; border:0; border-radius:8px; padding:14px 18px; background:#0077b6; color:#fff; font:inherit; font-weight:800; cursor:pointer; }
    .secondary-actions { display:flex; justify-content:center; gap:18px; flex-wrap:wrap; margin-top:18px; }
    .link-btn { border:0; background:transparent; color:#0077b6; padding:4px; font:inherit; font-weight:700; cursor:pointer; text-decoration:underline; }
    .expiry-note { color:#6a7d88; font-size:.88rem; margin-top:14px; line-height:1.5; }
';

include 'includes/header.php';
?>
<main class="verify-booking-page">
    <section class="verify-booking-card">
        <img src="globalife.png" alt="Globalife" class="verify-booking-logo">
        <h2>Confirm your appointment</h2>
        <p>Enter the code sent to your registered email. Your appointment will only be saved after successful verification.</p>
        <div class="email-chip"><?php echo htmlspecialchars($maskedEmail); ?></div>

        <?php if ($error): ?>
            <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="verify_appointment.php">
            <input type="hidden" name="action" value="verify">
            <input
                type="text"
                name="code"
                class="code-input"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]{6}"
                maxlength="6"
                placeholder="000000"
                aria-label="6-digit appointment confirmation code"
                required
                autofocus
            >
            <button type="submit" class="primary-btn">Verify and Submit Appointment</button>
        </form>

        <p class="expiry-note">The code expires after 10 minutes. Check your spam folder if it is not in your inbox.</p>

        <div class="secondary-actions">
            <form method="POST" action="verify_appointment.php">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="link-btn">Resend code</button>
            </form>
            <form method="POST" action="verify_appointment.php">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="link-btn">Review appointment</button>
            </form>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>


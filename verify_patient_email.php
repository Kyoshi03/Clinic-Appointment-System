<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'includes/session.php';
require_once 'config/database.php';
require_once 'includes/mailer.php';
require_once 'includes/admin_notifications.php';

if (empty($_SESSION['pending_patient_registration']['data'])) {
    header('Location: register_patient.php');
    exit();
}

$pending = &$_SESSION['pending_patient_registration'];
$patient = $pending['data'];
$error = '';
$success = isset($_GET['sent']) && $_GET['sent'] === '1'
    ? 'We sent a 6-digit verification code to your email.'
    : '';

function registration_mask_email(string $email): string {
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return $email;
    }
    $visible = substr($name, 0, min(2, strlen($name)));
    return $visible . str_repeat('*', max(1, strlen($name) - strlen($visible))) . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        unset($_SESSION['pending_patient_registration']);
        header('Location: register_patient.php');
        exit();
    }

    if ($action === 'resend') {
        $wait = 60 - (time() - (int) ($pending['sent_at'] ?? 0));
        if ($wait > 0) {
            $error = 'Please wait ' . $wait . ' seconds before requesting another code.';
        } else {
            $code = (string) random_int(100000, 999999);
            $sent = clinic_send_otp_email(
                (string) $patient['email'],
                (string) $patient['full_name'],
                $code,
                'registration'
            );

            if (!$sent['ok']) {
                $error = $sent['error'];
            } else {
                $pending['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
                $pending['expires_at'] = time() + 10 * 60;
                $pending['sent_at'] = time();
                $pending['attempts'] = 0;
                $success = 'A new verification code was sent to your email.';
            }
        }
    }

    if ($action === 'verify') {
        $code = trim($_POST['code'] ?? '');
        if (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the complete 6-digit verification code.';
        } elseif ((int) ($pending['expires_at'] ?? 0) < time()) {
            $error = 'This verification code has expired. Request a new code.';
        } elseif ((int) ($pending['attempts'] ?? 0) >= 5) {
            $error = 'Too many incorrect attempts. Request a new verification code.';
        } elseif (!password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
            $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
            $remaining = max(0, 5 - $pending['attempts']);
            $error = 'Incorrect verification code. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
        } else {
            $conn = getDBConnection();

            $username = (string) $patient['username'];
            $email = (string) $patient['email'];
            $check = $conn->prepare('SELECT id FROM users WHERE username = ? OR LOWER(email) = LOWER(?) LIMIT 1');
            $check->bind_param('ss', $username, $email);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if ($exists) {
                $conn->close();
                unset($_SESSION['pending_patient_registration']);
                $error = 'That username or email is already registered. Please start again.';
            } else {
                $passwordHash = (string) $patient['password'];
                $fullName = (string) $patient['full_name'];
                $role = (string) $patient['role'];
                $phone = (string) $patient['phone'];
                $gender = (string) $patient['gender'];
                $dateOfBirth = (string) $patient['date_of_birth'];
                $age = (int) $patient['age'];
                $civilStatus = (string) $patient['civil_status'];
                $address = (string) $patient['address'];
                $barangay = (string) $patient['barangay'];
                $city = (string) $patient['city'];
                $emergencyName = (string) $patient['emergency_contact_name'];
                $emergencyRelationship = (string) $patient['emergency_contact_relationship'];
                $emergencyNumber = (string) $patient['emergency_contact_number'];
                $insert = $conn->prepare(
                    'INSERT INTO users (
                        username, password, full_name, role, email, phone, gender,
                        date_of_birth, age, civil_status, address, barangay, city,
                        emergency_contact_name, emergency_contact_relationship,
                        emergency_contact_number
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->bind_param(
                    'ssssssssisssssss',
                    $username,
                    $passwordHash,
                    $fullName,
                    $role,
                    $email,
                    $phone,
                    $gender,
                    $dateOfBirth,
                    $age,
                    $civilStatus,
                    $address,
                    $barangay,
                    $city,
                    $emergencyName,
                    $emergencyRelationship,
                    $emergencyNumber
                );
                $created = $insert->execute();
                $newPatientId = $created ? (int) $insert->insert_id : 0;
                $insert->close();

                if (!$created) {
                    $conn->close();
                    $error = 'We could not create your account. Please try again.';
                } else {
                    $registrationSource = (string) ($patient['registration_source'] ?? '');
                    $notificationMessage = $fullName . ' created a verified patient account';
                    if ($registrationSource === 'walkin_qr') {
                        $notificationMessage .= ' using the reception desk QR code.';
                    } else {
                        $notificationMessage .= '.';
                    }
                    create_admin_notification(
                        $conn,
                        'patient_registration',
                        'New patient account',
                        $notificationMessage,
                        $newPatientId
                    );
                    $conn->close();
                    $_SESSION['patient_pending_welcome'] = true;
                    $_SESSION['patient_registration_success'] = [
                        'username' => $patient['username'],
                        'display_name' => $patient['full_name'],
                    ];
                    unset($_SESSION['pending_patient_registration']);
                    header('Location: register_patient.php?created=1');
                    exit();
                }
            }
        }
    }
}

$maskedEmail = registration_mask_email((string) $patient['email']);
$pageTitle = 'Verify Email | Globalife Medical Laboratory & Polyclinic';
$publicLoginHref = 'index.php#patient-login';
$publicSignUpHref = 'register_patient.php';
$additionalStyles = '
    body { background:#eef7fc; min-height:100vh; }
    .verify-page { min-height:calc(100vh - 92px); display:grid; place-items:center; padding:34px 20px; box-sizing:border-box; }
    .verify-card { width:min(100%,500px); background:#fff; border:1px solid #d8eaf3; border-radius:8px; padding:32px; box-shadow:0 18px 42px rgba(20,79,123,.14); text-align:center; box-sizing:border-box; }
    .verify-logo { width:70px; height:70px; border-radius:50%; object-fit:cover; border:3px solid #48cae4; margin-bottom:14px; }
    .verify-card h2 { margin:0 0 8px; color:#073b4c; font-size:1.65rem; }
    .verify-card > p { color:#5e7380; line-height:1.6; margin:0 0 20px; }
    .email-chip { display:inline-block; background:#edf8fd; color:#00699b; border:1px solid #cbe8f5; border-radius:999px; padding:8px 14px; font-weight:700; margin-bottom:20px; }
    .notice { padding:12px 14px; border-radius:8px; margin-bottom:16px; text-align:left; line-height:1.45; }
    .notice.error { background:#fff0f0; border:1px solid #ffd0d0; color:#8c1d2b; }
    .notice.success { background:#eefaf2; border:1px solid #c7ead2; color:#17652b; }
    .code-input { width:100%; box-sizing:border-box; padding:15px; border:2px solid #cfe2ed; border-radius:8px; text-align:center; font-size:1.5rem; font-weight:800; letter-spacing:.35em; margin-bottom:14px; }
    .code-input:focus { outline:none; border-color:#0077b6; box-shadow:0 0 0 4px rgba(0,119,182,.12); }
    .primary-btn { width:100%; border:0; border-radius:8px; padding:14px 18px; background:#0077b6; color:#fff; font:inherit; font-weight:800; cursor:pointer; }
    .secondary-actions { display:flex; justify-content:center; gap:18px; flex-wrap:wrap; margin-top:18px; }
    .link-btn { border:0; background:transparent; color:#0077b6; padding:4px; font:inherit; font-weight:700; cursor:pointer; text-decoration:underline; }
    .expiry-note { color:#6a7d88; font-size:.84rem; margin-top:14px; }
';

include 'includes/header.php';
?>
<main class="verify-page">
    <section class="verify-card">
        <img src="globalife.png" alt="Globalife" class="verify-logo">
        <h2>Verify your email</h2>
        <p>Enter the code sent to your email to finish creating your patient account.</p>
        <div class="email-chip"><?php echo htmlspecialchars($maskedEmail); ?></div>

        <?php if ($error): ?>
            <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="verify_patient_email.php">
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
                aria-label="6-digit verification code"
                required
                autofocus
            >
            <button type="submit" class="primary-btn">Verify and Create Account</button>
        </form>

        <p class="expiry-note">The code expires after 10 minutes. Check your spam folder if it is not in your inbox.</p>

        <div class="secondary-actions">
            <form method="POST" action="verify_patient_email.php">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="link-btn">Resend code</button>
            </form>
            <form method="POST" action="verify_patient_email.php">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="link-btn">Change email</button>
            </form>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>

<?php
if (!isset($pageTitle)) {
    $pageTitle = "Globalife Medical Laboratory & Polyclinic";
}
$currentUser = isLoggedIn() ? getCurrentUser() : null;
$headerLoginHref = $publicLoginHref ?? 'login.php';
$headerSignUpHref = $publicSignUpHref ?? 'register_patient.php';
$headerPatientPhotoUrl = $headerPatientPhotoUrl ?? null;
$headerPatientInitials = $headerPatientInitials ?? ($profileInitials ?? null);
$headerPatientDisplayName = $headerPatientDisplayName ?? null;
$headerBrandOnly = !empty($headerBrandOnly);
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$logoHref = 'index.php';
if ($currentPage === 'index.php') {
    $logoHref = '#home';
}
if (isLoggedIn()) {
    $logoHref = dashboardForRole($currentUser['role']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="globalife.png">
    <link rel="stylesheet" href="main.css">
    <link rel="stylesheet" href="birthday-picker.css">
    <?php if (isset($additionalHeadLinks)): ?>
        <?php echo $additionalHeadLinks; ?>
    <?php endif; ?>
    <?php if (isset($additionalStyles)): ?>
        <style><?php echo $additionalStyles; ?></style>
    <?php endif; ?>
    <style>
    /* Enhanced Professional Header */
    header {
        background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        color: #fff;
        padding: 0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        position: sticky;
        top: 0;
        z-index: 1000;
        transition: all 0.3s ease;
    }
    header.scrolled {
        padding: 5px 0;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.15);
    }
    .header-flex {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        padding: 18px 20px;
        max-width: 1400px;
        margin: 0 auto;
    }
    .header-flex.brand-only {
        justify-content: center;
        max-width: 900px;
        min-height: 88px;
    }
    .header-flex.brand-only .logo-section {
        text-align: center;
    }
    .logo-section {
        display: flex;
        align-items: center;
        gap: 15px;
        text-decoration: none;
        color: inherit;
        transition: transform 0.2s;
    }
    .logo-section:hover {
        transform: scale(1.02);
    }
    .logo-img {
        width: 55px;
        height: 55px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
        border: 3px solid rgba(255, 255, 255, 0.3);
        background: #fff;
        transition: all 0.3s ease;
    }
    .logo-img:hover {
        transform: scale(1.05) rotate(5deg);
        box-shadow: 0 6px 20px rgba(255, 255, 255, 0.3);
    }
    header h1 {
        font-size: 1.3rem;
        margin: 0;
        font-weight: 700;
        letter-spacing: -0.5px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    nav {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    nav.role-nurse {
        gap: 4px;
    }
    nav.role-nurse a {
        padding-right: 12px;
        padding-left: 12px;
        font-size: 0.88rem;
    }
    nav.role-nurse .logout-btn {
        padding-right: 18px;
        padding-left: 18px;
    }
    nav a {
        color: rgba(255, 255, 255, 0.95);
        text-decoration: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        position: relative;
        white-space: nowrap;
    }
    nav a::before {
        content: "";
        position: absolute;
        bottom: 5px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 2px;
        background: #90e0ef;
        transition: width 0.3s ease;
    }
    nav a:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-2px);
    }
    nav a:hover::before {
        width: 70%;
    }
    nav a.active {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }
    nav a.active::before {
        width: 70%;
    }
    .login-btn, .logout-btn, .signup-btn {
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        color: #fff;
        padding: 10px 24px;
        border-radius: 25px;
        font-weight: 600;
        text-decoration: none;
        border: 2px solid rgba(255, 255, 255, 0.3);
        transition: all 0.3s ease;
        cursor: pointer;
        font-size: 0.95rem;
        white-space: nowrap;
    }
    .login-btn:hover, .logout-btn:hover, .signup-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }
    .signup-btn {
        background: #fff;
        color: #023e8a;
        border-color: #fff;
    }
    .signup-btn:hover {
        background: #caf0f8;
        color: #023e8a;
        border-color: #caf0f8;
    }
    .logout-btn {
        background: rgba(217, 4, 41, 0.2);
        border-color: rgba(217, 4, 41, 0.4);
    }
    .logout-btn:hover {
        background: rgba(217, 4, 41, 0.3);
        border-color: rgba(217, 4, 41, 0.6);
    }
    .logout-confirm-overlay {
        position: fixed;
        inset: 0;
        z-index: 3000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(7, 59, 76, 0.62);
        opacity: 0;
        pointer-events: none;
        visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .logout-confirm-overlay.is-open {
        opacity: 1;
        pointer-events: auto;
        visibility: visible;
    }
    .logout-confirm-box {
        width: min(100%, 420px);
        box-sizing: border-box;
        background: #fff;
        border: 1px solid #dceef2;
        border-radius: 8px;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.24);
        padding: 28px;
        text-align: center;
    }
    .logout-confirm-icon {
        width: 58px;
        height: 58px;
        margin: 0 auto 16px;
        border-radius: 50%;
        background: #fff4e6;
        color: #c2410c;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.55rem;
        font-weight: 800;
    }
    .logout-confirm-box h2 {
        color: #073b4c;
        font-size: 1.35rem;
        margin: 0 0 8px;
    }
    .logout-confirm-box p {
        color: #51636d;
        line-height: 1.55;
        margin: 0 0 22px;
    }
    .logout-confirm-actions {
        display: flex;
        gap: 12px;
    }
    .logout-confirm-actions button {
        flex: 1;
        min-height: 44px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 800;
        font-family: inherit;
    }
    .logout-cancel-btn {
        background: #f0f7fa;
        color: #073b4c;
        border: 1px solid #cfe4e9;
    }
    .logout-confirm-btn {
        background: #d90429;
        color: #fff;
        border: 1px solid #d90429;
    }
    .logout-cancel-btn:hover {
        background: #e4f2f6;
    }
    .logout-confirm-btn:hover {
        background: #b00020;
    }
    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 16px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 25px;
        margin-right: 10px;
    }
    .user-avatar {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-weight: 700;
        font-size: 0.9rem;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }
    .user-avatar-photo {
        object-fit: cover;
        background: #fff;
    }
    .user-name {
        font-size: 0.9rem;
        font-weight: 600;
    }
    .mobile-menu-toggle {
        display: none;
        background: none;
        border: none;
        color: #fff;
        font-size: 1.8rem;
        cursor: pointer;
        padding: 5px 10px;
        border-radius: 5px;
        transition: background 0.2s;
    }
    .mobile-menu-toggle:hover {
        background: rgba(255, 255, 255, 0.1);
    }
    @media (max-width: 1180px) and (min-width: 901px) {
        .header-flex:has(nav.role-nurse) {
            gap: 12px;
            padding-right: 14px;
            padding-left: 14px;
        }
        .header-flex:has(nav.role-nurse) h1 {
            font-size: 1.05rem;
        }
        nav.role-nurse a {
            padding: 9px 8px;
            font-size: 0.8rem;
        }
        nav.role-nurse .logout-btn {
            padding: 9px 14px;
            font-size: 0.82rem;
        }
    }
    @media (max-width: 900px) {
        .mobile-menu-toggle {
            display: block;
        }
        nav {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            flex-direction: column;
            align-items: stretch;
            padding: 20px;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            transform: translateY(-100%);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        nav.active {
            transform: translateY(0);
            opacity: 1;
            visibility: visible;
        }
        nav a {
            padding: 12px 20px;
            border-radius: 8px;
        }
        header h1 {
            font-size: 1.1rem;
        }
        .logo-img {
            width: 45px;
            height: 45px;
        }
        .user-info {
            display: none;
        }
    }
    </style>
</head>
<body>
    <header id="mainHeader">
        <div class="header-flex<?php echo $headerBrandOnly ? ' brand-only' : ''; ?>">
            <a href="<?php echo htmlspecialchars($logoHref); ?>" class="logo-section" data-logo-home="<?php echo $currentPage === 'index.php' ? '1' : '0'; ?>">
                <img src="globalife.png" alt="Clinic Logo" class="logo-img">
                <h1>Globalife Medical Laboratory & Polyclinic</h1>
            </a>
            <?php if (!$headerBrandOnly): ?>
                <button class="mobile-menu-toggle" onclick="toggleMobileMenu()" aria-label="Open menu">☰</button>
                <nav id="mainNav" class="<?php echo isLoggedIn() ? 'role-' . htmlspecialchars((string) $currentUser['role']) : 'role-public'; ?>">
                <?php if (isLoggedIn()): ?>
                    <?php if ($currentUser['role'] === 'admin'): ?>
                        <a href="admin.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin.php' ? 'active' : ''; ?>">Dashboard</a>
                        <a href="admin_accounts.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_accounts.php' ? 'active' : ''; ?>">Accounts</a>
                        <a href="view_appointments.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">Appointments</a>
                        <a href="admin_lab_services.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_lab_services.php' ? 'active' : ''; ?>">Laboratory Services</a>
                        <a href="admin_doctors.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_doctors.php' ? 'active' : ''; ?>">Doctors &amp; Hours</a>
                        <a href="admin_clinic_setup.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_clinic_setup.php' ? 'active' : ''; ?>">Settings</a>
                    <?php elseif ($currentUser['role'] === 'doctor'): ?>
                        <a href="view_appointments.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">My appointments</a>
                        <a href="nurse_patients.php" class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['nurse_patients.php', 'nurse_patient.php'], true) ? 'active' : ''; ?>">Patients</a>
                    <?php elseif ($currentUser['role'] === 'nurse'): ?>
                        <a href="nurse.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'nurse.php' ? 'active' : ''; ?>">Dashboard</a>
                        <a href="view_appointments.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">Appointments</a>
                        <a href="nurse_patients.php" class="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['nurse_patients.php', 'nurse_patient.php'], true) ? 'active' : ''; ?>">Patients</a>
                        <a href="nurse_medical.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'nurse_medical.php' ? 'active' : ''; ?>">Medical Records</a>
                        <a href="nurse_lab.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'nurse_lab.php' ? 'active' : ''; ?>">Lab Results</a>
                    <?php elseif ($currentUser['role'] === 'receptionist'): ?>
                        <a href="receptionist.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'receptionist.php' ? 'active' : ''; ?>">Dashboard</a>
                        <a href="view_appointments.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">Appointments</a>
                        <a href="register_patient_receptionist.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'register_patient_receptionist.php' ? 'active' : ''; ?>">Patients</a>
                        <a href="receptionist_doctors.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'receptionist_doctors.php' ? 'active' : ''; ?>">Doctor schedules</a>
                        <a href="receptionist_lab_services.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'receptionist_lab_services.php' ? 'active' : ''; ?>">Laboratory Services</a>
                    <?php elseif ($currentUser['role'] === 'patient'): ?>
                        <div class="user-info">
                            <?php
                            $patientHeaderName = trim((string) ($headerPatientDisplayName ?? $currentUser['full_name']));
                            $patientHeaderFirstName = explode(' ', $patientHeaderName)[0] ?? 'Patient';
                            $patientHeaderInitials = trim((string) ($headerPatientInitials ?? strtoupper(substr($patientHeaderName, 0, 1))));
                            ?>
                            <?php if (!empty($headerPatientPhotoUrl)): ?>
                                <img src="<?php echo htmlspecialchars((string) $headerPatientPhotoUrl); ?>" alt="Profile photo" class="user-avatar user-avatar-photo">
                            <?php else: ?>
                                <div class="user-avatar"><?php echo htmlspecialchars($patientHeaderInitials); ?></div>
                            <?php endif; ?>
                            <span class="user-name"><?php echo htmlspecialchars($patientHeaderFirstName); ?></span>
                        </div>
                        <a href="patients.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'patients.php' ? 'active' : ''; ?>">Dashboard</a>
                        <a href="view_appointments.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">My Appointments</a>
                        <a href="book_appointment.php?start=1" class="<?php echo basename($_SERVER['PHP_SELF']) === 'book_appointment.php' ? 'active' : ''; ?>">Book Appointment</a>
                        <a href="patient_medical_records.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'patient_medical_records.php' ? 'active' : ''; ?>">My Clinic Records</a>
                    <?php endif; ?>
                    <form action="logout.php" method="post" style="display:inline;" class="logout-form">
                        <button type="submit" class="logout-btn">Log Out</button>
                    </form>
                <?php else: ?>
                    <a href="#home">Home</a>
                    <a href="#about">About</a>
                    <a href="#services">Services</a>
                    <a href="<?php echo htmlspecialchars($headerLoginHref); ?>" class="login-btn">Log In</a>
                    <a href="<?php echo htmlspecialchars($headerSignUpHref); ?>" class="signup-btn">Sign Up</a>
                <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <?php if (isLoggedIn()): ?>
        <div class="logout-confirm-overlay" id="logoutConfirm" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-hidden="true">
            <div class="logout-confirm-box">
                <div class="logout-confirm-icon" aria-hidden="true">?</div>
                <h2 id="logoutConfirmTitle">Are you sure you want to log out?</h2>
                <p>You will be returned to the homepage. Make sure any changes you need are saved before leaving.</p>
                <div class="logout-confirm-actions">
                    <button type="button" class="logout-cancel-btn" data-logout-cancel>Stay Logged In</button>
                    <button type="button" class="logout-confirm-btn" data-logout-confirm>Yes, Log Out</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script>
    function toggleMobileMenu() {
        const nav = document.getElementById('mainNav');
        if (nav) {
            nav.classList.toggle('active');
        }
    }
    document.addEventListener('click', function(event) {
        const nav = document.getElementById('mainNav');
        const toggle = document.querySelector('.mobile-menu-toggle');
        if (nav && toggle && !nav.contains(event.target) && !toggle.contains(event.target)) {
            nav.classList.remove('active');
        }
    });
    window.addEventListener('scroll', function() {
        const header = document.getElementById('mainHeader');
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });
    (function() {
        const logoutForms = document.querySelectorAll('.logout-form');
        const modal = document.getElementById('logoutConfirm');
        const cancelBtn = document.querySelector('[data-logout-cancel]');
        const confirmBtn = document.querySelector('[data-logout-confirm]');
        let pendingForm = null;

        function openLogoutConfirm(form) {
            pendingForm = form;
            if (!modal) {
                form.submit();
                return;
            }
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            if (confirmBtn) {
                confirmBtn.focus();
            }
        }

        function closeLogoutConfirm() {
            pendingForm = null;
            if (!modal) {
                return;
            }
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }

        logoutForms.forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (form.dataset.confirmed === 'true') {
                    return;
                }
                event.preventDefault();
                openLogoutConfirm(form);
            });
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeLogoutConfirm);
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                if (!pendingForm) {
                    closeLogoutConfirm();
                    return;
                }
                pendingForm.dataset.confirmed = 'true';
                pendingForm.submit();
            });
        }

        if (modal) {
            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeLogoutConfirm();
                }
            });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal && modal.classList.contains('is-open')) {
                closeLogoutConfirm();
            }
        });
    })();
    </script>

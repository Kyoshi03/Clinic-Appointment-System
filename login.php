<?php
require_once 'includes/session.php';

// If already logged in, redirect to appropriate dashboard
if (isLoggedIn()) {
    $user = getCurrentUser();
    switch ($user['role']) {
        case 'admin':
            header('Location: admin.php');
            break;
        case 'nurse':
            header('Location: nurse.php');
            break;
        case 'receptionist':
            header('Location: receptionist.php');
            break;
        case 'patient':
            header('Location: patients.php');
            break;
        default:
            header('Location: index.php');
    }
    exit();
}

$pageTitle = "Login | Globalife Medical Laboratory & Polyclinic";
$additionalStyles = '
    body {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    .login-selection-wrapper {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
        position: relative;
        overflow: hidden;
    }
    .login-selection-wrapper::before {
        content: "";
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 50px 50px;
        animation: float 20s infinite linear;
    }
    @keyframes float {
        0% { transform: translate(0, 0) rotate(0deg); }
        100% { transform: translate(-50px, -50px) rotate(360deg); }
    }
    .selection-container {
        max-width: 900px;
        width: 100%;
        padding: 50px 40px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        position: relative;
        z-index: 1;
        animation: slideUp 0.5s ease-out;
    }
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .selection-header {
        text-align: center;
        margin-bottom: 40px;
    }
    .selection-logo {
        width: 100px;
        height: 100px;
        margin: 0 auto 20px;
        border-radius: 50%;
        border: 4px solid #0077b6;
        box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
        object-fit: cover;
    }
    .selection-header h2 {
        color: #0077b6;
        margin: 0 0 10px 0;
        font-size: 2.5rem;
        font-weight: 700;
    }
    .selection-subtitle {
        color: #666;
        font-size: 1.1rem;
        margin: 0;
    }
    .login-options {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
        margin-top: 40px;
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
    }
    .login-option-card {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border: 2px solid #e0e0e0;
        border-radius: 15px;
        padding: 30px 20px;
        text-align: center;
        text-decoration: none;
        color: inherit;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    .login-option-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        transition: left 0.5s;
    }
    .login-option-card:hover::before {
        left: 100%;
    }
    .login-option-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        border-color: transparent;
    }
    .login-option-card.admin {
        border-top: 4px solid #d90429;
    }
    .login-option-card.admin:hover {
        background: linear-gradient(135deg, #d90429 0%, #c1121f 100%);
        color: #fff;
    }
    .login-option-card.nurse {
        border-top: 4px solid #0077b6;
    }
    .login-option-card.nurse:hover {
        background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        color: #fff;
    }
    .login-option-card.patient {
        border-top: 4px solid #48cae4;
    }
    .login-option-card.patient:hover {
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
    }
    .login-option-card.receptionist {
        border-top: 4px solid #90e0ef;
    }
    .login-option-card.receptionist:hover {
        background: linear-gradient(135deg, #90e0ef 0%, #48cae4 100%);
        color: #fff;
    }
    .option-icon {
        width: 60px;
        height: 60px;
        margin: 0 auto 15px;
        padding: 15px;
        border-radius: 50%;
        background: rgba(0, 119, 182, 0.1);
        transition: all 0.3s ease;
    }
    .login-option-card:hover .option-icon {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.1);
    }
    .login-option-card h3 {
        margin: 0 0 10px 0;
        font-size: 1.3rem;
        font-weight: 700;
        color: #333;
        transition: color 0.3s;
    }
    .login-option-card:hover h3 {
        color: #fff;
    }
    .login-option-card p {
        margin: 0;
        font-size: 0.9rem;
        color: #666;
        transition: color 0.3s;
    }
    .login-option-card:hover p {
        color: rgba(255, 255, 255, 0.9);
    }
    .back-to-home {
        text-align: center;
        margin-top: 40px;
        padding-top: 30px;
        border-top: 1px solid #e0e0e0;
    }
    .back-to-home a {
        color: #0077b6;
        text-decoration: none;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: color 0.2s;
        font-size: 1rem;
    }
    .back-to-home a:hover {
        color: #023e8a;
    }
    .back-icon {
        width: 20px;
        height: 20px;
    }
    @media (max-width: 768px) {
        .login-options {
            grid-template-columns: 1fr;
            max-width: 100%;
        }
        .selection-container {
            padding: 40px 30px;
            margin: 20px;
        }
        .selection-header h2 {
            font-size: 2rem;
        }
    }
';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="main.css">
    <style><?php echo $additionalStyles; ?></style>
</head>
<body>
    <div class="login-selection-wrapper">
        <div class="selection-container">
            <div class="selection-header">
                <img src="globalife.png" alt="Clinic Logo" class="selection-logo">
                <h2>Welcome to Globalife</h2>
                <p class="selection-subtitle">Select your login portal</p>
            </div>
            
            <div class="login-options">
                <a href="login_nurse.php" class="login-option-card nurse">
                    <div class="option-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                        </svg>
                    </div>
                    <h3>Nurse / Doctor</h3>
                    <p>Medical Portal</p>
                </a>
                
                <a href="login_receptionist.php" class="login-option-card receptionist">
                    <div class="option-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3>Receptionist</h3>
                    <p>Appointment Management</p>
                </a>
            </div>
            
            <div class="back-to-home">
                <a href="index.php">
                    <svg class="back-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Home
                </a>
            </div>
        </div>
    </div>
</body>
</html>

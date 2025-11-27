<?php
require_once 'includes/session.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        if (login($username, $password)) {
            $user = getCurrentUser();
            if ($user['role'] === 'patient') {
                header('Location: patients.php');
                exit();
            } else {
                logout();
                $error = 'Access denied. This login is for patients only.';
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$pageTitle = "Patient Login | Globalife Medical Laboratory & Polyclinic";
$additionalStyles = '
    body {
        background: linear-gradient(135deg, #90e0ef 0%, #48cae4 100%);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    .login-wrapper {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
        position: relative;
        overflow: hidden;
    }
    .login-wrapper::before {
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
    .login-container {
        max-width: 450px;
        width: 100%;
        padding: 50px 40px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        position: relative;
        z-index: 1;
        animation: slideUp 0.5s ease-out;
        border-top: 4px solid #48cae4;
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
    .login-header {
        text-align: center;
        margin-bottom: 35px;
    }
    .login-logo {
        width: 80px;
        height: 80px;
        margin: 0 auto 20px;
        border-radius: 50%;
        border: 4px solid #48cae4;
        box-shadow: 0 4px 15px rgba(72, 202, 228, 0.3);
        object-fit: cover;
    }
    .role-badge {
        display: inline-block;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 15px;
    }
    .login-container h2 {
        color: #48cae4;
        margin: 0 0 8px 0;
        font-size: 2rem;
        font-weight: 700;
    }
    .login-subtitle {
        color: #666;
        font-size: 0.95rem;
        margin: 0;
    }
    .form-group {
        margin-bottom: 25px;
        position: relative;
    }
    .form-group label {
        display: block;
        margin-bottom: 10px;
        color: #333;
        font-weight: 600;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    .input-icon {
        position: absolute;
        left: 15px;
        color: #48cae4;
        width: 20px;
        height: 20px;
        z-index: 2;
    }
    .password-toggle {
        position: absolute;
        right: 15px;
        color: #48cae4;
        width: 20px;
        height: 20px;
        z-index: 2;
        cursor: pointer;
        transition: color 0.2s;
    }
    .password-toggle:hover {
        color: #0077b6;
    }
    .form-group input {
        width: 100%;
        padding: 15px 15px 15px 50px;
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        font-size: 1rem;
        box-sizing: border-box;
        transition: all 0.3s ease;
        background: #f8f9fa;
    }
    .form-group input[type="password"],
    .form-group input[type="text"] {
        padding-right: 50px;
    }
    .form-group input:focus {
        outline: none;
        border-color: #48cae4;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(72, 202, 228, 0.1);
        transform: translateY(-2px);
    }
    .form-group input::placeholder {
        color: #999;
    }
    .error-message {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: #fff;
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        border-left: 4px solid #c33;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: shake 0.5s;
        box-shadow: 0 4px 15px rgba(238, 90, 111, 0.3);
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-10px); }
        75% { transform: translateX(10px); }
    }
    .error-icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }
    .remember-forgot {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        font-size: 0.9rem;
    }
    .remember-me {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .remember-me input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #48cae4;
    }
    .remember-me label {
        margin: 0;
        cursor: pointer;
        color: #666;
        font-weight: normal;
        text-transform: none;
        letter-spacing: 0;
    }
    .forgot-password {
        color: #48cae4;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.2s;
    }
    .forgot-password:hover {
        color: #0077b6;
        text-decoration: underline;
    }
    .login-btn-form {
        width: 100%;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        padding: 16px;
        border: none;
        border-radius: 12px;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(72, 202, 228, 0.4);
        text-transform: uppercase;
        letter-spacing: 1px;
        position: relative;
        overflow: hidden;
    }
    .login-btn-form::before {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.3);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
    }
    .login-btn-form:hover::before {
        width: 300px;
        height: 300px;
    }
    .login-btn-form:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(72, 202, 228, 0.5);
    }
    .login-btn-form:active {
        transform: translateY(0);
    }
    .login-btn-form span {
        position: relative;
        z-index: 1;
    }
    .back-to-home {
        text-align: center;
        margin-top: 25px;
        padding-top: 25px;
        border-top: 1px solid #e0e0e0;
    }
    .back-to-home a {
        color: #48cae4;
        text-decoration: none;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: color 0.2s;
    }
    .back-to-home a:hover {
        color: #0077b6;
    }
    .back-icon {
        width: 18px;
        height: 18px;
    }
    @media (max-width: 600px) {
        .login-container {
            padding: 40px 30px;
            margin: 20px;
        }
        .login-container h2 {
            font-size: 1.6rem;
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
    <div class="login-wrapper">
        <div class="login-container">
            <div class="login-header">
                <img src="globalife.png" alt="Clinic Logo" class="login-logo">
                <span class="role-badge">Patient</span>
                <h2>Patient Portal</h2>
                <p class="login-subtitle">Sign in to access your medical records</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <svg class="error-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login_patient.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <svg class="password-toggle" id="passwordToggle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                    </div>
                </div>
                
                <div class="remember-forgot">
                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>
                    <a href="#" class="forgot-password">Forgot Password?</a>
                </div>
                
                <button type="submit" class="login-btn-form">
                    <span>Sign In as Patient</span>
                </button>
            </form>
            
            <div class="login-link" style="text-align: center; margin-top: 20px; color: #666; font-size: 0.9rem;">
                Don't have an account? <a href="register_patient.php" style="color: #48cae4; text-decoration: none; font-weight: 600; transition: color 0.2s;">Register here</a>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const passwordToggle = document.getElementById('passwordToggle');
            
            // Eye open icon (show password)
            const eyeOpenIcon = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            `;
            
            // Eye closed icon (hide password)
            const eyeClosedIcon = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            `;
            
            passwordToggle.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    passwordToggle.innerHTML = eyeClosedIcon;
                } else {
                    passwordInput.type = 'password';
                    passwordToggle.innerHTML = eyeOpenIcon;
                }
            });
        });
    </script>
</body>
</html>


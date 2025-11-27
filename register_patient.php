<?php
require_once 'includes/session.php';
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $civil_status = trim($_POST['civil_status'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
    
    // Calculate age from date of birth
    $age = null;
    if (!empty($date_of_birth)) {
        $dob = new DateTime($date_of_birth);
        $today = new DateTime();
        $age = $today->diff($dob)->y;
    }
    
    // Password validation function
    function validatePassword($password) {
        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character (!@#$%^&*).';
        }
        return $errors;
    }
    
    // Validation
    if (empty($full_name) || empty($gender) || empty($date_of_birth) || empty($phone) || empty($email) || empty($barangay) || empty($city) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Validate password requirements
        $passwordErrors = validatePassword($password);
        if (!empty($passwordErrors)) {
            $error = implode(' ', $passwordErrors);
        } else {
        $conn = getDBConnection();
        
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Username already exists. Please choose a different username.';
            $stmt->close();
        } else {
            $stmt->close();
            
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Email already registered. Please use a different email.';
                $stmt->close();
            } else {
                $stmt->close();
                
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $role = 'patient';
                
                // Combine address
                $full_address = $address;
                if (!empty($barangay)) {
                    $full_address .= (!empty($full_address) ? ', ' : '') . $barangay;
                }
                if (!empty($city)) {
                    $full_address .= (!empty($full_address) ? ', ' : '') . $city;
                }
                
                // Insert new user
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, email, phone, gender, date_of_birth, age, civil_status, address, barangay, city, emergency_contact_name, emergency_contact_relationship, emergency_contact_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssssisssssss", $username, $hashed_password, $full_name, $role, $email, $phone, $gender, $date_of_birth, $age, $civil_status, $full_address, $barangay, $city, $emergency_contact_name, $emergency_contact_relationship, $emergency_contact_number);
                
                if ($stmt->execute()) {
                    $success = 'Registration successful! You can now login.';
                    // Clear form data on success
                    $_POST = array();
                } else {
                    $error = 'Registration failed. Please try again.';
                }
                
                $stmt->close();
            }
        }
        
        $conn->close();
        }
    }
}

$pageTitle = "Patient Registration | Globalife Medical Laboratory & Polyclinic";
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
        max-width: 600px;
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
        max-height: 90vh;
        overflow-y: auto;
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
        margin-bottom: 30px;
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
    .form-section {
        margin-bottom: 30px;
    }
    .form-section-title {
        color: #0077b6;
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e0e0e0;
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
    }
    .form-group {
        margin-bottom: 20px;
        position: relative;
    }
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .form-group label .optional {
        color: #999;
        font-weight: normal;
        text-transform: none;
        font-size: 0.75rem;
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
    .form-group input,
    .form-group select {
        width: 100%;
        padding: 12px 15px 12px 50px;
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        font-size: 1rem;
        box-sizing: border-box;
        transition: all 0.3s ease;
        background: #f8f9fa;
        font-family: inherit;
    }
    .form-group select {
        cursor: pointer;
    }
    .form-group input[type="password"] {
        padding-right: 50px;
    }
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #48cae4;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(72, 202, 228, 0.1);
        transform: translateY(-2px);
    }
    .form-group input::placeholder {
        color: #999;
    }
    .age-display {
        display: inline-block;
        margin-left: 10px;
        padding: 5px 12px;
        background: #48cae4;
        color: #fff;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .password-requirements {
        margin-top: 10px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 10px;
        border: 2px solid #e0e0e0;
    }
    .password-requirements-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #333;
        margin-bottom: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .password-requirement {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
        font-size: 0.85rem;
        color: #666;
        transition: all 0.3s ease;
    }
    .password-requirement:last-child {
        margin-bottom: 0;
    }
    .password-requirement.valid {
        color: #28a745;
    }
    .password-requirement.invalid {
        color: #dc3545;
    }
    .requirement-icon {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
    }
    .requirement-icon.valid {
        color: #28a745;
    }
    .requirement-icon.invalid {
        color: #dc3545;
    }
    .password-strength {
        margin-top: 10px;
        height: 4px;
        background: #e0e0e0;
        border-radius: 2px;
        overflow: hidden;
    }
    .password-strength-bar {
        height: 100%;
        width: 0%;
        transition: all 0.3s ease;
        border-radius: 2px;
    }
    .password-strength-bar.weak {
        width: 33%;
        background: #dc3545;
    }
    .password-strength-bar.medium {
        width: 66%;
        background: #ffc107;
    }
    .password-strength-bar.strong {
        width: 100%;
        background: #28a745;
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
    .success-message {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        color: #fff;
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        border-left: 4px solid #2f9e44;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 15px rgba(79, 207, 102, 0.3);
    }
    .error-icon, .success-icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
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
        margin-top: 10px;
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
        margin: 0 10px;
    }
    .back-to-home a:hover {
        color: #0077b6;
    }
    .back-icon {
        width: 18px;
        height: 18px;
    }
    .login-link {
        text-align: center;
        margin-top: 20px;
        color: #666;
        font-size: 0.9rem;
    }
    .login-link a {
        color: #48cae4;
        text-decoration: none;
        font-weight: 600;
        transition: color 0.2s;
    }
    .login-link a:hover {
        color: #0077b6;
        text-decoration: underline;
    }
    @media (max-width: 600px) {
        .login-container {
            padding: 40px 30px;
            margin: 20px;
        }
        .login-container h2 {
            font-size: 1.6rem;
        }
        .form-row {
            grid-template-columns: 1fr;
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
                <h2>Create Account</h2>
                <p class="login-subtitle">Register to access patient portal</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <svg class="error-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <svg class="success-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="register_patient.php" id="registerForm">
                <!-- Personal Information -->
                <div class="form-section">
                    <div class="form-section-title">Personal Information</div>
                    
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" required autofocus value="<?php echo (!empty($success) ? '' : (isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : '')); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gender">Sex / Gender</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <select id="gender" name="gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo (!empty($success) ? '' : ((isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : '')); ?>>Male</option>
                                    <option value="Female" <?php echo (!empty($success) ? '' : ((isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : '')); ?>>Female</option>
                                    <option value="Other" <?php echo (!empty($success) ? '' : ((isset($_POST['gender']) && $_POST['gender'] === 'Other') ? 'selected' : '')); ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth (DOB)</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <input type="date" id="date_of_birth" name="date_of_birth" required value="<?php echo (!empty($success) ? '' : (isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : '')); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="age">Age <span class="age-display" id="ageDisplay" style="display: none;">0 years old</span></label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <input type="text" id="age" name="age" placeholder="Auto-computed" readonly style="background: #e9ecef; cursor: not-allowed;">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="civil_status">Civil Status <span class="optional">(Optional)</span></label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                </svg>
                                <select id="civil_status" name="civil_status">
                                    <option value="">Select Civil Status</option>
                                    <option value="Single" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Single') ? 'selected' : '')); ?>>Single</option>
                                    <option value="Married" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Married') ? 'selected' : '')); ?>>Married</option>
                                    <option value="Divorced" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Divorced') ? 'selected' : '')); ?>>Divorced</option>
                                    <option value="Widowed" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Widowed') ? 'selected' : '')); ?>>Widowed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="form-section">
                    <div class="form-section-title">Contact Information</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Mobile Number</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                                <input type="tel" id="phone" name="phone" placeholder="Enter mobile number" required value="<?php echo (!empty($success) ? '' : (isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '')); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                <input type="email" id="email" name="email" placeholder="Enter your email" required value="<?php echo (!empty($success) ? '' : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '')); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <input type="text" id="address" name="address" placeholder="Street address (optional)" value="<?php echo (!empty($success) ? '' : (isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '')); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="barangay">Barangay</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                </svg>
                                <input type="text" id="barangay" name="barangay" placeholder="Enter barangay" required value="<?php echo (!empty($success) ? '' : (isset($_POST['barangay']) ? htmlspecialchars($_POST['barangay']) : '')); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="city">City</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                </svg>
                                <input type="text" id="city" name="city" placeholder="Enter city" required value="<?php echo (!empty($success) ? '' : (isset($_POST['city']) ? htmlspecialchars($_POST['city']) : '')); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Information -->
                <div class="form-section">
                    <div class="form-section-title">Account Information</div>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <input type="text" id="username" name="username" placeholder="Choose a username" required value="<?php echo (!empty($success) ? '' : (isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '')); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <input type="password" id="password" name="password" placeholder="Create a secure password" required>
                                <svg class="password-toggle" id="passwordToggle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                            <div class="password-requirements">
                                <div class="password-requirements-title">Password Requirements:</div>
                                <div class="password-requirement" id="req-length">
                                    <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <span>At least 8 characters</span>
                                </div>
                                <div class="password-requirement" id="req-uppercase">
                                    <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <span>One uppercase letter (A-Z)</span>
                                </div>
                                <div class="password-requirement" id="req-lowercase">
                                    <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <span>One lowercase letter (a-z)</span>
                                </div>
                                <div class="password-requirement" id="req-number">
                                    <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <span>One number (0-9)</span>
                                </div>
                                <div class="password-requirement" id="req-special">
                                    <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <span>One special character (!@#$%^&*)</span>
                                </div>
                            </div>
                            <div class="password-strength">
                                <div class="password-strength-bar" id="passwordStrengthBar"></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                                <svg class="password-toggle" id="confirmPasswordToggle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Emergency Contact -->
                <div class="form-section">
                    <div class="form-section-title">Emergency Contact <span style="color: #999; font-size: 0.85rem; font-weight: normal;">(Recommended)</span></div>
                    
                    <div class="form-group">
                        <label for="emergency_contact_name">Name</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" placeholder="Emergency contact full name" value="<?php echo (!empty($success) ? '' : (isset($_POST['emergency_contact_name']) ? htmlspecialchars($_POST['emergency_contact_name']) : '')); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_contact_relationship">Relationship</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                </svg>
                                <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" placeholder="e.g., Spouse, Parent, Sibling" value="<?php echo (!empty($success) ? '' : (isset($_POST['emergency_contact_relationship']) ? htmlspecialchars($_POST['emergency_contact_relationship']) : '')); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="emergency_contact_number">Contact Number</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                                <input type="tel" id="emergency_contact_number" name="emergency_contact_number" placeholder="Emergency contact number" value="<?php echo (!empty($success) ? '' : (isset($_POST['emergency_contact_number']) ? htmlspecialchars($_POST['emergency_contact_number']) : '')); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="login-btn-form">
                    <span>Create Account</span>
                </button>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="login_patient.php">Sign in here</a>
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
            // Clear form if registration was successful
            <?php if (!empty($success)): ?>
            const form = document.getElementById('registerForm');
            if (form) {
                form.reset();
                // Clear age display
                const ageDisplay = document.getElementById('ageDisplay');
                if (ageDisplay) {
                    ageDisplay.style.display = 'none';
                }
                const ageInput = document.getElementById('age');
                if (ageInput) {
                    ageInput.value = '';
                }
            }
            <?php endif; ?>
            
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const passwordToggle = document.getElementById('passwordToggle');
            const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
            const dateOfBirthInput = document.getElementById('date_of_birth');
            const ageInput = document.getElementById('age');
            const ageDisplay = document.getElementById('ageDisplay');
            
            // Calculate age from date of birth
            function calculateAge() {
                if (dateOfBirthInput.value) {
                    const dob = new Date(dateOfBirthInput.value);
                    const today = new Date();
                    let age = today.getFullYear() - dob.getFullYear();
                    const monthDiff = today.getMonth() - dob.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                        age--;
                    }
                    ageInput.value = age;
                    ageDisplay.textContent = age + ' years old';
                    ageDisplay.style.display = 'inline-block';
                } else {
                    ageInput.value = '';
                    ageDisplay.style.display = 'none';
                }
            }
            
            dateOfBirthInput.addEventListener('change', calculateAge);
            dateOfBirthInput.addEventListener('input', calculateAge);
            
            // Password validation and requirements checker
            function validatePasswordRequirements(password) {
                const requirements = {
                    length: password.length >= 8,
                    uppercase: /[A-Z]/.test(password),
                    lowercase: /[a-z]/.test(password),
                    number: /[0-9]/.test(password),
                    special: /[^A-Za-z0-9]/.test(password)
                };
                
                return requirements;
            }
            
            function updatePasswordRequirements(password) {
                const reqs = validatePasswordRequirements(password);
                
                // Update each requirement indicator
                updateRequirement('req-length', reqs.length);
                updateRequirement('req-uppercase', reqs.uppercase);
                updateRequirement('req-lowercase', reqs.lowercase);
                updateRequirement('req-number', reqs.number);
                updateRequirement('req-special', reqs.special);
                
                // Calculate password strength
                const validCount = Object.values(reqs).filter(v => v).length;
                const strengthBar = document.getElementById('passwordStrengthBar');
                
                if (password.length === 0) {
                    strengthBar.className = 'password-strength-bar';
                    strengthBar.style.width = '0%';
                } else if (validCount <= 2) {
                    strengthBar.className = 'password-strength-bar weak';
                } else if (validCount <= 4) {
                    strengthBar.className = 'password-strength-bar medium';
                } else {
                    strengthBar.className = 'password-strength-bar strong';
                }
            }
            
            function updateRequirement(id, isValid) {
                const req = document.getElementById(id);
                const icon = req.querySelector('.requirement-icon');
                
                if (isValid) {
                    req.classList.remove('invalid');
                    req.classList.add('valid');
                    icon.classList.remove('invalid');
                    icon.classList.add('valid');
                    icon.innerHTML = `
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    `;
                } else {
                    req.classList.remove('valid');
                    req.classList.add('invalid');
                    icon.classList.remove('valid');
                    icon.classList.add('invalid');
                    icon.innerHTML = `
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    `;
                }
            }
            
            // Real-time password validation
            passwordInput.addEventListener('input', function() {
                updatePasswordRequirements(passwordInput.value);
            });
            
            // Check password match in real-time
            confirmPasswordInput.addEventListener('input', function() {
                const passwordMatch = passwordInput.value === confirmPasswordInput.value;
                if (confirmPasswordInput.value.length > 0) {
                    if (passwordMatch) {
                        confirmPasswordInput.style.borderColor = '#28a745';
                    } else {
                        confirmPasswordInput.style.borderColor = '#dc3545';
                    }
                } else {
                    confirmPasswordInput.style.borderColor = '#e0e0e0';
                }
            });
            
            // Eye open icon (show password)
            const eyeOpenIcon = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            `;
            
            // Eye closed icon (hide password)
            const eyeClosedIcon = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            `;
            
            // Password toggle
            passwordToggle.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    passwordToggle.innerHTML = eyeClosedIcon;
                } else {
                    passwordInput.type = 'password';
                    passwordToggle.innerHTML = eyeOpenIcon;
                }
            });
            
            // Confirm password toggle
            confirmPasswordToggle.addEventListener('click', function() {
                if (confirmPasswordInput.type === 'password') {
                    confirmPasswordInput.type = 'text';
                    confirmPasswordToggle.innerHTML = eyeClosedIcon;
                } else {
                    confirmPasswordInput.type = 'password';
                    confirmPasswordToggle.innerHTML = eyeOpenIcon;
                }
            });
            
            // Form validation
            const form = document.getElementById('registerForm');
            form.addEventListener('submit', function(e) {
                const reqs = validatePasswordRequirements(passwordInput.value);
                const allValid = Object.values(reqs).every(v => v === true);
                
                if (!allValid) {
                    e.preventDefault();
                    alert('Please meet all password requirements before submitting.');
                    return false;
                }
                
                if (passwordInput.value !== confirmPasswordInput.value) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return false;
                }
            });
        });
    </script>
</body>
</html>

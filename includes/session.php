<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function dashboardForRole(string $role): string {
    switch ($role) {
        case 'admin':
            return 'admin.php';
        case 'nurse':
            return 'nurse.php';
        case 'doctor':
            return 'view_appointments.php';
        case 'receptionist':
            return 'receptionist.php';
        case 'patient':
            return 'patients.php';
        default:
            return 'index.php';
    }
}

function redirectToDashboardForCurrentUser(): void {
    if (!isLoggedIn()) {
        return;
    }

    header('Location: ' . dashboardForRole((string) $_SESSION['user_role']));
    exit();
}

// Check user role
function checkRole($requiredRole) {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
    
    if ($_SESSION['user_role'] !== $requiredRole) {
        header('Location: index.php');
        exit();
    }
}

/** Allow any of the given roles (e.g. nurse + doctor for clinical pages). */
function checkAnyRole(array $requiredRoles) {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
    if (!in_array($_SESSION['user_role'], $requiredRoles, true)) {
        header('Location: index.php');
        exit();
    }
}

// Get current user data
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role' => $_SESSION['user_role']
    ];
}

// Login function
function login($username, $password) {
    require_once __DIR__ . '/../config/database.php';
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT id, username, password, full_name, role, COALESCE(is_active, 1) AS is_active FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if ($user['role'] === 'doctor' && (int) $user['is_active'] !== 1) {
            $stmt->close();
            $conn->close();
            return false;
        }

        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            
            $stmt->close();
            $conn->close();
            return true;
        }
    }
    
    $stmt->close();
    $conn->close();
    return false;
}

// Logout function
function logout() {
    session_destroy();
    header('Location: index.php');
    exit();
}
?>


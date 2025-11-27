<?php
/**
 * Initialize default users for the clinic system
 * Run this file once to create default admin, nurse, receptionist, and patient accounts
 * Default password for all users: password123
 */

require_once 'config/database.php';

$conn = getDBConnection();

// Default password (password123) - in production, use stronger passwords
$defaultPassword = password_hash('password123', PASSWORD_DEFAULT);

// Default users
$users = [
    [
        'username' => 'admin',
        'password' => $defaultPassword,
        'full_name' => 'Administrator',
        'role' => 'admin',
        'email' => 'admin@globalife.com',
        'phone' => '09123456789'
    ],
    [
        'username' => 'nurse1',
        'password' => $defaultPassword,
        'full_name' => 'Dr. Maria Santos',
        'role' => 'nurse',
        'email' => 'nurse1@globalife.com',
        'phone' => '09123456790'
    ],
    [
        'username' => 'receptionist1',
        'password' => $defaultPassword,
        'full_name' => 'Receptionist User',
        'role' => 'receptionist',
        'email' => 'receptionist1@globalife.com',
        'phone' => '09123456791'
    ],
    [
        'username' => 'patient1',
        'password' => $defaultPassword,
        'full_name' => 'Junnie Abrador',
        'role' => 'patient',
        'email' => 'patient1@globalife.com',
        'phone' => '09123456792'
    ]
];

$stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, email, phone) VALUES (?, ?, ?, ?, ?, ?)");

foreach ($users as $user) {
    // Check if user already exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $checkStmt->bind_param("s", $user['username']);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->bind_param("ssssss", 
            $user['username'],
            $user['password'],
            $user['full_name'],
            $user['role'],
            $user['email'],
            $user['phone']
        );
        
        if ($stmt->execute()) {
            echo "User '{$user['username']}' created successfully.<br>";
        } else {
            echo "Error creating user '{$user['username']}': " . $stmt->error . "<br>";
        }
    } else {
        echo "User '{$user['username']}' already exists. Skipping.<br>";
    }
    
    $checkStmt->close();
}

$stmt->close();
$conn->close();

echo "<br><strong>Default users initialized!</strong><br>";
echo "All users have the password: <strong>password123</strong><br>";
echo "<br>You can now login with:<br>";
echo "- admin / password123 (Admin)<br>";
echo "- nurse1 / password123 (Nurse/Doctor)<br>";
echo "- receptionist1 / password123 (Receptionist)<br>";
echo "- patient1 / password123 (Patient)<br>";
?>


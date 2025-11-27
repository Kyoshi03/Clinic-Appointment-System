<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'clinic1_db');

// Create database connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

// Initialize database tables if they don't exist
function initDatabase() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    // Create database if it doesn't exist
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    $conn->select_db(DB_NAME);
    
    // Create users table
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        role ENUM('admin', 'nurse', 'receptionist', 'patient') NOT NULL,
        email VARCHAR(100),
        phone VARCHAR(20),
        gender ENUM('Male', 'Female', 'Other') DEFAULT NULL,
        date_of_birth DATE DEFAULT NULL,
        age INT DEFAULT NULL,
        civil_status VARCHAR(20) DEFAULT NULL,
        address VARCHAR(255) DEFAULT NULL,
        barangay VARCHAR(100) DEFAULT NULL,
        city VARCHAR(100) DEFAULT NULL,
        emergency_contact_name VARCHAR(100) DEFAULT NULL,
        emergency_contact_relationship VARCHAR(50) DEFAULT NULL,
        emergency_contact_number VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add new columns if they don't exist (for existing databases)
    $columns_to_add = [
        ['gender', "ENUM('Male', 'Female', 'Other') DEFAULT NULL", 'phone'],
        ['date_of_birth', 'DATE DEFAULT NULL', 'gender'],
        ['age', 'INT DEFAULT NULL', 'date_of_birth'],
        ['civil_status', 'VARCHAR(20) DEFAULT NULL', 'age'],
        ['address', 'VARCHAR(255) DEFAULT NULL', 'civil_status'],
        ['barangay', 'VARCHAR(100) DEFAULT NULL', 'address'],
        ['city', 'VARCHAR(100) DEFAULT NULL', 'barangay'],
        ['emergency_contact_name', 'VARCHAR(100) DEFAULT NULL', 'city'],
        ['emergency_contact_relationship', 'VARCHAR(50) DEFAULT NULL', 'emergency_contact_name'],
        ['emergency_contact_number', 'VARCHAR(20) DEFAULT NULL', 'emergency_contact_relationship']
    ];
    
    foreach ($columns_to_add as $col) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '{$col[0]}'");
        if ($check->num_rows == 0) {
            $after = !empty($col[2]) ? "AFTER {$col[2]}" : "";
            $conn->query("ALTER TABLE users ADD COLUMN {$col[0]} {$col[1]} {$after}");
        }
    }
    
    // Create appointments table
    $conn->query("CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        doctor_id INT,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    $conn->close();
}

// Call initDatabase on first load
initDatabase();
?>


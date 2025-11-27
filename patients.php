<?php
require_once 'includes/session.php';
checkRole('patient');

$pageTitle = "Patient Dashboard | Globalife Medical Laboratory & Polyclinic";
$currentUser = getCurrentUser();

// Get user details from database
require_once 'config/database.php';
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$result = $stmt->get_result();
$userDetails = $result->fetch_assoc();
$stmt->close();

// Get upcoming appointments
$stmt = $conn->prepare("SELECT * FROM appointments WHERE patient_id = ? AND status IN ('pending', 'confirmed') ORDER BY appointment_date ASC, appointment_time ASC LIMIT 3");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$additionalStyles = '
    body {
        background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%);
        min-height: 100vh;
    }
    .dashboard-wrapper {
        padding: 40px 20px;
        max-width: 1400px;
        margin: 0 auto;
    }
    .welcome-banner {
        background: linear-gradient(135deg, #0077b6 0%, #48cae4 100%);
        border-radius: 20px;
        padding: 40px;
        margin-bottom: 40px;
        color: #fff;
        box-shadow: 0 10px 40px rgba(0, 119, 182, 0.2);
        position: relative;
        overflow: hidden;
    }
    .welcome-banner::before {
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
    .welcome-content {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 20px;
    }
    .welcome-text h2 {
        margin: 0 0 10px 0;
        font-size: 2.5rem;
        font-weight: 800;
        text-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .welcome-text p {
        margin: 0;
        font-size: 1.1rem;
        opacity: 0.95;
    }
    .welcome-stats {
        display: flex;
        gap: 30px;
        flex-wrap: wrap;
    }
    .welcome-stat {
        text-align: center;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        padding: 20px 30px;
        border-radius: 15px;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }
    .welcome-stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 5px;
        line-height: 1;
    }
    .welcome-stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
    }
    .dashboard-card {
        background: #fff;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        border: 2px solid transparent;
        position: relative;
        overflow: hidden;
    }
    .dashboard-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 5px;
        background: linear-gradient(90deg, #0077b6 0%, #48cae4 100%);
    }
    .dashboard-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 40px rgba(0, 119, 182, 0.15);
        border-color: #0077b6;
    }
    .card-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }
    .card-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #0077b6 0%, #48cae4 100%);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
    }
    .card-icon svg {
        width: 28px;
        height: 28px;
    }
    .card-header h3 {
        margin: 0;
        color: #0077b6;
        font-size: 1.4rem;
        font-weight: 700;
    }
    .card-content {
        color: #666;
        line-height: 1.7;
        margin-bottom: 20px;
    }
    .card-content p {
        margin: 8px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .card-content strong {
        color: #333;
        min-width: 100px;
    }
    .dashboard-btn {
        background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        color: #fff;
        padding: 12px 28px;
        border-radius: 25px;
        text-decoration: none;
        font-size: 1rem;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
    }
    .dashboard-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
    }
    .dashboard-btn svg {
        width: 18px;
        height: 18px;
    }
    .appointments-section {
        background: #fff;
        border-radius: 20px;
        padding: 35px;
        box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
        margin-bottom: 40px;
    }
    .section-title {
        color: #0077b6;
        font-size: 1.8rem;
        font-weight: 700;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .section-title svg {
        width: 28px;
        height: 28px;
    }
    .appointments-list {
        display: grid;
        gap: 15px;
    }
    .appointment-item {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border: 2px solid #e0e0e0;
        border-radius: 15px;
        padding: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: all 0.3s ease;
    }
    .appointment-item:hover {
        border-color: #0077b6;
        transform: translateX(5px);
        box-shadow: 0 5px 20px rgba(0, 119, 182, 0.1);
    }
    .appointment-info {
        flex: 1;
    }
    .appointment-date {
        font-size: 1.3rem;
        font-weight: 700;
        color: #0077b6;
        margin-bottom: 5px;
    }
    .appointment-details {
        color: #666;
        font-size: 0.95rem;
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    .appointment-status {
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .appointment-status.pending {
        background: #fff3cd;
        color: #856404;
    }
    .appointment-status.confirmed {
        background: #d4edda;
        color: #155724;
    }
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }
    .empty-state svg {
        width: 80px;
        height: 80px;
        margin: 0 auto 20px;
        opacity: 0.3;
    }
    .empty-state h3 {
        color: #666;
        margin-bottom: 10px;
    }
    .quick-actions {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        margin-top: 30px;
    }
    .quick-action-btn {
        background: #fff;
        border: 2px solid #e0e0e0;
        border-radius: 15px;
        padding: 15px 25px;
        text-decoration: none;
        color: #0077b6;
        font-weight: 600;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 200px;
        justify-content: center;
    }
    .quick-action-btn:hover {
        border-color: #0077b6;
        background: #f8f9fa;
        transform: translateY(-3px);
        box-shadow: 0 5px 20px rgba(0, 119, 182, 0.1);
    }
    .quick-action-btn svg {
        width: 20px;
        height: 20px;
    }
    @media (max-width: 768px) {
        .welcome-content {
            flex-direction: column;
            text-align: center;
        }
        .welcome-text h2 {
            font-size: 2rem;
        }
        .welcome-stats {
            justify-content: center;
        }
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
        .appointment-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
    }
';

$additionalScripts = '
    function logout() {
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "logout.php";
        }
    }
';

include 'includes/header.php';
?>
    <div class="dashboard-wrapper">
        <div class="welcome-banner">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h2>Welcome back, <?php echo htmlspecialchars(explode(' ', $currentUser['full_name'])[0]); ?>! 👋</h2>
                    <p>Manage your appointments and access your medical records</p>
                </div>
                <div class="welcome-stats">
                    <div class="welcome-stat">
                        <div class="welcome-stat-number"><?php echo count($appointments); ?></div>
                        <div class="welcome-stat-label">Upcoming</div>
                    </div>
                    <div class="welcome-stat">
                        <div class="welcome-stat-number">24/7</div>
                        <div class="welcome-stat-label">Available</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3>My Appointments</h3>
                </div>
                <div class="card-content">
                    <p>View and manage your scheduled appointments</p>
                </div>
                <a href="#" class="dashboard-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    View All
                </a>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                    </div>
                    <h3>Book Appointment</h3>
                </div>
                <div class="card-content">
                    <p>Schedule a new appointment with our doctors</p>
                </div>
                <a href="#" class="dashboard-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Book Now
                </a>
            </div>

            <div class="dashboard-card">
                <div class="card-header">
                    <div class="card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3>Medical Records</h3>
                </div>
                <div class="card-content">
                    <p>Access your laboratory results and medical history</p>
                </div>
                <a href="#" class="dashboard-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    View Records
                </a>
            </div>
        </div>

        <div class="appointments-section">
            <div class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                Upcoming Appointments
            </div>
            
            <?php if (empty($appointments)): ?>
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <h3>No Upcoming Appointments</h3>
                    <p>You don't have any scheduled appointments yet.</p>
                    <a href="#" class="dashboard-btn" style="margin-top: 20px;">Book Your First Appointment</a>
                </div>
            <?php else: ?>
                <div class="appointments-list">
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                        $appDate = new DateTime($appointment['appointment_date']);
                        $formattedDate = $appDate->format('F d, Y');
                        $appTime = new DateTime($appointment['appointment_time']);
                        $formattedTime = $appTime->format('g:i A');
                        ?>
                        <div class="appointment-item">
                            <div class="appointment-info">
                                <div class="appointment-date"><?php echo $formattedDate; ?></div>
                                <div class="appointment-details">
                                    <span>🕐 <?php echo $formattedTime; ?></span>
                                    <span>👨‍⚕️ Doctor: TBD</span>
                                </div>
                            </div>
                            <span class="appointment-status <?php echo $appointment['status']; ?>">
                                <?php echo ucfirst($appointment['status']); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="quick-actions">
            <a href="#" class="quick-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                Update Profile
            </a>
            <a href="#" class="quick-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                </svg>
                Lab Results
            </a>
            <a href="#" class="quick-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                View History
            </a>
        </div>
    </div>
<?php include 'includes/footer.php'; ?>

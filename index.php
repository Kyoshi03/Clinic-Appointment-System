<?php
require_once 'includes/session.php';

$pageTitle = "Globalife Medical Appointment System";
$additionalStyles = '
    .header-flex {
        display: flex;
        align-items: center;
        gap: 18px;
    }
    .logo-img {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 2px 8px rgba(0,119,182,0.10);
        border: 2px solid #48cae4;
        background: #fff;
    }
    nav {
        display: flex;
        align-items: center;
    }
    .login-btn {
        background: #023e8a;
        padding: 7px 18px;
        border-radius: 4px;
        margin-left: 25px;
        font-weight: bold;
        color: #fff !important;
        text-decoration: none;
        transition: background 0.2s;
        display: flex;
        align-items: center;
        height: 40px;
    }
    .login-btn:hover {
        background: #0077b6;
        color: #90e0ef !important;
    }
    @media (max-width: 700px) {
        .header-flex {
            flex-direction: column;
            gap: 8px;
            align-items: flex-start;
        }
        nav {
            flex-direction: column;
            align-items: flex-start;
        }
        .login-btn {
            margin-left: 0;
            margin-top: 10px;
        }
    }
    .mission-vision {
        display: flex;
        flex-wrap: wrap;
        gap: 32px;
        margin-top: 30px;
    }
    .mission-vision > div {
        flex: 1 1 300px;
        background: #fff;
        border: 1px solid #90e0ef;
        border-radius: 10px;
        padding: 24px 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .mission-vision h4 {
        color: #0077b6;
        margin-top: 0;
    }
';

include 'includes/header.php';
?>
    <section class="hero">
        <div class="container">
            <h2>Welcome to Globalife Medical Laboratory & Polyclinic</h2>
            <p>Your health, our priority. Book your appointment online with ease.<br></p>
                <a href="login_patient.php" class="cta-btn">Book Appointment</a>
        </div>
    </section>
    <section id="about">
        <div class="container">
            <h3>About Us</h3>
            <p>
                Globalife Medical Laboratory & Polyclinic is here to provide quality and affordable healthcare for everyone in our community. We offer laboratory tests, medical check-ups, and treatments with the help of our caring and experienced doctors and staff. Our goal is to make sure every patient gets the best care in a friendly and
                comfortable environment because your health is our top priority.
            </p>
            <div class="mission-vision">
                <div>
                    <h4>MISSION</h4>
                    <p>
                        Our mission is to improve the health status of the community with a commitment of providing exemplary standards of clinical laboratory practice and efficiency to deliver reliable,
                        cost-effective and exceptional health care service while establishing mutual respect, professionalism, and teamwork, motivation and open
                        communication with our employees and clients.
                    </p>
                </div>
                <div>
                    <h4>VISION</h4>
                    <p>
                        the globalife vision is to be recognized as one of the nation's best health care institutions by providing new clinical and 
                        medical innovations, carrying out programs on patient's unique needs, and insuring the optimal level of service.
                    </p>
                </div>
            </div>
        </div>
    </section>
    <section id="services">
        <div class="container">
            <h3>Our Services</h3>
            <ul class="services-list">
                <li>General Consultation</li>
                <li>Laboratory Tests</li>
                <li>Home Healthcare Service</li>
                <li>Medical Services Provider</li>
                <li>Laboratory Pickup and Delivery Service</li>
            </ul>
        </div>
    </section>
<?php include 'includes/footer.php'; ?>


<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/admin_notifications.php';

$pageTitle = 'Accounts | Globalife Administration';
$conn = getDBConnection();
ensurePatientProfilePhotoColumn($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'add_staff') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $allowedRoles = ['admin', 'nurse', 'receptionist'];

    if ($fullName === '' || $username === '' || !in_array($role, $allowedRoles, true)) {
        $_SESSION['error'] = 'Complete the full name, username, and staff role.';
    } elseif (strlen($password) < 8) {
        $_SESSION['error'] = 'Use a password with at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $_SESSION['error'] = 'The passwords do not match.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Enter a valid email address.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'INSERT INTO users (username, password, full_name, role, email, phone, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->bind_param('ssssss', $username, $hash, $fullName, $role, $email, $phone);
        if ($stmt->execute()) {
            $newStaffId = (int) $stmt->insert_id;
            create_admin_notification(
                $conn,
                'staff_account_created',
                'New staff account',
                $fullName . ' was added as ' . ucfirst($role) . '.',
                $newStaffId
            );
            $_SESSION['success'] = 'Staff account created successfully.';
        } else {
            $_SESSION['error'] = $conn->errno === 1062
                ? 'That username is already in use.'
                : 'The staff account could not be created.';
        }
        $stmt->close();
    }

    $conn->close();
    header('Location: admin_accounts.php');
    exit();
}

$message = (string) ($_SESSION['success'] ?? '');
$error = (string) ($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

$counts = ['admin' => 0, 'doctor' => 0, 'nurse' => 0, 'receptionist' => 0, 'patient' => 0];
$countResult = $conn->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role');
while ($countResult && ($row = $countResult->fetch_assoc())) {
    if (isset($counts[$row['role']])) {
        $counts[$row['role']] = (int) $row['total'];
    }
}

$users = [];
$result = $conn->query(
    "SELECT id, username, full_name, role, email, phone, profile_photo,
            profile_updated_at, COALESCE(is_active, 1) AS is_active
     FROM users
     ORDER BY FIELD(role, 'admin', 'doctor', 'nurse', 'receptionist', 'patient'), full_name ASC"
);
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}
$conn->close();

$staffCount = $counts['admin'] + $counts['doctor'] + $counts['nurse'] + $counts['receptionist'];
$additionalStyles = patientAvatarStyles() . '
body{background:#f4f8fb;color:#1f343d}
.accounts-page{max-width:1180px;margin:0 auto;padding:28px 20px 48px}
.accounts-intro{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;padding-bottom:20px;border-bottom:1px solid #d7e4eb}
.accounts-intro h1{margin:0 0 7px;color:#073b4c;font-size:2rem}
.accounts-intro p{max-width:660px;margin:0;color:#607784;line-height:1.6}
.account-totals{display:grid;grid-template-columns:repeat(2,minmax(120px,1fr));gap:1px;overflow:hidden;border:1px solid #d4e3eb;border-radius:8px;background:#d4e3eb}
.account-total{min-width:135px;padding:12px 16px;background:#fff}
.account-total span{display:block;color:#657b88;font-size:.75rem;font-weight:900;text-transform:uppercase}
.account-total strong{display:block;margin-top:3px;color:#073b4c;font-size:1.35rem}
.notice{margin-top:16px;padding:13px 15px;border-radius:8px;font-weight:800}
.notice.ok{border:1px solid #bfe6ce;background:#edf9f1;color:#17643a}
.notice.error{border:1px solid #ffd0d5;background:#fff0f0;color:#9d1c2c}
.account-layout{display:grid;grid-template-columns:minmax(280px,.72fr) minmax(0,1.28fr);gap:16px;margin-top:20px}
.account-panel{border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06)}
.account-panel-head{padding:20px 20px 14px;border-bottom:1px solid #e1ebf0}
.account-panel-head h2{margin:0;color:#073b4c;font-size:1.2rem}
.account-panel-head p{margin:5px 0 0;color:#657b88;font-size:.9rem;line-height:1.5}
.staff-create-form{display:grid;gap:12px;padding:18px 20px 20px}
.field{display:grid;gap:6px}
.field label{color:#315466;font-size:.82rem;font-weight:900}
.field input,.field select,.directory-tools input,.directory-tools select{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cfe0e9;border-radius:7px;background:#fff;color:#183b4d;padding:9px 11px;font:inherit}
.field input:focus,.field select:focus,.directory-tools input:focus,.directory-tools select:focus{border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.1);outline:none}
.password-wrap{position:relative}
.password-wrap input{padding-right:62px}
.password-toggle{position:absolute;right:5px;top:5px;bottom:5px;border:0;border-radius:5px;background:#edf5f8;color:#075985;font:inherit;font-size:.78rem;font-weight:900;cursor:pointer}
.primary-btn{min-height:43px;border:0;border-radius:7px;background:#0f7cc2;color:#fff;font:inherit;font-weight:900;cursor:pointer}
.primary-btn:hover{background:#0b659f}
.account-guidance{margin:0 20px 20px;padding:13px;border-left:3px solid #0f7cc2;border-radius:6px;background:#f0f8fc;color:#456271;font-size:.86rem;line-height:1.55}
.account-guidance a{color:#0878b8;font-weight:900}
.directory-tools{display:grid;grid-template-columns:minmax(0,1fr) 190px;gap:10px;padding:14px 20px;border-bottom:1px solid #e1ebf0}
.account-list{display:grid}
.account-row{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:13px;align-items:center;padding:14px 20px;border-bottom:1px solid #e4edf2}
.account-row:last-child{border-bottom:0}
.account-row.hidden{display:none}
.account-avatar{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border:1px solid #c8dce7;border-radius:50%;background:#eaf5fa;color:#0878b8;font-weight:900;overflow:hidden}
.account-avatar img{width:100%;height:100%;object-fit:cover}
.account-name{color:#073b4c;font-weight:900}
.account-meta{display:flex;flex-wrap:wrap;gap:5px 12px;margin-top:5px;color:#667c88;font-size:.85rem}
.account-actions{display:flex;align-items:center;justify-content:flex-end;gap:7px}
.role-badge,.state-badge{display:inline-flex;align-items:center;min-height:27px;border-radius:14px;padding:4px 9px;font-size:.72rem;font-weight:900;text-transform:uppercase}
.role-badge{background:#e9f3f8;color:#315c70}
.state-badge.active{background:#e5f6eb;color:#17643a}
.state-badge.inactive{background:#fdecef;color:#a51220}
.edit-link{display:inline-flex;align-items:center;min-height:34px;border:1px solid #cde1ed;border-radius:6px;padding:6px 10px;background:#eef7fc;color:#075985;font-weight:900;text-decoration:none}
.empty-result{display:none;margin:18px 20px;padding:18px;border:1px dashed #c8dce6;border-radius:7px;color:#657b88;text-align:center}
.empty-result.show{display:block}
@media(max-width:900px){.accounts-intro{align-items:flex-start;flex-direction:column}.account-totals{width:100%}.account-layout{grid-template-columns:1fr}}
@media(max-width:620px){.accounts-page{padding:20px 13px 38px}.accounts-intro h1{font-size:1.65rem}.directory-tools{grid-template-columns:1fr}.account-row{grid-template-columns:auto minmax(0,1fr)}.account-actions{grid-column:1/-1;justify-content:flex-start;padding-left:55px}.account-meta{display:grid;gap:4px}}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const search = document.getElementById("accountSearch");
    const role = document.getElementById("accountRole");
    const empty = document.getElementById("accountNoMatches");
    const rows = Array.from(document.querySelectorAll("[data-account-row]"));

    function filterAccounts() {
        const query = (search.value || "").toLowerCase().trim();
        const selectedRole = role.value;
        let visible = 0;
        rows.forEach(function (row) {
            const matchesText = !query || (row.getAttribute("data-search") || "").toLowerCase().includes(query);
            const matchesRole = !selectedRole || row.getAttribute("data-role") === selectedRole;
            const show = matchesText && matchesRole;
            row.classList.toggle("hidden", !show);
            if (show) visible++;
        });
        empty.classList.toggle("show", visible === 0);
    }

    search.addEventListener("input", filterAccounts);
    role.addEventListener("change", filterAccounts);

    document.querySelectorAll("[data-password-toggle]").forEach(function (button) {
        button.addEventListener("click", function () {
            const input = document.getElementById(button.getAttribute("data-password-toggle"));
            const hidden = input.type === "password";
            input.type = hidden ? "text" : "password";
            button.textContent = hidden ? "Hide" : "Show";
        });
    });
});
';

include 'includes/header.php';
?>
<main class="accounts-page">
    <section class="accounts-intro">
        <div>
            <h1>Accounts</h1>
            <p>Create clinic staff logins and find patient or staff accounts without crowding the administrator dashboard.</p>
        </div>
        <div class="account-totals" aria-label="Account totals">
            <div class="account-total"><span>Clinic staff</span><strong><?php echo $staffCount; ?></strong></div>
            <div class="account-total"><span>Patients</span><strong><?php echo $counts['patient']; ?></strong></div>
        </div>
    </section>

    <?php if ($message): ?><div class="notice ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="account-layout">
        <section class="account-panel">
            <div class="account-panel-head">
                <h2>Create Staff Account</h2>
                <p>For administrators, nurses, and receptionists.</p>
            </div>
            <form method="post" class="staff-create-form">
                <input type="hidden" name="account_action" value="add_staff">
                <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required autocomplete="name"></div>
                <div class="field">
                    <label for="role">Staff role</label>
                    <select id="role" name="role" required>
                        <option value="">Choose a role</option>
                        <option value="admin">Administrator</option>
                        <option value="nurse">Nurse</option>
                        <option value="receptionist">Receptionist</option>
                    </select>
                </div>
                <div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="username"></div>
                <div class="field">
                    <label for="staff_password">Temporary password</label>
                    <div class="password-wrap">
                        <input type="password" id="staff_password" name="password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" data-password-toggle="staff_password">Show</button>
                    </div>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm password</label>
                    <div class="password-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" data-password-toggle="confirm_password">Show</button>
                    </div>
                </div>
                <div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" autocomplete="email"></div>
                <div class="field"><label for="phone">Phone number</label><input id="phone" name="phone" autocomplete="tel"></div>
                <button type="submit" class="primary-btn">Create Staff Account</button>
            </form>
            <p class="account-guidance">Doctor accounts and clinic hours are managed in <a href="admin_doctors.php">Doctors &amp; Hours</a>. Patient accounts may be created by patients or the receptionist.</p>
        </section>

        <section class="account-panel">
            <div class="account-panel-head">
                <h2>Account Directory</h2>
                <p>Search all registered clinic staff and patients.</p>
            </div>
            <div class="directory-tools" role="search">
                <input type="search" id="accountSearch" placeholder="Search name, username, email, or phone" aria-label="Search accounts">
                <select id="accountRole" aria-label="Filter account role">
                    <option value="">All account types</option>
                    <option value="admin">Administrators</option>
                    <option value="doctor">Doctors</option>
                    <option value="nurse">Nurses</option>
                    <option value="receptionist">Receptionists</option>
                    <option value="patient">Patients</option>
                </select>
            </div>
            <div class="empty-result" id="accountNoMatches">No accounts match your search.</div>
            <div class="account-list">
                <?php foreach ($users as $user): ?>
                    <?php
                    $userRole = (string) $user['role'];
                    $active = (int) $user['is_active'] === 1;
                    $photoUrl = patientProfilePhotoUrl($user['profile_photo'] ?? null, $user['profile_updated_at'] ?? null);
                    $searchText = implode(' ', [
                        $user['full_name'] ?? '',
                        $user['username'] ?? '',
                        $user['email'] ?? '',
                        $user['phone'] ?? '',
                        $userRole,
                    ]);
                    ?>
                    <article class="account-row" data-account-row data-role="<?php echo htmlspecialchars($userRole); ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                        <div class="account-avatar">
                            <?php if ($photoUrl): ?>
                                <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="">
                            <?php else: ?>
                                <?php echo htmlspecialchars(patientProfileInitials((string) $user['full_name'])); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="account-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="account-meta">
                                <span><?php echo htmlspecialchars($user['username']); ?></span>
                                <?php if ($user['email']): ?><span><?php echo htmlspecialchars($user['email']); ?></span><?php endif; ?>
                                <?php if ($user['phone']): ?><span><?php echo htmlspecialchars($user['phone']); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="account-actions">
                            <span class="role-badge"><?php echo htmlspecialchars($userRole); ?></span>
                            <?php if ($userRole === 'doctor'): ?>
                                <span class="state-badge <?php echo $active ? 'active' : 'inactive'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
                                <a class="edit-link" href="admin_doctors.php?edit=<?php echo (int) $user['id']; ?>">Edit</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</main>
<?php include 'includes/footer.php'; ?>

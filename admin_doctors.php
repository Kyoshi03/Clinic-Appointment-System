<?php
require_once 'includes/session.php';
checkRole('admin');
require_once 'config/database.php';
require_once __DIR__ . '/includes/doctor_schedule.php';

$pageTitle = 'Doctors & clinic hours | Globalife';
$conn = getDBConnection();
$message = '';
$error = '';
$defaultDoctorPassword = 'password123';

function admin_doctor_unique_username(mysqli $conn, string $fullName): string {
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/', '.', $fullName), '.'));
    if ($base === '') {
        $base = 'doctor';
    }
    if (strpos($base, 'dr.') !== 0 && strpos($base, 'dra.') !== 0) {
        $base = 'dr.' . $base;
    }

    $candidate = $base;
    $suffix = 2;
    $check = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    while (true) {
        $check->bind_param('s', $candidate);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $check->close();
            return $candidate;
        }
        $candidate = $base . '.' . $suffix;
        $suffix++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['doctor_admin_action'] ?? '';
    if ($act === 'toggle_active') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id > 0) {
            $conn->query('UPDATE users SET is_active = 1 - COALESCE(is_active,1) WHERE id = ' . $id . " AND role = 'doctor'");
            $message = 'Doctor active status updated.';
        }
    } elseif ($act === 'save_slots') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $days = $_POST['slot_day'] ?? [];
        $starts = $_POST['slot_start'] ?? [];
        $ends = $_POST['slot_end'] ?? [];
        if ($id <= 0) {
            $error = 'Invalid doctor.';
        } else {
            $conn->begin_transaction();
            try {
                $del = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
                $del->bind_param('i', $id);
                $del->execute();
                $del->close();
                $ins = $conn->prepare('INSERT INTO doctor_availability (user_id, day_of_week, time_start, time_end) VALUES (?, ?, ?, ?)');
                $n = count($days);
                for ($i = 0; $i < $n; $i++) {
                    $d = (int) ($days[$i] ?? 0);
                    $ts = trim((string) ($starts[$i] ?? ''));
                    $te = trim((string) ($ends[$i] ?? ''));
                    if ($d < 1 || $d > 7 || $ts === '' || $te === '') continue;
                    if (strlen($ts) === 5) $ts .= ':00';
                    if (strlen($te) === 5) $te .= ':00';
                    $ins->bind_param('iiss', $id, $d, $ts, $te);
                    $ins->execute();
                }
                $ins->close();
                $conn->commit();
                $message = 'Clinic hours saved.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to save clinic hours.';
            }
        }
    } elseif ($act === 'save_profile') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['full_name'] ?? '');
        $spec = trim($_POST['specialty'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($id > 0 && $name !== '') {
            $st = $conn->prepare("UPDATE users SET full_name=?, specialty=?, email=?, phone=? WHERE id=? AND role='doctor'");
            $st->bind_param('ssssi', $name, $spec, $email, $phone, $id);
            if ($st->execute()) $message = 'Doctor profile updated.';
            $st->close();
        }
    } elseif ($act === 'add_doctor') {
        $name = trim($_POST['full_name'] ?? '');
        $spec = trim($_POST['specialty'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '') {
            $error = 'Full name is required.';
        } else {
            $u = admin_doctor_unique_username($conn, $name);
            $hash = password_hash($defaultDoctorPassword, PASSWORD_DEFAULT);
            $st = $conn->prepare("INSERT INTO users (username, password, full_name, role, specialty, email, phone, is_active) VALUES (?, ?, ?, 'doctor', ?, ?, ?, 1)");
            $st->bind_param('ssssss', $u, $hash, $name, $spec, $email, $phone);
            if ($st->execute()) $message = 'Doctor account created. Username: ' . $u . ' | Default password: ' . $defaultDoctorPassword;
            else $error = $conn->errno === 1062 ? 'Username already exists.' : 'Failed to create doctor.';
            $st->close();
        }
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editDoctor = null;
$editSlots = [];
if ($editId > 0) {
    $st = $conn->prepare("SELECT id, username, full_name, specialty, email, phone, COALESCE(is_active,1) AS is_active FROM users WHERE id = ? AND role='doctor'");
    $st->bind_param('i', $editId);
    $st->execute();
    $editDoctor = $st->get_result()->fetch_assoc();
    $st->close();
    if ($editDoctor) $editSlots = doctor_fetch_availability_slots($conn, $editId);
}
$doctors = $conn->query("SELECT id, username, full_name, specialty, COALESCE(is_active,1) AS is_active FROM users WHERE role='doctor' ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);
$activeCount = count(array_filter($doctors, static fn ($doctor) => (int) $doctor['is_active'] === 1));
$inactiveCount = count($doctors) - $activeCount;
$conn->close();

$additionalStyles = '
 body{background:radial-gradient(circle at top right,#dff4ff 0%,#f4f9fd 35%,#f7fbff 100%)}
 .ad-wrap{max-width:1120px;margin:30px auto;padding:0 20px 48px;}
 .ad-card{background:#fff;border:1px solid #e3edf8;border-radius:18px;padding:22px;box-shadow:0 10px 28px rgba(20,79,123,.1);margin-bottom:16px;}
 .ad-card h2{margin:0 0 12px;color:#0b4f80;font-size:1.25rem}
 table{width:100%;border-collapse:collapse}
 th,td{padding:11px 8px;border-bottom:1px solid #edf2f8;text-align:left;vertical-align:top}
 th{background:#f5f9fd;color:#0b4f80;font-size:.88rem;text-transform:uppercase;letter-spacing:.04em}
 .btn{background:linear-gradient(135deg,#0f7cc2,#0b4f80);color:#fff;border:none;padding:9px 12px;border-radius:10px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-size:.82rem}
 .btn.s{background:#6c7f91}
 .ok{background:#d4edda;color:#155724;padding:11px;border-radius:8px;margin-bottom:12px}
 .er{background:#f8d7da;color:#721c24;padding:11px;border-radius:8px;margin-bottom:12px}
 .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
 .status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 16px}
 .status-card{background:#fff;border:1px solid #e3edf8;border-radius:8px;padding:15px;box-shadow:0 8px 18px rgba(20,79,123,.08)}
 .status-card span{display:block;color:#60727d;font-weight:800;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em}
 .status-card strong{display:block;color:#0b4f80;font-size:1.9rem;line-height:1;margin-top:7px}
 .doctor-status{font-weight:800}.doctor-status.active{color:#17643a}.doctor-status.inactive{color:#9d1c2c}
 @media(max-width:760px){.grid{grid-template-columns:1fr}}
 @media(max-width:760px){.status-grid{grid-template-columns:1fr}}
 input,select{width:100%;padding:10px;border:1px solid #cfdeee;border-radius:9px;box-sizing:border-box}
 .slot-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;align-items:end;margin-bottom:10px;background:#f8fbff;border:1px solid #e3edf8;padding:10px;border-radius:10px}
 @media(max-width:760px){.slot-row{grid-template-columns:1fr}}
';

include 'includes/header.php';
?>
<div class="ad-wrap">
    <h1 style="color:#023e8a">Doctors & clinic hours</h1>
    <?php if ($message): ?><div class="ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="er"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="status-grid" aria-label="Doctor status summary">
        <div class="status-card"><span>Total doctors</span><strong><?php echo count($doctors); ?></strong></div>
        <div class="status-card"><span>Active</span><strong><?php echo $activeCount; ?></strong></div>
        <div class="status-card"><span>Inactive</span><strong><?php echo $inactiveCount; ?></strong></div>
    </div>

    <div class="ad-card">
        <h2>Doctors</h2>
        <table>
            <thead><tr><th>Name</th><th>Specialty</th><th>Active</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($doctors as $d): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($d['full_name']); ?></strong><br><small style="color:#666"><?php echo htmlspecialchars($d['username']); ?></small></td>
                        <td><?php echo htmlspecialchars(trim((string) ($d['specialty'] ?? '')) !== '' ? $d['specialty'] : 'No specialty'); ?></td>
                        <td><span class="doctor-status <?php echo (int)$d['is_active'] ? 'active' : 'inactive'; ?>"><?php echo (int)$d['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td>
                            <a class="btn s" href="admin_doctors.php?edit=<?php echo (int)$d['id']; ?>">Edit</a>
                            <form method="post" style="display:inline;margin-left:8px">
                                <input type="hidden" name="doctor_admin_action" value="toggle_active">
                                <input type="hidden" name="user_id" value="<?php echo (int)$d['id']; ?>">
                                <button class="btn s" type="submit"><?php echo (int)$d['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ad-card">
        <h2>Add doctor account</h2>
        <p style="color:#60727d;margin-top:-4px;">Username will be generated automatically. Default password: <strong><?php echo htmlspecialchars($defaultDoctorPassword); ?></strong></p>
        <form method="post">
            <input type="hidden" name="doctor_admin_action" value="add_doctor">
            <div class="grid">
                <div style="grid-column:1/-1"><label>Full name</label><input name="full_name" required></div>
                <div><label>Specialty</label><input name="specialty"></div>
                <div><label>Email</label><input type="email" name="email"></div>
                <div><label>Phone</label><input name="phone"></div>
            </div>
            <button class="btn" type="submit" style="margin-top:12px">Create doctor</button>
        </form>
    </div>

    <?php if ($editDoctor): ?>
    <div class="ad-card">
        <h2>Edit doctor: <?php echo htmlspecialchars($editDoctor['full_name']); ?></h2>
        <form method="post" style="margin-bottom:20px">
            <input type="hidden" name="doctor_admin_action" value="save_profile">
            <input type="hidden" name="user_id" value="<?php echo (int)$editDoctor['id']; ?>">
            <div class="grid">
                <div style="grid-column:1/-1"><label>Full name</label><input name="full_name" value="<?php echo htmlspecialchars($editDoctor['full_name']); ?>" required></div>
                <div><label>Specialty</label><input name="specialty" value="<?php echo htmlspecialchars($editDoctor['specialty'] ?? ''); ?>"></div>
                <div><label>Email</label><input name="email" value="<?php echo htmlspecialchars($editDoctor['email'] ?? ''); ?>"></div>
                <div><label>Phone</label><input name="phone" value="<?php echo htmlspecialchars($editDoctor['phone'] ?? ''); ?>"></div>
            </div>
            <button class="btn" type="submit" style="margin-top:12px">Save profile</button>
        </form>

        <form method="post">
            <input type="hidden" name="doctor_admin_action" value="save_slots">
            <input type="hidden" name="user_id" value="<?php echo (int)$editDoctor['id']; ?>">
            <h3 style="color:#023e8a">Clinic hours</h3>
            <div id="slotRows">
                <?php
                $slots = $editSlots;
                if (empty($slots)) $slots = [['day_of_week' => 1, 'time_start' => '09:00:00', 'time_end' => '12:00:00']];
                foreach ($slots as $sl):
                    $d = (int)$sl['day_of_week']; $ts = substr((string)$sl['time_start'],0,5); $te = substr((string)$sl['time_end'],0,5);
                ?>
                <div class="slot-row">
                    <div>
                        <label>Day</label>
                        <select name="slot_day[]">
                            <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $k=>$v): ?>
                                <option value="<?php echo $k; ?>" <?php echo $d===$k?'selected':''; ?>><?php echo $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Start</label><input type="time" name="slot_start[]" value="<?php echo htmlspecialchars($ts); ?>"></div>
                    <div><label>End</label><input type="time" name="slot_end[]" value="<?php echo htmlspecialchars($te); ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn s" onclick="(function(){var r=document.querySelector('#slotRows .slot-row');if(!r)return;var n=r.cloneNode(true);n.querySelectorAll('input').forEach(i=>i.value='');document.getElementById('slotRows').appendChild(n);})();">+ Add row</button>
            <button class="btn" type="submit">Save clinic hours</button>
            <a href="admin_doctors.php" class="btn s">Done</a>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>


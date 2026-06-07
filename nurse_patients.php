<?php
require_once 'includes/session.php';
checkRole('nurse');
require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$pageTitle = 'Search patients | Nurse';
$q = trim($_GET['q'] ?? '');
$rows = [];
$recentRows = [];
$conn = getDBConnection();

if ($q === '') {
    $recentStmt = $conn->prepare("SELECT id, full_name, username, phone, email, profile_photo, profile_updated_at FROM users WHERE role='patient' ORDER BY full_name ASC LIMIT 12");
    $recentStmt->execute();
    $recentRows = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentStmt->close();
} else {
    $like = '%' . $q . '%';
    $st = $conn->prepare("SELECT id, full_name, username, phone, email, profile_photo, profile_updated_at FROM users WHERE role='patient' AND (full_name LIKE ? OR username LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY full_name ASC LIMIT 100");
    $st->bind_param('ssss', $like, $like, $like, $like);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$conn->close();

function nurse_patient_action_buttons(int $patientId): string {
    $id = (int) $patientId;
    return '
      <div class="np-actions" aria-label="Patient actions">
        <a class="np-action primary" href="nurse_patient.php?id=' . $id . '#appointments">Appointment history</a>
        <a class="np-action" href="nurse_patient.php?id=' . $id . '#medical-records">Medical records</a>
        <a class="np-action" href="nurse_patient.php?id=' . $id . '#lab-results">Lab results</a>
        <a class="np-action add" href="nurse_patient.php?id=' . $id . '#add-medical-record">Add medical record</a>
        <a class="np-action add" href="nurse_patient.php?id=' . $id . '#add-lab-result">Add lab result</a>
      </div>';
}

$additionalStyles = patientAvatarStyles() . '
.np-wrap{max-width:980px;margin:30px auto;padding:0 20px 40px}
.np-hero{background:#073b4c;color:#fff;border-radius:10px;padding:22px;margin-bottom:16px;box-shadow:0 12px 30px rgba(7,59,76,.16)}
.np-hero h1{margin:0 0 6px;font-size:1.65rem;color:#fff}
.np-hero p{margin:0;color:rgba(255,255,255,.82);line-height:1.5}
.np-card{background:#fff;border:1px solid #dce8ef;border-radius:10px;padding:22px;box-shadow:0 10px 24px rgba(25,76,110,.06);margin-bottom:16px}
.np-search{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.np-search input{flex:1;min-width:220px;padding:12px;border:2px solid #e0e0e0;border-radius:10px}
.np-btn{background:#0077b6;color:#fff;border:none;padding:12px 18px;border-radius:10px;font-weight:700;cursor:pointer}
.np-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 12px}
.np-section-title h2{margin:0;color:#073b4c;font-size:1.05rem}
.np-list{list-style:none;padding:0;margin:0;display:grid;gap:10px}
.np-list li{display:grid;grid-template-columns:auto minmax(0,1fr);gap:12px;padding:14px;border:1px solid #e8eef3;border-radius:10px;background:#f9fcff}
.np-list a{color:#0077b6;font-weight:700;text-decoration:none}.np-list a:hover{text-decoration:underline}
.np-patient-main{min-width:0}
.np-patient-name{display:inline-block;color:#0077b6;font-weight:800;text-decoration:none;margin-bottom:3px}
.np-patient-meta{color:#666}
.np-actions{grid-column:1 / -1;display:flex;flex-wrap:wrap;gap:8px;margin-left:58px}
.np-action{display:inline-flex;align-items:center;justify-content:center;min-height:34px;border:1px solid #d4e6f5;border-radius:8px;padding:7px 10px;background:#fff;color:#0b4f80;font-size:.84rem;font-weight:800;text-decoration:none}
.np-action.primary{background:#0077b6;color:#fff;border-color:#0077b6}
.np-action.add{background:#e7f7ed;color:#17643a;border-color:#bfe6ce}
.np-empty{color:#60727d;margin:0}
@media(max-width:640px){.np-list li{grid-template-columns:1fr}.np-actions{margin-left:0}.np-action{width:100%}}
';

include 'includes/header.php';
?>
<div class="np-wrap">
  <section class="np-hero">
    <h1>Search patients</h1>
    <p>Type a name, username, phone, or email. When no search is entered, you will see a quick list of patients right away.</p>
  </section>

  <div class="np-card">
    <form class="np-search" method="get">
      <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name, username, phone, email">
      <button class="np-btn" type="submit">Search</button>
    </form>

    <?php if ($q === ''): ?>
      <div class="np-section-title">
        <h2>Quick list</h2>
      </div>
      <?php if (empty($recentRows)): ?>
        <p class="np-empty">No patient accounts found.</p>
      <?php else: ?>
        <ul class="np-list">
          <?php foreach ($recentRows as $r): ?>
            <li>
              <?php echo renderPatientAvatar($r, ['size' => 'md', 'link' => true, 'link_target' => 'nurse', 'patient_id' => (int) $r['id']]); ?>
              <div class="np-patient-main">
                <a class="np-patient-name" href="nurse_patient.php?id=<?php echo (int) $r['id']; ?>"><?php echo htmlspecialchars($r['full_name']); ?></a>
                <br><small class="np-patient-meta"><?php echo htmlspecialchars($r['username']); ?><?php echo !empty($r['phone']) ? ' · ' . htmlspecialchars($r['phone']) : ''; ?></small>
              </div>
              <?php echo nurse_patient_action_buttons((int) $r['id']); ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php elseif (empty($rows)): ?>
      <p class="np-empty">No matching patient.</p>
    <?php else: ?>
      <div class="np-section-title">
        <h2>Search results</h2>
      </div>
      <ul class="np-list">
        <?php foreach ($rows as $r): ?>
          <li>
            <?php echo renderPatientAvatar($r, ['size' => 'md', 'link' => true, 'link_target' => 'nurse', 'patient_id' => (int) $r['id']]); ?>
            <div class="np-patient-main">
                <a class="np-patient-name" href="nurse_patient.php?id=<?php echo (int) $r['id']; ?>"><?php echo htmlspecialchars($r['full_name']); ?></a>
                <br><small class="np-patient-meta"><?php echo htmlspecialchars($r['username']); ?><?php echo !empty($r['phone']) ? ' · ' . htmlspecialchars($r['phone']) : ''; ?></small>
              </div>
              <?php echo nurse_patient_action_buttons((int) $r['id']); ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php include 'includes/footer.php'; ?>



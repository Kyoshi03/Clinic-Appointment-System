<?php
require_once 'includes/session.php';
checkRole('admin');
require_once 'config/database.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';

$pageTitle = 'Lab services & packages | Globalife';
$conn = getDBConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['admin_svc_action'] ?? '';
    if ($act === 'import_catalog') {
        $n = lab_insert_catalog_rows($conn, lab_catalog_seed_rows());
        $message = "Imported {$n} new row(s).";
        lab_sync_categories_from_services($conn);
    } elseif ($act === 'delete_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare('DELETE FROM lab_services WHERE id = ?');
            $st->bind_param('i', $id);
            if ($st->execute()) $message = 'Service deleted.';
            else $error = 'Could not delete service.';
            $st->close();
        }
    } elseif ($act === 'toggle_active') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id > 0) {
            $conn->query('UPDATE lab_services SET is_active = 1 - is_active WHERE id = ' . $id);
            $message = 'Active status updated.';
        }
    } elseif ($act === 'save_service' || $act === 'add_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $included = trim($_POST['included_tests'] ?? '');
        $opd = (float) ($_POST['opd_price'] ?? 0);
        $homeRaw = $_POST['home_service_price'] ?? '';
        $home = ($homeRaw === '' || $homeRaw === null) ? null : (float) $homeRaw;
        $isPkg = isset($_POST['is_package']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $category === '') {
            $error = 'Name and category are required.';
        } else {
            if ($act === 'add_service') {
                if ($home === null) {
                    $st = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
                    $st->bind_param('ssssdii', $name, $category, $description, $included, $opd, $isPkg, $isActive);
                } else {
                    $st = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $st->bind_param('ssssddii', $name, $category, $description, $included, $opd, $home, $isPkg, $isActive);
                }
                if ($st->execute()) $message = 'Service added.';
                else $error = 'Could not add service.';
                $st->close();
            } else {
                if ($id <= 0) {
                    $error = 'Invalid service.';
                } else {
                    if ($home === null) {
                        $st = $conn->prepare('UPDATE lab_services SET name=?, category=?, description=?, included_tests=?, opd_price=?, home_service_price=NULL, is_package=?, is_active=? WHERE id=?');
                        $st->bind_param('ssssdiii', $name, $category, $description, $included, $opd, $isPkg, $isActive, $id);
                    } else {
                        $st = $conn->prepare('UPDATE lab_services SET name=?, category=?, description=?, included_tests=?, opd_price=?, home_service_price=?, is_package=?, is_active=? WHERE id=?');
                        $st->bind_param('ssssddiii', $name, $category, $description, $included, $opd, $home, $isPkg, $isActive, $id);
                    }
                    if ($st->execute()) $message = 'Service updated.';
                    else $error = 'Could not update service.';
                    $st->close();
                }
            }
            lab_sync_categories_from_services($conn);
        }
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $st = $conn->prepare('SELECT * FROM lab_services WHERE id = ?');
    $st->bind_param('i', $editId);
    $st->execute();
    $editRow = $st->get_result()->fetch_assoc();
    $st->close();
}

$q = trim($_GET['q'] ?? '');
$rows = $conn->query('SELECT * FROM lab_services ORDER BY is_package DESC, category, name')->fetch_all(MYSQLI_ASSOC);
$conn->close();

if ($q !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($q) {
        $blob = strtolower(($r['name'] ?? '') . ' ' . ($r['category'] ?? '') . ' ' . ($r['included_tests'] ?? '') . ' ' . ($r['description'] ?? ''));
        return strpos($blob, strtolower($q)) !== false;
    }));
}
$groupedPackages = lab_group_services_list(array_values(array_filter($rows, fn ($r) => !empty($r['is_package']))));
$groupedIndividuals = lab_group_services_list(array_values(array_filter($rows, fn ($r) => empty($r['is_package']))));

$additionalStyles = '
    .svc-wrap { max-width: 1100px; margin: 32px auto; padding: 0 20px 48px; }
    .svc-card { background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
    .svc-card h2 { margin: 0 0 12px; color: #0077b6; }
    .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width:700px){ .form-grid { grid-template-columns: 1fr; } }
    .form-grid input,.form-grid textarea{ width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; box-sizing:border-box; }
    .btn-svc { background:#0077b6; color:#fff; border:none; padding:10px 16px; border-radius:8px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
    .btn-svc.secondary{ background:#6c757d; }
    .msg-ok{ background:#d4edda;color:#155724;padding:12px;border-radius:8px;margin-bottom:14px; }
    .msg-err{ background:#f8d7da;color:#721c24;padding:12px;border-radius:8px;margin-bottom:14px; }
    table.svc-t{ width:100%; border-collapse:collapse; }
    table.svc-t th, table.svc-t td{ padding:10px 8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
    table.svc-t th{ background:#f8f9fa; color:#023e8a; }
';

include 'includes/header.php';
?>
<div class="svc-wrap">
    <h1 style="color:#023e8a;">Manage laboratory tests &amp; packages</h1>
    <?php if ($message): ?><div class="msg-ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg-err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="svc-card">
        <h2>Tools</h2>
        <form method="post" style="display:inline;" onsubmit="return confirm('Import catalog rows?')">
            <input type="hidden" name="admin_svc_action" value="import_catalog">
            <button type="submit" class="btn-svc secondary">Import missing catalog</button>
        </form>
        <form method="get" style="display:inline; margin-left:8px;">
            <input type="search" name="q" placeholder="Search services..." value="<?php echo htmlspecialchars($q); ?>" style="padding:10px;border:1px solid #ddd;border-radius:8px;">
            <button class="btn-svc" type="submit">Search</button>
            <?php if ($q !== ''): ?><a href="admin_lab_services.php" class="btn-svc secondary">Clear</a><?php endif; ?>
        </form>
    </div>

    <div class="svc-card">
        <h2><?php echo $editRow ? 'Edit service' : 'Add service'; ?></h2>
        <form method="post">
            <input type="hidden" name="admin_svc_action" value="<?php echo $editRow ? 'save_service' : 'add_service'; ?>">
            <?php if ($editRow): ?><input type="hidden" name="service_id" value="<?php echo (int) $editRow['id']; ?>"><?php endif; ?>
            <div class="form-grid">
                <div><input type="text" name="name" placeholder="Name" required value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>"></div>
                <div><input type="text" name="category" placeholder="Category" required value="<?php echo htmlspecialchars($editRow['category'] ?? ''); ?>"></div>
                <div style="grid-column:1/-1;"><textarea name="description" placeholder="Description"><?php echo htmlspecialchars($editRow['description'] ?? ''); ?></textarea></div>
                <div style="grid-column:1/-1;"><textarea name="included_tests" placeholder="Included tests"><?php echo htmlspecialchars($editRow['included_tests'] ?? ''); ?></textarea></div>
                <div><input type="number" step="0.01" min="0" name="opd_price" placeholder="OPD price" required value="<?php echo htmlspecialchars($editRow['opd_price'] ?? '0'); ?>"></div>
                <div><input type="number" step="0.01" min="0" name="home_service_price" placeholder="Home service price (optional)" value="<?php echo isset($editRow['home_service_price']) && $editRow['home_service_price'] !== null ? htmlspecialchars((string) $editRow['home_service_price']) : ''; ?>"></div>
            </div>
            <p><label><input type="checkbox" name="is_package" <?php echo !empty($editRow['is_package']) ? 'checked' : ''; ?>> Package deal</label></p>
            <p><label><input type="checkbox" name="is_active" <?php echo !isset($editRow['is_active']) || !empty($editRow['is_active']) ? 'checked' : ''; ?>> Active</label></p>
            <button type="submit" class="btn-svc"><?php echo $editRow ? 'Save changes' : 'Add service'; ?></button>
            <?php if ($editRow): ?><a href="admin_lab_services.php" class="btn-svc secondary">Cancel</a><?php endif; ?>
        </form>
    </div>

    <div class="svc-card">
        <h2>Package deals</h2>
        <?php if (empty($groupedPackages)): ?>
            <p style="color:#666;">No package rows.</p>
        <?php else: foreach ($groupedPackages as $cat => $list): ?>
            <h3 style="color:#023e8a;"><?php echo htmlspecialchars($cat); ?> (<?php echo count($list); ?>)</h3>
            <table class="svc-t">
                <thead><tr><th>Name</th><th>OPD</th><th>Home</th><th>Active</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $r): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['name']); ?></strong><?php if (!empty($r['included_tests'])): ?><br><small style="color:#666;"><?php echo htmlspecialchars($r['included_tests']); ?></small><?php endif; ?></td>
                        <td>₱<?php echo number_format((float) $r['opd_price'], 2); ?></td>
                        <td><?php echo $r['home_service_price'] !== null && $r['home_service_price'] !== '' ? '₱' . number_format((float) $r['home_service_price'], 2) : '—'; ?></td>
                        <td><?php echo !empty($r['is_active']) ? 'Yes' : 'No'; ?></td>
                        <td>
                            <a href="admin_lab_services.php?edit=<?php echo (int) $r['id']; ?>">Edit</a>
                            <form method="post" style="display:inline;margin-left:8px;">
                                <input type="hidden" name="admin_svc_action" value="toggle_active">
                                <input type="hidden" name="service_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" style="background:none;border:none;color:#0077b6;cursor:pointer;text-decoration:underline;padding:0;">Toggle</button>
                            </form>
                            <form method="post" style="display:inline;margin-left:8px;" onsubmit="return confirm('Delete service?')">
                                <input type="hidden" name="admin_svc_action" value="delete_service">
                                <input type="hidden" name="service_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" style="background:none;border:none;color:#c1121f;cursor:pointer;text-decoration:underline;padding:0;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; endif; ?>
    </div>

    <div class="svc-card">
        <h2>Individual laboratory tests</h2>
        <?php if (empty($groupedIndividuals)): ?>
            <p style="color:#666;">No individual rows.</p>
        <?php else: foreach ($groupedIndividuals as $cat => $list): ?>
            <h3 style="color:#023e8a;"><?php echo htmlspecialchars($cat); ?> (<?php echo count($list); ?>)</h3>
            <table class="svc-t">
                <thead><tr><th>Name</th><th>OPD</th><th>Home</th><th>Active</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $r): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['name']); ?></strong><?php if (!empty($r['included_tests'])): ?><br><small style="color:#666;"><?php echo htmlspecialchars($r['included_tests']); ?></small><?php endif; ?></td>
                        <td>₱<?php echo number_format((float) $r['opd_price'], 2); ?></td>
                        <td><?php echo $r['home_service_price'] !== null && $r['home_service_price'] !== '' ? '₱' . number_format((float) $r['home_service_price'], 2) : '—'; ?></td>
                        <td><?php echo !empty($r['is_active']) ? 'Yes' : 'No'; ?></td>
                        <td>
                            <a href="admin_lab_services.php?edit=<?php echo (int) $r['id']; ?>">Edit</a>
                            <form method="post" style="display:inline;margin-left:8px;">
                                <input type="hidden" name="admin_svc_action" value="toggle_active">
                                <input type="hidden" name="service_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" style="background:none;border:none;color:#0077b6;cursor:pointer;text-decoration:underline;padding:0;">Toggle</button>
                            </form>
                            <form method="post" style="display:inline;margin-left:8px;" onsubmit="return confirm('Delete service?')">
                                <input type="hidden" name="admin_svc_action" value="delete_service">
                                <input type="hidden" name="service_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" style="background:none;border:none;color:#c1121f;cursor:pointer;text-decoration:underline;padding:0;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php include 'includes/footer.php'; ?>


<?php
require_once 'includes/session.php';
checkRole('patient');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$currentUser = getCurrentUser();
$patientId = (int) $currentUser['id'];
$conn = getDBConnection();

$headerStmt = $conn->prepare('SELECT full_name, profile_photo, profile_updated_at FROM users WHERE id = ?');
$headerStmt->bind_param('i', $patientId);
$headerStmt->execute();
$patientHeaderDetails = $headerStmt->get_result()->fetch_assoc() ?: [];
$headerStmt->close();
$headerPatientPhotoUrl = patientProfilePhotoUrl(
    $patientHeaderDetails['profile_photo'] ?? null,
    $patientHeaderDetails['profile_updated_at'] ?? null
);
$headerPatientInitials = patientProfileInitials(
    $patientHeaderDetails['full_name'] ?? $currentUser['full_name']
);
$headerPatientDisplayName = $patientHeaderDetails['full_name'] ?? $currentUser['full_name'];

$ap = $conn->prepare(
    "SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
            d.full_name AS doctor_name
     FROM appointments a
     LEFT JOIN users d ON d.id = a.doctor_id AND d.role = 'doctor'
     WHERE a.patient_id = ?
     ORDER BY a.appointment_date DESC, a.appointment_time DESC
     LIMIT 100"
);
$ap->bind_param('i', $patientId);
$ap->execute();
$appointments = $ap->get_result()->fetch_all(MYSQLI_ASSOC);
$ap->close();

$mr = $conn->prepare(
    'SELECT m.title, m.content, m.created_at, u.full_name AS author_name, u.role AS author_role
     FROM medical_records m
     LEFT JOIN users u ON u.id = m.author_id
     WHERE m.patient_id = ?
     ORDER BY m.created_at DESC'
);
$mr->bind_param('i', $patientId);
$mr->execute();
$medicalRows = $mr->get_result()->fetch_all(MYSQLI_ASSOC);
$mr->close();

$lr = $conn->prepare(
    'SELECT l.test_name, l.result_text, l.result_date, l.created_at,
            u.full_name AS author_name, u.role AS author_role, ls.name AS catalog_name
     FROM lab_result_entries l
     LEFT JOIN users u ON u.id = l.author_id
     LEFT JOIN lab_services ls ON ls.id = l.lab_service_id
     WHERE l.patient_id = ?
     ORDER BY l.result_date DESC, l.created_at DESC'
);
$lr->bind_param('i', $patientId);
$lr->execute();
$labRows = $lr->get_result()->fetch_all(MYSQLI_ASSOC);
$lr->close();
$conn->close();

function clinical_author_label(?string $name, ?string $role): string {
    $name = trim((string) $name);
    if ($name === '') {
        return 'Clinic staff';
    }

    $role = strtolower((string) $role);
    if ($role === 'doctor') {
        return $name . ' (Doctor)';
    }
    if ($role === 'nurse') {
        return $name . ' (Nurse)';
    }
    return $name;
}

function patient_record_date(?string $date, string $format = 'M j, Y'): string {
    $timestamp = strtotime((string) $date);
    return $timestamp ? date($format, $timestamp) : 'Not available';
}

function patient_record_time(?string $time): string {
    $timestamp = strtotime((string) $time);
    return $timestamp ? date('g:i A', $timestamp) : 'Not available';
}

function patient_record_status(string $status): string {
    $status = strtolower(trim($status));
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true)
        ? $status
        : 'pending';
}

$pageTitle = 'My Clinic Records | Patient';
$additionalStyles = '
body{background:#f3f7fa;color:#183b4d}
.records-page{max-width:1180px;margin:0 auto;padding:26px 22px 50px}
.records-back{display:inline-flex;align-items:center;gap:7px;margin-bottom:16px;color:#0878b8;font-weight:800;text-decoration:none}
.records-back:hover{text-decoration:underline}
.records-intro{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;padding:26px 30px;border:0;border-radius:8px;background:#073b4c;box-shadow:0 14px 34px rgba(7,59,76,.18)}
.records-intro h1{margin:0 0 7px;color:#fff;font-size:2rem;line-height:1.18}
.records-intro p{max-width:640px;margin:0;color:rgba(255,255,255,.82);line-height:1.6}
.records-summary{display:grid;grid-template-columns:repeat(3,minmax(116px,1fr));gap:1px;overflow:hidden;border:1px solid #d4e3eb;border-radius:8px;background:#d4e3eb}
.record-stat{min-width:120px;padding:13px 16px;background:#fff}
.record-stat span{display:block;color:#657b88;font-size:.75rem;font-weight:800;text-transform:uppercase}
.record-stat strong{display:block;margin-top:3px;color:#073b4c;font-size:1.35rem}
.records-workspace{margin-top:20px;overflow:hidden;border:1px solid #d6e4eb;border-radius:8px;background:#fff;box-shadow:0 10px 28px rgba(25,76,110,.07)}
.records-tabs{display:flex;gap:4px;padding:10px;border-bottom:1px solid #dce8ee;background:#f7fafc}
.records-tab{display:flex;align-items:center;justify-content:center;gap:8px;min-height:42px;border:1px solid transparent;border-radius:6px;padding:9px 15px;background:transparent;color:#4d6877;font:inherit;font-size:.91rem;font-weight:800;cursor:pointer}
.records-tab:hover{background:#eaf4f9;color:#075c8c}
.records-tab.active{border-color:#bddbea;background:#fff;color:#006fae;box-shadow:0 2px 7px rgba(25,76,110,.08)}
.records-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:22px;border-radius:11px;padding:0 6px;background:#e5f1f7;color:#315c70;font-size:.75rem}
.records-tab.active .records-tab-count{background:#d9f1fb;color:#006b9e}
.records-panel{display:none;padding:0 24px 26px}
.records-panel.active{display:block}
.panel-heading{padding:22px 0 16px}
.panel-heading h2{margin:0;color:#073b4c;font-size:1.22rem}
.panel-heading p{margin:4px 0 0;color:#6a7f8b;font-size:.9rem}
.records-table-wrap{overflow-x:auto;border:1px solid #dce7ed;border-radius:7px}
.records-table{width:100%;min-width:720px;border-collapse:collapse;font-size:.91rem}
.records-table th,.records-table td{padding:13px 14px;border-bottom:1px solid #e4edf2;text-align:left;vertical-align:middle}
.records-table th{background:#f4f8fa;color:#456271;font-size:.75rem;font-weight:900;text-transform:uppercase}
.records-table tbody tr:last-child td{border-bottom:0}
.records-table tbody tr:hover{background:#f9fcfd}
.primary-cell{color:#073b4c;font-weight:800}
.muted-cell{color:#70828d}
.type-label{text-transform:capitalize}
.status-pill{display:inline-flex;align-items:center;min-height:28px;border-radius:14px;padding:5px 10px;font-size:.76rem;font-weight:900;text-transform:capitalize}
.status-pill.pending{background:#fff3cd;color:#805b00}
.status-pill.confirmed{background:#e3f5ea;color:#17643a}
.status-pill.completed{background:#e4f2fa;color:#075985}
.status-pill.cancelled{background:#fde8eb;color:#a51220}
.record-list{border-top:1px solid #e1ebf0}
.record-entry{display:grid;grid-template-columns:190px minmax(0,1fr);gap:24px;padding:20px 0;border-bottom:1px solid #e1ebf0}
.record-entry:last-child{border-bottom:0}
.record-entry-meta strong{display:block;color:#073b4c;font-size:.94rem}
.record-entry-meta span{display:block;margin-top:5px;color:#6a7f8b;font-size:.82rem;line-height:1.45}
.record-entry-content h3{margin:0 0 7px;color:#075985;font-size:1rem}
.record-entry-content p{margin:0;color:#405d6c;white-space:pre-wrap;line-height:1.65}
.record-empty{padding:48px 20px;text-align:center;border:1px dashed #c9dce6;border-radius:7px;background:#f8fbfc}
.record-empty-mark{display:flex;align-items:center;justify-content:center;width:48px;height:48px;margin:0 auto 13px;border-radius:50%;background:#e7f3f8;color:#0878b8;font-size:1.25rem;font-weight:900}
.record-empty h3{margin:0 0 6px;color:#073b4c;font-size:1.05rem}
.record-empty p{margin:0;color:#6a7f8b;line-height:1.55}
@media(max-width:900px){.records-intro{align-items:flex-start;flex-direction:column}.records-summary{width:100%}}
@media(max-width:680px){.records-page{padding:20px 14px 36px}.records-intro h1{font-size:1.65rem}.records-summary{grid-template-columns:1fr}.record-stat{display:flex;align-items:center;justify-content:space-between}.record-stat strong{margin:0}.records-tabs{display:grid;grid-template-columns:1fr}.records-tab{justify-content:space-between}.records-panel{padding:0 14px 20px}.record-entry{grid-template-columns:1fr;gap:10px}}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const tabs = Array.from(document.querySelectorAll("[data-record-tab]"));
    const panels = Array.from(document.querySelectorAll("[data-record-panel]"));

    function activateTab(name, updateHash) {
        const selected = tabs.find(function (tab) {
            return tab.getAttribute("data-record-tab") === name;
        }) || tabs[0];
        if (!selected) {
            return;
        }

        const selectedName = selected.getAttribute("data-record-tab");
        tabs.forEach(function (tab) {
            const active = tab === selected;
            tab.classList.toggle("active", active);
            tab.setAttribute("aria-selected", active ? "true" : "false");
            tab.setAttribute("tabindex", active ? "0" : "-1");
        });
        panels.forEach(function (panel) {
            panel.classList.toggle("active", panel.getAttribute("data-record-panel") === selectedName);
        });

        if (updateHash) {
            history.replaceState(null, "", "#" + selectedName);
        }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener("click", function () {
            activateTab(tab.getAttribute("data-record-tab"), true);
        });
        tab.addEventListener("keydown", function (event) {
            if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") {
                return;
            }
            event.preventDefault();
            const direction = event.key === "ArrowRight" ? 1 : -1;
            const nextIndex = (index + direction + tabs.length) % tabs.length;
            tabs[nextIndex].focus();
            activateTab(tabs[nextIndex].getAttribute("data-record-tab"), true);
        });
    });

    activateTab(window.location.hash.replace("#", "") || "appointments", false);
});
';

include 'includes/header.php';
?>
<main class="records-page">
    <a href="patients.php" class="records-back"><span aria-hidden="true">&larr;</span> Back to dashboard</a>

    <section class="records-intro" aria-labelledby="recordsTitle">
        <div>
            <h1 id="recordsTitle">My Clinic Records</h1>
            <p>Review your appointment history, notes from the clinic team, and laboratory results in one organized place.</p>
        </div>
        <div class="records-summary" aria-label="Record totals">
            <div class="record-stat"><span>Appointments</span><strong><?php echo count($appointments); ?></strong></div>
            <div class="record-stat"><span>Medical notes</span><strong><?php echo count($medicalRows); ?></strong></div>
            <div class="record-stat"><span>Lab results</span><strong><?php echo count($labRows); ?></strong></div>
        </div>
    </section>

    <section class="records-workspace">
        <div class="records-tabs" role="tablist" aria-label="Clinic record sections">
            <button type="button" class="records-tab active" role="tab" aria-selected="true" data-record-tab="appointments">
                <span>Appointment History</span><span class="records-tab-count"><?php echo count($appointments); ?></span>
            </button>
            <button type="button" class="records-tab" role="tab" aria-selected="false" tabindex="-1" data-record-tab="notes">
                <span>Medical Notes</span><span class="records-tab-count"><?php echo count($medicalRows); ?></span>
            </button>
            <button type="button" class="records-tab" role="tab" aria-selected="false" tabindex="-1" data-record-tab="laboratory">
                <span>Laboratory Results</span><span class="records-tab-count"><?php echo count($labRows); ?></span>
            </button>
        </div>

        <section class="records-panel active" role="tabpanel" data-record-panel="appointments">
            <div class="panel-heading">
                <h2>Appointment History</h2>
                <p>Your past, current, and upcoming clinic bookings.</p>
            </div>
            <?php if (empty($appointments)): ?>
                <div class="record-empty">
                    <span class="record-empty-mark" aria-hidden="true">A</span>
                    <h3>No appointments yet</h3>
                    <p>Your booked appointments will appear here.</p>
                </div>
            <?php else: ?>
                <div class="records-table-wrap">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Booking type</th>
                                <th>Assigned doctor</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appointment): ?>
                                <?php $status = patient_record_status((string) $appointment['status']); ?>
                                <tr>
                                    <td class="primary-cell"><?php echo htmlspecialchars(patient_record_date($appointment['appointment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars(patient_record_time($appointment['appointment_time'])); ?></td>
                                    <?php
                                    $recordBookingType = (string) ($appointment['booking_type'] ?? '');
                                    $recordBookingLabel = [
                                        'consultation' => 'Doctor consultation',
                                        'package' => 'Laboratory package',
                                        'individual' => 'Laboratory tests',
                                    ][$recordBookingType] ?? 'General appointment';
                                    ?>
                                    <td class="type-label"><?php echo htmlspecialchars($recordBookingLabel); ?></td>
                                    <td class="<?php echo empty($appointment['doctor_name']) ? 'muted-cell' : ''; ?>">
                                        <?php echo htmlspecialchars($appointment['doctor_name'] ?: 'Not assigned'); ?>
                                    </td>
                                    <td><span class="status-pill <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="records-panel" role="tabpanel" data-record-panel="notes">
            <div class="panel-heading">
                <h2>Medical Notes</h2>
                <p>Clinical notes recorded by your nurse or doctor.</p>
            </div>
            <?php if (empty($medicalRows)): ?>
                <div class="record-empty">
                    <span class="record-empty-mark" aria-hidden="true">N</span>
                    <h3>No medical notes yet</h3>
                    <p>Notes added by the clinic team will appear here.</p>
                </div>
            <?php else: ?>
                <div class="record-list">
                    <?php foreach ($medicalRows as $record): ?>
                        <article class="record-entry">
                            <div class="record-entry-meta">
                                <strong><?php echo htmlspecialchars(patient_record_date($record['created_at'])); ?></strong>
                                <span><?php echo htmlspecialchars(clinical_author_label($record['author_name'] ?? '', $record['author_role'] ?? '')); ?></span>
                            </div>
                            <div class="record-entry-content">
                                <h3><?php echo htmlspecialchars($record['title']); ?></h3>
                                <p><?php echo htmlspecialchars($record['content'] ?: 'No additional details were recorded.'); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="records-panel" role="tabpanel" data-record-panel="laboratory">
            <div class="panel-heading">
                <h2>Laboratory Results</h2>
                <p>Results released and recorded by the clinic team.</p>
            </div>
            <?php if (empty($labRows)): ?>
                <div class="record-empty">
                    <span class="record-empty-mark" aria-hidden="true">L</span>
                    <h3>No laboratory results yet</h3>
                    <p>Your released laboratory results will appear here.</p>
                </div>
            <?php else: ?>
                <div class="record-list">
                    <?php foreach ($labRows as $result): ?>
                        <article class="record-entry">
                            <div class="record-entry-meta">
                                <strong><?php echo htmlspecialchars(patient_record_date($result['result_date'])); ?></strong>
                                <span><?php echo htmlspecialchars(clinical_author_label($result['author_name'] ?? '', $result['author_role'] ?? '')); ?></span>
                                <?php if (!empty($result['catalog_name'])): ?>
                                    <span><?php echo htmlspecialchars($result['catalog_name']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="record-entry-content">
                                <h3><?php echo htmlspecialchars($result['test_name']); ?></h3>
                                <p><?php echo htmlspecialchars($result['result_text'] ?: 'No result details were recorded.'); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
<?php include 'includes/footer.php'; ?>

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
.records-workspace{margin-top:20px;overflow:hidden;border:1px solid #d6e4eb;border-radius:8px;background:#fff;box-shadow:0 10px 28px rgba(25,76,110,.07)}
.records-tabs{display:flex;gap:4px;padding:10px;border-bottom:1px solid #dce8ee;background:#f7fafc}
.records-tab{display:flex;align-items:center;justify-content:center;gap:8px;min-height:42px;border:1px solid transparent;border-radius:6px;padding:9px 18px;background:transparent;color:#4d6877;font:inherit;font-size:.91rem;font-weight:800;cursor:pointer}
.records-tab:hover{background:#eaf4f9;color:#075c8c}
.records-tab.active{border-color:#bddbea;background:#fff;color:#006fae;box-shadow:0 2px 7px rgba(25,76,110,.08)}
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
.record-action{display:inline-flex;align-items:center;justify-content:center;min-height:34px;border:1px solid #bfe2f4;border-radius:7px;padding:7px 12px;background:#eaf7fd;color:#006fae;font-size:.8rem;font-weight:900;text-decoration:none;white-space:nowrap}
.record-action{cursor:pointer}
.record-action:hover{background:#d9f1fb}
.record-action.disabled{border-color:#e2ebf0;background:#f5f8fa;color:#6d818c;cursor:default}
.record-list{border-top:1px solid #e1ebf0}
.record-entry{display:grid;grid-template-columns:190px minmax(0,1fr);gap:24px;padding:18px 0;border-bottom:1px solid #e1ebf0}
.record-entry:last-child{border-bottom:0}
.record-entry-meta strong{display:block;color:#073b4c;font-size:.94rem}
.record-entry-meta span{display:block;margin-top:5px;color:#6a7f8b;font-size:.82rem;line-height:1.45}
.record-entry-content h3{margin:0 0 7px;color:#075985;font-size:1rem}
.record-entry-content p{margin:0;color:#405d6c;white-space:pre-wrap;line-height:1.65}
.record-details{border:1px solid #d9e8ef;border-radius:8px;background:#fbfdfe}
.record-details[open]{background:#fff}
.record-details summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;color:#073b4c;font-weight:900;cursor:pointer;list-style:none}
.record-details summary::-webkit-details-marker{display:none}
.record-details summary::after{content:"View details";flex:0 0 auto;border-radius:999px;background:#eaf7fd;color:#006fae;padding:6px 10px;font-size:.76rem;font-weight:900}
.record-details[open] summary::after{content:"Hide details"}
.record-details-body{padding:0 16px 16px}
.record-empty{padding:48px 20px;text-align:center;border:1px dashed #c9dce6;border-radius:7px;background:#f8fbfc}
.record-empty-mark{display:flex;align-items:center;justify-content:center;width:48px;height:48px;margin:0 auto 13px;border-radius:50%;background:#e7f3f8;color:#0878b8;font-size:1.25rem;font-weight:900}
.record-empty h3{margin:0 0 6px;color:#073b4c;font-size:1.05rem}
.record-empty p{margin:0;color:#6a7f8b;line-height:1.55}
.record-modal{position:fixed;inset:0;z-index:1200;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(7,39,54,.5);backdrop-filter:blur(4px)}
.record-modal.open{display:flex}
.record-modal-card{width:min(920px,100%);max-height:88vh;overflow:hidden;border-radius:12px;background:#fff;box-shadow:0 24px 70px rgba(7,39,54,.28)}
.record-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:22px 24px;background:linear-gradient(135deg,#073b4c,#0878b8);color:#fff}
.record-modal-kicker{margin:0 0 6px;color:#c7f3ff;font-size:.76rem;font-weight:900;text-transform:uppercase}
.record-modal-title{margin:0;font-size:1.55rem;line-height:1.2}
.record-modal-close{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border:0;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.45rem;font-weight:900;cursor:pointer}
.record-modal-close:hover{background:rgba(255,255,255,.26)}
.record-modal-body{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px;padding:20px;overflow:auto;max-height:calc(88vh - 98px)}
.record-modal-side{display:grid;gap:12px;align-content:start}
.record-modal-info{border:1px solid #d9e8ef;border-radius:10px;background:#f8fcfe;padding:16px}
.record-modal-info span{display:block;color:#6b808c;font-size:.73rem;font-weight:900;text-transform:uppercase}
.record-modal-info strong{display:block;margin-top:3px;color:#073b4c;font-size:1.03rem;line-height:1.35}
.record-modal-tabs{display:grid;gap:9px}
.record-modal-tab{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #d4e6ef;border-radius:10px;background:#fff;padding:12px 14px;color:#315c70;font:inherit;font-weight:900;text-align:left;cursor:pointer}
.record-modal-tab.active{border-color:#9fdaf1;background:#eaf8fe;color:#006fae}
.record-modal-tab:disabled{cursor:not-allowed;opacity:.6;background:#f5f8fa}
.record-modal-main{min-width:0;border:1px solid #d9e8ef;border-radius:10px;background:#fbfdfe;padding:16px}
.modal-record-panel{display:none}
.modal-record-panel.active{display:block}
.modal-record-panel h3{margin:0 0 12px;color:#073b4c;font-size:1.08rem}
.modal-record-card{border:1px solid #dce9ef;border-radius:9px;background:#fff;padding:14px;margin-bottom:12px}
.modal-record-card:last-child{margin-bottom:0}
.modal-record-card strong{display:block;color:#075985}
.modal-record-card span{display:block;margin-top:4px;color:#6b808c;font-size:.82rem}
.modal-record-card p{margin:10px 0 0;color:#405d6c;white-space:pre-wrap;line-height:1.6}
.modal-empty{display:grid;place-items:center;min-height:180px;border:1px dashed #c9dce6;border-radius:9px;background:#fff;text-align:center;color:#6b808c;padding:18px}
@media(max-width:900px){.records-intro{align-items:flex-start;flex-direction:column}}
@media(max-width:680px){.records-page{padding:20px 14px 36px}.records-intro h1{font-size:1.65rem}.records-tabs{display:grid;grid-template-columns:1fr}.records-tab{justify-content:flex-start}.records-panel{padding:0 14px 20px}.record-entry{grid-template-columns:1fr;gap:10px}.record-details summary{align-items:flex-start;flex-direction:column}.record-details summary::after{align-self:flex-start}.record-modal{padding:12px}.record-modal-body{grid-template-columns:1fr;max-height:calc(88vh - 92px);padding:14px}.record-modal-head{padding:18px}.record-modal-title{font-size:1.28rem}}

body{background:radial-gradient(circle at 88% 4%,rgba(72,202,228,.22),transparent 30%),linear-gradient(180deg,#eefaff 0%,#f7fbff 42%,#ffffff 100%);color:#10233f}
.records-page{max-width:1180px;padding:34px 22px 58px}
.records-back{margin-bottom:18px;color:#0077b6}
.records-intro{align-items:flex-start;border:1px solid #cde8f3;border-radius:18px;background:radial-gradient(circle at 88% 8%,rgba(72,202,228,.22),transparent 32%),linear-gradient(135deg,#ffffff 0%,#f3fbff 100%);box-shadow:0 18px 42px rgba(2,62,138,.08)}
.records-intro h1{color:#10233f;font-size:2rem}
.records-intro p{color:#60727d}
.records-kicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:10px;color:#0077b6;font-weight:950}
.records-kicker svg,.records-tab svg,.record-action svg,.record-modal-kicker svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2.3;stroke-linecap:round;stroke-linejoin:round}
.records-count-strip{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end}
.records-count-pill{display:grid;min-width:112px;padding:12px 14px;border:1px solid #d8eef7;border-radius:14px;background:#ffffff;color:#60727d;box-shadow:0 10px 24px rgba(2,62,138,.05)}
.records-count-pill strong{color:#0077b6;font-size:1.35rem;line-height:1}
.records-count-pill span{margin-top:5px;font-size:.72rem;font-weight:950;text-transform:uppercase}
.records-workspace{margin-top:18px;border-color:#cde8f3;border-radius:18px;box-shadow:0 16px 38px rgba(2,62,138,.08)}
.records-tabs{gap:10px;padding:14px;background:linear-gradient(135deg,#f8fdff,#eefaff)}
.records-tab{min-height:48px;border-color:#d8eef7;border-radius:14px;background:#fff;color:#315c70}
.records-tab:hover{border-color:#9bd2e9;background:#f0fbff;color:#0077b6}
.records-tab.active{border-color:#9bd2e9;background:#eaf8fc;color:#0077b6;box-shadow:0 10px 24px rgba(2,62,138,.06)}
.records-panel{padding:0 24px 28px}
.panel-heading{padding:24px 0 18px}
.panel-heading h2{color:#10233f;font-size:1.35rem}
.panel-heading p{color:#60727d;font-size:.94rem}
.records-table-wrap{border-color:#d8eef7;border-radius:16px;background:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.6)}
.records-table{font-size:.95rem}
.records-table th{background:#f3fbff;color:#60727d}
.records-table th,.records-table td{padding:16px;border-color:#e5f1f7}
.records-table tbody tr:hover{background:#f7fcff}
.primary-cell,.panel-heading h2,.record-entry-meta strong,.record-entry-content h3,.record-empty h3,.record-modal-info strong{color:#10233f}
.status-pill{min-height:30px;border-radius:999px;padding:6px 12px}
.status-pill.pending{background:#fff4d8;color:#8a5b00}
.status-pill.confirmed{background:#e8f8ef;color:#0f7a48}
.status-pill.completed{background:#eaf8fc;color:#0077b6}
.status-pill.cancelled{background:#fff1f2;color:#be123c}
.record-action{gap:7px;min-height:38px;border-color:#cde8f3;border-radius:12px;background:#eaf8fc;color:#0077b6}
.record-action:hover{border-color:#0077b6;background:#0077b6;color:#fff;text-decoration:none}
.record-list{display:grid;gap:12px;border-top:0}
.record-entry{grid-template-columns:170px minmax(0,1fr);border:1px solid #d8eef7;border-radius:16px;background:#fff;padding:16px;box-shadow:0 10px 24px rgba(2,62,138,.05)}
.record-details{border-color:#d8eef7;border-radius:14px}
.record-details summary::after{background:#eaf8fc;color:#0077b6}
.record-empty{border-color:#cde8f3;border-radius:16px;background:linear-gradient(135deg,#f8fdff,#ffffff)}
.record-empty-mark{background:#eaf8fc;color:#0077b6}
.record-modal{background:rgba(15,37,56,.48)}
.record-modal-card{border:1px solid #cde8f3;border-radius:22px}
.record-modal-head{background:radial-gradient(circle at 86% 20%,rgba(72,202,228,.22),transparent 34%),linear-gradient(135deg,#ffffff 0%,#eefaff 100%);color:#10233f;border-bottom:1px solid #d8eef7}
.record-modal-kicker{display:inline-flex;align-items:center;gap:8px;color:#0077b6}
.record-modal-close{background:#eaf8fc;color:#0077b6}
.record-modal-close:hover{background:#d8f2fb}
.record-modal-info,.record-modal-main{border-color:#d8eef7;background:#f8fdff}
.record-modal-tab{border-color:#d8eef7;border-radius:14px}
.record-modal-tab.active{border-color:#9bd2e9;background:#eaf8fc;color:#0077b6}
.modal-record-card{border-color:#d8eef7;border-radius:14px}
@media(max-width:900px){.records-intro{gap:18px}.records-count-strip{justify-content:flex-start}.records-count-pill{min-width:104px}}
@media(max-width:680px){.records-page{padding:18px 12px 96px}.records-intro{padding:22px 18px;border-radius:18px}.records-intro h1{font-size:1.7rem}.records-count-strip{width:100%;display:grid;grid-template-columns:1fr}.records-count-pill{min-width:0;padding:11px 10px}.records-tabs{grid-template-columns:1fr;gap:8px}.records-tab{justify-content:flex-start}.records-panel{padding:0 14px 22px}.records-table{min-width:760px}.record-entry{grid-template-columns:1fr}.record-modal-card{border-radius:18px}.record-modal-body{grid-template-columns:1fr}.record-modal-side{gap:10px}}
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

    const modal = document.querySelector("[data-record-modal]");
    const modalClose = document.querySelector("[data-record-modal-close]");
    const modalTitle = document.querySelector("[data-modal-title]");
    const modalType = document.querySelector("[data-modal-type]");
    const modalSchedule = document.querySelector("[data-modal-schedule]");
    const modalDoctor = document.querySelector("[data-modal-doctor]");
    const modalStatus = document.querySelector("[data-modal-status]");
    const modalTabs = Array.from(document.querySelectorAll("[data-modal-record-tab]"));
    const modalPanels = Array.from(document.querySelectorAll("[data-modal-record-panel]"));

    function activateModalPanel(name) {
        modalTabs.forEach(function (tab) {
            const active = tab.getAttribute("data-modal-record-tab") === name;
            tab.classList.toggle("active", active);
        });
        modalPanels.forEach(function (panel) {
            panel.classList.toggle("active", panel.getAttribute("data-modal-record-panel") === name);
        });
    }

    function closeModal() {
        if (!modal) {
            return;
        }
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    document.querySelectorAll("[data-appointment-details]").forEach(function (button) {
        button.addEventListener("click", function () {
            if (!modal) {
                return;
            }
            modalTitle.textContent = button.getAttribute("data-patient-title") || "Appointment details";
            modalType.textContent = button.getAttribute("data-booking-type") || "Clinic appointment";
            modalSchedule.textContent = button.getAttribute("data-schedule") || "Not available";
            modalDoctor.textContent = button.getAttribute("data-doctor") || "Not assigned";
            modalStatus.textContent = button.getAttribute("data-status") || "Pending";
            activateModalPanel(button.getAttribute("data-default-panel") || "notes");
            modal.classList.add("open");
            modal.setAttribute("aria-hidden", "false");
            document.body.style.overflow = "hidden";
        });
    });

    modalTabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
            if (tab.disabled) {
                return;
            }
            activateModalPanel(tab.getAttribute("data-modal-record-tab"));
        });
    });

    if (modalClose) {
        modalClose.addEventListener("click", closeModal);
    }
    if (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    }
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
        }
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
            <span class="records-kicker">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                Clinic records
            </span>
            <h1 id="recordsTitle">My Clinic Records</h1>
            <p>Review your appointment history, notes from the clinic team, and laboratory results in one organized place.</p>
        </div>
        <div class="records-count-strip" aria-label="Record totals">
            <span class="records-count-pill">
                <strong><?php echo count($appointments); ?></strong>
                <span>Appointments</span>
            </span>
        </div>
    </section>

    <section class="records-workspace">
        <div class="records-tabs" role="tablist" aria-label="Clinic record sections">
            <button type="button" class="records-tab active" role="tab" aria-selected="true" data-record-tab="appointments">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                <span>Appointment Record</span>
            </button>
            <button type="button" class="records-tab" role="tab" aria-selected="false" tabindex="-1" data-record-tab="notes">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6"/><path d="M9 11h2"/></svg>
                <span>Medical Notes</span>
            </button>
            <button type="button" class="records-tab" role="tab" aria-selected="false" tabindex="-1" data-record-tab="laboratory">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 2v7.31"/><path d="M14 9.3V2"/><path d="M8.5 2h7"/><path d="M14 9.3a6 6 0 1 1-4 0"/><path d="M8 15h8"/></svg>
                <span>Lab Results</span>
            </button>
        </div>

        <section class="records-panel active" role="tabpanel" data-record-panel="appointments">
            <div class="panel-heading">
                <h2>Appointment Record</h2>
                <p>Review each booking, its schedule, assigned doctor, and current status.</p>
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
                                <th>Record</th>
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
                                    $recordDefaultPanel = $recordBookingType === 'consultation' ? 'notes' : 'laboratory';
                                    $recordScheduleLabel = patient_record_date($appointment['appointment_date']) . ' at ' . patient_record_time($appointment['appointment_time']);
                                    ?>
                                    <td class="type-label"><?php echo htmlspecialchars($recordBookingLabel); ?></td>
                                    <td class="<?php echo empty($appointment['doctor_name']) ? 'muted-cell' : ''; ?>">
                                        <?php echo htmlspecialchars($appointment['doctor_name'] ?: 'Not assigned'); ?>
                                    </td>
                                    <td><span class="status-pill <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="record-action"
                                            data-appointment-details
                                            data-patient-title="<?php echo htmlspecialchars($recordBookingLabel, ENT_QUOTES); ?>"
                                            data-booking-type="<?php echo htmlspecialchars($recordBookingLabel, ENT_QUOTES); ?>"
                                            data-schedule="<?php echo htmlspecialchars($recordScheduleLabel, ENT_QUOTES); ?>"
                                            data-doctor="<?php echo htmlspecialchars($appointment['doctor_name'] ?: 'Not assigned', ENT_QUOTES); ?>"
                                            data-status="<?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES); ?>"
                                            data-default-panel="<?php echo htmlspecialchars($recordDefaultPanel, ENT_QUOTES); ?>"
                                        >
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>
                                            View details
                                        </button>
                                    </td>
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
                                <details class="record-details">
                                    <summary><?php echo htmlspecialchars($record['title']); ?></summary>
                                    <div class="record-details-body">
                                        <p><?php echo htmlspecialchars($record['content'] ?: 'No additional details were recorded.'); ?></p>
                                    </div>
                                </details>
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
                                <details class="record-details">
                                    <summary><?php echo htmlspecialchars($result['test_name']); ?></summary>
                                    <div class="record-details-body">
                                        <p><?php echo htmlspecialchars($result['result_text'] ?: 'No result details were recorded.'); ?></p>
                                    </div>
                                </details>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </section>

    <div class="record-modal" data-record-modal aria-hidden="true">
        <div class="record-modal-card" role="dialog" aria-modal="true" aria-labelledby="recordModalTitle">
            <div class="record-modal-head">
                <div>
                    <p class="record-modal-kicker">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                        Appointment record
                    </p>
                    <h2 class="record-modal-title" id="recordModalTitle" data-modal-title>Appointment details</h2>
                </div>
                <button type="button" class="record-modal-close" data-record-modal-close aria-label="Close record details">&times;</button>
            </div>
            <div class="record-modal-body">
                <aside class="record-modal-side" aria-label="Appointment summary">
                    <div class="record-modal-info">
                        <span>Appointment type</span>
                        <strong data-modal-type>Clinic appointment</strong>
                    </div>
                    <div class="record-modal-info">
                        <span>Schedule</span>
                        <strong data-modal-schedule>Not available</strong>
                    </div>
                    <div class="record-modal-info">
                        <span>Assigned doctor</span>
                        <strong data-modal-doctor>Not assigned</strong>
                    </div>
                    <div class="record-modal-info">
                        <span>Status</span>
                        <strong data-modal-status>Pending</strong>
                    </div>
                    <div class="record-modal-tabs" aria-label="Available clinic records">
                        <button type="button" class="record-modal-tab active" data-modal-record-tab="notes" <?php echo empty($medicalRows) ? 'disabled' : ''; ?>>
                            <span>Medical notes</span>
                            <span><?php echo count($medicalRows); ?></span>
                        </button>
                        <button type="button" class="record-modal-tab" data-modal-record-tab="laboratory" <?php echo empty($labRows) ? 'disabled' : ''; ?>>
                            <span>Laboratory results</span>
                            <span><?php echo count($labRows); ?></span>
                        </button>
                    </div>
                </aside>
                <div class="record-modal-main">
                    <section class="modal-record-panel active" data-modal-record-panel="notes">
                        <h3>Medical notes</h3>
                        <?php if (empty($medicalRows)): ?>
                            <div class="modal-empty">No medical notes have been released yet.</div>
                        <?php else: ?>
                            <?php foreach ($medicalRows as $record): ?>
                                <article class="modal-record-card">
                                    <strong><?php echo htmlspecialchars($record['title']); ?></strong>
                                    <span><?php echo htmlspecialchars(patient_record_date($record['created_at'])); ?> by <?php echo htmlspecialchars(clinical_author_label($record['author_name'] ?? '', $record['author_role'] ?? '')); ?></span>
                                    <p><?php echo htmlspecialchars($record['content'] ?: 'No additional details were recorded.'); ?></p>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                    <section class="modal-record-panel" data-modal-record-panel="laboratory">
                        <h3>Laboratory results</h3>
                        <?php if (empty($labRows)): ?>
                            <div class="modal-empty">No laboratory result has been released yet.</div>
                        <?php else: ?>
                            <?php foreach ($labRows as $result): ?>
                                <article class="modal-record-card">
                                    <strong><?php echo htmlspecialchars($result['test_name']); ?></strong>
                                    <span><?php echo htmlspecialchars(patient_record_date($result['result_date'])); ?> by <?php echo htmlspecialchars(clinical_author_label($result['author_name'] ?? '', $result['author_role'] ?? '')); ?></span>
                                    <?php if (!empty($result['catalog_name'])): ?>
                                        <span><?php echo htmlspecialchars($result['catalog_name']); ?></span>
                                    <?php endif; ?>
                                    <p><?php echo htmlspecialchars($result['result_text'] ?: 'No result details were recorded.'); ?></p>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>

<?php
require_once __DIR__ . '/nurse_medical_fields.php';

function nurse_clinical_column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $result && $result->num_rows > 0;
}

function nurse_clinical_ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS medical_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        author_id INT NOT NULL,
        title VARCHAR(220) NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_med_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $extra = [
        'diagnosis' => 'TEXT',
        'doctor_notes' => 'TEXT',
        'treatment' => 'TEXT',
        'medical_history' => 'TEXT',
        'vital_signs' => 'TEXT',
        'allergies' => 'TEXT',
        'patient_progress' => 'TEXT',
    ];
    foreach ($extra as $column => $type) {
        if (!nurse_clinical_column_exists($conn, 'medical_records', $column)) {
            $conn->query("ALTER TABLE medical_records ADD COLUMN `$column` $type NULL");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS lab_result_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        author_id INT NOT NULL,
        lab_service_id INT NULL,
        test_name VARCHAR(255) NOT NULL,
        result_text TEXT,
        result_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_labres_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function nurse_clinical_patient_exists(mysqli $conn, int $patientId): bool {
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function nurse_clinical_save_medical(mysqli $conn, int $patientId, int $authorId, array $fields): array {
    nurse_clinical_ensure_schema($conn);
    $title = trim((string) ($fields['title'] ?? ''));
    $content = trim((string) ($fields['content'] ?? ''));
    $diagnosis = trim((string) ($fields['diagnosis'] ?? ''));
    $doctorNotes = trim((string) ($fields['doctor_notes'] ?? ''));
    $treatment = trim((string) ($fields['treatment'] ?? ''));
    $history = trim((string) ($fields['medical_history'] ?? ''));
    $vitals = trim((string) ($fields['vital_signs'] ?? ''));
    $allergies = trim((string) ($fields['allergies'] ?? ''));
    $progress = trim((string) ($fields['patient_progress'] ?? ''));

    if ($title === '') {
        $title = $diagnosis !== '' ? $diagnosis : 'Medical note';
    }
    if ($content === '' && $diagnosis === '' && $doctorNotes === '' && $treatment === '' && $history === '' && $vitals === '' && $allergies === '' && $progress === '') {
        return ['ok' => false, 'error' => 'Please enter medical note details.'];
    }

    $stmt = $conn->prepare("INSERT INTO medical_records
        (patient_id, author_id, title, content, diagnosis, doctor_notes, treatment, medical_history, vital_signs, allergies, patient_progress)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not prepare medical record.'];
    }
    $stmt->bind_param('iisssssssss', $patientId, $authorId, $title, $content, $diagnosis, $doctorNotes, $treatment, $history, $vitals, $allergies, $progress);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Failed to save medical record.'];
    }
    $id = (int) $conn->insert_id;
    $stmt->close();
    return ['ok' => true, 'message' => 'Medical record saved.', 'id' => $id];
}

function nurse_clinical_save_lab(mysqli $conn, int $patientId, int $authorId, string $name, string $text, string $date, int $serviceId = 0): array {
    nurse_clinical_ensure_schema($conn);
    $name = trim($name);
    $text = trim($text);
    $date = trim($date);
    if ($name === '' || $date === '') {
        return ['ok' => false, 'error' => 'Test name and result date are required.'];
    }
    if (strtotime($date) === false) {
        return ['ok' => false, 'error' => 'Invalid result date.'];
    }
    if ($serviceId > 0) {
        $stmt = $conn->prepare('INSERT INTO lab_result_entries (patient_id, author_id, lab_service_id, test_name, result_text, result_date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiisss', $patientId, $authorId, $serviceId, $name, $text, $date);
    } else {
        $stmt = $conn->prepare('INSERT INTO lab_result_entries (patient_id, author_id, lab_service_id, test_name, result_text, result_date) VALUES (?, ?, NULL, ?, ?, ?)');
        $stmt->bind_param('iisss', $patientId, $authorId, $name, $text, $date);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Failed to save lab result.'];
    }
    $id = (int) $conn->insert_id;
    $stmt->close();
    return ['ok' => true, 'message' => 'Lab result saved.', 'id' => $id];
}

function nurse_clinical_fetch_medical(mysqli $conn, int $id): ?array {
    nurse_clinical_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT m.*, p.full_name AS patient_name, p.date_of_birth, p.gender, p.phone, p.email, u.full_name AS author_name
        FROM medical_records m
        JOIN users p ON p.id = m.patient_id
        LEFT JOIN users u ON u.id = m.author_id
        WHERE m.id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function nurse_clinical_fetch_lab(mysqli $conn, int $id): ?array {
    nurse_clinical_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT l.*, p.full_name AS patient_name, p.date_of_birth, p.gender, p.phone, p.email, u.full_name AS author_name
        FROM lab_result_entries l
        JOIN users p ON p.id = l.patient_id
        LEFT JOIN users u ON u.id = l.author_id
        WHERE l.id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function nurse_clinical_export_filename(string $prefix, string $patientName, string $dateStamp): string {
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($patientName)) ?: 'Patient';
    return $prefix . '_' . $safe . '_' . $dateStamp . '.html';
}

function nurse_clinical_render_export_html(array $data, string $type): string {
    $title = $type === 'lab' ? 'Patient Laboratory Result' : 'Patient Medical Record';
    $detailsTitle = $type === 'lab' ? 'Lab Result Details' : 'Medical Note Details';
    $rows = [
        'Name' => $data['patient_name'] ?? 'N/A',
        'Date of Birth' => $data['date_of_birth'] ?? 'Not recorded',
        'Gender' => $data['gender'] ?? 'Not recorded',
        'Contact Number' => $data['phone'] ?? 'Not recorded',
    ];
    $detailRows = $type === 'lab'
        ? [
            'Test Name' => $data['test_name'] ?? '',
            'Result Date' => $data['result_date'] ?? '',
            'Prepared By' => $data['author_name'] ?? '',
            'Result Details' => $data['result_text'] ?? '',
        ]
        : array_merge([
            'Title' => $data['title'] ?? '',
            'Date Added' => $data['created_at'] ?? '',
            'Prepared By' => $data['author_name'] ?? '',
        ], nurse_medical_sections_for_display($data));

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
@page{size:A4;margin:16mm}
body{font-family:Arial,sans-serif;color:#2d3436;background:#eef3f7;margin:0}
.sheet{width:190mm;min-height:277mm;margin:18px auto;background:#fff;padding:14mm 15mm;box-sizing:border-box}
.top-line,.bottom-line{height:3px;background:#0b5fa5}
.letterhead{display:flex;align-items:center;justify-content:space-between;padding:18px 4px}
.logo{width:62px;height:62px;border-radius:50%;object-fit:cover}
.clinic{text-align:right;font-weight:700;line-height:1.35;color:#0b1f33}
h1{text-align:center;margin:38px 0 8px;font-size:24px}
.sub{text-align:center;font-weight:700;margin-bottom:28px}
h2{font-size:17px;margin:24px 0 12px}
table{width:100%;border-collapse:collapse;margin-bottom:20px;page-break-inside:avoid}
td,th{border:1px solid #dde3ea;padding:8px 10px;vertical-align:top;font-size:13px;line-height:1.45}
th{width:34%;background:#f2f5f9;text-align:left;color:#60727d}
.section-break{border-top:1px solid #e5e9ef;margin:24px 0}
.footer{border-top:1px solid #e5e9ef;margin-top:28px;padding-top:10px;font-size:11px;color:#667}
@media print{body{background:#fff}.sheet{margin:0;box-shadow:none}}
</style>
</head>
<body>
<main class="sheet">
  <div class="top-line"></div>
  <div class="letterhead">
    <img src="globalife.png" class="logo" alt="Clinic logo">
    <div class="clinic">Globalife Medical Laboratory &amp; Polyclinic<br>Barangay Sahud Ulan, Tanza, Cavite<br>For authorized clinic use only</div>
  </div>
  <div class="bottom-line"></div>
  <h1><?php echo htmlspecialchars($title); ?></h1>
  <div class="sub">Globalife Medical Laboratory &amp; Polyclinic</div>
  <h2>Patient Information</h2>
  <table>
    <?php foreach ($rows as $label => $value): ?><tr><th><?php echo htmlspecialchars($label); ?></th><td><?php echo htmlspecialchars((string) $value); ?></td></tr><?php endforeach; ?>
  </table>
  <div class="section-break"></div>
  <h2><?php echo htmlspecialchars($detailsTitle); ?></h2>
  <table>
    <?php foreach ($detailRows as $label => $value): ?><tr><th><?php echo htmlspecialchars($label); ?></th><td><?php echo nl2br(htmlspecialchars((string) $value)); ?></td></tr><?php endforeach; ?>
  </table>
  <div class="footer">Generated by Globalife Clinic System on <?php echo htmlspecialchars(date('Y-m-d H:i')); ?>. Keep this document confidential.</div>
</main>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}

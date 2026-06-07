<?php
function nurse_medical_field_definitions(): array {
    return [
        'title' => 'Record title',
        'diagnosis' => 'Diagnosis',
        'doctor_notes' => 'Doctor/Nurse notes',
        'treatment' => 'Treatment',
        'medical_history' => 'Medical history',
        'vital_signs' => 'Vital signs',
        'allergies' => 'Allergies',
        'patient_progress' => 'Patient progress',
        'content' => 'Additional details',
    ];
}

function nurse_medical_fields_from_post(array $post): array {
    $out = [];
    foreach (array_keys(nurse_medical_field_definitions()) as $field) {
        $out[$field] = trim((string) ($post[$field] ?? $post['mr_' . $field] ?? ''));
    }
    if (($out['title'] ?? '') === '') {
        $out['title'] = ($out['diagnosis'] ?? '') !== '' ? $out['diagnosis'] : 'Medical note';
    }
    return $out;
}

function nurse_medical_form_session_from_post(array $post, int $patientId): array {
    return array_merge(['tab' => 'medical', 'patient_id' => $patientId], nurse_medical_fields_from_post($post));
}

function nurse_medical_sections_for_display(array $record): array {
    $defs = nurse_medical_field_definitions();
    $sections = [];
    foreach ($defs as $field => $label) {
        if ($field === 'title') continue;
        $value = trim((string) ($record[$field] ?? ''));
        if ($value !== '') {
            $sections[$label] = $value;
        }
    }
    return $sections;
}

function nurse_medical_render_sections(array $record): void {
    $sections = nurse_medical_sections_for_display($record);
    if (empty($sections)) {
        echo '<div style="white-space:pre-wrap;color:#444">' . htmlspecialchars((string) ($record['content'] ?? '')) . '</div>';
        return;
    }
    echo '<table class="medical-section-table">';
    foreach ($sections as $label => $value) {
        echo '<tr><th>' . htmlspecialchars($label) . '</th><td>' . nl2br(htmlspecialchars($value)) . '</td></tr>';
    }
    echo '</table>';
}

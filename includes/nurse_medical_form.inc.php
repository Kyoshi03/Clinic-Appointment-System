<?php
$clinicalForm = isset($clinicalForm) && is_array($clinicalForm) ? $clinicalForm : [];
$fieldValues = array_merge([
    'title' => '',
    'diagnosis' => '',
    'doctor_notes' => '',
    'treatment' => '',
    'medical_history' => '',
    'vital_signs' => '',
    'allergies' => '',
    'patient_progress' => '',
    'content' => '',
], $clinicalForm);
?>
<div class="clinical-form-grid">
    <div class="field full">
        <label>Record title</label>
        <input name="title" value="<?php echo htmlspecialchars((string) $fieldValues['title']); ?>" placeholder="Example: Follow-up assessment">
    </div>
    <div class="field">
        <label>Diagnosis</label>
        <textarea name="diagnosis" placeholder="Diagnosis"><?php echo htmlspecialchars((string) $fieldValues['diagnosis']); ?></textarea>
    </div>
    <div class="field">
        <label>Vital signs</label>
        <textarea name="vital_signs" placeholder="BP, temperature, pulse, respiratory rate"><?php echo htmlspecialchars((string) $fieldValues['vital_signs']); ?></textarea>
    </div>
    <div class="field">
        <label>Medical history</label>
        <textarea name="medical_history" placeholder="Relevant history"><?php echo htmlspecialchars((string) $fieldValues['medical_history']); ?></textarea>
    </div>
    <div class="field">
        <label>Allergies</label>
        <textarea name="allergies" placeholder="Known allergies"><?php echo htmlspecialchars((string) $fieldValues['allergies']); ?></textarea>
    </div>
    <div class="field">
        <label>Treatment</label>
        <textarea name="treatment" placeholder="Treatment or medication plan"><?php echo htmlspecialchars((string) $fieldValues['treatment']); ?></textarea>
    </div>
    <div class="field">
        <label>Patient progress</label>
        <textarea name="patient_progress" placeholder="Progress notes"><?php echo htmlspecialchars((string) $fieldValues['patient_progress']); ?></textarea>
    </div>
    <div class="field full">
        <label>Medical note details</label>
        <textarea name="doctor_notes" placeholder="Detailed notes"><?php echo htmlspecialchars((string) $fieldValues['doctor_notes']); ?></textarea>
    </div>
    <div class="field full">
        <label>Additional details</label>
        <textarea name="content" placeholder="Other observations"><?php echo htmlspecialchars((string) $fieldValues['content']); ?></textarea>
    </div>
</div>

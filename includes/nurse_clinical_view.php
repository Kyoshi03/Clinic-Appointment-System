<?php
$clinicalTab = $clinicalTab ?? 'medical';
?>
<main class="clinical-shell">
    <section class="clinical-hero">
        <h1><?php echo htmlspecialchars($pageHeading ?? 'Clinical records'); ?></h1>
        <p><?php echo htmlspecialchars($pageDesc ?? 'Manage patient clinical records.'); ?></p>
    </section>

    <?php if (!empty($_SESSION['success'])): ?><div class="ok"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?><div class="er"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="clinical-tabs">
        <a href="nurse_medical.php" class="<?php echo $clinicalTab === 'medical' ? 'active' : ''; ?>">Medical records</a>
        <a href="nurse_lab.php" class="<?php echo $clinicalTab === 'lab' ? 'active' : ''; ?>">Lab results</a>
    </div>

    <div class="clinical-grid">
        <section class="clinical-card">
            <h2><?php echo $clinicalTab === 'lab' ? 'Add lab result' : 'Add medical record'; ?></h2>
            <div class="field">
                <label>Select patient</label>
                <select id="clinicalPatientSelect">
                    <option value="">Choose patient</option>
                    <?php foreach (($patientOptions ?? []) as $option): ?>
                        <option value="<?php echo (int) $option['id']; ?>" <?php echo ((int) ($selectedPatientId ?? 0) === (int) $option['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($clinicalTab === 'lab'): ?>
                <form method="post" id="nurseLabForm">
                    <input type="hidden" name="nurse_clinical_action" value="add_lab_result">
                    <input type="hidden" name="clinical_tab" value="lab">
                    <input type="hidden" name="clinical_patient_id" id="labPatientId" value="<?php echo (int) ($selectedPatientId ?? 0); ?>">
                    <div class="field">
                        <label>Catalog service</label>
                        <select name="lr_lab_service_id" id="nurseLrSvc">
                            <option value="0">Manual test name</option>
                            <?php foreach (($labServicesList ?? []) as $service): ?>
                                <option value="<?php echo (int) $service['id']; ?>" data-name="<?php echo htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($service['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label>Test name</label><input name="lr_test_name" id="nurseLrName" value="<?php echo htmlspecialchars((string) ($clinicalForm['lr_test_name'] ?? '')); ?>" required></div>
                    <div class="field"><label>Result details</label><textarea name="lr_result_text"><?php echo htmlspecialchars((string) ($clinicalForm['lr_result_text'] ?? '')); ?></textarea></div>
                    <div class="field"><label>Result date</label><input type="date" name="lr_result_date" value="<?php echo htmlspecialchars((string) ($clinicalForm['lr_result_date'] ?? date('Y-m-d'))); ?>" required></div>
                    <button class="clinical-btn" type="submit">Save lab result</button>
                </form>
            <?php else: ?>
                <form method="post" id="nurseMedicalForm">
                    <input type="hidden" name="nurse_clinical_action" value="add_medical">
                    <input type="hidden" name="clinical_tab" value="medical">
                    <input type="hidden" name="clinical_patient_id" id="medicalPatientId" value="<?php echo (int) ($selectedPatientId ?? 0); ?>">
                    <?php require __DIR__ . '/nurse_medical_form.inc.php'; ?>
                    <button class="clinical-btn" type="submit">Save medical record</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="clinical-card">
            <h2>Recent records</h2>
            <div class="clinical-tabs">
                <button type="button" data-recent-tab="medical" class="<?php echo $clinicalTab === 'medical' ? 'active' : ''; ?>">Medical</button>
                <button type="button" data-recent-tab="lab" class="<?php echo $clinicalTab === 'lab' ? 'active' : ''; ?>">Lab</button>
            </div>
            <div data-recent-pane="medical" class="recent-list <?php echo $clinicalTab === 'medical' ? 'active' : ''; ?>">
                <?php if (empty($recentRecords)): ?><p style="color:#666">No medical records yet.</p><?php endif; ?>
                <?php foreach (($recentRecords ?? []) as $record): ?>
                    <div class="recent-item">
                        <strong><?php echo htmlspecialchars($record['patient_name'] . ' - ' . ($record['diagnosis'] ?: $record['title'])); ?></strong>
                        <small><?php echo htmlspecialchars($record['created_at']); ?></small>
                        <?php nurse_medical_render_sections($record); ?>
                        <div class="record-actions" aria-label="Medical record actions">
                            <a class="record-action-btn print" href="nurse_export.php?type=medical&amp;id=<?php echo (int) $record['record_id']; ?>&amp;action=print" target="_blank" rel="noopener">
                                <span aria-hidden="true">P</span>
                                Print report
                            </a>
                            <a class="record-action-btn download" href="nurse_export.php?type=medical&amp;id=<?php echo (int) $record['record_id']; ?>&amp;action=download">
                                <span aria-hidden="true">D</span>
                                Download PDF
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div data-recent-pane="lab" class="recent-list <?php echo $clinicalTab === 'lab' ? 'active' : ''; ?>">
                <?php if (empty($recentLabs)): ?><p style="color:#666">No lab results yet.</p><?php endif; ?>
                <?php foreach (($recentLabs ?? []) as $lab): ?>
                    <div class="recent-item">
                        <strong><?php echo htmlspecialchars($lab['patient_name'] . ' - ' . $lab['test_name']); ?></strong>
                        <small><?php echo htmlspecialchars($lab['result_date']); ?></small>
                        <div style="white-space:pre-wrap"><?php echo htmlspecialchars($lab['result_text'] ?? ''); ?></div>
                        <div class="record-actions" aria-label="Lab result actions">
                            <a class="record-action-btn print" href="nurse_export.php?type=lab&amp;id=<?php echo (int) $lab['record_id']; ?>&amp;action=print" target="_blank" rel="noopener">
                                <span aria-hidden="true">P</span>
                                Print report
                            </a>
                            <a class="record-action-btn download" href="nurse_export.php?type=lab&amp;id=<?php echo (int) $lab['record_id']; ?>&amp;action=download">
                                <span aria-hidden="true">D</span>
                                Download PDF
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</main>

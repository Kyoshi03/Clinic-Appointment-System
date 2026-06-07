<?php
/**
 * Small single-page PDF renderer for nurse medical record and lab result exports.
 * Uses built-in PDF fonts only so the export works without Composer libraries.
 */

function clinic_pdf_clean(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strtr($text, [
        '₱' => 'PHP ',
        '—' => '-',
        '–' => '-',
        '•' => '-',
        '“' => '"',
        '”' => '"',
        '‘' => "'",
        '’' => "'",
        "\r" => '',
    ]);
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? '';
}

function clinic_pdf_escape(string $text): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], clinic_pdf_clean($text));
}

function clinic_pdf_text(array &$ops, float $x, float $y, float $size, string $text, bool $bold = false): void {
    $font = $bold ? '/F2' : '/F1';
    $ops[] = sprintf("0.05 0.16 0.24 rg BT %s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET", $font, $size, $x, $y, clinic_pdf_escape($text));
}

function clinic_pdf_center_text(array &$ops, float $y, float $size, string $text, bool $bold = false): void {
    $clean = clinic_pdf_clean($text);
    $x = 297.5 - (strlen($clean) * $size * 0.25);
    clinic_pdf_text($ops, max(45, $x), $y, $size, $clean, $bold);
}

function clinic_pdf_line(array &$ops, float $x1, float $y1, float $x2, float $y2, float $width = 1.0): void {
    $ops[] = sprintf("0.00 0.32 0.68 RG %.2F w %.2F %.2F m %.2F %.2F l S", $width, $x1, $y1, $x2, $y2);
}

function clinic_pdf_rect(array &$ops, float $x, float $y, float $w, float $h, bool $fill = false): void {
    if ($fill) {
        $ops[] = sprintf("0.94 0.97 1.00 rg %.2F %.2F %.2F %.2F re f", $x, $y, $w, $h);
    }
    $ops[] = sprintf("0.78 0.84 0.90 RG 0.6 w %.2F %.2F %.2F %.2F re S", $x, $y, $w, $h);
}

function clinic_pdf_wrap(string $text, int $maxChars): array {
    $text = trim(clinic_pdf_clean($text));
    if ($text === '') {
        return [''];
    }
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            $out[] = '';
            continue;
        }
        foreach (explode("\n", wordwrap($line, $maxChars, "\n", true)) as $wrapped) {
            $out[] = $wrapped;
        }
    }
    return $out;
}

function clinic_pdf_table_row(array &$ops, float &$y, string $label, string $value, int $maxLines = 4): void {
    $x = 65;
    $labelW = 150;
    $valueW = 365;
    $lines = clinic_pdf_wrap($value, 66);
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $lines[] = 'Text shortened to fit this A4 export.';
    }
    $height = max(24, 11 + (count($lines) * 12));
    $bottom = $y - $height;
    clinic_pdf_rect($ops, $x, $bottom, $labelW, $height, true);
    clinic_pdf_rect($ops, $x + $labelW, $bottom, $valueW, $height, false);
    clinic_pdf_text($ops, $x + 8, $y - 16, 9, $label, true);
    $lineY = $y - 16;
    foreach ($lines as $line) {
        clinic_pdf_text($ops, $x + $labelW + 8, $lineY, 9, $line);
        $lineY -= 12;
    }
    $y = $bottom;
}

function clinic_pdf_build(string $stream): string {
    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($pdf);
        $n = $i + 1;
        $pdf .= $n . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function clinic_nurse_pdf_output(string $type, array $record): void {
    $ops = [];
    clinic_pdf_line($ops, 34, 815, 561, 815, 2.2);
    clinic_pdf_line($ops, 34, 735, 561, 735, 2.2);

    $ops[] = "0.00 0.32 0.68 rg 46 752 58 48 re f";
    clinic_pdf_text($ops, 63, 772, 18, 'GL', true);
    clinic_pdf_text($ops, 350, 790, 11, 'Globalife Medical Laboratory & Polyclinic', true);
    clinic_pdf_text($ops, 378, 774, 10, 'Barangay Sahud Ulan, Tanza, Cavite', true);
    clinic_pdf_text($ops, 403, 758, 9, 'For authorized clinic use only', true);

    $documentTitle = $type === 'lab' ? 'Patient Laboratory Result' : 'Patient Medical Record';
    clinic_pdf_center_text($ops, 680, 18, $documentTitle, true);
    clinic_pdf_center_text($ops, 658, 10, 'Globalife Medical Laboratory & Polyclinic', true);

    $y = 620;
    clinic_pdf_text($ops, 65, $y, 12, 'Patient Information', true);
    $y -= 18;
    clinic_pdf_table_row($ops, $y, 'Name', (string) ($record['patient_name'] ?? 'N/A'), 2);
    clinic_pdf_table_row($ops, $y, 'Date of Birth', (string) ($record['date_of_birth'] ?? 'Not recorded'), 2);
    clinic_pdf_table_row($ops, $y, 'Gender', (string) ($record['gender'] ?? 'Not recorded'), 2);
    clinic_pdf_table_row($ops, $y, 'Contact Number', (string) ($record['phone'] ?? 'Not recorded'), 2);
    clinic_pdf_table_row($ops, $y, 'Email', (string) ($record['email'] ?? 'Not recorded'), 2);

    $y -= 28;
    clinic_pdf_line($ops, 65, $y + 12, 530, $y + 12, 0.8);
    clinic_pdf_text($ops, 65, $y, 12, $type === 'lab' ? 'Lab Result Details' : 'Medical Note Details', true);
    $y -= 18;

    if ($type === 'lab') {
        clinic_pdf_table_row($ops, $y, 'Test Name', (string) ($record['test_name'] ?? 'N/A'), 3);
        clinic_pdf_table_row($ops, $y, 'Result Date', (string) ($record['result_date'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Prepared By', (string) ($record['author_name'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Result Details', (string) ($record['result_text'] ?? ''), 18);
    } else {
        clinic_pdf_table_row($ops, $y, 'Title', (string) ($record['title'] ?? 'N/A'), 3);
        clinic_pdf_table_row($ops, $y, 'Date Added', (string) ($record['created_at'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Prepared By', (string) ($record['author_name'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Medical Note Details', (string) ($record['content'] ?? ''), 18);
    }

    clinic_pdf_line($ops, 65, 70, 530, 70, 0.7);
    clinic_pdf_text($ops, 65, 54, 8, 'Generated by Globalife Clinic System. Keep this document confidential.');
    clinic_pdf_text($ops, 405, 54, 8, 'Generated: ' . date('Y-m-d H:i'));

    $pdf = clinic_pdf_build(implode("\n", $ops));
    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', strtolower((string) ($record['patient_name'] ?? 'patient'))) ?: 'patient';
    $fileName = $safeName . '_' . ($type === 'lab' ? 'lab_result' : 'medical_record') . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
}

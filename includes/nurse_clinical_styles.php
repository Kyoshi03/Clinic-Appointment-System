<?php
function nurse_clinical_styles(): string {
    return '
.clinical-shell{max-width:1180px;margin:28px auto;padding:0 20px 42px}
.clinical-hero{background:linear-gradient(135deg,#0077b6,#023e8a);color:#fff;border-radius:16px;padding:22px 24px;margin-bottom:18px}
.clinical-hero h1{margin:0 0 6px}.clinical-hero p{margin:0;color:#e6f7ff}
.clinical-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:950px){.clinical-grid{grid-template-columns:1fr}}
.clinical-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 18px rgba(0,0,0,.06);margin-bottom:16px}
.clinical-card h2{margin:0 0 12px;color:#0077b6;font-size:1.15rem}
.clinical-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.clinical-tabs a,.clinical-tabs button{border:1px solid #d5e8f6;background:#fff;color:#0b4f80;border-radius:999px;padding:8px 13px;font-weight:700;text-decoration:none;cursor:pointer}
.clinical-tabs .active{background:#0f7cc2;color:#fff}
.clinical-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.clinical-form-grid .full{grid-column:1/-1}@media(max-width:700px){.clinical-form-grid{grid-template-columns:1fr}}
.field label{display:block;font-weight:700;color:#344d5f;margin-bottom:5px}.field input,.field textarea,.field select{width:100%;box-sizing:border-box;border:1px solid #cbdce8;border-radius:8px;padding:10px;font:inherit}.field textarea{min-height:90px}
.clinical-btn{background:#0077b6;color:#fff;border:none;border-radius:8px;padding:11px 15px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex}
.clinical-btn.secondary{background:#eef7ff;color:#0b4f80;border:1px solid #cfe4f4}
.medical-section-table{width:100%;border-collapse:collapse;margin-top:8px}.medical-section-table th,.medical-section-table td{border:1px solid #e2e9f0;padding:8px;vertical-align:top;text-align:left}.medical-section-table th{width:34%;background:#f4f8fb;color:#60727d}
.recent-list{display:none;gap:10px}.recent-list.active{display:grid}.recent-item{border:1px solid #e2e9f0;background:#f8fbff;border-radius:10px;padding:12px}.recent-item strong{display:block;color:#073b4c}.recent-item small{color:#60727d}
';
}

<?php
/* ==========================================================
 *  PINTU SSO KE DSS
 *  Pengguna yang sudah login di MartOffice diarahkan ke DSS
 *  dengan membawa token bertanda tangan — tanpa login ulang.
 * ========================================================== */
require __DIR__ . '/includes/app.php';
need('admin', 'manajer');   // staff tidak memiliki akses DSS

if (!dss_hidup()) {
    flash('err', 'Layanan DSS belum berjalan. Buka Command Prompt, masuk ke folder <code>martoffice</code>, lalu jalankan <code>python dss/app.py</code> (port ' . DSS_PORT . ').');
    redirect('beranda.php');
}

$token = sso_token(me());
header('Cache-Control: no-store');
redirect(DSS_URL . '/sso?token=' . urlencode($token));

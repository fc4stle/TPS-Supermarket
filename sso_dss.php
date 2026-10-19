<?php
/* ==========================================================
 *  PINTU SSO KE DSS
 *  Pengguna yang sudah login di MartOffice diarahkan ke DSS
 *  dengan membawa token bertanda tangan — tanpa login ulang.
 * ========================================================== */
require __DIR__ . '/includes/app.php';
need('admin', 'manajer');   // staff tidak memiliki akses DSS

// DSS dinyalakan otomatis bila belum berjalan (tanpa membuka Command Prompt)
if (!dss_hidup() && !dss_nyalakan()) {
    flash('err', 'DSS tidak dapat dinyalakan otomatis. Pastikan Python dan Flask terpasang (<code>python -m pip install flask</code>), '
        . 'lihat catatan error di <code>dss/dss.log</code>, atau jalankan manual: <code>python dss/app.py</code> (port ' . DSS_PORT . ').');
    redirect('beranda.php');
}

$token = sso_token(me());
header('Cache-Control: no-store');
redirect(DSS_URL . '/sso?token=' . urlencode($token));

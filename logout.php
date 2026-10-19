<?php
require __DIR__ . '/includes/app.php';
$_SESSION = [];
session_regenerate_id(true);
flash('ok', 'Anda sudah keluar. Sampai jumpa lagi!');

/* Single sign-out: bila DSS sedang berjalan, browser dibawa sebentar ke DSS untuk
 * menghapus sesi DSS, lalu dikembalikan ke halaman login. Alamat kembali ditandatangani
 * HMAC agar DSS tidak bisa dipakai untuk mengalihkan pengguna ke situs lain. */
if (dss_hidup()) {
    $next = martoffice_home() . '/login.php';
    $ts = time();
    redirect(DSS_URL . '/sso/keluar?' . http_build_query(['next' => $next, 'ts' => $ts, 'sig' => api_sig('keluar:' . $next, $ts)]));
}
redirect('login.php');

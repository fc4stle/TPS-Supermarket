<?php
/* ==========================================================
 *  BOOTSTRAP — di-require oleh setiap halaman.
 * ========================================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/mis.php';
require_once __DIR__ . '/sso.php';

/* ---- Hitung URL dasar aplikasi secara otomatis ----
 * Contoh: C:\xampp\htdocs\martoffice\admin\dashboard.php diakses via
 * /martoffice/admin/dashboard.php  →  BASE_URL = /martoffice
 * sehingga folder boleh diberi nama apa saja. */
(function () {
    $root   = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $script = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    $name   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $rel    = (stripos($script, $root) === 0) ? substr($script, strlen($root)) : '/' . basename($name);
    $base   = substr($name, 0, strlen($name) - strlen($rel));
    define('BASE_URL', rtrim($base, '/'));
})();

/* ---- Error yang tidak tertangkap tampil sebagai halaman ramah ---- */
set_exception_handler(function (Throwable $ex) {
    fatal_page('Terjadi kesalahan', 'Maaf, permintaan tidak dapat diproses.<br><small>' . e($ex->getMessage())
        . ' (' . e(basename($ex->getFile())) . ':' . $ex->getLine() . ')</small>');
});

/* ---- Sesi ---- */
session_name('MARTOFFICE');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

/* ---- Pengguna yang sedang login (dibaca ulang dari DB setiap request) ---- */
$GLOBALS['ME'] = null;
if (!empty($_SESSION['uid'])) {
    $GLOBALS['ME'] = row('SELECT id,nama,username,role,jabatan FROM pegawai WHERE id=? AND aktif=1', [(int) $_SESSION['uid']]);
    if (!$GLOBALS['ME']) unset($_SESSION['uid']);
}

/* ---- Semua form POST wajib membawa token CSRF ---- */
verify_csrf();

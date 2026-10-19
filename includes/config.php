<?php
/* ==========================================================
 *  KONFIGURASI MARTOFFICE
 *  Ubah bagian DATABASE bila MySQL Anda memakai password
 *  atau port yang berbeda (XAMPP default: root tanpa password).
 * ========================================================== */

// ---- DATABASE ----
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'martoffice');
define('DB_PORT', 3306);

// ---- APLIKASI ----
define('APP_NAME', 'MartOffice');
define('APP_TAGLINE', 'Segar · Hemat · Lengkap');
define('STOK_MIN', 10);                 // batas stok menipis
define('UPLOAD_MAX', 5 * 1024 * 1024);  // 5 MB per dokumen
define('UPLOAD_EXT', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'txt', 'csv']);

date_default_timezone_set('Asia/Jakarta');

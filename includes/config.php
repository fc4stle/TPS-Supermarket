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

// ---- DSS (Flask + SQLite) & SINGLE SIGN-ON ----
// DSS berjalan terpisah di port 5055 (python dss/app.py).
// SSO_SECRET harus SAMA PERSIS dengan SSO_SECRET di dss/config.py.
define('DSS_PORT', 5055);
define('DSS_URL', 'http://localhost:' . DSS_PORT);      // alamat yang dibuka browser
define('DSS_API', 'http://127.0.0.1:' . DSS_PORT);      // alamat yang dipanggil server PHP
define('SSO_SECRET', 'martoffice-dss-sso-2026-ganti-kunci-ini');
define('SSO_TTL', 60);                                  // token SSO berlaku 60 detik
// DSS dinyalakan otomatis saat tombol "Buka DSS" diklik. Kosongkan agar Python dicari otomatis,
// atau isi lokasi lengkap, mis. 'C:\\Users\\Lenovo\\AppData\\Local\\Programs\\Python\\Python310\\pythonw.exe'
define('DSS_PYTHON', '');

date_default_timezone_set('Asia/Jakarta');

"""
Konfigurasi DSS MartOffice.

PENTING: SSO_SECRET di sini harus SAMA PERSIS dengan SSO_SECRET di
martoffice/includes/config.php. Kunci inilah yang dipakai kedua aplikasi
untuk menandatangani dan memeriksa token Single Sign-On.
"""
import os

# ---- Jaringan ----
HOST = "127.0.0.1"
PORT = 5055  # port khusus DSS; tidak bentrok dengan Apache (80) & MySQL (3306)

# Alamat aplikasi MartOffice (PHP) — dipakai untuk tombol kembali, login, dan API TPS
MARTOFFICE_URL = "http://localhost/martoffice"

# ---- Single Sign-On ----
SSO_SECRET = "martoffice-dss-sso-2026-ganti-kunci-ini"
SSO_TOKEN_TTL = 60          # detik; token SSO hanya berlaku 60 detik
API_TOLERANSI = 120         # detik; selisih waktu maksimum permintaan API antar-aplikasi
ROLE_DIIZINKAN = ("manajer", "admin")  # staff tidak boleh masuk DSS

# ---- Database ----
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, "dss.db")

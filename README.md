# 🛒 MartOffice — Sistem Informasi Supermarket Terpadu

Aplikasi supermarket berbasis **PHP native + MySQL** yang menggabungkan modul:
**TPS** (kasir, nota, stok, laporan penjualan), **OAS** (tugas, pengumuman, agenda, dokumen, pesan internal),
**KMS** (knowledge base dengan review, versi, dan feedback), **MIS** (11 halaman analitik kinerja khusus manajer),
dan **DSS** (pemilihan supplier terbaik metode SAW — aplikasi **Flask + SQLite** di port 5055 yang terhubung lewat **Single Sign-On**).

**Dibuat oleh:** Alysa Salsabila Irfan Putri (24051130049) — TI-J, Universitas Negeri Yogyakarta

## Cara menjalankan
1. Start **Apache** dan **MySQL** di XAMPP.
2. Salin folder ini ke `htdocs` dengan nama `martoffice`.
3. Import `database.sql` (opsional: lalu `data_simulasi.sql` untuk data contoh MIS).
4. Buka `http://localhost/martoffice/`.
5. DSS: `python -m pip install flask` (sekali), opsional `python dss/data_simulasi.py`. Klik kartu DSS di Beranda — DSS dinyalakan otomatis di port 5055.

Akun: `admin/admin123`, `manajer/manajer123`, `staff/staff123`. Panduan lengkap ada di [PANDUAN.md](PANDUAN.md).

> Versi TPS sebelumnya (role Admin/Kasir & Pembeli) tetap tersimpan di riwayat commit repo ini.

# 🛒 MartOffice — Panduan Menjalankan

Sistem terpadu supermarket: **TPS** (kasir, nota, stok, laporan) + **OAS** (tugas, pengumuman, agenda, dokumen, pesan) + **KMS** (knowledge base dengan review, tag, feedback, dan riwayat versi) + **MIS** (kinerja perusahaan untuk manajer) + **DSS** (pemilihan supplier metode SAW, aplikasi Flask terpisah yang terhubung lewat Single Sign-On). Tanpa role Pembeli.

## Kebutuhan
- XAMPP dengan **PHP 8.0 atau lebih baru** (Apache + MySQL/MariaDB)
- MySQL Workbench atau phpMyAdmin
- Untuk DSS: **Python 3.10 atau lebih baru** + Flask (`python -m pip install flask`)

## Langkah menjalankan
1. Buka **XAMPP Control Panel** → klik **Start** pada **Apache** dan **MySQL**.
2. Extract zip, lalu salin folder `martoffice` ke `C:\xampp\htdocs\`
   → hasilnya `C:\xampp\htdocs\martoffice\login.php`
3. Buat database:
   - **MySQL Workbench**: File → Open SQL Script → pilih `martoffice/database.sql` → klik ⚡ (Execute all).
   - **atau phpMyAdmin**: buka `http://localhost/phpmyadmin` → tab **Import** → pilih `database.sql` → **Go**.
   - Script otomatis membuat database `martoffice` beserta data awal. ⚠️ Database `martoffice` lama akan dihapus.
4. Buka browser: **http://localhost/martoffice/**

> Jika MySQL Anda memakai password atau port lain, ubah bagian DATABASE di `includes/config.php`.

## Beranda
Setelah login, semua role masuk ke **Beranda MartOffice** yang berisi lima kartu modul (TPS, OAS, KMS, MIS, DSS) dengan tombol **Buka modul**. Kartu MIS terkunci untuk admin dan staff; kartu DSS terkunci untuk staff.

## DSS · Pemilihan Supplier (Flask + SQLite, port 5055)
DSS adalah aplikasi terpisah yang berjalan **bersamaan** dengan XAMPP.

| Layanan | Port |
|---|---|
| Apache (MartOffice) | 80 |
| MySQL | 3306 |
| DSS (Flask) | **5055** |

1. Pastikan Apache dan MySQL XAMPP sudah Start dan MartOffice bisa dibuka.
2. Buka **Command Prompt** baru, lalu (sesuaikan dengan folder XAMPP Anda, mis. `C:\xampp2\htdocs`):
   ```
   cd C:\xampp\htdocs\martoffice
   python dss/app.py
   ```
   Biarkan jendela ini tetap terbuka (tekan `Ctrl + C` untuk menghentikan). File database `dss/dss.db` dibuat otomatis.
3. (Opsional, sekali saja) isi data supplier simulasi di jendela CMD lain:
   ```
   python dss/data_simulasi.py
   ```
   Untuk mengosongkan lagi: `python dss/data_simulasi.py --kosong`.
4. Login MartOffice sebagai **manajer** (atau admin) → Beranda → **🔐 Buka DSS (SSO)**. Anda masuk ke `http://localhost:5055` tanpa login ulang.

**Single Sign-On:** MartOffice membuat token bertanda tangan HMAC-SHA256 (berlaku 60 detik, sekali pakai) dan DSS memverifikasinya. Kunci `SSO_SECRET` di `includes/config.php` dan `dss/config.py` **harus sama**. Keluar dari salah satu aplikasi otomatis keluar dari keduanya.

**Hak akses DSS:** manajer = semua fitur (ubah bobot, simpan keputusan, hapus); admin = kelola supplier & penilaian, lihat hasil; staff = tidak bisa masuk.

**Menu DSS:** Dashboard (juara per kategori + prioritas dari data TPS), Data Supplier, Kriteria & Bobot, Penilaian, Perhitungan SAW langkah demi langkah, Analisis Sensitivitas, Riwayat Keputusan (cetak/CSV), dan Log SSO.

Jika port 5055 sudah dipakai, ganti `PORT` di `dss/config.py` **dan** `DSS_PORT` di `includes/config.php` dengan angka yang sama.

## MIS (khusus Manajer)
Login sebagai **manajer** → klik menu **📈 MIS · Kinerja Perusahaan**. MIS berisi 11 halaman: Executive Dashboard, Pusat Peringatan, Analitik Penjualan, Analitik Produk, Analitik Persediaan, Analitik Belanja Pelanggan, Monitoring OAS, Analitik KMS, Kinerja Pegawai, Perkiraan, dan Laporan Manajemen. Admin dan staff tidak dapat membukanya.

### Data simulasi (opsional)
Agar grafik dan perbandingan periode di MIS terisi, jalankan `data_simulasi.sql` **satu kali** setelah `database.sql` (cara menjalankannya sama). File ini menambahkan aktivitas toko fiktif ±9 minggu terakhir dan 3 akun staff (`dimas`, `nadia`, `bagus`, password `staff123`). Untuk kembali ke kondisi bersih, jalankan ulang `database.sql` saja.

## Akun
| Role | Username | Password |
|---|---|---|
| Administrator | `admin` | `admin123` |
| Manajer (Alysa Salsabila) | `manajer` | `manajer123` |
| Staff (Rafa Fadhilah) | `staff` | `staff123` |

Di halaman login ada tombol **🔑 Akun uji coba** untuk mengisi otomatis.

## Hak akses per role
| Menu | Admin | Manajer | Staff |
|---|:-:|:-:|:-:|
| Kasir & nota | ✅ | – | ✅ |
| Riwayat transaksi | semua | semua | milik sendiri |
| Produk & stok | tambah/ubah/arsip + restok | restok & koreksi | lihat |
| Laporan penjualan (+CSV) | ✅ | ✅ | – |
| Tugas | beri & pantau | beri & pantau | kerjakan |
| Pengumuman & agenda | buat | buat | baca |
| Dokumen & pesan | ✅ | ✅ | ✅ |
| Tulis knowledge | langsung publish | langsung publish | menunggu review |
| Review & kategori KMS | ✅ | ✅ | – |
| User & role | ✅ | – | – |

## Skenario demo (±5 menit)
1. **Staff** → Kasir → klik produk → pilih *Tunai* → *Uang pas* → **Bayar & Cetak Nota**.
2. **Staff** → Tulis Knowledge → kirim (status *menunggu review*).
3. **Manajer** → Review Knowledge → buka artikel → **Tolak** dengan catatan (staff otomatis dapat pesan).
4. **Staff** → Pesan Internal (lihat notifikasi) → Kontribusi Saya → Edit → simpan (jadi versi 2).
5. **Manajer** → **Setujui** → buka **Riwayat Versi** untuk melihat perbedaan antarversi.
6. **Staff** → beri feedback 👍/👎 + komentar di artikel.
7. **Manajer** → Tugas Staff → beri tugas → **Staff** kerjakan → selesai.
8. **Manajer** → Laporan Penjualan → Ekspor CSV / Cetak.

## Struktur folder
```
martoffice/
├── database.sql          ← jalankan sekali
├── beranda.php           ← halaman awal: 4 kartu modul
├── login.php, logout.php, index.php
├── includes/             ← config, fungsi, layout (dilindungi .htaccess)
├── assets/css, assets/js ← tampilan & interaksi
├── admin/                ← dashboard admin, user & role
├── manajer/  staff/      ← dashboard per role
├── tps/                  ← kasir, nota, transaksi, produk, laporan
├── oas/                  ← tugas, pengumuman, agenda, dokumen, pesan
├── kms/                  ← knowledge center, tulis, detail, versi, kategori
├── mis/                  ← 11 halaman MIS (khusus manajer)
├── sso_dss.php           ← pintu Single Sign-On ke DSS
├── api/dss_tps.php       ← API data TPS untuk DSS (HMAC)
├── dss/                  ← aplikasi DSS (Flask + SQLite, port 5055)
│   ├── app.py, sso.py, saw.py, db.py, config.py
│   ├── data_simulasi.py  ← opsional, supplier contoh
│   └── templates/, static/
├── data_simulasi.sql     ← opsional, data contoh untuk MIS
└── uploads/dokumen/      ← file unggahan (tidak bisa diakses langsung)
```

## Keamanan yang sudah diterapkan
Password di-hash (bcrypt), semua query memakai prepared statement, token CSRF di setiap form, aksi hapus/ubah hanya lewat POST, redirect setelah simpan (refresh tidak menggandakan transaksi), transaksi kasir memakai *database transaction* + kunci stok, serta upload dibatasi tipe & ukuran dan hanya bisa diunduh setelah login.

## Jika ada masalah
| Gejala | Solusi |
|---|---|
| "Database belum siap" | MySQL belum Start, atau `database.sql` belum dijalankan. |
| Emoji tampil `?` | Jalankan ulang `database.sql` (sudah utf8mb4). |
| Upload gagal | File maks. 5 MB; cek `upload_max_filesize` di php.ini. |
| Halaman 404 | Pastikan alamatnya `localhost/martoffice/` (nama folder sama dengan URL). |
| Tampilan polos tanpa font | Font Google butuh internet; tanpa internet tetap jalan dengan font sistem. |
| "Layanan DSS belum berjalan" | Jalankan `python dss/app.py` dan biarkan jendela CMD terbuka. |
| `No module named flask` | Jalankan `python -m pip install flask` (pakai `python -m pip`, bukan `pip`). |
| "Port 5055 sudah dipakai" | Cek `netstat -ano \| findstr :5055`, tutup aplikasinya atau ganti port di kedua file config. |
| DSS: "Tanda tangan token tidak cocok" | `SSO_SECRET` di `includes/config.php` dan `dss/config.py` berbeda — samakan. |
| DSS: "Data TPS belum dapat diambil" | Apache mati atau `MARTOFFICE_URL` di `dss/config.py` salah (default `http://localhost/martoffice`). |

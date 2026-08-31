# TPS Supermarket

Aplikasi Transaction Processing System (TPS) untuk simulasi transaksi pembelian barang di supermarket, dibuat menggunakan PHP dan MySQL.

**Dibuat oleh:** Alysa Salsabila Irfan Putri (24051130049) — TI-J, Universitas Negeri Yogyakarta

## Fitur

- **Login 2 role**: Admin/Kasir dan Pembeli
- **Admin**: kelola produk (tambah/hapus), buat transaksi kasir, lihat semua riwayat transaksi
- **Pembeli**: belanja mandiri (self-checkout), lihat riwayat belanja sendiri
- Perhitungan total, subtotal, dan kembalian otomatis (JavaScript)
- Struktur database TPS: kategori, supplier, produk, pegawai, pelanggan, transaksi, detail_transaksi, pembayaran

## Struktur Database

Struktur tabel MySQL ada di file `database/struktur_tps_supermarket.sql`.

## Cara Menjalankan

1. Jalankan Apache dan MySQL di XAMPP
2. Import file SQL di `database/` lewat phpMyAdmin atau MySQL Workbench
3. Copy folder ini ke `C:\xampp\htdocs\`
4. Akses lewat browser: `http://localhost/tps_supermarket_frontend/login.php`

## Teknologi

- PHP (native, mysqli)
- MySQL
- HTML, CSS, JavaScript

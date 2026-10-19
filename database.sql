-- =====================================================================
--  MartOffice — Sistem Terpadu Supermarket (TPS + OAS + KMS)
--  Jalankan seluruh file ini di MySQL Workbench / phpMyAdmin.
--  Database lama bernama `martoffice` akan dihapus dan dibuat ulang.
-- =====================================================================
DROP DATABASE IF EXISTS martoffice;
CREATE DATABASE martoffice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE martoffice;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  MASTER: pegawai & produk
-- ---------------------------------------------------------------------
CREATE TABLE pegawai (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nama      VARCHAR(80)  NOT NULL,
  username  VARCHAR(40)  NOT NULL UNIQUE,
  password  VARCHAR(255) NOT NULL,
  role      ENUM('admin','manajer','staff') NOT NULL,
  jabatan   VARCHAR(60)  NULL,
  aktif     TINYINT(1)   NOT NULL DEFAULT 1,
  dibuat    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE produk (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  sku       VARCHAR(30)  NOT NULL UNIQUE,
  nama      VARCHAR(80)  NOT NULL,
  ikon      VARCHAR(16)  NOT NULL DEFAULT '🛍️',
  kategori  VARCHAR(40)  NOT NULL,
  satuan    VARCHAR(20)  NOT NULL DEFAULT 'pcs',
  harga     DECIMAL(12,2) NOT NULL,
  stok      INT          NOT NULL DEFAULT 0,
  aktif     TINYINT(1)   NOT NULL DEFAULT 1,
  dibuat    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE stok_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_produk   INT NOT NULL,
  perubahan   INT NOT NULL,
  jenis       ENUM('awal','restok','penjualan','koreksi') NOT NULL,
  keterangan  VARCHAR(150) NULL,
  id_pegawai  INT NULL,
  waktu       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_produk)  REFERENCES produk(id),
  FOREIGN KEY (id_pegawai) REFERENCES pegawai(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  TPS: transaksi kasir
-- ---------------------------------------------------------------------
CREATE TABLE transaksi (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nota      VARCHAR(30) NULL UNIQUE,
  id_kasir  INT NOT NULL,
  metode    ENUM('tunai','debit','qris') NOT NULL,
  total     DECIMAL(12,2) NOT NULL,
  bayar     DECIMAL(12,2) NOT NULL,
  kembali   DECIMAL(12,2) NOT NULL,
  waktu     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_kasir) REFERENCES pegawai(id)
) ENGINE=InnoDB;

CREATE TABLE detail_transaksi (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_transaksi  INT NOT NULL,
  id_produk     INT NOT NULL,
  nama_produk   VARCHAR(80) NOT NULL,
  qty           INT NOT NULL,
  harga         DECIMAL(12,2) NOT NULL,
  subtotal      DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (id_transaksi) REFERENCES transaksi(id) ON DELETE CASCADE,
  FOREIGN KEY (id_produk)    REFERENCES produk(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  OAS: tugas, pengumuman, agenda, dokumen, pesan internal
-- ---------------------------------------------------------------------
CREATE TABLE tugas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  judul         VARCHAR(120) NOT NULL,
  deskripsi     TEXT NULL,
  id_pemberi    INT NOT NULL,
  id_staff      INT NOT NULL,
  prioritas     ENUM('rendah','sedang','tinggi') NOT NULL DEFAULT 'sedang',
  tenggat       DATE NOT NULL,
  status        ENUM('terbuka','proses','selesai') NOT NULL DEFAULT 'terbuka',
  catatan_staff VARCHAR(255) NULL,
  dibuat        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  selesai_pada  DATETIME NULL,
  FOREIGN KEY (id_pemberi) REFERENCES pegawai(id),
  FOREIGN KEY (id_staff)   REFERENCES pegawai(id)
) ENGINE=InnoDB;

CREATE TABLE pengumuman (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  judul       VARCHAR(120) NOT NULL,
  isi         TEXT NOT NULL,
  penting     TINYINT(1) NOT NULL DEFAULT 0,
  id_penulis  INT NOT NULL,
  dibuat      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_penulis) REFERENCES pegawai(id)
) ENGINE=InnoDB;

CREATE TABLE agenda (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  judul       VARCHAR(120) NOT NULL,
  deskripsi   TEXT NULL,
  lokasi      VARCHAR(100) NULL,
  mulai       DATETIME NOT NULL,
  selesai     DATETIME NULL,
  untuk       ENUM('semua','manajemen','staff') NOT NULL DEFAULT 'semua',
  id_pembuat  INT NOT NULL,
  dibuat      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_pembuat) REFERENCES pegawai(id)
) ENGINE=InnoDB;

CREATE TABLE dokumen (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  judul          VARCHAR(150) NOT NULL,
  deskripsi      VARCHAR(255) NULL,
  kategori       ENUM('SOP','Formulir','Laporan','Surat','Lainnya') NOT NULL DEFAULT 'Lainnya',
  nama_file      VARCHAR(120) NOT NULL,
  file_asli      VARCHAR(200) NOT NULL,
  ukuran         INT NOT NULL,
  id_pengunggah  INT NOT NULL,
  dibuat         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_pengunggah) REFERENCES pegawai(id)
) ENGINE=InnoDB;

CREATE TABLE pesan (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  id_pengirim  INT NOT NULL,
  id_penerima  INT NOT NULL,
  subjek       VARCHAR(150) NOT NULL,
  isi          TEXT NOT NULL,
  dibaca       TINYINT(1) NOT NULL DEFAULT 0,
  id_balasan   INT NULL,
  dibuat       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_pengirim) REFERENCES pegawai(id),
  FOREIGN KEY (id_penerima) REFERENCES pegawai(id),
  FOREIGN KEY (id_balasan)  REFERENCES pesan(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  KMS: kategori, artikel, tag, riwayat versi, feedback
-- ---------------------------------------------------------------------
CREATE TABLE kategori_kms (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nama       VARCHAR(60) NOT NULL UNIQUE,
  deskripsi  VARCHAR(200) NULL,
  ikon       VARCHAR(16) NOT NULL DEFAULT '📁',
  aktif      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE artikel_kms (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  judul           VARCHAR(150) NOT NULL,
  id_kategori     INT NOT NULL,
  ringkasan       VARCHAR(255) NOT NULL,
  isi             TEXT NOT NULL,
  id_penulis      INT NOT NULL,
  status          ENUM('menunggu_review','published','ditolak') NOT NULL DEFAULT 'menunggu_review',
  visibilitas     ENUM('internal','terbatas') NOT NULL DEFAULT 'internal',
  id_reviewer     INT NULL,
  catatan_review  VARCHAR(255) NULL,
  versi           INT NOT NULL DEFAULT 1,
  dilihat         INT NOT NULL DEFAULT 0,
  dibuat          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diperbarui      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (id_kategori) REFERENCES kategori_kms(id),
  FOREIGN KEY (id_penulis)  REFERENCES pegawai(id),
  FOREIGN KEY (id_reviewer) REFERENCES pegawai(id)
) ENGINE=InnoDB;

CREATE TABLE tag_kms (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  nama  VARCHAR(40) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE artikel_tag (
  id_artikel  INT NOT NULL,
  id_tag      INT NOT NULL,
  PRIMARY KEY (id_artikel, id_tag),
  FOREIGN KEY (id_artikel) REFERENCES artikel_kms(id) ON DELETE CASCADE,
  FOREIGN KEY (id_tag)     REFERENCES tag_kms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE artikel_versi (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  id_artikel         INT NOT NULL,
  versi              INT NOT NULL,
  judul              VARCHAR(150) NOT NULL,
  id_kategori        INT NOT NULL,
  ringkasan          VARCHAR(255) NOT NULL,
  isi                TEXT NOT NULL,
  tag_teks           VARCHAR(255) NULL,
  id_editor          INT NOT NULL,
  catatan_perubahan  VARCHAR(255) NULL,
  dibuat             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_versi (id_artikel, versi),
  FOREIGN KEY (id_artikel) REFERENCES artikel_kms(id) ON DELETE CASCADE,
  FOREIGN KEY (id_editor)  REFERENCES pegawai(id)
) ENGINE=InnoDB;

CREATE TABLE feedback_kms (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_artikel  INT NOT NULL,
  id_pegawai  INT NOT NULL,
  membantu    TINYINT(1) NOT NULL,
  komentar    VARCHAR(255) NULL,
  dibuat      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_feedback (id_artikel, id_pegawai),
  FOREIGN KEY (id_artikel) REFERENCES artikel_kms(id) ON DELETE CASCADE,
  FOREIGN KEY (id_pegawai) REFERENCES pegawai(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
--  MIS: tabel agregasi penjualan harian (diisi ulang otomatis oleh MIS
--  dari tabel transaksi & detail_transaksi setiap halaman MIS dibuka)
-- ---------------------------------------------------------------------
CREATE TABLE mis_penjualan_harian (
  tanggal           DATE PRIMARY KEY,
  jumlah_transaksi  INT NOT NULL DEFAULT 0,
  omzet             DECIMAL(15,2) NOT NULL DEFAULT 0,
  rata_belanja      DECIMAL(15,2) NOT NULL DEFAULT 0,
  barang_terjual    INT NOT NULL DEFAULT 0,
  omzet_tunai       DECIMAL(15,2) NOT NULL DEFAULT 0,
  omzet_debit       DECIMAL(15,2) NOT NULL DEFAULT 0,
  omzet_qris        DECIMAL(15,2) NOT NULL DEFAULT 0,
  diperbarui        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
--  DATA AWAL (hanya data master yang dibutuhkan untuk mulai bekerja)
--  Password sudah di-hash (bcrypt):
--    admin / admin123   ·   manajer / manajer123   ·   staff / staff123
-- =====================================================================
INSERT INTO pegawai (nama, username, password, role, jabatan) VALUES
('Administrator',   'admin',   '$2y$12$k5jZq9icVFDEbo8QLl1Z7.n0sJNFMbWVbm/De2qvN3cqJrWUtk1z.', 'admin',   'Admin Sistem'),
('Alysa Salsabila', 'manajer', '$2y$12$/ntm9.wpNjVezctOHKi8cOk3igfDXrDy8ELe4iph1gBzTqdRdUIHu', 'manajer', 'Store Manager'),
('Rafa Fadhilah',   'staff',   '$2y$12$C.iIPolEml6bcyzrGQcE7uxR2pGjXXz8RCwy1OKO.AOzoDePpxIs6', 'staff',   'Kasir & Staf Toko');

INSERT INTO produk (sku, nama, ikon, kategori, satuan, harga, stok) VALUES
('BS-001', 'Apel Fuji 1kg',        '🍎', 'Buah & Sayur',   'pack',  32000,  40),
('BS-002', 'Pisang Cavendish 1kg', '🍌', 'Buah & Sayur',   'sisir', 24000,  35),
('BS-003', 'Wortel 500g',          '🥕', 'Buah & Sayur',   'pack',   9000,  60),
('BS-004', 'Brokoli 250g',         '🥦', 'Buah & Sayur',   'pack',  12500,   8),
('SU-001', 'Susu UHT 1L',          '🥛', 'Susu & Olahan',  'kotak', 18500,  50),
('SU-002', 'Keju Cheddar 165g',    '🧀', 'Susu & Olahan',  'pcs',   27000,  18),
('RB-001', 'Roti Tawar',           '🍞', 'Roti & Bakery',  'pcs',   15000,  30),
('RB-002', 'Croissant Butter',     '🥐', 'Roti & Bakery',  'pcs',   12000,   6),
('SM-001', 'Telur Ayam 1kg',       '🥚', 'Sembako',        'pack',  28000,  45),
('SM-002', 'Beras Premium 5kg',    '🍚', 'Sembako',        'karung',72000,  25),
('DI-001', 'Ayam Fillet 500g',     '🍗', 'Daging & Ikan',  'pack',  36000,  20),
('DI-002', 'Ikan Salmon 200g',     '🐟', 'Daging & Ikan',  'pack',  58000,   9),
('MN-001', 'Air Mineral 600ml',    '💧', 'Minuman',        'botol',  3500, 120),
('MN-002', 'Kopi Bubuk 200g',      '☕', 'Minuman',        'pack',  24500,  28);

INSERT INTO stok_log (id_produk, perubahan, jenis, keterangan, id_pegawai)
SELECT id, stok, 'awal', 'Stok awal saat sistem dipasang', 1 FROM produk;

INSERT INTO kategori_kms (nama, deskripsi, ikon) VALUES
('Operasional Toko',   'SOP kasir, stok, dan pelayanan pelanggan',      '🏪'),
('Produk & Penjualan', 'Pengetahuan produk, display, dan promosi',      '🛒'),
('SDM & Administrasi', 'Kebijakan kerja dan pembelajaran internal',     '👥'),
('Sistem & TI',        'Panduan penggunaan TPS, OAS, KMS, dan akun',    '💻');

INSERT INTO artikel_kms (judul, id_kategori, ringkasan, isi, id_penulis, status, visibilitas, id_reviewer) VALUES
('Panduan Memproses Transaksi Kasir', 1,
 'Langkah standar kasir dari memilih barang sampai kembalian.',
 '1. Buka menu Kasir, cari produk dengan nama atau SKU.\n2. Tambahkan produk ke keranjang dan atur jumlahnya.\n3. Periksa total belanja bersama pelanggan.\n4. Pilih metode bayar (Tunai / Debit / QRIS) dan masukkan uang yang diterima.\n5. Pastikan kembalian benar, lalu tekan "Bayar & Cetak Nota".\n6. Cetak nota dan serahkan ke pelanggan.',
 2, 'published', 'internal', 2),
('SOP Pengecekan Stok Harian', 1,
 'Cara memeriksa dan melaporkan stok yang menipis.',
 'Setiap pagi sebelum toko buka:\n- Buka menu Stok Produk dan lihat produk bertanda "menipis".\n- Cocokkan jumlah fisik di rak dengan angka di sistem.\n- Laporkan produk yang stoknya di bawah batas minimum ke manajer melalui menu Pesan sebelum pukul 10.00.\n- Manajer melakukan restok dan sistem mencatatnya di riwayat stok.',
 2, 'published', 'internal', 2),
('Menata Display Buah & Sayur', 2,
 'Tips display agar produk segar tampak menarik dan tidak cepat rusak.',
 'Susun produk berdasarkan warna agar rak terlihat segar.\nTerapkan FIFO: barang lama di depan, barang baru di belakang.\nSemprot air secara berkala pada sayuran daun.\nPisahkan buah yang mulai lunak dan laporkan untuk dijadikan promo.',
 2, 'published', 'internal', 2);

INSERT INTO tag_kms (nama) VALUES ('kasir'),('tps'),('sop'),('stok'),('display'),('segar');
INSERT INTO artikel_tag (id_artikel, id_tag) VALUES (1,1),(1,2),(1,3),(2,4),(2,3),(3,5),(3,6);

INSERT INTO artikel_versi (id_artikel, versi, judul, id_kategori, ringkasan, isi, tag_teks, id_editor, catatan_perubahan)
SELECT a.id, 1, a.judul, a.id_kategori, a.ringkasan, a.isi,
       (SELECT GROUP_CONCAT(t.nama ORDER BY t.nama SEPARATOR ', ') FROM artikel_tag x JOIN tag_kms t ON t.id = x.id_tag WHERE x.id_artikel = a.id),
       a.id_penulis, 'Versi awal'
FROM artikel_kms a;

-- =====================================================================
--  MartOffice — DATABASE EXPERT SYSTEM (ES)
--  Menu 1: Rekomendasi Produk sesuai Kebutuhan Pelanggan   (untuk pembeli)
--  Menu 2: Waktu Order Barang                               (untuk manajer)
--
--  Database ES dibuat di database MySQL yang sama (martoffice) dengan
--  awalan tabel es_ agar dapat langsung memakai data modul sebelumnya:
--    TPS : produk, stok, transaksi, detail_transaksi   (fakta produk & stok)
--    OAS : tugas, pegawai                               (tindak lanjut permintaan)
--    KMS : artikel_kms                                  (sumber pengetahuan pakar)
--    MIS : aturan peringatan stok + view ringkasan ES   (pemantauan manajer)
--    DSS : es_dss_sinkron                               (hasil DSS: supplier, promo, bundling, restok)
--
--  Metode: rule-based forward chaining + Certainty Factor (CF)
--    Menu 1: fakta = jawaban pembeli, kesimpulan = produk yang dianjurkan/dihindari
--    Menu 2: fakta dihitung otomatis dari TPS/OAS/KMS/MIS/DSS, kesimpulan = kapan order
--    CF(aturan, produk) = CF pakar aturan × bobot tag produk
--    CF gabungan (beberapa aturan) = 1 − Π(1 − CFi)      (setara CF1 + CF2 × (1 − CF1))
--    CF akhir produk = CF anjuran × (1 − CF hindaran)
--    Produk hanya dinilai bila cocok dengan aturan utama (tujuan belanja)
--
--  Cara pakai: jalankan database.sql (dan data_simulasi.sql bila dipakai)
--  lebih dulu, lalu jalankan file ini. File ini aman dijalankan ulang:
--  tabel es_ dibuat ulang tanpa mengubah tabel TPS/OAS/KMS/MIS.
-- =====================================================================
USE martoffice;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS v_es_mis_order, v_es_order_terbaru, v_es_mis_produk_dicari, v_es_mis_harian, v_es_rekomendasi, v_es_skor_produk,
                    v_es_cf_produk, v_es_aturan_aktif, v_es_basis_pengetahuan, v_es_produk;
DROP TABLE IF EXISTS es_order_fakta, es_order_analisis, es_pengaturan, es_kesimpulan, es_permintaan, es_konsultasi_hasil, es_konsultasi_fakta, es_konsultasi,
                     es_dss_sinkron, es_aturan_syarat, es_aturan, es_produk_tag, es_tag,
                     es_pilihan, es_pertanyaan, es_fakta, es_menu;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. MENU sistem pakar (menu 1 dibuat sekarang, menu lain menyusul)
-- ---------------------------------------------------------------------
CREATE TABLE es_menu (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  kode       VARCHAR(20)  NOT NULL UNIQUE,
  nama       VARCHAR(100) NOT NULL,
  deskripsi  VARCHAR(255) NOT NULL,
  metode     VARCHAR(80)  NOT NULL,
  pengguna   VARCHAR(80)  NOT NULL,
  aktif      TINYINT(1)   NOT NULL DEFAULT 1,
  urutan     INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. FAKTA (premis) yang dapat diberikan pengguna
-- ---------------------------------------------------------------------
CREATE TABLE es_fakta (
  kode       VARCHAR(20)  PRIMARY KEY,
  id_menu    INT          NOT NULL,
  nama       VARCHAR(120) NOT NULL,
  keterangan VARCHAR(255) NULL,
  sumber     ENUM('Pengguna','TPS','OAS','KMS','MIS','DSS','ES') NOT NULL DEFAULT 'Pengguna',
  otomatis   TINYINT(1)   NOT NULL DEFAULT 0,       -- 1 = dihitung sistem dari data modul lain
  cara       VARCHAR(255) NULL,                     -- cara menghitung fakta otomatis
  FOREIGN KEY (id_menu) REFERENCES es_menu(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kesimpulan (goal) untuk menu yang hasilnya berupa keputusan, mis. kapan order
CREATE TABLE es_kesimpulan (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  id_menu    INT          NOT NULL,
  kode       VARCHAR(10)  NOT NULL UNIQUE,
  nama       VARCHAR(100) NOT NULL,
  tindakan   VARCHAR(255) NOT NULL,
  prioritas  INT          NOT NULL,                 -- 1 = paling mendesak
  warna      CHAR(1)      NOT NULL DEFAULT 'g',     -- r merah · o oranye · y kuning · g hijau · n abu
  FOREIGN KEY (id_menu) REFERENCES es_menu(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- parameter mesin inferensi (sebagian diperbarui dari DSS: siklus, stok pengaman, lead time)
CREATE TABLE es_pengaturan (
  kunci      VARCHAR(30)  PRIMARY KEY,
  nilai      DECIMAL(10,2) NOT NULL,
  keterangan VARCHAR(255) NOT NULL,
  sumber     VARCHAR(10)  NOT NULL DEFAULT 'ES',
  diperbarui DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. PERTANYAAN konsultasi & PILIHAN jawaban (setiap pilihan = satu fakta)
-- ---------------------------------------------------------------------
CREATE TABLE es_pertanyaan (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  id_menu    INT          NOT NULL,
  kode       VARCHAR(10)  NOT NULL UNIQUE,
  pertanyaan VARCHAR(200) NOT NULL,
  bantuan    VARCHAR(255) NULL,
  wajib      TINYINT(1)   NOT NULL DEFAULT 1,
  urutan     INT          NOT NULL DEFAULT 0,
  FOREIGN KEY (id_menu) REFERENCES es_menu(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE es_pilihan (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  id_pertanyaan  INT          NOT NULL,
  label          VARCHAR(120) NOT NULL,
  kode_fakta     VARCHAR(20)  NOT NULL,
  urutan         INT          NOT NULL DEFAULT 0,
  FOREIGN KEY (id_pertanyaan) REFERENCES es_pertanyaan(id) ON DELETE CASCADE,
  FOREIGN KEY (kode_fakta)    REFERENCES es_fakta(kode) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. TAG produk = pengetahuan produk dari staf penjualan
--    es_produk_tag menghubungkan produk TPS dengan tag + bobot keyakinan pakar
-- ---------------------------------------------------------------------
CREATE TABLE es_tag (
  kode       VARCHAR(20)  PRIMARY KEY,
  nama       VARCHAR(80)  NOT NULL,
  keterangan VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE es_produk_tag (
  id_produk  INT          NOT NULL,
  kode_tag   VARCHAR(20)  NOT NULL,
  bobot      DECIMAL(3,2) NOT NULL DEFAULT 1.00,   -- 0,00–1,00: seberapa kuat produk memiliki sifat tag
  PRIMARY KEY (id_produk, kode_tag),
  FOREIGN KEY (id_produk) REFERENCES produk(id) ON DELETE CASCADE,
  FOREIGN KEY (kode_tag)  REFERENCES es_tag(kode) ON DELETE CASCADE,
  CHECK (bobot BETWEEN 0 AND 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. ATURAN (basis pengetahuan): JIKA semua syarat terpenuhi MAKA anjurkan/hindari tag
-- ---------------------------------------------------------------------
CREATE TABLE es_aturan (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  id_menu     INT          NOT NULL,
  kode        VARCHAR(10)  NOT NULL UNIQUE,
  aksi        ENUM('anjurkan','hindari','simpulkan') NOT NULL,
  utama       TINYINT(1)   NOT NULL DEFAULT 0,      -- 1 = aturan tujuan belanja; produk wajib cocok minimal satu aturan utama
  kode_tag    VARCHAR(20)  NULL,                    -- menu 1: tag produk yang dianjurkan/dihindari
  kode_kesimpulan VARCHAR(10) NULL,                 -- menu 2: kesimpulan (kapan order)
  cf_pakar    DECIMAL(3,2) NOT NULL,
  penjelasan  VARCHAR(255) NOT NULL,                -- alasan yang ditampilkan ke pelanggan
  id_artikel  INT          NULL,                    -- sumber pengetahuan di KMS
  aktif       TINYINT(1)   NOT NULL DEFAULT 1,
  FOREIGN KEY (id_menu)    REFERENCES es_menu(id) ON DELETE CASCADE,
  FOREIGN KEY (kode_tag)   REFERENCES es_tag(kode),
  FOREIGN KEY (kode_kesimpulan) REFERENCES es_kesimpulan(kode),
  FOREIGN KEY (id_artikel) REFERENCES artikel_kms(id) ON DELETE SET NULL,
  CHECK (cf_pakar BETWEEN 0 AND 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE es_aturan_syarat (
  id_aturan   INT         NOT NULL,
  kode_fakta  VARCHAR(20) NOT NULL,
  PRIMARY KEY (id_aturan, kode_fakta),
  FOREIGN KEY (id_aturan)  REFERENCES es_aturan(id) ON DELETE CASCADE,
  FOREIGN KEY (kode_fakta) REFERENCES es_fakta(kode) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. HASIL DSS yang dipakai ES (diisi/diperbarui oleh DSS lewat API)
-- ---------------------------------------------------------------------
CREATE TABLE es_dss_sinkron (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  jenis       ENUM('supplier','promo','bundling','restok') NOT NULL,
  id_produk   INT          NULL,
  id_produk_2 INT          NULL,                    -- pasangan produk (bundling)
  kategori    VARCHAR(40)  NULL,
  label       VARCHAR(150) NOT NULL,
  nilai       DECIMAL(12,4) NULL,                   -- skor SAW, lift, atau jumlah pesan
  lead_hari   DECIMAL(5,2) NULL,                    -- lama pengiriman supplier terpilih (kriteria SAW)
  keterangan  VARCHAR(255) NULL,
  disinkron   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_produk)   REFERENCES produk(id) ON DELETE CASCADE,
  FOREIGN KEY (id_produk_2) REFERENCES produk(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. KONSULTASI (riwayat), FAKTA yang dipilih, dan HASIL rekomendasi
-- ---------------------------------------------------------------------
CREATE TABLE es_konsultasi (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  id_menu        INT          NOT NULL,
  id_pegawai     INT          NOT NULL,             -- staf yang melayani (pegawai MartOffice)
  nama_pelanggan VARCHAR(80)  NULL,
  catatan        VARCHAR(255) NULL,
  waktu          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_menu)    REFERENCES es_menu(id),
  FOREIGN KEY (id_pegawai) REFERENCES pegawai(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE es_konsultasi_fakta (
  id_konsultasi INT          NOT NULL,
  kode_fakta    VARCHAR(20)  NOT NULL,
  cf_pengguna   DECIMAL(3,2) NOT NULL DEFAULT 1.00, -- keyakinan jawaban pengguna
  PRIMARY KEY (id_konsultasi, kode_fakta),
  FOREIGN KEY (id_konsultasi) REFERENCES es_konsultasi(id) ON DELETE CASCADE,
  FOREIGN KEY (kode_fakta)    REFERENCES es_fakta(kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE es_konsultasi_hasil (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_konsultasi INT          NOT NULL,
  id_produk     INT          NOT NULL,
  peringkat     INT          NOT NULL,
  cf_akhir      DECIMAL(5,4) NOT NULL,
  alasan        VARCHAR(500) NOT NULL,
  stok_saat_itu INT          NOT NULL,              -- snapshot stok TPS
  dibeli        TINYINT(1)   NOT NULL DEFAULT 0,    -- ditandai bila pelanggan jadi membeli
  FOREIGN KEY (id_konsultasi) REFERENCES es_konsultasi(id) ON DELETE CASCADE,
  FOREIGN KEY (id_produk)     REFERENCES produk(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- produk cocok tetapi stok habis → permintaan tak terpenuhi → tugas OAS & masukan DSS restok
CREATE TABLE es_permintaan (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  id_konsultasi INT          NOT NULL,
  id_produk     INT          NOT NULL,
  status        ENUM('baru','ditugaskan','selesai') NOT NULL DEFAULT 'baru',
  id_tugas      INT          NULL,                  -- tugas OAS yang dibuat
  dibuat        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_konsultasi) REFERENCES es_konsultasi(id) ON DELETE CASCADE,
  FOREIGN KEY (id_produk)     REFERENCES produk(id),
  FOREIGN KEY (id_tugas)      REFERENCES tugas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. ANALISIS WAKTU ORDER (menu 2, manajer): satu baris per produk per analisis
-- ---------------------------------------------------------------------
CREATE TABLE es_order_analisis (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  id_produk       INT          NOT NULL,
  dianalisis      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kode_kesimpulan VARCHAR(10)  NOT NULL,
  cf              DECIMAL(5,4) NOT NULL,
  tanggal_order   DATE         NULL,                -- kapan sebaiknya order dibuat
  qty_saran       INT          NOT NULL DEFAULT 0,
  stok            INT          NOT NULL,            -- snapshot TPS
  rata_harian     DECIMAL(8,2) NOT NULL,
  sisa_hari       DECIMAL(8,2) NULL,                -- stok ÷ rata harian
  lead_hari       DECIMAL(5,2) NOT NULL,            -- dari supplier terpilih DSS
  supplier        VARCHAR(150) NULL,
  aturan          VARCHAR(255) NOT NULL,            -- aturan yang aktif
  alasan          TEXT         NOT NULL,
  status          ENUM('saran','ditugaskan','diabaikan') NOT NULL DEFAULT 'saran',
  id_tugas        INT          NULL,                -- tugas order yang dikirim ke OAS
  dibuat_oleh     INT          NULL,
  FOREIGN KEY (id_produk)       REFERENCES produk(id) ON DELETE CASCADE,
  FOREIGN KEY (kode_kesimpulan) REFERENCES es_kesimpulan(kode),
  FOREIGN KEY (id_tugas)        REFERENCES tugas(id) ON DELETE SET NULL,
  FOREIGN KEY (dibuat_oleh)     REFERENCES pegawai(id) ON DELETE SET NULL,
  INDEX (dianalisis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE es_order_fakta (
  id_analisis INT          NOT NULL,
  kode_fakta  VARCHAR(20)  NOT NULL,
  nilai       VARCHAR(120) NOT NULL,                -- bukti, mis. "sisa 1,5 hari ≤ lead 2 + aman 2"
  PRIMARY KEY (id_analisis, kode_fakta),
  FOREIGN KEY (id_analisis) REFERENCES es_order_analisis(id) ON DELETE CASCADE,
  FOREIGN KEY (kode_fakta)  REFERENCES es_fakta(kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  DATA AWAL (basis pengetahuan)
-- =====================================================================
INSERT INTO es_menu (kode, nama, deskripsi, metode, pengguna, aktif, urutan) VALUES
('REKOMENDASI', 'Rekomendasi Produk sesuai Kebutuhan Pelanggan', 'Memberi saran produk yang paling tepat untuk pembeli sesuai tujuan belanja, jumlah orang, anggaran, preferensi, dan waktu menyiapkan, beserta alasannya.', 'Forward chaining + Certainty Factor', 'Pembeli / staf toko', 1, 1),
('ORDER',       'Waktu Order Barang',                              'Memberi keputusan kapan waktu paling tepat membuat order barang ke supplier untuk setiap produk, beserta jumlah dan alasannya.', 'Forward chaining + Certainty Factor', 'Manajer', 1, 2),
('MASALAH',     'Identifikasi Masalah dan Penanganan Produk',      'Menentukan dugaan penyebab dan penanganan produk bermasalah (jual, diskon, retur, buang).', 'Forward chaining + Certainty Factor', 'Staf gudang', 0, 3),
('RETUR',       'Pemeriksaan Kelayakan Retur/Penukaran',           'Menentukan retur layak, tidak layak, atau perlu pemeriksaan staf.', 'Forward chaining', 'Kasir', 0, 4),
('STOK',        'Diagnosis Penyebab Stok Menumpuk',                'Menentukan kemungkinan penyebab stok menumpuk dan saran penanganan.', 'Forward chaining + Certainty Factor', 'Manajer', 0, 5),
('PROMOSI',     'Pemeriksaan Kelayakan Promosi',                   'Memeriksa apakah promo dari DSS layak dijalankan, perlu penyesuaian, atau ditolak.', 'Forward chaining', 'Manajer', 0, 6);

SET @m := (SELECT id FROM es_menu WHERE kode='REKOMENDASI');

INSERT INTO es_fakta (kode, id_menu, nama) VALUES
('F01', @m, 'Tujuan belanja: sarapan'),
('F02', @m, 'Tujuan belanja: masak harian'),
('F03', @m, 'Tujuan belanja: bekal sekolah/kerja'),
('F04', @m, 'Tujuan belanja: acara/kumpul keluarga'),
('F05', @m, 'Tujuan belanja: stok bulanan'),
('F06', @m, 'Tujuan belanja: camilan & santai'),
('F07', @m, 'Jumlah orang: 1–2'),
('F08', @m, 'Jumlah orang: 3–4'),
('F09', @m, 'Jumlah orang: 5 atau lebih'),
('F10', @m, 'Anggaran hemat (< Rp50.000)'),
('F11', @m, 'Anggaran sedang (Rp50.000–150.000)'),
('F12', @m, 'Anggaran longgar (> Rp150.000)'),
('F13', @m, 'Preferensi: tidak ada'),
('F14', @m, 'Preferensi: kurangi gula'),
('F15', @m, 'Preferensi: vegetarian (tanpa daging & ikan)'),
('F16', @m, 'Preferensi: tinggi protein'),
('F17', @m, 'Waktu menyiapkan: sangat singkat (siap santap)'),
('F18', @m, 'Waktu menyiapkan: biasa (masak sebentar)'),
('F19', @m, 'Waktu menyiapkan: punya banyak waktu');

INSERT INTO es_pertanyaan (id_menu, kode, pertanyaan, bantuan, urutan) VALUES
(@m, 'Q1', 'Untuk keperluan apa belanja hari ini?', 'Pilih tujuan utama belanja pelanggan.', 1),
(@m, 'Q2', 'Untuk berapa orang?', 'Dipakai untuk menyarankan jumlah barang.', 2),
(@m, 'Q3', 'Berapa kira-kira anggarannya?', NULL, 3),
(@m, 'Q4', 'Apakah ada preferensi atau pantangan?', NULL, 4),
(@m, 'Q5', 'Berapa lama waktu untuk menyiapkan makanan?', NULL, 5);

INSERT INTO es_pilihan (id_pertanyaan, label, kode_fakta, urutan)
SELECT q.id, x.label, x.fakta, x.urut FROM es_pertanyaan q JOIN (
  SELECT 'Q1' q, 'Sarapan' label, 'F01' fakta, 1 urut UNION ALL SELECT 'Q1', 'Masak harian', 'F02', 2 UNION ALL
  SELECT 'Q1', 'Bekal sekolah/kerja', 'F03', 3 UNION ALL SELECT 'Q1', 'Acara/kumpul keluarga', 'F04', 4 UNION ALL
  SELECT 'Q1', 'Stok bulanan', 'F05', 5 UNION ALL SELECT 'Q1', 'Camilan & santai', 'F06', 6 UNION ALL
  SELECT 'Q2', '1–2 orang', 'F07', 1 UNION ALL SELECT 'Q2', '3–4 orang', 'F08', 2 UNION ALL SELECT 'Q2', '5 orang atau lebih', 'F09', 3 UNION ALL
  SELECT 'Q3', 'Hemat (< Rp50.000)', 'F10', 1 UNION ALL SELECT 'Q3', 'Sedang (Rp50.000–150.000)', 'F11', 2 UNION ALL SELECT 'Q3', 'Longgar (> Rp150.000)', 'F12', 3 UNION ALL
  SELECT 'Q4', 'Tidak ada', 'F13', 1 UNION ALL SELECT 'Q4', 'Kurangi gula', 'F14', 2 UNION ALL
  SELECT 'Q4', 'Vegetarian', 'F15', 3 UNION ALL SELECT 'Q4', 'Tinggi protein', 'F16', 4 UNION ALL
  SELECT 'Q5', 'Sangat singkat (siap santap)', 'F17', 1 UNION ALL SELECT 'Q5', 'Biasa (masak sebentar)', 'F18', 2 UNION ALL
  SELECT 'Q5', 'Punya banyak waktu', 'F19', 3
) x ON x.q = q.kode;

INSERT INTO es_tag (kode, nama, keterangan) VALUES
('sarapan',      'Cocok untuk sarapan',        'Praktis dan umum dikonsumsi pagi hari'),
('masak',        'Bahan masakan',              'Bahan utama atau pelengkap masakan rumah'),
('bekal',        'Cocok untuk bekal',          'Mudah dibawa dan tahan beberapa jam'),
('acara',        'Cocok untuk acara',          'Disajikan untuk banyak orang'),
('stok_bulanan', 'Kebutuhan stok bulanan',     'Tahan lama dan rutin dibeli'),
('camilan',      'Camilan',                    'Dimakan di sela waktu santai'),
('siap_santap',  'Siap santap',                'Bisa langsung dikonsumsi tanpa dimasak'),
('protein',      'Sumber protein',             'Kandungan protein tinggi'),
('sayur_buah',   'Sayur & buah',               'Sumber serat dan vitamin'),
('sehat',        'Pilihan sehat',              'Rendah gula atau bergizi'),
('manis',        'Mengandung gula tinggi',     'Dihindari pelanggan yang mengurangi gula'),
('hewani',       'Daging/ikan',                'Dihindari pelanggan vegetarian'),
('minuman',      'Minuman',                    'Pelengkap belanja'),
('hemat',        'Harga hemat',                'Harga terjangkau per porsi'),
('premium',      'Produk premium',             'Harga relatif tinggi'),
('rumah_tangga', 'Kebutuhan rumah tangga',     'Perlengkapan non-makanan');

-- pengetahuan produk dari staf penjualan (berdasarkan SKU TPS; SKU yang belum ada otomatis dilewati)
INSERT INTO es_produk_tag (id_produk, kode_tag, bobot)
SELECT p.id, x.tag, x.bobot FROM produk p JOIN (
  SELECT 'BS-001' sku, 'sayur_buah' tag, 1.00 bobot UNION ALL SELECT 'BS-001','bekal',0.90 UNION ALL SELECT 'BS-001','sehat',0.90 UNION ALL SELECT 'BS-001','camilan',0.50 UNION ALL SELECT 'BS-001','siap_santap',0.80 UNION ALL
  SELECT 'BS-002','sayur_buah',1.00 UNION ALL SELECT 'BS-002','sarapan',0.80 UNION ALL SELECT 'BS-002','bekal',0.80 UNION ALL SELECT 'BS-002','sehat',0.80 UNION ALL SELECT 'BS-002','hemat',0.60 UNION ALL SELECT 'BS-002','siap_santap',0.90 UNION ALL
  SELECT 'BS-003','sayur_buah',1.00 UNION ALL SELECT 'BS-003','masak',0.90 UNION ALL SELECT 'BS-003','sehat',0.80 UNION ALL SELECT 'BS-003','hemat',0.80 UNION ALL
  SELECT 'BS-004','sayur_buah',1.00 UNION ALL SELECT 'BS-004','masak',0.90 UNION ALL SELECT 'BS-004','sehat',1.00 UNION ALL
  SELECT 'BS-005','sayur_buah',1.00 UNION ALL SELECT 'BS-005','bekal',0.70 UNION ALL SELECT 'BS-005','sehat',0.80 UNION ALL SELECT 'BS-005','acara',0.50 UNION ALL SELECT 'BS-005','siap_santap',0.80 UNION ALL
  SELECT 'BS-006','sayur_buah',1.00 UNION ALL SELECT 'BS-006','masak',0.90 UNION ALL SELECT 'BS-006','hemat',0.90 UNION ALL SELECT 'BS-006','sehat',0.70 UNION ALL
  SELECT 'CM-001','camilan',1.00 UNION ALL SELECT 'CM-001','acara',0.80 UNION ALL SELECT 'CM-001','siap_santap',0.90 UNION ALL SELECT 'CM-001','hemat',0.60 UNION ALL
  SELECT 'CM-002','camilan',0.90 UNION ALL SELECT 'CM-002','manis',1.00 UNION ALL SELECT 'CM-002','acara',0.60 UNION ALL SELECT 'CM-002','siap_santap',0.90 UNION ALL
  SELECT 'CM-003','sehat',0.70 UNION ALL SELECT 'CM-003','manis',0.80 UNION ALL SELECT 'CM-003','sarapan',0.50 UNION ALL SELECT 'CM-003','premium',0.80 UNION ALL
  SELECT 'DI-001','protein',1.00 UNION ALL SELECT 'DI-001','masak',1.00 UNION ALL SELECT 'DI-001','hewani',1.00 UNION ALL SELECT 'DI-001','acara',0.60 UNION ALL
  SELECT 'DI-002','protein',1.00 UNION ALL SELECT 'DI-002','masak',0.80 UNION ALL SELECT 'DI-002','hewani',1.00 UNION ALL SELECT 'DI-002','premium',1.00 UNION ALL SELECT 'DI-002','sehat',0.70 UNION ALL
  SELECT 'PR-001','rumah_tangga',1.00 UNION ALL SELECT 'PR-001','stok_bulanan',0.90 UNION ALL
  SELECT 'PR-002','rumah_tangga',1.00 UNION ALL SELECT 'PR-002','stok_bulanan',0.80 UNION ALL SELECT 'PR-002','acara',0.50 UNION ALL
  SELECT 'PR-003','rumah_tangga',0.80 UNION ALL SELECT 'PR-003','acara',0.40 UNION ALL SELECT 'PR-003','premium',0.60 UNION ALL
  SELECT 'MN-001','minuman',1.00 UNION ALL SELECT 'MN-001','acara',0.90 UNION ALL SELECT 'MN-001','bekal',0.70 UNION ALL SELECT 'MN-001','hemat',1.00 UNION ALL SELECT 'MN-001','siap_santap',1.00 UNION ALL
  SELECT 'MN-002','minuman',0.90 UNION ALL SELECT 'MN-002','sarapan',0.80 UNION ALL SELECT 'MN-002','stok_bulanan',0.50 UNION ALL
  SELECT 'MN-003','minuman',1.00 UNION ALL SELECT 'MN-003','manis',0.80 UNION ALL SELECT 'MN-003','acara',0.80 UNION ALL SELECT 'MN-003','siap_santap',1.00 UNION ALL SELECT 'MN-003','bekal',0.60 UNION ALL
  SELECT 'MN-004','minuman',1.00 UNION ALL SELECT 'MN-004','sarapan',0.80 UNION ALL SELECT 'MN-004','manis',0.60 UNION ALL SELECT 'MN-004','sehat',0.50 UNION ALL SELECT 'MN-004','acara',0.60 UNION ALL
  SELECT 'RB-001','sarapan',1.00 UNION ALL SELECT 'RB-001','bekal',0.90 UNION ALL SELECT 'RB-001','siap_santap',0.80 UNION ALL SELECT 'RB-001','hemat',0.70 UNION ALL
  SELECT 'RB-002','sarapan',0.80 UNION ALL SELECT 'RB-002','siap_santap',1.00 UNION ALL SELECT 'RB-002','manis',0.40 UNION ALL SELECT 'RB-002','premium',0.50 UNION ALL
  SELECT 'RB-003','camilan',0.90 UNION ALL SELECT 'RB-003','manis',1.00 UNION ALL SELECT 'RB-003','acara',0.90 UNION ALL SELECT 'RB-003','siap_santap',1.00 UNION ALL
  SELECT 'SM-001','protein',0.90 UNION ALL SELECT 'SM-001','sarapan',0.80 UNION ALL SELECT 'SM-001','masak',0.90 UNION ALL SELECT 'SM-001','hemat',0.80 UNION ALL SELECT 'SM-001','stok_bulanan',0.60 UNION ALL
  SELECT 'SM-002','stok_bulanan',1.00 UNION ALL SELECT 'SM-002','masak',0.90 UNION ALL SELECT 'SM-002','hemat',0.70 UNION ALL
  SELECT 'SM-003','stok_bulanan',1.00 UNION ALL SELECT 'SM-003','masak',0.90 UNION ALL
  SELECT 'SM-004','stok_bulanan',0.90 UNION ALL SELECT 'SM-004','manis',1.00 UNION ALL SELECT 'SM-004','masak',0.50 UNION ALL
  SELECT 'SM-005','siap_santap',0.80 UNION ALL SELECT 'SM-005','stok_bulanan',0.80 UNION ALL SELECT 'SM-005','hemat',1.00 UNION ALL
  SELECT 'SU-001','sarapan',0.90 UNION ALL SELECT 'SU-001','protein',0.60 UNION ALL SELECT 'SU-001','bekal',0.80 UNION ALL SELECT 'SU-001','minuman',0.70 UNION ALL SELECT 'SU-001','stok_bulanan',0.50 UNION ALL SELECT 'SU-001','siap_santap',1.00 UNION ALL
  SELECT 'SU-002','sarapan',0.60 UNION ALL SELECT 'SU-002','protein',0.70 UNION ALL SELECT 'SU-002','premium',0.60 UNION ALL SELECT 'SU-002','bekal',0.50 UNION ALL
  SELECT 'SU-003','sehat',0.90 UNION ALL SELECT 'SU-003','sarapan',0.70 UNION ALL SELECT 'SU-003','protein',0.60 UNION ALL SELECT 'SU-003','camilan',0.50 UNION ALL SELECT 'SU-003','siap_santap',0.90
) x ON x.sku = p.sku;

-- sumber pengetahuan pakar di KMS (dibuat sekali bila belum ada)
INSERT INTO artikel_kms (judul, id_kategori, ringkasan, isi, id_penulis, status, visibilitas, id_reviewer, versi)
SELECT 'Panduan Merekomendasikan Produk ke Pelanggan',
       (SELECT id FROM kategori_kms WHERE nama='Produk & Penjualan' LIMIT 1),
       'Cara menyarankan produk sesuai tujuan belanja, jumlah orang, anggaran, dan pantangan pelanggan.',
       'Tanyakan tujuan belanja lebih dulu (sarapan, masak harian, bekal, acara, stok bulanan, atau camilan). Untuk sarapan sarankan roti, susu, telur, dan buah. Untuk masak harian sarankan sayur, protein, dan bumbu dasar. Untuk acara sarankan camilan, minuman, dan kue dalam jumlah sesuai tamu. Pelanggan yang mengurangi gula sebaiknya tidak ditawari produk manis; tawarkan buah, yogurt plain, atau air mineral. Pelanggan vegetarian jangan ditawari daging dan ikan; ganti dengan telur, susu, dan sayur. Bila anggaran terbatas, utamakan produk hemat dan hindari produk premium. Selalu cek stok di sistem kasir dan tawarkan pengganti bila produk habis.',
       (SELECT id FROM pegawai WHERE role='staff' AND aktif=1 ORDER BY id LIMIT 1), 'published', 'internal',
       (SELECT id FROM pegawai WHERE role='manajer' ORDER BY id LIMIT 1), 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM artikel_kms WHERE judul='Panduan Merekomendasikan Produk ke Pelanggan');

SET @kms := (SELECT id FROM artikel_kms WHERE judul='Panduan Merekomendasikan Produk ke Pelanggan' LIMIT 1);
SET @kms_bundling := (SELECT id FROM artikel_kms WHERE judul LIKE 'Ide Bundling%' LIMIT 1);

INSERT INTO es_aturan (id_menu, kode, aksi, kode_tag, cf_pakar, penjelasan, id_artikel) VALUES
(@m, 'R01', 'anjurkan', 'sarapan',      0.90, 'Cocok untuk sarapan',                              @kms),
(@m, 'R02', 'anjurkan', 'siap_santap',  0.80, 'Sarapan dengan waktu singkat: pilih yang siap santap', @kms),
(@m, 'R03', 'anjurkan', 'masak',        0.90, 'Bahan untuk masak harian',                         @kms),
(@m, 'R04', 'anjurkan', 'sayur_buah',   0.70, 'Masak harian perlu sayur',                         @kms),
(@m, 'R05', 'anjurkan', 'bekal',        0.85, 'Mudah dibawa untuk bekal',                         @kms),
(@m, 'R06', 'anjurkan', 'acara',        0.85, 'Cocok disajikan saat acara',                       @kms),
(@m, 'R07', 'anjurkan', 'minuman',      0.75, 'Acara banyak orang perlu minuman',                 @kms),
(@m, 'R08', 'anjurkan', 'stok_bulanan', 0.90, 'Kebutuhan rutin untuk stok bulanan',               @kms),
(@m, 'R09', 'anjurkan', 'rumah_tangga', 0.60, 'Perlengkapan rumah ikut stok bulanan',             @kms),
(@m, 'R10', 'anjurkan', 'camilan',      0.85, 'Camilan untuk santai',                             @kms_bundling),
(@m, 'R11', 'hindari',  'manis',        0.90, 'Mengandung gula tinggi (pelanggan mengurangi gula)', @kms),
(@m, 'R12', 'anjurkan', 'sehat',        0.60, 'Pilihan sehat rendah gula',                        @kms),
(@m, 'R13', 'hindari',  'hewani',       1.00, 'Mengandung daging/ikan (pelanggan vegetarian)',    @kms),
(@m, 'R14', 'anjurkan', 'sayur_buah',   0.80, 'Sayur dan buah untuk vegetarian',                  @kms),
(@m, 'R15', 'anjurkan', 'protein',      0.85, 'Sumber protein tinggi',                            @kms),
(@m, 'R16', 'hindari',  'premium',      0.70, 'Harga premium melebihi anggaran hemat',            @kms),
(@m, 'R17', 'anjurkan', 'hemat',        0.60, 'Harga hemat sesuai anggaran',                      @kms),
(@m, 'R18', 'anjurkan', 'premium',      0.50, 'Anggaran longgar untuk acara: produk premium',     @kms),
(@m, 'R19', 'anjurkan', 'siap_santap',  0.70, 'Bisa langsung disantap (waktu singkat)',           @kms),
(@m, 'R20', 'anjurkan', 'sayur_buah',   0.60, 'Buah melengkapi bekal',                            @kms),
(@m, 'R21', 'anjurkan', 'protein',      0.60, 'Masak harian dengan lauk berprotein',              @kms);

-- aturan tujuan belanja menjadi aturan utama (produk harus sesuai tujuan, aturan lain menguatkan/melemahkan)
UPDATE es_aturan SET utama = 1 WHERE kode IN ('R01','R03','R05','R06','R08','R10');

INSERT INTO es_aturan_syarat (id_aturan, kode_fakta)
SELECT a.id, x.f FROM es_aturan a JOIN (
  SELECT 'R01' r, 'F01' f UNION ALL SELECT 'R02','F01' UNION ALL SELECT 'R02','F17' UNION ALL
  SELECT 'R03','F02' UNION ALL SELECT 'R04','F02' UNION ALL SELECT 'R05','F03' UNION ALL
  SELECT 'R06','F04' UNION ALL SELECT 'R07','F04' UNION ALL SELECT 'R07','F09' UNION ALL
  SELECT 'R08','F05' UNION ALL SELECT 'R09','F05' UNION ALL SELECT 'R10','F06' UNION ALL
  SELECT 'R11','F14' UNION ALL SELECT 'R12','F14' UNION ALL SELECT 'R13','F15' UNION ALL
  SELECT 'R14','F15' UNION ALL SELECT 'R15','F16' UNION ALL SELECT 'R16','F10' UNION ALL
  SELECT 'R17','F10' UNION ALL SELECT 'R18','F12' UNION ALL SELECT 'R18','F04' UNION ALL
  SELECT 'R19','F17' UNION ALL SELECT 'R20','F03' UNION ALL SELECT 'R21','F02' UNION ALL SELECT 'R21','F18'
) x ON x.r = a.kode;

-- snapshot awal hasil DSS (26 Okt 2026); selanjutnya diperbarui otomatis oleh DSS lewat API
INSERT INTO es_dss_sinkron (jenis, id_produk, label, nilai, keterangan)
SELECT 'promo', p.id, CONCAT('Produk promo DSS: ', p.nama), x.skor, 'Skor SAW kasus Produk Promo' FROM produk p JOIN (
  SELECT 'PR-003' sku, 55.88 skor UNION ALL SELECT 'BS-004', 53.27 UNION ALL SELECT 'SU-002', 36.40 UNION ALL
  SELECT 'BS-001', 31.67 UNION ALL SELECT 'RB-003', 31.06) x ON x.sku = p.sku;
INSERT INTO es_dss_sinkron (jenis, id_produk, id_produk_2, label, nilai, keterangan)
SELECT 'bundling', a.id, b.id, CONCAT('Paket ', a.nama, ' + ', b.nama), x.lift, CONCAT('Lift analisis keranjang · harga paket Rp', x.paket) FROM (
  SELECT 'BS-002' sa, 'CM-001' sb, 1.46 lift, '32.000' paket UNION ALL SELECT 'SM-004','CM-001',1.38,'26.000' UNION ALL
  SELECT 'SM-001','MN-002',1.37,'47.000' UNION ALL SELECT 'DI-001','CM-001',1.37,'43.000' UNION ALL SELECT 'SM-004','PR-001',1.36,'29.000'
) x JOIN produk a ON a.sku = x.sa JOIN produk b ON b.sku = x.sb;
INSERT INTO es_dss_sinkron (jenis, kategori, label, nilai, lead_hari, keterangan) VALUES
('supplier', 'Buah & Sayur',  'CV Tani Makmur Kaliurang',  0.8918, 1, 'Supplier terpilih SAW'),
('supplier', 'Daging & Ikan', 'PT Sumber Protein Mandiri', 0.9043, 2, 'Supplier terpilih SAW'),
('supplier', 'Minuman',       'PT Tirta Jaya Distribusi',  0.9058, 2, 'Supplier terpilih SAW'),
('supplier', 'Roti & Bakery', 'CV Roti Mataram',           0.9461, 1, 'Supplier terpilih SAW'),
('supplier', 'Sembako',       'CV Logistik Sembako Jogja', 0.9575, 2, 'Supplier terpilih SAW'),
('supplier', 'Susu & Olahan', 'PT Dairy Indo Prima',       0.8880, 2, 'Supplier terpilih SAW');

-- =====================================================================
--  MENU 2 — WAKTU ORDER BARANG (manajer)
--  Fakta dihitung otomatis oleh back end (includes/es.php) dari data modul lain
-- =====================================================================
SET @o := (SELECT id FROM es_menu WHERE kode='ORDER');

INSERT INTO es_pengaturan (kunci, nilai, keterangan, sumber) VALUES
('siklus',        7,    'Siklus pemesanan (hari): stok target cukup untuk sekian hari setelah barang datang', 'DSS'),
('stok_aman',     2,    'Stok pengaman (hari penjualan)', 'DSS'),
('siklus_segar',  3,    'Siklus maksimum produk mudah rusak (SOP Kedaluwarsa & FIFO di KMS)', 'DSS'),
('lead_bawaan',   2,    'Lama pengiriman bawaan bila kategori belum punya supplier terpilih', 'DSS'),
('naik_persen',   20,   'Penjualan 7 hari dianggap naik bila ≥ sekian persen di atas rata-rata 30 hari', 'ES'),
('turun_persen',  20,   'Penjualan 7 hari dianggap turun bila ≥ sekian persen di bawah rata-rata 30 hari', 'ES'),
('mis_naik',      10,   'Tren transaksi toko (MIS) dianggap naik bila ≥ sekian persen', 'ES'),
('cf_min',        0.40, 'CF minimum produk yang ditampilkan pada rekomendasi pembeli', 'ES'),
('rekom_maks',    8,    'Jumlah maksimum produk yang direkomendasikan per konsultasi', 'ES');

INSERT INTO es_fakta (kode, id_menu, nama, sumber, otomatis, cara) VALUES
('O01', @o, 'Stok produk sudah habis',                                   'TPS', 1, 'produk.stok = 0'),
('O02', @o, 'Stok habis sebelum barang baru datang',                     'TPS', 1, 'sisa hari = stok ÷ rata jual harian 30 hari ≤ lead time supplier (DSS) + stok pengaman'),
('O03', @o, 'Stok menyentuh batas pesan dalam 3 hari',                   'TPS', 1, 'lead + aman < sisa hari ≤ lead + aman + 3'),
('O04', @o, 'Stok masih cukup lebih dari 3 hari setelah batas pesan',    'TPS', 1, 'sisa hari > lead + aman + 3'),
('O05', @o, 'Penjualan 7 hari terakhir naik',                            'TPS', 1, 'rata jual 7 hari ≥ (1 + naik%) × rata jual 30 hari'),
('O06', @o, 'Penjualan 7 hari terakhir turun',                           'TPS', 1, 'rata jual 7 hari ≤ (1 − turun%) × rata jual 30 hari'),
('O07', @o, 'Akhir pekan ramai jatuh sebelum barang datang',             'TPS', 1, 'Sabtu/Minggu ada di antara hari ini dan hari barang datang, dan jual akhir pekan > hari biasa (pola 8 minggu)'),
('O08', @o, 'Produk mudah rusak',                                        'KMS', 1, 'kategori dengan risiko kedaluwarsa tinggi menurut SOP Kedaluwarsa & FIFO'),
('O09', @o, 'Produk muncul di Pusat Peringatan MIS',                     'MIS', 1, 'stok < batas minimum MIS (STOK_MIN)'),
('O10', @o, 'Transaksi toko 7 hari terakhir naik',                       'MIS', 1, 'jumlah transaksi 7 hari (mis_penjualan_harian) ≥ (1 + mis_naik%) × 7 hari sebelumnya'),
('O11', @o, 'Produk sedang menjadi kandidat promo DSS',                  'DSS', 1, 'produk ada di hasil SAW Produk Promo (es_dss_sinkron jenis promo)'),
('O12', @o, 'Produk termasuk paket bundling DSS',                        'DSS', 1, 'produk ada di paket bundling dengan lift > 1 (es_dss_sinkron jenis bundling)'),
('O13', @o, 'Ada agenda stock opname dalam 2 hari',                      'OAS', 1, 'agenda berjudul "opname" mulai hari ini sampai 2 hari ke depan'),
('O14', @o, 'Sudah ada tugas order yang belum selesai',                  'OAS', 1, 'tugas [ES]/[DSS] yang menyebut produk ini berstatus terbuka/proses'),
('O15', @o, 'Ada permintaan pembeli yang tidak terpenuhi',               'ES',  1, 'es_permintaan berstatus baru/ditugaskan untuk produk ini (dari menu rekomendasi)'),
('O16', @o, 'Produk tidak terjual 30 hari',                              'TPS', 1, 'terjual 30 hari = 0');

INSERT INTO es_kesimpulan (id_menu, kode, nama, tindakan, prioritas, warna) VALUES
(@o, 'K01', 'Order hari ini',            'Buat order ke supplier hari ini agar stok tidak kosong.', 1, 'r'),
(@o, 'K02', 'Order 1–2 hari lagi',       'Siapkan order dan kirim paling lambat besok.', 2, 'o'),
(@o, 'K03', 'Order sesuai jadwal',       'Order pada tanggal yang dihitung sistem (saat stok menyentuh batas pesan).', 3, 'y'),
(@o, 'K04', 'Tunda order',               'Belum perlu order; tinjau ulang pada tanggal yang disarankan.', 4, 'g'),
(@o, 'K05', 'Order sudah diproses',      'Tidak perlu order baru; selesaikan tugas order yang sudah ada di OAS.', 5, 'n'),
(@o, 'K06', 'Jangan order, evaluasi',    'Produk tidak laku; jangan order dulu, evaluasi harga/display atau promosikan.', 6, 'n');

-- sumber pengetahuan pakar untuk menu 2 di KMS
INSERT INTO artikel_kms (judul, id_kategori, ringkasan, isi, id_penulis, status, visibilitas, id_reviewer, versi)
SELECT 'SOP Menentukan Waktu Order Barang ke Supplier',
       (SELECT id FROM kategori_kms WHERE nama='Operasional Toko' LIMIT 1),
       'Kapan order dibuat: hitung sisa hari stok, lama pengiriman supplier, stok pengaman, dan pola penjualan.',
       'Order harus dibuat sebelum stok habis. Hitung sisa hari stok = stok ÷ rata-rata penjualan harian. Bila sisa hari sudah sama atau kurang dari lama pengiriman supplier ditambah stok pengaman, order hari ini. Bila batas pesan jatuh dalam 3 hari, siapkan order paling lambat besok, terutama bila penjualan sedang naik atau akhir pekan akan datang sebelum barang tiba. Produk segar (buah, sayur, roti, daging, susu) jangan dipesan terlalu awal karena cepat rusak; pesan mendekati batas pesan dengan jumlah untuk 3 hari. Jangan order produk yang sedang dihabiskan lewat promo, produk yang tidak laku 30 hari, atau produk yang order-nya masih diproses. Bila ada stock opname dalam 2 hari, tunggu hasil opname kecuali stok sudah habis.',
       (SELECT id FROM pegawai WHERE role='manajer' AND aktif=1 ORDER BY id LIMIT 1), 'published', 'internal',
       (SELECT id FROM pegawai WHERE role='manajer' ORDER BY id LIMIT 1), 1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM artikel_kms WHERE judul='SOP Menentukan Waktu Order Barang ke Supplier');

SET @kms_order := (SELECT id FROM artikel_kms WHERE judul='SOP Menentukan Waktu Order Barang ke Supplier' LIMIT 1);
SET @kms_fifo  := (SELECT id FROM artikel_kms WHERE judul LIKE 'Menerapkan FIFO%' LIMIT 1);
SET @kms_stok  := (SELECT id FROM artikel_kms WHERE judul LIKE 'SOP Pengecekan Stok%' LIMIT 1);

INSERT INTO es_aturan (id_menu, kode, aksi, kode_kesimpulan, cf_pakar, penjelasan, id_artikel) VALUES
(@o, 'RO01', 'simpulkan', 'K01', 1.00, 'Stok sudah habis, pembeli tidak bisa dilayani',                      @kms_order),
(@o, 'RO02', 'simpulkan', 'K01', 0.90, 'Stok akan habis sebelum barang baru datang',                        @kms_order),
(@o, 'RO03', 'simpulkan', 'K01', 0.95, 'Stok kritis dan penjualan sedang naik',                             @kms_order),
(@o, 'RO04', 'simpulkan', 'K02', 0.80, 'Batas pesan jatuh dalam 3 hari',                                    @kms_order),
(@o, 'RO05', 'simpulkan', 'K01', 0.70, 'Batas pesan dekat dan penjualan naik, stok bisa habis lebih cepat', @kms_order),
(@o, 'RO06', 'simpulkan', 'K01', 0.75, 'Akhir pekan ramai datang sebelum barang tiba',                      @kms_order),
(@o, 'RO07', 'simpulkan', 'K02', 0.60, 'Produk sudah masuk Pusat Peringatan MIS',                           @kms_stok),
(@o, 'RO08', 'simpulkan', 'K01', 0.60, 'Ada pembeli yang mencari produk ini tetapi stok tidak cukup',       @kms_order),
(@o, 'RO09', 'simpulkan', 'K03', 0.80, 'Stok masih cukup; order saat menyentuh batas pesan',                @kms_order),
(@o, 'RO10', 'simpulkan', 'K02', 0.60, 'Batas pesan dekat dan transaksi toko sedang naik (MIS)',            @kms_order),
(@o, 'RO11', 'simpulkan', 'K04', 0.70, 'Stok cukup dan penjualan sedang turun',                             @kms_order),
(@o, 'RO12', 'simpulkan', 'K04', 0.60, 'Produk sedang dihabiskan lewat promo (DSS)',                        @kms_order),
(@o, 'RO13', 'simpulkan', 'K04', 0.50, 'Tunggu hasil stock opname (OAS)',                                   @kms_stok),
(@o, 'RO14', 'simpulkan', 'K05', 0.95, 'Order untuk produk ini masih diproses di OAS',                      @kms_order),
(@o, 'RO15', 'simpulkan', 'K06', 0.90, 'Produk tidak terjual 30 hari',                                      @kms_order),
(@o, 'RO16', 'simpulkan', 'K04', 0.60, 'Produk segar jangan dipesan terlalu awal',                          @kms_fifo),
(@o, 'RO17', 'simpulkan', 'K01', 0.60, 'Produk paket bundling DSS, permintaan ikut naik',                   @kms_order),
(@o, 'RO18', 'simpulkan', 'K02', 0.70, 'Produk segar: pesan tepat saat mendekati batas pesan',              @kms_fifo);

INSERT INTO es_aturan_syarat (id_aturan, kode_fakta)
SELECT a.id, x.f FROM es_aturan a JOIN (
  SELECT 'RO01' r, 'O01' f UNION ALL SELECT 'RO02','O02' UNION ALL SELECT 'RO03','O02' UNION ALL SELECT 'RO03','O05' UNION ALL
  SELECT 'RO04','O03' UNION ALL SELECT 'RO05','O03' UNION ALL SELECT 'RO05','O05' UNION ALL SELECT 'RO06','O03' UNION ALL SELECT 'RO06','O07' UNION ALL
  SELECT 'RO07','O09' UNION ALL SELECT 'RO08','O15' UNION ALL SELECT 'RO09','O04' UNION ALL SELECT 'RO10','O03' UNION ALL SELECT 'RO10','O10' UNION ALL
  SELECT 'RO11','O04' UNION ALL SELECT 'RO11','O06' UNION ALL SELECT 'RO12','O04' UNION ALL SELECT 'RO12','O11' UNION ALL SELECT 'RO13','O13' UNION ALL
  SELECT 'RO14','O14' UNION ALL SELECT 'RO15','O16' UNION ALL SELECT 'RO16','O04' UNION ALL SELECT 'RO16','O08' UNION ALL
  SELECT 'RO17','O03' UNION ALL SELECT 'RO17','O12' UNION ALL SELECT 'RO18','O03' UNION ALL SELECT 'RO18','O08'
) x ON x.r = a.kode;

-- =====================================================================
--  VIEW: menggabungkan data TPS, MIS, KMS, DSS dan mesin inferensi ES
-- =====================================================================

-- data produk untuk ES: stok & penjualan (TPS), status peringatan (aturan MIS: stok < 10), promo/supplier (DSS)
CREATE VIEW v_es_produk AS
SELECT p.id, p.sku, p.nama, p.ikon, p.kategori, p.satuan, p.harga, p.stok,
       COALESCE((SELECT SUM(d.qty) FROM detail_transaksi d JOIN transaksi t ON t.id = d.id_transaksi
                 WHERE d.id_produk = p.id AND t.waktu >= CURDATE() - INTERVAL 29 DAY), 0) AS terjual_30,
       CASE WHEN p.stok = 0 THEN 'habis' WHEN p.stok < 10 THEN 'menipis' ELSE 'aman' END AS status_mis,
       (SELECT s.nilai FROM es_dss_sinkron s WHERE s.jenis = 'promo' AND s.id_produk = p.id ORDER BY s.id DESC LIMIT 1) AS skor_promo_dss,
       (SELECT s.label FROM es_dss_sinkron s WHERE s.jenis = 'supplier' AND s.kategori = p.kategori ORDER BY s.id DESC LIMIT 1) AS supplier_dss
FROM produk p WHERE p.aktif = 1;

-- basis pengetahuan yang mudah dibaca: JIKA ... MAKA ... (CF) + sumber KMS
CREATE VIEW v_es_basis_pengetahuan AS
SELECT a.id, a.kode, m.kode AS kode_menu, m.nama AS menu,
       GROUP_CONCAT(f.nama ORDER BY f.kode SEPARATOR ' DAN ') AS jika,
       CASE WHEN a.aksi = 'simpulkan' THEN CONCAT('MAKA ', ks.nama) ELSE CONCAT(UPPER(a.aksi), ' ', t.nama) END AS maka,
       a.cf_pakar, a.penjelasan, k.judul AS sumber_kms, a.aktif
FROM es_aturan a JOIN es_menu m ON m.id = a.id_menu
LEFT JOIN es_tag t ON t.kode = a.kode_tag LEFT JOIN es_kesimpulan ks ON ks.kode = a.kode_kesimpulan
JOIN es_aturan_syarat s ON s.id_aturan = a.id JOIN es_fakta f ON f.kode = s.kode_fakta
LEFT JOIN artikel_kms k ON k.id = a.id_artikel
GROUP BY a.id, a.kode, m.kode, m.nama, a.aksi, t.nama, ks.nama, a.cf_pakar, a.penjelasan, k.judul, a.aktif;

-- forward chaining: aturan yang SEMUA syaratnya ada di fakta konsultasi (CF aturan × CF jawaban terkecil)
CREATE VIEW v_es_aturan_aktif AS
SELECT kf.id_konsultasi, a.id AS id_aturan, a.kode, a.aksi, a.utama, a.kode_tag, a.penjelasan,
       a.cf_pakar * MIN(kf.cf_pengguna) AS cf_aturan
FROM es_aturan a
JOIN es_aturan_syarat s ON s.id_aturan = a.id
JOIN es_konsultasi_fakta kf ON kf.kode_fakta = s.kode_fakta
WHERE a.aktif = 1 AND a.aksi IN ('anjurkan', 'hindari')
GROUP BY kf.id_konsultasi, a.id, a.kode, a.aksi, a.utama, a.kode_tag, a.penjelasan, a.cf_pakar
HAVING COUNT(*) = (SELECT COUNT(*) FROM es_aturan_syarat s2 WHERE s2.id_aturan = a.id);

-- CF per produk: gabungan paralel 1 − Π(1 − CF aturan × bobot tag), dipisah anjuran dan hindaran
CREATE VIEW v_es_cf_produk AS
SELECT aa.id_konsultasi, pt.id_produk,
       1 - EXP(SUM(CASE WHEN aa.aksi = 'anjurkan' THEN LN(1 - LEAST(aa.cf_aturan * pt.bobot, 0.9999)) ELSE 0 END)) AS cf_anjur,
       1 - EXP(SUM(CASE WHEN aa.aksi = 'hindari'  THEN LN(1 - LEAST(aa.cf_aturan * pt.bobot, 0.9999)) ELSE 0 END)) AS cf_hindari,
       MAX(CASE WHEN aa.utama = 1 THEN aa.cf_aturan * pt.bobot ELSE 0 END) AS cf_utama,
       GROUP_CONCAT(DISTINCT CASE WHEN aa.aksi = 'anjurkan' THEN aa.penjelasan END ORDER BY aa.kode SEPARATOR '; ') AS alasan,
       GROUP_CONCAT(DISTINCT CASE WHEN aa.aksi = 'hindari'  THEN aa.penjelasan END ORDER BY aa.kode SEPARATOR '; ') AS peringatan,
       GROUP_CONCAT(DISTINCT aa.kode ORDER BY aa.kode SEPARATOR ', ') AS aturan
FROM v_es_aturan_aktif aa
JOIN es_produk_tag pt ON pt.kode_tag = aa.kode_tag
GROUP BY aa.id_konsultasi, pt.id_produk;

-- CF akhir = CF anjuran × (1 − CF hindaran), lengkap dengan stok TPS, status MIS, dan info DSS
CREATE VIEW v_es_skor_produk AS
SELECT c.id_konsultasi, p.id AS id_produk, p.sku, p.nama, p.ikon, p.kategori, p.harga, p.stok, p.status_mis,
       ROUND(c.cf_anjur, 4) AS cf_anjur, ROUND(c.cf_hindari, 4) AS cf_hindari,
       ROUND(c.cf_anjur * (1 - c.cf_hindari), 4) AS cf_akhir,
       c.alasan, c.peringatan, c.aturan, p.skor_promo_dss, p.supplier_dss
FROM v_es_cf_produk c JOIN v_es_produk p ON p.id = c.id_produk
WHERE c.cf_utama > 0;   -- produk harus sesuai tujuan belanja

-- rekomendasi siap tampil: hanya produk ber-stok dengan CF akhir ≥ 0,40
CREATE VIEW v_es_rekomendasi AS
SELECT * FROM v_es_skor_produk WHERE stok > 0 AND cf_akhir >= 0.40;

-- MIS: ringkasan konsultasi ES per hari
CREATE VIEW v_es_mis_harian AS
SELECT DATE(k.waktu) AS tanggal, COUNT(DISTINCT k.id) AS konsultasi,
       COUNT(DISTINCT h.id) AS produk_direkomendasikan,
       COUNT(DISTINCT CASE WHEN h.dibeli = 1 THEN h.id END) AS jadi_dibeli,
       COUNT(DISTINCT r.id) AS permintaan_stok_habis
FROM es_konsultasi k
LEFT JOIN es_konsultasi_hasil h ON h.id_konsultasi = k.id
LEFT JOIN es_permintaan r ON r.id_konsultasi = k.id
GROUP BY DATE(k.waktu);

-- MIS & DSS restok: produk yang paling sering dicari pelanggan lewat ES
CREATE VIEW v_es_mis_produk_dicari AS
SELECT p.id, p.sku, p.nama, p.kategori, p.stok,
       COUNT(DISTINCT h.id_konsultasi) AS direkomendasikan,
       COUNT(DISTINCT CASE WHEN h.dibeli = 1 THEN h.id_konsultasi END) AS dibeli,
       (SELECT COUNT(*) FROM es_permintaan r WHERE r.id_produk = p.id AND r.status != 'selesai') AS permintaan_tertunda
FROM produk p
LEFT JOIN es_konsultasi_hasil h ON h.id_produk = p.id
WHERE p.aktif = 1
GROUP BY p.id, p.sku, p.nama, p.kategori, p.stok;

-- menu 2: analisis waktu order terbaru setiap produk
CREATE VIEW v_es_order_terbaru AS
SELECT a.*, p.sku, p.nama, p.kategori, p.satuan, k.nama AS kesimpulan, k.tindakan, k.prioritas, k.warna
FROM es_order_analisis a
JOIN (SELECT id_produk, MAX(id) id FROM es_order_analisis GROUP BY id_produk) t ON t.id = a.id
JOIN produk p ON p.id = a.id_produk JOIN es_kesimpulan k ON k.kode = a.kode_kesimpulan;

-- MIS: ringkasan keputusan waktu order terbaru
CREATE VIEW v_es_mis_order AS
SELECT k.kode, k.nama, k.prioritas, k.warna, COUNT(o.id) AS jumlah_produk, COALESCE(SUM(o.qty_saran), 0) AS total_qty,
       SUM(o.status = 'ditugaskan') AS sudah_ditugaskan
FROM es_kesimpulan k LEFT JOIN v_es_order_terbaru o ON o.kode_kesimpulan = k.kode
WHERE k.id_menu = (SELECT id FROM es_menu WHERE kode = 'ORDER')
GROUP BY k.kode, k.nama, k.prioritas, k.warna;

-- =====================================================================
--  CONTOH PEMAKAIAN (jalankan manual untuk mencoba; tidak dijalankan otomatis)
-- =====================================================================
-- 1) Buat konsultasi: sarapan, 3–4 orang, anggaran sedang, kurangi gula, waktu singkat
--    INSERT INTO es_konsultasi (id_menu, id_pegawai, nama_pelanggan) VALUES (@m, 3, 'Pelanggan');
--    SET @k := LAST_INSERT_ID();
--    INSERT INTO es_konsultasi_fakta (id_konsultasi, kode_fakta) VALUES (@k,'F01'),(@k,'F08'),(@k,'F11'),(@k,'F14'),(@k,'F17');
-- 2) Lihat aturan yang aktif (forward chaining)
--    SELECT * FROM v_es_aturan_aktif WHERE id_konsultasi = @k;
-- 3) Lihat rekomendasi produk berurutan
--    SELECT nama, stok, cf_akhir, alasan, peringatan FROM v_es_rekomendasi WHERE id_konsultasi = @k ORDER BY cf_akhir DESC;
-- 4) Produk cocok tetapi stok habis (calon es_permintaan → tugas OAS)
--    SELECT nama, cf_akhir FROM v_es_skor_produk WHERE id_konsultasi = @k AND stok = 0 AND cf_akhir >= 0.40;
-- 5) Menu 2 (manajer): jalankan analisis lewat back end  → api/es.php?aksi=order (POST)
--    lalu lihat keputusan waktu order terbaru setiap produk
--    SELECT nama, kesimpulan, cf, tanggal_order, qty_saran, alasan FROM v_es_order_terbaru ORDER BY prioritas, tanggal_order;

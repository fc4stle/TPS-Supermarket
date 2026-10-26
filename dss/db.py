"""
Lapisan database DSS (SQLite).
File database `dss.db` dibuat otomatis saat aplikasi pertama kali dijalankan.
"""
import sqlite3
from datetime import datetime

from flask import g

import config

SKEMA = """
-- Pengguna DSS: tidak punya password sendiri. Dibuat/diperbarui otomatis
-- setiap kali pegawai MartOffice masuk lewat SSO (Just-In-Time provisioning).
CREATE TABLE IF NOT EXISTS pengguna (
  id             INTEGER PRIMARY KEY,          -- sama dengan pegawai.id di MartOffice
  username       TEXT NOT NULL UNIQUE,
  nama           TEXT NOT NULL,
  role           TEXT NOT NULL,
  jabatan        TEXT,
  pertama_masuk  TEXT NOT NULL,
  terakhir_masuk TEXT NOT NULL,
  jumlah_masuk   INTEGER NOT NULL DEFAULT 0
);

-- Kategori supplier; disinkronkan dari kategori produk TPS MartOffice
CREATE TABLE IF NOT EXISTS kategori (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  nama      TEXT NOT NULL UNIQUE,
  ikon      TEXT NOT NULL DEFAULT '📦',
  sumber    TEXT NOT NULL DEFAULT 'lokal' CHECK (sumber IN ('lokal','tps')),
  disinkron TEXT
);

-- Alternatif keputusan
CREATE TABLE IF NOT EXISTS supplier (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  kode        TEXT NOT NULL UNIQUE,
  nama        TEXT NOT NULL,
  kategori_id INTEGER NOT NULL REFERENCES kategori(id),
  kontak      TEXT,
  telepon     TEXT,
  alamat      TEXT,
  bukti       TEXT,                -- sumber bukti penilaian (surat penawaran, uji sampel, dll.)
  bukti_diubah TEXT,
  aktif       INTEGER NOT NULL DEFAULT 1,
  dibuat      TEXT NOT NULL,
  dibuat_oleh TEXT
);

-- Kriteria SAW beserta bobot (dalam persen, total harus 100)
CREATE TABLE IF NOT EXISTS kriteria (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  kode       TEXT NOT NULL UNIQUE,
  nama       TEXT NOT NULL,
  jenis      TEXT NOT NULL CHECK (jenis IN ('benefit','cost')),
  bobot      REAL NOT NULL CHECK (bobot >= 0),
  satuan     TEXT,
  keterangan TEXT,
  urutan     INTEGER NOT NULL DEFAULT 0
);

-- Matriks keputusan: nilai tiap supplier pada tiap kriteria
CREATE TABLE IF NOT EXISTS nilai (
  supplier_id INTEGER NOT NULL REFERENCES supplier(id) ON DELETE CASCADE,
  kriteria_id INTEGER NOT NULL REFERENCES kriteria(id) ON DELETE CASCADE,
  nilai       REAL NOT NULL CHECK (nilai > 0),
  diubah      TEXT NOT NULL,
  diubah_oleh TEXT,
  PRIMARY KEY (supplier_id, kriteria_id)
);

-- Riwayat keputusan manajer (snapshot bobot & ranking saat disimpan)
CREATE TABLE IF NOT EXISTS keputusan (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  kategori_id   INTEGER NOT NULL REFERENCES kategori(id),
  judul         TEXT NOT NULL,
  catatan       TEXT,
  bobot_json    TEXT NOT NULL,
  hasil_json    TEXT NOT NULL,
  pemenang_id   INTEGER,
  pemenang_nama TEXT NOT NULL,
  nilai_v       REAL NOT NULL,
  dibuat_oleh   TEXT NOT NULL,
  dibuat_pada   TEXT NOT NULL
);

-- Jejak Single Sign-On (berhasil maupun ditolak)
CREATE TABLE IF NOT EXISTS sso_log (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  waktu    TEXT NOT NULL,
  jti      TEXT,
  username TEXT,
  nama     TEXT,
  role     TEXT,
  status   TEXT NOT NULL CHECK (status IN ('berhasil','ditolak','keluar')),
  alasan   TEXT,
  ip       TEXT
);
CREATE INDEX IF NOT EXISTS idx_sso_jti ON sso_log(jti);

-- Riwayat sinkronisasi data dari MartOffice (TPS, MIS, OAS, KMS).
-- Snapshot terakhir dipakai sebagai cache sehingga DSS tetap bisa
-- menampilkan data walaupun MartOffice sedang tidak aktif.
CREATE TABLE IF NOT EXISTS sinkron (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  waktu      TEXT NOT NULL,
  status     TEXT NOT NULL CHECK (status IN ('berhasil','gagal')),
  pemicu     TEXT,
  durasi_ms  INTEGER,
  ringkasan  TEXT,
  data_json  TEXT
);

-- Kriteria & bobot untuk kasus DSS lain yang memakai SAW (promo, pegawai)
CREATE TABLE IF NOT EXISTS bobot_modul (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  modul      TEXT NOT NULL,
  kode       TEXT NOT NULL,
  nama       TEXT NOT NULL,
  jenis      TEXT NOT NULL CHECK (jenis IN ('benefit','cost')),
  bobot      REAL NOT NULL CHECK (bobot >= 0),
  satuan     TEXT,
  sumber     TEXT,
  keterangan TEXT,
  urutan     INTEGER NOT NULL DEFAULT 0,
  UNIQUE (modul, kode)
);

-- Parameter kasus DSS (restok, shift, bundling, promo) yang dapat diubah manajer
CREATE TABLE IF NOT EXISTS pengaturan (
  kunci      TEXT PRIMARY KEY,
  nilai      REAL NOT NULL,
  keterangan TEXT
);

-- Keputusan dari kasus DSS selain pemilihan supplier
CREATE TABLE IF NOT EXISTS keputusan_lain (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  modul       TEXT NOT NULL,
  judul       TEXT NOT NULL,
  catatan     TEXT,
  ringkasan   TEXT,
  data_json   TEXT NOT NULL,
  dibuat_oleh TEXT NOT NULL,
  dibuat_pada TEXT NOT NULL
);

-- tindak lanjut keputusan yang dikirim balik ke OAS MartOffice (pengumuman / tugas / agenda)
CREATE TABLE IF NOT EXISTS tindak_lanjut (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  sumber       TEXT NOT NULL CHECK (sumber IN ('supplier','lain')),
  keputusan_id INTEGER NOT NULL,
  jenis        TEXT NOT NULL,
  ref_id       INTEGER,
  judul        TEXT NOT NULL,
  keterangan   TEXT,
  dibuat_oleh  TEXT NOT NULL,
  dibuat_pada  TEXT NOT NULL
);
"""

# Kriteria awal pemilihan supplier supermarket (konfigurasi, dapat diubah manajer)
KRITERIA_AWAL = [
    ("C1", "Harga penawaran", "cost", 25, "Rp/paket", "Harga beli paket standar dari supplier. Makin murah makin baik."),
    ("C2", "Kualitas barang", "benefit", 25, "skor 1–5", "Rata-rata skor pemeriksaan mutu (QC) saat barang diterima."),
    ("C3", "Ketepatan waktu kirim", "benefit", 20, "%", "Persentase pengiriman yang datang sesuai jadwal."),
    ("C4", "Barang rusak / retur", "cost", 15, "%", "Persentase barang yang rusak atau harus diretur."),
    ("C5", "Lama pengiriman", "cost", 15, "hari", "Lead time dari pemesanan sampai barang tiba."),
]

# Kriteria awal kasus DSS lain (kode, nama, jenis, bobot, satuan, sumber data, keterangan)
BOBOT_MODUL_AWAL = {
    "promo": [
        ("P1", "Hari persediaan", "benefit", 30, "hari", "TPS", "Stok dibagi rata-rata penjualan harian. Makin lama stok bertahan, makin perlu dipromosikan."),
        ("P2", "Penurunan penjualan", "benefit", 25, "%", "TPS · MIS", "Turunnya jumlah terjual 30 hari dibanding 30 hari sebelumnya (dihitung +1 agar tidak nol)."),
        ("P3", "Risiko kedaluwarsa", "benefit", 25, "skor 1–5", "KMS", "Produk segar lebih cepat rusak (acuan SOP Pengecekan Tanggal Kedaluwarsa di KMS)."),
        ("P4", "Kontribusi omzet", "cost", 20, "%", "TPS", "Porsi omzet 30 hari. Produk laris tidak perlu didiskon."),
    ],
    "pegawai": [
        ("E1", "Transaksi dilayani", "benefit", 25, "transaksi/30 hari", "TPS", "Jumlah transaksi kasir 30 hari terakhir (dihitung +1 agar tidak nol)."),
        ("E2", "Omzet dilayani", "benefit", 15, "Rp/30 hari", "TPS", "Total belanja yang dilayani 30 hari terakhir (dihitung +1 agar tidak nol)."),
        ("E3", "Ketepatan waktu tugas", "benefit", 25, "%", "OAS", "Persentase tugas selesai sebelum tenggat (60 hari terakhir)."),
        ("E4", "Tugas terlambat", "cost", 10, "tugas", "OAS", "Tugas aktif yang sudah melewati tenggat (dihitung +1 agar tidak nol)."),
        ("E5", "Knowledge dibagikan", "benefit", 10, "artikel", "KMS", "Artikel knowledge published (dihitung +1 agar tidak nol)."),
        ("E6", "Dinilai membantu", "benefit", 15, "feedback", "KMS", "Jumlah feedback 'membantu' pada knowledge yang ditulis (dihitung +1 agar tidak nol)."),
    ],
}

PENGATURAN_AWAL = [
    ("restok_siklus", 7, "Siklus pemesanan (hari): stok target cukup untuk sekian hari setelah barang datang"),
    ("restok_aman", 2, "Stok pengaman (hari penjualan)"),
    ("restok_lead", 2, "Lama pengiriman bawaan (hari) bila kategori belum punya supplier"),
    ("restok_segar", 3, "Siklus maksimum produk mudah rusak (SOP Kedaluwarsa & FIFO di KMS)"),
    ("promo_jumlah", 5, "Jumlah produk yang direkomendasikan untuk promo"),
    ("promo_elastis", 1.5, "Elastisitas harga untuk simulasi: kenaikan % penjualan per 1% diskon"),
    ("bundling_diskon", 10, "Potongan harga paket bundling (%)"),
    ("shift_kasir", 3, "Jumlah kasir yang tersedia per hari"),
    ("shift_batas", 14, "Jam pergantian shift pagi ke shift sore"),
]

# Kategori produk MartOffice (dipakai bila TPS belum bisa dihubungi)
KATEGORI_AWAL = [
    ("Buah & Sayur", "🥬"), ("Daging & Ikan", "🥩"), ("Minuman", "🥤"),
    ("Roti & Bakery", "🍞"), ("Sembako", "🍚"), ("Susu & Olahan", "🥛"),
]
IKON_KATEGORI = dict(KATEGORI_AWAL)


def sekarang() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(config.DB_PATH)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys = ON")
    return con


def get_db() -> sqlite3.Connection:
    if "db" not in g:
        g.db = connect()
    return g.db


def close_db(_exc=None):
    con = g.pop("db", None)
    if con is not None:
        con.close()


def rows(sql, params=()):
    return get_db().execute(sql, params).fetchall()


def row(sql, params=()):
    return get_db().execute(sql, params).fetchone()


def val(sql, params=()):
    r = get_db().execute(sql, params).fetchone()
    return r[0] if r else None


def run(sql, params=()):
    con = get_db()
    cur = con.execute(sql, params)
    con.commit()
    return cur


def init_db():
    """Membuat tabel bila belum ada dan mengisi kriteria serta kategori awal."""
    con = connect()
    con.executescript(SKEMA)
    # migrasi: database lama yang belum punya kolom bukti
    kolom = [r[1] for r in con.execute("PRAGMA table_info(supplier)")]
    for nama in ("bukti", "bukti_diubah"):
        if nama not in kolom:
            con.execute(f"ALTER TABLE supplier ADD COLUMN {nama} TEXT")
    if con.execute("SELECT COUNT(*) FROM kriteria").fetchone()[0] == 0:
        con.executemany(
            "INSERT INTO kriteria (kode,nama,jenis,bobot,satuan,keterangan,urutan) VALUES (?,?,?,?,?,?,?)",
            [(k, n, j, b, s, ket, i) for i, (k, n, j, b, s, ket) in enumerate(KRITERIA_AWAL, 1)],
        )
    for modul, daftar in BOBOT_MODUL_AWAL.items():
        if con.execute("SELECT COUNT(*) FROM bobot_modul WHERE modul=?", (modul,)).fetchone()[0] == 0:
            con.executemany(
                "INSERT INTO bobot_modul (modul,kode,nama,jenis,bobot,satuan,sumber,keterangan,urutan) VALUES (?,?,?,?,?,?,?,?,?)",
                [(modul, k, n, j, b, s_, sb, ket, i) for i, (k, n, j, b, s_, sb, ket) in enumerate(daftar, 1)])
    con.executemany("INSERT OR IGNORE INTO pengaturan (kunci, nilai, keterangan) VALUES (?,?,?)", PENGATURAN_AWAL)
    if con.execute("SELECT COUNT(*) FROM kategori").fetchone()[0] == 0:
        con.executemany("INSERT INTO kategori (nama, ikon) VALUES (?,?)", KATEGORI_AWAL)
    con.commit()
    con.close()

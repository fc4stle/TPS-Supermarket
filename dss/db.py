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
"""

# Kriteria awal pemilihan supplier supermarket (konfigurasi, dapat diubah manajer)
KRITERIA_AWAL = [
    ("C1", "Harga penawaran", "cost", 25, "Rp/paket", "Harga beli paket standar dari supplier. Makin murah makin baik."),
    ("C2", "Kualitas barang", "benefit", 25, "skor 1–5", "Rata-rata skor pemeriksaan mutu (QC) saat barang diterima."),
    ("C3", "Ketepatan waktu kirim", "benefit", 20, "%", "Persentase pengiriman yang datang sesuai jadwal."),
    ("C4", "Barang rusak / retur", "cost", 15, "%", "Persentase barang yang rusak atau harus diretur."),
    ("C5", "Lama pengiriman", "cost", 15, "hari", "Lead time dari pemesanan sampai barang tiba."),
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
    if con.execute("SELECT COUNT(*) FROM kategori").fetchone()[0] == 0:
        con.executemany("INSERT INTO kategori (nama, ikon) VALUES (?,?)", KATEGORI_AWAL)
    con.commit()
    con.close()

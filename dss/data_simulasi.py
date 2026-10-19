"""
DATA SIMULASI DSS (opsional)
============================
Mengisi DSS dengan supplier fiktif beserta penilaiannya agar perhitungan SAW
langsung dapat didemokan. Data ini TIDAK nyata — hanya contoh untuk tugas.

Menjalankan (dari folder martoffice):
    python dss/data_simulasi.py          → isi data simulasi (data supplier lama dihapus)
    python dss/data_simulasi.py --kosong → kosongkan semua supplier, nilai, dan keputusan

Kriteria, kategori, pengguna, dan log SSO tidak diubah.
"""
import json
import os
import sys
from datetime import datetime, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import config  # noqa: E402
import db  # noqa: E402
import saw  # noqa: E402

# kategori: [(nama supplier, kontak, telepon, alamat, {C1 harga, C2 kualitas, C3 tepat waktu %, C4 rusak %, C5 lead time hari})]
SUPPLIER = {
    "Buah & Sayur": [
        ("CV Tani Makmur Kaliurang", "Bu Sri Lestari", "0812-2731-4410", "Pakem, Sleman", (182000, 4.6, 94, 2.5, 1)),
        ("UD Segar Lestari", "Pak Joko Waluyo", "0813-9022-1175", "Ngaglik, Sleman", (168000, 4.1, 88, 4.0, 1)),
        ("Koperasi Petani Merapi", "Mas Dwi Haryanto", "0857-4100-3321", "Cangkringan, Sleman", (159000, 4.3, 82, 3.5, 2)),
        ("PT Agro Nusantara Fresh", "Ibu Ratna Dewi", "0274-551-200", "Ring Road Utara, Depok", (205000, 4.8, 97, 1.5, 2)),
        ("Kebun Organik Gamping", "Pak Hendra", "0819-0477-6650", "Gamping, Sleman", (214000, 4.9, 85, 2.0, 3)),
    ],
    "Daging & Ikan": [
        ("CV Prima Daging Bantul", "Pak Agus Salim", "0812-1555-8020", "Sewon, Bantul", (412000, 4.4, 92, 1.8, 1)),
        ("UD Laut Selatan Jaya", "Bu Wulan", "0858-6623-0091", "Depok, Pantai Gesing", (365000, 4.0, 86, 3.2, 2)),
        ("PT Sumber Protein Mandiri", "Bapak Yusuf", "0274-487-311", "Kalasan, Sleman", (398000, 4.7, 95, 1.2, 2)),
        ("RPH Mitra Giwangan", "Mas Bayu", "0821-3340-7782", "Giwangan, Yogyakarta", (389000, 4.2, 90, 2.4, 1)),
    ],
    "Minuman": [
        ("PT Tirta Jaya Distribusi", "Ibu Melati", "0274-623-900", "Jl. Magelang, Sleman", (96000, 4.5, 96, 0.8, 2)),
        ("CV Sumber Segar Abadi", "Pak Rudi", "0812-2900-4561", "Banguntapan, Bantul", (89000, 4.1, 89, 1.5, 3)),
        ("UD Mandala Minuman", "Bu Endah", "0856-4011-2290", "Mlati, Sleman", (92500, 4.3, 93, 1.0, 2)),
        ("PT Nusa Beverage Indonesia", "Bapak Kevin", "021-5890-1122", "Cabang Yogyakarta", (101000, 4.8, 98, 0.5, 4)),
    ],
    "Roti & Bakery": [
        ("Bakery Sari Rasa", "Bu Ani", "0813-2566-7731", "Kotagede, Yogyakarta", (148000, 4.5, 91, 3.0, 1)),
        ("CV Roti Mataram", "Pak Darmanto", "0812-1520-3388", "Umbulharjo, Yogyakarta", (139000, 4.2, 95, 2.2, 1)),
        ("PT Gandum Emas Bakery", "Ibu Fitri", "0274-372-450", "Godean, Sleman", (156000, 4.7, 88, 1.8, 2)),
        ("Dapur Roti Bu Har", "Bu Harjono", "0857-2901-1166", "Kasihan, Bantul", (131000, 3.9, 84, 4.5, 1)),
    ],
    "Sembako": [
        ("PT Beras Sentosa Klaten", "Pak Wahyu", "0272-321-889", "Delanggu, Klaten", (685000, 4.4, 93, 1.0, 3)),
        ("UD Sumber Pangan Bantul", "Bu Yanti", "0812-2711-0034", "Piyungan, Bantul", (662000, 4.1, 87, 1.6, 2)),
        ("CV Logistik Sembako Jogja", "Mas Arif", "0858-0021-5560", "Mlati, Sleman", (671000, 4.6, 96, 0.7, 2)),
        ("Koperasi Pangan Gunungkidul", "Pak Supri", "0819-3388-1273", "Wonosari, Gunungkidul", (648000, 3.9, 80, 2.4, 4)),
        ("PT Agung Distribusi Pangan", "Ibu Lusi", "0274-889-102", "Ring Road Selatan", (699000, 4.8, 97, 0.6, 3)),
    ],
    "Susu & Olahan": [
        ("Koperasi Susu Sarono Makmur", "Pak Sardi", "0813-2810-6634", "Cangkringan, Sleman", (128000, 4.5, 90, 1.5, 1)),
        ("PT Dairy Indo Prima", "Ibu Kartika", "0274-551-781", "Jl. Solo, Sleman", (142000, 4.8, 97, 0.6, 2)),
        ("CV Susu Segar Boyolali", "Pak Tono", "0857-2244-9910", "Mojosongo, Boyolali", (121000, 4.2, 86, 2.2, 2)),
        ("UD Olahan Susu Kaliurang", "Bu Ningsih", "0812-1561-4471", "Pakem, Sleman", (135000, 4.4, 92, 1.4, 1)),
    ],
}
# supplier yang sengaja dibuat belum lengkap / nonaktif untuk memperlihatkan perilaku sistem
BELUM_LENGKAP = {"Kebun Organik Gamping": ("C3", "C5")}
NONAKTIF = {"Dapur Roti Bu Har"}
# keputusan masa lalu (hari yang lalu) agar status prioritas TPS bervariasi
KEPUTUSAN_LALU = {"Susu & Olahan": 12, "Minuman": 47}


def kosongkan(con):
    con.execute("DELETE FROM keputusan")
    con.execute("DELETE FROM nilai")
    con.execute("DELETE FROM supplier")
    con.execute("DELETE FROM sqlite_sequence WHERE name IN ('supplier','keputusan')")


def isi():
    db.init_db()
    con = db.connect()
    kosongkan(con)
    krit = {r["kode"]: r["id"] for r in con.execute("SELECT id, kode FROM kriteria")}
    if not all(k in krit for k in ("C1", "C2", "C3", "C4", "C5")):
        print("[!] Kriteria C1-C5 tidak lengkap (mungkin sudah diubah). Hapus dss.db agar kriteria awal dibuat ulang.")
        return
    waktu_isi = (datetime.now() - timedelta(days=60)).strftime("%Y-%m-%d %H:%M:%S")
    no = 0
    for kat, daftar in SUPPLIER.items():
        r = con.execute("SELECT id FROM kategori WHERE nama=?", (kat,)).fetchone()
        kid = r["id"] if r else con.execute("INSERT INTO kategori (nama, ikon) VALUES (?,?)", (kat, db.IKON_KATEGORI.get(kat, "📦"))).lastrowid
        for nama, kontak, tel, alamat, nilai in daftar:
            no += 1
            # sumber bukti fiktif: semua penawaran dari periode yang sama (September 2026)
            bukti = None if nama in BELUM_LENGKAP else f"Surat penawaran No. {no:03d}/PNW/IX/2026 · uji sampel QC {8 + no % 14} Sep 2026"
            sid = con.execute(
                "INSERT INTO supplier (kode,nama,kategori_id,kontak,telepon,alamat,aktif,dibuat,dibuat_oleh,bukti,bukti_diubah) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                (f"SUP-{no:03d}", nama, kid, kontak, tel, alamat, 0 if nama in NONAKTIF else 1, waktu_isi, "Data simulasi", bukti, waktu_isi if bukti else None),
            ).lastrowid
            for kode, v in zip(("C1", "C2", "C3", "C4", "C5"), nilai):
                if kode in BELUM_LENGKAP.get(nama, ()):
                    continue
                con.execute("INSERT INTO nilai (supplier_id,kriteria_id,nilai,diubah,diubah_oleh) VALUES (?,?,?,?,?)",
                            (sid, krit[kode], v, waktu_isi, "Data simulasi"))
    con.commit()

    # keputusan lalu dihitung dengan mesin SAW yang sama
    kriteria = [dict(r) for r in con.execute("SELECT * FROM kriteria ORDER BY urutan, id")]
    for kat, hari in KEPUTUSAN_LALU.items():
        kid = con.execute("SELECT id FROM kategori WHERE nama=?", (kat,)).fetchone()["id"]
        alt = [dict(r) for r in con.execute("SELECT id,kode,nama,bukti FROM supplier WHERE kategori_id=? AND aktif=1 ORDER BY kode", (kid,))]
        nilai = {(r["supplier_id"], r["kriteria_id"]): r["nilai"] for r in con.execute(
            "SELECT n.* FROM nilai n JOIN supplier s ON s.id=n.supplier_id WHERE s.kategori_id=?", (kid,))}
        h = saw.hitung_saw(kriteria, alt, nilai)
        w = h["ranking"][0]
        tgl = (datetime.now() - timedelta(days=hari)).replace(hour=10, minute=15, second=0).strftime("%Y-%m-%d %H:%M:%S")
        con.execute(
            """INSERT INTO keputusan (kategori_id,judul,catatan,bobot_json,hasil_json,pemenang_id,pemenang_nama,nilai_v,dibuat_oleh,dibuat_pada)
               VALUES (?,?,?,?,?,?,?,?,?,?)""",
            (kid, f"Evaluasi supplier {kat} (simulasi)", "Data simulasi — keputusan contoh periode sebelumnya.",
             json.dumps([{"kode": k["kode"], "nama": k["nama"], "jenis": k["jenis"], "bobot": k["bobot"]} for k in kriteria]),
             json.dumps([{"rank": r["rank"], "kode": r["kode"], "nama": r["nama"], "v": round(r["v"], 6), "bukti": r.get("bukti"),
                          "x": {k["kode"]: h["X"][r["id"]][k["id"]] for k in kriteria},
                          "r": {k["kode"]: round(h["R"][r["id"]][k["id"]], 6) for k in kriteria}} for r in h["ranking"]]),
             w["id"], w["nama"], w["v"], "Alysa Salsabila", tgl),
        )
    con.commit()
    con.close()
    print(f"[OK] {no} supplier simulasi beserta penilaiannya dimasukkan ke {config.DB_PATH}")


if __name__ == "__main__":
    if "--kosong" in sys.argv:
        db.init_db()
        c = db.connect(); kosongkan(c); c.commit(); c.close()
        print("[OK] Semua supplier, nilai, dan keputusan dikosongkan.")
    else:
        isi()

"""
Mesin perhitungan kasus DSS selain pemilihan supplier.
Semua data berasal dari MartOffice (api/dss_data.php): TPS, OAS, KMS, dan MIS.

  1. Rekomendasi restok      : titik pesan ulang (reorder point) + stok target
  2. Produk promo/diskon     : SAW + simulasi what-if diskon
  3. Paket bundling          : analisis keranjang (support, confidence, lift)
  4. Pegawai terbaik         : SAW lintas TPS, OAS, KMS
  5. Kasir per shift         : pola jam transaksi + pembagian kasir proporsional
"""
import math

import saw

HARI = {1: "Minggu", 2: "Senin", 3: "Selasa", 4: "Rabu", 5: "Kamis", 6: "Jumat", 7: "Sabtu"}
URUT_HARI = [2, 3, 4, 5, 6, 7, 1]  # Senin ... Minggu

# skor risiko kedaluwarsa per kategori produk (acuan SOP kedaluwarsa di KMS)
RISIKO_KEDALUWARSA = {
    "Buah & Sayur": 5, "Roti & Bakery": 5, "Daging & Ikan": 5, "Susu & Olahan": 4,
    "Camilan": 2, "Minuman": 2, "Sembako": 2, "Kebutuhan Rumah": 1,
}


def rata_harian(p):
    return p["terjual_30"] / 30.0


# ---------------------------------------------------------------------
# 1. Rekomendasi restok
# ---------------------------------------------------------------------
def hitung_restok(produk, supplier_kat, siklus=7, aman=2, lead_bawaan=2, segar=3, mis_peringatan=None, sudah_ditugaskan=()):
    """
    produk          : list produk dari TPS
    supplier_kat    : {nama_kategori: {"supplier": nama, "lead": hari}} dari juara SAW pemilihan supplier (DSS)
    segar           : siklus maksimum untuk kategori mudah rusak (risiko kedaluwarsa ≥ 4, acuan SOP KMS)
    mis_peringatan  : {id_produk: "kritis"/"perhatian"} produk yang sedang muncul di Pusat Peringatan MIS
    sudah_ditugaskan: nama produk yang sudah ada di tugas [DSS] OAS yang belum selesai
    Rumus:
      rata harian = terjual 30 hari / 30
      titik pesan ulang (ROP) = rata × (lead time + stok pengaman)
      stok target = rata × (lead time + stok pengaman + siklus)
      saran pesan = stok target − stok saat ini (dibulatkan ke atas)
    """
    mis_peringatan = mis_peringatan or {}
    hasil = []
    for p in produk:
        r = rata_harian(p)
        sup = supplier_kat.get(p["kategori"]) or {}
        lead = float(sup.get("lead") or lead_bawaan)
        mudah_rusak = RISIKO_KEDALUWARSA.get(p["kategori"], 2) >= 4
        siklus_p = min(siklus, segar) if mudah_rusak else siklus
        rop = r * (lead + aman)
        target = r * (lead + aman + siklus_p)
        saran = max(0, math.ceil(target - p["stok"])) if r > 0 else 0
        tahan = (p["stok"] / r) if r > 0 else None
        if r == 0:
            status, tone, urut = "Tidak terjual 30 hari", "n", 4
        elif p["stok"] == 0:
            status, tone, urut = "Habis", "r", 0
        elif p["stok"] <= rop:
            status, tone, urut = "Pesan sekarang", "o", 1
        elif p["stok"] <= rop + r * 3:
            status, tone, urut = "Segera", "y", 2
        else:
            status, tone, urut = "Aman", "g", 3
        saran = saran if urut <= 2 else 0
        hasil.append({**p, "rata": r, "lead": lead, "rop": rop, "target": target, "saran": saran,
                      "tahan": tahan, "status": status, "tone": tone, "urut": urut, "siklus": siklus_p, "mudah_rusak": mudah_rusak,
                      "mis": mis_peringatan.get(p["id"]), "ditugaskan": any(p["nama"] in t for t in sudah_ditugaskan),
                      "supplier": sup.get("supplier"), "nilai_pesan": saran * p["harga"]})
    hasil.sort(key=lambda x: (x["urut"], x["tahan"] if x["tahan"] is not None else 9e9))
    return hasil


# ---------------------------------------------------------------------
# 2. Produk promo / diskon (SAW)
# ---------------------------------------------------------------------
def nilai_promo(p, total_omzet):
    r = rata_harian(p)
    hari = min(120.0, p["stok"] / r) if r > 0 else 120.0
    turun = 0.0
    if p["terjual_lalu"] > 0 and p["terjual_30"] < p["terjual_lalu"]:
        turun = (p["terjual_lalu"] - p["terjual_30"]) / p["terjual_lalu"] * 100
    porsi = (p["omzet_30"] / total_omzet * 100) if total_omzet else 0
    return {
        "P1": max(0.5, round(hari, 2)),
        "P2": round(turun, 2) + 1,
        "P3": RISIKO_KEDALUWARSA.get(p["kategori"], 2),
        "P4": max(0.1, round(porsi, 2)),
        "_mentah": {"hari": hari, "turun": turun, "porsi": porsi},
    }


def kategori_disebut(teks_list, kategori):
    """Kategori produk yang disebut di pengumuman/agenda OAS (mis. "Promo akhir bulan: sembako dan minuman")."""
    hasil = set()
    for t in teks_list:
        t = (t or "").lower()
        for k in kategori:
            kata = [w for w in k.lower().replace("&", " ").split() if len(w) > 3]
            if any(w in t for w in kata):
                hasil.add(k)
    return hasil


def hitung_promo(produk, kriteria, total_omzet=None):
    """
    Produk yang stoknya habis tidak diikutkan (tidak ada yang bisa dipromosikan).
    total_omzet: omzet toko 30 hari dari MIS (mis_penjualan_harian) sebagai penyebut kontribusi omzet (P4).
    """
    kandidat = [p for p in produk if p["stok"] > 0]
    total = total_omzet or sum(p["omzet_30"] for p in produk)
    alt, nilai, info = [], {}, {}
    for p in kandidat:
        v = nilai_promo(p, total)
        alt.append({"id": p["id"], "kode": p["sku"], "nama": p["nama"]})
        info[p["id"]] = {**p, **v["_mentah"]}
        for k in kriteria:
            nilai[(p["id"], k["id"])] = v[k["kode"]]
    return saw.hitung_saw(kriteria, alt, nilai), info


# ---------------------------------------------------------------------
# 3. Paket bundling (analisis keranjang)
# ---------------------------------------------------------------------
def hitung_bundling(produk, keranjang, diskon=10, lambat_ids=()):
    """
    support    = transaksi berisi A dan B / seluruh transaksi
    confidence = transaksi berisi A dan B / transaksi berisi A
    lift       = support(A,B) / (support(A) × support(B))   → > 1 berarti saling mendorong
    """
    peta = {p["id"]: p for p in produk}
    n = keranjang.get("transaksi") or 0
    hasil = []
    for x in keranjang.get("pasangan", []):
        a, b = peta.get(x["a"]), peta.get(x["b"])
        if not a or not b or not n or not x["trx_a"] or not x["trx_b"]:
            continue
        sup = x["bersama"] / n
        lift = x["bersama"] * n / (x["trx_a"] * x["trx_b"])
        normal = a["harga"] + b["harga"]
        hasil.append({
            "a": a, "b": b, "bersama": x["bersama"], "support": sup * 100,
            "conf_ab": x["bersama"] / x["trx_a"] * 100, "conf_ba": x["bersama"] / x["trx_b"] * 100,
            "lift": lift, "harga_normal": normal, "harga_paket": round(normal * (1 - diskon / 100) / 500) * 500,
            "lambat": a["id"] in lambat_ids or b["id"] in lambat_ids,
            "lintas": a["kategori"] != b["kategori"],
        })
    hasil.sort(key=lambda r: (-round(r["lift"], 3), -r["bersama"]))
    return hasil


# ---------------------------------------------------------------------
# 4. Pegawai terbaik (SAW)
# ---------------------------------------------------------------------
def nilai_pegawai(g):
    tepat = (g["tepat_waktu"] / g["tugas_selesai"] * 100) if g["tugas_selesai"] else 0
    return {
        "E1": g["transaksi"] + 1, "E2": g["omzet"] + 1, "E3": max(1.0, round(tepat, 1)),
        "E4": g["terlambat"] + 1, "E5": g["knowledge"] + 1, "E6": g["dinilai_membantu"] + 1,
        "_mentah": {"tepat": tepat},
    }


def hitung_pegawai(pegawai, kriteria):
    alt, nilai, info = [], {}, {}
    for g in pegawai:
        v = nilai_pegawai(g)
        alt.append({"id": g["id"], "kode": f"PG-{g['id']:02d}", "nama": g["nama"]})
        info[g["id"]] = {**g, **v["_mentah"], "nilai": {k: v[k] for k in v if not k.startswith("_")}}
        for k in kriteria:
            nilai[(g["id"], k["id"])] = v[k["kode"]]
    return saw.hitung_saw(kriteria, alt, nilai), info


# ---------------------------------------------------------------------
# 5. Kasir per shift
# ---------------------------------------------------------------------
def bagi_proporsional(total, porsi, minimal=1):
    """Membagi `total` kasir ke beberapa shift sesuai porsi transaksi (metode sisa terbesar, minimal 1)."""
    k = len(porsi)
    if total < k * minimal:
        return [minimal] * k
    sisa_total = total - k * minimal
    s = sum(porsi) or 1
    kuota = [p / s * sisa_total for p in porsi]
    dasar = [int(q) for q in kuota]
    kurang = sisa_total - sum(dasar)
    urut = sorted(range(k), key=lambda i: -(kuota[i] - dasar[i]))
    for i in urut[:kurang]:
        dasar[i] += 1
    return [d + minimal for d in dasar]


def hitung_shift(jam, kasir_list, kasir_tersedia=3, batas=14, minggu=8, tren=None):
    """
    jam        : [{"hari": 1–7 (Minggu=1), "jam": 0–23, "transaksi": n}] selama `minggu` minggu
    kasir_list : pegawai urut dari kinerja terbaik (dipakai untuk usulan nama)
    """
    peta = {(r["hari"], r["jam"]): r["transaksi"] / minggu for r in jam}
    jam_buka = sorted({r["jam"] for r in jam}) or list(range(8, 21))
    matriks = [{"hari": HARI[h], "kode": h, "jam": [round(peta.get((h, j), 0), 2) for j in jam_buka]} for h in URUT_HARI]
    maks = max([v for b in matriks for v in b["jam"]] or [1])

    def ringkas(hari_set, label):
        per_jam = [sum(peta.get((h, j), 0) for h in hari_set) / len(hari_set) for j in jam_buka]
        rata = sum(per_jam) / len(per_jam) if per_jam else 0
        pagi = sum(v for j, v in zip(jam_buka, per_jam) if j < batas)
        sore = sum(v for j, v in zip(jam_buka, per_jam) if j >= batas)
        alok = bagi_proporsional(kasir_tersedia, [pagi, sore])
        sibuk = [j for j, v in zip(jam_buka, per_jam) if rata and v >= rata * 1.25]
        puncak = jam_buka[per_jam.index(max(per_jam))] if per_jam else None
        return {"label": label, "per_jam": per_jam, "rata": rata, "pagi": pagi, "sore": sore,
                "kasir_pagi": alok[0], "kasir_sore": alok[1], "sibuk": sibuk, "puncak": puncak,
                "porsi_pagi": pagi / (pagi + sore) * 100 if pagi + sore else 0}

    kerja = ringkas([2, 3, 4, 5, 6], "Hari kerja (Senin–Jumat)")
    libur = ringkas([7, 1], "Akhir pekan (Sabtu–Minggu)")

    # usulan nama: kasir dengan kinerja terbaik ditempatkan bergantian, dimulai dari shift yang lebih sibuk
    for r in (kerja, libur):
        sibuk, lain = ("sore", "pagi") if r["sore"] > r["pagi"] else ("pagi", "sore")
        sisa = {"pagi": r["kasir_pagi"], "sore": r["kasir_sore"]}
        urutan = []
        while sisa["pagi"] or sisa["sore"]:
            for s_ in (sibuk, lain):
                if sisa[s_]:
                    urutan.append(s_)
                    sisa[s_] -= 1
        r["nama_pagi"], r["nama_sore"] = [], []
        for i, s_ in enumerate(urutan):
            nama = kasir_list[i % len(kasir_list)]["nama"] if kasir_list else f"Kasir {i + 1}"
            if kasir_list and i >= len(kasir_list):
                nama += " (rangkap)"
            r["nama_" + s_].append(nama)
    # tren transaksi 7 hari dari MIS: bila naik ≥ 10%, beban jam puncak diperkirakan ikut naik
    saran_tren = None
    if tren is not None and tren >= 10:
        saran_tren = f"Transaksi 7 hari terakhir naik {tren:.1f}% (MIS). Siapkan 1 kasir cadangan pada jam puncak."
    elif tren is not None and tren <= -10:
        saran_tren = f"Transaksi 7 hari terakhir turun {abs(tren):.1f}% (MIS). Jumlah kasir saat ini sudah mencukupi."
    return {"jam_buka": jam_buka, "matriks": matriks, "maks": maks, "kerja": kerja, "libur": libur,
            "batas": batas, "kasir": kasir_tersedia, "minggu": minggu, "tren": tren, "saran_tren": saran_tren}

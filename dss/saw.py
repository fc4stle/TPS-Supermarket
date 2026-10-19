"""
Mesin perhitungan Simple Additive Weighting (SAW).

Langkah SAW:
  1. Matriks keputusan X (nilai alternatif i pada kriteria j).
  2. Normalisasi R:
       benefit : r_ij = x_ij / max_i(x_ij)
       cost    : r_ij = min_i(x_ij) / x_ij
  3. Bobot dinormalisasi agar total = 1 : w_j = bobot_j / Σ bobot.
  4. Nilai preferensi V_i = Σ_j w_j · r_ij
  5. Ranking: V terbesar = alternatif terbaik.
"""


def hitung_saw(kriteria, alternatif, nilai, bobot=None):
    """
    kriteria  : list dict {id, kode, nama, jenis, bobot}
    alternatif: list dict {id, kode, nama, ...}
    nilai     : dict {(alt_id, krit_id): float}
    bobot     : dict opsional {krit_id: bobot} untuk menimpa bobot kriteria (analisis sensitivitas)
    """
    kriteria = list(kriteria)
    bobot_asli = {k["id"]: float(bobot.get(k["id"], k["bobot"]) if bobot else k["bobot"]) for k in kriteria}
    total_bobot = sum(bobot_asli.values())
    w = {kid: (b / total_bobot if total_bobot > 0 else 0.0) for kid, b in bobot_asli.items()}

    lengkap, kurang = [], []
    for a in alternatif:
        hilang = [k["kode"] for k in kriteria if not nilai.get((a["id"], k["id"]))]
        (kurang if hilang else lengkap).append({**dict(a), "hilang": hilang})

    hasil = {
        "kriteria": [dict(k) for k in kriteria],
        "bobot": bobot_asli,
        "total_bobot": total_bobot,
        "w": w,
        "alternatif": lengkap,
        "tidak_lengkap": kurang,
        "X": {}, "R": {}, "WR": {}, "V": {},
        "pembagi": {},
        "ranking": [],
    }
    if not lengkap or not kriteria:
        return hasil

    # 1. Matriks keputusan
    for a in lengkap:
        hasil["X"][a["id"]] = {k["id"]: float(nilai[(a["id"], k["id"])]) for k in kriteria}

    # 2. Normalisasi
    for k in kriteria:
        kolom = [hasil["X"][a["id"]][k["id"]] for a in lengkap]
        hasil["pembagi"][k["id"]] = max(kolom) if k["jenis"] == "benefit" else min(kolom)
    for a in lengkap:
        hasil["R"][a["id"]] = {}
        for k in kriteria:
            x = hasil["X"][a["id"]][k["id"]]
            p = hasil["pembagi"][k["id"]]
            hasil["R"][a["id"]][k["id"]] = (x / p) if k["jenis"] == "benefit" else (p / x)

    # 3–4. Nilai terbobot dan preferensi
    for a in lengkap:
        hasil["WR"][a["id"]] = {k["id"]: w[k["id"]] * hasil["R"][a["id"]][k["id"]] for k in kriteria}
        hasil["V"][a["id"]] = sum(hasil["WR"][a["id"]].values())

    # 5. Ranking (seri dipecah berdasarkan kode agar hasil konsisten)
    urut = sorted(lengkap, key=lambda a: (-round(hasil["V"][a["id"]], 10), a["kode"]))
    for i, a in enumerate(urut, 1):
        hasil["ranking"].append({**a, "rank": i, "v": hasil["V"][a["id"]]})
    return hasil


def juara(kriteria, alternatif, nilai, bobot=None):
    r = hitung_saw(kriteria, alternatif, nilai, bobot)["ranking"]
    return r[0] if r else None


def geser_bobot(kriteria, kid, baru):
    """Ubah bobot satu kriteria menjadi `baru` (persen) dan bagi sisa 100% ke kriteria lain secara proporsional."""
    lain = [k for k in kriteria if k["id"] != kid]
    sisa_lama = sum(float(k["bobot"]) for k in lain)
    sisa_baru = 100.0 - baru
    out = {kid: baru}
    for k in lain:
        out[k["id"]] = (float(k["bobot"]) / sisa_lama * sisa_baru) if sisa_lama > 0 else sisa_baru / max(1, len(lain))
    return out


def sensitivitas(kriteria, alternatif, nilai):
    """
    Untuk setiap kriteria, bobotnya digeser dari 0% sampai 100% (langkah 1%) sementara
    kriteria lain menyesuaikan secara proporsional. Dicatat rentang bobot di mana
    juara tetap sama dengan juara saat ini, serta siapa juara pada ±10 poin.
    """
    kriteria = [dict(k) for k in kriteria]
    tot = sum(float(k["bobot"]) for k in kriteria) or 1.0
    for k in kriteria:  # bobot bebas diubah ke persen (total 100) agar dapat digeser 0–100%
        k["bobot"] = float(k["bobot"]) / tot * 100
    awal = juara(kriteria, alternatif, nilai)
    if not awal:
        return None, []
    tabel = []
    for k in kriteria:
        b0 = float(k["bobot"])
        juara_per = {}
        for p in range(0, 101):
            j = juara(kriteria, alternatif, nilai, geser_bobot(kriteria, k["id"], float(p)))
            juara_per[p] = j
        # rentang kontinu di sekitar bobot saat ini yang juaranya tetap
        b_int = int(round(b0))
        lo = hi = b_int
        while lo - 1 >= 0 and juara_per[lo - 1]["id"] == awal["id"]:
            lo -= 1
        while hi + 1 <= 100 and juara_per[hi + 1]["id"] == awal["id"]:
            hi += 1
        turun = juara_per[max(0, b_int - 10)]
        naik = juara_per[min(100, b_int + 10)]
        tabel.append({
            "kriteria": k,
            "bobot": b0,
            "aman_min": lo, "aman_max": hi,
            "turun": max(0, b_int - 10), "juara_turun": turun,
            "naik": min(100, b_int + 10), "juara_naik": naik,
            "stabil": turun["id"] == awal["id"] and naik["id"] == awal["id"],
        })
    return awal, tabel

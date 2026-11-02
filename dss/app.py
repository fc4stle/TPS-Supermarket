"""
DSS MartOffice — Decision Support System pemilihan supplier terbaik (metode SAW).
Kelanjutan dari TPS, OAS, KMS, dan MIS MartOffice. Dibangun dengan Flask + SQLite,
login memakai Single Sign-On dari MartOffice (PHP + MySQL).

Menjalankan:  python app.py   →   http://localhost:5055
"""
import csv
import io
import json
import os
import secrets
import socket
import sys
import time
from datetime import datetime, timedelta
from functools import wraps

from flask import (Flask, Response, abort, flash, g, jsonify, redirect,
                   render_template, request, session, url_for)

import config
import db
import modul
import saw
import sso

app = Flask(__name__)


def _secret_key() -> str:
    """Kunci sesi Flask disimpan di file agar sesi tetap valid setelah aplikasi dijalankan ulang."""
    path = os.path.join(config.BASE_DIR, ".secret_key")
    if not os.path.exists(path):
        with open(path, "w") as f:
            f.write(secrets.token_hex(32))
    with open(path) as f:
        return f.read().strip()


app.config.update(
    SECRET_KEY=_secret_key(),
    SESSION_COOKIE_NAME="DSS_SESSION",      # beda nama dengan cookie MARTOFFICE (sama-sama di localhost)
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",
    PERMANENT_SESSION_LIFETIME=timedelta(hours=8),
)
app.teardown_appcontext(db.close_db)
app.json.ensure_ascii = False   # respons JSON tetap terbaca (emoji & huruf Indonesia tidak di-escape)

BULAN = ["", "Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"]
HARI = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu"]


# =====================================================================
#  Filter & helper tampilan
# =====================================================================
@app.template_filter("angka")
def f_angka(v, des=0):
    if v is None:
        return "–"
    s = f"{float(v):,.{des}f}"
    return s.replace(",", "X").replace(".", ",").replace("X", ".")


@app.template_filter("rp")
def f_rp(v):
    return "Rp " + f_angka(v)


@app.template_filter("nilai")
def f_nilai(v):
    """Nilai mentah kriteria: bilangan bulat tanpa desimal, selain itu maksimal 2 desimal."""
    if v is None:
        return "–"
    v = float(v)
    return f_angka(v, 0) if v.is_integer() else f_angka(v, 2).rstrip("0").rstrip(",")


@app.template_filter("mentah")
def f_mentah(v):
    """Angka untuk atribut value input (titik desimal, tanpa pemisah ribuan)."""
    v = float(v)
    return str(int(v)) if v.is_integer() else f"{v:.6f}".rstrip("0")


@app.template_filter("tgl")
def f_tgl(s, jam=True):
    if not s:
        return "–"
    d = datetime.strptime(str(s)[:19], "%Y-%m-%d %H:%M:%S")
    t = f"{d.day} {BULAN[d.month]} {d.year}"
    return t + (f", {d:%H:%M}" if jam else "")


@app.template_filter("pct")
def f_pct(v, des=1):
    return f_angka(float(v), des) + "%"


@app.context_processor
def inject():
    u = session.get("user")
    home = (u or {}).get("home") or config.MARTOFFICE_URL
    d = datetime.now()
    return {
        "user": u,
        "home": home.rstrip("/"),
        "is_manajer": bool(u and u["role"] == "manajer"),
        "csrf": session.get("csrf", ""),
        "hari_ini": f"{HARI[d.weekday()]}, {d.day} {BULAN[d.month]} {d.year}",
        "port": config.PORT,
        "IKON": db.IKON_KATEGORI,
        "MODUL": MODUL,
    }


# =====================================================================
#  Keamanan: login wajib, role, CSRF
# =====================================================================
def login_wajib(*roles):
    def deco(fn):
        @wraps(fn)
        def inner(*a, **kw):
            u = session.get("user")
            if not u:
                return redirect(url_for("masuk"))
            if roles and u["role"] not in roles:
                flash("err", "Fitur ini hanya untuk role: " + ", ".join(roles) + ".")
                return redirect(url_for("dashboard"))
            return fn(*a, **kw)
        return inner
    return deco


@app.before_request
def cek_csrf():
    if request.method == "POST":
        if not session.get("csrf") or request.form.get("_token") != session["csrf"]:
            flash("err", "Sesi formulir kedaluwarsa. Silakan ulangi.")
            return redirect(request.referrer or url_for("dashboard"))


def flash(kategori, pesan):  # urutan argumen dibuat sama dengan MartOffice: flash('ok', 'pesan')
    from flask import flash as _f
    _f(pesan, kategori)


def nama_user():
    u = session.get("user") or {}
    return u.get("nama", "-")


# =====================================================================
#  Pengambilan data
# =====================================================================
def daftar_kategori():
    return db.rows("""
        SELECT k.*, COUNT(s.id) n_supplier,
               SUM(CASE WHEN s.aktif=1 THEN 1 ELSE 0 END) n_aktif
        FROM kategori k LEFT JOIN supplier s ON s.kategori_id=k.id
        GROUP BY k.id ORDER BY k.nama""")


def daftar_kriteria():
    return [dict(r) for r in db.rows("SELECT * FROM kriteria ORDER BY urutan, id")]


def data_saw(kategori_id, bobot=None):
    """Menyiapkan data dan menjalankan SAW untuk supplier aktif dalam satu kategori."""
    kriteria = daftar_kriteria()
    alt = [dict(r) for r in db.rows(
        "SELECT id, kode, nama, kontak, bukti FROM supplier WHERE kategori_id=? AND aktif=1 ORDER BY kode", (kategori_id,))]
    nilai = {(r["supplier_id"], r["kriteria_id"]): r["nilai"] for r in db.rows(
        "SELECT n.* FROM nilai n JOIN supplier s ON s.id=n.supplier_id WHERE s.kategori_id=?", (kategori_id,))}
    return kriteria, alt, nilai, saw.hitung_saw(kriteria, alt, nilai, bobot)


def pilih_kategori():
    """Kategori aktif dari query string; default kategori pertama yang memiliki supplier aktif."""
    kats = daftar_kategori()
    kid = request.args.get("kategori", type=int)
    if kid and any(k["id"] == kid for k in kats):
        return kats, next(k for k in kats if k["id"] == kid)
    for k in kats:
        if (k["n_aktif"] or 0) > 0:
            return kats, k
    return kats, (kats[0] if kats else None)


def total_bobot():
    return round(db.val("SELECT COALESCE(SUM(bobot),0) FROM kriteria") or 0, 4)


CACHE_DETIK = 120  # data MartOffice dianggap masih segar selama 2 menit


def data_martoffice(home=None, paksa=False, pemicu="otomatis"):
    """
    Mengambil data TPS, MIS, OAS, dan KMS dari MartOffice dengan cache di tabel `sinkron`.
    Mengembalikan dict data (ditambah kunci _waktu dan _offline) atau None bila belum pernah berhasil.
    """
    home = home or (session.get("user") or {}).get("home")
    terakhir = db.row("SELECT * FROM sinkron WHERE status='berhasil' ORDER BY id DESC LIMIT 1")
    if terakhir and not paksa:
        umur = (datetime.now() - datetime.strptime(terakhir["waktu"], "%Y-%m-%d %H:%M:%S")).total_seconds()
        if umur < CACHE_DETIK:
            return {**json.loads(terakhir["data_json"]), "_waktu": terakhir["waktu"], "_offline": False}
    t0 = time.time()
    data, err = sso.ambil_data_martoffice(home)
    durasi = int((time.time() - t0) * 1000)
    if data:
        ringkas = f"TPS {len(data['tps'])} kategori · MIS {data['mis']['jumlah_peringatan']} peringatan · " \
                  f"OAS {len(data['oas']['dokumen'])} dokumen, {len(data['oas']['tugas'])} tugas · KMS {len(data['kms']['artikel'])} artikel"
        db.run("INSERT INTO sinkron (waktu,status,pemicu,durasi_ms,ringkasan,data_json) VALUES (?,?,?,?,?,?)",
               (db.sekarang(), "berhasil", pemicu, durasi, ringkas, json.dumps(data, ensure_ascii=False)))
        db.run("DELETE FROM sinkron WHERE id NOT IN (SELECT id FROM sinkron ORDER BY id DESC LIMIT 50)")
        simpan_kategori(data["tps"])
        return {**data, "_waktu": db.sekarang(), "_offline": False}
    db.run("INSERT INTO sinkron (waktu,status,pemicu,durasi_ms,ringkasan) VALUES (?,?,?,?,?)",
           (db.sekarang(), "gagal", pemicu, durasi, err))
    if terakhir:  # pakai snapshot terakhir yang berhasil
        return {**json.loads(terakhir["data_json"]), "_waktu": terakhir["waktu"], "_offline": True}
    return None


def simpan_kategori(tps):
    """Menambahkan kategori produk TPS yang belum ada di DSS. Mengembalikan jumlah kategori baru."""
    baru = 0
    for k in tps:
        ada = db.row("SELECT id FROM kategori WHERE nama=?", (k["nama"],))
        if ada:
            db.run("UPDATE kategori SET sumber='tps', disinkron=? WHERE id=?", (db.sekarang(), ada["id"]))
        else:
            db.run("INSERT INTO kategori (nama, ikon, sumber, disinkron) VALUES (?,?, 'tps', ?)",
                   (k["nama"], db.IKON_KATEGORI.get(k["nama"]) or k.get("ikon") or "📦", db.sekarang()))
            baru += 1
    return baru


# =====================================================================
#  SSO
# =====================================================================
def catat_sso(status, payload=None, alasan=None):
    p = payload if isinstance(payload, dict) else {}
    db.run("INSERT INTO sso_log (waktu,jti,username,nama,role,status,alasan,ip) VALUES (?,?,?,?,?,?,?,?)",
           (db.sekarang(), str(p.get("jti", ""))[:64] or None, p.get("username"), p.get("nama"), p.get("role"),
            status, alasan, request.remote_addr))


@app.route("/sso")
def sso_masuk():
    """Titik masuk SSO: menerima token dari MartOffice, memverifikasi, lalu membuat sesi DSS."""
    token = request.args.get("token", "")
    payload, alasan = sso.verifikasi_token(token)
    if not alasan and db.val("SELECT COUNT(*) FROM sso_log WHERE jti=? AND status='berhasil'", (payload["jti"],)):
        alasan = "Token sudah pernah dipakai (replay ditolak)"
    if alasan:
        catat_sso("ditolak", payload, alasan)
        session.pop("user", None)
        return render_template("masuk.html", alasan=alasan), 403

    # Just-In-Time provisioning: data pengguna disalin/diperbarui dari MartOffice
    now = db.sekarang()
    db.run("""INSERT INTO pengguna (id,username,nama,role,jabatan,pertama_masuk,terakhir_masuk,jumlah_masuk)
              VALUES (?,?,?,?,?,?,?,1)
              ON CONFLICT(id) DO UPDATE SET username=excluded.username, nama=excluded.nama, role=excluded.role,
                 jabatan=excluded.jabatan, terakhir_masuk=excluded.terakhir_masuk, jumlah_masuk=jumlah_masuk+1""",
           (int(payload["sub"]), payload["username"], payload["nama"], payload["role"], payload.get("jabatan"), now, now))
    catat_sso("berhasil", payload)

    session.clear()
    session.permanent = True
    session["user"] = {k: payload.get(k) for k in ("sub", "username", "nama", "role", "jabatan", "home")}
    session["csrf"] = secrets.token_hex(16)
    data_martoffice(payload.get("home"), paksa=True, pemicu="login SSO")
    flash("ok", f"Selamat datang, {payload['nama']}! Anda masuk ke DSS lewat Single Sign-On MartOffice.")
    return redirect(url_for("dashboard"))


@app.route("/sso/keluar")
def sso_keluar():
    """Single sign-out: dipanggil oleh logout.php MartOffice, lalu kembali ke halaman login MartOffice."""
    u = session.get("user")
    if u:
        catat_sso("keluar", u, "Logout dari MartOffice")
    session.clear()
    nxt = request.args.get("next", "")
    if nxt and sso.cek_api("keluar:" + nxt, request.args.get("ts"), request.args.get("sig")):
        return redirect(nxt)
    return redirect(url_for("masuk"))


@app.route("/keluar")
def keluar():
    """Logout dari DSS sekaligus dari MartOffice."""
    u = session.get("user") or {}
    home = (u.get("home") or config.MARTOFFICE_URL).rstrip("/")
    if u:
        catat_sso("keluar", u, "Logout dari DSS")
    session.clear()
    return redirect(home + "/logout.php")


@app.route("/masuk")
def masuk():
    if session.get("user"):
        return redirect(url_for("dashboard"))
    return render_template("masuk.html", alasan=None)


# =====================================================================
#  API untuk MartOffice (ditandatangani HMAC)
# =====================================================================
@app.route("/api/ringkasan")
def api_ringkasan():
    if not sso.cek_api("dss:ringkasan", request.args.get("ts"), request.args.get("sig")):
        return jsonify(ok=False, pesan="Tanda tangan API tidak valid"), 401
    juara = []
    for k in daftar_kategori():
        _, _, _, h = data_saw(k["id"])
        if h["ranking"]:
            r = h["ranking"][0]
            juara.append({"kategori": k["nama"], "ikon": k["ikon"], "supplier": r["nama"], "v": round(r["v"], 4)})
    terakhir = db.row("""SELECT k.nama kategori, p.judul, p.pemenang_nama, p.nilai_v, p.dibuat_oleh, p.dibuat_pada
                         FROM keputusan p JOIN kategori k ON k.id=p.kategori_id ORDER BY p.id DESC LIMIT 1""")
    return jsonify(
        ok=True,
        supplier_aktif=db.val("SELECT COUNT(*) FROM supplier WHERE aktif=1"),
        kriteria=db.val("SELECT COUNT(*) FROM kriteria"),
        keputusan=db.val("SELECT COUNT(*) FROM keputusan"),
        juara=juara,
        terakhir=dict(terakhir) if terakhir else None,
        url=f"http://localhost:{config.PORT}",
    )


# =====================================================================
#  Halaman
# =====================================================================
@app.route("/supplier/ringkasan")
@login_wajib()
def supplier_ringkasan():
    kats = daftar_kategori()
    juara = []
    for k in kats:
        _, alt, _, h = data_saw(k["id"])
        if alt:
            r = h["ranking"]
            juara.append({
                "kategori": k, "pertama": r[0] if r else None, "kedua": r[1] if len(r) > 1 else None,
                "n": len(alt), "kurang": len(h["tidak_lengkap"]),
                "terakhir": db.row("SELECT pemenang_nama, dibuat_pada FROM keputusan WHERE kategori_id=? ORDER BY id DESC LIMIT 1", (k["id"],)),
            })
    mo = data_martoffice()
    prioritas = []
    if mo:
        kats = daftar_kategori()
        info = {k["nama"]: k for k in kats}
        for t in mo["tps"]:
            k = info.get(t["nama"])
            akhir = db.row("SELECT dibuat_pada FROM keputusan WHERE kategori_id=? ORDER BY id DESC LIMIT 1", (k["id"],)) if k else None
            umur = (datetime.now() - datetime.strptime(akhir["dibuat_pada"], "%Y-%m-%d %H:%M:%S")).days if akhir else None
            n_sup = (k["n_aktif"] or 0) if k else 0
            if not n_sup:
                status, tone = "Belum ada supplier di DSS", "n"
            elif not akhir:
                status, tone = "Belum pernah dievaluasi", "r"
            elif umur > 30:
                status, tone = f"Evaluasi terakhir {umur} hari lalu", "y"
            else:
                status, tone = "Sudah dievaluasi", "g"
            prioritas.append({**t, "kat": k, "status": status, "tone": tone, "n_supplier": n_sup,
                              "skor": t["omzet"] * (1 if not n_sup else 2 if not akhir else 1.5 if umur > 30 else 0.5) + t["menipis"] * 50000})
        prioritas.sort(key=lambda x: -x["skor"])

    langkah = [
        ("Data supplier", db.val("SELECT COUNT(*) FROM supplier WHERE aktif=1") > 0, url_for("supplier")),
        ("Kriteria & bobot", total_bobot() > 0, url_for("kriteria")),
        ("Penilaian lengkap", any(j["pertama"] for j in juara), url_for("penilaian")),
        ("Perhitungan SAW", any(j["pertama"] for j in juara), url_for("hitung")),
        ("Keputusan disimpan", db.val("SELECT COUNT(*) FROM keputusan") > 0, url_for("keputusan")),
    ]
    stat = {
        "supplier": db.val("SELECT COUNT(*) FROM supplier WHERE aktif=1"),
        "kategori": sum(1 for j in juara if j["pertama"]),
        "kriteria": db.val("SELECT COUNT(*) FROM kriteria"),
        "bobot": total_bobot(),
        "benefit": db.val("SELECT COUNT(*) FROM kriteria WHERE jenis='benefit'"),
        "cost": db.val("SELECT COUNT(*) FROM kriteria WHERE jenis='cost'"),
        "keputusan": db.val("SELECT COUNT(*) FROM keputusan"),
        "sso": db.val("SELECT COUNT(*) FROM sso_log WHERE status='berhasil' AND date(waktu)=date('now','localtime')"),
    }
    terbaru = db.rows("""SELECT p.*, k.nama kategori, k.ikon FROM keputusan p JOIN kategori k ON k.id=p.kategori_id
                         ORDER BY p.id DESC LIMIT 5""")
    return render_template("supplier_ringkasan.html", juara=juara, mo=mo, prioritas=prioritas, langkah=langkah,
                           stat=stat, terbaru=terbaru, active="sup-ringkasan")


# ---------------- Supplier ----------------
def _form_supplier():
    f = {k: (request.form.get(k) or "").strip() for k in ("kode", "nama", "kategori_id", "kontak", "telepon", "alamat")}
    err = []
    if len(f["nama"]) < 3:
        err.append("Nama supplier minimal 3 karakter.")
    if not db.row("SELECT id FROM kategori WHERE id=?", (f["kategori_id"] or 0,)):
        err.append("Pilih kategori supplier.")
    if f["telepon"] and not all(c.isdigit() or c in "+-() " for c in f["telepon"]):
        err.append("Nomor telepon hanya boleh berisi angka, spasi, +, -, ( ).")
    return f, err


@app.route("/supplier", methods=["GET", "POST"])
@login_wajib()
def supplier():
    if request.method == "POST":
        aksi = request.form.get("aksi")
        if aksi == "sinkron":
            sebelum = db.val("SELECT COUNT(*) FROM kategori")
            mo = data_martoffice(paksa=True, pemicu="tombol sinkron kategori")
            ok = mo and not mo["_offline"]
            flash("ok" if ok else "err",
                  f"Sinkronisasi kategori dari TPS selesai ({db.val('SELECT COUNT(*) FROM kategori') - sebelum} kategori baru)." if ok
                  else "TPS MartOffice tidak dapat dihubungi. Pastikan Apache menyala.")
            return redirect(url_for("supplier"))
        f, err = _form_supplier()
        if not f["kode"]:
            last = db.val("SELECT MAX(CAST(SUBSTR(kode,5) AS INTEGER)) FROM supplier WHERE kode LIKE 'SUP-%'") or 0
            f["kode"] = f"SUP-{last + 1:03d}"
        if db.row("SELECT id FROM supplier WHERE kode=?", (f["kode"],)):
            err.append(f"Kode {f['kode']} sudah dipakai.")
        if err:
            for e in err:
                flash("err", e)
            return render_template("supplier.html", **_ctx_supplier(), form=f, active="supplier")
        db.run("""INSERT INTO supplier (kode,nama,kategori_id,kontak,telepon,alamat,dibuat,dibuat_oleh)
                  VALUES (?,?,?,?,?,?,?,?)""",
               (f["kode"].upper(), f["nama"], int(f["kategori_id"]), f["kontak"], f["telepon"], f["alamat"], db.sekarang(), nama_user()))
        flash("ok", f"Supplier {f['nama']} ditambahkan. Lanjutkan dengan mengisi penilaiannya.")
        return redirect(url_for("supplier", kategori=f["kategori_id"]))
    return render_template("supplier.html", **_ctx_supplier(), form={}, active="supplier")


def _ctx_supplier():
    kid = request.args.get("kategori", type=int)
    cari = (request.args.get("q") or "").strip()
    n_krit = db.val("SELECT COUNT(*) FROM kriteria")
    sql = """SELECT s.*, k.nama kategori, k.ikon, (SELECT COUNT(*) FROM nilai n WHERE n.supplier_id=s.id) n_nilai
             FROM supplier s JOIN kategori k ON k.id=s.kategori_id WHERE 1=1"""
    p = []
    if kid:
        sql += " AND s.kategori_id=?"; p.append(kid)
    if cari:
        sql += " AND (s.nama LIKE ? OR s.kode LIKE ? OR s.kontak LIKE ?)"; p += [f"%{cari}%"] * 3
    sql += " ORDER BY s.aktif DESC, k.nama, s.kode"
    return {"suppliers": db.rows(sql, p), "kategori": daftar_kategori(), "kid": kid, "cari": cari, "n_krit": n_krit}


@app.route("/supplier/<int:sid>/ubah", methods=["GET", "POST"])
@login_wajib()
def supplier_ubah(sid):
    s = db.row("SELECT * FROM supplier WHERE id=?", (sid,))
    if not s:
        abort(404)
    if request.method == "POST":
        f, err = _form_supplier()
        f["kode"] = (f["kode"] or s["kode"]).upper()
        if db.row("SELECT id FROM supplier WHERE kode=? AND id<>?", (f["kode"], sid)):
            err.append(f"Kode {f['kode']} sudah dipakai supplier lain.")
        if err:
            for e in err:
                flash("err", e)
            return render_template("supplier_form.html", s={**dict(s), **f}, kategori=daftar_kategori(), active="supplier")
        db.run("UPDATE supplier SET kode=?, nama=?, kategori_id=?, kontak=?, telepon=?, alamat=? WHERE id=?",
               (f["kode"], f["nama"], int(f["kategori_id"]), f["kontak"], f["telepon"], f["alamat"], sid))
        flash("ok", "Data supplier diperbarui.")
        return redirect(url_for("supplier", kategori=f["kategori_id"]))
    return render_template("supplier_form.html", s=dict(s), kategori=daftar_kategori(), active="supplier")


@app.route("/supplier/<int:sid>/aksi", methods=["POST"])
@login_wajib()
def supplier_aksi(sid):
    s = db.row("SELECT * FROM supplier WHERE id=?", (sid,))
    if not s:
        abort(404)
    aksi = request.form.get("aksi")
    if aksi == "status":
        db.run("UPDATE supplier SET aktif=1-aktif WHERE id=?", (sid,))
        flash("ok", f"{s['nama']} {'dinonaktifkan (tidak ikut perhitungan)' if s['aktif'] else 'diaktifkan kembali'}.")
    elif aksi == "hapus":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat menghapus supplier.")
        else:
            db.run("DELETE FROM supplier WHERE id=?", (sid,))
            flash("ok", f"Supplier {s['nama']} beserta nilainya dihapus.")
    return redirect(request.referrer or url_for("supplier"))


# ---------------- Kriteria ----------------
@app.route("/kriteria", methods=["GET", "POST"])
@login_wajib()
def kriteria():
    if request.method == "POST":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat mengubah kriteria dan bobot.")
            return redirect(url_for("kriteria"))
        aksi = request.form.get("aksi")
        if aksi == "bobot":
            baru, jenis = {}, {}
            try:
                for k in daftar_kriteria():
                    b = float(request.form.get(f"bobot_{k['id']}", "").replace(",", "."))
                    if b < 0:
                        raise ValueError
                    baru[k["id"]] = b
                    j = request.form.get(f"jenis_{k['id']}", k["jenis"])
                    if j not in ("benefit", "cost"):
                        raise ValueError
                    jenis[k["id"]] = j
            except ValueError:
                flash("err", "Bobot harus angka 0 atau lebih dan jenis harus benefit atau cost.")
                return redirect(url_for("kriteria"))
            if sum(baru.values()) <= 0:
                flash("err", "Total bobot harus lebih dari 0. Bobot belum disimpan.")
                return redirect(url_for("kriteria"))
            berubah = [k["kode"] for k in daftar_kriteria() if k["jenis"] != jenis[k["id"]]]
            for kid in baru:
                db.run("UPDATE kriteria SET bobot=?, jenis=? WHERE id=?", (baru[kid], jenis[kid], kid))
            flash("ok", "Bobot dan jenis kriteria disimpan (total bobot " + f_nilai(sum(baru.values())) + ", dinormalisasi otomatis)."
                  + (f" Jenis berubah: {', '.join(berubah)}." if berubah else "") + " Ranking supplier otomatis menyesuaikan.")
        elif aksi in ("tambah", "ubah"):
            nama = (request.form.get("nama") or "").strip()
            jenis = request.form.get("jenis")
            satuan = (request.form.get("satuan") or "").strip()
            ket = (request.form.get("keterangan") or "").strip()
            if len(nama) < 3 or jenis not in ("benefit", "cost"):
                flash("err", "Nama kriteria minimal 3 karakter dan jenis harus benefit/cost.")
            elif aksi == "tambah":
                n = (db.val("SELECT MAX(CAST(SUBSTR(kode,2) AS INTEGER)) FROM kriteria") or 0) + 1
                db.run("INSERT INTO kriteria (kode,nama,jenis,bobot,satuan,keterangan,urutan) VALUES (?,?,?,0,?,?,?)",
                       (f"C{n}", nama, jenis, satuan, ket, n))
                flash("info", f"Kriteria C{n} ditambahkan dengan bobot 0. Beri bobot di tabel, lalu isi nilainya di Penilaian.")
            else:
                db.run("UPDATE kriteria SET nama=?, jenis=?, satuan=?, keterangan=? WHERE id=?",
                       (nama, jenis, satuan, ket, request.form.get("id", type=int)))
                flash("ok", "Kriteria diperbarui.")
        elif aksi == "hapus":
            if db.val("SELECT COUNT(*) FROM kriteria") <= 2:
                flash("err", "Minimal harus ada 2 kriteria.")
            else:
                k = db.row("SELECT * FROM kriteria WHERE id=?", (request.form.get("id", type=int),))
                if k:
                    db.run("DELETE FROM kriteria WHERE id=?", (k["id"],))
                    flash("info", f"Kriteria {k['kode']} dihapus. Bobot kriteria lain otomatis dinormalisasi ulang.")
        return redirect(url_for("kriteria"))
    ubah = request.args.get("ubah", type=int)
    return render_template("kriteria.html", kriteria=daftar_kriteria(), total=total_bobot(),
                           ubah=db.row("SELECT * FROM kriteria WHERE id=?", (ubah,)) if ubah else None, active="kriteria")


# ---------------- Penilaian ----------------
@app.route("/penilaian", methods=["GET", "POST"])
@login_wajib()
def penilaian():
    kats, kat = pilih_kategori()
    kriteria_ = daftar_kriteria()
    if request.method == "POST" and kat:
        salah, n = [], 0
        sups = db.rows("SELECT id, nama, bukti FROM supplier WHERE kategori_id=? AND aktif=1", (kat["id"],))
        tanpa_bukti = []
        for s in sups:
            bukti = (request.form.get(f"bukti_{s['id']}") or "").strip()[:300]
            if bukti != (s["bukti"] or ""):
                db.run("UPDATE supplier SET bukti=?, bukti_diubah=? WHERE id=?", (bukti or None, db.sekarang(), s["id"]))
            if not bukti:
                tanpa_bukti.append(s["nama"])
            for k in kriteria_:
                raw = (request.form.get(f"n_{s['id']}_{k['id']}") or "").strip().replace(",", ".")
                if raw == "":
                    db.run("DELETE FROM nilai WHERE supplier_id=? AND kriteria_id=?", (s["id"], k["id"]))
                    continue
                try:
                    v = float(raw)
                    if v <= 0:
                        raise ValueError
                except ValueError:
                    salah.append(f"{s['nama']} – {k['kode']}")
                    continue
                db.run("""INSERT INTO nilai (supplier_id,kriteria_id,nilai,diubah,diubah_oleh) VALUES (?,?,?,?,?)
                          ON CONFLICT(supplier_id,kriteria_id) DO UPDATE SET nilai=excluded.nilai, diubah=excluded.diubah,
                          diubah_oleh=excluded.diubah_oleh""", (s["id"], k["id"], v, db.sekarang(), nama_user()))
                n += 1
        if salah:
            flash("err", "Nilai harus angka lebih dari 0 (SAW tidak dapat membagi dengan nol). Tidak disimpan: " + ", ".join(salah[:6]) + ("…" if len(salah) > 6 else ""))
        flash("ok", f"{n} nilai penilaian {kat['nama']} disimpan.")
        if tanpa_bukti:
            flash("info", "Sumber bukti belum dicatat untuk: " + ", ".join(tanpa_bukti) + ". Catat dokumen penawaran atau hasil uji sebagai dasar penilaian.")
        return redirect(url_for("penilaian", kategori=kat["id"]))
    sups, nilai = [], {}
    if kat:
        sups = db.rows("SELECT * FROM supplier WHERE kategori_id=? AND aktif=1 ORDER BY kode", (kat["id"],))
        nilai = {(r["supplier_id"], r["kriteria_id"]): r["nilai"] for r in db.rows(
            "SELECT n.* FROM nilai n JOIN supplier s ON s.id=n.supplier_id WHERE s.kategori_id=?", (kat["id"],))}
    mo = data_martoffice()
    dok = mo["oas"]["dokumen"] if mo else []
    return render_template("penilaian.html", kats=kats, kat=kat, kriteria=kriteria_, sups=sups, nilai=nilai, dok=dok, active="penilaian")


# ---------------- Perhitungan SAW ----------------
@app.route("/hitung", methods=["GET", "POST"])
@login_wajib()
def hitung():
    kats, kat = pilih_kategori()
    if not kat:
        return render_template("hitung.html", kats=kats, kat=None, h=None, active="hitung")
    _, _, _, h = data_saw(kat["id"])
    if request.method == "POST":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat menyimpan keputusan.")
        elif h["total_bobot"] <= 0:
            flash("err", "Total bobot kriteria masih 0. Atur bobot sebelum menyimpan keputusan.")
        elif not h["ranking"]:
            flash("err", "Belum ada supplier dengan penilaian lengkap.")
        else:
            juara = h["ranking"][0]
            judul = (request.form.get("judul") or "").strip() or f"Pemilihan supplier {kat['nama']}"
            snapshot_bobot = [{"kode": k["kode"], "nama": k["nama"], "jenis": k["jenis"], "bobot": h["bobot"][k["id"]]} for k in h["kriteria"]]
            snapshot_hasil = [{"rank": r["rank"], "kode": r["kode"], "nama": r["nama"], "v": round(r["v"], 6), "bukti": r.get("bukti"),
                               "x": {k["kode"]: h["X"][r["id"]][k["id"]] for k in h["kriteria"]},
                               "r": {k["kode"]: round(h["R"][r["id"]][k["id"]], 6) for k in h["kriteria"]}} for r in h["ranking"]]
            cur = db.run("""INSERT INTO keputusan (kategori_id,judul,catatan,bobot_json,hasil_json,pemenang_id,pemenang_nama,nilai_v,dibuat_oleh,dibuat_pada)
                            VALUES (?,?,?,?,?,?,?,?,?,?)""",
                         (kat["id"], judul[:150], (request.form.get("catatan") or "").strip()[:1000],
                          json.dumps(snapshot_bobot), json.dumps(snapshot_hasil), juara["id"], juara["nama"], juara["v"],
                          nama_user(), db.sekarang()))
            flash("ok", f"Keputusan disimpan: {juara['nama']} terpilih sebagai supplier terbaik {kat['nama']}.")
            return redirect(url_for("keputusan_detail", kid=cur.lastrowid))
        return redirect(url_for("hitung", kategori=kat["id"]))
    return render_template("hitung.html", kats=kats, kat=kat, h=h, active="hitung")


# ---------------- Sensitivitas ----------------
@app.route("/sensitivitas")
@login_wajib()
def sensitivitas():
    kats, kat = pilih_kategori()
    awal, tabel, data = None, [], None
    if kat:
        kriteria_, alt, nilai, h = data_saw(kat["id"])
        lengkap = [a for a in alt if a["id"] in h["X"]]
        if lengkap:
            awal, tabel = saw.sensitivitas(kriteria_, lengkap, nilai)
            data = {
                "kriteria": [{"id": k["id"], "kode": k["kode"], "nama": k["nama"], "jenis": k["jenis"], "bobot": k["bobot"]} for k in kriteria_],
                "alternatif": [{"id": a["id"], "kode": a["kode"], "nama": a["nama"],
                                "x": {str(k["id"]): h["X"][a["id"]][k["id"]] for k in kriteria_}} for a in lengkap],
            }
    return render_template("sensitivitas.html", kats=kats, kat=kat, awal=awal, tabel=tabel, data=data, active="sensitivitas")


# ---------------- Keputusan ----------------
@app.route("/keputusan")
@login_wajib()
def keputusan():
    kid = request.args.get("kategori", type=int)
    sql = "SELECT p.*, k.nama kategori, k.ikon FROM keputusan p JOIN kategori k ON k.id=p.kategori_id"
    rows = db.rows(sql + (" WHERE p.kategori_id=?" if kid else "") + " ORDER BY p.id DESC", (kid,) if kid else ())
    m = request.args.get("modul")
    m = m if m in MODUL else None
    lain = db.rows("SELECT * FROM keputusan_lain" + (" WHERE modul=?" if m else "") + " ORDER BY id DESC", (m,) if m else ())
    if m and m != "supplier":
        rows = []
    return render_template("keputusan.html", rows=rows, lain=lain, kats=daftar_kategori(), kid=kid, modul_aktif=m,
                           active="keputusan")


def _keputusan(kid):
    p = db.row("SELECT p.*, k.nama kategori, k.ikon FROM keputusan p JOIN kategori k ON k.id=p.kategori_id WHERE p.id=?", (kid,))
    if not p:
        abort(404)
    return p, json.loads(p["bobot_json"]), json.loads(p["hasil_json"])


@app.route("/keputusan/<int:kid>")
@login_wajib()
def keputusan_detail(kid):
    p, bobot, hasil = _keputusan(kid)
    p2 = db.row("SELECT p.*, k.nama kategori FROM keputusan p JOIN kategori k ON k.id=p.kategori_id WHERE p.id=?", (kid,))
    return render_template("keputusan_detail.html", p=p, bobot=bobot, hasil=hasil, active="keputusan",
                           usulan=usulan_tindak("supplier", p2, None), tl=tindak_lanjut_ada("supplier", kid), sumber="supplier")


@app.route("/keputusan/<int:kid>/csv")
@login_wajib()
def keputusan_csv(kid):
    p, bobot, hasil = _keputusan(kid)
    buf = io.StringIO()
    w = csv.writer(buf, delimiter=";")
    w.writerow(["Keputusan", p["judul"]]); w.writerow(["Kategori", p["kategori"]])
    w.writerow(["Dibuat", f"{p['dibuat_pada']} oleh {p['dibuat_oleh']}"]); w.writerow([])
    w.writerow(["Kriteria"] + [f"{b['kode']} {b['nama']} ({b['jenis']}, {b['bobot']}%)" for b in bobot])
    w.writerow([]); w.writerow(["Rank", "Kode", "Supplier"] + [b["kode"] for b in bobot] + ["Nilai V"])
    for r in hasil:
        w.writerow([r["rank"], r["kode"], r["nama"]] + [r["x"][b["kode"]] for b in bobot] + [str(round(r["v"], 4)).replace(".", ",")])
    return Response("﻿" + buf.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": f"attachment; filename=keputusan-dss-{kid}.csv"})


@app.route("/keputusan/<int:kid>/hapus", methods=["POST"])
@login_wajib("manajer")
def keputusan_hapus(kid):
    db.run("DELETE FROM keputusan WHERE id=?", (kid,))
    flash("ok", "Riwayat keputusan dihapus.")
    return redirect(url_for("keputusan"))


# =====================================================================
#  Kasus DSS lain: restok, promo, bundling, pegawai terbaik, kasir per shift
# =====================================================================
MODUL = {
    "supplier": ("🚚", "Pemilihan Supplier", "SAW"),
    "restok": ("📦", "Rekomendasi Restok", "Reorder point"),
    "promo": ("🏷️", "Produk Promo", "SAW + what-if"),
    "bundling": ("🧺", "Paket Bundling", "Analisis keranjang"),
    "pegawai": ("🏅", "Pegawai Terbaik", "SAW"),
    "shift": ("🕒", "Kasir per Shift", "Pola jam sibuk"),
}
PARAM_MODUL = {
    "restok": ("restok_siklus", "restok_aman", "restok_lead", "restok_segar"),
    "promo": ("promo_jumlah", "promo_elastis"),
    "bundling": ("bundling_diskon",),
    "shift": ("shift_kasir", "shift_batas"),
}


def pengaturan():
    return {r["kunci"]: r["nilai"] for r in db.rows("SELECT kunci, nilai FROM pengaturan")}


def kriteria_modul(nama):
    return [dict(r) for r in db.rows("SELECT * FROM bobot_modul WHERE modul=? ORDER BY urutan, id", (nama,))]


def juara_supplier_kategori():
    """Juara SAW tiap kategori beserta lama pengirimannya (dipakai rekomendasi restok)."""
    krit = daftar_kriteria()
    k_lead = next((k for k in krit if "pengiriman" in k["nama"].lower()), None)
    hasil = {}
    for k in daftar_kategori():
        _, _, nilai, h = data_saw(k["id"])
        if h["ranking"]:
            j = h["ranking"][0]
            hasil[k["nama"]] = {"supplier": j["nama"], "v": j["v"], "kategori_id": k["id"],
                                "lead": nilai.get((j["id"], k_lead["id"])) if k_lead else None}
    return hasil


def butuh_data():
    mo = data_martoffice()
    if not mo or "produk" not in mo:
        mo = data_martoffice(paksa=True, pemicu="data rinci kasus DSS")
    return mo if mo and "produk" in mo else None


def simpan_keputusan_lain(nama_modul, judul, ringkasan, kolom, baris, parameter=None):
    cur = db.run("""INSERT INTO keputusan_lain (modul,judul,catatan,ringkasan,data_json,dibuat_oleh,dibuat_pada)
                    VALUES (?,?,?,?,?,?,?)""",
                 (nama_modul, judul[:150], (request.form.get("catatan") or "").strip()[:1000], ringkasan,
                  json.dumps({"kolom": kolom, "baris": baris, "parameter": parameter or {}}, ensure_ascii=False),
                  nama_user(), db.sekarang()))
    flash("ok", f"Keputusan disimpan: {ringkasan}")
    return redirect(url_for("keputusan_lain_detail", kid=cur.lastrowid))


def tren_transaksi_mis(mo):
    """Perubahan jumlah transaksi 7 hari terakhir vs 7 hari sebelumnya dari mis_penjualan_harian (MIS)."""
    h = {x["tanggal"]: x["transaksi"] for x in (mo.get("mis", {}).get("harian") or [])}
    if not h:
        return None
    hari = [(datetime.now().date() - timedelta(days=i)).isoformat() for i in range(14)]  # hari tanpa transaksi dihitung 0
    a, b = sum(h.get(t, 0) for t in hari[:7]), sum(h.get(t, 0) for t in hari[7:])
    return round((a - b) / b * 100, 1) if b else None


def teks_oas(mo):
    """Judul + isi pengumuman dan agenda OAS 30 hari (untuk mendeteksi promo yang sudah berjalan)."""
    o = mo.get("oas", {})
    return [f"{x['judul']} {x.get('isi', '')}" for x in o.get("pengumuman", [])] + \
           [f"{x['judul']} {x.get('deskripsi', '')}" for x in o.get("agenda", [])]


def tugas_dss_aktif(mo):
    return [t["judul"] + " " + t.get("deskripsi", "") for t in mo.get("oas", {}).get("tugas_dss", []) if t["status"] != "selesai"]


def artikel_kms(mo, *kata):
    return [a for a in mo.get("kms", {}).get("artikel", []) if any(k in a["judul"].lower() for k in kata)]


def tindak_lanjut_ada(sumber, kid):
    return db.rows("SELECT * FROM tindak_lanjut WHERE sumber=? AND keputusan_id=? ORDER BY id", (sumber, kid))


def integrasi_kasus(nama, mo):
    """
    Peta data yang dipakai (masuk) dan dihasilkan (keluar) setiap kasus terhadap TPS, OAS, KMS, dan MIS.
    Dipakai kartu 'Terhubung dengan MartOffice' di tiap halaman dan matriks di Pusat Keputusan.
    """
    mo = mo or {}
    mis, oas = mo.get("mis", {}), mo.get("oas", {})
    n_tl = {r["modul"]: r["n"] for r in db.rows("""SELECT k.modul, COUNT(*) n FROM tindak_lanjut t JOIN keputusan_lain k
                                                   ON t.sumber='lain' AND k.id=t.keputusan_id GROUP BY k.modul""")}
    n_tl["supplier"] = db.val("SELECT COUNT(*) FROM tindak_lanjut WHERE sumber='supplier'")
    judul = lambda xs: ", ".join(a["judul"] for a in xs[:2]) or "–"
    peta = {
        "supplier": {
            "TPS": "kategori produk, omzet & stok menipis per kategori",
            "OAS": f"dokumen pemasok ({judul([d for d in oas.get('dokumen', []) if 'pemasok' in d['judul'].lower() or 'supplier' in d['judul'].lower()])}) sebagai bukti penilaian",
            "KMS": f"SOP penerimaan barang & retur ({judul(artikel_kms(mo, 'retur', 'penerimaan', 'fifo'))})",
            "MIS": f"{len(mis.get('peringatan_stok', []))} peringatan stok untuk prioritas kategori",
        },
        "restok": {
            "TPS": "stok & penjualan 30 hari per produk",
            "OAS": f"tugas [DSS] di OAS yang belum selesai ({len(tugas_dss_aktif(mo))} aktif) → produk yang sudah ditugaskan ditandai agar tidak dipesan dobel",
            "KMS": f"SOP kedaluwarsa & FIFO → siklus produk mudah rusak dibatasi ({judul(artikel_kms(mo, 'kedaluwarsa', 'fifo'))})",
            "MIS": f"{len(mis.get('produk_peringatan', []))} produk di Pusat Peringatan MIS ditandai ⚠ · keputusan waktu order Expert System ikut ditampilkan",
        },
        "promo": {
            "TPS": "stok, penjualan 30 hari & 30 hari sebelumnya",
            "OAS": "pengumuman/agenda promo 30 hari → kategori yang sudah dipromosikan ditandai",
            "KMS": f"risiko kedaluwarsa per kategori ({judul(artikel_kms(mo, 'kedaluwarsa', 'label harga'))})",
            "MIS": f"omzet toko 30 hari {f_rp(mis.get('omzet_30'))} sebagai dasar kontribusi omzet (P4)",
        },
        "bundling": {
            "TPS": f"{mo.get('keranjang', {}).get('transaksi', 0)} struk belanja (pasangan produk)",
            "OAS": "pengumuman & tugas display paket dikirim setelah keputusan",
            "KMS": f"ide bundling ({judul(artikel_kms(mo, 'bundling', 'display'))})",
            "MIS": f"rata-rata belanja {f_rp(mis.get('rata_belanja_30'))} → paket dibandingkan dengan nilai keranjang",
        },
        "pegawai": {
            "TPS": "transaksi & omzet per kasir 30 hari",
            "OAS": "tugas selesai, tepat waktu, terlambat (60 hari)",
            "KMS": "artikel yang ditulis & dinilai membantu",
            "MIS": f"porsi omzet tiap pegawai terhadap omzet toko {f_rp(mis.get('omzet_30'))}",
        },
        "shift": {
            "TPS": "jam setiap transaksi 8 minggu",
            "OAS": f"dokumen jadwal shift & agenda ({judul([d for d in oas.get('dokumen', []) if 'shift' in d['judul'].lower()])})",
            "KMS": f"aturan shift & kas kasir ({judul(artikel_kms(mo, 'shift', 'kas kasir'))})",
            "MIS": "tren transaksi 7 hari (mis_penjualan_harian) → saran kasir cadangan",
        },
    }
    keluar = {
        "supplier": "pengumuman supplier terpilih + tugas penerimaan barang (OAS) · panel MIS",
        "restok": "tugas pemesanan untuk staf gudang (OAS) · panel MIS",
        "promo": "pengumuman promo + tugas pasang label (OAS) · panel MIS",
        "bundling": "pengumuman paket + tugas display (OAS) · panel MIS",
        "pegawai": "pengumuman pegawai terbaik (OAS) · panel MIS",
        "shift": "pengumuman jadwal + agenda briefing kasir (OAS) · panel MIS",
    }
    return {"masuk": peta[nama], "keluar": keluar[nama], "terkirim": n_tl.get(nama, 0)}


def usulan_tindak(modul_, p, d):
    """Tindak lanjut yang dapat dikirim ke OAS untuk sebuah keputusan. p = baris keputusan, d = data_json."""
    besok = (datetime.now() + timedelta(days=1)).strftime("%Y-%m-%d")
    lusa = (datetime.now() + timedelta(days=2)).strftime("%Y-%m-%d")
    baris = d.get("baris", []) if d else []
    if modul_ == "supplier":
        return [
            {"kode": "umumkan", "jenis": "pengumuman", "label": "📢 Umumkan supplier terpilih", "judul": f"Supplier {p['kategori']}: {p['pemenang_nama']}",
             "isi": f"Berdasarkan DSS (SAW), {p['pemenang_nama']} dipilih sebagai supplier {p['kategori']}. {p['catatan'] or ''}".strip()},
            {"kode": "terima", "jenis": "tugas", "label": "📋 Tugas: siapkan penerimaan barang", "jabatan": "gudang", "tenggat": lusa, "prioritas": "sedang",
             "judul": f"Siapkan penerimaan barang dari {p['pemenang_nama']}", "isi": f"Supplier baru kategori {p['kategori']}. Ikuti SOP penerimaan barang di KMS."},
        ]
    if modul_ == "restok":
        daftar = "\n".join(f"- {b[1]}: {b[5]} {b[6]} (supplier {b[7]})" for b in baris)
        return [{"kode": "pesan", "jenis": "tugas", "label": "📋 Tugas: pesan barang ke supplier", "jabatan": "gudang", "tenggat": besok, "prioritas": "tinggi",
                 "judul": f"Restok {len(baris)} produk: " + ", ".join(b[1] for b in baris[:3]) + ("…" if len(baris) > 3 else ""),
                 "isi": "Rencana restok dari DSS:\n" + daftar + "\nTerapkan FIFO saat barang diterima (KMS)."}]
    if modul_ == "promo":
        nama = ", ".join(b[2] for b in baris)
        diskon = (d.get("parameter") or {}).get("diskon", 15)
        return [
            {"kode": "umumkan", "jenis": "pengumuman", "label": "📢 Umumkan promo ke pegawai", "penting": True,
             "judul": f"Promo diskon {diskon:g}%: " + ", ".join(b[2] for b in baris[:2]) + ("…" if len(baris) > 2 else ""),
             "isi": f"Produk promo hasil DSS: {nama}. Diskon {diskon:g}%. Tawarkan ke pelanggan saat di kasir."},
            {"kode": "label", "jenis": "tugas", "label": "📋 Tugas: pasang label harga promo", "jabatan": "toko", "tenggat": besok, "prioritas": "tinggi",
             "judul": f"Pasang label promo {diskon:g}% ({len(baris)} produk)", "isi": f"Produk: {nama}. Ikuti panduan 'Cara Memasang Label Harga Promo' di KMS."},
        ]
    if modul_ == "bundling":
        paket = "; ".join(f"{b[0]} = Rp{b[4]:,}".replace(",", ".") for b in baris)
        return [
            {"kode": "umumkan", "jenis": "pengumuman", "label": "📢 Umumkan paket bundling", "judul": f"Paket hemat baru ({len(baris)} paket)",
             "isi": f"Paket bundling hasil DSS: {paket}."},
            {"kode": "display", "jenis": "tugas", "label": "📋 Tugas: siapkan display paket", "jabatan": "toko", "tenggat": lusa, "prioritas": "sedang",
             "judul": f"Siapkan display {len(baris)} paket bundling", "isi": f"Susun paket: {paket}. Letakkan dekat kasir."},
        ]
    if modul_ == "pegawai":
        juara = baris[0][1] if baris else "-"
        return [{"kode": "umumkan", "jenis": "pengumuman", "label": "📢 Umumkan pegawai terbaik", "penting": True,
                 "judul": f"Pegawai terbaik: {juara}", "isi": f"Selamat kepada {juara} sebagai pegawai terbaik periode ini berdasarkan data TPS, OAS, dan KMS. {p['catatan'] or ''}".strip()}]
    if modul_ == "shift":
        jadwal = "\n".join(f"- {b[0]} · {b[1]}: {b[2]} kasir ({b[3]})" for b in baris)
        return [
            {"kode": "umumkan", "jenis": "pengumuman", "label": "📢 Umumkan jadwal kasir", "judul": "Jadwal kasir baru", "isi": "Jadwal kasir hasil DSS:\n" + jadwal},
            {"kode": "briefing", "jenis": "agenda", "label": "🗓️ Agenda: briefing kasir", "mulai": besok + " 07:30", "untuk": "staff",
             "judul": "Briefing jadwal kasir baru", "isi": "Pembahasan jadwal shift kasir dari DSS. Baca juga 'Aturan Tukar Shift dan Izin' di KMS."},
        ]
    return []


@app.route("/tindak-lanjut/<sumber>/<int:kid>/<kode>", methods=["POST"])
@login_wajib("manajer")
def kirim_tindak_lanjut(sumber, kid, kode):
    if sumber == "supplier":
        p = db.row("SELECT p.*, k.nama kategori FROM keputusan p JOIN kategori k ON k.id=p.kategori_id WHERE p.id=?", (kid,))
        modul_, d, balik = "supplier", None, url_for("keputusan_detail", kid=kid)
    elif sumber == "lain":
        p = db.row("SELECT * FROM keputusan_lain WHERE id=?", (kid,))
        modul_, d, balik = (p["modul"] if p else None), (json.loads(p["data_json"]) if p else None), url_for("keputusan_lain_detail", kid=kid)
    else:
        abort(404)
    if not p:
        abort(404)
    aksi = next((a for a in usulan_tindak(modul_, p, d) if a["kode"] == kode), None)
    if not aksi:
        abort(404)
    if db.row("SELECT id FROM tindak_lanjut WHERE sumber=? AND keputusan_id=? AND jenis=? AND judul=?", (sumber, kid, aksi["jenis"], aksi["judul"])):
        flash("info", "Tindak lanjut ini sudah pernah dikirim ke OAS.")
        return redirect(balik)
    isi = {k: v for k, v in aksi.items() if k not in ("kode", "label")}
    isi["oleh"] = (session.get("user") or {}).get("username")
    hasil, err = sso.kirim_martoffice(isi, (session.get("user") or {}).get("home"))
    if not hasil:
        flash("err", f"Gagal mengirim ke MartOffice: {err}. Pastikan Apache XAMPP menyala.")
        return redirect(balik)
    ket = f"untuk {hasil['staff']}" if hasil.get("staff") else ""
    db.run("""INSERT INTO tindak_lanjut (sumber,keputusan_id,jenis,ref_id,judul,keterangan,dibuat_oleh,dibuat_pada)
              VALUES (?,?,?,?,?,?,?,?)""", (sumber, kid, aksi["jenis"], hasil.get("id"), aksi["judul"], ket, nama_user(), db.sekarang()))
    data_martoffice(paksa=True, pemicu="tindak lanjut ke OAS")
    flash("ok", f"Terkirim ke OAS sebagai {aksi['jenis']} {ket}. Staf melihatnya di MartOffice dan MIS memantau penyelesaiannya.")
    return redirect(balik)


@app.route("/bobot/<nama>", methods=["POST"])
@login_wajib("manajer")
def bobot_modul(nama):
    krit = kriteria_modul(nama)
    if not krit:
        abort(404)
    baru = {}
    try:
        for k in krit:
            b = float((request.form.get(f"bobot_{k['id']}") or "").replace(",", "."))
            j = request.form.get(f"jenis_{k['id']}", k["jenis"])
            if b < 0 or j not in ("benefit", "cost"):
                raise ValueError
            baru[k["id"]] = (b, j)
    except ValueError:
        flash("err", "Bobot harus angka 0 atau lebih dan jenis harus benefit atau cost.")
        return redirect(request.referrer or url_for("dashboard"))
    if sum(b for b, _ in baru.values()) <= 0:
        flash("err", "Total bobot harus lebih dari 0.")
        return redirect(request.referrer or url_for("dashboard"))
    for kid, (b, j) in baru.items():
        db.run("UPDATE bobot_modul SET bobot=?, jenis=? WHERE id=?", (b, j, kid))
    flash("ok", "Bobot dan jenis kriteria disimpan. Ranking otomatis menyesuaikan.")
    return redirect(request.referrer or url_for("dashboard"))


@app.route("/pengaturan/<nama>", methods=["POST"])
@login_wajib("manajer")
def simpan_pengaturan(nama):
    if nama not in PARAM_MODUL:
        abort(404)
    for kunci in PARAM_MODUL[nama]:
        raw = (request.form.get(kunci) or "").replace(",", ".").strip()
        try:
            v = float(raw)
            if v < 0 or (kunci == "shift_batas" and not 8 <= v <= 20) or (kunci == "shift_kasir" and not 2 <= v <= 20):
                raise ValueError
        except ValueError:
            flash("err", f"Nilai '{raw}' tidak valid untuk {kunci}.")
            return redirect(request.referrer or url_for("dashboard"))
        db.run("UPDATE pengaturan SET nilai=? WHERE kunci=?", (v, kunci))
    flash("ok", "Parameter disimpan. Hasil dihitung ulang.")
    return redirect(request.referrer or url_for("dashboard"))


def _data_restok(mo):
    st = pengaturan()
    mis_ids = {x["id"]: x["level"] for x in mo.get("mis", {}).get("produk_peringatan", [])}
    rows = modul.hitung_restok(mo["produk"], juara_supplier_kategori(), st["restok_siklus"], st["restok_aman"], st["restok_lead"],
                               st.get("restok_segar", 3), mis_ids, tugas_dss_aktif(mo))
    # hasil Expert System MartOffice: keputusan waktu order & produk yang dicari pembeli tetapi habis
    es = mo.get("es") or {}
    order = {x["id_produk"]: x for x in es.get("order", [])}
    cari = {x["id_produk"]: x["n"] for x in es.get("permintaan", [])}
    for r in rows:
        r["es_order"] = order.get(r["id"])
        r["es_cari"] = cari.get(r["id"], 0)
    return rows, st


@app.route("/restok", methods=["GET", "POST"])
@login_wajib()
def restok():
    mo = butuh_data()
    if not mo:
        return render_template("modul_kosong.html", judul_modul="Rekomendasi Restok", active="restok")
    rows, st = _data_restok(mo)
    if request.method == "POST":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat menyimpan keputusan.")
            return redirect(url_for("restok"))
        pesan = [r for r in rows if r["saran"] > 0]
        if not pesan:
            flash("info", "Tidak ada produk yang perlu dipesan saat ini.")
            return redirect(url_for("restok"))
        baris = [[r["sku"], r["nama"], r["kategori"], r["stok"], r["status"], r["saran"], r["satuan"], r["supplier"] or "–"] for r in pesan]
        return simpan_keputusan_lain("restok", request.form.get("judul") or f"Rencana restok {hari_ini_teks()}",
                                     f"{len(pesan)} produk dipesan, total {sum(r['saran'] for r in pesan)} unit",
                                     ["SKU", "Produk", "Kategori", "Stok", "Status", "Pesan", "Satuan", "Supplier"], baris,
                                     {k: st[k] for k in PARAM_MODUL["restok"]})
    status = request.args.get("status")
    kat = request.args.get("kategori")
    tampil = [r for r in rows if (not status or r["tone"] == status) and (not kat or r["kategori"] == kat)]
    draf = {}
    for r in rows:
        if r["saran"] > 0:
            draf.setdefault(r["kategori"], {"supplier": r["supplier"], "lead": r["lead"], "item": []})["item"].append(r)
    hitung_status = {t: sum(1 for r in rows if r["tone"] == t) for t in ("r", "o", "y", "g", "n")}
    return render_template("restok.html", rows=tampil, semua=rows, draf=draf, st=st, hs=hitung_status, status=status, kat=kat,
                           kategori=sorted({r["kategori"] for r in rows}), mo=mo, integ=integrasi_kasus("restok", mo),
                           tugas=mo.get("oas", {}).get("tugas_dss", []), artikel=artikel_kms(mo, "kedaluwarsa", "fifo", "stok"), active="restok")


def _data_promo(mo):
    krit = kriteria_modul("promo")
    h, info = modul.hitung_promo(mo["produk"], krit, mo.get("mis", {}).get("omzet_30"))
    teks = [t for t in teks_oas(mo) if "promo" in t.lower() or "diskon" in t.lower()]
    aktif = modul.kategori_disebut(teks, {p["kategori"] for p in mo["produk"]})
    gabung = " ".join(teks).lower()
    for v in info.values():  # sudah dipromosikan bila produk atau kategorinya disebut di pengumuman/agenda promo OAS
        v["promo_oas"] = v["kategori"] in aktif or v["nama"].lower() in gabung
    return krit, h, info


@app.route("/promo", methods=["GET", "POST"])
@login_wajib()
def promo():
    mo = butuh_data()
    if not mo:
        return render_template("modul_kosong.html", judul_modul="Produk Promo", active="promo")
    krit, h, info = _data_promo(mo)
    st = pengaturan()
    n = int(st["promo_jumlah"])
    if request.method == "POST":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat menyimpan keputusan.")
            return redirect(url_for("promo"))
        try:
            diskon = float(request.form.get("diskon", "15"))
        except ValueError:
            diskon = 15
        top = h["ranking"][:n]
        baris = [[r["rank"], r["kode"], r["nama"], info[r["id"]]["kategori"], f"{r['v'] * 100:.2f}",
                  f"{diskon:g}%", info[r["id"]]["stok"]] for r in top]
        return simpan_keputusan_lain("promo", request.form.get("judul") or f"Produk promo {hari_ini_teks()}",
                                     f"{len(top)} produk promo, diskon {diskon:g}% · teratas {top[0]['nama']}" if top else "Tidak ada produk",
                                     ["Rank", "SKU", "Produk", "Kategori", "Skor", "Diskon", "Stok"], baris,
                                     {"diskon": diskon, "bobot": {k["kode"]: k["bobot"] for k in krit}})
    sim = [{"id": r["id"], "nama": r["nama"], "harga": info[r["id"]]["harga"], "stok": info[r["id"]]["stok"],
            "terjual_30": info[r["id"]]["terjual_30"], "satuan": info[r["id"]]["satuan"]} for r in h["ranking"]]
    artikel = [a for a in mo["kms"]["artikel"] if any(w in a["judul"].lower() for w in ("kedaluwarsa", "bundling", "promo", "fifo"))]
    promo_oas = [x for x in mo.get("oas", {}).get("pengumuman", []) + mo.get("oas", {}).get("agenda", []) if "promo" in x["judul"].lower()]
    return render_template("promo.html", krit=krit, h=h, info=info, n=n, st=st, sim=sim, artikel=artikel, mo=mo, promo_oas=promo_oas,
                           integ=integrasi_kasus("promo", mo), risiko=modul.RISIKO_KEDALUWARSA, active="promo", nama_modul="promo")


@app.route("/bundling", methods=["GET", "POST"])
@login_wajib()
def bundling():
    mo = butuh_data()
    if not mo:
        return render_template("modul_kosong.html", judul_modul="Paket Bundling", active="bundling")
    st = pengaturan()
    _, hp, _ = _data_promo(mo)
    lambat = {r["id"] for r in hp["ranking"][:int(st["promo_jumlah"])]}
    rows = modul.hitung_bundling(mo["produk"], mo["keranjang"], st["bundling_diskon"], lambat)
    if request.method == "POST":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat menyimpan keputusan.")
            return redirect(url_for("bundling"))
        pilih = [int(i) for i in request.form.getlist("pilih") if i.isdigit()]
        dipilih = [rows[i] for i in pilih if 0 <= i < len(rows)]
        if not dipilih:
            flash("err", "Centang minimal satu paket bundling.")
            return redirect(url_for("bundling"))
        baris = [[f"{r['a']['nama']} + {r['b']['nama']}", r["bersama"], f"{r['lift']:.2f}", r["harga_normal"], r["harga_paket"]] for r in dipilih]
        return simpan_keputusan_lain("bundling", request.form.get("judul") or f"Paket bundling {hari_ini_teks()}",
                                     f"{len(dipilih)} paket bundling · {dipilih[0]['a']['nama']} + {dipilih[0]['b']['nama']}",
                                     ["Paket", "Dibeli bersama", "Lift", "Harga normal", "Harga paket"], baris,
                                     {"diskon": st["bundling_diskon"]})
    artikel = [a for a in mo["kms"]["artikel"] if "bundling" in a["judul"].lower()]
    return render_template("bundling.html", rows=rows, st=st, n_trx=mo["keranjang"]["transaksi"], artikel=artikel, mo=mo,
                           rata=mo.get("mis", {}).get("rata_belanja_30") or 0, integ=integrasi_kasus("bundling", mo), active="bundling")


def _data_pegawai(mo):
    krit = kriteria_modul("pegawai")
    h, info = modul.hitung_pegawai(mo["pegawai"], krit)
    return krit, h, info


@app.route("/pegawai", methods=["GET", "POST"])
@login_wajib()
def pegawai():
    mo = butuh_data()
    if not mo:
        return render_template("modul_kosong.html", judul_modul="Pegawai Terbaik", active="pegawai")
    krit, h, info = _data_pegawai(mo)
    if request.method == "POST":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat menyimpan keputusan.")
            return redirect(url_for("pegawai"))
        if not h["ranking"]:
            flash("err", "Belum ada data pegawai.")
            return redirect(url_for("pegawai"))
        baris = [[r["rank"], r["nama"], info[r["id"]]["jabatan"], f"{r['v'] * 100:.2f}"] for r in h["ranking"]]
        return simpan_keputusan_lain("pegawai", request.form.get("judul") or f"Pegawai terbaik {hari_ini_teks()}",
                                     f"Pegawai terbaik: {h['ranking'][0]['nama']} (skor {h['ranking'][0]['v'] * 100:.2f})",
                                     ["Rank", "Pegawai", "Jabatan", "Skor"], baris, {"bobot": {k["kode"]: k["bobot"] for k in krit}})
    omzet_toko = mo.get("mis", {}).get("omzet_30") or 0
    for g in info.values():
        g["porsi"] = g["omzet"] / omzet_toko * 100 if omzet_toko else None
    return render_template("pegawai.html", krit=krit, h=h, info=info, mo=mo, omzet_toko=omzet_toko,
                           integ=integrasi_kasus("pegawai", mo), active="pegawai", nama_modul="pegawai")


def _data_shift(mo):
    st = pengaturan()
    _, hp, _ = _data_pegawai(mo)
    peta = {g["id"]: g for g in mo["pegawai"]}
    kasir = [peta[r["id"]] for r in hp["ranking"] if peta[r["id"]]["transaksi"] > 0]
    return modul.hitung_shift(mo["jam"], kasir, int(st["shift_kasir"]), int(st["shift_batas"]), tren=tren_transaksi_mis(mo)), st, kasir


@app.route("/shift", methods=["GET", "POST"])
@login_wajib()
def shift():
    mo = butuh_data()
    if not mo:
        return render_template("modul_kosong.html", judul_modul="Kasir per Shift", active="shift")
    hs, st, kasir = _data_shift(mo)
    if request.method == "POST":
        if session["user"]["role"] != "manajer":
            flash("err", "Hanya manajer yang dapat menyimpan keputusan.")
            return redirect(url_for("shift"))
        b = hs["batas"]
        baris = []
        for r in (hs["kerja"], hs["libur"]):
            baris.append([r["label"], f"Pagi (buka–{b:02d}.00)", r["kasir_pagi"], ", ".join(r["nama_pagi"])])
            baris.append([r["label"], f"Sore ({b:02d}.00–tutup)", r["kasir_sore"], ", ".join(r["nama_sore"])])
        return simpan_keputusan_lain("shift", request.form.get("judul") or f"Jadwal kasir {hari_ini_teks()}",
                                     f"{hs['kasir']} kasir/hari · hari kerja {hs['kerja']['kasir_pagi']} pagi + {hs['kerja']['kasir_sore']} sore",
                                     ["Hari", "Shift", "Jumlah kasir", "Usulan nama"], baris, {"kasir": hs["kasir"], "batas": b})
    dok = [d for d in mo["oas"]["dokumen"] if "shift" in d["judul"].lower()]
    return render_template("shift.html", hs=hs, st=st, kasir=kasir, dok=dok, mo=mo, artikel=artikel_kms(mo, "shift", "kas kasir", "kasir"),
                           agenda=mo.get("oas", {}).get("agenda", []), integ=integrasi_kasus("shift", mo), active="shift")


def hari_ini_teks():
    d = datetime.now()
    return f"{d.day} {BULAN[d.month]} {d.year}"


@app.route("/keputusan/lain/<int:kid>")
@login_wajib()
def keputusan_lain_detail(kid):
    p = db.row("SELECT * FROM keputusan_lain WHERE id=?", (kid,))
    if not p:
        abort(404)
    d = json.loads(p["data_json"])
    return render_template("keputusan_lain.html", p=p, d=d, info=MODUL.get(p["modul"]), active="keputusan",
                           usulan=usulan_tindak(p["modul"], p, d), tl=tindak_lanjut_ada("lain", kid), sumber="lain")


@app.route("/keputusan/lain/<int:kid>/hapus", methods=["POST"])
@login_wajib("manajer")
def keputusan_lain_hapus(kid):
    db.run("DELETE FROM keputusan_lain WHERE id=?", (kid,))
    flash("ok", "Riwayat keputusan dihapus.")
    return redirect(url_for("keputusan"))


# ---------------- Alternatif kasus & hasil perhitungan ----------------
ALTERNATIF = {
    "supplier": {"masalah": "Memilih supplier terbaik untuk setiap kategori produk dari beberapa calon supplier.",
                 "pengambil": "Manajer toko", "jenis": "Semi-terstruktur · pemilihan alternatif",
                 "rumus": ["r = x / max (benefit) · r = min / x (cost)", "V = Σ wⱼ · rᵢⱼ · skor = 100 × V"],
                 "kriteria": "C1 Harga (cost), C2 Kualitas (benefit), C3 Ketepatan kirim (benefit), C4 Lama pengiriman (cost), C5 Retur (cost)"},
    "restok": {"masalah": "Menentukan produk yang harus dipesan sekarang dan jumlah pesanannya agar tidak kehabisan stok.",
               "pengambil": "Manajer toko / staf gudang", "jenis": "Terstruktur · perencanaan persediaan",
               "rumus": ["d = terjual 30 hari ÷ 30", "ROP = d × (L + SS)", "Q = d × (L + SS + siklus) − stok"],
               "kriteria": "L dari supplier juara kasus 1, SS = stok pengaman, siklus produk segar dibatasi SOP KMS"},
    "promo": {"masalah": "Memilih produk yang paling perlu didiskon dan memperkirakan dampak diskon terhadap penjualan dan omzet.",
              "pengambil": "Manajer toko", "jenis": "Semi-terstruktur · pemilihan + simulasi what-if",
              "rumus": ["SAW atas P1–P4 (V = Σ w · r)", "jual promo = d × (1 + e × diskon)", "omzet = min(stok, jual × hari) × harga promo"],
              "kriteria": "P1 Hari persediaan (benefit), P2 Penurunan penjualan (benefit), P3 Risiko kedaluwarsa (benefit), P4 Kontribusi omzet (cost)"},
    "bundling": {"masalah": "Menemukan pasangan produk yang sering dibeli bersama untuk dijual sebagai paket hemat.",
                 "pengambil": "Manajer toko", "jenis": "Semi-terstruktur · analisis keranjang belanja",
                 "rumus": ["support = n(A∩B) ÷ N", "confidence A→B = n(A∩B) ÷ n(A)", "lift = support(A∩B) ÷ (support A × support B)"],
                 "kriteria": "Pasangan dengan lift > 1 (saling mendorong pembelian), diurutkan dari lift tertinggi"},
    "pegawai": {"masalah": "Menentukan pegawai dengan kinerja terbaik secara objektif dari data kerja, bukan penilaian subjektif.",
                "pengambil": "Manajer toko", "jenis": "Semi-terstruktur · penilaian kinerja",
                "rumus": ["r = x / max (benefit) · r = min / x (cost)", "V = Σ wⱼ · rᵢⱼ · skor = 100 × V"],
                "kriteria": "E1 Transaksi, E2 Omzet, E3 Ketepatan waktu tugas, E4 Tugas terlambat (cost), E5 Knowledge, E6 Dinilai membantu"},
    "shift": {"masalah": "Menentukan jumlah kasir di shift pagi dan sore sesuai pola keramaian toko.",
              "pengambil": "Manajer toko", "jenis": "Terstruktur · penjadwalan",
              "rumus": ["porsi shift = transaksi shift ÷ transaksi sehari", "kasir shift = 1 + (kasir − 2) × porsi (sisa terbesar)"],
              "kriteria": "Rata-rata transaksi per jam 8 minggu, pergantian shift pukul 14.00, tren transaksi MIS"},
}


def hasil_semua(mo):
    """Hasil perhitungan terkini keenam kasus (dipakai halaman Alternatif & Hasil)."""
    h = {}
    juara = []
    for k in daftar_kategori():
        _, _, _, hk = data_saw(k["id"])
        if hk["ranking"]:
            r = hk["ranking"]
            juara.append({"kategori": k["nama"], "ikon": k["ikon"], "supplier": r[0]["nama"], "v": r[0]["v"],
                          "kedua": r[1]["nama"] if len(r) > 1 else "–", "v2": r[1]["v"] if len(r) > 1 else None, "n": len(r)})
    h["supplier"] = juara
    if not mo:
        return h
    rs, st = _data_restok(mo)
    h["restok"] = [r for r in rs if r["saran"] > 0]
    kp, hp, ip = _data_promo(mo)
    h["promo"] = {"krit": kp, "rank": hp["ranking"][:int(st["promo_jumlah"])], "X": hp["X"], "info": ip}
    h["bundling"] = modul.hitung_bundling(mo["produk"], mo["keranjang"], st["bundling_diskon"])[:5]
    kg, hg, ig = _data_pegawai(mo)
    h["pegawai"] = {"krit": kg, "rank": hg["ranking"], "X": hg["X"], "info": ig}
    h["shift"], _, _ = _data_shift(mo)
    h["st"] = st
    return h


@app.route("/alternatif")
@login_wajib()
def alternatif():
    mo = butuh_data()
    alt = {k: dict(v) for k, v in ALTERNATIF.items()}
    for nama, krit in (("supplier", daftar_kriteria()), ("promo", kriteria_modul("promo")), ("pegawai", kriteria_modul("pegawai"))):
        alt[nama]["kriteria"] = ", ".join(f"{k['kode']} {k['nama']} ({k['jenis']}, bobot {f_nilai(k['bobot'])})" for k in krit)
    return render_template("alternatif.html", mo=mo, h=hasil_semua(mo), alt=alt,
                           integ={k: integrasi_kasus(k, mo) for k in MODUL}, active="alternatif")


@app.route("/")
@login_wajib()
def dashboard():
    """Pusat Keputusan: ringkasan keenam kasus DSS dalam satu layar."""
    mo = butuh_data()
    kartu = {}
    juara = juara_supplier_kategori()
    kartu["supplier"] = {"utama": f"{len(juara)} kategori", "isi": ", ".join(f"{v['supplier']}" for v in list(juara.values())[:2]) or "Belum ada penilaian lengkap",
                         "href": url_for("hitung")}
    if mo:
        rs, _ = _data_restok(mo)
        perlu = [r for r in rs if r["saran"] > 0]
        kartu["restok"] = {"utama": f"{len(perlu)} produk", "isi": "perlu dipesan · " + ", ".join(r["nama"] for r in perlu[:2]) if perlu else "Semua stok aman",
                           "href": url_for("restok"), "alert": any(r["tone"] == "r" for r in rs)}
        _, hp, _ = _data_promo(mo)
        kartu["promo"] = {"utama": hp["ranking"][0]["nama"] if hp["ranking"] else "–", "isi": f"kandidat promo teratas · skor {hp['ranking'][0]['v'] * 100:.1f}" if hp["ranking"] else "",
                          "href": url_for("promo")}
        st = pengaturan()
        bd = modul.hitung_bundling(mo["produk"], mo["keranjang"], st["bundling_diskon"])
        kartu["bundling"] = {"utama": f"{bd[0]['a']['nama']} + {bd[0]['b']['nama']}" if bd else "–",
                             "isi": f"lift {bd[0]['lift']:.2f} · {bd[0]['bersama']}× dibeli bersama" if bd else "Belum ada pasangan produk", "href": url_for("bundling")}
        _, hg, _ = _data_pegawai(mo)
        kartu["pegawai"] = {"utama": hg["ranking"][0]["nama"] if hg["ranking"] else "–", "isi": f"skor {hg['ranking'][0]['v'] * 100:.1f} dari TPS, OAS, KMS" if hg["ranking"] else "",
                            "href": url_for("pegawai")}
        hs, _, _ = _data_shift(mo)
        kartu["shift"] = {"utama": f"{hs['kerja']['kasir_pagi']} pagi · {hs['kerja']['kasir_sore']} sore",
                          "isi": f"jam puncak {hs['kerja']['puncak']:02d}.00 (hari kerja), {hs['libur']['puncak']:02d}.00 (akhir pekan)" if hs['kerja']['puncak'] is not None else "",
                          "href": url_for("shift")}
    lain = db.rows("SELECT * FROM keputusan_lain ORDER BY id DESC LIMIT 6")
    sup = db.rows("""SELECT p.id, p.judul, p.pemenang_nama, p.dibuat_pada, p.dibuat_oleh, k.nama kategori FROM keputusan p
                     JOIN kategori k ON k.id=p.kategori_id ORDER BY p.id DESC LIMIT 6""")
    terbaru = sorted([{"modul": "supplier", "judul": r["judul"], "ringkasan": f"🥇 {r['pemenang_nama']} ({r['kategori']})", "waktu": r["dibuat_pada"],
                       "oleh": r["dibuat_oleh"], "href": url_for("keputusan_detail", kid=r["id"])} for r in sup] +
                     [{"modul": r["modul"], "judul": r["judul"], "ringkasan": r["ringkasan"], "waktu": r["dibuat_pada"], "oleh": r["dibuat_oleh"],
                       "href": url_for("keputusan_lain_detail", kid=r["id"])} for r in lain], key=lambda x: x["waktu"], reverse=True)[:6]
    integ = {k: integrasi_kasus(k, mo) for k in MODUL}
    n_tl = db.val("SELECT COUNT(*) FROM tindak_lanjut")
    return render_template("pusat.html", kartu=kartu, mo=mo, terbaru=terbaru, integ=integ, n_tl=n_tl, active="dashboard")


# ---------------- Integrasi data (back end) ----------------
@app.route("/integrasi", methods=["GET", "POST"])
@login_wajib()
def integrasi():
    if request.method == "POST":
        mo = data_martoffice(paksa=True, pemicu="tombol sinkron manual")
        flash("ok" if mo and not mo["_offline"] else "err",
              "Data TPS, MIS, OAS, dan KMS berhasil diambil ulang dari MartOffice." if mo and not mo["_offline"]
              else "MartOffice tidak dapat dihubungi; DSS memakai snapshot terakhir.")
        return redirect(url_for("integrasi"))
    mo = data_martoffice()
    log = db.rows("SELECT id,waktu,status,pemicu,durasi_ms,ringkasan FROM sinkron ORDER BY id DESC LIMIT 15")
    tabel = [(t, db.val(f"SELECT COUNT(*) FROM {t}")) for t in
             ("pengguna", "kategori", "supplier", "kriteria", "nilai", "keputusan", "sso_log", "sinkron")]
    return render_template("integrasi.html", mo=mo, log=log, tabel=tabel, active="integrasi",
                           db_path=os.path.basename(config.DB_PATH), cache=CACHE_DETIK)


@app.route("/api/status")
def api_status():
    """Status layanan DSS (tanpa data sensitif) — dipakai untuk memeriksa back end berjalan."""
    akhir = db.row("SELECT waktu, status FROM sinkron ORDER BY id DESC LIMIT 1")
    return jsonify(
        ok=True, layanan="DSS MartOffice", metode="Simple Additive Weighting (SAW)",
        framework="Flask", database="SQLite (" + os.path.basename(config.DB_PATH) + ")", port=config.PORT,
        waktu_server=db.sekarang(),
        tabel={t: db.val(f"SELECT COUNT(*) FROM {t}") for t in
               ("pengguna", "kategori", "supplier", "kriteria", "nilai", "keputusan", "sso_log", "sinkron")},
        sinkron_terakhir=dict(akhir) if akhir else None,
    )


@app.route("/api/es")
def api_es():
    """
    Hasil DSS untuk Expert System MartOffice (ditandatangani HMAC "dss:es"):
    supplier terpilih + lama pengiriman, kandidat promo, paket bundling, rencana restok, dan parameter restok.
    """
    home = request.args.get("home") or ""
    if not sso.cek_api("dss:es|" + home, request.args.get("ts"), request.args.get("sig")):
        return jsonify(ok=False, pesan="Tanda tangan API tidak valid"), 401
    st = pengaturan()
    out = {"ok": True, "waktu": db.sekarang(),
           "pengaturan": {"siklus": st["restok_siklus"], "stok_aman": st["restok_aman"],
                          "siklus_segar": st.get("restok_segar", 3), "lead_bawaan": st["restok_lead"]},
           "supplier": [{"kategori": k, "supplier": v["supplier"], "v": round(v["v"], 4), "lead": v["lead"]}
                        for k, v in juara_supplier_kategori().items()],
           "promo": [], "bundling": [], "restok": []}
    mo = data_martoffice(home or None)
    if mo and "produk" in mo:
        _, hp, _ = _data_promo(mo)
        out["promo"] = [{"id_produk": r["id"], "nama": r["nama"], "skor": round(r["v"] * 100, 2)}
                        for r in hp["ranking"][:int(st["promo_jumlah"])]]
        out["bundling"] = [{"a": r["a"]["id"], "b": r["b"]["id"], "label": f"{r['a']['nama']} + {r['b']['nama']}",
                            "lift": round(r["lift"], 4), "harga_paket": r["harga_paket"]}
                           for r in modul.hitung_bundling(mo["produk"], mo["keranjang"], st["bundling_diskon"])[:5]]
        rs, _ = _data_restok(mo)
        out["restok"] = [{"id_produk": r["id"], "nama": r["nama"], "saran": r["saran"], "status": r["status"]}
                         for r in rs if r["saran"] > 0]
    return jsonify(out)


@app.route("/api/keputusan")
def api_keputusan():
    """Daftar keputusan untuk MIS MartOffice (ditandatangani HMAC)."""
    if not sso.cek_api("dss:keputusan", request.args.get("ts"), request.args.get("sig")):
        return jsonify(ok=False, pesan="Tanda tangan API tidak valid"), 401
    rows = db.rows("""SELECT p.id, k.nama kategori, p.judul, p.pemenang_nama, round(p.nilai_v,4) nilai_v,
                             round(p.nilai_v*100,2) skor, p.dibuat_oleh, p.dibuat_pada
                      FROM keputusan p JOIN kategori k ON k.id=p.kategori_id ORDER BY p.id DESC LIMIT 20""")
    lain = db.rows("""SELECT k.id, k.modul, k.judul, k.ringkasan, k.dibuat_oleh, k.dibuat_pada,
                             (SELECT COUNT(*) FROM tindak_lanjut t WHERE t.sumber='lain' AND t.keputusan_id=k.id) tindak_lanjut
                      FROM keputusan_lain k ORDER BY k.id DESC LIMIT 20""")
    semua = sorted([{"modul": "supplier", "kasus": MODUL["supplier"][1], "ikon": MODUL["supplier"][0], "judul": r["judul"],
                     "ringkasan": f"{r['pemenang_nama']} ({r['kategori']}) · skor {r['skor']}", "dibuat_oleh": r["dibuat_oleh"],
                     "dibuat_pada": r["dibuat_pada"],
                     "tindak_lanjut": db.val("SELECT COUNT(*) FROM tindak_lanjut WHERE sumber='supplier' AND keputusan_id=?", (r["id"],))} for r in rows] +
                   [{"modul": r["modul"], "kasus": MODUL.get(r["modul"], ("", r["modul"]))[1], "ikon": MODUL.get(r["modul"], ("🧭",))[0],
                     "judul": r["judul"], "ringkasan": r["ringkasan"], "dibuat_oleh": r["dibuat_oleh"], "dibuat_pada": r["dibuat_pada"],
                     "tindak_lanjut": r["tindak_lanjut"]} for r in lain], key=lambda x: x["dibuat_pada"], reverse=True)[:20]
    per_kasus = {k: (db.val("SELECT COUNT(*) FROM keputusan") if k == "supplier" else
                     db.val("SELECT COUNT(*) FROM keputusan_lain WHERE modul=?", (k,))) for k in MODUL}
    return jsonify(ok=True, jumlah=len(rows), keputusan=[dict(r) for r in rows], semua=semua,
                   per_kasus=[{"modul": k, "ikon": v[0], "kasus": v[1], "metode": v[2], "jumlah": per_kasus[k]} for k, v in MODUL.items()])


# ---------------- Log SSO ----------------
@app.route("/log-sso")
@login_wajib()
def log_sso():
    status = request.args.get("status")
    if status not in ("berhasil", "ditolak", "keluar"):
        status = None
    rows = db.rows("SELECT * FROM sso_log" + (" WHERE status=?" if status else "") + " ORDER BY id DESC LIMIT 200",
                   (status,) if status else ())
    rekap = {r["status"]: r["n"] for r in db.rows("SELECT status, COUNT(*) n FROM sso_log GROUP BY status")}
    pengguna = db.rows("SELECT * FROM pengguna ORDER BY terakhir_masuk DESC")
    return render_template("log_sso.html", rows=rows, rekap=rekap, pengguna=pengguna, status=status, active="log")


@app.errorhandler(404)
def e404(_e):
    return render_template("galat.html", kode=404, judul="Halaman tidak ditemukan"), 404


# =====================================================================
#  Menjalankan aplikasi
# =====================================================================
def port_kosong(host, port) -> bool:
    """Port dianggap terpakai bila sudah ada aplikasi yang menerima koneksi di port tersebut."""
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.settimeout(0.5)
        return s.connect_ex((host, port)) != 0


db.init_db()

if __name__ == "__main__":
    if not port_kosong(config.HOST, config.PORT):
        print(f"\n[X] Port {config.PORT} sudah dipakai aplikasi lain.")
        print(f"    Cek dengan:  netstat -ano | findstr :{config.PORT}")
        print("    Atau ganti PORT di dss/config.py dan DSS_URL di includes/config.php.\n")
        sys.exit(1)
    print("=" * 60)
    print("  DSS MartOffice - Pemilihan Supplier (SAW)")
    print(f"  Berjalan di : http://localhost:{config.PORT}")
    print(f"  Database    : {config.DB_PATH}")
    print(f"  Masuk lewat : {config.MARTOFFICE_URL}/beranda.php  -> kartu DSS")
    print("  Hentikan    : tekan Ctrl + C")
    print("=" * 60)
    app.run(host=config.HOST, port=config.PORT, debug=False)

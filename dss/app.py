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
@app.route("/")
@login_wajib()
def dashboard():
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
    return render_template("dashboard.html", juara=juara, mo=mo, prioritas=prioritas, langkah=langkah,
                           stat=stat, terbaru=terbaru, active="dashboard")


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
    return render_template("keputusan.html", rows=rows, kats=daftar_kategori(), kid=kid, active="keputusan")


def _keputusan(kid):
    p = db.row("SELECT p.*, k.nama kategori, k.ikon FROM keputusan p JOIN kategori k ON k.id=p.kategori_id WHERE p.id=?", (kid,))
    if not p:
        abort(404)
    return p, json.loads(p["bobot_json"]), json.loads(p["hasil_json"])


@app.route("/keputusan/<int:kid>")
@login_wajib()
def keputusan_detail(kid):
    p, bobot, hasil = _keputusan(kid)
    return render_template("keputusan_detail.html", p=p, bobot=bobot, hasil=hasil, active="keputusan")


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


@app.route("/api/keputusan")
def api_keputusan():
    """Daftar keputusan untuk MIS MartOffice (ditandatangani HMAC)."""
    if not sso.cek_api("dss:keputusan", request.args.get("ts"), request.args.get("sig")):
        return jsonify(ok=False, pesan="Tanda tangan API tidak valid"), 401
    rows = db.rows("""SELECT p.id, k.nama kategori, p.judul, p.pemenang_nama, round(p.nilai_v,4) nilai_v,
                             round(p.nilai_v*100,2) skor, p.dibuat_oleh, p.dibuat_pada
                      FROM keputusan p JOIN kategori k ON k.id=p.kategori_id ORDER BY p.id DESC LIMIT 20""")
    return jsonify(ok=True, jumlah=len(rows), keputusan=[dict(r) for r in rows])


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

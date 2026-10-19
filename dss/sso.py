"""
Single Sign-On antara MartOffice (PHP) dan DSS (Flask).

Format token (dibuat oleh martoffice/sso_dss.php):
    base64url(payload_json) + "." + base64url(HMAC_SHA256(base64url(payload_json), SSO_SECRET))

Isi payload: sub (id pegawai), username, nama, role, jabatan, home (URL MartOffice),
iat (waktu dibuat), exp (kedaluwarsa), jti (id unik token, mencegah pemakaian ulang).
"""
import base64
import hashlib
import hmac
import json
import time
import urllib.parse
import urllib.request

import config


def _b64url_decode(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


def _b64url_encode(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def buat_token(payload: dict) -> str:
    """Membuat token dengan format yang sama seperti PHP (dipakai untuk pengujian)."""
    p64 = _b64url_encode(json.dumps(payload).encode())
    sig = hmac.new(config.SSO_SECRET.encode(), p64.encode(), hashlib.sha256).digest()
    return p64 + "." + _b64url_encode(sig)


def verifikasi_token(token: str):
    """Mengembalikan (payload, None) bila sah, atau (payload_atau_None, alasan) bila ditolak."""
    if not token or token.count(".") != 1:
        return None, "Format token tidak valid"
    p64, s64 = token.split(".")
    try:
        sig = _b64url_decode(s64)
        payload = json.loads(_b64url_decode(p64))
    except Exception:
        return None, "Token rusak / tidak dapat dibaca"

    harus = hmac.new(config.SSO_SECRET.encode(), p64.encode(), hashlib.sha256).digest()
    if not hmac.compare_digest(sig, harus):
        return payload if isinstance(payload, dict) else None, "Tanda tangan token tidak cocok (token palsu atau diubah)"
    if not isinstance(payload, dict):
        return None, "Isi token tidak valid"
    for kunci in ("sub", "username", "nama", "role", "iat", "exp", "jti"):
        if kunci not in payload:
            return payload, f"Token tidak lengkap (tidak ada '{kunci}')"
    now = int(time.time())
    if int(payload["exp"]) < now:
        return payload, "Token sudah kedaluwarsa"
    if int(payload["iat"]) > now + 30:
        return payload, "Waktu pembuatan token tidak wajar"
    if payload["role"] not in config.ROLE_DIIZINKAN:
        return payload, f"Role '{payload['role']}' tidak memiliki akses ke DSS"
    return payload, None


# ---------- Tanda tangan untuk API antar-aplikasi ----------
def tanda_tangan_api(nama: str, ts: int) -> str:
    return hmac.new(config.SSO_SECRET.encode(), f"{nama}|{ts}".encode(), hashlib.sha256).hexdigest()


def cek_api(nama: str, ts: str, sig: str) -> bool:
    try:
        ts_i = int(ts)
    except (TypeError, ValueError):
        return False
    if abs(time.time() - ts_i) > config.API_TOLERANSI:
        return False
    return hmac.compare_digest(tanda_tangan_api(nama, ts_i), sig or "")


def ambil_data_tps(home: str | None = None, timeout: float = 3.0):
    """Mengambil ringkasan kategori dari TPS MartOffice (api/dss_tps.php). Mengembalikan dict atau None."""
    base = (home or config.MARTOFFICE_URL).rstrip("/")
    ts = int(time.time())
    q = urllib.parse.urlencode({"ts": ts, "sig": tanda_tangan_api("tps:kategori", ts)})
    try:
        with urllib.request.urlopen(f"{base}/api/dss_tps.php?{q}", timeout=timeout) as r:
            data = json.loads(r.read().decode("utf-8"))
            return data if data.get("ok") else None
    except Exception:
        return None

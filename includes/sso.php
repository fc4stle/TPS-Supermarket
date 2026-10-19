<?php
/* ==========================================================
 *  SINGLE SIGN-ON & INTEGRASI DENGAN DSS (Flask, port 5055)
 *
 *  Token SSO = base64url(payload JSON) . "." . base64url(HMAC-SHA256)
 *  Kunci HMAC (SSO_SECRET) dimiliki bersama oleh MartOffice dan DSS,
 *  sehingga DSS dapat memastikan token benar-benar dibuat MartOffice
 *  dan isinya tidak diubah di perjalanan.
 * ========================================================== */

function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Alamat lengkap MartOffice, mis. http://localhost/martoffice */
function martoffice_home(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
}

/** Membuat token SSO bertanda tangan untuk pengguna yang sedang login. */
function sso_token(array $u): string
{
    $now = time();
    $payload = [
        'sub'      => (int) $u['id'],
        'username' => $u['username'],
        'nama'     => $u['nama'],
        'role'     => $u['role'],
        'jabatan'  => $u['jabatan'],
        'home'     => martoffice_home(),
        'iat'      => $now,
        'exp'      => $now + SSO_TTL,
        'jti'      => bin2hex(random_bytes(16)),   // id unik → token hanya bisa dipakai sekali
    ];
    $p64 = b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = b64url(hash_hmac('sha256', $p64, SSO_SECRET, true));
    return $p64 . '.' . $sig;
}

/** Tanda tangan untuk API antar-aplikasi (MartOffice ⇄ DSS). */
function api_sig(string $nama, int $ts): string
{
    return hash_hmac('sha256', $nama . '|' . $ts, SSO_SECRET);
}

function api_valid(string $nama): bool
{
    $ts = (int) ($_GET['ts'] ?? 0);
    return abs(time() - $ts) <= 120 && hash_equals(api_sig($nama, $ts), (string) ($_GET['sig'] ?? ''));
}

/** Apakah layanan DSS sedang berjalan di port DSS_PORT? */
function dss_hidup(float $timeout = 0.6): bool
{
    $fp = @fsockopen('127.0.0.1', DSS_PORT, $errno, $errstr, $timeout);
    if (!$fp) return false;
    fclose($fp);
    return true;
}

/** Ringkasan keputusan dari DSS untuk ditampilkan di MartOffice. null bila DSS tidak berjalan. */
function dss_ringkasan(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    if (!dss_hidup()) return null;
    $ts = time();
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents(DSS_API . '/api/ringkasan?' . http_build_query(['ts' => $ts, 'sig' => api_sig('dss:ringkasan', $ts)]), false, $ctx);
    $data = $json ? json_decode($json, true) : null;
    if (is_array($data) && !empty($data['ok'])) $cache = $data;
    return $cache;
}

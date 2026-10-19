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

/** Lokasi Python untuk menjalankan DSS (pythonw.exe di Windows agar tidak muncul jendela). */
function dss_python(): ?string
{
    if (DSS_PYTHON !== '') return is_file(DSS_PYTHON) ? DSS_PYTHON : null;
    if (PHP_OS_FAMILY !== 'Windows') {
        $p = trim((string) @shell_exec('command -v python3 2>/dev/null'));
        return $p !== '' ? $p : null;
    }
    foreach (['pythonw', 'python'] as $nama) {
        $hasil = (string) @shell_exec('where ' . $nama . ' 2>NUL');
        foreach (preg_split('/\r?\n/', trim($hasil)) as $p) {
            $p = trim($p);
            if ($p === '' || stripos($p, 'WindowsApps') !== false || !is_file($p)) continue;   // lewati alias Microsoft Store
            $w = preg_replace('/python\.exe$/i', 'pythonw.exe', $p);
            return is_file($w) ? $w : $p;
        }
    }
    return null;
}

/**
 * Menyalakan DSS di latar belakang (tanpa jendela) lalu menunggu sampai port DSS_PORT siap.
 * Dipanggil oleh sso_dss.php, sehingga pengguna tidak perlu membuka Command Prompt.
 */
function dss_nyalakan(int $tunggu = 15): bool
{
    if (dss_hidup()) return true;
    $py = dss_python();
    if (!$py) return false;
    $root = realpath(__DIR__ . '/..');
    $app = $root . DIRECTORY_SEPARATOR . 'dss' . DIRECTORY_SEPARATOR . 'app.py';
    $log = $root . DIRECTORY_SEPARATOR . 'dss' . DIRECTORY_SEPARATOR . 'dss.log';
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start "DSS MartOffice" /B "' . $py . '" "' . $app . '" > "' . $log . '" 2>&1', 'r'));
    } else {
        exec('nohup ' . escapeshellarg($py) . ' ' . escapeshellarg($app) . ' > ' . escapeshellarg($log) . ' 2>&1 &');
    }
    $batas = microtime(true) + $tunggu;
    while (microtime(true) < $batas) {
        usleep(400000);
        if (dss_hidup(0.3)) return true;
    }
    return false;
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

/** Daftar keputusan supplier dari DSS untuk panel MIS. null bila DSS tidak berjalan. */
function dss_keputusan(): ?array
{
    if (!dss_hidup()) return null;
    $ts = time();
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents(DSS_API . '/api/keputusan?' . http_build_query(['ts' => $ts, 'sig' => api_sig('dss:keputusan', $ts)]), false, $ctx);
    $data = $json ? json_decode($json, true) : null;
    return is_array($data) && !empty($data['ok']) ? $data['keputusan'] : null;
}

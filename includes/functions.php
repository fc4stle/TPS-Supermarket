<?php
/* ==========================================================
 *  FUNGSI BANTU MARTOFFICE
 * ========================================================== */

/* ---------- Database ---------- */
function db(): mysqli
{
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        } catch (mysqli_sql_exception $ex) {
            fatal_page('Database belum siap',
                'MartOffice tidak dapat terhubung ke database <b>' . DB_NAME . '</b>.<br>'
                . 'Pastikan MySQL di XAMPP sudah <b>Start</b> dan file <code>database.sql</code> sudah dijalankan.<br>'
                . '<small>Detail: ' . e($ex->getMessage()) . '</small>');
        }
        $db->set_charset('utf8mb4');
        $db->query("SET time_zone = '+07:00'");
    }
    return $db;
}

/** Jalankan query dengan prepared statement. Tipe parameter dideteksi otomatis. */
function q(string $sql, array $p = [])
{
    $st = db()->prepare($sql);
    if ($p) {
        $types = '';
        foreach ($p as $v) $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
        $st->bind_param($types, ...$p);
    }
    $st->execute();
    $res = $st->get_result();
    return $res === false ? $st : $res;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetch_all(MYSQLI_ASSOC); }
function row(string $sql, array $p = []): ?array { return q($sql, $p)->fetch_assoc() ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetch_row(); return $r ? $r[0] : null; }
function last_id(): int { return (int) db()->insert_id; }

/* ---------- Output & format ---------- */
function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rp($n): string { return 'Rp ' . number_format((float) $n, 0, ',', '.'); }
function angka($n): string { return number_format((float) $n, 0, ',', '.'); }

function tgl($dt, bool $jam = true): string
{
    if (!$dt) return '-';
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($dt);
    return date('j', $t) . ' ' . $bln[(int) date('n', $t)] . ' ' . date('Y', $t) . ($jam ? ' · ' . date('H:i', $t) : '');
}
function tgl_panjang($dt = null): string
{
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bln = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $t = $dt ? strtotime($dt) : time();
    return $hari[(int) date('w', $t)] . ', ' . date('j', $t) . ' ' . $bln[(int) date('n', $t)] . ' ' . date('Y', $t);
}
function lalu($dt): string
{
    $d = time() - strtotime($dt);
    if ($d < 60) return 'baru saja';
    if ($d < 3600) return floor($d / 60) . ' menit lalu';
    if ($d < 86400) return floor($d / 3600) . ' jam lalu';
    if ($d < 604800) return floor($d / 86400) . ' hari lalu';
    return tgl($dt, false);
}
function ukuran_file(int $b): string
{
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    if ($b >= 1024) return number_format($b / 1024, 0, ',', '.') . ' KB';
    return $b . ' B';
}
function inisial(string $nama): string
{
    $p = preg_split('/\s+/', trim($nama));
    return strtoupper(mb_substr($p[0] ?? '?', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
}
function badge(string $text, string $tone = 'g'): string
{
    return '<span class="badge ' . $tone . '">' . e($text) . '</span>';
}
function status_badge(string $s): string
{
    $map = [
        'published' => ['Published', 'g'], 'menunggu_review' => ['Menunggu review', 'y'], 'ditolak' => ['Ditolak', 'r'],
        'terbuka' => ['Terbuka', 'r'], 'proses' => ['Dikerjakan', 'y'], 'selesai' => ['Selesai', 'g'],
        'tunai' => ['Tunai', 'g'], 'debit' => ['Debit', 'b'], 'qris' => ['QRIS', 'p'],
        'rendah' => ['Rendah', 'n'], 'sedang' => ['Sedang', 'y'], 'tinggi' => ['Tinggi', 'r'],
        'internal' => ['Internal', 'n'], 'terbatas' => ['Terbatas', 'p'],
        'admin' => ['Admin', 'p'], 'manajer' => ['Manajer', 'b'], 'staff' => ['Staff', 'g'],
    ];
    [$t, $c] = $map[$s] ?? [$s, 'n'];
    return badge($t, $c);
}
function empty_state(string $icon, string $title, string $text = ''): string
{
    return '<div class="empty"><div class="empty-ic">' . $icon . '</div><b>' . e($title) . '</b>'
        . ($text ? '<p>' . $text . '</p>' : '') . '</div>';
}

/* ---------- Request ---------- */
function post(string $k, $def = '') { return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : $def; }
function get(string $k, $def = '') { return is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : $def; }
function pilih($v, array $boleh, $default) { return in_array($v, $boleh, true) ? $v : $default; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

/* ---------- URL & redirect ---------- */
function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): void
{
    header('Location: ' . (preg_match('~^(https?:)?/~', $path) ? $path : url($path)));
    exit;
}
function back(): void { redirect($_SERVER['REQUEST_URI'] ?? url()); }
function qs(array $ubah): string
{
    $q = array_merge($_GET, $ubah);
    foreach ($q as $k => $v) if ($v === null || $v === '') unset($q[$k]);
    return '?' . http_build_query($q);
}

/* ---------- Flash message ---------- */
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function render_flash(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
        $ic = ['ok' => '✅', 'err' => '⚠️', 'info' => 'ℹ️'][$t] ?? 'ℹ️';
        $out .= '<div class="alert ' . $t . '"><span>' . $ic . '</span><div>' . $m . '</div><button type="button" class="x" onclick="this.parentNode.remove()">×</button></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf(): string { return '<input type="hidden" name="_token" value="' . csrf_token() . '">'; }
function verify_csrf(): void
{
    if (!is_post()) return;
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('err', 'Data yang dikirim terlalu besar (melebihi batas upload server).');
        back();
    }
    if (!hash_equals(csrf_token(), (string) ($_POST['_token'] ?? ''))) {
        flash('err', 'Sesi formulir sudah kedaluwarsa. Silakan ulangi.');
        back();
    }
}

/* ---------- Auth ---------- */
function me(): ?array { return $GLOBALS['ME'] ?? null; }
function uid(): int { return (int) (me()['id'] ?? 0); }
function role(): string { return me()['role'] ?? ''; }
function is_boss(): bool { return in_array(role(), ['admin', 'manajer'], true); }
function home_url(string $role = ''): string
{
    return ['admin' => 'admin/dashboard.php', 'manajer' => 'manajer/dashboard.php', 'staff' => 'staff/dashboard.php'][$role ?: role()] ?? 'login.php';
}
function need(string ...$roles): void
{
    if (!me()) {
        flash('info', 'Silakan masuk terlebih dahulu.');
        redirect('login.php');
    }
    if ($roles && !in_array(role(), $roles, true)) {
        flash('err', 'Anda tidak memiliki akses ke halaman tersebut.');
        redirect(home_url());
    }
}

/* ---------- Pagination ---------- */
function paginate(int $total, int $per = 15): array
{
    $pages = max(1, (int) ceil($total / $per));
    $page = min($pages, max(1, (int) get('hal', 1)));
    return ['page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per, 'total' => $total];
}
function pager(array $pg): string
{
    if ($pg['pages'] <= 1) return '';
    $h = '<nav class="pager">';
    for ($i = 1; $i <= $pg['pages']; $i++) {
        $h .= $i == $pg['page'] ? '<span class="on">' . $i . '</span>' : '<a href="' . e(qs(['hal' => $i])) . '">' . $i . '</a>';
    }
    return $h . '</nav>';
}

/* ---------- Stok ---------- */
function catat_stok(int $pid, int $delta, string $jenis, string $ket = ''): void
{
    q('INSERT INTO stok_log(id_produk,perubahan,jenis,keterangan,id_pegawai) VALUES(?,?,?,?,?)', [$pid, $delta, $jenis, $ket, uid()]);
}

/* ---------- KMS: tag & versi ---------- */
function parse_tags(string $s): array
{
    $out = [];
    foreach (explode(',', $s) as $t) {
        $t = mb_strtolower(trim(preg_replace('/\s+/', '-', trim($t)), '#- '));
        $t = mb_substr(preg_replace('/[^\p{L}\p{N}\-]/u', '', $t), 0, 40);
        if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
    }
    return array_slice($out, 0, 10);
}
function simpan_tags(int $aid, array $tags): void
{
    q('DELETE FROM artikel_tag WHERE id_artikel=?', [$aid]);
    foreach ($tags as $t) {
        q('INSERT IGNORE INTO tag_kms(nama) VALUES(?)', [$t]);
        $tid = (int) val('SELECT id FROM tag_kms WHERE nama=?', [$t]);
        q('INSERT IGNORE INTO artikel_tag(id_artikel,id_tag) VALUES(?,?)', [$aid, $tid]);
    }
    q('DELETE FROM tag_kms WHERE id NOT IN (SELECT id_tag FROM artikel_tag)');
}
function tags_artikel(int $aid): array
{
    return array_column(rows('SELECT t.nama FROM artikel_tag x JOIN tag_kms t ON t.id=x.id_tag WHERE x.id_artikel=? ORDER BY t.nama', [$aid]), 'nama');
}
function simpan_versi(int $aid, string $catatan): void
{
    $a = row('SELECT * FROM artikel_kms WHERE id=?', [$aid]);
    q('INSERT INTO artikel_versi(id_artikel,versi,judul,id_kategori,ringkasan,isi,tag_teks,id_editor,catatan_perubahan) VALUES(?,?,?,?,?,?,?,?,?)',
        [$aid, (int) $a['versi'], $a['judul'], (int) $a['id_kategori'], $a['ringkasan'], $a['isi'], implode(', ', tags_artikel($aid)), uid(), $catatan]);
}
/** Syarat artikel boleh dilihat user saat ini. */
function boleh_lihat_artikel(array $a): bool
{
    if (is_boss() || (int) $a['id_penulis'] === uid()) return true;
    return $a['status'] === 'published' && $a['visibilitas'] === 'internal';
}
function tag_chips(array $tags): string
{
    $h = '';
    foreach ($tags as $t) $h .= '<a class="chip-tag" href="' . url('kms/index.php?tag=' . urlencode($t)) . '">#' . e($t) . '</a>';
    return $h;
}

/** Diff baris sederhana (LCS) untuk riwayat versi. */
function diff_baris(string $lama, string $baru): string
{
    $a = preg_split("/\r\n|\n|\r/", $lama);
    $b = preg_split("/\r\n|\n|\r/", $baru);
    $n = count($a); $m = count($b);
    if ($n * $m > 250000) return '<div class="diff"><div class="d-same">(Teks terlalu panjang untuk dibandingkan)</div></div>';
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--)
        for ($j = $m - 1; $j >= 0; $j--)
            $L[$i][$j] = $a[$i] === $b[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    $h = ''; $i = $j = 0;
    while ($i < $n || $j < $m) {
        if ($i < $n && $j < $m && $a[$i] === $b[$j]) { $h .= '<div class="d-same">' . (e($a[$i]) ?: '&nbsp;') . '</div>'; $i++; $j++; }
        elseif ($j < $m && ($i >= $n || $L[$i][$j + 1] >= $L[$i + 1][$j])) { $h .= '<div class="d-add">+ ' . e($b[$j]) . '</div>'; $j++; }
        else { $h .= '<div class="d-del">− ' . e($a[$i]) . '</div>'; $i++; }
    }
    return '<div class="diff">' . $h . '</div>';
}

/* ---------- Halaman error sederhana ---------- */
function fatal_page(string $judul, string $pesan): void
{
    http_response_code(500);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($judul) . '</title>'
        . '<style>body{font-family:system-ui,Segoe UI,Arial;background:#f3f7f1;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}'
        . '.b{background:#fff;max-width:560px;padding:32px;border-radius:18px;box-shadow:0 10px 30px rgba(0,60,20,.12);border-top:6px solid #f59e0b}'
        . 'h1{color:#14532d;margin:0 0 12px;font-size:22px}p{line-height:1.7;color:#334}code{background:#fef3c7;padding:2px 6px;border-radius:6px}small{color:#889}</style></head>'
        . '<body><div class="b"><h1>🛒 ' . e($judul) . '</h1><p>' . $pesan . '</p></div></body></html>';
    exit;
}

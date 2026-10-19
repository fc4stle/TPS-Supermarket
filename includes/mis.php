<?php
/* ==========================================================
 *  MIS — Management Information System
 *  Lapisan manajerial di atas TPS, OAS, dan KMS.
 *  MIS tidak menyimpan data operasional baru: semua indikator
 *  dihitung dari tabel ketiga modul (plus satu tabel agregasi).
 * ========================================================== */

/* ---------- Menu sidebar khusus MIS ---------- */
function mis_menu(): array
{
    $n = count(mis_alerts());
    return [
        ['MIS · Ringkasan', [
            ['mis', '📈', 'Executive Dashboard', 'mis/index.php', [], 0],
            ['mis-peringatan', '🚨', 'Pusat Peringatan', 'mis/peringatan.php', [], $n],
        ]],
        ['Dari TPS', [
            ['mis-penjualan', '💰', 'Analitik Penjualan', 'mis/penjualan.php', [], 0],
            ['mis-produk', '🛍️', 'Analitik Produk', 'mis/produk.php', [], 0],
            ['mis-persediaan', '📦', 'Analitik Persediaan', 'mis/persediaan.php', [], 0],
            ['mis-pelanggan', '🧺', 'Analitik Belanja Pelanggan', 'mis/pelanggan.php', [], 0],
        ]],
        ['Dari OAS & KMS', [
            ['mis-oas', '📋', 'Monitoring OAS', 'mis/oas.php', [], 0],
            ['mis-kms', '📚', 'Analitik KMS', 'mis/kms.php', [], 0],
        ]],
        ['Manajemen', [
            ['mis-pegawai', '🏅', 'Kinerja Pegawai', 'mis/pegawai.php', [], 0],
            ['mis-perkiraan', '🔮', 'Perkiraan', 'mis/perkiraan.php', [], 0],
            ['mis-laporan', '🗒️', 'Laporan Manajemen', 'mis/laporan.php', [], 0],
            ['dss', '🧭', 'DSS · Pemilihan Supplier ↗', 'sso_dss.php', [], 0],
        ]],
        ['Operasional', [
            ['beranda', '🏠', 'Beranda', 'beranda.php', [], 0],
            ['kembali', '↩️', 'Kembali ke Operasional', home_url(), [], 0],
        ]],
    ];
}

/* ---------- Tabel agregasi harian ---------- */
function mis_refresh(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->query("CREATE TABLE IF NOT EXISTS mis_penjualan_harian (
        tanggal           DATE PRIMARY KEY,
        jumlah_transaksi  INT NOT NULL DEFAULT 0,
        omzet             DECIMAL(15,2) NOT NULL DEFAULT 0,
        rata_belanja      DECIMAL(15,2) NOT NULL DEFAULT 0,
        barang_terjual    INT NOT NULL DEFAULT 0,
        omzet_tunai       DECIMAL(15,2) NOT NULL DEFAULT 0,
        omzet_debit       DECIMAL(15,2) NOT NULL DEFAULT 0,
        omzet_qris        DECIMAL(15,2) NOT NULL DEFAULT 0,
        diperbarui        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    db()->begin_transaction();
    db()->query('DELETE FROM mis_penjualan_harian');
    db()->query("INSERT INTO mis_penjualan_harian(tanggal,jumlah_transaksi,omzet,rata_belanja,barang_terjual,omzet_tunai,omzet_debit,omzet_qris,diperbarui)
        SELECT DATE(t.waktu), COUNT(*), SUM(t.total), AVG(t.total), COALESCE(SUM(x.qty),0),
               SUM(IF(t.metode='tunai',t.total,0)), SUM(IF(t.metode='debit',t.total,0)), SUM(IF(t.metode='qris',t.total,0)), NOW()
        FROM transaksi t LEFT JOIN (SELECT id_transaksi, SUM(qty) qty FROM detail_transaksi GROUP BY id_transaksi) x ON x.id_transaksi=t.id
        GROUP BY DATE(t.waktu)");
    db()->commit();
}

/* ---------- Periode & pembanding ---------- */
function mis_periode(string $default = '30'): array
{
    $p = pilih(get('p'), ['hari', '7', '30', '90', 'bulan', 'bulan_lalu', 'kustom'], $default);
    $today = date('Y-m-d');
    $tgl = fn($s) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) ? $s : '';
    switch ($p) {
        case 'hari':
            [$d, $s, $pd, $ps, $label] = [$today, $today, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), 'Hari ini'];
            break;
        case 'bulan':
            $d = date('Y-m-01'); $s = $today;
            $pd = date('Y-m-01', strtotime('first day of last month'));
            $ps = date('Y-m-d', min(strtotime($pd . ' +' . ((int) date('j') - 1) . ' days'), strtotime('last day of last month')));
            $label = 'Bulan ini';
            break;
        case 'bulan_lalu':
            $d = date('Y-m-01', strtotime('first day of last month')); $s = date('Y-m-t', strtotime($d));
            $pd = date('Y-m-01', strtotime($d . ' -1 month')); $ps = date('Y-m-t', strtotime($pd));
            $label = 'Bulan lalu';
            break;
        case 'kustom':
            $d = $tgl(get('dari')) ?: date('Y-m-d', strtotime('-29 days')); $s = $tgl(get('sampai')) ?: $today;
            if ($d > $s) [$d, $s] = [$s, $d];
            $n = (int) ((strtotime($s) - strtotime($d)) / 86400) + 1;
            $ps = date('Y-m-d', strtotime($d . ' -1 day')); $pd = date('Y-m-d', strtotime($ps . ' -' . ($n - 1) . ' days'));
            $label = 'Rentang pilihan';
            break;
        default:
            $n = (int) $p;
            $s = $today; $d = date('Y-m-d', strtotime('-' . ($n - 1) . ' days'));
            $ps = date('Y-m-d', strtotime($d . ' -1 day')); $pd = date('Y-m-d', strtotime($ps . ' -' . ($n - 1) . ' days'));
            $label = $n . ' hari terakhir';
    }
    return ['p' => $p, 'dari' => $d, 'sampai' => $s, 'pdari' => $pd, 'psampai' => $ps, 'label' => $label,
        'hari' => (int) ((strtotime($s) - strtotime($d)) / 86400) + 1];
}

/** Mulai halaman MIS: batasi ke manajer, segarkan agregasi, tampilkan header + filter periode. */
function mis_start(string $title, string $active, string $subtitle, ?array $per = null, bool $csv = false): void
{
    page_start($title, $active, $subtitle);
    echo '<div class="mis">';
    if ($per) echo mis_filter($per, $csv);
}
function mis_end(): void
{
    echo '</div>';
    page_end();
}
function mis_filter(array $per, bool $csv = false): string
{
    $opsi = ['hari' => 'Hari ini', '7' => '7 hari', '30' => '30 hari', '90' => '90 hari', 'bulan' => 'Bulan ini', 'bulan_lalu' => 'Bulan lalu'];
    $h = '<form class="card mis-filter no-print" method="get"><div class="chips">';
    foreach ($opsi as $k => $v) $h .= '<a class="chip ' . ($per['p'] === $k ? 'on' : '') . '" href="?p=' . $k . '">' . $v . '</a>';
    $h .= '</div><input type="hidden" name="p" value="kustom">'
        . '<input type="date" name="dari" value="' . e($per['dari']) . '" aria-label="Dari tanggal">'
        . '<span class="muted">s.d.</span><input type="date" name="sampai" value="' . e($per['sampai']) . '" aria-label="Sampai tanggal">'
        . '<button type="submit" class="sm">Terapkan</button><span class="grow"></span>';
    if ($csv) $h .= '<a class="btn sm y" href="' . e(qs(['export' => 'csv'])) . '">⬇️ CSV</a>';
    $h .= '<button type="button" class="sm ghost" onclick="window.print()">🖨️ Cetak</button></form>';
    $h .= '<p class="mis-periode">📅 <b>' . e($per['label']) . '</b>: ' . tgl($per['dari'], false) . ' – ' . tgl($per['sampai'], false)
        . ' <span class="muted">· dibandingkan dengan ' . tgl($per['pdari'], false) . ' – ' . tgl($per['psampai'], false) . '</span></p>';
    return $h;
}

/* ---------- Komponen tampilan ---------- */
/** Lencana perubahan terhadap periode pembanding. $baikNaik=false bila kenaikan justru buruk. */
function delta($now, $prev, bool $baikNaik = true, bool $poin = false): string
{
    $now = (float) $now; $prev = (float) $prev;
    if ($prev == 0.0 && $now == 0.0) return '<span class="delta flat" title="Tidak ada data pada kedua periode">– sama</span>';
    if ($prev == 0.0) return '<span class="delta up ' . ($baikNaik ? 'good' : 'bad') . '" title="Periode pembanding kosong">▲ baru</span>';
    $ch = $poin ? $now - $prev : ($now - $prev) / abs($prev) * 100;
    if (abs($ch) < 0.05) return '<span class="delta flat">– sama</span>';
    $naik = $ch > 0;
    $kelas = ($naik === $baikNaik) ? 'good' : 'bad';
    return '<span class="delta ' . ($naik ? 'up' : 'down') . ' ' . $kelas . '" title="Periode sebelumnya: ' . e($poin ? persen($prev) : angka($prev)) . '">'
        . ($naik ? '▲' : '▼') . ' ' . number_format(abs($ch), 1, ',', '.') . ($poin ? ' poin' : '%') . '</span>';
}
function persen($v, int $des = 1): string { return number_format((float) $v, $des, ',', '.') . '%'; }
function rasio($a, $b): float { return $b > 0 ? $a / $b * 100 : 0.0; }
function ringkas($v): string
{
    $v = (float) $v;
    if ($v >= 1e9) return 'Rp ' . number_format($v / 1e9, 2, ',', '.') . ' M';
    if ($v >= 1e6) return 'Rp ' . number_format($v / 1e6, 1, ',', '.') . ' jt';
    return rp($v);
}

function kpi(string $label, string $value, string $icon, string $delta = '', string $note = '', string $tone = 'g', string $href = ''): string
{
    $in = '<div class="kpi-top"><span class="kpi-ic">' . $icon . '</span>' . $delta . '</div><small>' . e($label) . '</small><strong>' . $value . '</strong>'
        . ($note ? '<em>' . $note . '</em>' : '');
    return $href ? '<a class="kpi ' . $tone . '" href="' . url($href) . '">' . $in . '</a>' : '<div class="kpi ' . $tone . '">' . $in . '</div>';
}
function sumber(string $tabel): string
{
    return '<p class="sumber">Sumber: <code>' . str_replace(', ', '</code>, <code>', e($tabel)) . '</code></p>';
}
function panel(string $judul, string $isi, string $sumber = '', string $aksi = '', string $kelas = ''): string
{
    return '<section class="card panel ' . $kelas . '"><div class="card-h"><h3>' . $judul . '</h3>' . $aksi . '</div>' . $isi . ($sumber ? sumber($sumber) : '') . '</section>';
}

/** Batang horizontal. $rows = [[label, nilai, teksNilai, teksKecil?], ...] */
function hbars(array $rows, string $warna = 'g', string $kosong = 'Belum ada data pada periode ini'): string
{
    if (!$rows) return empty_state('📭', $kosong);
    $max = max(array_merge([0.0001], array_map(fn($r) => (float) $r[1], $rows)));
    $h = '<div class="hbars">';
    foreach ($rows as $r) {
        $h .= '<div class="hbar" title="' . e(strip_tags($r[0]) . ': ' . $r[2]) . '"><span class="hl">' . $r[0] . (isset($r[3]) ? '<small>' . e($r[3]) . '</small>' : '') . '</span>'
            . '<span class="ht"><i class="c-' . $warna . '" style="width:' . max(1, round($r[1] / $max * 100, 1)) . '%"></i></span><span class="hv">' . e($r[2]) . '</span></div>';
    }
    return $h . '</div>';
}

/** Batang bertumpuk satu baris + legenda. $seg = [[label, nilai, kelasWarna], ...] */
function stack(array $seg, string $satuan = ''): string
{
    $tot = array_sum(array_column($seg, 1));
    if ($tot <= 0) return empty_state('📭', 'Belum ada data pada periode ini');
    $b = $l = '';
    foreach ($seg as [$label, $v, $c]) {
        $pc = $v / $tot * 100;
        if ($v > 0) $b .= '<i class="' . $c . '" style="flex:' . $v . '" title="' . e($label . ': ' . angka($v) . ' ' . $satuan . ' (' . persen($pc) . ')') . '"></i>';
        $l .= '<span><i class="' . $c . '"></i>' . e($label) . ' <b>' . angka($v) . '</b> <small>' . persen($pc) . '</small></span>';
    }
    return '<div class="stack">' . $b . '</div><div class="legend">' . $l . '</div>';
}

/** Grafik kolom. $data = [[label, nilai, kelas?], ...]; kelas 'fc' untuk nilai perkiraan. */
function kolom(array $data, bool $rupiah = true, int $tinggi = 210, string $legend = ''): string
{
    $max = max(array_merge([1], array_map(fn($r) => (float) $r[1], $data)));
    $n = count($data);
    $padat = $n > 16;
    $step = $n > 60 ? 10 : ($n > 31 ? 5 : ($padat ? 3 : 1));
    $fmt = fn($v) => $rupiah ? ($v >= 1e6 ? number_format($v / 1e6, 1, ',', '.') . 'jt' : ($v >= 1000 ? number_format($v / 1000, 0, ',', '.') . 'rb' : angka($v))) : angka($v);
    $b = $l = '';
    foreach ($data as $i => $r) {
        [$label, $v] = $r; $v = (float) $v;
        $h = $v > 0 ? max(2, round($v / $max * 100, 1)) : 0;
        $b .= '<div class="col ' . ($r[2] ?? '') . '" title="' . e($label . ': ' . ($rupiah ? rp($v) : angka($v))) . '"><span>' . ($v > 0 && !$padat ? $fmt($v) : '') . '</span><i style="height:' . $h . '%"></i></div>';
        $l .= '<span>' . ($i % $step === 0 || $i === $n - 1 && $step === 1 ? e($label) : '') . '</span>';
    }
    return '<div class="cols-wrap"><div class="cols-y"><span>' . $fmt($max) . '</span><span>' . $fmt($max / 2) . '</span><span>0</span></div>'
        . '<div class="cols-plot"><div class="cols" style="height:' . $tinggi . 'px">' . $b . '</div><div class="col-labels">' . $l . '</div></div></div>' . $legend;
}

/** Tabel sederhana dari baris-baris HTML. */
function tabel(array $head, array $rows, string $kosong = 'Belum ada data'): string
{
    if (!$rows) return empty_state('📭', $kosong);
    $h = '<div class="table-wrap"><table><thead><tr>';
    foreach ($head as $c) $h .= '<th' . (substr($c, 0, 1) === '#' ? ' class="num"' : '') . '>' . e(ltrim($c, '#')) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach (array_values($r) as $i => $c) $h .= '<td' . (substr($head[$i] ?? '', 0, 1) === '#' ? ' class="num"' : '') . '>' . $c . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}
function mis_csv(string $nama, array $head, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nama . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $head, ';', '"', '\\');
    foreach ($rows as $r) fputcsv($o, array_values($r), ';', '"', '\\');
    fclose($o);
    exit;
}

/* ---------- Ringkasan angka tiap modul ---------- */
function mis_tps(string $d, string $s): array
{
    $r = row('SELECT COALESCE(SUM(jumlah_transaksi),0) trx, COALESCE(SUM(omzet),0) omzet, COALESCE(SUM(barang_terjual),0) item
              FROM mis_penjualan_harian WHERE tanggal BETWEEN ? AND ?', [$d, $s]);
    return ['trx' => (int) $r['trx'], 'omzet' => (float) $r['omzet'], 'item' => (int) $r['item'],
        'aov' => $r['trx'] ? $r['omzet'] / $r['trx'] : 0.0, 'ipt' => $r['trx'] ? $r['item'] / $r['trx'] : 0.0];
}
/** Deret harian lengkap (hari tanpa transaksi diisi nol). */
function mis_seri(string $d, string $s): array
{
    $map = [];
    foreach (rows('SELECT * FROM mis_penjualan_harian WHERE tanggal BETWEEN ? AND ?', [$d, $s]) as $r) $map[$r['tanggal']] = $r;
    $out = [];
    for ($t = strtotime($d); $t <= strtotime($s); $t += 86400) {
        $k = date('Y-m-d', $t);
        $out[$k] = $map[$k] ?? ['tanggal' => $k, 'jumlah_transaksi' => 0, 'omzet' => 0, 'rata_belanja' => 0, 'barang_terjual' => 0, 'omzet_tunai' => 0, 'omzet_debit' => 0, 'omzet_qris' => 0];
    }
    return $out;
}
function mis_oas(string $d, string $s): array
{
    $r = row("SELECT COUNT(*) jatuh, COALESCE(SUM(status='selesai'),0) selesai,
                     COALESCE(SUM(status='selesai' AND DATE(selesai_pada)<=tenggat),0) tepat
              FROM tugas WHERE tenggat BETWEEN ? AND ?", [$d, $s]);
    $lama = val("SELECT AVG(TIMESTAMPDIFF(HOUR,dibuat,selesai_pada)) FROM tugas WHERE status='selesai' AND DATE(selesai_pada) BETWEEN ? AND ?", [$d, $s]);
    return [
        'jatuh' => (int) $r['jatuh'], 'selesai' => (int) $r['selesai'], 'tepat' => (int) $r['tepat'],
        'rate' => rasio($r['selesai'], $r['jatuh']), 'tepat_rate' => rasio($r['tepat'], $r['jatuh']),
        'lama' => $lama === null ? null : (float) $lama,
        'dibuat' => (int) val('SELECT COUNT(*) FROM tugas WHERE DATE(dibuat) BETWEEN ? AND ?', [$d, $s]),
        'aktif' => (int) val("SELECT COUNT(*) FROM tugas WHERE status!='selesai'"),
        'telat' => (int) val("SELECT COUNT(*) FROM tugas WHERE status!='selesai' AND tenggat<CURDATE()"),
    ];
}
function mis_kms(string $d, string $s): array
{
    $f = row('SELECT COUNT(*) n, COALESCE(SUM(membantu=1),0) ya FROM feedback_kms WHERE DATE(dibuat) BETWEEN ? AND ?', [$d, $s]);
    return [
        'fb' => (int) $f['n'], 'ya' => (int) $f['ya'], 'helpful' => rasio($f['ya'], $f['n']),
        'baru' => (int) val('SELECT COUNT(*) FROM artikel_kms WHERE DATE(dibuat) BETWEEN ? AND ?', [$d, $s]),
        'published' => (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='published'"),
        'menunggu' => (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='menunggu_review'"),
        'ditolak' => (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='ditolak'"),
        'dilihat' => (int) val("SELECT COALESCE(SUM(dilihat),0) FROM artikel_kms WHERE status='published'"),
    ];
}
function lama_teks(?float $jam): string
{
    if ($jam === null) return '–';
    return $jam >= 48 ? number_format($jam / 24, 1, ',', '.') . ' hari' : number_format($jam, 0, ',', '.') . ' jam';
}

/* ---------- Peringatan lintas modul ---------- */
function mis_alerts(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $a = [];
    $add = function (string $level, string $modul, string $judul, string $detail, string $href) use (&$a) {
        $a[] = compact('level', 'modul', 'judul', 'detail', 'href');
    };
    // ---- TPS ----
    foreach (rows('SELECT nama,ikon,sku FROM produk WHERE aktif=1 AND stok=0 ORDER BY nama') as $p)
        $add('kritis', 'TPS', 'Stok habis: ' . $p['ikon'] . ' ' . $p['nama'], 'Produk ' . $p['sku'] . ' tidak bisa dijual sampai direstok.', 'tps/produk.php?f=habis');
    foreach (rows('SELECT nama,ikon,stok,satuan FROM produk WHERE aktif=1 AND stok>0 AND stok<? ORDER BY stok', [STOK_MIN]) as $p)
        $add('perhatian', 'TPS', 'Stok menipis: ' . $p['ikon'] . ' ' . $p['nama'], 'Tersisa ' . $p['stok'] . ' ' . $p['satuan'] . ' (batas minimum ' . STOK_MIN . ').', 'tps/produk.php?f=menipis');
    $o1 = (float) val('SELECT COALESCE(SUM(total),0) FROM transaksi WHERE DATE(waktu) BETWEEN CURDATE()-INTERVAL 6 DAY AND CURDATE()');
    $o0 = (float) val('SELECT COALESCE(SUM(total),0) FROM transaksi WHERE DATE(waktu) BETWEEN CURDATE()-INTERVAL 13 DAY AND CURDATE()-INTERVAL 7 DAY');
    if ($o0 > 0 && $o1 < $o0 * 0.9)
        $add('perhatian', 'TPS', 'Omzet 7 hari terakhir turun ' . persen(($o0 - $o1) / $o0 * 100), ringkas($o1) . ' dibanding ' . ringkas($o0) . ' pada 7 hari sebelumnya.', 'mis/penjualan.php?p=7');
    $mati = (int) val('SELECT COUNT(*) FROM produk p WHERE p.aktif=1 AND p.stok>0 AND NOT EXISTS(SELECT 1 FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi
                       WHERE d.id_produk=p.id AND t.waktu>=CURDATE()-INTERVAL 14 DAY) AND EXISTS(SELECT 1 FROM transaksi)');
    if ($mati) $add('info', 'TPS', $mati . ' produk tidak terjual dalam 14 hari', 'Pertimbangkan promo atau pindahkan posisi display.', 'mis/produk.php?p=30');
    // ---- OAS ----
    foreach (rows("SELECT t.judul,t.tenggat,DATEDIFF(CURDATE(),t.tenggat) telat,p.nama FROM tugas t JOIN pegawai p ON p.id=t.id_staff
                   WHERE t.status!='selesai' AND t.tenggat<CURDATE() ORDER BY t.tenggat") as $t)
        $add($t['telat'] > 3 ? 'kritis' : 'perhatian', 'OAS', 'Tugas terlambat ' . $t['telat'] . ' hari: ' . $t['judul'], 'Ditugaskan ke ' . $t['nama'] . ', tenggat ' . tgl($t['tenggat'], false) . '.', 'oas/tugas.php');
    foreach (rows("SELECT p.nama, COUNT(*) n FROM tugas t JOIN pegawai p ON p.id=t.id_staff WHERE t.status!='selesai' GROUP BY p.id,p.nama HAVING n>=4") as $w)
        $add('perhatian', 'OAS', 'Beban kerja tinggi: ' . $w['nama'], $w['n'] . ' tugas aktif sekaligus. Pertimbangkan pembagian ulang.', 'mis/oas.php');
    $psn = (int) val('SELECT COUNT(*) FROM pesan WHERE dibaca=0 AND dibuat<NOW()-INTERVAL 3 DAY');
    if ($psn) $add('info', 'OAS', $psn . ' pesan internal belum dibaca lebih dari 3 hari', 'Komunikasi antarpegawai tertunda.', 'mis/oas.php');
    // ---- KMS ----
    foreach (rows("SELECT a.id,a.judul,p.nama,DATEDIFF(CURDATE(),DATE(a.diperbarui)) hari FROM artikel_kms a JOIN pegawai p ON p.id=a.id_penulis
                   WHERE a.status='menunggu_review' AND a.diperbarui<NOW()-INTERVAL 2 DAY ORDER BY a.diperbarui") as $k)
        $add('perhatian', 'KMS', 'Review tertunda ' . $k['hari'] . ' hari: ' . $k['judul'], 'Kontribusi ' . $k['nama'] . ' belum ditinjau.', 'kms/view.php?id=' . $k['id']);
    foreach (rows("SELECT a.id,a.judul,COUNT(*) n,SUM(f.membantu=1) ya FROM feedback_kms f JOIN artikel_kms a ON a.id=f.id_artikel WHERE a.status='published'
                   GROUP BY a.id,a.judul HAVING n>=2 AND ya/n<0.5") as $k)
        $add('perhatian', 'KMS', 'Knowledge dinilai kurang membantu: ' . $k['judul'], 'Hanya ' . $k['ya'] . ' dari ' . $k['n'] . ' pembaca merasa terbantu. Perlu direvisi.', 'kms/view.php?id=' . $k['id']);
    $sepi = (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='published' AND dilihat=0");
    if ($sepi) $add('info', 'KMS', $sepi . ' knowledge published belum pernah dibaca', 'Sosialisasikan lewat pengumuman agar pengetahuan terpakai.', 'mis/kms.php');
    foreach (rows("SELECT k.nama FROM kategori_kms k WHERE k.aktif=1 AND NOT EXISTS(SELECT 1 FROM artikel_kms a WHERE a.id_kategori=k.id AND a.status='published')") as $k)
        $add('info', 'KMS', 'Kategori tanpa knowledge: ' . $k['nama'], 'Belum ada artikel published di kategori ini.', 'kms/index.php');

    $urut = ['kritis' => 0, 'perhatian' => 1, 'info' => 2];
    usort($a, fn($x, $y) => $urut[$x['level']] <=> $urut[$y['level']]);
    return $cache = $a;
}
function alert_item(array $x): string
{
    $ic = ['kritis' => '⛔', 'perhatian' => '⚠️', 'info' => 'ℹ️'][$x['level']];
    $lv = ['kritis' => 'Kritis', 'perhatian' => 'Perhatian', 'info' => 'Info'][$x['level']];
    return '<a class="al ' . $x['level'] . '" href="' . url($x['href']) . '"><span class="al-ic">' . $ic . '</span><div class="al-b"><b>' . e($x['judul']) . '</b><small>' . e($x['detail']) . '</small></div>'
        . '<span class="al-tag"><span class="badge n">' . $x['modul'] . '</span><span class="al-lv">' . $lv . '</span></span></a>';
}

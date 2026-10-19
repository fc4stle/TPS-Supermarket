<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();

/* Perkiraan omzet: rata-rata hari yang sama (Senin dengan Senin, dst.) pada 4 minggu terakhir. */
$hist = [];
foreach (rows('SELECT tanggal, omzet, jumlah_transaksi FROM mis_penjualan_harian WHERE tanggal >= CURDATE() - INTERVAL 70 DAY') as $r) $hist[$r['tanggal']] = [(float) $r['omzet'], (int) $r['jumlah_transaksi']];
$ramal = function (string $tgl) use ($hist): ?array {
    $o = []; $n = [];
    for ($w = 1; $w <= 4; $w++) {
        $k = date('Y-m-d', strtotime($tgl . ' -' . ($w * 7) . ' days'));
        if (isset($hist[$k])) { $o[] = $hist[$k][0]; $n[] = $hist[$k][1]; }
    }
    return $o ? [array_sum($o) / count($o), array_sum($n) / count($n), count($o)] : null;
};
$hariNama = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$cukup = count($hist) >= 14;

$chart = []; $aktual7 = 0;
for ($i = 14; $i >= 1; $i--) {
    $k = date('Y-m-d', strtotime("-$i days")); $v = $hist[$k][0] ?? 0;
    $chart[] = [date('j/n', strtotime($k)), $v];
    if ($i <= 7) $aktual7 += $v;
}
$depan = []; $total7 = 0; $trx7 = 0;
for ($i = 1; $i <= 7; $i++) {
    $k = date('Y-m-d', strtotime("+$i days")); $f = $cukup ? $ramal($k) : null;
    $depan[] = ['tgl' => $k, 'omzet' => $f[0] ?? 0, 'trx' => $f[1] ?? 0, 'n' => $f[2] ?? 0];
    $chart[] = [date('j/n', strtotime($k)), $f[0] ?? 0, 'fc'];
    $total7 += $f[0] ?? 0; $trx7 += $f[1] ?? 0;
}
$puncak = $depan; usort($puncak, fn($a, $b) => $b['omzet'] <=> $a['omzet']);

// uji akurasi: ramalkan 7 hari terakhir dengan data sebelum hari itu, bandingkan dengan aktual
$err = [];
for ($i = 7; $i >= 1; $i--) {
    $k = date('Y-m-d', strtotime("-$i days")); $f = $ramal($k); $a = $hist[$k][0] ?? 0;
    if ($f && $a > 0) $err[] = abs($f[0] - $a) / $a * 100;
}
$mape = $err ? array_sum($err) / count($err) : null;

// perkiraan stok habis: stok ÷ rata-rata terjual per hari (14 hari terakhir)
$stok = rows("SELECT p.id, p.sku, p.nama, p.ikon, p.kategori, p.satuan, p.stok, p.harga,
        COALESCE((SELECT SUM(d.qty) FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE d.id_produk=p.id AND DATE(t.waktu) BETWEEN CURDATE()-INTERVAL 14 DAY AND CURDATE()-INTERVAL 1 DAY),0) j14
    FROM produk p WHERE p.aktif=1");
$list = [];
foreach ($stok as $p) {
    $rate = $p['j14'] / 14;
    if ($rate <= 0) continue;
    $sisa = $p['stok'] / $rate;
    $butuh = (int) ceil($rate * 14);
    $saran = max(0, $butuh - (int) $p['stok']);
    $list[] = $p + ['rate' => $rate, 'sisa' => $sisa, 'saran' => $saran ? (int) (ceil($saran / 6) * 6) : 0];
}
usort($list, fn($a, $b) => $a['sisa'] <=> $b['sisa']);
$habis7 = count(array_filter($list, fn($p) => $p['sisa'] <= 7));

if (get('export') === 'csv') {
    mis_csv('mis-perkiraan-stok', ['SKU', 'Produk', 'Stok', 'Rata-rata Terjual per Hari', 'Perkiraan Hari Tersisa', 'Perkiraan Tanggal Habis', 'Saran Restok'],
        array_map(fn($p) => [$p['sku'], $p['nama'], $p['stok'], round($p['rate'], 2), round($p['sisa'], 1), date('Y-m-d', strtotime('+' . (int) floor($p['sisa']) . ' days')), $p['saran']], $list));
}

mis_start('Perkiraan', 'mis-perkiraan', 'Perkiraan omzet 7 hari ke depan dan kapan stok akan habis, dihitung dari pola penjualan terakhir.');
?>
<div class="alert info"><span>🔮</span><div>Angka di halaman ini adalah <b>perkiraan</b>, bukan kepastian. Omzet diperkirakan dari rata-rata hari yang sama pada 4 minggu terakhir; stok dari rata-rata penjualan 14 hari terakhir.</div></div>

<?php if (!$cukup): ?>
  <div class="card"><?= empty_state('🔮', 'Data belum cukup untuk membuat perkiraan', 'Dibutuhkan riwayat penjualan minimal 14 hari. Saat ini baru ada ' . count($hist) . ' hari.') ?></div>
<?php else: ?>
<div class="kpis">
  <?= kpi('Perkiraan Omzet 7 Hari', ringkas($total7), '🔮', delta($total7, $aktual7), '7 hari terakhir ' . ringkas($aktual7), 'g') ?>
  <?= kpi('Perkiraan Transaksi', angka(round($trx7)), '🧾', '', number_format($trx7 / 7, 1, ',', '.') . ' per hari', 'y') ?>
  <?= kpi('Hari Teramai', $hariNama[(int) date('w', strtotime($puncak[0]['tgl']))], '📅', '', tgl($puncak[0]['tgl'], false) . ' · ' . ringkas($puncak[0]['omzet']), 'b') ?>
  <?= kpi('Stok Habis ≤ 7 Hari', (string) $habis7, '⏳', '', 'produk perlu direstok', $habis7 ? 'r' : 'g') ?>
  <?= kpi('Selisih Rata-rata', $mape === null ? '–' : persen($mape), '🎯', '', 'perkiraan vs aktual 7 hari terakhir', 'p') ?>
</div>

<div class="grid g-23">
  <section class="card panel"><div class="card-h"><h3>📈 Omzet: 14 Hari Terakhir dan Perkiraan 7 Hari ke Depan</h3></div>
  <?= kolom($chart, true, 330, '<div class="legend" style="margin-top:10px"><span><i class="c-g"></i>Aktual</span><span><i class="fc"></i>Perkiraan</span></div>') ?>
  <?= sumber('mis_penjualan_harian') ?>
</section>
  <section class="card panel"><div class="card-h"><h3>🗓️ Rincian Perkiraan Harian</h3></div>
    <?= tabel(['Hari', '#Omzet', '#Transaksi'], array_map(fn($x) => ['<b>' . $hariNama[(int) date('w', strtotime($x['tgl']))] . '</b><br><small>' . tgl($x['tgl'], false) . ' · dari ' . $x['n'] . ' minggu</small>', ringkas($x['omzet']), '± ' . number_format($x['trx'], 0, ',', '.')], $depan)) ?>
    <?= sumber('mis_penjualan_harian') ?>
  </section>
</div>

<section class="card panel"><div class="card-h"><h3>⏳ Perkiraan Stok Habis &amp; Saran Restok</h3><a class="btn sm y no-print" href="?export=csv">⬇️ CSV</a></div>
    <?= tabel(['Produk', '#Stok', '#Terjual/hari', '#Habis dalam', 'Perkiraan tanggal', '#Saran restok'], array_map(fn($p) => [
        '<b>' . e($p['ikon'] . ' ' . $p['nama']) . '</b><br><small>' . e($p['sku']) . '</small>', '<span class="' . ($p['stok'] < STOK_MIN ? 'low' : '') . '">' . angka($p['stok']) . '</span>', number_format($p['rate'], 1, ',', '.'),
        $p['stok'] == 0 ? '<b class="low">sudah habis</b>' : '<b class="' . ($p['sisa'] <= 7 ? 'low' : '') . '">± ' . number_format($p['sisa'], 0, ',', '.') . ' hari</b>',
        $p['stok'] == 0 ? '–' : tgl(date('Y-m-d', strtotime('+' . (int) floor($p['sisa']) . ' days')), false),
        $p['saran'] ? '<b>+' . angka($p['saran']) . '</b> ' . e($p['satuan']) : badge('cukup', 'g')], array_slice($list, 0, 12)), 'Belum ada produk dengan penjualan dalam 14 hari terakhir') ?>
    <p class="hint">Saran restok = kebutuhan 14 hari ke depan dikurangi stok saat ini, dibulatkan ke kelipatan 6.</p>
    <?= sumber('produk, detail_transaksi') ?>
  </section>

<?php endif; ?>
<?php mis_end();

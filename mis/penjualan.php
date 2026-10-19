<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];
$W = 'DATE(t.waktu) BETWEEN ? AND ?';

$t = mis_tps($d, $s); $t0 = mis_tps($pd, $ps);
$seri = mis_seri($d, $s);

if (get('export') === 'csv') {
    mis_csv('mis-penjualan-harian', ['Tanggal', 'Transaksi', 'Omzet', 'Rata-rata Belanja', 'Barang Terjual', 'Tunai', 'Debit', 'QRIS'],
        array_map(fn($r) => [$r['tanggal'], $r['jumlah_transaksi'], $r['omzet'], round($r['rata_belanja']), $r['barang_terjual'], $r['omzet_tunai'], $r['omzet_debit'], $r['omzet_qris']], array_values($seri)));
}

// grafik tren: harian bila <= 45 hari, selain itu mingguan
$chart = [];
if ($per['hari'] <= 45) {
    foreach ($seri as $tg => $r) $chart[] = [date('j/n', strtotime($tg)), (float) $r['omzet']];
    $jenis = 'Harian';
} else {
    $mg = [];
    foreach ($seri as $tg => $r) { $k = date('o-W', strtotime($tg)); $mg[$k] = ($mg[$k] ?? ['l' => date('j/n', strtotime($tg)), 'v' => 0]); $mg[$k]['v'] += (float) $r['omzet']; }
    foreach ($mg as $m) $chart[] = ['mgg ' . $m['l'], $m['v']];
    $jenis = 'Mingguan';
}
$aktif = array_filter($seri, fn($r) => $r['omzet'] > 0);
$best = $aktif ? array_reduce($aktif, fn($c, $r) => !$c || $r['omzet'] > $c['omzet'] ? $r : $c) : null;

$kat = rows("SELECT p.kategori, SUM(d.subtotal) omzet, SUM(d.qty) qty FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk p ON p.id=d.id_produk
             WHERE $W GROUP BY p.kategori ORDER BY omzet DESC", [$d, $s]);
$totKat = array_sum(array_column($kat, 'omzet')) ?: 1;
$met = array_column(rows("SELECT t.metode, COUNT(*) n, SUM(t.total) omzet FROM transaksi t WHERE $W GROUP BY t.metode", [$d, $s]), null, 'metode');
$jamMap = array_column(rows("SELECT HOUR(t.waktu) j, COUNT(*) n FROM transaksi t WHERE $W GROUP BY HOUR(t.waktu)", [$d, $s]), 'n', 'j');
$jam = []; for ($j = 7; $j <= 20; $j++) $jam[] = [sprintf('%02d', $j), (int) ($jamMap[$j] ?? 0)];
$maxJam = max(array_column($jam, 1));
foreach ($jam as &$x) if ($maxJam && $x[1] === $maxJam) $x[2] = 'hi';
unset($x);

$hariNama = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$perHari = array_fill(0, 7, ['s' => 0, 'n' => 0]);
foreach ($seri as $tg => $r) { $i = (int) date('N', strtotime($tg)) - 1; $perHari[$i]['s'] += (float) $r['omzet']; $perHari[$i]['n']++; }
$hari = []; foreach ($perHari as $i => $x) $hari[] = [$hariNama[$i], $x['n'] ? $x['s'] / $x['n'] : 0];
$maxHari = max(array_column($hari, 1));
foreach ($hari as &$x) if ($maxHari && $x[1] === $maxHari) $x[2] = 'hi';
unset($x);

$topTrx = rows("SELECT d.nama_produk, p.ikon, SUM(d.qty) qty, SUM(d.subtotal) omzet FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk p ON p.id=d.id_produk
                WHERE $W GROUP BY d.id_produk, d.nama_produk, p.ikon ORDER BY qty DESC LIMIT 5", [$d, $s]);

mis_start('Analitik Penjualan', 'mis-penjualan', 'Omzet, tren, kategori, metode bayar, serta jam dan hari tersibuk — dari modul TPS.', $per, true);
?>
<div class="kpis">
  <?= kpi('Omzet', ringkas($t['omzet']), '💰', delta($t['omzet'], $t0['omzet']), 'sebelumnya ' . ringkas($t0['omzet'])) ?>
  <?= kpi('Jumlah Transaksi', angka($t['trx']), '🧾', delta($t['trx'], $t0['trx']), 'sebelumnya ' . angka($t0['trx']), 'y') ?>
  <?= kpi('Rata-rata Belanja', rp($t['aov']), '🛍️', delta($t['aov'], $t0['aov']), 'sebelumnya ' . rp($t0['aov']), 'b') ?>
  <?= kpi('Barang per Transaksi', number_format($t['ipt'], 1, ',', '.'), '🧺', delta($t['ipt'], $t0['ipt']), angka($t['item']) . ' unit terjual', 'o') ?>
  <?= kpi('Hari Terbaik', $best ? ringkas($best['omzet']) : '–', '🏅', '', $best ? tgl_panjang($best['tanggal']) : 'belum ada penjualan', 'p') ?>
</div>

<?= panel('📈 Tren Omzet ' . $jenis, $t['trx'] ? kolom($chart, true, 250) : empty_state('📉', 'Belum ada penjualan pada periode ini'), 'mis_penjualan_harian (agregasi dari transaksi)') ?>

<div class="grid g2">
  <?= panel('🧺 Omzet per Kategori Produk', hbars(array_map(fn($k) => [e($k['kategori']), $k['omzet'], ringkas($k['omzet']), persen($k['omzet'] / $totKat * 100) . ' dari omzet · ' . angka($k['qty']) . ' unit'], $kat)), 'detail_transaksi, produk') ?>
  <section class="card panel"><div class="card-h"><h3>💳 Metode Pembayaran</h3><small>berdasarkan nominal</small></div>
    <?= stack([['Tunai', (float) ($met['tunai']['omzet'] ?? 0), 'c-b'], ['QRIS', (float) ($met['qris']['omzet'] ?? 0), 'c-o'], ['Debit', (float) ($met['debit']['omzet'] ?? 0), 'c-a']], 'rupiah') ?>
    <?= tabel(['Metode', '#Transaksi', '#Nominal', '#Rata-rata'], array_map(fn($m) => [status_badge($m), angka($met[$m]['n'] ?? 0), rp($met[$m]['omzet'] ?? 0), rp(($met[$m]['n'] ?? 0) ? $met[$m]['omzet'] / $met[$m]['n'] : 0)], ['tunai', 'qris', 'debit'])) ?>
    <?= sumber('transaksi') ?>
  </section>
</div>

<div class="grid g2">
  <?= panel('🕐 Jam Tersibuk <small>(jumlah transaksi per jam)</small>', $t['trx'] ? kolom($jam, false, 170) : empty_state('🕐', 'Belum ada data'), 'transaksi.waktu') ?>
  <?= panel('📅 Hari Tersibuk <small>(rata-rata omzet per hari)</small>', $t['trx'] ? kolom($hari, true, 170) : empty_state('📅', 'Belum ada data'), 'mis_penjualan_harian') ?>
</div>

<div class="grid g-23">
  <section class="card panel"><div class="card-h"><h3>🗓️ Rincian Harian</h3><small><?= count($aktif) ?> hari ada transaksi</small></div>
    <?= tabel(['Tanggal', '#Transaksi', '#Omzet', '#Rata-rata', '#Barang'], array_map(fn($r) => [tgl_panjang($r['tanggal']), angka($r['jumlah_transaksi']), rp($r['omzet']), rp($r['rata_belanja']), angka($r['barang_terjual'])],
        array_slice(array_reverse(array_values($aktif)), 0, 10)), 'Belum ada transaksi pada periode ini') ?>
    <p class="hint">Menampilkan 10 hari terakhir. Unduh CSV untuk seluruh periode.</p>
    <?= sumber('mis_penjualan_harian') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>🔥 Paling Banyak Terjual</h3><a href="<?= url('mis/produk.php') . e(qs(['export' => null])) ?>">Analitik produk →</a></div>
    <?php if (!$topTrx) echo empty_state('🛒', 'Belum ada penjualan');
    foreach ($topTrx as $i => $p) echo '<div class="rank"><span class="no">' . ($i + 1) . '</span><div class="rb"><b>' . e($p['ikon'] . ' ' . $p['nama_produk']) . '</b><small>' . ringkas($p['omzet']) . '</small></div><span class="rv">' . angka($p['qty']) . ' unit</span></div>'; ?>
    <?= sumber('detail_transaksi') ?>
  </section>
</div>
<?php mis_end();

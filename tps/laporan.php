<?php
require __DIR__ . '/../includes/app.php';
need('manajer', 'admin');

$dari   = get('dari') ?: date('Y-m-01');
$sampai = get('sampai') ?: date('Y-m-d');
if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari];
$p = [$dari, $sampai];
$W = 'DATE(t.waktu) BETWEEN ? AND ?';

/* ---- Ekspor CSV (bisa dibuka di Excel) ---- */
if (get('export') === 'csv') {
    $data = rows("SELECT t.nota,t.waktu,p.nama kasir,t.metode,t.total,t.bayar,t.kembali FROM transaksi t JOIN pegawai p ON p.id=t.id_kasir WHERE $W ORDER BY t.waktu", $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-penjualan-' . $dari . '-sd-' . $sampai . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No. Nota', 'Waktu', 'Kasir', 'Metode', 'Total', 'Bayar', 'Kembali'], ';', '"', '\\');
    foreach ($data as $r) fputcsv($out, [$r['nota'], $r['waktu'], $r['kasir'], strtoupper($r['metode']), $r['total'], $r['bayar'], $r['kembali']], ';', '"', '\\');
    fclose($out);
    exit;
}

$sum   = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM transaksi t WHERE $W", $p);
$items = (int) val("SELECT COALESCE(SUM(d.qty),0) FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE $W", $p);

// grafik: harian bila rentang <= 31 hari, selain itu bulanan
$hari = (int) ((strtotime($sampai) - strtotime($dari)) / 86400) + 1;
$chart = [];
if ($hari <= 31) {
    $map = array_column(rows("SELECT DATE(t.waktu) k, SUM(t.total) v FROM transaksi t WHERE $W GROUP BY DATE(t.waktu)", $p), 'v', 'k');
    for ($d = strtotime($dari); $d <= strtotime($sampai); $d += 86400) $chart[] = [date('j/n', $d), (float) ($map[date('Y-m-d', $d)] ?? 0)];
} else {
    $map = array_column(rows("SELECT DATE_FORMAT(t.waktu,'%Y-%m') k, SUM(t.total) v FROM transaksi t WHERE $W GROUP BY k", $p), 'v', 'k');
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    for ($m = strtotime(date('Y-m-01', strtotime($dari))); $m <= strtotime($sampai); $m = strtotime('+1 month', $m))
        $chart[] = [$bln[(int) date('n', $m)] . ' ' . date('y', $m), (float) ($map[date('Y-m', $m)] ?? 0)];
}

$terlaris = rows("SELECT d.nama_produk, pr.ikon, SUM(d.qty) qty, SUM(d.subtotal) omzet FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi
                  JOIN produk pr ON pr.id=d.id_produk WHERE $W GROUP BY d.id_produk,d.nama_produk,pr.ikon ORDER BY qty DESC LIMIT 10", $p);
$metode   = rows("SELECT t.metode, COUNT(*) n, SUM(t.total) s FROM transaksi t WHERE $W GROUP BY t.metode ORDER BY s DESC", $p);
$kategori = rows("SELECT pr.kategori, SUM(d.subtotal) s FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk pr ON pr.id=d.id_produk WHERE $W GROUP BY pr.kategori ORDER BY s DESC", $p);
$kasir    = rows("SELECT g.nama, COUNT(*) n, SUM(t.total) s FROM transaksi t JOIN pegawai g ON g.id=t.id_kasir WHERE $W GROUP BY g.id,g.nama ORDER BY s DESC", $p);

page_start('Laporan Penjualan', 'laporan', 'Periode ' . tgl($dari, false) . ' – ' . tgl($sampai, false));
?>
<form class="card row no-print" method="get">
  <div><label>Dari tanggal</label><input type="date" name="dari" value="<?= e($dari) ?>"></div>
  <div><label>Sampai tanggal</label><input type="date" name="sampai" value="<?= e($sampai) ?>"></div>
  <div class="fit"><button type="submit">📊 Tampilkan</button></div>
  <div class="fit chips">
    <a class="chip" href="?dari=<?= date('Y-m-d') ?>&sampai=<?= date('Y-m-d') ?>">Hari ini</a>
    <a class="chip" href="?dari=<?= date('Y-m-d', strtotime('-6 days')) ?>&sampai=<?= date('Y-m-d') ?>">7 hari</a>
    <a class="chip" href="?dari=<?= date('Y-m-01') ?>&sampai=<?= date('Y-m-d') ?>">Bulan ini</a>
    <a class="chip" href="?dari=<?= date('Y-01-01') ?>&sampai=<?= date('Y-m-d') ?>">Tahun ini</a>
  </div>
  <div class="fit actions">
    <a class="btn y" href="<?= e(qs(['export' => 'csv'])) ?>">⬇️ Ekspor CSV</a>
    <button type="button" class="ghost" onclick="window.print()">🖨️ Cetak</button>
  </div>
</form>

<div class="stats">
  <?= stat_card('Total Penjualan', rp($sum['s']), '💰', 'g') ?>
  <?= stat_card('Jumlah Transaksi', angka($sum['n']), '🧾', 'y') ?>
  <?= stat_card('Rata-rata Belanja', rp($sum['n'] ? $sum['s'] / $sum['n'] : 0), '🛍️', 'b') ?>
  <?= stat_card('Barang Terjual', angka($items), '📦', 'o') ?>
</div>

<div class="card">
  <div class="card-h"><h3>📈 Tren Penjualan <?= $hari <= 31 ? 'Harian' : 'Bulanan' ?></h3></div>
  <?= $sum['n'] ? bar_chart($chart) : empty_state('📉', 'Belum ada penjualan pada periode ini', 'Grafik tampil setelah ada transaksi dari kasir.') ?>
</div>

<div class="grid g-side">
  <div class="card tight">
    <div class="card-h"><h3>🏆 10 Produk Terlaris</h3></div>
    <div class="table-wrap" style="margin-top:12px">
    <?php if (!$terlaris): echo empty_state('🛒', 'Belum ada produk terjual');
    else: ?><table><thead><tr><th>#</th><th>Produk</th><th class="num">Terjual</th><th class="num">Omzet</th></tr></thead><tbody>
      <?php foreach ($terlaris as $i => $t): ?><tr><td><?= ['🥇', '🥈', '🥉'][$i] ?? $i + 1 ?></td><td><?= e($t['ikon'] . ' ' . $t['nama_produk']) ?></td><td class="num"><?= angka($t['qty']) ?></td><td class="num"><?= rp($t['omzet']) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
    </div>
  </div>
  <div>
    <div class="card">
      <div class="card-h"><h3>💳 Metode Pembayaran</h3></div>
      <?php if (!$metode): echo empty_state('💳', 'Belum ada data');
      else: $mx = max(array_column($metode, 's')); foreach ($metode as $m): ?>
        <div class="hbar"><span class="hl"><?= strtoupper($m['metode']) ?> <small>(<?= $m['n'] ?>)</small></span><span class="ht"><i style="width:<?= round($m['s'] / $mx * 100) ?>%"></i></span><span class="hv"><?= rp($m['s']) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="card">
      <div class="card-h"><h3>🧺 Per Kategori</h3></div>
      <?php if (!$kategori): echo empty_state('🧺', 'Belum ada data');
      else: $mx = max(array_column($kategori, 's')); foreach ($kategori as $k): ?>
        <div class="hbar"><span class="hl"><?= e($k['kategori']) ?></span><span class="ht"><i style="width:<?= round($k['s'] / $mx * 100) ?>%"></i></span><span class="hv"><?= rp($k['s']) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="card tight">
  <div class="card-h"><h3>👤 Kinerja Kasir</h3></div>
  <div class="table-wrap" style="margin-top:12px">
  <?php if (!$kasir): echo empty_state('👤', 'Belum ada data kasir');
  else: ?><table><thead><tr><th>Kasir</th><th class="num">Transaksi</th><th class="num">Total Penjualan</th><th class="num">Rata-rata</th></tr></thead><tbody>
    <?php foreach ($kasir as $k): ?><tr><td><b><?= e($k['nama']) ?></b></td><td class="num"><?= angka($k['n']) ?></td><td class="num"><?= rp($k['s']) ?></td><td class="num"><?= rp($k['s'] / $k['n']) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  </div>
</div>
<p class="no-print"><a href="<?= url('tps/transaksi.php?dari=' . $dari . '&sampai=' . $sampai) ?>">🗂️ Lihat daftar transaksi periode ini →</a></p>
<?php page_end();

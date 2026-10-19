<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];

// kinerja semua produk aktif pada periode ini dan periode pembanding
$sql = "SELECT p.id, p.sku, p.nama, p.ikon, p.kategori, p.harga, p.stok,
          COALESCE((SELECT SUM(d.qty) FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE d.id_produk=p.id AND DATE(t.waktu) BETWEEN ? AND ?),0) qty,
          COALESCE((SELECT SUM(d.subtotal) FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE d.id_produk=p.id AND DATE(t.waktu) BETWEEN ? AND ?),0) omzet,
          COALESCE((SELECT SUM(d.qty) FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE d.id_produk=p.id AND DATE(t.waktu) BETWEEN ? AND ?),0) qty0
        FROM produk p WHERE p.aktif=1 ORDER BY omzet DESC, p.nama";
$prod = rows($sql, [$d, $s, $d, $s, $pd, $ps]);
$total = array_sum(array_column($prod, 'omzet'));
$laku = array_values(array_filter($prod, fn($p) => $p['qty'] > 0));
$mati = array_values(array_filter($prod, fn($p) => $p['qty'] == 0));

if (get('export') === 'csv') {
    mis_csv('mis-kinerja-produk', ['SKU', 'Produk', 'Kategori', 'Harga', 'Stok', 'Terjual', 'Omzet', 'Kontribusi %', 'Terjual periode sebelumnya'],
        array_map(fn($p) => [$p['sku'], $p['nama'], $p['kategori'], $p['harga'], $p['stok'], $p['qty'], $p['omzet'], $total ? round($p['omzet'] / $total * 100, 2) : 0, $p['qty0']], $prod));
}

// Pareto: berapa produk yang menyumbang 80% omzet
$kum = 0; $n80 = 0; $pareto = [];
foreach ($laku as $p) {
    $kum += $p['omzet']; $n80++;
    $pareto[] = [e($p['ikon'] . ' ' . $p['nama']), $p['omzet'], ringkas($p['omzet']), 'kumulatif ' . persen($kum / max(1, $total) * 100)];
    if ($kum >= 0.8 * $total) break;
}
$top5 = array_sum(array_column(array_slice($laku, 0, 5), 'omzet'));
$kat = [];
foreach ($prod as $p) { $k = $p['kategori']; $kat[$k] = $kat[$k] ?? ['omzet' => 0, 'qty' => 0, 'n' => 0]; $kat[$k]['omzet'] += $p['omzet']; $kat[$k]['qty'] += $p['qty']; $kat[$k]['n']++; }
uasort($kat, fn($a, $b) => $b['omzet'] <=> $a['omzet']);
$katTop = $total ? array_key_first($kat) : '–';
$sepi = $laku; usort($sepi, fn($a, $b) => $a['qty'] <=> $b['qty']); $sepi = array_slice($sepi, 0, 5);
$naik = $laku; usort($naik, fn($a, $b) => ($b['qty'] - $b['qty0']) <=> ($a['qty'] - $a['qty0']));

mis_start('Analitik Produk', 'mis-produk', 'Produk terlaris, kontribusi omzet, dan produk yang perlu perhatian — dari modul TPS.', $per, true);
?>
<div class="kpis">
  <?= kpi('Produk Terjual', count($laku) . ' / ' . count($prod), '🛍️', '', 'jenis produk yang laku', 'g') ?>
  <?= kpi('Produk Tidak Terjual', (string) count($mati), '🧊', '', 'tanpa penjualan pada periode', count($mati) ? 'r' : 'g') ?>
  <?= kpi('Kontribusi 5 Teratas', persen(rasio($top5, $total)), '🏆', '', 'dari total omzet', 'y') ?>
  <?= kpi('Penyumbang 80% Omzet', $total ? $n80 . ' produk' : '–', '📐', '', 'aturan Pareto', 'b') ?>
  <?= kpi('Kategori Terlaris', e($katTop), '🧺', '', $total ? ringkas($kat[$katTop]['omzet']) : 'belum ada penjualan', 'o') ?>
</div>

<section class="card panel"><div class="card-h"><h3>🏆 10 Produk Terlaris</h3><small>diurutkan berdasarkan omzet</small></div>
  <?= tabel(['#', 'Produk', 'Kategori', '#Terjual', '#Omzet', '#Kontribusi', 'vs sebelumnya'], array_map(fn($p, $i) => [
      ['🥇', '🥈', '🥉'][$i] ?? $i + 1, '<b>' . e($p['ikon'] . ' ' . $p['nama']) . '</b><br><small>' . e($p['sku']) . ' · ' . rp($p['harga']) . '</small>', badge($p['kategori'], 'n'),
      angka($p['qty']), rp($p['omzet']), persen(rasio($p['omzet'], $total)), delta($p['qty'], $p['qty0'])], array_slice($laku, 0, 10), array_keys(array_slice($laku, 0, 10))), 'Belum ada produk terjual pada periode ini') ?>
  <?= sumber('detail_transaksi, transaksi, produk') ?>
</section>

<div class="grid g2">
  <?= panel('📐 Produk Penyumbang 80% Omzet', hbars($pareto), 'detail_transaksi', '<small>' . ($total ? $n80 . ' dari ' . count($prod) . ' produk' : '') . '</small>') ?>
  <?= panel('🧺 Kontribusi per Kategori', hbars(array_map(fn($k, $v) => [e($k), $v['omzet'], ringkas($v['omzet']), persen(rasio($v['omzet'], $total)) . ' · ' . $v['n'] . ' produk · ' . angka($v['qty']) . ' unit'], array_keys($kat), $kat)), 'detail_transaksi, produk') ?>
</div>

<div class="grid g3">
  <section class="card panel"><div class="card-h"><h3>📈 Kenaikan Tertinggi</h3><small>unit vs periode sebelumnya</small></div>
    <?php if (!$laku) echo empty_state('📈', 'Belum ada data');
    foreach (array_slice($naik, 0, 5) as $p) echo '<div class="rank"><div class="rb"><b>' . e($p['ikon'] . ' ' . $p['nama']) . '</b><small>' . angka($p['qty0']) . ' → ' . angka($p['qty']) . ' unit</small></div>' . delta($p['qty'], $p['qty0']) . '</div>'; ?>
    <?= sumber('detail_transaksi') ?></section>
  <section class="card panel"><div class="card-h"><h3>🐢 Paling Sepi</h3><small>terjual paling sedikit</small></div>
    <?php if (!$sepi) echo empty_state('🐢', 'Belum ada data');
    foreach ($sepi as $p) echo '<div class="rank"><div class="rb"><b>' . e($p['ikon'] . ' ' . $p['nama']) . '</b><small>' . e($p['kategori']) . ' · stok ' . $p['stok'] . '</small></div><span class="rv">' . angka($p['qty']) . ' unit</span></div>'; ?>
    <?= sumber('detail_transaksi, produk') ?></section>
  <section class="card panel"><div class="card-h"><h3>🧊 Tidak Terjual</h3><small>pada periode ini</small></div>
    <?php if (!$mati) echo empty_state('✅', 'Semua produk terjual');
    foreach (array_slice($mati, 0, 6) as $p) echo '<div class="rank"><div class="rb"><b>' . e($p['ikon'] . ' ' . $p['nama']) . '</b><small>' . e($p['kategori']) . ' · stok ' . $p['stok'] . ' · nilai ' . ringkas($p['stok'] * $p['harga']) . '</small></div>' . badge($p['stok'] > 0 ? 'stok mengendap' : 'stok kosong', $p['stok'] > 0 ? 'y' : 'r') . '</div>'; ?>
    <?= sumber('produk, detail_transaksi') ?></section>
</div>
<?php mis_end();

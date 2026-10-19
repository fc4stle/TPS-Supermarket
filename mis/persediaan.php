<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];
$hari = max(1, $per['hari']);

$prod = rows("SELECT p.*, COALESCE((SELECT SUM(d.qty) FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE d.id_produk=p.id AND DATE(t.waktu) BETWEEN ? AND ?),0) terjual,
              (SELECT MAX(l.waktu) FROM stok_log l WHERE l.id_produk=p.id AND l.jenis='restok') restok_akhir
              FROM produk p WHERE p.aktif=1", [$d, $s]);
foreach ($prod as &$p) {
    $p['per_hari'] = $p['terjual'] / $hari;
    $p['sisa_hari'] = $p['per_hari'] > 0 ? $p['stok'] / $p['per_hari'] : null;
    $p['putar'] = $p['terjual'] / max(1, $p['stok']);   // unit terjual pada periode ÷ stok saat ini
    $p['kelas'] = $p['terjual'] == 0 ? 'Tidak bergerak' : ($p['sisa_hari'] <= 14 ? 'Cepat' : ($p['sisa_hari'] <= 45 ? 'Normal' : 'Lambat'));
    $p['nilai'] = $p['stok'] * $p['harga'];
}
unset($p);
usort($prod, fn($a, $b) => ($a['sisa_hari'] ?? 9e9) <=> ($b['sisa_hari'] ?? 9e9));

if (get('export') === 'csv') {
    mis_csv('mis-persediaan', ['SKU', 'Produk', 'Kategori', 'Stok', 'Nilai Persediaan', 'Terjual', 'Rata-rata per Hari', 'Perputaran', 'Perkiraan Hari Tersisa', 'Kelas'],
        array_map(fn($p) => [$p['sku'], $p['nama'], $p['kategori'], $p['stok'], $p['nilai'], $p['terjual'], round($p['per_hari'], 2), round($p['putar'], 2), $p['sisa_hari'] === null ? '' : round($p['sisa_hari'], 1), $p['kelas']], $prod));
}

$nilai = array_sum(array_column($prod, 'nilai'));
$menipis = count(array_filter($prod, fn($p) => $p['stok'] > 0 && $p['stok'] < STOK_MIN));
$habis = count(array_filter($prod, fn($p) => $p['stok'] == 0));
$terjual = array_sum(array_column($prod, 'terjual'));
$unit = array_sum(array_column($prod, 'stok'));
$kelas = array_count_values(array_column($prod, 'kelas'));

$gerak = array_column(rows("SELECT jenis, SUM(perubahan) n, COUNT(*) c FROM stok_log WHERE DATE(waktu) BETWEEN ? AND ? AND jenis!='awal' GROUP BY jenis", [$d, $s]), null, 'jenis');
$gerak0 = array_column(rows("SELECT jenis, SUM(perubahan) n FROM stok_log WHERE DATE(waktu) BETWEEN ? AND ? AND jenis!='awal' GROUP BY jenis", [$pd, $ps]), 'n', 'jenis');
$masuk = (int) ($gerak['restok']['n'] ?? 0); $keluar = abs((int) ($gerak['penjualan']['n'] ?? 0)); $koreksi = (int) ($gerak['koreksi']['n'] ?? 0);

$kat = [];
foreach ($prod as $p) { $kat[$p['kategori']] = $kat[$p['kategori']] ?? ['nilai' => 0, 'unit' => 0, 'n' => 0]; $kat[$p['kategori']]['nilai'] += $p['nilai']; $kat[$p['kategori']]['unit'] += $p['stok']; $kat[$p['kategori']]['n']++; }
uasort($kat, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
$restok = rows("SELECT l.waktu, l.perubahan, l.keterangan, p.nama, p.ikon, g.nama oleh FROM stok_log l JOIN produk p ON p.id=l.id_produk LEFT JOIN pegawai g ON g.id=l.id_pegawai
                WHERE l.jenis IN ('restok','koreksi') AND DATE(l.waktu) BETWEEN ? AND ? ORDER BY l.waktu DESC LIMIT 8", [$d, $s]);

$warnaKelas = ['Cepat' => 'g', 'Normal' => 'b', 'Lambat' => 'y', 'Tidak bergerak' => 'r'];
mis_start('Analitik Persediaan', 'mis-persediaan', 'Nilai persediaan, perputaran stok, dan pergerakan barang — dari modul TPS.', $per, true);
?>
<div class="kpis">
  <?= kpi('Nilai Persediaan', ringkas($nilai), '💰', '', angka($unit) . ' unit di ' . count($prod) . ' produk', 'g') ?>
  <?= kpi('Stok Menipis', (string) $menipis, '⚠️', '', 'di bawah ' . STOK_MIN . ' unit', $menipis ? 'y' : 'g', 'tps/produk.php?f=menipis') ?>
  <?= kpi('Stok Habis', (string) $habis, '🚫', '', 'tidak bisa dijual', $habis ? 'r' : 'g', 'tps/produk.php?f=habis') ?>
  <?= kpi('Unit Terjual', angka($terjual), '📤', delta($keluar, abs((int) ($gerak0['penjualan'] ?? 0))), number_format($terjual / $hari, 1, ',', '.') . ' unit per hari', 'b') ?>
  <?= kpi('Unit Masuk (Restok)', angka($masuk), '📥', delta($masuk, (int) ($gerak0['restok'] ?? 0)), ($gerak['restok']['c'] ?? 0) . ' kali restok', 'o') ?>
</div>

<div class="grid g2">
  <section class="card panel"><div class="card-h"><h3>🔄 Pergerakan Stok</h3><small>unit pada periode ini</small></div>
    <?= hbars([['📥 Masuk (restok)', $masuk, angka($masuk) . ' unit', ($gerak['restok']['c'] ?? 0) . ' catatan'], ['📤 Keluar (penjualan)', $keluar, angka($keluar) . ' unit', ($gerak['penjualan']['c'] ?? 0) . ' catatan'],
        ['🧮 Koreksi (rusak/selisih)', abs($koreksi), ($koreksi > 0 ? '+' : '') . angka($koreksi) . ' unit', ($gerak['koreksi']['c'] ?? 0) . ' catatan']], 'b') ?>
    <div class="mini"><div><small>Selisih masuk − keluar</small><b><?= ($masuk - $keluar + $koreksi >= 0 ? '+' : '') . angka($masuk - $keluar + $koreksi) ?> unit</b></div>
      <div><small>Rasio keluar ÷ masuk</small><b><?= $masuk ? number_format($keluar / $masuk, 2, ',', '.') : '–' ?></b></div></div>
    <?= sumber('stok_log') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>⚡ Kelas Perputaran Stok</h3><small><?= count($prod) ?> produk aktif</small></div>
    <?= stack([['Cepat', $kelas['Cepat'] ?? 0, 'c-g'], ['Normal', $kelas['Normal'] ?? 0, 'c-b'], ['Lambat', $kelas['Lambat'] ?? 0, 'c-y'], ['Tidak bergerak', $kelas['Tidak bergerak'] ?? 0, 'c-r']], 'produk') ?>
    <p class="hint" style="margin-top:12px">Perputaran = unit terjual pada periode ÷ stok saat ini. Kelas ditentukan dari ketahanan stok: <b>Cepat</b> habis ≤ 14 hari · <b>Normal</b> 15–45 hari · <b>Lambat</b> &gt; 45 hari · <b>Tidak bergerak</b> = tidak ada penjualan.</p>
    <?= sumber('produk, detail_transaksi') ?>
  </section>
</div>

<section class="card panel"><div class="card-h"><h3>📦 Perputaran &amp; Ketahanan Stok per Produk</h3><small>diurutkan dari yang paling cepat habis</small></div>
  <?= tabel(['Produk', 'Kategori', '#Stok', '#Terjual', '#Rata-rata/hari', '#Perputaran', '#Perkiraan habis', 'Kelas'], array_map(fn($p) => [
      '<b>' . e($p['ikon'] . ' ' . $p['nama']) . '</b><br><small>' . e($p['sku']) . '</small>', badge($p['kategori'], 'n'),
      '<span class="' . ($p['stok'] < STOK_MIN ? 'low' : '') . '">' . angka($p['stok']) . '</span>', angka($p['terjual']), number_format($p['per_hari'], 1, ',', '.'), number_format($p['putar'], 2, ',', '.') . '×',
      $p['stok'] == 0 ? '<span class="low">habis</span>' : ($p['sisa_hari'] === null ? '–' : '<span class="' . ($p['sisa_hari'] < 7 ? 'low' : '') . '">± ' . number_format($p['sisa_hari'], 0, ',', '.') . ' hari</span>'),
      badge($p['kelas'], ['Cepat' => 'g', 'Normal' => 'b', 'Lambat' => 'y', 'Tidak bergerak' => 'r'][$p['kelas']])], array_slice($prod, 0, 15))) ?>
  <p class="hint">Menampilkan 15 produk dengan ketahanan stok terpendek. Unduh CSV untuk seluruh produk.</p>
  <?= sumber('produk, detail_transaksi, transaksi') ?>
</section>

<div class="grid g2">
  <?= panel('🗂️ Nilai Persediaan per Kategori', hbars(array_map(fn($k, $v) => [e($k), $v['nilai'], ringkas($v['nilai']), $v['n'] . ' produk · ' . angka($v['unit']) . ' unit · ' . persen(rasio($v['nilai'], $nilai))], array_keys($kat), $kat)), 'produk') ?>
  <section class="card panel"><div class="card-h"><h3>🕘 Restok &amp; Koreksi Terakhir</h3></div>
    <?= tabel(['Waktu', 'Produk', '#Unit', 'Keterangan', 'Oleh'], array_map(fn($r) => [tgl($r['waktu']), e($r['ikon'] . ' ' . $r['nama']), '<b class="' . ($r['perubahan'] < 0 ? 'low' : '') . '">' . ($r['perubahan'] > 0 ? '+' : '') . $r['perubahan'] . '</b>', e($r['keterangan']), e($r['oleh'] ?? '-')], $restok), 'Belum ada restok atau koreksi pada periode ini') ?>
    <?= sumber('stok_log') ?>
  </section>
</div>
<?php mis_end();

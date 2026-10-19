<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];

$t = mis_tps($d, $s);  $t0 = mis_tps($pd, $ps);
$o = mis_oas($d, $s);  $o0 = mis_oas($pd, $ps);
$k = mis_kms($d, $s);  $k0 = mis_kms($pd, $ps);
$alerts = mis_alerts();
$nKritis = count(array_filter($alerts, fn($a) => $a['level'] === 'kritis'));

$seri = []; $hariAktif = [];
foreach (mis_seri($d, $s) as $tg => $r) {
    $seri[] = [date('j/n', strtotime($tg)), (float) $r['omzet']];
    if ($r['omzet'] > 0) $hariAktif[$tg] = (float) $r['omzet'];
}
$mini = '';
if ($hariAktif) {
    $maxT = array_keys($hariAktif, max($hariAktif))[0]; $minT = array_keys($hariAktif, min($hariAktif))[0];
    $mini = '<div class="mini"><div><small>Hari terbaik</small><b>' . ringkas($hariAktif[$maxT]) . '</b><small>' . tgl($maxT, false) . '</small></div>'
        . '<div><small>Hari terendah</small><b>' . ringkas($hariAktif[$minT]) . '</b><small>' . tgl($minT, false) . '</small></div>'
        . '<div><small>Rata-rata per hari buka</small><b>' . ringkas(array_sum($hariAktif) / count($hariAktif)) . '</b><small>' . count($hariAktif) . ' hari ada transaksi</small></div>'
        . '<div><small>Transaksi per hari</small><b>' . number_format($t['trx'] / count($hariAktif), 1, ',', '.') . '</b><small>rata-rata</small></div></div>';
}

$W = 'DATE(t.waktu) BETWEEN ? AND ?';
$topProduk = rows("SELECT p.ikon, d.nama_produk, SUM(d.qty) qty, SUM(d.subtotal) omzet FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk p ON p.id=d.id_produk
                   WHERE $W GROUP BY d.id_produk, d.nama_produk, p.ikon ORDER BY omzet DESC LIMIT 5", [$d, $s]);
$topKasir = rows("SELECT g.nama, COUNT(*) n, SUM(t.total) omzet FROM transaksi t JOIN pegawai g ON g.id=t.id_kasir WHERE $W GROUP BY g.id, g.nama ORDER BY omzet DESC LIMIT 5", [$d, $s]);
$topKms = rows("SELECT id, judul, dilihat FROM artikel_kms WHERE status='published' ORDER BY dilihat DESC LIMIT 5");
$stokMenipis = (int) val('SELECT COUNT(*) FROM produk WHERE aktif=1 AND stok<?', [STOK_MIN]);
$nilaiStok = (float) val('SELECT COALESCE(SUM(harga*stok),0) FROM produk WHERE aktif=1');

mis_start('Executive Dashboard', 'mis', 'Kinerja perusahaan dalam satu layar — dirangkum dari TPS, OAS, dan KMS.', $per);
?>
<div class="kpis">
  <?= kpi('Omzet', ringkas($t['omzet']), '💰', delta($t['omzet'], $t0['omzet']), 'sebelumnya ' . ringkas($t0['omzet']), 'g', 'mis/penjualan.php' . qs([])) ?>
  <?= kpi('Jumlah Transaksi', angka($t['trx']), '🧾', delta($t['trx'], $t0['trx']), 'sebelumnya ' . angka($t0['trx']), 'y', 'mis/penjualan.php' . qs([])) ?>
  <?= kpi('Rata-rata Belanja', rp($t['aov']), '🛍️', delta($t['aov'], $t0['aov']), 'per transaksi', 'b', 'mis/pelanggan.php' . qs([])) ?>
  <?= kpi('Barang Terjual', angka($t['item']), '📦', delta($t['item'], $t0['item']), 'unit pada periode ini', 'o', 'mis/produk.php' . qs([])) ?>
  <?= kpi('Penyelesaian Tugas', persen($o['rate']), '📋', delta($o['rate'], $o0['rate'], true, true), $o['selesai'] . ' dari ' . $o['jatuh'] . ' tugas jatuh tempo', 'p', 'mis/oas.php' . qs([])) ?>
  <?= kpi('Helpful Rate Knowledge', persen($k['helpful']), '📚', delta($k['helpful'], $k0['helpful'], true, true), $k['ya'] . ' dari ' . $k['fb'] . ' feedback membantu', 'r', 'mis/kms.php' . qs([])) ?>
</div>

<div class="grid g-23">
  <?= panel('📈 Tren Omzet Harian', $t['trx'] ? kolom($seri, true, 300) . $mini : empty_state('📉', 'Belum ada penjualan pada periode ini', 'Grafik terisi setelah kasir mencatat transaksi.'),
        'mis_penjualan_harian (agregasi dari transaksi)', '<a href="' . url('mis/penjualan.php') . e(qs([])) . '">Analitik penjualan →</a>') ?>
  <section class="card panel">
    <div class="card-h"><h3>🚨 Peringatan <?= $alerts ? badge(count($alerts) . ' aktif', $nKritis ? 'r' : 'y') : '' ?></h3><a href="<?= url('mis/peringatan.php') ?>">Lihat semua →</a></div>
    <?php if (!$alerts): echo empty_state('✅', 'Tidak ada peringatan', 'Stok, tugas, dan knowledge dalam kondisi aman.');
    else: foreach (array_slice($alerts, 0, 5) as $a) echo alert_item($a);
      if (count($alerts) > 5) echo '<p class="muted mb0" style="font-size:12.5px">+' . (count($alerts) - 5) . ' peringatan lainnya di Pusat Peringatan.</p>';
    endif; ?>
    <?= sumber('produk, tugas, artikel_kms, feedback_kms, pesan') ?>
  </section>
</div>

<h3 class="section-title">🧩 Ringkasan per Modul</h3>
<div class="grid g3" style="margin-bottom:20px">
  <div class="modul" style="border-top:4px solid var(--g)">
    <h3>🧾 TPS — Transaksi <a href="<?= url('mis/penjualan.php') . e(qs([])) ?>">detail →</a></h3>
    <dl>
      <dt>Omzet periode</dt><dd><?= ringkas($t['omzet']) ?></dd>
      <dt>Rata-rata omzet per hari</dt><dd><?= ringkas($t['omzet'] / max(1, $per['hari'])) ?></dd>
      <dt>Barang per transaksi</dt><dd><?= number_format($t['ipt'], 1, ',', '.') ?></dd>
      <dt>Nilai persediaan saat ini</dt><dd><?= ringkas($nilaiStok) ?></dd>
      <dt>Produk stok menipis / habis</dt><dd class="<?= $stokMenipis ? 'pill-bad' : 'pill-ok' ?>"><?= $stokMenipis ?> produk</dd>
    </dl>
    <?= sumber('transaksi, detail_transaksi, produk') ?>
  </div>
  <div class="modul" style="border-top:4px solid var(--b)">
    <h3>📋 OAS — Perkantoran <a href="<?= url('mis/oas.php') . e(qs([])) ?>">detail →</a></h3>
    <dl>
      <dt>Tugas aktif saat ini</dt><dd><?= $o['aktif'] ?></dd>
      <dt>Tugas terlambat</dt><dd class="<?= $o['telat'] ? 'pill-bad' : 'pill-ok' ?>"><?= $o['telat'] ?></dd>
      <dt>Selesai tepat waktu</dt><dd><?= persen($o['tepat_rate']) ?></dd>
      <dt>Rata-rata lama pengerjaan</dt><dd><?= lama_teks($o['lama']) ?></dd>
      <dt>Tugas dibuat pada periode</dt><dd><?= $o['dibuat'] ?></dd>
    </dl>
    <?= sumber('tugas') ?>
  </div>
  <div class="modul" style="border-top:4px solid var(--o)">
    <h3>📚 KMS — Pengetahuan <a href="<?= url('mis/kms.php') . e(qs([])) ?>">detail →</a></h3>
    <dl>
      <dt>Knowledge published</dt><dd><?= $k['published'] ?></dd>
      <dt>Menunggu review</dt><dd class="<?= $k['menunggu'] ? 'pill-bad' : 'pill-ok' ?>"><?= $k['menunggu'] ?></dd>
      <dt>Total dilihat (kumulatif)</dt><dd><?= angka($k['dilihat']) ?></dd>
      <dt>Knowledge baru pada periode</dt><dd><?= $k['baru'] ?></dd>
      <dt>Feedback diterima</dt><dd><?= $k['fb'] ?></dd>
    </dl>
    <?= sumber('artikel_kms, feedback_kms') ?>
  </div>
</div>

<div class="grid g3">
  <section class="card panel"><div class="card-h"><h3>🏆 Produk Penyumbang Omzet</h3></div>
    <?php if (!$topProduk) echo empty_state('🛒', 'Belum ada penjualan');
    foreach ($topProduk as $i => $p) echo '<div class="rank"><span class="no">' . ($i + 1) . '</span><div class="rb"><b>' . e($p['ikon'] . ' ' . $p['nama_produk']) . '</b><small>' . angka($p['qty']) . ' unit terjual</small></div><span class="rv">' . ringkas($p['omzet']) . '</span></div>'; ?>
    <?= sumber('detail_transaksi, produk') ?></section>
  <section class="card panel"><div class="card-h"><h3>🧑‍💼 Omzet per Kasir</h3></div>
    <?php if (!$topKasir) echo empty_state('👤', 'Belum ada data kasir');
    foreach ($topKasir as $i => $p) echo '<div class="rank"><span class="no">' . ($i + 1) . '</span><div class="rb"><b>' . e($p['nama']) . '</b><small>' . angka($p['n']) . ' transaksi</small></div><span class="rv">' . ringkas($p['omzet']) . '</span></div>'; ?>
    <?= sumber('transaksi, pegawai') ?></section>
  <section class="card panel"><div class="card-h"><h3>👁 Knowledge Paling Dilihat</h3></div>
    <?php if (!$topKms) echo empty_state('📭', 'Belum ada knowledge');
    foreach ($topKms as $i => $p) echo '<div class="rank"><span class="no">' . ($i + 1) . '</span><div class="rb"><b><a href="' . url('kms/view.php?id=' . $p['id']) . '">' . e($p['judul']) . '</a></b></div><span class="rv">' . angka($p['dilihat']) . 'x</span></div>'; ?>
    <?= sumber('artikel_kms') ?></section>
</div>
<?php mis_end();

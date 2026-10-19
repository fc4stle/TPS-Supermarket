<?php
require __DIR__ . '/../includes/app.php';
need('manajer');

$hariIni  = (float) val('SELECT COALESCE(SUM(total),0) FROM transaksi WHERE DATE(waktu)=CURDATE()');
$trxHari  = (int) val('SELECT COUNT(*) FROM transaksi WHERE DATE(waktu)=CURDATE()');
$bulanIni = (float) val("SELECT COALESCE(SUM(total),0) FROM transaksi WHERE DATE_FORMAT(waktu,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
$review   = rows("SELECT a.id,a.judul,a.dibuat,p.nama FROM artikel_kms a JOIN pegawai p ON p.id=a.id_penulis WHERE a.status='menunggu_review' ORDER BY a.dibuat LIMIT 5");
$menipis  = rows('SELECT * FROM produk WHERE aktif=1 AND stok<? ORDER BY stok LIMIT 5', [STOK_MIN]);
$tugas    = rows("SELECT t.*,p.nama FROM tugas t JOIN pegawai p ON p.id=t.id_staff WHERE t.status!='selesai' ORDER BY t.tenggat LIMIT 5");
$terlaris = rows("SELECT d.nama_produk,p.ikon,SUM(d.qty) n FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk p ON p.id=d.id_produk
                  WHERE DATE_FORMAT(t.waktu,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m') GROUP BY d.id_produk,d.nama_produk,p.ikon ORDER BY n DESC LIMIT 5");

page_start('Dashboard Manajer', 'dashboard', 'Ringkasan kinerja TPS, OAS, dan Knowledge Management.');
echo hero('Selamat bekerja, ' . e(explode(' ', me()['nama'])[0]) . '! 🌿', 'Pantau penjualan, koordinasikan tugas staff, dan jaga kualitas knowledge toko.', '📈🛒🥬📋');
?>
<div class="stats">
  <?= stat_card('Penjualan Hari Ini', rp($hariIni), '💰', 'g', 'tps/laporan.php?dari=' . date('Y-m-d') . '&sampai=' . date('Y-m-d'), $trxHari . ' transaksi') ?>
  <?= stat_card('Penjualan Bulan Ini', rp($bulanIni), '📈', 'y', 'tps/laporan.php') ?>
  <?= stat_card('Total Transaksi', angka(val('SELECT COUNT(*) FROM transaksi')), '🧾', 'b', 'tps/transaksi.php') ?>
  <?= stat_card('Tugas Terbuka', angka(val("SELECT COUNT(*) FROM tugas WHERE status!='selesai'")), '📋', 'o', 'oas/tugas.php') ?>
  <?= stat_card('Knowledge Menunggu Review', angka(val("SELECT COUNT(*) FROM artikel_kms WHERE status='menunggu_review'")), '📝', 'r', 'kms/index.php?tab=review') ?>
</div>

<div class="grid g-side">
  <div class="card">
    <div class="card-h"><h3>📊 Penjualan 7 Hari Terakhir</h3><a href="<?= url('tps/laporan.php') ?>">Laporan lengkap →</a></div>
    <?= bar_chart(penjualan_harian(7)) ?>
  </div>
  <div class="card">
    <div class="card-h"><h3>🏆 Terlaris Bulan Ini</h3></div>
    <?php if (!$terlaris): echo empty_state('🛒', 'Belum ada penjualan bulan ini', 'Data muncul setelah kasir mencatat transaksi.');
    else: $mx = max(array_column($terlaris, 'n'));
        foreach ($terlaris as $t): ?>
      <div class="hbar"><span class="hl"><?= e($t['ikon'] . ' ' . $t['nama_produk']) ?></span><span class="ht"><i style="width:<?= round($t['n'] / $mx * 100) ?>%"></i></span><span class="hv"><?= angka($t['n']) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="grid g3">
  <div class="card">
    <div class="card-h"><h3>📝 Perlu Direview</h3><a href="<?= url('kms/index.php?tab=review') ?>">Semua</a></div>
    <?php if (!$review): echo empty_state('🎉', 'Tidak ada antrean review');
    else: ?><div class="list"><?php foreach ($review as $a): ?>
      <div class="li"><span class="li-ic">📄</span><div class="li-body"><b><a href="<?= url('kms/view.php?id=' . $a['id']) ?>"><?= e($a['judul']) ?></a></b><small>Oleh <?= e($a['nama']) ?> · <?= lalu($a['dibuat']) ?></small></div></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h3>📋 Tugas Berjalan</h3><a href="<?= url('oas/tugas.php') ?>">Kelola</a></div>
    <?php if (!$tugas): echo empty_state('🗒️', 'Belum ada tugas aktif', 'Beri tugas ke staff lewat menu Tugas Staff.');
    else: ?><div class="list"><?php foreach ($tugas as $t): $telat = $t['tenggat'] < date('Y-m-d'); ?>
      <div class="li"><span class="li-ic">📌</span><div class="li-body"><b><?= e($t['judul']) ?></b><small><?= e($t['nama']) ?> · <span class="<?= $telat ? 'overdue' : '' ?>">tenggat <?= tgl($t['tenggat'], false) ?></span></small></div><?= status_badge($t['status']) ?></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h3>⚠️ Stok Menipis</h3><a href="<?= url('tps/produk.php?f=menipis') ?>">Restok</a></div>
    <?php if (!$menipis): echo empty_state('✅', 'Semua stok aman');
    else: ?><div class="list"><?php foreach ($menipis as $p): ?>
      <div class="li"><span class="li-ic"><?= e($p['ikon']) ?></span><div class="li-body"><b><?= e($p['nama']) ?></b><small><?= e($p['sku']) ?></small></div><span class="badge r"><?= $p['stok'] ?></span></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
</div>

<h3 class="section-title">⚡ Akses Cepat</h3>
<div class="quicks">
  <?= quick('📈', 'MIS · Kinerja Perusahaan', 'Dashboard eksekutif dari TPS, OAS, KMS', 'mis/index.php', 'p') ?>
  <?= quick('📊', 'Laporan TPS', 'Penjualan, produk, dan kasir', 'tps/laporan.php', 'y') ?>
  <?= quick('📋', 'OAS', 'Tugas dan pengumuman internal', 'oas/tugas.php', 'b') ?>
  <?= quick('📚', 'Knowledge Center', 'Akses pengetahuan organisasi', 'kms/index.php', 'g') ?>
  <?= quick('📝', 'Review KMS', 'Review dan kurasi kontribusi staff', 'kms/index.php?tab=review', 'o') ?>
</div>
<?php page_end();

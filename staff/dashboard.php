<?php
require __DIR__ . '/../includes/app.php';
need('staff');
$id = uid();

$trxSaya   = (int) val('SELECT COUNT(*) FROM transaksi WHERE id_kasir=? AND DATE(waktu)=CURDATE()', [$id]);
$omzetSaya = (float) val('SELECT COALESCE(SUM(total),0) FROM transaksi WHERE id_kasir=? AND DATE(waktu)=CURDATE()', [$id]);
$tugas     = rows("SELECT * FROM tugas WHERE id_staff=? AND status!='selesai' ORDER BY tenggat LIMIT 5", [$id]);
$info      = rows('SELECT a.*,p.nama FROM pengumuman a JOIN pegawai p ON p.id=a.id_penulis ORDER BY a.penting DESC, a.dibuat DESC LIMIT 3');
$agenda    = rows("SELECT * FROM agenda WHERE mulai>=CURDATE() AND untuk IN ('semua','staff') ORDER BY mulai LIMIT 4");

page_start('Dashboard Operasional', 'dashboard', 'TPS, OAS, dan KMS dalam satu layar kerja.');
echo hero('Hai, ' . e(explode(' ', me()['nama'])[0]) . '! Siap melayani hari ini? 🛒', 'Buka kasir untuk mencatat belanja pelanggan, cek tugas dari manajer, dan bagikan pengetahuanmu ke tim.', '🍎🥖🥛🧀🥕');
?>
<div class="stats">
  <?= stat_card('Transaksi Saya Hari Ini', angka($trxSaya), '🧾', 'g', 'tps/transaksi.php', rp($omzetSaya)) ?>
  <?= stat_card('Stok < ' . STOK_MIN, angka(val('SELECT COUNT(*) FROM produk WHERE aktif=1 AND stok<?', [STOK_MIN])), '⚠️', 'r', 'tps/produk.php?f=menipis') ?>
  <?= stat_card('Tugas Saya', angka(val("SELECT COUNT(*) FROM tugas WHERE id_staff=? AND status!='selesai'", [$id])), '📋', 'b', 'oas/tugas.php') ?>
  <?= stat_card('Pesan Belum Dibaca', angka(val('SELECT COUNT(*) FROM pesan WHERE id_penerima=? AND dibaca=0', [$id])), '✉️', 'o', 'oas/pesan.php') ?>
  <?= stat_card('Knowledge Published', angka(val("SELECT COUNT(*) FROM artikel_kms WHERE status='published' AND visibilitas='internal'")), '📚', 'y', 'kms/index.php') ?>
</div>

<div class="quicks">
  <?= quick('🧾', 'Buka Kasir', 'Catat transaksi pelanggan', 'tps/kasir.php', 'g') ?>
  <?= quick('📚', 'Knowledge Center', 'Cari SOP, panduan, dan solusi', 'kms/index.php', 'y') ?>
  <?= quick('✍️', 'Kontribusi Knowledge', 'Bagi skill dan tips untuk tim', 'kms/tulis.php', 'o') ?>
  <?= quick('📁', 'Dokumen', 'Formulir dan arsip toko', 'oas/dokumen.php', 'b') ?>
</div>

<div class="grid g3">
  <div class="card">
    <div class="card-h"><h3>📋 Tugas Saya</h3><a href="<?= url('oas/tugas.php') ?>">Semua</a></div>
    <?php if (!$tugas): echo empty_state('🎉', 'Tidak ada tugas aktif', 'Tugas dari manajer akan muncul di sini.');
    else: ?><div class="list"><?php foreach ($tugas as $t): $telat = $t['tenggat'] < date('Y-m-d'); ?>
      <div class="li"><span class="li-ic">📌</span><div class="li-body"><b><?= e($t['judul']) ?></b><small class="<?= $telat ? 'overdue' : '' ?>"><?= $telat ? 'Terlambat · ' : '' ?>tenggat <?= tgl($t['tenggat'], false) ?></small></div><?= status_badge($t['status']) ?></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h3>📢 Pengumuman</h3><a href="<?= url('oas/pengumuman.php') ?>">Semua</a></div>
    <?php if (!$info): echo empty_state('📭', 'Belum ada pengumuman');
    else: ?><div class="list"><?php foreach ($info as $a): ?>
      <div class="li"><span class="li-ic"><?= $a['penting'] ? '🔔' : '📢' ?></span><div class="li-body"><b><?= e($a['judul']) ?></b><small><?= e($a['nama']) ?> · <?= lalu($a['dibuat']) ?></small></div></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h3>📅 Agenda Mendatang</h3><a href="<?= url('oas/agenda.php') ?>">Kalender</a></div>
    <?php if (!$agenda): echo empty_state('🗓️', 'Belum ada agenda terjadwal');
    else: ?><div class="list"><?php foreach ($agenda as $g): ?>
      <div class="li"><span class="li-ic">📅</span><div class="li-body"><b><?= e($g['judul']) ?></b><small><?= tgl($g['mulai']) ?><?= $g['lokasi'] ? ' · ' . e($g['lokasi']) : '' ?></small></div></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<?php page_end();

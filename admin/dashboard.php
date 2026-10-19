<?php
require __DIR__ . '/../includes/app.php';
need('admin');

$jmlMenipis = (int) val('SELECT COUNT(*) FROM produk WHERE aktif=1 AND stok<?', [STOK_MIN]);
$menipis = rows('SELECT * FROM produk WHERE aktif=1 AND stok<? ORDER BY stok LIMIT 6', [STOK_MIN]);
$pegawaiBaru = rows('SELECT nama,role,jabatan,aktif,dibuat FROM pegawai ORDER BY dibuat DESC, id DESC LIMIT 5');
$kmsBaru = rows('SELECT a.id,a.judul,a.status,a.dibuat,p.nama FROM artikel_kms a JOIN pegawai p ON p.id=a.id_penulis ORDER BY a.dibuat DESC LIMIT 5');

page_start('Administrator', 'dashboard', 'Kelola data master, pengguna, dan seluruh modul MartOffice.');
echo hero('Halo, ' . e(me()['nama']) . ' 👋', 'Pantau pengguna, produk, transaksi, OAS, dan knowledge base dari satu tempat.', '🛒📦👥📚');
?>
<div class="stats">
  <?= stat_card('User Aktif', angka(val('SELECT COUNT(*) FROM pegawai WHERE aktif=1')), '👥', 'p', 'admin/users.php') ?>
  <?= stat_card('Produk Aktif', angka(val('SELECT COUNT(*) FROM produk WHERE aktif=1')), '📦', 'g', 'tps/produk.php', $jmlMenipis ? $jmlMenipis . ' stok menipis' : 'stok aman') ?>
  <?= stat_card('Transaksi', angka(val('SELECT COUNT(*) FROM transaksi')), '🧾', 'y', 'tps/transaksi.php') ?>
  <?= stat_card('Tugas OAS', angka(val('SELECT COUNT(*) FROM tugas')), '📋', 'b', 'oas/tugas.php', angka(val("SELECT COUNT(*) FROM tugas WHERE status!='selesai'")) . ' belum selesai') ?>
  <?= stat_card('Knowledge KMS', angka(val('SELECT COUNT(*) FROM artikel_kms')), '📚', 'o', 'kms/index.php', angka(val("SELECT COUNT(*) FROM artikel_kms WHERE status='menunggu_review'")) . ' menunggu review') ?>
</div>

<h3 class="section-title">⚙️ Master &amp; Sistem</h3>
<div class="quicks">
  <?= quick('👥', 'User & Role', 'Tambah, ubah role, reset password', 'admin/users.php', 'p') ?>
  <?= quick('📦', 'Produk & Stok', 'Kelola rak, harga, dan persediaan', 'tps/produk.php', 'g') ?>
  <?= quick('📋', 'OAS', 'Tugas, pengumuman, agenda', 'oas/tugas.php', 'b') ?>
  <?= quick('📊', 'Laporan', 'Penjualan, terlaris, per kasir', 'tps/laporan.php', 'y') ?>
  <?= quick('🏷️', 'Kelola KMS', 'Kategori knowledge & review', 'kms/kategori.php', 'o') ?>
  <?= quick('📁', 'Dokumen', 'Arsip SOP, formulir, surat', 'oas/dokumen.php', 'r') ?>
</div>

<div class="grid g3">
  <div class="card">
    <div class="card-h"><h3>⚠️ Stok Menipis</h3><a href="<?= url('tps/produk.php?f=menipis') ?>">Lihat semua</a></div>
    <?php if (!$menipis): echo empty_state('✅', 'Semua stok aman', 'Tidak ada produk di bawah ' . STOK_MIN . ' unit.');
    else: ?><div class="list"><?php foreach ($menipis as $p): ?>
      <div class="li"><span class="li-ic"><?= e($p['ikon']) ?></span><div class="li-body"><b><?= e($p['nama']) ?></b><small><?= e($p['kategori']) ?></small></div><span class="badge r"><?= $p['stok'] ?> <?= e($p['satuan']) ?></span></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h3>👥 Pegawai Terbaru</h3><a href="<?= url('admin/users.php') ?>">Kelola</a></div>
    <div class="list"><?php foreach ($pegawaiBaru as $p): ?>
      <div class="li"><span class="avatar"><?= e(inisial($p['nama'])) ?></span><div class="li-body"><b><?= e($p['nama']) ?></b><small><?= e($p['jabatan'] ?: '-') ?><?= $p['aktif'] ? '' : ' · nonaktif' ?></small></div><?= status_badge($p['role']) ?></div>
    <?php endforeach; ?></div>
  </div>
  <div class="card">
    <div class="card-h"><h3>📚 Aktivitas KMS</h3><a href="<?= url('kms/index.php') ?>">Buka</a></div>
    <?php if (!$kmsBaru): echo empty_state('📭', 'Belum ada knowledge');
    else: ?><div class="list"><?php foreach ($kmsBaru as $a): ?>
      <div class="li"><span class="li-ic">📄</span><div class="li-body"><b><a href="<?= url('kms/view.php?id=' . $a['id']) ?>"><?= e($a['judul']) ?></a></b><small><?= e($a['nama']) ?> · <?= lalu($a['dibuat']) ?></small></div><?= status_badge($a['status']) ?></div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<?php page_end();

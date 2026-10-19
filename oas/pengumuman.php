<?php
require __DIR__ . '/../includes/app.php';
need();
$boss = is_boss();

if (is_post() && $boss) {
    if (post('aksi') === 'buat') {
        if (post('judul') === '' || post('isi') === '') flash('err', 'Judul dan isi pengumuman wajib diisi.');
        else {
            q('INSERT INTO pengumuman(judul,isi,penting,id_penulis) VALUES(?,?,?,?)', [post('judul'), post('isi'), isset($_POST['penting']) ? 1 : 0, uid()]);
            flash('ok', 'Pengumuman dipublikasikan ke seluruh pegawai. 📢');
        }
    } elseif (post('aksi') === 'hapus') {
        q('DELETE FROM pengumuman WHERE id=?', [(int) post('id')]);
        flash('ok', 'Pengumuman dihapus.');
    }
    redirect('oas/pengumuman.php');
}

$cari = get('cari');
$p = []; $w = '';
if ($cari !== '') { $w = 'WHERE a.judul LIKE ? OR a.isi LIKE ?'; $p = ["%$cari%", "%$cari%"]; }
$total = (int) val("SELECT COUNT(*) FROM pengumuman a $w", $p);
$pg = paginate($total, 10);
$list = rows("SELECT a.*,p.nama,p.jabatan FROM pengumuman a JOIN pegawai p ON p.id=a.id_penulis $w ORDER BY a.penting DESC, a.dibuat DESC LIMIT ? OFFSET ?", array_merge($p, [$pg['per'], $pg['offset']]));

page_start('Pengumuman', 'pengumuman', 'Informasi resmi dari manajemen untuk seluruh pegawai.');
?>
<div class="grid <?= $boss ? 'g-form' : '' ?>">
  <?php if ($boss): ?>
  <div class="card" style="align-self:start">
    <div class="card-h"><h3>📢 Buat Pengumuman</h3></div>
    <form method="post">
      <?= csrf() ?><input type="hidden" name="aksi" value="buat">
      <label>Judul</label><input name="judul" required maxlength="120" placeholder="mis. Jadwal stock opname akhir bulan">
      <label>Isi pengumuman</label><textarea name="isi" rows="7" required></textarea>
      <label class="radio-card" style="margin-top:12px"><input type="checkbox" name="penting" value="1"> 🔔 Tandai penting (disematkan di atas)</label>
      <div class="form-actions"><button type="submit" class="o">📣 Publikasikan</button></div>
    </form>
  </div>
  <?php endif; ?>
  <div>
    <form method="get" class="card row" style="padding:14px 18px">
      <div><input name="cari" value="<?= e($cari) ?>" placeholder="🔍 Cari pengumuman…"></div>
      <div class="fit"><button type="submit">Cari</button></div>
    </form>
    <?php if (!$list): echo '<div class="card">' . empty_state('📭', $cari ? 'Pengumuman tidak ditemukan' : 'Belum ada pengumuman', $boss ? 'Buat pengumuman pertama lewat formulir di samping.' : 'Pengumuman dari manajemen akan tampil di sini.') . '</div>';
    else: foreach ($list as $a): ?>
      <article class="ann <?= $a['penting'] ? 'penting' : '' ?>">
        <div class="card-h" style="margin-bottom:0">
          <h3><?= $a['penting'] ? '🔔 ' : '' ?><?= e($a['judul']) ?></h3>
          <?php if ($a['penting']) echo badge('Penting', 'o'); ?>
        </div>
        <div class="isi"><?= e($a['isi']) ?></div>
        <div class="actions" style="justify-content:space-between">
          <small>✍️ <?= e($a['nama']) ?><?= $a['jabatan'] ? ' · ' . e($a['jabatan']) : '' ?> · <?= tgl($a['dibuat']) ?></small>
          <?php if ($boss): ?><form method="post" class="inline"><?= csrf() ?><input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button class="link-btn" name="aksi" value="hapus" data-confirm="Hapus pengumuman ini?">Hapus</button></form><?php endif; ?>
        </div>
      </article>
    <?php endforeach; endif; ?>
    <?= pager($pg) ?>
  </div>
</div>
<?php page_end();

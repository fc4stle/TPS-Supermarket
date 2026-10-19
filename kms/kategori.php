<?php
require __DIR__ . '/../includes/app.php';
need('admin', 'manajer');

if (is_post()) {
    $aksi = post('aksi');
    $id = (int) post('id');
    if ($aksi === 'simpan') {
        $nama = mb_substr(post('nama'), 0, 60);
        if ($nama === '') flash('err', 'Nama kategori wajib diisi.');
        elseif (val('SELECT COUNT(*) FROM kategori_kms WHERE nama=? AND id!=?', [$nama, $id])) flash('err', 'Kategori “' . e($nama) . '” sudah ada.');
        else {
            $ikon = post('ikon') ?: '📁';
            if ($id) q('UPDATE kategori_kms SET nama=?,deskripsi=?,ikon=? WHERE id=?', [$nama, post('deskripsi'), $ikon, $id]);
            else q('INSERT INTO kategori_kms(nama,deskripsi,ikon) VALUES(?,?,?)', [$nama, post('deskripsi'), $ikon]);
            flash('ok', 'Kategori <b>' . e($nama) . '</b> disimpan.');
            redirect('kms/kategori.php');
        }
        redirect('kms/kategori.php' . ($id ? '?edit=' . $id : ''));
    }
    if (in_array($aksi, ['nonaktif', 'aktifkan'], true)) {
        q('UPDATE kategori_kms SET aktif=? WHERE id=?', [$aksi === 'aktifkan' ? 1 : 0, $id]);
        flash('ok', $aksi === 'aktifkan' ? 'Kategori diaktifkan.' : 'Kategori dinonaktifkan (tidak bisa dipilih untuk knowledge baru).');
    }
    if ($aksi === 'hapus') {
        if (val('SELECT COUNT(*) FROM artikel_kms WHERE id_kategori=?', [$id])) flash('err', 'Kategori masih dipakai knowledge. Nonaktifkan saja.');
        else { q('DELETE FROM kategori_kms WHERE id=?', [$id]); flash('ok', 'Kategori dihapus.'); }
    }
    redirect('kms/kategori.php');
}

$edit = get('edit') ? row('SELECT * FROM kategori_kms WHERE id=?', [(int) get('edit')]) : null;
$list = rows("SELECT k.*, (SELECT COUNT(*) FROM artikel_kms a WHERE a.id_kategori=k.id) n,
              (SELECT COUNT(*) FROM artikel_kms a WHERE a.id_kategori=k.id AND a.status='published') np FROM kategori_kms k ORDER BY k.aktif DESC, k.nama");

page_start('Kategori KMS', 'kms-kategori', 'Susun struktur Knowledge Center agar pengetahuan mudah ditemukan.');
?>
<div class="grid g-form">
  <div class="card" style="align-self:start">
    <div class="card-h"><h3><?= $edit ? '✏️ Ubah Kategori' : '➕ Tambah Kategori' ?></h3><?php if ($edit): ?><a href="<?= url('kms/kategori.php') ?>">Batal</a><?php endif; ?></div>
    <form method="post">
      <?= csrf() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="row">
        <div class="fit" style="width:90px"><label>Ikon</label><input name="ikon" value="<?= e($edit['ikon'] ?? '') ?>" placeholder="📁" maxlength="8" style="font-size:20px;text-align:center"></div>
        <div><label>Nama</label><input name="nama" value="<?= e($edit['nama'] ?? '') ?>" required maxlength="60"></div>
      </div>
      <label>Deskripsi</label><textarea name="deskripsi" rows="3" maxlength="200"><?= e($edit['deskripsi'] ?? '') ?></textarea>
      <div class="form-actions"><button type="submit"><?= $edit ? '💾 Simpan' : '➕ Tambah' ?></button></div>
    </form>
  </div>

  <div class="card">
    <div class="card-h"><h3>🏷️ Kategori Aktif</h3><small><?= count($list) ?> kategori</small></div>
    <?php if (!$list): echo empty_state('🏷️', 'Belum ada kategori'); else: ?>
    <div class="list"><?php foreach ($list as $k): ?>
      <div class="li" style="<?= $k['aktif'] ? '' : 'opacity:.55' ?>">
        <span class="li-ic"><?= e($k['ikon']) ?></span>
        <div class="li-body"><b><?= e($k['nama']) ?> <?= $k['aktif'] ? '' : badge('nonaktif', 'n') ?></b><small><?= e($k['deskripsi'] ?: '-') ?></small>
          <small>📚 <?= $k['np'] ?> published · <?= $k['n'] ?> total</small></div>
        <div class="actions">
          <a class="btn sm ghost" href="?edit=<?= $k['id'] ?>">✏️</a>
          <form method="post" class="inline"><?= csrf() ?><input type="hidden" name="id" value="<?= $k['id'] ?>">
            <?php if ($k['aktif']): ?><button class="sm ghost" name="aksi" value="nonaktif">Nonaktifkan</button><?php else: ?><button class="sm" name="aksi" value="aktifkan">Aktifkan</button><?php endif; ?>
            <?php if (!$k['n']): ?><button class="sm r" name="aksi" value="hapus" data-confirm="Hapus kategori <?= e($k['nama']) ?>?">🗑</button><?php endif; ?>
          </form>
        </div>
      </div>
    <?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</div>
<?php page_end();

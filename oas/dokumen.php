<?php
require __DIR__ . '/../includes/app.php';
need();
$KATEGORI = ['SOP', 'Formulir', 'Laporan', 'Surat', 'Lainnya'];
$DIR = __DIR__ . '/../uploads/dokumen/';

if (is_post()) {
    if (post('aksi') === 'unggah') {
        $f = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
        $errs = [UPLOAD_ERR_INI_SIZE => 'File melebihi batas upload server.', UPLOAD_ERR_FORM_SIZE => 'File terlalu besar.', UPLOAD_ERR_NO_FILE => 'Pilih file terlebih dahulu.'];
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) flash('err', $errs[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Upload gagal (kode ' . (int) ($f['error'] ?? -1) . ').');
        elseif ($f['size'] > UPLOAD_MAX) flash('err', 'Ukuran file maksimal ' . ukuran_file(UPLOAD_MAX) . '.');
        elseif (!in_array($ext, UPLOAD_EXT, true)) flash('err', 'Tipe file .' . e($ext) . ' tidak diizinkan. Gunakan: ' . implode(', ', UPLOAD_EXT) . '.');
        elseif (post('judul') === '') flash('err', 'Judul dokumen wajib diisi.');
        else {
            if (!is_dir($DIR)) mkdir($DIR, 0775, true);
            $nama = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $DIR . $nama)) flash('err', 'Gagal menyimpan file. Periksa izin folder uploads/dokumen.');
            else {
                q('INSERT INTO dokumen(judul,deskripsi,kategori,nama_file,file_asli,ukuran,id_pengunggah) VALUES(?,?,?,?,?,?,?)',
                    [post('judul'), post('deskripsi'), pilih(post('kategori'), $KATEGORI, 'Lainnya'), $nama, mb_substr(basename($f['name']), 0, 200), (int) $f['size'], uid()]);
                flash('ok', 'Dokumen <b>' . e(post('judul')) . '</b> berhasil diunggah.');
            }
        }
    } elseif (post('aksi') === 'hapus') {
        $d = row('SELECT * FROM dokumen WHERE id=?', [(int) post('id')]);
        if ($d && (is_boss() || (int) $d['id_pengunggah'] === uid())) {
            q('DELETE FROM dokumen WHERE id=?', [(int) $d['id']]);
            if (is_file($DIR . $d['nama_file'])) @unlink($DIR . $d['nama_file']);
            flash('ok', 'Dokumen dihapus.');
        }
    }
    back();
}

$kat = pilih(get('kat'), $KATEGORI, '');
$cari = get('cari');
$w = []; $p = [];
if ($kat) { $w[] = 'd.kategori=?'; $p[] = $kat; }
if ($cari !== '') { $w[] = '(d.judul LIKE ? OR d.deskripsi LIKE ? OR d.file_asli LIKE ?)'; array_push($p, "%$cari%", "%$cari%", "%$cari%"); }
$docs = rows('SELECT d.*,p.nama FROM dokumen d JOIN pegawai p ON p.id=d.id_pengunggah ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY d.dibuat DESC', $p);
$hit = array_column(rows('SELECT kategori, COUNT(*) n FROM dokumen GROUP BY kategori'), 'n', 'kategori');
$ikon = ['SOP' => '📘', 'Formulir' => '📝', 'Laporan' => '📊', 'Surat' => '✉️', 'Lainnya' => '📎'];

page_start('Dokumen', 'dokumen', 'Arsip digital SOP, formulir, laporan, dan surat toko.');
?>
<div class="chips" style="margin-bottom:18px">
  <a class="chip <?= $kat === '' ? 'on' : '' ?>" href="?">📂 Semua (<?= array_sum($hit) ?>)</a>
  <?php foreach ($KATEGORI as $k): ?><a class="chip <?= $kat === $k ? 'on' : '' ?>" href="?kat=<?= $k ?>"><?= $ikon[$k] ?> <?= $k ?> (<?= $hit[$k] ?? 0 ?>)</a><?php endforeach; ?>
</div>

<div class="grid g-side">
  <div class="card">
    <form method="get" class="row" style="margin-bottom:8px">
      <input type="hidden" name="kat" value="<?= e($kat) ?>">
      <div><input name="cari" value="<?= e($cari) ?>" placeholder="🔍 Cari judul atau nama file…"></div>
      <div class="fit"><button type="submit">Cari</button></div>
    </form>
    <?php if (!$docs): echo empty_state('📂', 'Belum ada dokumen' . ($kat ? ' kategori ' . $kat : ''), 'Unggah dokumen pertama lewat formulir di samping.');
    else: ?><div class="list"><?php foreach ($docs as $d): $ext = strtolower(pathinfo($d['nama_file'], PATHINFO_EXTENSION)); ?>
      <div class="li" style="align-items:center">
        <span class="file-ic <?= e($ext) ?>"><?= strtoupper(e($ext)) ?></span>
        <div class="li-body">
          <b><?= e($d['judul']) ?></b>
          <small><?= e($d['file_asli']) ?> · <?= ukuran_file((int) $d['ukuran']) ?></small>
          <?php if ($d['deskripsi']): ?><small style="color:var(--ink)"><?= e($d['deskripsi']) ?></small><?php endif; ?>
          <small><?= badge($d['kategori'], 'n') ?> diunggah <?= e($d['nama']) ?> · <?= lalu($d['dibuat']) ?></small>
        </div>
        <div class="actions" style="flex-direction:column;align-items:stretch">
          <a class="btn sm" href="<?= url('oas/unduh.php?id=' . $d['id']) ?>">⬇️ Unduh</a>
          <?php if (is_boss() || (int) $d['id_pengunggah'] === uid()): ?>
          <form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="sm ghost block" name="aksi" value="hapus" data-confirm="Hapus dokumen “<?= e($d['judul']) ?>”?">🗑 Hapus</button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>

  <div class="card" style="align-self:start">
    <div class="card-h"><h3>⬆️ Unggah Dokumen</h3></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf() ?><input type="hidden" name="aksi" value="unggah">
      <label>Judul dokumen</label><input name="judul" required maxlength="150" placeholder="mis. Formulir Retur Barang">
      <label>Kategori</label><select name="kategori"><?php foreach ($KATEGORI as $k) echo "<option>$k</option>"; ?></select>
      <label>Keterangan</label><input name="deskripsi" maxlength="255" placeholder="Opsional">
      <label>File</label><input type="file" name="file" required accept=".<?= implode(',.', UPLOAD_EXT) ?>">
      <p class="hint">Maks. <?= ukuran_file(UPLOAD_MAX) ?> · <?= implode(', ', UPLOAD_EXT) ?></p>
      <div class="form-actions"><button type="submit">⬆️ Unggah</button></div>
    </form>
  </div>
</div>
<?php page_end();

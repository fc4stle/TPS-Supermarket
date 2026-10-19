<?php
require __DIR__ . '/../includes/app.php';
need();
$boss = is_boss();

$id = (int) (is_post() ? post('id') : get('id'));
$a = null;
if ($id) {
    $a = row('SELECT * FROM artikel_kms WHERE id=?', [$id]);
    if (!$a || (!$boss && (int) $a['id_penulis'] !== uid())) {
        flash('err', 'Anda hanya dapat mengubah knowledge milik sendiri.');
        redirect('kms/index.php');
    }
}

if (is_post()) {
    $d = [
        'judul' => mb_substr(post('judul'), 0, 150), 'id_kategori' => (int) post('kategori'),
        'ringkasan' => mb_substr(post('ringkasan'), 0, 255), 'isi' => post('isi'),
        'visibilitas' => pilih(post('visibilitas'), ['internal', 'terbatas'], 'internal'),
    ];
    $tags = parse_tags(post('tag'));
    $err = [];
    if ($d['judul'] === '' || $d['ringkasan'] === '' || $d['isi'] === '') $err[] = 'Judul, ringkasan, dan isi wajib diisi.';
    if (!val('SELECT COUNT(*) FROM kategori_kms WHERE id=? AND aktif=1', [$d['id_kategori']])) $err[] = 'Pilih kategori yang aktif.';
    if ($err) {
        flash('err', implode('<br>', $err));
        $_SESSION['old_kms'] = $_POST;
        redirect('kms/tulis.php' . ($id ? '?id=' . $id : ''));
    }

    $db = db();
    $db->begin_transaction();
    if (!$id) {
        $status = $boss ? 'published' : 'menunggu_review';
        q('INSERT INTO artikel_kms(judul,id_kategori,ringkasan,isi,visibilitas,id_penulis,status,id_reviewer) VALUES(?,?,?,?,?,?,?,?)',
            [...array_values($d), uid(), $status, $boss ? uid() : null]);
        $id = last_id();
        simpan_tags($id, $tags);
        simpan_versi($id, 'Versi awal');
        $pesan = $boss ? 'Knowledge langsung dipublikasikan. 🎉' : 'Knowledge terkirim dan <b>menunggu review</b> manajer. Terima kasih sudah berbagi! 🙌';
    } else {
        q('UPDATE artikel_kms SET judul=?,id_kategori=?,ringkasan=?,isi=?,visibilitas=?,versi=versi+1 WHERE id=?', [...array_values($d), $id]);
        $pesan = 'Perubahan disimpan sebagai versi baru.';
        if (!$boss) {
            q("UPDATE artikel_kms SET status='menunggu_review', id_reviewer=NULL, catatan_review=NULL WHERE id=?", [$id]);
            $pesan = 'Perubahan disimpan dan dikirim ulang untuk <b>review</b> manajer.';
        }
        simpan_tags($id, $tags);
        simpan_versi($id, post('catatan') ?: 'Pembaruan konten');
    }
    $db->commit();
    flash('ok', $pesan);
    redirect('kms/view.php?id=' . $id);
}

$old = $_SESSION['old_kms'] ?? null; unset($_SESSION['old_kms']);
$v = [
    'judul' => $old['judul'] ?? $a['judul'] ?? '',
    'kategori' => (int) ($old['kategori'] ?? $a['id_kategori'] ?? 0),
    'ringkasan' => $old['ringkasan'] ?? $a['ringkasan'] ?? '',
    'isi' => $old['isi'] ?? $a['isi'] ?? '',
    'tag' => $old['tag'] ?? ($a ? implode(', ', tags_artikel($id)) : ''),
    'visibilitas' => $old['visibilitas'] ?? $a['visibilitas'] ?? 'internal',
];
$kategori = rows('SELECT * FROM kategori_kms WHERE aktif=1 OR id=? ORDER BY nama', [$v['kategori']]);
$semuaTag = array_column(rows('SELECT nama FROM tag_kms ORDER BY nama'), 'nama');

page_start($id ? 'Edit Knowledge' : 'Tulis Knowledge', 'kms-tulis', $id ? 'Setiap penyimpanan tercatat sebagai versi baru di riwayat.' : 'Bagikan SOP, tips, atau solusi yang kamu kuasai untuk seluruh tim.');
?>
<div class="grid g-side">
  <form class="card" method="post">
    <?= csrf() ?><input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($a && !$boss && $a['status'] === 'published'): ?>
      <div class="alert info"><span>ℹ️</span><div>Knowledge ini sudah published. Setelah diedit, statusnya kembali <b>menunggu review</b> sampai disetujui manajer.</div></div>
    <?php elseif ($a && $a['status'] === 'ditolak'): ?>
      <div class="alert err"><span>💬</span><div><b>Catatan reviewer:</b> <?= e($a['catatan_review'] ?: '-') ?><br>Perbaiki lalu simpan untuk mengirim ulang.</div></div>
    <?php endif; ?>
    <label>Judul</label><input name="judul" value="<?= e($v['judul']) ?>" required maxlength="150" placeholder="mis. Cara Menangani Pelanggan yang Komplain">
    <div class="row">
      <div><label>Kategori</label><select name="kategori" required>
        <?php foreach ($kategori as $k) echo '<option value="' . $k['id'] . '"' . ($v['kategori'] === (int) $k['id'] ? ' selected' : '') . '>' . e($k['ikon'] . ' ' . $k['nama']) . '</option>'; ?></select></div>
      <div><label>Visibilitas</label><select name="visibilitas">
        <option value="internal" <?= $v['visibilitas'] === 'internal' ? 'selected' : '' ?>>🌐 Internal — semua pegawai</option>
        <option value="terbatas" <?= $v['visibilitas'] === 'terbatas' ? 'selected' : '' ?>>🔒 Terbatas — manajemen & penulis</option></select></div>
    </div>
    <label>Ringkasan singkat</label><input name="ringkasan" value="<?= e($v['ringkasan']) ?>" required maxlength="255" placeholder="Satu kalimat yang menjelaskan isi knowledge">
    <label>Isi knowledge</label>
    <textarea name="isi" rows="14" required placeholder="Tulis langkah-langkah, tips, atau penjelasan. Gunakan baris baru untuk setiap poin."><?= e($v['isi']) ?></textarea>
    <label>Tag <small>(pisahkan dengan koma)</small></label>
    <input name="tag" list="dl-tag" value="<?= e($v['tag']) ?>" placeholder="kasir, sop, stok">
    <datalist id="dl-tag"><?php foreach ($semuaTag as $t) echo '<option value="' . e($t) . '">'; ?></datalist>
    <?php if ($id): ?><label>Catatan perubahan</label><input name="catatan" maxlength="255" placeholder="mis. Menambahkan langkah verifikasi QRIS"><?php endif; ?>
    <div class="form-actions">
      <button type="submit"><?= $id ? '💾 Simpan Versi Baru' : ($boss ? '🚀 Publikasikan' : '📤 Kirim untuk Review') ?></button>
      <a class="btn ghost" href="<?= url($id ? 'kms/view.php?id=' . $id : 'kms/index.php') ?>">Batal</a>
    </div>
  </form>

  <aside>
    <div class="card">
      <h3>💡 Tips menulis knowledge</h3>
      <div class="list" style="margin-top:8px">
        <div class="li"><span class="li-ic">🎯</span><div class="li-body"><b>Satu topik, satu artikel</b><small>Fokus pada satu masalah atau prosedur.</small></div></div>
        <div class="li"><span class="li-ic">🔢</span><div class="li-body"><b>Gunakan langkah bernomor</b><small>Memudahkan rekan mengikuti SOP.</small></div></div>
        <div class="li"><span class="li-ic">🏷️</span><div class="li-body"><b>Beri tag yang jelas</b><small>Tag membantu pencarian di Knowledge Center.</small></div></div>
      </div>
    </div>
    <div class="card">
      <h3>🔄 Alur governance</h3>
      <div class="timeline" style="margin-top:12px">
        <div class="tl on"><b>Tulis / edit</b><br><small>Disimpan sebagai versi baru</small></div>
        <div class="tl"><b>Menunggu review</b><br><small>Manajer memeriksa isi (khusus kontribusi staff)</small></div>
        <div class="tl"><b>Published / Ditolak</b><br><small>Ditolak? Perbaiki dan kirim ulang</small></div>
        <div class="tl"><b>Feedback pembaca</b><br><small>“Membantu / belum membantu” + komentar</small></div>
      </div>
    </div>
  </aside>
</div>
<?php page_end();

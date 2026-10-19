<?php
require __DIR__ . '/../includes/app.php';
need();
$boss = is_boss();
$id = (int) get('id');

$a = row('SELECT a.*,k.nama kn,k.ikon ki,p.nama pn,p.jabatan pj,r.nama rn FROM artikel_kms a JOIN kategori_kms k ON k.id=a.id_kategori
          JOIN pegawai p ON p.id=a.id_penulis LEFT JOIN pegawai r ON r.id=a.id_reviewer WHERE a.id=?', [$id]);
if (!$a || !boleh_lihat_artikel($a)) {
    flash('err', 'Knowledge tidak ditemukan atau Anda tidak memiliki akses.');
    redirect('kms/index.php');
}
$milik = (int) $a['id_penulis'] === uid();

if (is_post()) {
    $aksi = post('aksi');
    if ($aksi === 'feedback' && $a['status'] === 'published') {
        $m = post('membantu');
        if (!in_array($m, ['0', '1'], true)) flash('err', 'Pilih “Membantu” atau “Belum membantu”.');
        else {
            q('INSERT INTO feedback_kms(id_artikel,id_pegawai,membantu,komentar) VALUES(?,?,?,?)
               ON DUPLICATE KEY UPDATE membantu=VALUES(membantu), komentar=VALUES(komentar), dibuat=NOW()',
                [$id, uid(), (int) $m, mb_substr(post('komentar'), 0, 255) ?: null]);
            flash('ok', 'Terima kasih atas feedback Anda! 💚');
        }
    } elseif ($aksi === 'review' && $boss && $a['status'] === 'menunggu_review') {
        $hasil = pilih(post('hasil'), ['published', 'ditolak'], '');
        if (!$hasil) flash('err', 'Pilih hasil review.');
        elseif ($hasil === 'ditolak' && post('catatan') === '') flash('err', 'Tuliskan catatan agar penulis tahu apa yang perlu diperbaiki.');
        else {
            q('UPDATE artikel_kms SET status=?, id_reviewer=?, catatan_review=? WHERE id=?', [$hasil, uid(), post('catatan') ?: null, $id]);
            if (!$milik) {
                q('INSERT INTO pesan(id_pengirim,id_penerima,subjek,isi) VALUES(?,?,?,?)', [uid(), (int) $a['id_penulis'],
                    ($hasil === 'published' ? '✅ Knowledge disetujui: ' : '❌ Knowledge perlu perbaikan: ') . mb_substr($a['judul'], 0, 100),
                    'Knowledge "' . $a['judul'] . '" ' . ($hasil === 'published' ? 'telah disetujui dan dipublikasikan.' : 'belum dapat dipublikasikan.')
                    . (post('catatan') ? "\n\nCatatan reviewer:\n" . post('catatan') : '')]);
            }
            flash('ok', $hasil === 'published' ? 'Knowledge disetujui dan dipublikasikan. Penulis mendapat notifikasi pesan.' : 'Knowledge ditolak. Penulis mendapat catatan perbaikan.');
        }
    } elseif ($aksi === 'tarik' && $boss && $a['status'] === 'published') {
        q("UPDATE artikel_kms SET status='menunggu_review' WHERE id=?", [$id]);
        flash('ok', 'Knowledge ditarik dari publikasi dan kembali ke antrean review.');
    } elseif ($aksi === 'hapus' && ($boss || ($milik && $a['status'] !== 'published'))) {
        q('DELETE FROM artikel_kms WHERE id=?', [$id]);
        q('DELETE FROM tag_kms WHERE id NOT IN (SELECT id_tag FROM artikel_tag)');
        flash('ok', 'Knowledge “' . e($a['judul']) . '” dihapus.');
        redirect('kms/index.php');
    }
    redirect('kms/view.php?id=' . $id);
}

// hitung dilihat satu kali per sesi
if ($a['status'] === 'published' && empty($_SESSION['dilihat'][$id])) {
    q('UPDATE artikel_kms SET dilihat=dilihat+1 WHERE id=?', [$id]);
    $_SESSION['dilihat'][$id] = 1;
    $a['dilihat']++;
}

$tags = tags_artikel($id);
$fb = row('SELECT COALESCE(SUM(membantu=1),0) ya, COUNT(*) total FROM feedback_kms WHERE id_artikel=?', [$id]);
$fbSaya = row('SELECT * FROM feedback_kms WHERE id_artikel=? AND id_pegawai=?', [$id, uid()]);
$komentar = rows("SELECT f.*,p.nama FROM feedback_kms f JOIN pegawai p ON p.id=f.id_pegawai WHERE f.id_artikel=? AND f.komentar IS NOT NULL AND f.komentar!='' ORDER BY f.dibuat DESC", [$id]);
$terkait = rows("SELECT id,judul,ringkasan FROM artikel_kms WHERE id_kategori=? AND id!=? AND status='published'" . ($boss ? '' : " AND visibilitas='internal'") . ' ORDER BY dilihat DESC LIMIT 3', [(int) $a['id_kategori'], $id]);
$jmlVersi = (int) val('SELECT COUNT(*) FROM artikel_versi WHERE id_artikel=?', [$id]);
$persen = $fb['total'] ? round($fb['ya'] / $fb['total'] * 100) : 0;

page_start($a['judul'], $a['status'] === 'menunggu_review' && $boss ? 'kms-review' : 'kms', 'Knowledge Center › ' . e($a['kn']));
?>
<div class="card-h">
  <div class="actions"><?= badge('KMS · ' . $a['ki'] . ' ' . $a['kn'], 'y') ?><?= status_badge($a['status']) ?><?= status_badge($a['visibilitas']) ?></div>
  <div class="actions">
    <a class="btn ghost sm" href="<?= url('kms/index.php') ?>">← Knowledge Center</a>
    <a class="btn ghost sm" href="<?= url('kms/versi.php?id=' . $id) ?>">🕘 Riwayat Versi (<?= $jmlVersi ?>)</a>
    <?php if ($boss || $milik): ?><a class="btn sm" href="<?= url('kms/tulis.php?id=' . $id) ?>">✏️ Edit</a><?php endif; ?>
  </div>
</div>

<div class="grid g-side">
  <div>
    <article class="card">
      <h2 style="font-size:26px;margin-bottom:10px"><?= e($a['judul']) ?></h2>
      <div class="kmeta" style="margin-bottom:14px">
        <span>✍️ <b><?= e($a['pn']) ?></b><?= $a['pj'] ? ' · ' . e($a['pj']) : '' ?></span>
        <span>🕘 v<?= $a['versi'] ?> · diperbarui <?= tgl($a['diperbarui']) ?></span>
        <span>👁 <?= angka($a['dilihat']) ?>x dilihat</span>
        <span>👍 <?= $fb['ya'] ?>/<?= $fb['total'] ?> membantu</span>
      </div>
      <p class="lead"><?= e($a['ringkasan']) ?></p>
      <div class="article-body"><?= e($a['isi']) ?></div>
      <?php if ($tags): ?><hr><b style="font-size:13px">Tag:</b> <?= tag_chips($tags) ?><?php endif; ?>
    </article>

    <?php if ($a['status'] === 'published'): ?>
    <div class="grid g2">
      <div class="card">
        <h3>Apakah knowledge ini membantu?</h3>
        <div class="fb-bar" title="<?= $persen ?>% membantu"><i style="width:<?= $persen ?>%"></i></div>
        <small><?= $fb['total'] ? $persen . '% dari ' . $fb['total'] . ' pembaca merasa terbantu' : 'Belum ada feedback — jadilah yang pertama.' ?></small>
        <form method="post" style="margin-top:10px">
          <?= csrf() ?><input type="hidden" name="aksi" value="feedback">
          <div class="radio-row">
            <label class="radio-card"><input type="radio" name="membantu" value="1" <?= $fbSaya && $fbSaya['membantu'] ? 'checked' : '' ?> required> 👍 Membantu</label>
            <label class="radio-card"><input type="radio" name="membantu" value="0" <?= $fbSaya && !$fbSaya['membantu'] ? 'checked' : '' ?>> 👎 Belum membantu</label>
          </div>
          <label>Komentar (opsional)</label>
          <textarea name="komentar" rows="2" maxlength="255" placeholder="Apa yang perlu diperjelas?"><?= e($fbSaya['komentar'] ?? '') ?></textarea>
          <div class="form-actions"><button type="submit"><?= $fbSaya ? '🔄 Perbarui Feedback' : '💬 Kirim Feedback' ?></button></div>
        </form>
      </div>
      <div class="card">
        <h3>💬 Komentar Pembaca (<?= count($komentar) ?>)</h3>
        <?php if (!$komentar): echo empty_state('💭', 'Belum ada komentar');
        else: foreach ($komentar as $k): ?>
          <div class="comment"><span class="avatar"><?= e(inisial($k['nama'])) ?></span>
            <div><b><?= e($k['nama']) ?></b> <?= $k['membantu'] ? badge('👍 membantu', 'g') : badge('👎 belum', 'r') ?><br><?= e($k['komentar']) ?><br><small><?= lalu($k['dibuat']) ?></small></div></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <h3>🛡️ Governance</h3>
      <dl class="meta-list" style="margin-top:12px">
        <dt>Status</dt><dd><?= status_badge($a['status']) ?></dd>
        <dt>Visibilitas</dt><dd><?= $a['visibilitas'] === 'internal' ? 'Internal (semua pegawai)' : 'Terbatas (manajemen)' ?></dd>
        <dt>Penulis</dt><dd><?= e($a['pn']) ?></dd>
        <dt>Reviewer</dt><dd><?= e($a['rn'] ?? '-') ?></dd>
        <dt>Versi</dt><dd>v<?= $a['versi'] ?></dd>
        <dt>Dibuat</dt><dd><?= tgl($a['dibuat']) ?></dd>
        <?php if ($a['catatan_review']): ?><dt>Catatan</dt><dd><?= e($a['catatan_review']) ?></dd><?php endif; ?>
      </dl>
      <a class="btn ghost sm block" style="margin-top:14px" href="<?= url('kms/versi.php?id=' . $id) ?>">🕘 Lihat Riwayat Versi</a>

      <?php if ($boss && $a['status'] === 'menunggu_review'): ?>
        <hr><h3>📝 Review Knowledge</h3>
        <form method="post">
          <?= csrf() ?><input type="hidden" name="aksi" value="review">
          <label>Catatan review</label><textarea name="catatan" rows="3" placeholder="Wajib diisi bila ditolak"></textarea>
          <div class="form-actions">
            <button name="hasil" value="published">✅ Setujui</button>
            <button class="r" name="hasil" value="ditolak">✖ Tolak</button>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($boss || ($milik && $a['status'] !== 'published')): ?>
        <hr>
        <form method="post" class="actions">
          <?= csrf() ?>
          <?php if ($boss && $a['status'] === 'published'): ?><button class="sm ghost" name="aksi" value="tarik" data-confirm="Tarik knowledge ini dari publikasi?">↩ Tarik ke review</button><?php endif; ?>
          <button class="sm r" name="aksi" value="hapus" data-confirm="Hapus knowledge ini beserta riwayat versi dan feedback-nya?">🗑 Hapus</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($terkait): ?>
    <div class="card">
      <h3>🔗 Knowledge Terkait</h3>
      <div class="list" style="margin-top:6px"><?php foreach ($terkait as $t): ?>
        <div class="li"><span class="li-ic">📄</span><div class="li-body"><b><a href="<?= url('kms/view.php?id=' . $t['id']) ?>"><?= e($t['judul']) ?></a></b><small><?= e($t['ringkasan']) ?></small></div></div>
      <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
  </aside>
</div>
<?php page_end();

<?php
require __DIR__ . '/../includes/app.php';
need();
$boss = is_boss();
$id = (int) get('id');

$a = row('SELECT * FROM artikel_kms WHERE id=?', [$id]);
if (!$a || !boleh_lihat_artikel($a)) {
    flash('err', 'Knowledge tidak ditemukan.');
    redirect('kms/index.php');
}
$bisaUbah = $boss || (int) $a['id_penulis'] === uid();

if (is_post() && post('aksi') === 'pulihkan' && $bisaUbah) {
    $v = row('SELECT * FROM artikel_versi WHERE id_artikel=? AND versi=?', [$id, (int) post('versi')]);
    if ($v) {
        db()->begin_transaction();
        q('UPDATE artikel_kms SET judul=?,id_kategori=?,ringkasan=?,isi=?,versi=versi+1 WHERE id=?', [$v['judul'], (int) $v['id_kategori'], $v['ringkasan'], $v['isi'], $id]);
        if (!$boss) q("UPDATE artikel_kms SET status='menunggu_review', id_reviewer=NULL, catatan_review=NULL WHERE id=?", [$id]);
        simpan_tags($id, parse_tags((string) $v['tag_teks']));
        simpan_versi($id, 'Dipulihkan dari versi ' . $v['versi']);
        db()->commit();
        flash('ok', 'Konten dipulihkan dari versi ' . $v['versi'] . ' sebagai versi baru.');
    }
    redirect('kms/versi.php?id=' . $id);
}

$versi = rows('SELECT v.*,p.nama editor,k.nama kn FROM artikel_versi v JOIN pegawai p ON p.id=v.id_editor JOIN kategori_kms k ON k.id=v.id_kategori WHERE v.id_artikel=? ORDER BY v.versi DESC', [$id]);
$pilih = (int) get('v') ?: (int) ($versi[0]['versi'] ?? 0);
$cur = null; $prev = null;
foreach ($versi as $i => $v) if ((int) $v['versi'] === $pilih) { $cur = $v; $prev = $versi[$i + 1] ?? null; }

page_start('Riwayat Versi', 'kms', e($a['judul']));
?>
<div class="card-h">
  <div class="actions"><?= badge('🕘 ' . count($versi) . ' versi tersimpan', 'y') ?><?= badge('Versi aktif: v' . $a['versi'], 'g') ?></div>
  <a class="btn ghost sm" href="<?= url('kms/view.php?id=' . $id) ?>">← Kembali ke knowledge</a>
</div>

<div class="grid" style="grid-template-columns:300px minmax(0,1fr)" id="versiLayout">
  <div class="card">
    <h3 style="margin-bottom:14px">📜 Linimasa</h3>
    <?php if (!$versi): echo empty_state('📜', 'Belum ada riwayat versi'); else: ?>
    <div class="timeline">
      <?php foreach ($versi as $v): ?>
        <div class="tl <?= (int) $v['versi'] === $pilih ? 'on' : '' ?>">
          <a href="?id=<?= $id ?>&v=<?= $v['versi'] ?>"><b>Versi <?= $v['versi'] ?></b></a><?= (int) $v['versi'] === (int) $a['versi'] ? ' ' . badge('terkini', 'g') : '' ?><br>
          <small><?= e($v['catatan_perubahan'] ?: '-') ?></small><br>
          <small>✍️ <?= e($v['editor']) ?> · <?= tgl($v['dibuat']) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($cur): ?>
    <div class="card">
      <div class="card-h">
        <h3>📄 Versi <?= $cur['versi'] ?> — <?= e($cur['judul']) ?></h3>
        <?php if ($bisaUbah && (int) $cur['versi'] !== (int) $a['versi']): ?>
          <form method="post"><?= csrf() ?><input type="hidden" name="aksi" value="pulihkan"><input type="hidden" name="versi" value="<?= $cur['versi'] ?>">
            <button class="sm o" data-confirm="Pulihkan konten dari versi <?= $cur['versi'] ?>? Konten saat ini tetap tersimpan di riwayat."><?= '↺ Pulihkan versi ini' ?></button></form>
        <?php endif; ?>
      </div>
      <dl class="meta-list">
        <dt>Kategori</dt><dd><?= e($cur['kn']) ?></dd>
        <dt>Tag</dt><dd><?= $cur['tag_teks'] ? tag_chips(parse_tags($cur['tag_teks'])) : '-' ?></dd>
        <dt>Diedit oleh</dt><dd><?= e($cur['editor']) ?> · <?= tgl($cur['dibuat']) ?></dd>
        <dt>Catatan</dt><dd><?= e($cur['catatan_perubahan'] ?: '-') ?></dd>
      </dl>
      <hr>
      <p class="lead"><?= e($cur['ringkasan']) ?></p>
      <div class="article-body"><?= e($cur['isi']) ?></div>
    </div>

    <div class="card">
      <h3 style="margin-bottom:12px">🔍 Perubahan dibanding <?= $prev ? 'versi ' . $prev['versi'] : 'sebelumnya' ?></h3>
      <?php if (!$prev): echo empty_state('🌱', 'Ini versi pertama', 'Belum ada versi sebelumnya untuk dibandingkan.');
      else:
        $ubah = [];
        if ($prev['judul'] !== $cur['judul']) $ubah[] = 'Judul: “' . e($prev['judul']) . '” → “' . e($cur['judul']) . '”';
        if ($prev['id_kategori'] !== $cur['id_kategori']) $ubah[] = 'Kategori: ' . e($prev['kn']) . ' → ' . e($cur['kn']);
        if ($prev['ringkasan'] !== $cur['ringkasan']) $ubah[] = 'Ringkasan diubah';
        if ((string) $prev['tag_teks'] !== (string) $cur['tag_teks']) $ubah[] = 'Tag: ' . e($prev['tag_teks'] ?: '-') . ' → ' . e($cur['tag_teks'] ?: '-');
        if ($ubah) echo '<div class="alert info"><span>✏️</span><div>' . implode('<br>', $ubah) . '</div></div>';
        echo $prev['isi'] === $cur['isi'] ? '<p class="muted">Isi tidak berubah.</p>' : '<p class="hint">Isi: <span class="badge g">+ ditambah</span> <span class="badge r">− dihapus</span></p>' . diff_baris($prev['isi'], $cur['isi']);
      endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<style>@media(max-width:1100px){#versiLayout{grid-template-columns:1fr!important}}</style>
<?php page_end();

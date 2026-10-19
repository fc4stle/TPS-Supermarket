<?php
require __DIR__ . '/../includes/app.php';
need();
$boss = is_boss();

$tab  = pilih(get('tab'), ['semua', 'saya', 'review'], 'semua');
if ($tab === 'review' && !$boss) $tab = 'semua';
$cari = get('c');
$kat  = (int) get('k');
$tag  = get('tag');
$urut = pilih(get('urut'), ['baru', 'populer', 'membantu'], 'baru');

$w = []; $p = [];
if ($tab === 'semua') {
    $w[] = "a.status='published'";
    if (!$boss) $w[] = "a.visibilitas='internal'";
} elseif ($tab === 'saya') {
    $w[] = 'a.id_penulis=?'; $p[] = uid();
} else {
    $w[] = "a.status='menunggu_review'";
}
if ($cari !== '') {
    $w[] = '(a.judul LIKE ? OR a.ringkasan LIKE ? OR a.isi LIKE ? OR EXISTS(SELECT 1 FROM artikel_tag x JOIN tag_kms t ON t.id=x.id_tag WHERE x.id_artikel=a.id AND t.nama LIKE ?))';
    array_push($p, "%$cari%", "%$cari%", "%$cari%", "%$cari%");
}
if ($kat) { $w[] = 'a.id_kategori=?'; $p[] = $kat; }
if ($tag !== '') { $w[] = 'EXISTS(SELECT 1 FROM artikel_tag x JOIN tag_kms t ON t.id=x.id_tag WHERE x.id_artikel=a.id AND t.nama=?)'; $p[] = $tag; }
$order = ['baru' => 'a.diperbarui DESC', 'populer' => 'a.dilihat DESC, a.diperbarui DESC', 'membantu' => 'fb_ya DESC, a.dilihat DESC'][$urut];
$W = implode(' AND ', $w);

$total = (int) val("SELECT COUNT(*) FROM artikel_kms a WHERE $W", $p);
$pg = paginate($total, 12);
$artikel = rows("SELECT a.*, k.nama kn, k.ikon ki, p.nama pn,
        (SELECT COUNT(*) FROM feedback_kms f WHERE f.id_artikel=a.id AND f.membantu=1) fb_ya,
        (SELECT COUNT(*) FROM feedback_kms f WHERE f.id_artikel=a.id) fb_total,
        (SELECT GROUP_CONCAT(t.nama ORDER BY t.nama SEPARATOR ',') FROM artikel_tag x JOIN tag_kms t ON t.id=x.id_tag WHERE x.id_artikel=a.id) tags
    FROM artikel_kms a JOIN kategori_kms k ON k.id=a.id_kategori JOIN pegawai p ON p.id=a.id_penulis
    WHERE $W ORDER BY $order LIMIT ? OFFSET ?", array_merge($p, [$pg['per'], $pg['offset']]));

$visSql = $boss ? '' : " AND a.visibilitas='internal'";
$kategori = rows("SELECT k.*, (SELECT COUNT(*) FROM artikel_kms a WHERE a.id_kategori=k.id AND a.status='published'$visSql) n FROM kategori_kms k WHERE k.aktif=1 ORDER BY k.nama");
$tagPopuler = rows("SELECT t.nama, COUNT(*) n FROM artikel_tag x JOIN tag_kms t ON t.id=x.id_tag JOIN artikel_kms a ON a.id=x.id_artikel WHERE a.status='published'$visSql GROUP BY t.id,t.nama ORDER BY n DESC, t.nama LIMIT 15");
$nReview = $boss ? (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='menunggu_review'") : 0;
$nSaya = (int) val('SELECT COUNT(*) FROM artikel_kms WHERE id_penulis=?', [uid()]);

page_start($tab === 'review' ? 'Review Knowledge' : 'Knowledge Center', $tab === 'review' ? 'kms-review' : 'kms', 'Cari SOP, panduan produk, troubleshooting, dan pengetahuan organisasi.');
?>
<section class="hero-banner" style="background:linear-gradient(120deg,#14532d,#15803d 60%,#65a30d)">
  <div style="flex:1;min-width:0">
    <span class="hb-kicker">📚 KMS · Pusat Pengetahuan</span>
    <h2>Apa yang ingin kamu pelajari hari ini?</h2>
    <form method="get" class="row" style="margin-top:12px;max-width:640px">
      <input type="hidden" name="tab" value="<?= $tab ?>">
      <div style="flex:3"><input name="c" value="<?= e($cari) ?>" placeholder="🔍 Cari judul, isi, atau tag…" style="border:0"></div>
      <div><select name="k" style="border:0"><option value="0">Semua kategori</option><?php foreach ($kategori as $k) echo '<option value="' . $k['id'] . '"' . ($kat === (int) $k['id'] ? ' selected' : '') . '>' . e($k['nama']) . '</option>'; ?></select></div>
      <div class="fit"><button type="submit" class="y">Cari Knowledge</button></div>
    </form>
  </div>
  <div class="hb-fruit" aria-hidden="true">📖💡</div>
</section>

<div class="card-h">
  <div class="tabs" style="margin-bottom:0;border:0">
    <a class="<?= $tab === 'semua' ? 'on' : '' ?>" href="?tab=semua">📚 Semua Knowledge</a>
    <a class="<?= $tab === 'saya' ? 'on' : '' ?>" href="?tab=saya">🙋 Kontribusi Saya<span class="count"><?= $nSaya ?></span></a>
    <?php if ($boss): ?><a class="<?= $tab === 'review' ? 'on' : '' ?>" href="?tab=review">📝 Review<?= $nReview ? '<span class="count">' . $nReview . '</span>' : '' ?></a><?php endif; ?>
  </div>
  <div class="actions">
    <select onchange="location.href=this.value" style="width:auto">
      <?php foreach (['baru' => 'Terbaru', 'populer' => 'Paling dilihat', 'membantu' => 'Paling membantu'] as $k => $v) echo '<option value="' . e(qs(['urut' => $k, 'hal' => null])) . '"' . ($urut === $k ? ' selected' : '') . '>Urut: ' . $v . '</option>'; ?>
    </select>
    <a class="btn" href="<?= url('kms/tulis.php') ?>">✍️ Tulis Knowledge</a>
  </div>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 270px;margin-top:16px" id="kmsLayout">
  <div>
    <?php if ($cari !== '' || $kat || $tag !== ''): ?>
      <div class="alert info"><span>🔎</span><div>Menampilkan <b><?= $total ?></b> hasil
        <?= $cari !== '' ? ' untuk “<b>' . e($cari) . '</b>”' : '' ?><?= $tag !== '' ? ' dengan tag <b>#' . e($tag) . '</b>' : '' ?>.
        <a href="?tab=<?= $tab ?>">Reset filter</a></div></div>
    <?php endif; ?>
    <?php if (!$artikel):
        $msg = ['semua' => ['📭', 'Belum ada knowledge yang cocok', 'Jadilah yang pertama berbagi — klik “Tulis Knowledge”.'],
                'saya' => ['✍️', 'Kamu belum menulis knowledge', 'Bagikan SOP, tips, atau solusi masalah yang kamu kuasai.'],
                'review' => ['🎉', 'Tidak ada antrean review', 'Semua kontribusi staff sudah ditinjau.']][$tab];
        echo '<div class="card">' . empty_state(...$msg) . '</div>';
    else: ?>
    <div class="kms-grid">
      <?php foreach ($artikel as $a): ?>
      <article class="kcard <?= e($a['status']) ?>">
        <div class="actions"><?= badge($a['ki'] . ' ' . $a['kn'], 'g') ?><?= status_badge($a['visibilitas']) ?><?= $a['status'] !== 'published' ? status_badge($a['status']) : '' ?></div>
        <h3><a href="<?= url('kms/view.php?id=' . $a['id']) ?>"><?= e($a['judul']) ?></a></h3>
        <p><?= e($a['ringkasan']) ?></p>
        <div class="kmeta"><span>✍️ <?= e($a['pn']) ?></span><span>👁 <?= angka($a['dilihat']) ?>x</span><span>👍 <?= $a['fb_ya'] ?>/<?= $a['fb_total'] ?></span><span>🕘 v<?= $a['versi'] ?> · <?= lalu($a['diperbarui']) ?></span></div>
        <?php if ($a['status'] === 'ditolak' && $a['catatan_review']): ?><div class="alert err" style="margin:0;padding:8px 12px;font-size:13px"><span>💬</span><div><?= e($a['catatan_review']) ?></div></div><?php endif; ?>
        <div><?= tag_chips(array_filter(explode(',', $a['tags'] ?? ''))) ?></div>
      </article>
      <?php endforeach; ?>
    </div>
    <?= pager($pg) ?>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card">
      <h3 style="margin-bottom:10px">🗂️ Kategori</h3>
      <div class="list">
        <?php foreach ($kategori as $k): ?>
          <a class="li" href="?tab=semua&k=<?= $k['id'] ?>" style="text-decoration:none;<?= $kat === (int) $k['id'] ? 'background:var(--gxl);border-radius:10px' : '' ?>">
            <span class="li-ic"><?= e($k['ikon']) ?></span><div class="li-body"><b><?= e($k['nama']) ?></b><small><?= e($k['deskripsi']) ?></small></div><span class="badge n"><?= $k['n'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($boss): ?><a class="btn sm ghost block" style="margin-top:10px" href="<?= url('kms/kategori.php') ?>">⚙️ Kelola kategori</a><?php endif; ?>
    </div>
    <div class="card">
      <h3 style="margin-bottom:10px">🏷️ Tag Populer</h3>
      <?= $tagPopuler ? tag_chips(array_column($tagPopuler, 'nama')) : '<small>Belum ada tag.</small>' ?>
    </div>
  </aside>
</div>
<style>@media(max-width:1100px){#kmsLayout{grid-template-columns:1fr!important}}</style>
<?php page_end();

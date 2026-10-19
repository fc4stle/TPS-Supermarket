<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];

$k = mis_kms($d, $s); $k0 = mis_kms($pd, $ps);

$art = rows("SELECT a.id, a.judul, a.status, a.dilihat, a.versi, a.dibuat, a.diperbarui, c.nama kategori, p.nama penulis, p.role,
        (SELECT COUNT(*) FROM feedback_kms f WHERE f.id_artikel=a.id) fb,
        (SELECT COALESCE(SUM(f.membantu=1),0) FROM feedback_kms f WHERE f.id_artikel=a.id) ya
    FROM artikel_kms a JOIN kategori_kms c ON c.id=a.id_kategori JOIN pegawai p ON p.id=a.id_penulis ORDER BY a.dilihat DESC");
if (get('export') === 'csv') {
    mis_csv('mis-knowledge', ['Judul', 'Kategori', 'Penulis', 'Role', 'Status', 'Versi', 'Dilihat', 'Feedback', 'Membantu', 'Helpful Rate %', 'Dibuat'],
        array_map(fn($a) => [$a['judul'], $a['kategori'], $a['penulis'], $a['role'], $a['status'], $a['versi'], $a['dilihat'], $a['fb'], $a['ya'], $a['fb'] ? round($a['ya'] / $a['fb'] * 100, 1) : '', $a['dibuat']], $art));
}
$pub = array_values(array_filter($art, fn($a) => $a['status'] === 'published'));
$membantu = array_values(array_filter($pub, fn($a) => $a['fb'] >= 2));
usort($membantu, fn($a, $b) => [$b['ya'] / $b['fb'], $b['fb']] <=> [$a['ya'] / $a['fb'], $a['fb']]);
$perhatian = array_values(array_filter($pub, fn($a) => ($a['fb'] >= 2 && $a['ya'] / $a['fb'] < 0.67) || $a['dilihat'] == 0));
usort($perhatian, fn($a, $b) => ($a['fb'] ? $a['ya'] / $a['fb'] : 2) <=> ($b['fb'] ? $b['ya'] / $b['fb'] : 2));

$kat = rows("SELECT c.nama, c.ikon, COUNT(a.id) n, COALESCE(SUM(a.dilihat),0) v FROM kategori_kms c LEFT JOIN artikel_kms a ON a.id_kategori=c.id AND a.status='published'
             WHERE c.aktif=1 GROUP BY c.id, c.nama, c.ikon ORDER BY v DESC");
$kontrib = rows("SELECT p.nama, p.role, COUNT(*) n, SUM(a.status='published') pub, SUM(a.dilihat) v, SUM(DATE(a.dibuat) BETWEEN ? AND ?) baru
                 FROM artikel_kms a JOIN pegawai p ON p.id=a.id_penulis GROUP BY p.id, p.nama, p.role ORDER BY n DESC, v DESC", [$d, $s]);
$perRole = array_column(rows("SELECT p.role, COUNT(*) n FROM artikel_kms a JOIN pegawai p ON p.id=a.id_penulis GROUP BY p.role"), 'n', 'role');
$pembaca = rows('SELECT p.nama, COUNT(*) n, SUM(f.membantu=1) ya FROM feedback_kms f JOIN pegawai p ON p.id=f.id_pegawai WHERE DATE(f.dibuat) BETWEEN ? AND ? GROUP BY p.id, p.nama ORDER BY n DESC LIMIT 5', [$d, $s]);
$tags = rows("SELECT t.nama, COUNT(*) n FROM artikel_tag x JOIN tag_kms t ON t.id=x.id_tag JOIN artikel_kms a ON a.id=x.id_artikel WHERE a.status='published' GROUP BY t.id, t.nama ORDER BY n DESC, t.nama LIMIT 14");
$antre = rows("SELECT a.id, a.judul, p.nama, DATEDIFF(CURDATE(), DATE(a.diperbarui)) hari FROM artikel_kms a JOIN pegawai p ON p.id=a.id_penulis WHERE a.status='menunggu_review' ORDER BY a.diperbarui");
$revisi = (int) val('SELECT COUNT(*) FROM artikel_versi WHERE versi>1 AND DATE(dibuat) BETWEEN ? AND ?', [$d, $s]);
// tren kontribusi per minggu (9 minggu)
$mg = [];
for ($i = 8; $i >= 0; $i--) {
    $a = date('Y-m-d', strtotime('monday this week -' . $i . ' week')); $b = date('Y-m-d', strtotime($a . ' +6 days'));
    $mg[] = [date('j/n', strtotime($a)), (int) val('SELECT COUNT(*) FROM artikel_kms WHERE DATE(dibuat) BETWEEN ? AND ?', [$a, $b])];
}
$hr = fn($a) => $a['fb'] ? persen($a['ya'] / $a['fb'] * 100, 0) : '–';

mis_start('Analitik KMS', 'mis-kms', 'Pertumbuhan, pemakaian, dan kualitas knowledge perusahaan — dari modul KMS.', $per, true);
?>
<div class="kpis">
  <?= kpi('Knowledge Published', (string) $k['published'], '📚', '', $k['baru'] . ' artikel baru pada periode', 'g', 'kms/index.php') ?>
  <?= kpi('Menunggu Review', (string) $k['menunggu'], '📝', '', $antre ? 'tertua ' . $antre[0]['hari'] . ' hari' : 'antrean kosong', $k['menunggu'] ? 'y' : 'g', 'kms/index.php?tab=review') ?>
  <?= kpi('Ditolak', (string) $k['ditolak'], '✖️', '', 'perlu diperbaiki penulis', $k['ditolak'] ? 'r' : 'g') ?>
  <?= kpi('Total Dilihat', angka($k['dilihat']), '👁', '', 'kumulatif · ' . ($k['published'] ? number_format($k['dilihat'] / $k['published'], 1, ',', '.') : 0) . ' per artikel', 'b') ?>
  <?= kpi('Helpful Rate', persen($k['helpful']), '👍', delta($k['helpful'], $k0['helpful'], true, true), $k['ya'] . ' dari ' . $k['fb'] . ' feedback periode ini', 'o') ?>
  <?= kpi('Revisi Knowledge', (string) $revisi, '🕘', '', 'versi baru pada periode', 'p') ?>
</div>

<div class="grid g2">
  <?= panel('🗂️ Knowledge per Kategori', hbars(array_map(fn($x) => [e($x['ikon'] . ' ' . $x['nama']), $x['v'], angka($x['v']) . 'x dilihat', $x['n'] . ' artikel published'], $kat), 'g', 'Belum ada kategori'), 'kategori_kms, artikel_kms') ?>
  <section class="card panel"><div class="card-h"><h3>🛡️ Status &amp; Sumber Knowledge</h3><small><?= count($art) ?> artikel</small></div>
    <?= stack([['Published', $k['published'], 'c-a'], ['Menunggu review', $k['menunggu'], 'c-y'], ['Ditolak', $k['ditolak'], 'c-r']], 'artikel') ?>
    <h4 style="margin:18px 0 6px;font-size:13.5px">Kontribusi menurut role penulis</h4>
    <?= stack([['Staff', (int) ($perRole['staff'] ?? 0), 'c-b'], ['Manajer', (int) ($perRole['manajer'] ?? 0), 'c-o'], ['Admin', (int) ($perRole['admin'] ?? 0), 'c-n']], 'artikel') ?>
    <?= sumber('artikel_kms, pegawai') ?>
  </section>
</div>

<div class="grid g2">
  <section class="card panel"><div class="card-h"><h3>👁 5 Knowledge Paling Dilihat</h3></div>
    <?= tabel(['Knowledge', 'Kategori', '#Dilihat', '#Helpful'], array_map(fn($a) => ['<a href="' . url('kms/view.php?id=' . $a['id']) . '"><b>' . e($a['judul']) . '</b></a><br><small>' . e($a['penulis']) . ' · v' . $a['versi'] . '</small>', badge($a['kategori'], 'n'), '<b>' . angka($a['dilihat']) . 'x</b>', $hr($a)], array_slice($pub, 0, 5)), 'Belum ada knowledge published') ?>
    <?= sumber('artikel_kms.dilihat') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>👍 5 Knowledge Paling Membantu</h3><small>minimal 2 feedback</small></div>
    <?= tabel(['Knowledge', '#Feedback', '#Membantu', '#Helpful'], array_map(fn($a) => ['<a href="' . url('kms/view.php?id=' . $a['id']) . '"><b>' . e($a['judul']) . '</b></a><br><small>' . e($a['penulis']) . '</small>', $a['fb'], $a['ya'], '<b class="pill-ok">' . $hr($a) . '</b>'], array_slice($membantu, 0, 5)), 'Belum ada knowledge dengan 2 feedback atau lebih') ?>
    <?= sumber('feedback_kms') ?>
  </section>
</div>

<div class="grid g2">
  <section class="card panel"><div class="card-h"><h3>🩺 Knowledge Perlu Perhatian</h3><small>helpful rate rendah atau belum dibaca</small></div>
    <?= tabel(['Knowledge', '#Dilihat', '#Helpful', 'Masalah'], array_map(fn($a) => ['<a href="' . url('kms/view.php?id=' . $a['id']) . '"><b>' . e($a['judul']) . '</b></a><br><small>' . e($a['penulis']) . ' · ' . e($a['kategori']) . '</small>', angka($a['dilihat']) . 'x',
        $a['fb'] ? '<b class="pill-bad">' . $hr($a) . '</b> <small>(' . $a['ya'] . '/' . $a['fb'] . ')</small>' : '–', $a['dilihat'] == 0 ? badge('Belum pernah dibaca', 'n') : badge('Dinilai kurang membantu', 'r')], array_slice($perhatian, 0, 6)), 'Semua knowledge dalam kondisi baik ✅') ?>
    <?= sumber('artikel_kms, feedback_kms') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>📝 Antrean Review</h3><small><?= count($antre) ?> menunggu</small></div>
    <?= tabel(['Knowledge', 'Penulis', '#Menunggu'], array_map(fn($a) => ['<a href="' . url('kms/view.php?id=' . $a['id']) . '"><b>' . e($a['judul']) . '</b></a>', e($a['nama']), '<span class="' . ($a['hari'] > 2 ? 'low' : '') . '">' . $a['hari'] . ' hari</span>'], $antre), 'Tidak ada antrean review 🎉') ?>
    <h4 style="margin:16px 0 8px;font-size:13.5px">🏷️ Tag terpopuler</h4>
    <div><?php foreach ($tags as $t) echo '<a class="chip-tag" href="' . url('kms/index.php?tag=' . urlencode($t['nama'])) . '">#' . e($t['nama']) . ' · ' . $t['n'] . '</a>'; if (!$tags) echo '<small>Belum ada tag.</small>'; ?></div>
    <?= sumber('artikel_kms, artikel_tag, tag_kms') ?>
  </section>
</div>

<div class="grid g-23">
  <section class="card panel"><div class="card-h"><h3>✍️ Kontributor Knowledge</h3><small>seluruh waktu · kolom “baru” = periode terpilih</small></div>
    <?= tabel(['Penulis', 'Role', '#Artikel', '#Published', '#Baru', '#Total dilihat'], array_map(fn($x) => ['<b>' . e($x['nama']) . '</b>', status_badge($x['role']), $x['n'], $x['pub'], $x['baru'], angka($x['v']) . 'x'], $kontrib), 'Belum ada kontributor') ?>
    <?= sumber('artikel_kms, pegawai') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>📖 Pembaca Teraktif</h3><small>pemberi feedback pada periode</small></div>
    <?php if (!$pembaca) echo empty_state('📖', 'Belum ada feedback pada periode ini');
    foreach ($pembaca as $x) echo '<div class="rank"><span class="avatar" style="width:30px;height:30px;font-size:11px">' . e(inisial($x['nama'])) . '</span><div class="rb"><b>' . e($x['nama']) . '</b><small>' . $x['ya'] . ' dinilai membantu</small></div><span class="rv">' . $x['n'] . ' feedback</span></div>'; ?>
    <?= sumber('feedback_kms, pegawai') ?>
  </section>
</div>

<?= panel('📆 Tren Kontribusi Knowledge <small>(artikel baru per minggu, 9 minggu terakhir)</small>', array_sum(array_column($mg, 1)) ? kolom($mg, false, 150) : empty_state('📆', 'Belum ada kontribusi dalam 9 minggu terakhir'), 'artikel_kms.dibuat') ?>
<?php mis_end();

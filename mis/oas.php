<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];

$o = mis_oas($d, $s); $o0 = mis_oas($pd, $ps);

// beban kerja & ketepatan per staff
$staff = rows("SELECT p.id, p.nama, p.jabatan,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.status!='selesai') aktif,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.status!='selesai' AND t.tenggat<CURDATE()) telat,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.tenggat BETWEEN ? AND ?) jatuh,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.tenggat BETWEEN ? AND ? AND t.status='selesai') selesai,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.tenggat BETWEEN ? AND ? AND t.status='selesai' AND DATE(t.selesai_pada)<=t.tenggat) tepat,
        (SELECT AVG(TIMESTAMPDIFF(HOUR,t.dibuat,t.selesai_pada)) FROM tugas t WHERE t.id_staff=p.id AND t.status='selesai' AND DATE(t.selesai_pada) BETWEEN ? AND ?) lama
    FROM pegawai p WHERE p.role='staff' AND p.aktif=1 ORDER BY aktif DESC, p.nama", [$d, $s, $d, $s, $d, $s, $d, $s]);

if (get('export') === 'csv') {
    mis_csv('mis-beban-kerja-staff', ['Staff', 'Jabatan', 'Tugas Aktif', 'Terlambat', 'Jatuh Tempo (periode)', 'Selesai', 'Tepat Waktu', '% Tepat Waktu', 'Rata-rata Lama (jam)'],
        array_map(fn($x) => [$x['nama'], $x['jabatan'], $x['aktif'], $x['telat'], $x['jatuh'], $x['selesai'], $x['tepat'], round(rasio($x['tepat'], $x['jatuh']), 1), $x['lama'] === null ? '' : round($x['lama'])], $staff));
}

$status = array_column(rows('SELECT status, COUNT(*) n FROM tugas WHERE DATE(dibuat) BETWEEN ? AND ? GROUP BY status', [$d, $s]), 'n', 'status');
$prio = array_column(rows("SELECT prioritas, COUNT(*) n, SUM(status='selesai') s, SUM(status='selesai' AND DATE(selesai_pada)<=tenggat) t FROM tugas WHERE tenggat BETWEEN ? AND ? GROUP BY prioritas", [$d, $s]), null, 'prioritas');
$telat = rows("SELECT t.judul, t.prioritas, t.tenggat, t.status, DATEDIFF(CURDATE(), t.tenggat) hari, p.nama FROM tugas t JOIN pegawai p ON p.id=t.id_staff
               WHERE t.status!='selesai' AND t.tenggat<CURDATE() ORDER BY t.tenggat", []);
$dok = rows('SELECT kategori, COUNT(*) n FROM dokumen GROUP BY kategori ORDER BY n DESC');
$dokBaru = (int) val('SELECT COUNT(*) FROM dokumen WHERE DATE(dibuat) BETWEEN ? AND ?', [$d, $s]);
$unggah = rows('SELECT p.nama, COUNT(*) n FROM dokumen d JOIN pegawai p ON p.id=d.id_pengunggah GROUP BY p.id, p.nama ORDER BY n DESC LIMIT 4');
$kom = [
    'pengumuman' => (int) val('SELECT COUNT(*) FROM pengumuman WHERE DATE(dibuat) BETWEEN ? AND ?', [$d, $s]),
    'penting' => (int) val('SELECT COUNT(*) FROM pengumuman WHERE penting=1 AND DATE(dibuat) BETWEEN ? AND ?', [$d, $s]),
    'agenda' => (int) val('SELECT COUNT(*) FROM agenda WHERE DATE(mulai) BETWEEN ? AND ?', [$d, $s]),
    'mendatang' => (int) val('SELECT COUNT(*) FROM agenda WHERE mulai>=NOW()'),
    'pesan' => (int) val('SELECT COUNT(*) FROM pesan WHERE DATE(dibuat) BETWEEN ? AND ?', [$d, $s]),
    'dibaca' => (int) val('SELECT COUNT(*) FROM pesan WHERE dibaca=1 AND DATE(dibuat) BETWEEN ? AND ?', [$d, $s]),
    'tunda' => (int) val('SELECT COUNT(*) FROM pesan WHERE dibaca=0'),
];
$pengirim = rows('SELECT p.nama, COUNT(*) n FROM pesan m JOIN pegawai p ON p.id=m.id_pengirim WHERE DATE(m.dibuat) BETWEEN ? AND ? GROUP BY p.id, p.nama ORDER BY n DESC LIMIT 4', [$d, $s]);
// tren mingguan: tugas dibuat vs selesai (8 minggu terakhir)
$mg = [];
for ($i = 7; $i >= 0; $i--) {
    $a = date('Y-m-d', strtotime('monday this week -' . $i . ' week')); $b = date('Y-m-d', strtotime($a . ' +6 days'));
    $mg[] = [date('j/n', strtotime($a)), (int) val('SELECT COUNT(*) FROM tugas WHERE DATE(dibuat) BETWEEN ? AND ?', [$a, $b]), (int) val("SELECT COUNT(*) FROM tugas WHERE status='selesai' AND DATE(selesai_pada) BETWEEN ? AND ?", [$a, $b])];
}
$mxM = max(array_merge([1], array_column($mg, 1), array_column($mg, 2)));

mis_start('Monitoring OAS', 'mis-oas', 'Penyelesaian tugas, beban kerja staff, dokumen, dan komunikasi internal — dari modul OAS.', $per, true);
?>
<div class="kpis">
  <?= kpi('Tugas Aktif', (string) $o['aktif'], '📋', '', 'saat ini (terbuka + dikerjakan)', 'b', 'oas/tugas.php') ?>
  <?= kpi('Tugas Terlambat', (string) $o['telat'], '⏰', '', 'melewati tenggat', $o['telat'] ? 'r' : 'g', 'oas/tugas.php') ?>
  <?= kpi('Tingkat Penyelesaian', persen($o['rate']), '✅', delta($o['rate'], $o0['rate'], true, true), $o['selesai'] . ' dari ' . $o['jatuh'] . ' tugas jatuh tempo', 'g') ?>
  <?= kpi('Selesai Tepat Waktu', persen($o['tepat_rate']), '🎯', delta($o['tepat_rate'], $o0['tepat_rate'], true, true), $o['tepat'] . ' tugas tepat waktu', 'y') ?>
  <?= kpi('Rata-rata Lama Pengerjaan', lama_teks($o['lama']), '⏱️', $o['lama'] !== null && $o0['lama'] !== null ? delta($o['lama'], $o0['lama'], false) : '', 'dari dibuat sampai selesai', 'p') ?>
</div>

<div class="grid g2">
  <section class="card panel"><div class="card-h"><h3>📊 Status Tugas</h3><small>tugas yang dibuat pada periode ini</small></div>
    <?= stack([['Terbuka', (int) ($status['terbuka'] ?? 0), 'c-o'], ['Dikerjakan', (int) ($status['proses'] ?? 0), 'c-b'], ['Selesai', (int) ($status['selesai'] ?? 0), 'c-a']], 'tugas') ?>
    <h4 style="margin:18px 0 8px;font-size:13.5px">Menurut prioritas <small>(tugas jatuh tempo pada periode)</small></h4>
    <?= tabel(['Prioritas', '#Jumlah', '#Selesai', '#Tepat waktu', '#% Selesai'], array_values(array_filter(array_map(fn($k) => isset($prio[$k]) ? [status_badge($k), $prio[$k]['n'], $prio[$k]['s'], $prio[$k]['t'], persen(rasio($prio[$k]['s'], $prio[$k]['n']))] : null, ['tinggi', 'sedang', 'rendah']))), 'Tidak ada tugas jatuh tempo pada periode ini') ?>
    <?= sumber('tugas') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>📆 Tugas Dibuat vs Selesai</h3><small>per minggu, 8 minggu terakhir</small></div>
    <div class="cols-wrap"><div class="cols-y"><span><?= $mxM ?></span><span><?= round($mxM / 2) ?></span><span>0</span></div><div class="cols-plot">
      <div class="cols" style="height:170px;gap:10px"><?php foreach ($mg as [$l, $x, $y]): ?>
        <div class="col" style="flex-direction:row;align-items:flex-end;gap:2px">
          <i class="c-b" style="flex:1;width:auto;height:<?= $x / $mxM * 100 ?>%" title="Minggu <?= $l ?>: <?= $x ?> tugas dibuat"></i>
          <i class="c-a" style="flex:1;width:auto;height:<?= $y / $mxM * 100 ?>%" title="Minggu <?= $l ?>: <?= $y ?> tugas selesai"></i></div>
      <?php endforeach; ?></div>
      <div class="col-labels" style="gap:10px"><?php foreach ($mg as [$l]) echo '<span>' . $l . '</span>'; ?></div></div></div>
    <div class="legend"><span><i class="c-b"></i>Dibuat</span><span><i class="c-a"></i>Selesai</span></div>
    <?= sumber('tugas.dibuat, tugas.selesai_pada') ?>
  </section>
</div>

<section class="card panel"><div class="card-h"><h3>👥 Beban Kerja &amp; Ketepatan per Staff</h3><small>aktif = kondisi saat ini · lainnya = periode terpilih</small></div>
  <?= tabel(['Staff', '#Tugas aktif', '#Terlambat', '#Jatuh tempo', '#Selesai', '#Tepat waktu', '#% Tepat waktu', '#Rata-rata lama'], array_map(fn($x) => [
      '<b>' . e($x['nama']) . '</b><br><small>' . e($x['jabatan'] ?: '-') . '</small>', $x['aktif'] >= 4 ? '<b class="low">' . $x['aktif'] . ' ⚠️</b>' : $x['aktif'],
      $x['telat'] ? '<b class="low">' . $x['telat'] . '</b>' : '0', $x['jatuh'], $x['selesai'], $x['tepat'], $x['jatuh'] ? persen(rasio($x['tepat'], $x['jatuh'])) : '–', lama_teks($x['lama'] === null ? null : (float) $x['lama'])], $staff), 'Belum ada akun staff') ?>
  <?= sumber('tugas, pegawai') ?>
</section>

<section class="card panel"><div class="card-h"><h3>⏰ Tugas Melewati Tenggat</h3><small><?= count($telat) ?> tugas</small></div>
  <?= tabel(['Tugas', 'Staff', 'Prioritas', 'Tenggat', '#Terlambat', 'Status'], array_map(fn($x) => ['<b>' . e($x['judul']) . '</b>', e($x['nama']), status_badge($x['prioritas']), tgl($x['tenggat'], false), '<b class="low">' . $x['hari'] . ' hari</b>', status_badge($x['status'])], $telat), 'Tidak ada tugas yang terlambat 🎉') ?>
  <?= sumber('tugas') ?>
</section>

<div class="grid g3">
  <section class="card panel"><div class="card-h"><h3>📁 Dokumen Operasional</h3><small><?= array_sum(array_column($dok, 'n')) ?> dokumen</small></div>
    <?= hbars(array_map(fn($x) => [e($x['kategori']), $x['n'], $x['n'] . ' file'], $dok), 'g', 'Belum ada dokumen') ?>
    <div class="mini"><div><small>Diunggah pada periode</small><b><?= $dokBaru ?> dokumen</b></div></div>
    <?= sumber('dokumen') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>📢 Komunikasi Internal</h3></div>
    <div class="modul" style="box-shadow:none;border:0;padding:0"><dl>
      <dt>Pengumuman pada periode</dt><dd><?= $kom['pengumuman'] ?> <small>(<?= $kom['penting'] ?> penting)</small></dd>
      <dt>Agenda pada periode</dt><dd><?= $kom['agenda'] ?></dd>
      <dt>Agenda mendatang</dt><dd><?= $kom['mendatang'] ?></dd>
      <dt>Pesan terkirim</dt><dd><?= $kom['pesan'] ?></dd>
      <dt>Pesan sudah dibaca</dt><dd><?= $kom['pesan'] ? persen(rasio($kom['dibaca'], $kom['pesan'])) : '–' ?></dd>
      <dt>Pesan belum dibaca (total)</dt><dd class="<?= $kom['tunda'] ? 'pill-bad' : 'pill-ok' ?>"><?= $kom['tunda'] ?></dd>
    </dl></div>
    <p class="hint" style="margin-top:10px">MIS hanya menghitung jumlah, isi pesan tidak ditampilkan.</p>
    <?= sumber('pengumuman, agenda, pesan') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>🙋 Paling Aktif</h3></div>
    <h4 style="font-size:12.5px;color:var(--mut);margin-bottom:4px">Pengunggah dokumen</h4>
    <?php if (!$unggah) echo '<small>Belum ada.</small>';
    foreach ($unggah as $x) echo '<div class="rank"><span class="avatar" style="width:30px;height:30px;font-size:11px">' . e(inisial($x['nama'])) . '</span><div class="rb"><b>' . e($x['nama']) . '</b></div><span class="rv">' . $x['n'] . ' file</span></div>'; ?>
    <h4 style="font-size:12.5px;color:var(--mut);margin:14px 0 4px">Pengirim pesan (periode)</h4>
    <?php if (!$pengirim) echo '<small>Belum ada.</small>';
    foreach ($pengirim as $x) echo '<div class="rank"><span class="avatar" style="width:30px;height:30px;font-size:11px">' . e(inisial($x['nama'])) . '</span><div class="rb"><b>' . e($x['nama']) . '</b></div><span class="rv">' . $x['n'] . ' pesan</span></div>'; ?>
    <?= sumber('dokumen, pesan, pegawai') ?>
  </section>
</div>
<?php mis_end();

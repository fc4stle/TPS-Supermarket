<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s] = [$per['dari'], $per['sampai']];

$peg = rows("SELECT p.id, p.nama, p.role, p.jabatan,
        (SELECT COUNT(*) FROM transaksi t WHERE t.id_kasir=p.id AND DATE(t.waktu) BETWEEN ? AND ?) trx,
        (SELECT COALESCE(SUM(t.total),0) FROM transaksi t WHERE t.id_kasir=p.id AND DATE(t.waktu) BETWEEN ? AND ?) omzet,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.tenggat BETWEEN ? AND ?) jatuh,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.tenggat BETWEEN ? AND ? AND t.status='selesai') selesai,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.tenggat BETWEEN ? AND ? AND t.status='selesai' AND DATE(t.selesai_pada)<=t.tenggat) tepat,
        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=p.id AND t.status!='selesai' AND t.tenggat<CURDATE()) telat,
        (SELECT COUNT(*) FROM artikel_kms a WHERE a.id_penulis=p.id AND DATE(a.dibuat) BETWEEN ? AND ?) artikel,
        (SELECT COUNT(*) FROM artikel_kms a WHERE a.id_penulis=p.id AND a.status='published') published,
        (SELECT COUNT(*) FROM feedback_kms f WHERE f.id_pegawai=p.id AND DATE(f.dibuat) BETWEEN ? AND ?) feedback,
        (SELECT COUNT(*) FROM stok_log l WHERE l.id_pegawai=p.id AND l.jenis IN ('restok','koreksi') AND DATE(l.waktu) BETWEEN ? AND ?) stok
    FROM pegawai p WHERE p.aktif=1 ORDER BY p.role, p.nama", [$d, $s, $d, $s, $d, $s, $d, $s, $d, $s, $d, $s, $d, $s, $d, $s]);

if (get('export') === 'csv') {
    mis_csv('mis-kinerja-pegawai', ['Pegawai', 'Role', 'Jabatan', 'Transaksi', 'Omzet', 'Rata-rata Belanja', 'Tugas Jatuh Tempo', 'Tugas Selesai', 'Tepat Waktu', 'Tugas Terlambat (aktif)', 'Knowledge Baru', 'Knowledge Published', 'Feedback Diberikan', 'Restok/Koreksi'],
        array_map(fn($x) => [$x['nama'], $x['role'], $x['jabatan'], $x['trx'], $x['omzet'], $x['trx'] ? round($x['omzet'] / $x['trx']) : 0, $x['jatuh'], $x['selesai'], $x['tepat'], $x['telat'], $x['artikel'], $x['published'], $x['feedback'], $x['stok']], $peg));
}

$top = function (array $rows, callable $nilai) { $best = null; foreach ($rows as $r) { $v = $nilai($r); if ($v !== null && $v > 0 && (!$best || $v > $best[1])) $best = [$r, $v]; } return $best; };
$kasir = $top($peg, fn($x) => (float) $x['omzet']);
$tepat = $top($peg, fn($x) => $x['jatuh'] >= 2 ? $x['tepat'] / $x['jatuh'] * 100 + $x['tepat'] / 1000 : null);
$penulis = $top($peg, fn($x) => $x['artikel'] * 10 + $x['feedback']);
$totOmzet = array_sum(array_column($peg, 'omzet'));
$kasirList = array_values(array_filter($peg, fn($x) => $x['trx'] > 0)); usort($kasirList, fn($a, $b) => $b['omzet'] <=> $a['omzet']);
$tugasList = array_values(array_filter($peg, fn($x) => $x['jatuh'] > 0)); usort($tugasList, fn($a, $b) => rasio($b['tepat'], $b['jatuh']) <=> rasio($a['tepat'], $a['jatuh']));

mis_start('Kinerja Pegawai', 'mis-pegawai', 'Kontribusi setiap pegawai di kasir (TPS), tugas (OAS), dan knowledge (KMS).', $per, true);
?>
<div class="grid g3" style="margin-bottom:20px">
  <?php foreach ([['🏆 Kasir Omzet Tertinggi', $kasir, fn($b) => ringkas($b[0]['omzet']) . ' · ' . angka($b[0]['trx']) . ' transaksi', 'TPS', 'var(--g)'],
                  ['🎯 Paling Tepat Waktu', $tepat, fn($b) => persen(rasio($b[0]['tepat'], $b[0]['jatuh']), 0) . ' tepat waktu · ' . $b[0]['tepat'] . ' dari ' . $b[0]['jatuh'] . ' tugas', 'OAS', 'var(--b)'],
                  ['✍️ Kontributor Knowledge Teraktif', $penulis, fn($b) => $b[0]['artikel'] . ' artikel baru · ' . $b[0]['feedback'] . ' feedback', 'KMS', 'var(--o)']] as [$judul, $b, $ket, $modul, $warna]): ?>
    <div class="modul" style="border-top:4px solid <?= $warna ?>">
      <h3><?= $judul ?> <?= badge($modul, 'n') ?></h3>
      <?php if ($b): ?><div class="actions"><span class="avatar"><?= e(inisial($b[0]['nama'])) ?></span><div><b style="font-size:16px"><?= e($b[0]['nama']) ?></b><br><small><?= e($b[0]['jabatan'] ?: ucfirst($b[0]['role'])) ?></small></div></div>
        <p class="mb0" style="margin-top:10px;font-weight:600"><?= $ket($b) ?></p>
      <?php else: echo '<p class="muted mb0">Belum ada data pada periode ini.</p>'; endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<section class="card panel"><div class="card-h"><h3>🏅 Rapor Kinerja Pegawai</h3><small>gabungan TPS · OAS · KMS pada periode terpilih</small></div>
  <?= tabel(['Pegawai', '#Transaksi', '#Omzet', '#Tugas selesai', '#Tepat waktu', '#Terlambat', '#Knowledge baru', '#Feedback', '#Restok'], array_map(fn($x) => [
      '<div class="actions" style="flex-wrap:nowrap"><span class="avatar" style="width:32px;height:32px;font-size:11.5px">' . e(inisial($x['nama'])) . '</span><div><b>' . e($x['nama']) . '</b><br><small>' . e($x['jabatan'] ?: '-') . '</small> ' . status_badge($x['role']) . '</div></div>',
      $x['trx'] ? angka($x['trx']) : '–', $x['trx'] ? rp($x['omzet']) : '–',
      $x['jatuh'] ? $x['selesai'] . ' / ' . $x['jatuh'] : '–', $x['jatuh'] ? '<span class="' . (rasio($x['tepat'], $x['jatuh']) >= 75 ? 'pill-ok' : 'pill-bad') . '">' . persen(rasio($x['tepat'], $x['jatuh']), 0) . '</span>' : '–',
      $x['telat'] ? '<b class="low">' . $x['telat'] . '</b>' : '0', $x['artikel'] ?: '–', $x['feedback'] ?: '–', $x['stok'] ?: '–'], $peg)) ?>
  <?= sumber('pegawai, transaksi, tugas, artikel_kms, feedback_kms, stok_log') ?>
</section>

<div class="grid g2">
  <?= panel('💰 Omzet per Kasir', hbars(array_map(fn($x) => [e($x['nama']), $x['omzet'], ringkas($x['omzet']), angka($x['trx']) . ' transaksi · ' . persen(rasio($x['omzet'], $totOmzet)) . ' dari omzet'], $kasirList), 'g', 'Belum ada transaksi pada periode ini'), 'transaksi, pegawai') ?>
  <?= panel('🎯 Ketepatan Waktu Tugas', hbars(array_map(fn($x) => [e($x['nama']), rasio($x['tepat'], $x['jatuh']), persen(rasio($x['tepat'], $x['jatuh']), 0), $x['tepat'] . ' tepat waktu dari ' . $x['jatuh'] . ' tugas jatuh tempo'], $tugasList), 'b', 'Tidak ada tugas jatuh tempo pada periode ini'), 'tugas, pegawai') ?>
</div>
<p class="hint">Catatan: rapor ini alat bantu evaluasi. Angka perlu dibaca bersama konteks, misalnya jadwal shift dan jenis tugas yang diberikan.</p>
<?php mis_end();

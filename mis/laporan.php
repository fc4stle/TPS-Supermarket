<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode('bulan_lalu');
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];
$W = 'DATE(t.waktu) BETWEEN ? AND ?';

$t = mis_tps($d, $s); $t0 = mis_tps($pd, $ps);
$o = mis_oas($d, $s); $o0 = mis_oas($pd, $ps);
$k = mis_kms($d, $s); $k0 = mis_kms($pd, $ps);
$alerts = array_values(array_filter(mis_alerts(), fn($a) => $a['level'] !== 'info'));

$topProduk = row("SELECT d.nama_produk n, SUM(d.subtotal) v, SUM(d.qty) q FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE $W GROUP BY d.id_produk, d.nama_produk ORDER BY v DESC LIMIT 1", [$d, $s]);
$topKat = row("SELECT p.kategori n, SUM(d.subtotal) v FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk p ON p.id=d.id_produk WHERE $W GROUP BY p.kategori ORDER BY v DESC LIMIT 1", [$d, $s]);
$topKasir = row("SELECT g.nama n, SUM(t.total) v, COUNT(*) c FROM transaksi t JOIN pegawai g ON g.id=t.id_kasir WHERE $W GROUP BY g.id, g.nama ORDER BY v DESC LIMIT 1", [$d, $s]);
$nonTunai = rasio((float) val("SELECT COUNT(*) FROM transaksi t WHERE $W AND t.metode!='tunai'", [$d, $s]), $t['trx']);
$topStaff = row("SELECT p.nama n, COUNT(*) c FROM tugas t JOIN pegawai p ON p.id=t.id_staff WHERE t.status='selesai' AND DATE(t.selesai_pada)<=t.tenggat AND t.tenggat BETWEEN ? AND ? GROUP BY p.id, p.nama ORDER BY c DESC LIMIT 1", [$d, $s]);
$topKms = row("SELECT a.judul n, COUNT(*) c FROM feedback_kms f JOIN artikel_kms a ON a.id=f.id_artikel WHERE f.membantu=1 AND DATE(f.dibuat) BETWEEN ? AND ? GROUP BY a.id, a.judul ORDER BY c DESC LIMIT 1", [$d, $s]);
$topPenulis = row("SELECT p.nama n, COUNT(*) c FROM artikel_kms a JOIN pegawai p ON p.id=a.id_penulis WHERE DATE(a.dibuat) BETWEEN ? AND ? GROUP BY p.id, p.nama ORDER BY c DESC LIMIT 1", [$d, $s]);
$masuk = (int) val("SELECT COALESCE(SUM(perubahan),0) FROM stok_log WHERE jenis='restok' AND DATE(waktu) BETWEEN ? AND ?", [$d, $s]);
$nilaiStok = (float) val('SELECT COALESCE(SUM(harga*stok),0) FROM produk WHERE aktif=1');

// kalimat ringkasan eksekutif disusun dari angka
$arah = fn($a, $b, $naik = 'naik', $turun = 'turun') => $b == 0 ? ($a > 0 ? 'baru tercatat' : 'belum ada') : ($a >= $b ? $naik : $turun) . ' ' . persen(abs($a - $b) / abs($b) * 100) ;
$ring = [];
if ($t['trx']) {
    $ring[] = 'Omzet periode ini <b>' . rp($t['omzet']) . '</b> dari <b>' . angka($t['trx']) . '</b> transaksi, ' . $arah($t['omzet'], $t0['omzet']) . ' dibanding periode sebelumnya (' . rp($t0['omzet']) . ').';
    $ring[] = 'Rata-rata belanja per transaksi <b>' . rp($t['aov']) . '</b> dengan <b>' . number_format($t['ipt'], 1, ',', '.') . '</b> barang per keranjang; ' . persen($nonTunai) . ' transaksi dibayar non-tunai.';
} else $ring[] = 'Belum ada transaksi penjualan pada periode ini.';
if ($o['jatuh']) $ring[] = 'Dari <b>' . $o['jatuh'] . '</b> tugas yang jatuh tempo, <b>' . persen($o['rate']) . '</b> selesai dan <b>' . persen($o['tepat_rate']) . '</b> selesai tepat waktu. Saat ini ada <b>' . $o['telat'] . '</b> tugas terlambat.';
else $ring[] = 'Tidak ada tugas yang jatuh tempo pada periode ini.';
$ring[] = 'Knowledge base berisi <b>' . $k['published'] . '</b> artikel published (' . $k['baru'] . ' baru pada periode ini)' . ($k['fb'] ? ' dengan helpful rate <b>' . persen($k['helpful']) . '</b> dari ' . $k['fb'] . ' feedback.' : '; belum ada feedback pada periode ini.');

$baris = fn($label, $now, $prev, $d, $sumber) => [$label, '<b>' . $now . '</b>', $prev, $d, '<code>' . $sumber . '</code>'];
mis_start('Laporan Manajemen', 'mis-laporan', 'Ringkasan kinerja siap cetak untuk rapat evaluasi.', $per);
?>
<article class="report">
  <div class="rep-head">
    <div><h2>🛒 MartOffice — Laporan Manajemen</h2><p class="mb0 muted">Management Information System · ringkasan TPS, OAS, dan KMS</p></div>
    <div class="right" style="font-size:13px"><b><?= e($per['label']) ?></b><br><?= tgl($d, false) ?> – <?= tgl($s, false) ?><br><span class="muted">Disusun: <?= e(me()['nama']) ?> · <?= tgl(date('Y-m-d H:i')) ?></span></div>
  </div>

  <h3>1. Ringkasan Eksekutif</h3>
  <ul><?php foreach ($ring as $r) echo '<li>' . $r . '</li>'; ?></ul>

  <h3>2. Kinerja Penjualan dan Persediaan (TPS)</h3>
  <?= tabel(['Indikator', '#Periode ini', '#Periode sebelumnya', 'Perubahan', 'Sumber'], [
      $baris('Omzet', rp($t['omzet']), rp($t0['omzet']), delta($t['omzet'], $t0['omzet']), 'transaksi.total'),
      $baris('Jumlah transaksi', angka($t['trx']), angka($t0['trx']), delta($t['trx'], $t0['trx']), 'transaksi'),
      $baris('Rata-rata belanja', rp($t['aov']), rp($t0['aov']), delta($t['aov'], $t0['aov']), 'transaksi.total'),
      $baris('Barang terjual', angka($t['item']), angka($t0['item']), delta($t['item'], $t0['item']), 'detail_transaksi.qty'),
      $baris('Unit masuk (restok)', angka($masuk), '–', '', 'stok_log'),
      $baris('Nilai persediaan saat ini', rp($nilaiStok), '–', '', 'produk.harga × stok'),
  ]) ?>

  <h3>3. Kinerja Perkantoran (OAS)</h3>
  <?= tabel(['Indikator', '#Periode ini', '#Periode sebelumnya', 'Perubahan', 'Sumber'], [
      $baris('Tugas jatuh tempo', (string) $o['jatuh'], (string) $o0['jatuh'], '', 'tugas.tenggat'),
      $baris('Tingkat penyelesaian', persen($o['rate']), persen($o0['rate']), delta($o['rate'], $o0['rate'], true, true), 'tugas.status'),
      $baris('Selesai tepat waktu', persen($o['tepat_rate']), persen($o0['tepat_rate']), delta($o['tepat_rate'], $o0['tepat_rate'], true, true), 'tugas.selesai_pada'),
      $baris('Rata-rata lama pengerjaan', lama_teks($o['lama']), lama_teks($o0['lama']), $o['lama'] !== null && $o0['lama'] !== null ? delta($o['lama'], $o0['lama'], false) : '', 'tugas.dibuat → selesai_pada'),
      $baris('Tugas aktif / terlambat saat ini', $o['aktif'] . ' / ' . $o['telat'], '–', '', 'tugas'),
  ]) ?>

  <h3>4. Kinerja Pengetahuan (KMS)</h3>
  <?= tabel(['Indikator', '#Periode ini', '#Periode sebelumnya', 'Perubahan', 'Sumber'], [
      $baris('Knowledge baru', (string) $k['baru'], (string) $k0['baru'], delta($k['baru'], $k0['baru']), 'artikel_kms.dibuat'),
      $baris('Feedback diterima', (string) $k['fb'], (string) $k0['fb'], delta($k['fb'], $k0['fb']), 'feedback_kms'),
      $baris('Helpful rate', persen($k['helpful']), persen($k0['helpful']), delta($k['helpful'], $k0['helpful'], true, true), 'feedback_kms.membantu'),
      $baris('Published / menunggu review', $k['published'] . ' / ' . $k['menunggu'], '–', '', 'artikel_kms.status'),
      $baris('Total dilihat (kumulatif)', angka($k['dilihat']), '–', '', 'artikel_kms.dilihat'),
  ]) ?>

  <h3>5. Sorotan Periode</h3>
  <ul>
    <?php
    $sorot = [];
    if ($topProduk) $sorot[] = 'Produk penyumbang omzet terbesar: <b>' . e($topProduk['n']) . '</b> (' . rp($topProduk['v']) . ', ' . angka($topProduk['q']) . ' unit).';
    if ($topKat) $sorot[] = 'Kategori terlaris: <b>' . e($topKat['n']) . '</b> (' . rp($topKat['v']) . ').';
    if ($topKasir) $sorot[] = 'Kasir dengan omzet tertinggi: <b>' . e($topKasir['n']) . '</b> (' . rp($topKasir['v']) . ' dari ' . angka($topKasir['c']) . ' transaksi).';
    if ($topStaff) $sorot[] = 'Staff dengan tugas tepat waktu terbanyak: <b>' . e($topStaff['n']) . '</b> (' . $topStaff['c'] . ' tugas).';
    if ($topKms) $sorot[] = 'Knowledge yang paling banyak dinilai membantu: <b>' . e($topKms['n']) . '</b> (' . $topKms['c'] . ' penilaian).';
    if ($topPenulis) $sorot[] = 'Kontributor knowledge teraktif: <b>' . e($topPenulis['n']) . '</b> (' . $topPenulis['c'] . ' artikel baru).';
    echo $sorot ? '<li>' . implode('</li><li>', $sorot) . '</li>' : '<li class="muted">Belum ada data untuk disorot pada periode ini.</li>';
    ?>
  </ul>

  <h3>6. Hal yang Perlu Ditindaklanjuti</h3>
  <?php if (!$alerts): ?><p class="muted">Tidak ada hal kritis saat laporan ini disusun.</p>
  <?php else: echo tabel(['Tingkat', 'Modul', 'Temuan', 'Keterangan'], array_map(fn($a) => [$a['level'] === 'kritis' ? '⛔ Kritis' : '⚠️ Perhatian', $a['modul'], '<b>' . e($a['judul']) . '</b>', e($a['detail'])], array_slice($alerts, 0, 12)));
    if (count($alerts) > 12) echo '<p class="hint">+' . (count($alerts) - 12) . ' temuan lain ada di Pusat Peringatan.</p>';
  endif; ?>
  <p class="hint">Bagian 6 menggambarkan kondisi saat laporan dibuka, bukan kondisi pada akhir periode.</p>

  <div style="display:flex;justify-content:flex-end;margin-top:34px"><div class="c" style="text-align:center;font-size:13px">Yogyakarta, <?= tgl(date('Y-m-d'), false) ?><br>Manajer Toko<br><br><br><br><b><?= e(me()['nama']) ?></b></div></div>
</article>
<?php mis_end();

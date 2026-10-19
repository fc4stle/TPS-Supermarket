<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();
$per = mis_periode();
[$d, $s, $pd, $ps] = [$per['dari'], $per['sampai'], $per['pdari'], $per['psampai']];
$W = 'DATE(t.waktu) BETWEEN ? AND ?';

$t = mis_tps($d, $s); $t0 = mis_tps($pd, $ps);
$maxTrx = (float) val("SELECT COALESCE(MAX(t.total),0) FROM transaksi t WHERE $W", [$d, $s]);
$non = fn($a, $b) => rasio((float) val("SELECT COUNT(*) FROM transaksi t WHERE $W AND t.metode!='tunai'", [$a, $b]), (float) val("SELECT COUNT(*) FROM transaksi t WHERE $W", [$a, $b]));
$nonTunai = $non($d, $s); $nonTunai0 = $non($pd, $ps);

// sebaran nilai belanja
$bk = row("SELECT COALESCE(SUM(t.total<50000),0) a, COALESCE(SUM(t.total>=50000 AND t.total<100000),0) b, COALESCE(SUM(t.total>=100000 AND t.total<200000),0) c,
                  COALESCE(SUM(t.total>=200000 AND t.total<300000),0) d, COALESCE(SUM(t.total>=300000),0) e FROM transaksi t WHERE $W", [$d, $s]);
$nilai = [['< 50rb', (int) $bk['a']], ['50–100rb', (int) $bk['b']], ['100–200rb', (int) $bk['c']], ['200–300rb', (int) $bk['d']], ['≥ 300rb', (int) $bk['e']]];
// sebaran jumlah barang per keranjang
$kr = array_column(rows("SELECT LEAST(x.q,8) q, COUNT(*) n FROM (SELECT SUM(d.qty) q FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE $W GROUP BY d.id_transaksi) x GROUP BY LEAST(x.q,8)", [$d, $s]), 'n', 'q');
$keranjang = []; for ($i = 1; $i <= 8; $i++) $keranjang[] = [$i === 8 ? '8+' : (string) $i, (int) ($kr[$i] ?? 0)];
$jenis = (float) val("SELECT AVG(x.j) FROM (SELECT COUNT(*) j FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi WHERE $W GROUP BY d.id_transaksi) x", [$d, $s]);

$met = array_column(rows("SELECT t.metode, COUNT(*) n, AVG(t.total) a FROM transaksi t WHERE $W GROUP BY t.metode", [$d, $s]), null, 'metode');
// pola kunjungan: jam x (hari kerja / akhir pekan)
$pola = rows("SELECT HOUR(t.waktu) j, SUM(WEEKDAY(t.waktu)<5) kerja, SUM(WEEKDAY(t.waktu)>=5) akhir FROM transaksi t WHERE $W GROUP BY HOUR(t.waktu)", [$d, $s]);
$polaMap = array_column($pola, null, 'j');
$nKerja = 0; $nAkhir = 0;
for ($x = strtotime($d); $x <= strtotime($s); $x += 86400) (date('N', $x) < 6) ? $nKerja++ : $nAkhir++;

// produk yang sering dibeli bersamaan
$pair = rows("SELECT a.nama_produk pa, b.nama_produk pb, COUNT(*) n FROM detail_transaksi a JOIN detail_transaksi b ON b.id_transaksi=a.id_transaksi AND b.id_produk>a.id_produk
              JOIN transaksi t ON t.id=a.id_transaksi WHERE $W GROUP BY a.id_produk, b.id_produk, a.nama_produk, b.nama_produk ORDER BY n DESC LIMIT 8", [$d, $s]);
$besar = rows("SELECT t.id, t.nota, t.total, t.metode, t.waktu, g.nama, (SELECT SUM(qty) FROM detail_transaksi d WHERE d.id_transaksi=t.id) item
               FROM transaksi t JOIN pegawai g ON g.id=t.id_kasir WHERE $W ORDER BY t.total DESC LIMIT 5", [$d, $s]);

mis_start('Analitik Belanja Pelanggan', 'mis-pelanggan', 'Pola belanja pelanggan dibaca dari isi keranjang dan waktu transaksi kasir.', $per);
?>
<div class="alert info"><span>ℹ️</span><div>MartOffice tidak menyimpan identitas pembeli, sehingga analisis ini memakai <b>pola transaksi</b> (nilai belanja, isi keranjang, waktu kunjungan), bukan data pelanggan perorangan.</div></div>

<div class="kpis">
  <?= kpi('Kunjungan Belanja', angka($t['trx']), '🚶', delta($t['trx'], $t0['trx']), number_format($t['trx'] / max(1, $per['hari']), 1, ',', '.') . ' per hari', 'g') ?>
  <?= kpi('Rata-rata Belanja', rp($t['aov']), '🛍️', delta($t['aov'], $t0['aov']), 'per kunjungan', 'y') ?>
  <?= kpi('Isi Keranjang', number_format($t['ipt'], 1, ',', '.') . ' unit', '🧺', delta($t['ipt'], $t0['ipt']), number_format($jenis, 1, ',', '.') . ' jenis produk', 'b') ?>
  <?= kpi('Belanja Terbesar', ringkas($maxTrx), '💎', '', 'satu transaksi', 'p') ?>
  <?= kpi('Pembayaran Non-Tunai', persen($nonTunai), '📱', delta($nonTunai, $nonTunai0, true, true), 'QRIS + debit', 'o') ?>
</div>

<div class="grid g2">
  <?= panel('💵 Sebaran Nilai Belanja <small>(jumlah transaksi)</small>', $t['trx'] ? kolom($nilai, false, 180) : empty_state('💵', 'Belum ada transaksi'), 'transaksi.total') ?>
  <?= panel('🧺 Jumlah Barang per Keranjang <small>(jumlah transaksi)</small>', $t['trx'] ? kolom($keranjang, false, 180) : empty_state('🧺', 'Belum ada transaksi'), 'detail_transaksi.qty') ?>
</div>

<div class="grid g2">
  <section class="card panel"><div class="card-h"><h3>💳 Pilihan Cara Bayar</h3><small>jumlah transaksi</small></div>
    <?= stack([['Tunai', (int) ($met['tunai']['n'] ?? 0), 'c-b'], ['QRIS', (int) ($met['qris']['n'] ?? 0), 'c-o'], ['Debit', (int) ($met['debit']['n'] ?? 0), 'c-a']], 'transaksi') ?>
    <div class="mini">
      <?php foreach (['tunai' => 'Tunai', 'qris' => 'QRIS', 'debit' => 'Debit'] as $k => $v) echo '<div><small>Rata-rata belanja ' . $v . '</small><b>' . rp($met[$k]['a'] ?? 0) . '</b></div>'; ?>
    </div>
    <?= sumber('transaksi.metode') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>🕐 Pola Jam Kunjungan</h3><small>rata-rata transaksi per jam</small></div>
    <?php if (!$t['trx']): echo empty_state('🕐', 'Belum ada transaksi'); else:
      $baris = [['Hari kerja', 'kerja', max(1, $nKerja)], ['Akhir pekan', 'akhir', max(1, $nAkhir)]];
      $mx = 0.0001; foreach ($baris as [, $kol, $n]) for ($j = 7; $j <= 20; $j++) $mx = max($mx, ($polaMap[$j][$kol] ?? 0) / $n);
      foreach ($baris as [$label, $kol, $n]): ?>
        <div style="display:flex;gap:10px;align-items:center;margin:6px 0"><span style="width:86px;font-size:12.5px;font-weight:600"><?= $label ?></span>
          <div class="heat" style="flex:1;grid-template-columns:repeat(14,1fr)"><?php for ($j = 7; $j <= 20; $j++): $v = ($polaMap[$j][$kol] ?? 0) / $n; $a = round(0.08 + 0.92 * $v / $mx, 2); ?>
            <div style="background:rgba(22,163,74,<?= $a ?>);color:<?= $a > .55 ? '#fff' : '#14532d' ?>" title="<?= $label ?>, pukul <?= sprintf('%02d', $j) ?>.00: rata-rata <?= number_format($v, 1, ',', '.') ?> transaksi"><?= number_format($v, 1, ',', '.') ?></div><?php endfor; ?></div></div>
      <?php endforeach; ?>
      <div style="display:flex;gap:10px"><span style="width:86px"></span><div class="heat-l" style="flex:1;grid-template-columns:repeat(14,1fr)"><?php for ($j = 7; $j <= 20; $j++) echo '<span>' . sprintf('%02d', $j) . '</span>'; ?></div></div>
      <p class="hint" style="margin-top:8px">Semakin gelap = semakin ramai. Angka = rata-rata transaksi pada jam tersebut.</p>
    <?php endif; ?>
    <?= sumber('transaksi.waktu') ?>
  </section>
</div>

<div class="grid g2">
  <section class="card panel"><div class="card-h"><h3>🤝 Sering Dibeli Bersamaan</h3><small>pasangan produk dalam satu nota</small></div>
    <?= tabel(['Produk A', 'Produk B', '#Bersama'], array_map(fn($x) => [e($x['pa']), e($x['pb']), '<b>' . angka($x['n']) . '</b> nota'], $pair), 'Belum ada transaksi dengan lebih dari satu produk') ?>
    <p class="hint">Bisa dipakai untuk penataan rak berdekatan atau paket bundling.</p>
    <?= sumber('detail_transaksi') ?>
  </section>
  <section class="card panel"><div class="card-h"><h3>💎 5 Transaksi Terbesar</h3></div>
    <?= tabel(['Nota', 'Waktu', 'Kasir', '#Barang', '#Total'], array_map(fn($x) => ['<a href="' . url('tps/nota.php?id=' . $x['id']) . '"><b>' . e($x['nota']) . '</b></a><br>' . status_badge($x['metode']), tgl($x['waktu']), e($x['nama']), angka($x['item']), '<b>' . rp($x['total']) . '</b>'], $besar), 'Belum ada transaksi') ?>
    <?= sumber('transaksi, detail_transaksi') ?>
  </section>
</div>
<?php mis_end();

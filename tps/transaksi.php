<?php
require __DIR__ . '/../includes/app.php';
need();

$dari   = get('dari') ?: date('Y-m-01');
$sampai = get('sampai') ?: date('Y-m-d');
$metode = pilih(get('metode'), ['tunai', 'debit', 'qris'], '');
$kasir  = is_boss() ? (int) get('kasir') : uid();
$cari   = get('cari');

$where = ['DATE(t.waktu) BETWEEN ? AND ?']; $p = [$dari, $sampai];
if ($metode) { $where[] = 't.metode=?'; $p[] = $metode; }
if ($kasir)  { $where[] = 't.id_kasir=?'; $p[] = $kasir; }
if ($cari !== '') { $where[] = 't.nota LIKE ?'; $p[] = '%' . $cari . '%'; }
$w = implode(' AND ', $where);

$sum = row("SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM transaksi t WHERE $w", $p);
$pg  = paginate((int) $sum['n'], 15);
$trx = rows("SELECT t.*,p.nama kasir,(SELECT SUM(qty) FROM detail_transaksi d WHERE d.id_transaksi=t.id) item
             FROM transaksi t JOIN pegawai p ON p.id=t.id_kasir WHERE $w ORDER BY t.waktu DESC, t.id DESC LIMIT ? OFFSET ?",
    array_merge($p, [$pg['per'], $pg['offset']]));
$kasirList = is_boss() ? rows("SELECT DISTINCT p.id,p.nama FROM pegawai p JOIN transaksi t ON t.id_kasir=p.id ORDER BY p.nama") : [];

page_start(role() === 'staff' ? 'Transaksi Saya' : 'Riwayat Transaksi', 'transaksi', 'Daftar transaksi kasir beserta nota.');
?>
<div class="stats">
  <?= stat_card('Jumlah Transaksi', angka($sum['n']), '🧾', 'g') ?>
  <?= stat_card('Total Penjualan', rp($sum['s']), '💰', 'y') ?>
  <?= stat_card('Rata-rata / Transaksi', rp($sum['n'] ? $sum['s'] / $sum['n'] : 0), '📊', 'b') ?>
</div>

<form class="card row" method="get">
  <div><label>Dari tanggal</label><input type="date" name="dari" value="<?= e($dari) ?>"></div>
  <div><label>Sampai</label><input type="date" name="sampai" value="<?= e($sampai) ?>"></div>
  <div><label>Metode</label><select name="metode"><option value="">Semua metode</option>
    <?php foreach (['tunai' => 'Tunai', 'debit' => 'Debit', 'qris' => 'QRIS'] as $k => $v) echo '<option value="' . $k . '"' . ($metode === $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select></div>
  <?php if (is_boss()): ?>
  <div><label>Kasir</label><select name="kasir"><option value="0">Semua kasir</option>
    <?php foreach ($kasirList as $k) echo '<option value="' . $k['id'] . '"' . ($kasir === (int) $k['id'] ? ' selected' : '') . '>' . e($k['nama']) . '</option>'; ?></select></div>
  <?php endif; ?>
  <div><label>No. nota</label><input name="cari" value="<?= e($cari) ?>" placeholder="TRX-…"></div>
  <div class="fit"><button type="submit">🔍 Filter</button></div>
</form>

<div class="card tight">
  <div class="card-h"><h3>🗂️ Daftar Transaksi</h3>
    <?php if (in_array(role(), ['staff', 'admin'], true)): ?><a class="btn sm" href="<?= url('tps/kasir.php') ?>">+ Transaksi Baru</a><?php endif; ?></div>
  <div class="table-wrap" style="margin-top:12px">
  <?php if (!$trx): echo empty_state('🧾', 'Belum ada transaksi pada periode ini', 'Transaksi yang dicatat lewat menu Kasir akan tampil di sini.');
  else: ?>
  <table>
    <thead><tr><th>No. Nota</th><th>Waktu</th><th>Kasir</th><th>Metode</th><th class="num">Item</th><th class="num">Total</th><th></th></tr></thead>
    <tbody><?php foreach ($trx as $t): ?>
      <tr>
        <td><b><?= e($t['nota']) ?></b></td>
        <td class="nowrap"><?= tgl($t['waktu']) ?></td>
        <td><?= e($t['kasir']) ?></td>
        <td><?= status_badge($t['metode']) ?></td>
        <td class="num"><?= angka($t['item']) ?></td>
        <td class="num"><b><?= rp($t['total']) ?></b></td>
        <td class="right"><a class="btn sm ghost" href="<?= url('tps/nota.php?id=' . $t['id']) ?>">🧾 Nota</a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  </div>
</div>
<?= pager($pg) ?>
<?php page_end();

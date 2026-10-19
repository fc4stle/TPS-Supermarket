<?php
require __DIR__ . '/../includes/app.php';
need();

$t = row('SELECT t.*,p.nama kasir FROM transaksi t JOIN pegawai p ON p.id=t.id_kasir WHERE t.id=?', [(int) get('id')]);
if (!$t || (role() === 'staff' && (int) $t['id_kasir'] !== uid())) {
    flash('err', 'Nota tidak ditemukan.');
    redirect('tps/transaksi.php');
}
$items = rows('SELECT d.*,p.ikon FROM detail_transaksi d JOIN produk p ON p.id=d.id_produk WHERE d.id_transaksi=? ORDER BY d.id', [(int) $t['id']]);

page_start('Nota ' . $t['nota'], 'transaksi', 'Cetak nota untuk diserahkan ke pelanggan.');
?>
<div class="actions no-print" style="justify-content:center;margin-bottom:18px">
  <button type="button" onclick="window.print()">🖨️ Cetak Nota</button>
  <?php if (in_array(role(), ['staff', 'admin'], true)): ?><a class="btn y" href="<?= url('tps/kasir.php') ?>">🧾 Transaksi Baru</a><?php endif; ?>
  <a class="btn ghost" href="<?= url('tps/transaksi.php') ?>">🗂️ Riwayat Transaksi</a>
</div>

<div class="receipt">
  <h2>🛒 MartOffice</h2>
  <div class="c">Supermarket · <?= APP_TAGLINE ?><br>Yogyakarta</div>
  <div class="dash"></div>
  <table>
    <tr><td>No. Nota</td><td class="num"><b><?= e($t['nota']) ?></b></td></tr>
    <tr><td>Tanggal</td><td class="num"><?= date('d/m/Y H:i', strtotime($t['waktu'])) ?></td></tr>
    <tr><td>Kasir</td><td class="num"><?= e($t['kasir']) ?></td></tr>
  </table>
  <div class="dash"></div>
  <table>
    <?php foreach ($items as $i): ?>
      <tr><td colspan="2"><?= e($i['nama_produk']) ?></td></tr>
      <tr><td>&nbsp;&nbsp;<?= $i['qty'] ?> x <?= angka($i['harga']) ?></td><td class="num"><?= angka($i['subtotal']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="dash"></div>
  <table>
    <tr><td><b>TOTAL</b></td><td class="num"><b><?= rp($t['total']) ?></b></td></tr>
    <tr><td>Bayar (<?= strtoupper($t['metode']) ?>)</td><td class="num"><?= rp($t['bayar']) ?></td></tr>
    <tr><td>Kembali</td><td class="num"><?= rp($t['kembali']) ?></td></tr>
    <tr><td>Jumlah barang</td><td class="num"><?= array_sum(array_column($items, 'qty')) ?> item</td></tr>
  </table>
  <div class="dash"></div>
  <div class="c">Terima kasih telah berbelanja 💚<br>Barang yang sudah dibeli<br>tidak dapat ditukar/dikembalikan.</div>
</div>
<?php page_end();

<?php
require "auth.php";
require "config.php";
require_login();
$id_transaksi = intval($_GET["id"] ?? 0);

$stmt = $conn->prepare("SELECT t.no_nota, t.tanggal_transaksi, t.total_belanja,
                                pg.nama_pegawai, pl.nama_pelanggan
                         FROM transaksi t
                         LEFT JOIN pegawai pg ON t.id_pegawai = pg.id_pegawai
                         LEFT JOIN pelanggan pl ON t.id_pelanggan = pl.id_pelanggan
                         WHERE t.id_transaksi = ?");
$stmt->bind_param("i", $id_transaksi);
$stmt->execute();
$transaksi = $stmt->get_result()->fetch_assoc();

$stmtItems = $conn->prepare("SELECT p.nama_produk, dt.jumlah, dt.harga_satuan, dt.subtotal
                              FROM detail_transaksi dt
                              JOIN produk p ON dt.id_produk = p.id_produk
                              WHERE dt.id_transaksi = ?");
$stmtItems->bind_param("i", $id_transaksi);
$stmtItems->execute();
$items = $stmtItems->get_result();

$stmtBayar = $conn->prepare("SELECT metode_bayar, jumlah_bayar, kembalian FROM pembayaran WHERE id_transaksi = ?");
$stmtBayar->bind_param("i", $id_transaksi);
$stmtBayar->execute();
$bayar = $stmtBayar->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Detail Transaksi - TPS Supermarket</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <h1>🛒 TPS Supermarket</h1>
    <div>
        <a href="index.php">Dashboard</a>
        <a href="produk.php">Produk</a>
        <a href="kasir.php">Transaksi Baru</a>
        <a href="riwayat.php">Riwayat Transaksi</a>
    </div>
</div>

<div class="container">
    <h2>Detail Transaksi</h2>

    <?php if ($transaksi): ?>
    <table>
        <tr><th>No. Nota</th><td><?= $transaksi['no_nota'] ?></td></tr>
        <tr><th>Tanggal</th><td><?= $transaksi['tanggal_transaksi'] ?></td></tr>
        <tr><th>Kasir</th><td><?= $transaksi['nama_pegawai'] ?></td></tr>
        <tr><th>Pelanggan</th><td><?= $transaksi['nama_pelanggan'] ?? 'Umum' ?></td></tr>
    </table>

    <h2 style="margin-top:24px;">Item Belanja</h2>
    <table>
        <tr><th>Produk</th><th>Jumlah</th><th>Harga Satuan</th><th>Subtotal</th></tr>
        <?php while ($it = $items->fetch_assoc()): ?>
        <tr>
            <td><?= $it['nama_produk'] ?></td>
            <td><?= $it['jumlah'] ?></td>
            <td>Rp <?= number_format($it['harga_satuan'],0,',','.') ?></td>
            <td>Rp <?= number_format($it['subtotal'],0,',','.') ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <div class="total-box">Total: Rp <?= number_format($transaksi['total_belanja'],0,',','.') ?></div>

    <?php if ($bayar): ?>
    <h2 style="margin-top:24px;">Pembayaran</h2>
    <table>
        <tr><th>Metode</th><td><?= strtoupper($bayar['metode_bayar']) ?></td></tr>
        <tr><th>Jumlah Bayar</th><td>Rp <?= number_format($bayar['jumlah_bayar'],0,',','.') ?></td></tr>
        <tr><th>Kembalian</th><td>Rp <?= number_format($bayar['kembalian'],0,',','.') ?></td></tr>
    </table>
    <?php endif; ?>

    <?php else: ?>
        <p>Transaksi tidak ditemukan.</p>
    <?php endif; ?>

    <p style="margin-top:20px;"><a href="riwayat.php" class="btn">← Kembali ke Riwayat</a></p>
</div>

</body>
</html>

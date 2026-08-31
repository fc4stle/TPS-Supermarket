<?php
require "auth.php";
require "config.php";
require_login();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Dashboard - TPS Supermarket</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <h1>🛒 TPS Supermarket</h1>
    <div>
        <?php if ($_SESSION['role'] == 'admin'): ?>
            <a href="index.php">Dashboard</a>
            <a href="produk.php">Produk</a>
            <a href="kelola_produk.php">Kelola Produk</a>
            <a href="kasir.php">Transaksi Kasir</a>
            <a href="riwayat.php">Semua Transaksi</a>
        <?php else: ?>
            <a href="index.php">Dashboard</a>
            <a href="belanja.php">Belanja</a>
            <a href="riwayat.php">Riwayat Belanja Saya</a>
        <?php endif; ?>
        <a href="logout.php">Logout (<?= htmlspecialchars($_SESSION['nama']) ?>)</a>
    </div>
</div>

<div class="container">
    <h2>Halo, <?= htmlspecialchars($_SESSION['nama']) ?> (<?= ucfirst($_SESSION['role']) ?>)</h2>

    <?php if ($_SESSION['role'] == 'admin'): ?>
        <?php
        $jml_produk = $conn->query("SELECT COUNT(*) as jml FROM produk")->fetch_assoc()['jml'];
        $jml_transaksi = $conn->query("SELECT COUNT(*) as jml FROM transaksi")->fetch_assoc()['jml'];
        $total_penjualan = $conn->query("SELECT SUM(total_belanja) as total FROM transaksi")->fetch_assoc()['total'];
        ?>
        <table>
            <tr><th>Jumlah Produk</th><td><?= $jml_produk ?> item</td></tr>
            <tr><th>Jumlah Transaksi</th><td><?= $jml_transaksi ?> transaksi</td></tr>
            <tr><th>Total Penjualan</th><td>Rp <?= number_format($total_penjualan ?? 0, 0, ',', '.') ?></td></tr>
        </table>
        <p style="margin-top:24px;">
            <a href="kasir.php" class="btn">+ Buat Transaksi (Kasir)</a>
            <a href="kelola_produk.php" class="btn">Kelola Produk</a>
        </p>
    <?php else: ?>
        <?php
        $stmt = $conn->prepare("SELECT COUNT(*) as jml, SUM(total_belanja) as total FROM transaksi WHERE id_pelanggan = ?");
        $stmt->bind_param("i", $_SESSION['id_pelanggan']);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        ?>
        <table>
            <tr><th>Total Transaksi Anda</th><td><?= $r['jml'] ?> kali</td></tr>
            <tr><th>Total Belanja Anda</th><td>Rp <?= number_format($r['total'] ?? 0, 0, ',', '.') ?></td></tr>
        </table>
        <p style="margin-top:24px;">
            <a href="belanja.php" class="btn">+ Mulai Belanja</a>
        </p>
    <?php endif; ?>
</div>

</body>
</html>

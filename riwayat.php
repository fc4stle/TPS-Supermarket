<?php
require "auth.php";
require "config.php";
require_login();

if ($_SESSION['role'] == 'admin') {
    $sql = "SELECT t.id_transaksi, t.no_nota, t.tanggal_transaksi, t.total_belanja,
                   pg.nama_pegawai, pl.nama_pelanggan
            FROM transaksi t
            LEFT JOIN pegawai pg ON t.id_pegawai = pg.id_pegawai
            LEFT JOIN pelanggan pl ON t.id_pelanggan = pl.id_pelanggan
            ORDER BY t.tanggal_transaksi DESC";
    $result = $conn->query($sql);
} else {
    $stmt = $conn->prepare("SELECT t.id_transaksi, t.no_nota, t.tanggal_transaksi, t.total_belanja,
                                    pg.nama_pegawai, pl.nama_pelanggan
                             FROM transaksi t
                             LEFT JOIN pegawai pg ON t.id_pegawai = pg.id_pegawai
                             LEFT JOIN pelanggan pl ON t.id_pelanggan = pl.id_pelanggan
                             WHERE t.id_pelanggan = ?
                             ORDER BY t.tanggal_transaksi DESC");
    $stmt->bind_param("i", $_SESSION['id_pelanggan']);
    $stmt->execute();
    $result = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Riwayat Transaksi - TPS Supermarket</title>
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
    <h2><?= $_SESSION['role'] == 'admin' ? 'Semua Transaksi' : 'Riwayat Belanja Saya' ?></h2>
    <table>
        <tr>
            <th>No. Nota</th><th>Tanggal</th><th>Kasir</th><th>Pelanggan</th><th>Total</th><th>Detail</th>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['no_nota'] ?></td>
                <td><?= $row['tanggal_transaksi'] ?></td>
                <td><?= $row['nama_pegawai'] ?? 'Self-Checkout' ?></td>
                <td><?= $row['nama_pelanggan'] ?? 'Umum' ?></td>
                <td>Rp <?= number_format($row['total_belanja'],0,',','.') ?></td>
                <td><a href="detail.php?id=<?= $row['id_transaksi'] ?>">Lihat</a></td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>

</body>
</html>

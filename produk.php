<?php require "auth.php"; require "config.php"; require_login(); ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Daftar Produk - TPS Supermarket</title>
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
    <h2>Daftar Produk</h2>
    <table>
        <tr>
            <th>Kode</th>
            <th>Nama Produk</th>
            <th>Kategori</th>
            <th>Harga Jual</th>
            <th>Stok</th>
        </tr>
        <?php
        $sql = "SELECT p.kode_produk, p.nama_produk, k.nama_kategori, p.harga_jual, p.stok
                FROM produk p
                LEFT JOIN kategori k ON p.id_kategori = k.id_kategori
                ORDER BY p.nama_produk";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            echo "<tr>
                    <td>{$row['kode_produk']}</td>
                    <td>{$row['nama_produk']}</td>
                    <td>{$row['nama_kategori']}</td>
                    <td>Rp " . number_format($row['harga_jual'],0,',','.') . "</td>
                    <td>{$row['stok']}</td>
                  </tr>";
        }
        ?>
    </table>
</div>

</body>
</html>

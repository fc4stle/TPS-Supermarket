<?php
require "auth.php";
require "config.php";
require_login('admin');

$pesan = "";

// Tambah produk
if ($_SERVER["REQUEST_METHOD"] == "POST" && $_POST["aksi"] == "tambah") {
    $stmt = $conn->prepare("INSERT INTO produk (kode_produk, nama_produk, id_kategori, id_supplier, harga_beli, harga_jual, stok) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $id_kategori = intval($_POST["id_kategori"]);
    $id_supplier = intval($_POST["id_supplier"]);
    $harga_beli = floatval($_POST["harga_beli"]);
    $harga_jual = floatval($_POST["harga_jual"]);
    $stok = intval($_POST["stok"]);
    $stmt->bind_param("ssiiddi", $_POST["kode_produk"], $_POST["nama_produk"], $id_kategori, $id_supplier, $harga_beli, $harga_jual, $stok);
    if ($stmt->execute()) {
        $pesan = "Produk berhasil ditambahkan.";
    } else {
        $pesan = "Gagal menambahkan produk: " . $conn->error;
    }
}

// Hapus produk
if (isset($_GET["hapus"])) {
    $id = intval($_GET["hapus"]);
    $stmt = $conn->prepare("DELETE FROM produk WHERE id_produk = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $pesan = "Produk berhasil dihapus.";
}

$kategoriList = $conn->query("SELECT * FROM kategori");
$supplierList = $conn->query("SELECT * FROM supplier");
$produkList = $conn->query("SELECT p.*, k.nama_kategori FROM produk p LEFT JOIN kategori k ON p.id_kategori = k.id_kategori ORDER BY p.nama_produk");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kelola Produk - TPS Supermarket</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <h1>🛒 TPS Supermarket</h1>
    <div>
        <a href="index.php">Dashboard</a>
        <a href="produk.php">Produk</a>
        <a href="kelola_produk.php">Kelola Produk</a>
        <a href="kasir.php">Transaksi Kasir</a>
        <a href="riwayat.php">Semua Transaksi</a>
        <a href="logout.php">Logout (<?= htmlspecialchars($_SESSION['nama']) ?>)</a>
    </div>
</div>

<div class="container">
    <h2>Kelola Produk</h2>
    <?php if ($pesan): ?><div class="alert-success"><?= $pesan ?></div><?php endif; ?>

    <h2 style="font-size:16px;">Tambah Produk Baru</h2>
    <form method="POST">
        <input type="hidden" name="aksi" value="tambah">
        <div class="form-row"><label>Kode Produk</label><input type="text" name="kode_produk" required></div>
        <div class="form-row"><label>Nama Produk</label><input type="text" name="nama_produk" required></div>
        <div class="form-row">
            <label>Kategori</label>
            <select name="id_kategori">
                <?php while ($k = $kategoriList->fetch_assoc()): ?>
                    <option value="<?= $k['id_kategori'] ?>"><?= $k['nama_kategori'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="form-row">
            <label>Supplier</label>
            <select name="id_supplier">
                <?php while ($s = $supplierList->fetch_assoc()): ?>
                    <option value="<?= $s['id_supplier'] ?>"><?= $s['nama_supplier'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="form-row"><label>Harga Beli</label><input type="number" name="harga_beli" required></div>
        <div class="form-row"><label>Harga Jual</label><input type="number" name="harga_jual" required></div>
        <div class="form-row"><label>Stok Awal</label><input type="number" name="stok" required></div>
        <button type="submit">Tambah Produk</button>
    </form>

    <h2 style="font-size:16px; margin-top:30px;">Daftar Produk</h2>
    <table>
        <tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Harga Jual</th><th>Stok</th><th>Aksi</th></tr>
        <?php while ($p = $produkList->fetch_assoc()): ?>
        <tr>
            <td><?= $p['kode_produk'] ?></td>
            <td><?= $p['nama_produk'] ?></td>
            <td><?= $p['nama_kategori'] ?></td>
            <td>Rp <?= number_format($p['harga_jual'],0,',','.') ?></td>
            <td><?= $p['stok'] ?></td>
            <td><a href="?hapus=<?= $p['id_produk'] ?>" onclick="return confirm('Hapus produk ini?')">Hapus</a></td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>

</body>
</html>

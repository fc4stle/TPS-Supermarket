<?php
require "auth.php";
require "config.php";
require_login('admin');
$pesan = "";
$berhasil = false;

// Proses submit transaksi
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_pegawai = intval($_POST["id_pegawai"]);
    $id_pelanggan_raw = intval($_POST["id_pelanggan"]);
    $id_pelanggan = $id_pelanggan_raw > 0 ? $id_pelanggan_raw : null;
    $metode_bayar = $_POST["metode_bayar"];
    $jumlah_bayar = floatval($_POST["jumlah_bayar"]);

    $ids = $_POST["id_produk"];
    $jumlahs = $_POST["qty"];
    $hargas = $_POST["harga"];

    $items = []; // item yang qty > 0
    $total = 0;

    foreach ($ids as $i => $id_produk) {
        $qty = intval($jumlahs[$i]);
        if ($qty > 0) {
            $harga = floatval($hargas[$i]);
            $subtotal = $qty * $harga;
            $items[] = ["id_produk" => $id_produk, "qty" => $qty, "harga" => $harga, "subtotal" => $subtotal];
            $total += $subtotal;
        }
    }

    if (empty($items)) {
        $pesan = "Pilih minimal satu produk dengan jumlah lebih dari 0.";
    } elseif ($jumlah_bayar < $total) {
        $pesan = "Jumlah bayar kurang dari total belanja.";
    } else {
        // Buat nomor nota otomatis
        $no_nota = "TRX-" . date("Ymd-His");
        $kembalian = $jumlah_bayar - $total;

        $conn->begin_transaction();
        try {
            // 1. Insert header transaksi
            $stmt = $conn->prepare("INSERT INTO transaksi (no_nota, id_pegawai, id_pelanggan, total_belanja) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("siid", $no_nota, $id_pegawai, $id_pelanggan, $total);
            $stmt->execute();
            $id_transaksi = $conn->insert_id;
            $stmt->close();

            // 2. Insert detail transaksi + update stok
            $stmtDetail = $conn->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, jumlah, harga_satuan, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmtStok = $conn->prepare("UPDATE produk SET stok = stok - ? WHERE id_produk = ?");

            foreach ($items as $item) {
                $stmtDetail->bind_param("iiidd", $id_transaksi, $item["id_produk"], $item["qty"], $item["harga"], $item["subtotal"]);
                $stmtDetail->execute();

                $stmtStok->bind_param("ii", $item["qty"], $item["id_produk"]);
                $stmtStok->execute();
            }
            $stmtDetail->close();
            $stmtStok->close();

            // 3. Insert pembayaran
            $stmtBayar = $conn->prepare("INSERT INTO pembayaran (id_transaksi, metode_bayar, jumlah_bayar, kembalian) VALUES (?, ?, ?, ?)");
            $stmtBayar->bind_param("isdd", $id_transaksi, $metode_bayar, $jumlah_bayar, $kembalian);
            $stmtBayar->execute();
            $stmtBayar->close();

            $conn->commit();
            $berhasil = true;
            $pesan = "Transaksi berhasil disimpan! No. Nota: $no_nota | Total: Rp " . number_format($total,0,',','.') . " | Kembalian: Rp " . number_format($kembalian,0,',','.');
        } catch (Exception $e) {
            $conn->rollback();
            $pesan = "Gagal menyimpan transaksi: " . $e->getMessage();
        }
    }
}

$produkList = $conn->query("SELECT * FROM produk WHERE stok > 0 ORDER BY nama_produk");
$pegawaiList = $conn->query("SELECT * FROM pegawai");
$pelangganList = $conn->query("SELECT * FROM pelanggan");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Transaksi Baru - TPS Supermarket</title>
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
    <h2>Buat Transaksi Baru (oleh Admin/Kasir)</h2>

    <?php if ($pesan): ?>
        <div class="<?= $berhasil ? 'alert-success' : 'alert-error' ?>"><?= $pesan ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-row">
            <label>Kasir</label>
            <select name="id_pegawai" required>
                <?php while ($p = $pegawaiList->fetch_assoc()): ?>
                    <option value="<?= $p['id_pegawai'] ?>"><?= $p['nama_pegawai'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="form-row">
            <label>Pelanggan</label>
            <select name="id_pelanggan">
                <option value="0">-- Umum --</option>
                <?php while ($pl = $pelangganList->fetch_assoc()): ?>
                    <option value="<?= $pl['id_pelanggan'] ?>"><?= $pl['nama_pelanggan'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <table>
            <tr>
                <th>Produk</th>
                <th>Harga</th>
                <th>Stok</th>
                <th>Jumlah Beli</th>
                <th>Subtotal</th>
            </tr>
            <?php while ($row = $produkList->fetch_assoc()): ?>
            <tr>
                <td>
                    <?= $row['nama_produk'] ?>
                    <input type="hidden" name="id_produk[]" value="<?= $row['id_produk'] ?>">
                    <input type="hidden" name="harga[]" class="harga-item" value="<?= $row['harga_jual'] ?>">
                </td>
                <td>Rp <?= number_format($row['harga_jual'],0,',','.') ?></td>
                <td><?= $row['stok'] ?></td>
                <td><input type="number" name="qty[]" class="qty-item" min="0" max="<?= $row['stok'] ?>" value="0" onchange="hitungTotal()" oninput="hitungTotal()"></td>
                <td class="subtotal-item">Rp 0</td>
            </tr>
            <?php endwhile; ?>
        </table>

        <div class="total-box">Total Belanja: <span id="totalBelanja">Rp 0</span></div>

        <div class="form-row" style="margin-top:20px;">
            <label>Metode Bayar</label>
            <select name="metode_bayar" required>
                <option value="tunai">Tunai</option>
                <option value="debit">Debit</option>
                <option value="kredit">Kredit</option>
                <option value="qris">QRIS</option>
            </select>
        </div>
        <div class="form-row">
            <label>Jumlah Bayar (Rp)</label>
            <input type="number" id="jumlahBayar" name="jumlah_bayar" min="0" step="100" required onchange="hitungTotal()" oninput="hitungTotal()">
        </div>
        <div class="form-row">
            <label>Kembalian</label>
            <span id="kembalian">Rp 0</span>
        </div>

        <button type="submit">Simpan Transaksi</button>
    </form>
</div>

<script>
function formatRupiah(angka) {
    return "Rp " + angka.toLocaleString('id-ID');
}

function hitungTotal() {
    const hargaList = document.querySelectorAll('.harga-item');
    const qtyList = document.querySelectorAll('.qty-item');
    const subtotalCells = document.querySelectorAll('.subtotal-item');
    let total = 0;

    qtyList.forEach((qtyInput, i) => {
        const harga = parseFloat(hargaList[i].value) || 0;
        const qty = parseInt(qtyInput.value) || 0;
        const subtotal = harga * qty;
        subtotalCells[i].textContent = formatRupiah(subtotal);
        total += subtotal;
    });

    document.getElementById('totalBelanja').textContent = formatRupiah(total);

    const jumlahBayar = parseFloat(document.getElementById('jumlahBayar').value) || 0;
    const kembalian = jumlahBayar - total;
    document.getElementById('kembalian').textContent = formatRupiah(kembalian < 0 ? 0 : kembalian);
}
</script>

</body>
</html>

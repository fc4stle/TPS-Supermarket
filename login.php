<?php
require "config.php";
session_start();
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($_POST["form_type"] == "admin") {
        $username = $_POST["username"];
        $password = $_POST["password"];
        $stmt = $conn->prepare("SELECT * FROM pegawai WHERE username = ? AND password = ?");
        $stmt->bind_param("ss", $username, $password);
        $stmt->execute();
        $pegawai = $stmt->get_result()->fetch_assoc();
        if ($pegawai) {
            $_SESSION['role'] = 'admin';
            $_SESSION['id_pegawai'] = $pegawai['id_pegawai'];
            $_SESSION['nama'] = $pegawai['nama_pegawai'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Username atau password admin salah.";
        }
    } elseif ($_POST["form_type"] == "pembeli") {
        $nama = trim($_POST["nama_pelanggan"]);
        if ($nama == "") {
            $error = "Nama tidak boleh kosong.";
        } else {
            // Cari pelanggan yang sudah ada, kalau belum ada, buat baru
            $stmt = $conn->prepare("SELECT * FROM pelanggan WHERE nama_pelanggan = ?");
            $stmt->bind_param("s", $nama);
            $stmt->execute();
            $pelanggan = $stmt->get_result()->fetch_assoc();

            if (!$pelanggan) {
                $stmt2 = $conn->prepare("INSERT INTO pelanggan (nama_pelanggan) VALUES (?)");
                $stmt2->bind_param("s", $nama);
                $stmt2->execute();
                $id_pelanggan = $conn->insert_id;
            } else {
                $id_pelanggan = $pelanggan['id_pelanggan'];
            }

            $_SESSION['role'] = 'pembeli';
            $_SESSION['id_pelanggan'] = $id_pelanggan;
            $_SESSION['nama'] = $nama;
            header("Location: index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Login - TPS Supermarket</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="navbar">
    <h1>🛒 TPS Supermarket</h1>
</div>

<div class="container" style="max-width:500px;">
    <h2>Login</h2>
    <?php if ($error): ?>
        <div class="alert-error"><?= $error ?></div>
    <?php endif; ?>

    <h2 style="font-size:16px; margin-top:20px;">Login sebagai Admin / Kasir</h2>
    <form method="POST">
        <input type="hidden" name="form_type" value="admin">
        <div class="form-row"><label>Username</label><input type="text" name="username" required></div>
        <div class="form-row"><label>Password</label><input type="password" name="password" required></div>
        <button type="submit">Login Admin</button>
    </form>

    <hr style="margin:24px 0;">

    <h2 style="font-size:16px;">Login sebagai Pembeli</h2>
    <form method="POST">
        <input type="hidden" name="form_type" value="pembeli">
        <div class="form-row"><label>Nama Anda</label><input type="text" name="nama_pelanggan" required></div>
        <button type="submit">Masuk sebagai Pembeli</button>
    </form>
</div>

</body>
</html>

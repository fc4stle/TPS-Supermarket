<?php
require __DIR__ . '/includes/app.php';

if (me()) redirect('beranda.php');

$username = '';
if (is_post()) {
    $username = post('username');
    $u = row('SELECT * FROM pegawai WHERE username=?', [$username]);
    if (!$u || !password_verify(post('password'), $u['password'])) {
        flash('err', 'Username atau password salah.');
    } elseif (!$u['aktif']) {
        flash('err', 'Akun Anda dinonaktifkan. Hubungi administrator.');
    } else {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        flash('ok', 'Selamat datang, <b>' . e($u['nama']) . '</b>! 👋');
        redirect('beranda.php');
    }
    $_SESSION['last_user'] = $username;
    redirect('login.php');
}
$username = $_SESSION['last_user'] ?? '';
unset($_SESSION['last_user']);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Masuk · <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛒</text></svg>">
</head>
<body>
<div class="login-page">
  <section class="login-hero">
    <div class="lh-brand"><span class="logo">🛒</span><span><b>Mart</b><i>Office</i></span></div>
    <h1>Satu sistem untuk<br><span>seluruh supermarket.</span></h1>
    <p>Kasir &amp; stok, tugas &amp; administrasi kantor, sampai pusat pengetahuan karyawan — semuanya terhubung di MartOffice.</p>
    <div class="fruits" aria-hidden="true">🍎🥖🥛🧀🥕🍌</div>
    <div class="feat">
      <div><span>🧾</span><p class="mb0"><b>TPS — Transaction Processing</b><small>Kasir, nota, stok, dan laporan penjualan</small></p></div>
      <div><span>📋</span><p class="mb0"><b>OAS — Office Automation</b><small>Tugas, pengumuman, agenda, dokumen, pesan</small></p></div>
      <div><span>📚</span><p class="mb0"><b>KMS — Knowledge Management</b><small>SOP &amp; panduan dengan review dan riwayat versi</small></p></div>
      <div><span>📈</span><p class="mb0"><b>MIS &amp; DSS — Manajemen</b><small>Kinerja perusahaan &amp; pemilihan supplier (SAW, via SSO)</small></p></div>
    </div>
  </section>

  <section class="login-side">
    <div class="login-box">
      <span class="badge y">🏷️ <?= APP_TAGLINE ?></span>
      <h2 style="margin-top:12px">Masuk</h2>
      <p class="muted">Gunakan akun pegawai Anda untuk melanjutkan.</p>
      <?= render_flash() ?>
      <form method="post" autocomplete="off">
        <?= csrf() ?>
        <label for="username">Username</label>
        <input id="username" name="username" value="<?= e($username) ?>" required autofocus placeholder="mis. staff">
        <label for="password">Password</label>
        <div class="pw-wrap">
          <input id="password" name="password" type="password" required placeholder="••••••••">
          <button type="button" data-toggle-pw="password" aria-label="Lihat password">👁️</button>
        </div>
        <div class="form-actions"><button class="block" type="submit">Masuk ke MartOffice →</button></div>
      </form>
      <details class="demo">
        <summary>🔑 Akun uji coba</summary>
        <p class="mb0" style="margin-top:6px">Klik untuk mengisi otomatis:</p>
        <button type="button" class="sm ghost" onclick="isi('admin','admin123')">Administrator</button>
        <button type="button" class="sm ghost" onclick="isi('manajer','manajer123')">Manajer</button>
        <button type="button" class="sm ghost" onclick="isi('staff','staff123')">Staff</button>
      </details>
    </div>
  </section>
</div>
<script>
function isi(u, p) { username.value = u; password.value = p; password.focus(); }
</script>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>

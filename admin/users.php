<?php
require __DIR__ . '/../includes/app.php';
need('admin');

if (is_post()) {
    $aksi = post('aksi');
    $id   = (int) post('id');

    if ($aksi === 'simpan') {
        $nama = post('nama'); $username = strtolower(post('username')); $jabatan = post('jabatan');
        $role = pilih(post('role'), ['admin', 'manajer', 'staff'], '');
        $pass = (string) ($_POST['password'] ?? '');
        $err = [];
        if ($nama === '' || $username === '' || !$role) $err[] = 'Nama, username, dan role wajib diisi.';
        if (!preg_match('/^[a-z0-9._]{3,40}$/', $username)) $err[] = 'Username 3–40 karakter: huruf kecil, angka, titik, atau garis bawah.';
        if (val('SELECT COUNT(*) FROM pegawai WHERE username=? AND id!=?', [$username, $id])) $err[] = 'Username sudah dipakai.';
        if (!$id && strlen($pass) < 6) $err[] = 'Password minimal 6 karakter.';
        if ($id && $pass !== '' && strlen($pass) < 6) $err[] = 'Password baru minimal 6 karakter.';
        if ($id === uid() && $role !== 'admin') $err[] = 'Anda tidak dapat mengubah role akun Anda sendiri.';
        if ($err) {
            flash('err', implode('<br>', $err));
            $_SESSION['old_user'] = $_POST;
            redirect('admin/users.php?' . ($id ? 'edit=' . $id : 'tambah=1') . '#form');
        }
        if ($id) {
            q('UPDATE pegawai SET nama=?,username=?,jabatan=?,role=? WHERE id=?', [$nama, $username, $jabatan, $role, $id]);
            if ($pass !== '') q('UPDATE pegawai SET password=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            flash('ok', 'Data <b>' . e($nama) . '</b> diperbarui' . ($pass !== '' ? ' (password direset)' : '') . '.');
        } else {
            q('INSERT INTO pegawai(nama,username,password,role,jabatan) VALUES(?,?,?,?,?)', [$nama, $username, password_hash($pass, PASSWORD_DEFAULT), $role, $jabatan]);
            flash('ok', 'Akun <b>' . e($username) . '</b> berhasil dibuat.');
        }
        redirect('admin/users.php');
    }

    if (in_array($aksi, ['nonaktif', 'aktifkan'], true)) {
        if ($id === uid()) flash('err', 'Anda tidak dapat menonaktifkan akun sendiri.');
        else {
            q('UPDATE pegawai SET aktif=? WHERE id=?', [$aksi === 'aktifkan' ? 1 : 0, $id]);
            flash('ok', $aksi === 'aktifkan' ? 'Akun diaktifkan kembali.' : 'Akun dinonaktifkan dan tidak bisa login.');
        }
        redirect('admin/users.php');
    }
}

$fr = pilih(get('role'), ['admin', 'manajer', 'staff'], '');
$users = rows('SELECT p.*,
    (SELECT COUNT(*) FROM transaksi t WHERE t.id_kasir=p.id) trx,
    (SELECT COUNT(*) FROM artikel_kms a WHERE a.id_penulis=p.id) kms
    FROM pegawai p' . ($fr ? ' WHERE p.role=?' : '') . ' ORDER BY p.aktif DESC, p.role, p.nama', $fr ? [$fr] : []);

$edit = null;
if (get('edit') || get('tambah')) {
    $edit = get('edit') ? row('SELECT * FROM pegawai WHERE id=?', [(int) get('edit')]) : [];
    if (!empty($_SESSION['old_user'])) $edit = array_merge($edit ?: [], $_SESSION['old_user']);
}
unset($_SESSION['old_user']);
$jml = array_column(rows('SELECT role, COUNT(*) n FROM pegawai WHERE aktif=1 GROUP BY role'), 'n', 'role');

page_start('User & Role', 'users', 'Kelola akun pegawai dan hak akses setiap role.');
?>
<div class="stats">
  <?= stat_card('Administrator', angka($jml['admin'] ?? 0), '🛡️', 'p', 'admin/users.php?role=admin') ?>
  <?= stat_card('Manajer', angka($jml['manajer'] ?? 0), '🧑‍💼', 'b', 'admin/users.php?role=manajer') ?>
  <?= stat_card('Staff', angka($jml['staff'] ?? 0), '🧑‍🍳', 'g', 'admin/users.php?role=staff') ?>
</div>

<div class="grid g-form">
  <div class="card" id="form">
    <?php if ($edit !== null): $id = (int) ($edit['id'] ?? 0); ?>
      <div class="card-h"><h3><?= $id ? '✏️ Ubah Akun' : '➕ Akun Baru' ?></h3><a href="<?= url('admin/users.php') ?>">Batal</a></div>
      <form method="post" autocomplete="off">
        <?= csrf() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $id ?>">
        <label>Nama lengkap</label><input name="nama" value="<?= e($edit['nama'] ?? '') ?>" required>
        <label>Username</label><input name="username" value="<?= e($edit['username'] ?? '') ?>" required pattern="[a-z0-9._]{3,40}">
        <label>Jabatan</label><input name="jabatan" value="<?= e($edit['jabatan'] ?? '') ?>" placeholder="mis. Kasir, Store Manager">
        <label>Role</label>
        <div class="radio-row">
          <?php foreach (['admin' => '🛡️ Admin', 'manajer' => '🧑‍💼 Manajer', 'staff' => '🧑‍🍳 Staff'] as $k => $v): ?>
            <label class="radio-card"><input type="radio" name="role" value="<?= $k ?>" <?= ($edit['role'] ?? 'staff') === $k ? 'checked' : '' ?>> <?= $v ?></label>
          <?php endforeach; ?>
        </div>
        <label><?= $id ? 'Reset password (kosongkan bila tidak diubah)' : 'Password' ?></label>
        <div class="pw-wrap"><input type="password" name="password" id="pw" <?= $id ? '' : 'required' ?> minlength="6" autocomplete="new-password">
          <button type="button" data-toggle-pw="pw">👁️</button></div>
        <div class="form-actions"><button type="submit">💾 Simpan</button></div>
      </form>
    <?php else: ?>
      <div class="card-h"><h3>🔐 Hak Akses Role</h3></div>
      <div class="list">
        <div class="li"><span class="li-ic">🛡️</span><div class="li-body"><b>Administrator</b><small>Kelola user, produk, kategori KMS; akses kasir, laporan, OAS, dan review KMS.</small></div></div>
        <div class="li"><span class="li-ic">🧑‍💼</span><div class="li-body"><b>Manajer</b><small>Laporan penjualan, restok, beri tugas, pengumuman, agenda, dan review knowledge.</small></div></div>
        <div class="li"><span class="li-ic">🧑‍🍳</span><div class="li-body"><b>Staff</b><small>Kasir, cek stok, kerjakan tugas, dokumen, pesan, dan kontribusi knowledge.</small></div></div>
      </div>
      <div class="form-actions"><a class="btn y block" href="<?= url('admin/users.php?tambah=1#form') ?>">➕ Tambah Akun Pegawai</a></div>
    <?php endif; ?>
  </div>

  <div class="card tight">
    <div class="card-h"><h3>👥 Daftar Pegawai</h3>
      <div class="chips"><?php foreach (['' => 'Semua', 'admin' => 'Admin', 'manajer' => 'Manajer', 'staff' => 'Staff'] as $k => $v) echo '<a class="chip ' . ($fr === $k ? 'on' : '') . '" href="?role=' . $k . '">' . $v . '</a>'; ?></div></div>
    <div class="table-wrap" style="margin-top:12px">
    <table><thead><tr><th>Pegawai</th><th>Role</th><th class="num">Transaksi</th><th class="num">Knowledge</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($users as $x): ?>
        <tr style="<?= $x['aktif'] ? '' : 'opacity:.55' ?>">
          <td><div class="actions" style="flex-wrap:nowrap"><span class="avatar"><?= e(inisial($x['nama'])) ?></span><div><b><?= e($x['nama']) ?></b><br><small>@<?= e($x['username']) ?> · <?= e($x['jabatan'] ?: '-') ?></small></div></div></td>
          <td><?= status_badge($x['role']) ?></td>
          <td class="num"><?= angka($x['trx']) ?></td>
          <td class="num"><?= angka($x['kms']) ?></td>
          <td><?= $x['aktif'] ? badge('Aktif', 'g') : badge('Nonaktif', 'n') ?></td>
          <td class="right nowrap">
            <a class="btn sm ghost" href="<?= url('admin/users.php?edit=' . $x['id'] . '#form') ?>">✏️ Ubah</a>
            <?php if ((int) $x['id'] !== uid()): ?>
            <form method="post" class="inline"><?= csrf() ?><input type="hidden" name="id" value="<?= $x['id'] ?>">
              <?php if ($x['aktif']): ?><button class="sm r" name="aksi" value="nonaktif" data-confirm="Nonaktifkan akun <?= e($x['nama']) ?>?">Nonaktifkan</button>
              <?php else: ?><button class="sm" name="aksi" value="aktifkan">Aktifkan</button><?php endif; ?>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    </div>
  </div>
</div>
<?php page_end();

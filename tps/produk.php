<?php
require __DIR__ . '/../includes/app.php';
need();
$isAdmin = role() === 'admin';

if (is_post()) {
    $aksi = post('aksi');
    $pid  = (int) post('id');

    if ($aksi === 'simpan' && $isAdmin) {
        $d = [
            'sku' => strtoupper(post('sku')), 'nama' => post('nama'), 'ikon' => post('ikon') ?: '🛍️',
            'kategori' => post('kategori'), 'satuan' => post('satuan') ?: 'pcs', 'harga' => (float) post('harga'),
        ];
        $err = [];
        if ($d['sku'] === '' || $d['nama'] === '' || $d['kategori'] === '') $err[] = 'SKU, nama, dan kategori wajib diisi.';
        if ($d['harga'] <= 0) $err[] = 'Harga harus lebih dari 0.';
        if (mb_strlen($d['ikon']) > 8) $err[] = 'Ikon cukup satu emoji.';
        if (val('SELECT COUNT(*) FROM produk WHERE sku=? AND id!=?', [$d['sku'], $pid])) $err[] = 'SKU ' . e($d['sku']) . ' sudah dipakai produk lain.';
        if ($err) {
            flash('err', implode('<br>', $err));
            $_SESSION['old_produk'] = $_POST;
            redirect('tps/produk.php' . ($pid ? '?edit=' . $pid : '?tambah=1'));
        }
        if ($pid) {
            q('UPDATE produk SET sku=?,nama=?,ikon=?,kategori=?,satuan=?,harga=? WHERE id=?', [...array_values($d), $pid]);
            flash('ok', 'Produk <b>' . e($d['nama']) . '</b> diperbarui.');
        } else {
            $stok = max(0, (int) post('stok'));
            q('INSERT INTO produk(sku,nama,ikon,kategori,satuan,harga,stok) VALUES(?,?,?,?,?,?,?)', [...array_values($d), $stok]);
            if ($stok > 0) catat_stok(last_id(), $stok, 'awal', 'Stok awal produk baru');
            flash('ok', 'Produk <b>' . e($d['nama']) . '</b> ditambahkan ke rak.');
        }
        redirect('tps/produk.php');
    }

    if ($aksi === 'restok' && is_boss()) {
        $n = (int) post('jumlah');
        if ($n < 1) { flash('err', 'Jumlah restok minimal 1.'); back(); }
        q('UPDATE produk SET stok=stok+? WHERE id=?', [$n, $pid]);
        catat_stok($pid, $n, 'restok', post('ket') ?: 'Restok');
        flash('ok', 'Stok <b>' . e(val('SELECT nama FROM produk WHERE id=?', [$pid])) . '</b> bertambah ' . $n . '.');
        back();
    }

    if ($aksi === 'koreksi' && is_boss()) {
        $baru = (int) post('stok_fisik');
        $ket  = post('ket');
        $lama = (int) val('SELECT stok FROM produk WHERE id=?', [$pid]);
        if ($baru < 0 || $ket === '') { flash('err', 'Stok fisik tidak boleh negatif dan alasan koreksi wajib diisi.'); back(); }
        if ($baru !== $lama) {
            q('UPDATE produk SET stok=? WHERE id=?', [$baru, $pid]);
            catat_stok($pid, $baru - $lama, 'koreksi', $ket);
        }
        flash('ok', 'Stok dikoreksi dari ' . $lama . ' menjadi ' . $baru . '.');
        back();
    }

    if (in_array($aksi, ['arsip', 'aktifkan'], true) && $isAdmin) {
        q('UPDATE produk SET aktif=? WHERE id=?', [$aksi === 'aktifkan' ? 1 : 0, $pid]);
        flash('ok', $aksi === 'arsip' ? 'Produk diarsipkan dan tidak tampil di kasir.' : 'Produk diaktifkan kembali.');
        back();
    }
    redirect('tps/produk.php');
}

$f   = pilih(get('f'), ['menipis', 'arsip', 'habis'], '');
$kat = get('kat');
$cari = get('cari');
$where = [$f === 'arsip' ? 'aktif=0' : 'aktif=1']; $p = [];
if ($f === 'menipis') { $where[] = 'stok<?'; $p[] = STOK_MIN; }
if ($f === 'habis') $where[] = 'stok=0';
if ($kat !== '') { $where[] = 'kategori=?'; $p[] = $kat; }
if ($cari !== '') { $where[] = '(nama LIKE ? OR sku LIKE ?)'; $p[] = "%$cari%"; $p[] = "%$cari%"; }
$produk = rows('SELECT * FROM produk WHERE ' . implode(' AND ', $where) . ' ORDER BY kategori,nama', $p);
$kategori = array_column(rows('SELECT DISTINCT kategori FROM produk ORDER BY kategori'), 'kategori');

$edit = null;
if ($isAdmin && (get('edit') || get('tambah'))) {
    $edit = get('edit') ? row('SELECT * FROM produk WHERE id=?', [(int) get('edit')]) : [];
    if (!empty($_SESSION['old_produk'])) $edit = array_merge($edit ?: [], $_SESSION['old_produk']);
}
unset($_SESSION['old_produk']);
$log = is_boss() ? rows('SELECT l.*,p.nama,p.ikon,g.nama oleh FROM stok_log l JOIN produk p ON p.id=l.id_produk LEFT JOIN pegawai g ON g.id=l.id_pegawai ORDER BY l.waktu DESC, l.id DESC LIMIT 15') : [];

$nilai = (float) val('SELECT COALESCE(SUM(harga*stok),0) FROM produk WHERE aktif=1');
page_start($isAdmin ? 'Produk & Stok' : 'Stok Produk', 'produk', $isAdmin ? 'Kelola rak, harga, dan persediaan supermarket.' : (is_boss() ? 'Pantau persediaan dan lakukan restok.' : 'Lihat ketersediaan barang di rak.'));
?>
<div class="stats">
  <?= stat_card('Produk Aktif', angka(val('SELECT COUNT(*) FROM produk WHERE aktif=1')), '📦', 'g', 'tps/produk.php') ?>
  <?= stat_card('Stok Menipis', angka(val('SELECT COUNT(*) FROM produk WHERE aktif=1 AND stok<?', [STOK_MIN])), '⚠️', 'r', 'tps/produk.php?f=menipis', 'di bawah ' . STOK_MIN . ' unit') ?>
  <?= stat_card('Stok Habis', angka(val('SELECT COUNT(*) FROM produk WHERE aktif=1 AND stok=0')), '🚫', 'o', 'tps/produk.php?f=habis') ?>
  <?= is_boss() ? stat_card('Nilai Persediaan', rp($nilai), '💰', 'y') : '' ?>
</div>

<?php if ($edit !== null): $pid = (int) ($edit['id'] ?? 0); ?>
<div class="card" id="form">
  <div class="card-h"><h3><?= $pid ? '✏️ Ubah Produk' : '➕ Tambah Produk' ?></h3><a href="<?= url('tps/produk.php') ?>">Batal</a></div>
  <form method="post">
    <?= csrf() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= $pid ?>">
    <div class="row">
      <div class="fit" style="width:110px"><label>Ikon</label><input name="ikon" value="<?= e($edit['ikon'] ?? '') ?>" placeholder="🍌" maxlength="8" style="font-size:22px;text-align:center"></div>
      <div><label>SKU / Kode</label><input name="sku" value="<?= e($edit['sku'] ?? '') ?>" required placeholder="BS-005"></div>
      <div style="flex:2"><label>Nama produk</label><input name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></div>
    </div>
    <div class="row">
      <div><label>Kategori</label><input name="kategori" list="dl-kat" value="<?= e($edit['kategori'] ?? '') ?>" required>
        <datalist id="dl-kat"><?php foreach ($kategori as $k) echo '<option value="' . e($k) . '">'; ?></datalist></div>
      <div><label>Satuan</label><input name="satuan" value="<?= e($edit['satuan'] ?? 'pcs') ?>" placeholder="pcs / pack / kg"></div>
      <div><label>Harga jual (Rp)</label><input type="number" name="harga" min="1" step="any" value="<?= e($edit['harga'] ?? '') ?>" required></div>
      <?php if (!$pid): ?><div><label>Stok awal</label><input type="number" name="stok" min="0" value="<?= e($edit['stok'] ?? 0) ?>"></div><?php endif; ?>
    </div>
    <?php if ($pid): ?><p class="hint">Perubahan jumlah stok dilakukan lewat tombol Restok atau Koreksi agar tercatat di riwayat stok.</p><?php endif; ?>
    <div class="form-actions"><button type="submit">💾 Simpan Produk</button></div>
  </form>
</div>
<?php endif; ?>

<form class="card row" method="get">
  <div style="flex:2"><label>Cari produk</label><input name="cari" value="<?= e($cari) ?>" placeholder="Nama atau SKU…"></div>
  <div><label>Kategori</label><select name="kat"><option value="">Semua kategori</option><?php foreach ($kategori as $k) echo '<option' . ($kat === $k ? ' selected' : '') . '>' . e($k) . '</option>'; ?></select></div>
  <div><label>Tampilkan</label><select name="f">
    <?php foreach (['' => 'Semua produk aktif', 'menipis' => 'Stok menipis', 'habis' => 'Stok habis', 'arsip' => 'Produk diarsipkan'] as $k => $v) echo '<option value="' . $k . '"' . ($f === $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select></div>
  <div class="fit"><button type="submit">🔍 Terapkan</button></div>
  <?php if ($isAdmin): ?><div class="fit"><a class="btn y" href="<?= url('tps/produk.php?tambah=1#form') ?>">➕ Produk Baru</a></div><?php endif; ?>
</form>

<div class="card tight">
  <div class="card-h"><h3>📦 Daftar Produk <small>(<?= count($produk) ?>)</small></h3></div>
  <div class="table-wrap" style="margin-top:12px">
  <?php if (!$produk): echo empty_state('📦', 'Tidak ada produk yang cocok', $isAdmin ? 'Tambahkan produk baru dengan tombol “Produk Baru”.' : '');
  else: ?>
  <table>
    <thead><tr><th></th><th>Produk</th><th>Kategori</th><th class="num">Harga</th><th class="num">Stok</th><?= is_boss() ? '<th>Aksi Stok</th>' : '' ?><?= $isAdmin ? '<th></th>' : '' ?></tr></thead>
    <tbody><?php foreach ($produk as $x): $low = $x['stok'] < STOK_MIN; ?>
      <tr>
        <td style="font-size:28px;width:50px"><?= e($x['ikon']) ?></td>
        <td><b><?= e($x['nama']) ?></b><br><small><?= e($x['sku']) ?> · per <?= e($x['satuan']) ?></small></td>
        <td><?= badge($x['kategori'], 'n') ?></td>
        <td class="num"><?= rp($x['harga']) ?></td>
        <td class="num <?= $low ? 'low' : '' ?>"><?= angka($x['stok']) ?><?= $x['stok'] == 0 ? ' 🚫' : ($low ? ' ⚠️' : '') ?></td>
        <?php if (is_boss()): ?>
        <td>
          <form method="post" class="actions" style="flex-wrap:nowrap">
            <?= csrf() ?><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="hidden" name="aksi" value="restok">
            <input type="number" name="jumlah" value="10" min="1" style="width:74px;padding:6px 8px">
            <button class="sm" type="submit">+ Restok</button>
            <button class="sm ghost" type="button" onclick="koreksi(<?= $x['id'] ?>,'<?= e(addslashes($x['nama'])) ?>',<?= $x['stok'] ?>)">Koreksi</button>
          </form>
        </td>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <td class="right nowrap">
          <a class="btn sm ghost" href="<?= url('tps/produk.php?edit=' . $x['id'] . '#form') ?>">✏️</a>
          <form method="post" class="inline"><?= csrf() ?><input type="hidden" name="id" value="<?= $x['id'] ?>">
            <?php if ($x['aktif']): ?><button class="sm r" name="aksi" value="arsip" data-confirm="Arsipkan <?= e($x['nama']) ?>? Produk tidak akan tampil di kasir.">Arsip</button>
            <?php else: ?><button class="sm" name="aksi" value="aktifkan">Aktifkan</button><?php endif; ?>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  </div>
</div>

<?php if (is_boss()): ?>
<div class="card tight" id="koreksiBox" style="display:none">
  <div class="card-h"><h3>🧮 Koreksi Stok — <span id="kNama"></span></h3></div>
  <form method="post" style="padding:0 20px 20px">
    <?= csrf() ?><input type="hidden" name="aksi" value="koreksi"><input type="hidden" name="id" id="kId">
    <div class="row">
      <div><label>Stok di sistem</label><input id="kLama" disabled></div>
      <div><label>Stok fisik hasil hitung</label><input type="number" name="stok_fisik" id="kBaru" min="0" required></div>
      <div style="flex:2"><label>Alasan koreksi</label><input name="ket" required placeholder="mis. barang rusak / hasil stock opname"></div>
      <div class="fit"><button type="submit" class="o">Simpan Koreksi</button></div>
    </div>
  </form>
</div>

<div class="card tight">
  <div class="card-h"><h3>🕘 Riwayat Pergerakan Stok</h3><small>15 terakhir</small></div>
  <div class="table-wrap" style="margin-top:12px">
  <?php if (!$log): echo empty_state('📜', 'Belum ada pergerakan stok');
  else: ?>
  <table><thead><tr><th>Waktu</th><th>Produk</th><th>Jenis</th><th class="num">Perubahan</th><th>Keterangan</th><th>Oleh</th></tr></thead><tbody>
    <?php foreach ($log as $l): ?>
      <tr><td class="nowrap"><?= tgl($l['waktu']) ?></td><td><?= e($l['ikon'] . ' ' . $l['nama']) ?></td>
        <td><?= badge(ucfirst($l['jenis']), ['awal' => 'n', 'restok' => 'g', 'penjualan' => 'b', 'koreksi' => 'o'][$l['jenis']]) ?></td>
        <td class="num <?= $l['perubahan'] < 0 ? 'low' : '' ?>"><b><?= $l['perubahan'] > 0 ? '+' : '' ?><?= $l['perubahan'] ?></b></td>
        <td><?= e($l['keterangan']) ?></td><td><?= e($l['oleh'] ?? '-') ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  </div>
</div>
<script>
function koreksi(id, nama, stok) {
  kId.value = id; kNama.textContent = nama; kLama.value = stok; kBaru.value = stok;
  koreksiBox.style.display = ''; koreksiBox.scrollIntoView({behavior: 'smooth'}); kBaru.focus();
}
</script>
<?php endif; ?>
<?php page_end();

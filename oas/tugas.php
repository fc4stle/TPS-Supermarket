<?php
require __DIR__ . '/../includes/app.php';
need();
$boss = is_boss();

if (is_post()) {
    $aksi = post('aksi');
    $id   = (int) post('id');

    if ($aksi === 'buat' && $boss) {
        $staff = (int) post('id_staff');
        $tenggat = post('tenggat');
        $ok = val("SELECT COUNT(*) FROM pegawai WHERE id=? AND role='staff' AND aktif=1", [$staff]);
        if (post('judul') === '' || !$ok || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tenggat)) {
            flash('err', 'Judul, staff tujuan, dan tenggat wajib diisi dengan benar.');
        } else {
            q('INSERT INTO tugas(judul,deskripsi,id_pemberi,id_staff,prioritas,tenggat) VALUES(?,?,?,?,?,?)',
                [post('judul'), post('deskripsi'), uid(), $staff, pilih(post('prioritas'), ['rendah', 'sedang', 'tinggi'], 'sedang'), $tenggat]);
            flash('ok', 'Tugas <b>' . e(post('judul')) . '</b> dikirim ke ' . e(val('SELECT nama FROM pegawai WHERE id=?', [$staff])) . '.');
        }
        redirect('oas/tugas.php');
    }

    $t = row('SELECT * FROM tugas WHERE id=?', [$id]);
    if (!$t || (!$boss && (int) $t['id_staff'] !== uid())) { flash('err', 'Tugas tidak ditemukan.'); redirect('oas/tugas.php'); }

    if ($aksi === 'kerjakan' && $t['status'] === 'terbuka') {
        q("UPDATE tugas SET status='proses' WHERE id=?", [$id]);
        flash('ok', 'Tugas ditandai sedang dikerjakan. Semangat! 💪');
    } elseif ($aksi === 'selesai' && $t['status'] !== 'selesai') {
        q("UPDATE tugas SET status='selesai', selesai_pada=NOW(), catatan_staff=? WHERE id=?", [post('catatan') ?: null, $id]);
        flash('ok', 'Tugas selesai. Terima kasih! ✅');
    } elseif ($aksi === 'buka' && $boss && $t['status'] === 'selesai') {
        q("UPDATE tugas SET status='proses', selesai_pada=NULL WHERE id=?", [$id]);
        flash('ok', 'Tugas dibuka kembali.');
    } elseif ($aksi === 'hapus' && $boss) {
        q('DELETE FROM tugas WHERE id=?', [$id]);
        flash('ok', 'Tugas dihapus.');
    }
    back();
}

$st = pilih(get('status'), ['terbuka', 'proses', 'selesai', 'aktif'], 'aktif');
$fs = $boss ? (int) get('staff') : uid();
$where = []; $p = [];
if ($st === 'aktif') $where[] = "t.status!='selesai'"; else { $where[] = 't.status=?'; $p[] = $st; }
if ($fs) { $where[] = 't.id_staff=?'; $p[] = $fs; }
$tugas = rows('SELECT t.*,s.nama staff,g.nama pemberi FROM tugas t JOIN pegawai s ON s.id=t.id_staff JOIN pegawai g ON g.id=t.id_pemberi
               WHERE ' . implode(' AND ', $where) . " ORDER BY t.status='selesai', t.tenggat, FIELD(t.prioritas,'tinggi','sedang','rendah')", $p);
$hit = array_column(rows('SELECT status, COUNT(*) n FROM tugas' . ($boss ? '' : ' WHERE id_staff=' . uid()) . ' GROUP BY status'), 'n', 'status');
$staffList = $boss ? rows("SELECT id,nama FROM pegawai WHERE role='staff' AND aktif=1 ORDER BY nama") : [];
$today = date('Y-m-d');

page_start($boss ? 'Tugas Staff' : 'Tugas Saya', 'tugas', $boss ? 'Office Automation: beri dan pantau tugas staff.' : 'Tugas dari manajer — ubah status saat mulai dan selesai dikerjakan.');
?>
<div class="stats">
  <?= stat_card('Terbuka', angka($hit['terbuka'] ?? 0), '📥', 'r', 'oas/tugas.php?status=terbuka') ?>
  <?= stat_card('Sedang Dikerjakan', angka($hit['proses'] ?? 0), '⏳', 'y', 'oas/tugas.php?status=proses') ?>
  <?= stat_card('Selesai', angka($hit['selesai'] ?? 0), '✅', 'g', 'oas/tugas.php?status=selesai') ?>
</div>

<div class="grid <?= $boss ? 'g-form' : '' ?>">
  <?php if ($boss): ?>
  <div class="card">
    <div class="card-h"><h3>➕ Beri Tugas ke Staff</h3></div>
    <?php if (!$staffList): echo empty_state('👤', 'Belum ada akun staff aktif', 'Admin perlu menambahkan akun staff terlebih dahulu.'); else: ?>
    <form method="post">
      <?= csrf() ?><input type="hidden" name="aksi" value="buat">
      <label>Judul tugas</label><input name="judul" required maxlength="120" placeholder="mis. Rapikan rak minuman">
      <label>Deskripsi</label><textarea name="deskripsi" rows="3" placeholder="Detail pekerjaan…"></textarea>
      <div class="row">
        <div><label>Untuk staff</label><select name="id_staff"><?php foreach ($staffList as $s) echo '<option value="' . $s['id'] . '">' . e($s['nama']) . '</option>'; ?></select></div>
        <div><label>Tenggat</label><input type="date" name="tenggat" min="<?= $today ?>" value="<?= $today ?>" required></div>
      </div>
      <label>Prioritas</label>
      <div class="radio-row">
        <label class="radio-card"><input type="radio" name="prioritas" value="rendah"> 🟢 Rendah</label>
        <label class="radio-card"><input type="radio" name="prioritas" value="sedang" checked> 🟡 Sedang</label>
        <label class="radio-card"><input type="radio" name="prioritas" value="tinggi"> 🔴 Tinggi</label>
      </div>
      <div class="form-actions"><button type="submit">📤 Kirim Tugas</button></div>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-h">
      <h3>📋 <?= $boss ? 'Daftar Tugas' : 'Tugas Saya' ?></h3>
      <form method="get" class="actions">
        <select name="status" onchange="this.form.submit()" style="width:auto">
          <?php foreach (['aktif' => 'Belum selesai', 'terbuka' => 'Terbuka', 'proses' => 'Dikerjakan', 'selesai' => 'Selesai'] as $k => $v) echo '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $v . '</option>'; ?>
        </select>
        <?php if ($boss): ?><select name="staff" onchange="this.form.submit()" style="width:auto"><option value="0">Semua staff</option>
          <?php foreach ($staffList as $s) echo '<option value="' . $s['id'] . '"' . ($fs === (int) $s['id'] ? ' selected' : '') . '>' . e($s['nama']) . '</option>'; ?></select><?php endif; ?>
      </form>
    </div>
    <?php if (!$tugas): echo empty_state('🗒️', 'Tidak ada tugas di daftar ini', $boss ? 'Tugas yang Anda berikan akan muncul di sini.' : 'Tugas dari manajer akan muncul di sini.');
    else: ?><div class="list"><?php foreach ($tugas as $t): $telat = $t['status'] !== 'selesai' && $t['tenggat'] < $today; ?>
      <div class="li">
        <span class="li-ic"><?= ['terbuka' => '📥', 'proses' => '⏳', 'selesai' => '✅'][$t['status']] ?></span>
        <div class="li-body">
          <b><?= e($t['judul']) ?> <?= status_badge($t['prioritas']) ?></b>
          <?php if ($t['deskripsi']): ?><small style="white-space:pre-line;color:var(--ink);margin:4px 0"><?= e($t['deskripsi']) ?></small><?php endif; ?>
          <small><?= $boss ? '👤 ' . e($t['staff']) . ' · ' : 'Dari ' . e($t['pemberi']) . ' · ' ?>
            <span class="<?= $telat ? 'overdue' : '' ?>">📅 <?= $telat ? 'Terlambat! ' : '' ?>tenggat <?= tgl($t['tenggat'], false) ?></span>
            <?= $t['selesai_pada'] ? ' · selesai ' . tgl($t['selesai_pada']) : '' ?></small>
          <?php if ($t['catatan_staff']): ?><small>💬 “<?= e($t['catatan_staff']) ?>”</small><?php endif; ?>
          <form method="post" class="actions" style="margin-top:8px">
            <?= csrf() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
            <?php if ($t['status'] === 'terbuka' && !$boss): ?><button class="sm o" name="aksi" value="kerjakan">▶ Kerjakan</button><?php endif; ?>
            <?php if ($t['status'] === 'proses' && !$boss): ?>
              <input name="catatan" placeholder="Catatan hasil (opsional)" style="max-width:260px;padding:6px 10px">
              <button class="sm" name="aksi" value="selesai">✔ Tandai Selesai</button>
            <?php endif; ?>
            <?php if ($boss && $t['status'] === 'selesai'): ?><button class="sm ghost" name="aksi" value="buka">↺ Buka lagi</button><?php endif; ?>
            <?php if ($boss): ?><button class="sm ghost" name="aksi" value="hapus" data-confirm="Hapus tugas ini?">🗑 Hapus</button><?php endif; ?>
          </form>
        </div>
        <?= status_badge($t['status']) ?>
      </div>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<?php page_end();

<?php
require __DIR__ . '/../includes/app.php';
need();
$boss = is_boss();

if (is_post() && $boss) {
    if (post('aksi') === 'buat') {
        $mulai = post('tanggal') . ' ' . (post('jam_mulai') ?: '00:00') . ':00';
        $selesai = post('jam_selesai') ? post('tanggal') . ' ' . post('jam_selesai') . ':00' : null;
        if (post('judul') === '' || !strtotime($mulai)) flash('err', 'Judul dan tanggal agenda wajib diisi.');
        elseif ($selesai && $selesai <= $mulai) flash('err', 'Jam selesai harus setelah jam mulai.');
        else {
            q('INSERT INTO agenda(judul,deskripsi,lokasi,mulai,selesai,untuk,id_pembuat) VALUES(?,?,?,?,?,?,?)',
                [post('judul'), post('deskripsi'), post('lokasi'), $mulai, $selesai, pilih(post('untuk'), ['semua', 'manajemen', 'staff'], 'semua'), uid()]);
            flash('ok', 'Agenda <b>' . e(post('judul')) . '</b> dijadwalkan.');
        }
    } elseif (post('aksi') === 'hapus') {
        q('DELETE FROM agenda WHERE id=?', [(int) post('id')]);
        flash('ok', 'Agenda dihapus.');
    }
    back();
}

$untuk = role() === 'staff' ? "untuk IN ('semua','staff')" : '1=1';
$bln = preg_match('/^\d{4}-\d{2}$/', get('bln')) ? get('bln') : date('Y-m');
$awal = strtotime($bln . '-01');
$tab = pilih(get('tab'), ['mendatang', 'lampau'], 'mendatang');

$list = $tab === 'mendatang'
    ? rows("SELECT a.*,p.nama FROM agenda a JOIN pegawai p ON p.id=a.id_pembuat WHERE $untuk AND COALESCE(a.selesai,a.mulai)>=NOW() - INTERVAL 1 HOUR ORDER BY a.mulai LIMIT 30")
    : rows("SELECT a.*,p.nama FROM agenda a JOIN pegawai p ON p.id=a.id_pembuat WHERE $untuk AND COALESCE(a.selesai,a.mulai)<NOW() - INTERVAL 1 HOUR ORDER BY a.mulai DESC LIMIT 30");
$hariAda = array_column(rows("SELECT DAY(mulai) d, COUNT(*) n FROM agenda WHERE $untuk AND DATE_FORMAT(mulai,'%Y-%m')=? GROUP BY DAY(mulai)", [$bln]), 'n', 'd');
$namaBln = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$singkat = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

page_start('Agenda', 'agenda', 'Jadwal rapat, stock opname, pelatihan, dan kegiatan toko.');
?>
<div class="grid g-side">
  <div class="card">
    <div class="tabs">
      <a class="<?= $tab === 'mendatang' ? 'on' : '' ?>" href="?tab=mendatang&bln=<?= $bln ?>">🗓️ Mendatang</a>
      <a class="<?= $tab === 'lampau' ? 'on' : '' ?>" href="?tab=lampau&bln=<?= $bln ?>">🕘 Sudah lewat</a>
    </div>
    <?php if (!$list): echo empty_state('📅', $tab === 'mendatang' ? 'Belum ada agenda mendatang' : 'Belum ada agenda yang lewat', $boss && $tab === 'mendatang' ? 'Jadwalkan kegiatan lewat formulir di samping.' : '');
    else: foreach ($list as $a): $t = strtotime($a['mulai']); ?>
      <div class="agenda-item">
        <div class="date-box <?= $tab === 'lampau' ? 'past' : '' ?>"><span><?= $singkat[(int) date('n', $t)] ?></span><b><?= date('j', $t) ?></b></div>
        <div class="li-body">
          <b style="font-size:15px"><?= e($a['judul']) ?></b>
          <small>🕒 <?= tgl_panjang($a['mulai']) ?>, <?= date('H:i', $t) ?><?= $a['selesai'] ? '–' . date('H:i', strtotime($a['selesai'])) : '' ?> WIB
            <?= $a['lokasi'] ? ' · 📍 ' . e($a['lokasi']) : '' ?></small>
          <?php if ($a['deskripsi']): ?><p style="margin:6px 0;white-space:pre-line"><?= e($a['deskripsi']) ?></p><?php endif; ?>
          <div class="actions">
            <?= badge(['semua' => 'Semua pegawai', 'manajemen' => 'Manajemen', 'staff' => 'Staff'][$a['untuk']], $a['untuk'] === 'semua' ? 'g' : 'b') ?>
            <small>oleh <?= e($a['nama']) ?></small>
            <?php if ($boss): ?><form method="post" class="inline"><?= csrf() ?><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="link-btn" name="aksi" value="hapus" data-confirm="Hapus agenda ini?">Hapus</button></form><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-h">
        <a class="btn sm ghost" href="?tab=<?= $tab ?>&bln=<?= date('Y-m', strtotime('-1 month', $awal)) ?>">‹</a>
        <h3><?= $namaBln[(int) date('n', $awal)] . ' ' . date('Y', $awal) ?></h3>
        <a class="btn sm ghost" href="?tab=<?= $tab ?>&bln=<?= date('Y-m', strtotime('+1 month', $awal)) ?>">›</a>
      </div>
      <div class="cal">
        <?php foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $h) echo "<b>$h</b>";
        $pad = ((int) date('N', $awal)) - 1;
        for ($i = 0; $i < $pad; $i++) echo '<span></span>';
        for ($d = 1; $d <= (int) date('t', $awal); $d++) {
            $cls = (date('Y-m', $awal) . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT) === date('Y-m-d') ? 'today ' : '') . (isset($hariAda[$d]) ? 'ada' : '');
            echo '<span class="' . $cls . '"' . (isset($hariAda[$d]) ? ' title="' . $hariAda[$d] . ' agenda"' : '') . '>' . $d . '</span>';
        } ?>
      </div>
      <p class="hint" style="margin-top:10px">🟠 titik oranye = ada agenda pada hari itu</p>
    </div>

    <?php if ($boss): ?>
    <div class="card">
      <div class="card-h"><h3>➕ Jadwalkan Agenda</h3></div>
      <form method="post">
        <?= csrf() ?><input type="hidden" name="aksi" value="buat">
        <label>Nama kegiatan</label><input name="judul" required maxlength="120" placeholder="mis. Rapat evaluasi mingguan">
        <label>Tanggal</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>
        <div class="row">
          <div><label>Jam mulai</label><input type="time" name="jam_mulai" value="09:00" required></div>
          <div><label>Jam selesai</label><input type="time" name="jam_selesai" value="10:00"></div>
        </div>
        <label>Lokasi</label><input name="lokasi" placeholder="mis. Ruang rapat lt. 2">
        <label>Peserta</label>
        <select name="untuk"><option value="semua">Semua pegawai</option><option value="manajemen">Manajemen saja</option><option value="staff">Staff</option></select>
        <label>Keterangan</label><textarea name="deskripsi" rows="3"></textarea>
        <div class="form-actions"><button type="submit">📅 Simpan Agenda</button></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_end();

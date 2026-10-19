<?php
require __DIR__ . '/../includes/app.php';
need();
$me = uid();

if (is_post()) {
    $ke = (int) post('ke');
    $balas = (int) post('balas') ?: null;
    if (!val('SELECT COUNT(*) FROM pegawai WHERE id=? AND aktif=1 AND id!=?', [$ke, $me])) flash('err', 'Pilih penerima yang valid.');
    elseif (post('subjek') === '' || post('isi') === '') flash('err', 'Subjek dan isi pesan wajib diisi.');
    else {
        if ($balas && !val('SELECT COUNT(*) FROM pesan WHERE id=? AND (id_pengirim=? OR id_penerima=?)', [$balas, $me, $me])) $balas = null;
        q('INSERT INTO pesan(id_pengirim,id_penerima,subjek,isi,id_balasan) VALUES(?,?,?,?,?)', [$me, $ke, post('subjek'), post('isi'), $balas]);
        flash('ok', 'Pesan terkirim ke <b>' . e(val('SELECT nama FROM pegawai WHERE id=?', [$ke])) . '</b>. ✉️');
        redirect('oas/pesan.php?tab=terkirim');
    }
    $_SESSION['old_pesan'] = $_POST;
    redirect('oas/pesan.php?tab=tulis');
}

$tab = pilih(get('tab'), ['masuk', 'terkirim', 'tulis'], 'masuk');
$lihat = null;
if (get('id')) {
    $lihat = row('SELECT m.*,a.nama dari,a.jabatan dari_jab,b.nama ke FROM pesan m JOIN pegawai a ON a.id=m.id_pengirim JOIN pegawai b ON b.id=m.id_penerima
                  WHERE m.id=? AND (m.id_pengirim=? OR m.id_penerima=?)', [(int) get('id'), $me, $me]);
    if (!$lihat) { flash('err', 'Pesan tidak ditemukan.'); redirect('oas/pesan.php'); }
    if ((int) $lihat['id_penerima'] === $me && !$lihat['dibaca']) q('UPDATE pesan SET dibaca=1 WHERE id=?', [(int) $lihat['id']]);
    $asal = $lihat['id_balasan'] ? row('SELECT m.*,a.nama dari FROM pesan m JOIN pegawai a ON a.id=m.id_pengirim WHERE m.id=?', [(int) $lihat['id_balasan']]) : null;
    $tab = (int) $lihat['id_pengirim'] === $me ? 'terkirim' : 'masuk';
}

$unread = (int) val('SELECT COUNT(*) FROM pesan WHERE id_penerima=? AND dibaca=0', [$me]);
$list = $tab === 'masuk'
    ? rows('SELECT m.*,p.nama orang FROM pesan m JOIN pegawai p ON p.id=m.id_pengirim WHERE m.id_penerima=? ORDER BY m.dibuat DESC LIMIT 50', [$me])
    : ($tab === 'terkirim' ? rows('SELECT m.*,p.nama orang FROM pesan m JOIN pegawai p ON p.id=m.id_penerima WHERE m.id_pengirim=? ORDER BY m.dibuat DESC LIMIT 50', [$me]) : []);

// data form tulis / balas
$old = $_SESSION['old_pesan'] ?? []; unset($_SESSION['old_pesan']);
$pre = ['ke' => (int) ($old['ke'] ?? get('ke')), 'subjek' => $old['subjek'] ?? '', 'isi' => $old['isi'] ?? '', 'balas' => (int) ($old['balas'] ?? 0)];
if ($tab === 'tulis' && get('balas')) {
    $b = row('SELECT * FROM pesan WHERE id=? AND (id_pengirim=? OR id_penerima=?)', [(int) get('balas'), $me, $me]);
    if ($b) $pre = ['ke' => (int) ($b['id_pengirim'] == $me ? $b['id_penerima'] : $b['id_pengirim']), 'subjek' => (str_starts_with($b['subjek'], 'Re: ') ? '' : 'Re: ') . $b['subjek'], 'isi' => '', 'balas' => (int) $b['id']];
}
$penerima = rows('SELECT id,nama,role,jabatan FROM pegawai WHERE aktif=1 AND id!=? ORDER BY role,nama', [$me]);

page_start('Pesan Internal', 'pesan', 'Komunikasi antar pegawai tanpa keluar dari MartOffice.');
?>
<div class="grid g-form">
  <div class="card tight">
    <div class="tabs" style="padding:0 16px;margin-bottom:0">
      <a class="<?= $tab === 'masuk' ? 'on' : '' ?>" href="?tab=masuk">📥 Masuk<?= $unread ? '<span class="count">' . $unread . '</span>' : '' ?></a>
      <a class="<?= $tab === 'terkirim' ? 'on' : '' ?>" href="?tab=terkirim">📤 Terkirim</a>
      <a class="<?= $tab === 'tulis' ? 'on' : '' ?>" href="?tab=tulis">✏️ Tulis</a>
    </div>
    <?php if ($tab === 'tulis'): ?>
      <div style="padding:14px 18px"><?= empty_state('✍️', 'Menulis pesan baru', 'Isi formulir di samping lalu tekan Kirim.') ?></div>
    <?php elseif (!$list): ?>
      <div style="padding:14px 18px"><?= empty_state($tab === 'masuk' ? '📭' : '📤', $tab === 'masuk' ? 'Kotak masuk kosong' : 'Belum ada pesan terkirim') ?></div>
    <?php else: foreach ($list as $m): $baru = $tab === 'masuk' && !$m['dibaca']; ?>
      <a class="msg-row <?= $baru ? 'unread' : '' ?>" href="?id=<?= $m['id'] ?>" style="<?= $lihat && $lihat['id'] == $m['id'] ? 'background:var(--gl)' : '' ?>">
        <span class="avatar"><?= e(inisial($m['orang'])) ?></span>
        <div class="li-body">
          <b><?= $tab === 'terkirim' ? 'Ke: ' : '' ?><?= e($m['orang']) ?></b>
          <small style="color:var(--ink)"><?= e($m['subjek']) ?></small>
          <small><?= e(mb_strimwidth($m['isi'], 0, 60, '…')) ?></small>
        </div>
        <div class="right"><small class="nowrap"><?= lalu($m['dibuat']) ?></small><?php if ($baru): ?><br><span class="dot" style="display:inline-block"></span><?php endif; ?></div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <?php if ($tab === 'tulis'): ?>
      <div class="card-h"><h3><?= $pre['balas'] ? '↩️ Balas Pesan' : '✏️ Pesan Baru' ?></h3></div>
      <?php if (!$penerima): echo empty_state('👥', 'Belum ada pegawai lain yang aktif'); else: ?>
      <form method="post">
        <?= csrf() ?><input type="hidden" name="balas" value="<?= $pre['balas'] ?>">
        <label>Kepada</label>
        <select name="ke" required><option value="">— pilih penerima —</option>
          <?php foreach ($penerima as $x) echo '<option value="' . $x['id'] . '"' . ($pre['ke'] === (int) $x['id'] ? ' selected' : '') . '>' . e($x['nama']) . ' (' . ucfirst($x['role']) . ($x['jabatan'] ? ' · ' . e($x['jabatan']) : '') . ')</option>'; ?>
        </select>
        <label>Subjek</label><input name="subjek" value="<?= e($pre['subjek']) ?>" required maxlength="150">
        <label>Isi pesan</label><textarea name="isi" rows="8" required><?= e($pre['isi']) ?></textarea>
        <div class="form-actions"><button type="submit">📨 Kirim Pesan</button><a class="btn ghost" href="?tab=masuk">Batal</a></div>
      </form>
      <?php endif; ?>
    <?php elseif ($lihat): ?>
      <div class="card-h"><h3><?= e($lihat['subjek']) ?></h3><a class="btn sm y" href="?tab=tulis&balas=<?= $lihat['id'] ?>">↩️ Balas</a></div>
      <div class="actions" style="margin-bottom:14px">
        <span class="avatar"><?= e(inisial($lihat['dari'])) ?></span>
        <div><b><?= e($lihat['dari']) ?></b> <small>→ <?= e($lihat['ke']) ?></small><br><small><?= tgl($lihat['dibuat']) ?><?= (int) $lihat['id_pengirim'] === $me ? ' · ' . ($lihat['dibaca'] ? '✓✓ sudah dibaca' : '✓ terkirim') : '' ?></small></div>
      </div>
      <div class="msg-body"><?= e($lihat['isi']) ?></div>
      <?php if (!empty($asal)): ?>
        <p class="hint" style="margin-top:14px">Membalas pesan dari <?= e($asal['dari']) ?> (<?= tgl($asal['dibuat']) ?>):</p>
        <div class="msg-body" style="background:#f8fafc;border-left:4px solid var(--line);font-size:13px"><b><?= e($asal['subjek']) ?></b><br><?= e($asal['isi']) ?></div>
      <?php endif; ?>
    <?php else: ?>
      <?= empty_state('✉️', 'Pilih pesan untuk dibaca', 'Atau <a href="?tab=tulis">tulis pesan baru</a> ke rekan kerja.') ?>
    <?php endif; ?>
  </div>
</div>
<?php page_end();

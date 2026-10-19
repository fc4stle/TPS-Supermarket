<?php
require __DIR__ . '/includes/app.php';
need();
$r = role();
$id = uid();
$manajer = $r === 'manajer';

/* ---- angka ringkas setiap modul (langsung dari database) ---- */
$tps = [
    'omzet'   => (float) val('SELECT COALESCE(SUM(total),0) FROM transaksi WHERE DATE(waktu)=CURDATE()'),
    'trx'     => (int) val('SELECT COUNT(*) FROM transaksi WHERE DATE(waktu)=CURDATE()'),
    'menipis' => (int) val('SELECT COUNT(*) FROM produk WHERE aktif=1 AND stok<?', [STOK_MIN]),
];
$oas = [
    'tugas'  => $r === 'staff' ? (int) val("SELECT COUNT(*) FROM tugas WHERE id_staff=? AND status!='selesai'", [$id]) : (int) val("SELECT COUNT(*) FROM tugas WHERE status!='selesai'"),
    'pesan'  => (int) val('SELECT COUNT(*) FROM pesan WHERE id_penerima=? AND dibaca=0', [$id]),
    'agenda' => (int) val("SELECT COUNT(*) FROM agenda WHERE mulai>=NOW()" . ($r === 'staff' ? " AND untuk IN ('semua','staff')" : '')),
];
$kms = [
    'pub'    => (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='published'" . (is_boss() ? '' : " AND visibilitas='internal'")),
    'review' => (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='menunggu_review'"),
    'saya'   => (int) val('SELECT COUNT(*) FROM artikel_kms WHERE id_penulis=?', [$id]),
];
$nAlert = $manajer ? count(mis_alerts()) : 0;
$bolehDss = is_boss();
$dss = $bolehDss ? dss_ringkasan() : null;
$dssAngka = [];
if ($dss) {
    $t = $dss['terakhir'];
    $dssAngka = [['Supplier aktif', (string) $dss['supplier_aktif']], ['Keputusan tersimpan', (string) $dss['keputusan']],
                 ['Keputusan terakhir', $t ? e($t['pemenang_nama']) . ' <small>(' . e($t['kategori']) . ')</small>' : '–']];
}
$omzet7 = $manajer ? (float) val('SELECT COALESCE(SUM(total),0) FROM transaksi WHERE DATE(waktu)>=CURDATE()-INTERVAL 6 DAY') : 0;

$modul = [
    ['TPS', 'Transaksi & Stok', '🧾', 'g', 'Kasir, nota, riwayat transaksi, stok produk, dan laporan penjualan.',
        [['Omzet hari ini', ringkas($tps['omzet'])], ['Transaksi hari ini', angka($tps['trx'])], ['Stok menipis', $tps['menipis'] . ' produk']],
        $r === 'manajer' ? 'tps/laporan.php' : 'tps/kasir.php', true, ''],
    ['OAS', 'Administrasi Kantor', '📋', 'b', 'Tugas, pengumuman, agenda, dokumen, dan pesan internal antarpegawai.',
        [[$r === 'staff' ? 'Tugas saya' : 'Tugas aktif', (string) $oas['tugas']], ['Pesan belum dibaca', (string) $oas['pesan']], ['Agenda mendatang', (string) $oas['agenda']]],
        'oas/tugas.php', true, ''],
    ['KMS', 'Knowledge Management', '📚', 'o', 'SOP, panduan produk, dan pengetahuan internal dengan review, versi, dan feedback.',
        [['Knowledge published', (string) $kms['pub']], [is_boss() ? 'Menunggu review' : 'Kontribusi saya', (string) (is_boss() ? $kms['review'] : $kms['saya'])], ['Kategori', (string) val('SELECT COUNT(*) FROM kategori_kms WHERE aktif=1')]],
        'kms/index.php', true, ''],
    ['MIS', 'Management Information', '📈', 'p', 'Dashboard kinerja perusahaan untuk manajer, dirangkum dari TPS, OAS, dan KMS.',
        $manajer ? [['Omzet 7 hari', ringkas($omzet7)], ['Peringatan aktif', (string) $nAlert], ['Halaman analitik', '11']] : [],
        'mis/index.php', $manajer, 'Khusus manajer'],
    ['DSS', 'Decision Support', '🧭', 't', 'Pemilihan supplier terbaik dengan metode SAW. Aplikasi Flask terpisah (port ' . DSS_PORT . '), masuk otomatis lewat SSO.',
        $dssAngka, 'sso_dss.php', $bolehDss, 'Manajer & admin'],
];

page_start('Beranda MartOffice', 'beranda', 'Sistem terpadu supermarket — pilih modul untuk mulai bekerja.');
echo hero('Lima modul, satu sistem 🛒', 'MartOffice menyatukan transaksi kasir (TPS), administrasi kantor (OAS), pusat pengetahuan (KMS), informasi manajemen (MIS), dan pendukung keputusan (DSS).', '🧾📋📚📈🧭');
?>
<div class="modules">
  <?php foreach ($modul as [$kode, $nama, $ic, $tone, $desk, $angka, $href, $boleh, $kunci]): ?>
    <section class="module <?= $tone ?> <?= $boleh ? '' : 'locked' ?>">
      <div class="module-top">
        <span class="module-ic"><?= $ic ?></span>
        <span class="badge <?= $boleh ? 'n' : 'r' ?>"><?= $boleh ? $kode : '🔒 ' . $kunci ?></span>
      </div>
      <h3><?= $kode ?> — <?= e($nama) ?></h3>
      <p><?= e($desk) ?></p>
      <?php if ($angka): ?>
        <dl class="module-stats"><?php foreach ($angka as [$l, $v]) echo '<div><dt>' . e($l) . '</dt><dd>' . $v . '</dd></div>'; ?></dl>
      <?php elseif ($kode === 'DSS' && $boleh): ?>
        <p class="module-note">💤 DSS sedang tidak aktif. Klik tombol di bawah — DSS akan <b>dinyalakan otomatis</b> (±5 detik), tanpa perlu membuka Command Prompt.</p>
      <?php else: ?>
        <p class="module-note">Modul ini hanya dapat dibuka oleh akun dengan role <b><?= $kode === 'DSS' ? 'manajer atau admin' : 'manajer' ?></b>.</p>
      <?php endif; ?>
      <?php if ($boleh): ?>
        <a class="btn block" href="<?= url($href) ?>"<?= $kode === 'DSS' ? ' onclick="this.textContent=\'⏳ Membuka DSS…\';this.style.pointerEvents=\'none\'"' : '' ?>><?= $kode === 'DSS' ? '🔐 Buka DSS (SSO) ↗' : 'Buka modul →' ?></a>
      <?php else: ?>
        <button class="block ghost" type="button" disabled data-keep-disabled>🔒 Terkunci</button>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-top:4px">
  <div class="card-h"><h3>🔗 Alur Integrasi Modul</h3></div>
  <div class="flow">
    <div><b>🧾 TPS</b><small>mencatat transaksi &amp; stok</small></div><span>→</span>
    <div><b>📋 OAS</b><small>mengatur tugas &amp; dokumen</small></div><span>→</span>
    <div><b>📚 KMS</b><small>menyimpan SOP &amp; pengetahuan</small></div><span>→</span>
    <div><b>📈 MIS</b><small>merangkum semuanya untuk manajer</small></div><span>→</span>
    <div class="hl"><b>🧭 DSS</b><small>membantu memilih supplier terbaik</small></div>
  </div>
  <p class="hint mb0" style="margin-top:12px">TPS, OAS, KMS, dan MIS memakai satu database MySQL. DSS adalah aplikasi Flask + SQLite terpisah di port <?= DSS_PORT ?> yang terhubung lewat <b>Single Sign-On</b> dan API bertanda tangan HMAC.</p>
</div>
<?php page_end();

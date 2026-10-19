<?php
require __DIR__ . '/../includes/app.php';
need('manajer');
mis_refresh();

$semua = mis_alerts();
$modul = pilih(get('modul'), ['TPS', 'OAS', 'KMS'], '');
$list = $modul ? array_values(array_filter($semua, fn($a) => $a['modul'] === $modul)) : $semua;
$hit = fn($arr, $k, $v) => count(array_filter($arr, fn($a) => $a[$k] === $v));
$level = ['kritis' => ['⛔ Kritis', 'Perlu ditangani hari ini'], 'perhatian' => ['⚠️ Perhatian', 'Perlu ditindaklanjuti minggu ini'], 'info' => ['ℹ️ Info', 'Untuk diketahui dan dipertimbangkan']];

mis_start('Pusat Peringatan', 'mis-peringatan', 'Hal-hal yang perlu perhatian manajer, dideteksi otomatis dari TPS, OAS, dan KMS.');
?>
<div class="kpis">
  <?= kpi('Total Peringatan', (string) count($semua), '🚨', '', 'kondisi saat ini', count($semua) ? 'o' : 'g') ?>
  <?= kpi('Kritis', (string) $hit($semua, 'level', 'kritis'), '⛔', '', 'tangani hari ini', $hit($semua, 'level', 'kritis') ? 'r' : 'g') ?>
  <?= kpi('Perhatian', (string) $hit($semua, 'level', 'perhatian'), '⚠️', '', 'tindak lanjuti minggu ini', 'y') ?>
  <?= kpi('Info', (string) $hit($semua, 'level', 'info'), 'ℹ️', '', 'untuk dipertimbangkan', 'b') ?>
</div>

<div class="card mis-filter no-print">
  <div class="chips">
    <a class="chip <?= $modul === '' ? 'on' : '' ?>" href="?">Semua modul (<?= count($semua) ?>)</a>
    <?php foreach (['TPS' => '🧾', 'OAS' => '📋', 'KMS' => '📚'] as $m => $ic) echo '<a class="chip ' . ($modul === $m ? 'on' : '') . '" href="?modul=' . $m . '">' . $ic . ' ' . $m . ' (' . $hit($semua, 'modul', $m) . ')</a>'; ?>
  </div><span class="grow"></span>
  <button type="button" class="sm ghost" onclick="window.print()">🖨️ Cetak</button>
</div>

<?php if (!$list): ?>
  <div class="card"><?= empty_state('✅', 'Tidak ada peringatan' . ($modul ? ' untuk modul ' . $modul : ''), 'Stok, tugas, dan knowledge dalam kondisi aman.') ?></div>
<?php else: foreach ($level as $lv => [$judul, $ket]):
    $bag = array_values(array_filter($list, fn($a) => $a['level'] === $lv));
    if (!$bag) continue; ?>
  <section class="card panel">
    <div class="card-h"><h3><?= $judul ?> <?= badge((string) count($bag), ['kritis' => 'r', 'perhatian' => 'y', 'info' => 'b'][$lv]) ?></h3><small><?= $ket ?></small></div>
    <?php foreach ($bag as $a) echo alert_item($a); ?>
  </section>
<?php endforeach; endif; ?>

<section class="card panel"><div class="card-h"><h3>📐 Aturan Peringatan</h3></div>
  <?= tabel(['Modul', 'Kondisi yang dipantau', 'Tingkat', 'Sumber data'], [
      [badge('TPS', 'n'), 'Stok produk aktif = 0', 'Kritis', '<code>produk.stok</code>'],
      [badge('TPS', 'n'), 'Stok di bawah ' . STOK_MIN . ' unit', 'Perhatian', '<code>produk.stok</code>'],
      [badge('TPS', 'n'), 'Omzet 7 hari turun lebih dari 10% dari 7 hari sebelumnya', 'Perhatian', '<code>transaksi.total</code>'],
      [badge('TPS', 'n'), 'Produk berstok tidak terjual dalam 14 hari', 'Info', '<code>detail_transaksi</code>'],
      [badge('OAS', 'n'), 'Tugas melewati tenggat (lebih dari 3 hari = kritis)', 'Kritis / Perhatian', '<code>tugas.tenggat</code>, <code>tugas.status</code>'],
      [badge('OAS', 'n'), 'Satu staff memegang 4 tugas aktif atau lebih', 'Perhatian', '<code>tugas.id_staff</code>'],
      [badge('OAS', 'n'), 'Pesan belum dibaca lebih dari 3 hari', 'Info', '<code>pesan.dibaca</code>'],
      [badge('KMS', 'n'), 'Knowledge menunggu review lebih dari 2 hari', 'Perhatian', '<code>artikel_kms.status</code>'],
      [badge('KMS', 'n'), 'Helpful rate di bawah 50% (minimal 2 feedback)', 'Perhatian', '<code>feedback_kms.membantu</code>'],
      [badge('KMS', 'n'), 'Knowledge published belum pernah dibaca / kategori kosong', 'Info', '<code>artikel_kms.dilihat</code>'],
  ]) ?>
</section>
<?php mis_end();

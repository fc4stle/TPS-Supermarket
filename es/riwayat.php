<?php
/* Riwayat konsultasi ES dan permintaan pembeli yang belum terpenuhi. */
require __DIR__ . '/../includes/app.php';
require __DIR__ . '/_es.php';
need();
es_wajib_tersedia('Riwayat Konsultasi', 'es-riwayat');

$pembeli = is_pembeli();
page_start($pembeli ? 'Riwayat Saya' : 'Riwayat Konsultasi', 'es-riwayat', $pembeli
    ? 'Konsultasi yang pernah Anda lakukan beserta produk yang disarankan.'
    : 'Expert System: semua konsultasi pembeli yang tersimpan, jawaban, dan rekomendasi yang diberikan.');
echo es_app_open('riwayat', ['boss' => role() === 'manajer' ? 1 : 0, 'pembeli' => $pembeli ? 1 : 0]);
?>
<?php if (role() === 'manajer'): ?>
<div class="card">
  <div class="card-h"><h3>📭 Permintaan pembeli yang belum terpenuhi</h3><small>Produk dicari lewat ES tetapi stoknya habis</small></div>
  <div id="es-minta"><div class="es-load">Memuat…</div></div>
</div>
<?php endif; ?>
<div class="grid es-rgrid">
  <div class="card tight">
    <div class="card-h"><h3>🕘 Daftar konsultasi</h3><a class="btn sm" href="<?= url('es/konsultasi.php') ?>">➕ Konsultasi baru</a></div>
    <div id="es-list" style="padding:14px 0 0"><div class="es-load">Memuat…</div></div>
  </div>
  <div class="card">
    <div id="es-detail"><div class="es-load">Pilih konsultasi untuk melihat detail.</div></div>
  </div>
</div>
<?php
echo es_app_close();
page_end();

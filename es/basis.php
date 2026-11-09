<?php
/* Basis pengetahuan ES: pertanyaan, fakta, aturan, kesimpulan, dan parameter engine. */
require __DIR__ . '/../includes/app.php';
require __DIR__ . '/_es.php';
need();
es_wajib_tersedia('Basis Pengetahuan', 'es-basis');

page_start('Basis Pengetahuan', 'es-basis', 'Expert System: isi pengetahuan pakar yang dipakai engine. Setiap aturan merujuk ke artikel KMS.');
echo es_app_open('basis');
?>
<div class="tabs" id="es-btabs">
  <a href="?menu=REKOMENDASI" data-menu="REKOMENDASI" class="on">🛒 Rekomendasi Produk (pembeli)</a>
  <a href="?menu=ORDER" data-menu="ORDER">🗓️ Waktu Order (manajer)</a>
</div>
<div id="es-basis"><div class="es-load">Memuat…</div></div>
<?php
echo es_app_close();
page_end();

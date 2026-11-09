<?php
/* ES Menu 1 · Konsultasi produk untuk pembeli (diisi staf toko sambil melayani pembeli). */
require __DIR__ . '/../includes/app.php';
require __DIR__ . '/_es.php';
need();
es_wajib_tersedia('Konsultasi Produk', 'es-konsultasi');

page_start('Konsultasi Produk', 'es-konsultasi', 'Expert System: tanyakan kebutuhan pembeli, sistem menyarankan produk yang paling tepat beserta alasannya.');
echo es_app_open('konsultasi', ['cf-min' => es_set()['cf_min'] ?? 0.4]);
?>
<div id="es-form" class="grid g-side">
  <div class="card">
    <div id="es-msg"></div>
    <div id="es-wiz" class="es-wiz"><div class="es-load">Memuat pertanyaan dari basis pengetahuan…</div></div>
  </div>
  <div>
    <div class="card">
      <div class="card-h"><h3>📥 Fakta terkumpul</h3></div>
      <div id="es-fakta" class="es-trace"></div>
    </div>
    <div class="card">
      <div class="card-h"><h3>⚙️ Cara kerja</h3></div>
      <ol class="es-how">
        <li><span><b>Pertanyaan</b>Staf menanyakan 5 hal kepada pembeli.</span></li>
        <li><span><b>Jawaban → fakta</b>Setiap jawaban menjadi fakta (F01–F19) dengan tingkat keyakinan.</span></li>
        <li><span><b>Engine</b>Forward chaining mencocokkan fakta dengan aturan JIKA–MAKA dari KMS, lalu menghitung Certainty Factor tiap produk TPS.</span></li>
        <li><span><b>Rekomendasi</b>Produk berstok diurutkan dari yang paling tepat. Produk yang cocok tetapi habis dicatat sebagai permintaan untuk gudang (OAS).</span></li>
      </ol>
    </div>
  </div>
</div>
<div id="es-hasil" class="es-hide"></div>
<?php
echo es_app_close();
page_end();

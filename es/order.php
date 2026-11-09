<?php
/* ES Menu 2 · Keputusan waktu order barang untuk manajer. */
require __DIR__ . '/../includes/app.php';
require __DIR__ . '/_es.php';
need('manajer');
es_wajib_tersedia('Keputusan Waktu Order', 'es-order');

page_start('Keputusan Waktu Order', 'es-order', 'Expert System: kapan waktu paling tepat order barang ke supplier, dihitung dari data TPS, OAS, KMS, MIS, dan DSS.');
echo es_app_open('order');
?>
<div class="card">
  <div class="es-tools mb0" style="margin-bottom:0">
    <span id="es-oinfo" class="muted">Memuat…</span>
    <span class="sp"></span>
    <button type="button" class="ghost" id="es-osinkron" title="Ambil supplier terpilih, lead time, promo, dan bundling dari DSS">🔄 Sinkron DSS</button>
    <button type="button" id="es-ojalan">⚙️ Jalankan analisis</button>
  </div>
</div>
<div id="es-omsg"></div>
<div id="es-okes" class="es-kes"></div>
<div class="card">
  <div class="es-tools">
    <input type="search" id="es-ocari" placeholder="Cari produk, kategori, atau SKU…" aria-label="Cari produk">
    <span class="sp"></span>
    <button type="button" class="o" id="es-otugas" disabled>📋 Pilih produk untuk ditugaskan</button>
  </div>
  <p class="hint" style="margin:-4px 0 12px">Klik baris produk untuk melihat fakta, aturan, dan perhitungan engine. Centang produk (keputusan K01–K03) untuk membuat tugas order di OAS.</p>
  <div id="es-otbl"><div class="es-load">Memuat hasil analisis…</div></div>
</div>
<?php
echo es_app_close();
page_end();

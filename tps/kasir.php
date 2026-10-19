<?php
require __DIR__ . '/../includes/app.php';
need('staff', 'admin');

if (is_post()) {
    $req = [];
    foreach ((array) ($_POST['q'] ?? []) as $pid => $n) {
        $pid = (int) $pid; $n = (int) $n;
        if ($pid > 0 && $n > 0) $req[$pid] = min($n, 9999);
    }
    $metode = pilih(post('metode'), ['tunai', 'debit', 'qris'], 'tunai');
    $bayar  = (float) post('bayar', '0');

    if (!$req) {
        flash('err', 'Keranjang masih kosong. Pilih minimal satu produk.');
        redirect('tps/kasir.php');
    }

    $db = db();
    $db->begin_transaction();
    try {
        $items = []; $total = 0; $kurang = [];
        foreach ($req as $pid => $n) {
            $p = row('SELECT * FROM produk WHERE id=? AND aktif=1 FOR UPDATE', [$pid]);
            if (!$p) { $kurang[] = 'Produk #' . $pid . ' tidak tersedia'; continue; }
            if ($p['stok'] < $n) { $kurang[] = e($p['nama']) . ' (sisa ' . $p['stok'] . ')'; continue; }
            $items[] = [$p, $n];
            $total += $p['harga'] * $n;
        }
        if ($kurang) throw new RuntimeException('Stok tidak mencukupi: ' . implode(', ', $kurang) . '.');
        if ($metode !== 'tunai') $bayar = $total;              // non-tunai selalu pas
        if ($bayar < $total) throw new RuntimeException('Uang dibayar (' . rp($bayar) . ') kurang dari total belanja (' . rp($total) . ').');

        $kembali = $bayar - $total;
        q('INSERT INTO transaksi(id_kasir,metode,total,bayar,kembali) VALUES(?,?,?,?,?)', [uid(), $metode, (float) $total, (float) $bayar, (float) $kembali]);
        $tid  = last_id();
        $nota = 'TRX-' . date('Ymd') . '-' . str_pad((string) $tid, 5, '0', STR_PAD_LEFT);
        q('UPDATE transaksi SET nota=? WHERE id=?', [$nota, $tid]);

        foreach ($items as [$p, $n]) {
            q('INSERT INTO detail_transaksi(id_transaksi,id_produk,nama_produk,qty,harga,subtotal) VALUES(?,?,?,?,?,?)',
                [$tid, (int) $p['id'], $p['nama'], $n, (float) $p['harga'], (float) ($p['harga'] * $n)]);
            q('UPDATE produk SET stok=stok-? WHERE id=?', [$n, (int) $p['id']]);
            catat_stok((int) $p['id'], -$n, 'penjualan', $nota);
        }
        $db->commit();
        unset($_SESSION['cart_draft']);
        flash('ok', 'Transaksi <b>' . $nota . '</b> berhasil disimpan. Kembalian: <b>' . rp($kembali) . '</b>');
        redirect('tps/nota.php?id=' . $tid);
    } catch (Throwable $ex) {
        $db->rollback();
        $_SESSION['cart_draft'] = $req;
        flash('err', $ex instanceof RuntimeException ? $ex->getMessage() : 'Transaksi gagal disimpan: ' . e($ex->getMessage()));
        redirect('tps/kasir.php');
    }
}

$produk = rows('SELECT id,sku,nama,ikon,kategori,satuan,harga,stok FROM produk WHERE aktif=1 ORDER BY kategori,nama');
$kategori = array_values(array_unique(array_column($produk, 'kategori')));
$draft = $_SESSION['cart_draft'] ?? [];
unset($_SESSION['cart_draft']);

page_start('Kasir', 'kasir', 'Klik produk di rak untuk menambah ke keranjang. Total dan kembalian dihitung otomatis.');
?>
<div class="pos">
  <div>
    <div class="pos-tools">
      <input id="cari" type="search" placeholder="🔍 Cari nama produk atau ketik SKU lalu Enter…" autofocus>
    </div>
    <div class="chips" style="margin-bottom:14px" id="kat">
      <button type="button" class="chip on" data-k="">🛒 Semua</button>
      <?php foreach ($kategori as $k): ?><button type="button" class="chip" data-k="<?= e($k) ?>"><?= e($k) ?></button><?php endforeach; ?>
    </div>
    <div class="shelf">
      <?php if (!$produk): echo empty_state('📦', 'Belum ada produk aktif', 'Admin perlu menambahkan produk di menu Produk &amp; Stok.'); else: ?>
      <div class="shelf-grid" id="rak">
        <?php foreach ($produk as $p): ?>
          <div class="prod <?= $p['stok'] < 1 ? 'habis' : '' ?>" data-id="<?= $p['id'] ?>" data-k="<?= e($p['kategori']) ?>" data-s="<?= e(mb_strtolower($p['nama'] . ' ' . $p['sku'])) ?>" tabindex="0">
            <span class="price-tag"><?= e($p['sku']) ?></span>
            <span class="in-cart">0</span>
            <div class="em"><?= e($p['ikon']) ?></div>
            <b><?= e($p['nama']) ?></b>
            <span class="sk <?= $p['stok'] < STOK_MIN ? 'low' : '' ?>"><?= $p['stok'] < 1 ? 'Habis' : 'Stok ' . $p['stok'] . ' ' . e($p['satuan']) ?></span>
            <div class="hg"><?= rp($p['harga']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div id="kosong" style="display:none"><?= empty_state('🔍', 'Produk tidak ditemukan', 'Coba kata kunci lain.') ?></div>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" class="cart" id="formBayar">
    <?= csrf() ?>
    <div class="cart-h"><h3>🧺 Keranjang</h3><button type="button" class="sm ghost" onclick="kosongkan()">Kosongkan</button></div>
    <div class="cart-items" id="items"></div>
    <div id="hidden"></div>
    <div class="cart-foot">
      <div class="sum"><span>Total belanja</span><big id="tot">Rp 0</big></div>
      <label>Metode bayar</label>
      <div class="pay-row">
        <label><input type="radio" name="metode" value="tunai" checked> 💵 Tunai</label>
        <label><input type="radio" name="metode" value="debit"> 💳 Debit</label>
        <label><input type="radio" name="metode" value="qris"> 📱 QRIS</label>
      </div>
      <div id="cashBox">
        <label for="bayar">Uang diterima (Rp)</label>
        <input type="number" name="bayar" id="bayar" min="0" step="100" placeholder="0">
        <div class="cash-btns">
          <button type="button" data-cash="pas">Uang pas</button>
          <button type="button" data-cash="20000">20rb</button>
          <button type="button" data-cash="50000">50rb</button>
          <button type="button" data-cash="100000">100rb</button>
        </div>
      </div>
      <div class="sum" style="margin-top:12px"><span>Kembalian</span><span class="kmb" id="kmb">Rp 0</span></div>
      <div class="form-actions"><button class="block y" id="btnBayar" type="submit" disabled>✅ Bayar &amp; Cetak Nota</button></div>
    </div>
  </form>
</div>

<script>
const PRODUK = <?= json_encode(array_column(array_map(fn($p) => ['id' => (int) $p['id'], 'nama' => $p['nama'], 'ikon' => $p['ikon'], 'harga' => (float) $p['harga'], 'stok' => (int) $p['stok'], 'sku' => mb_strtolower($p['sku'])], $produk), null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
let cart = <?= json_encode((object) array_map('intval', $draft)) ?>;
const rp = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');
const el = id => document.getElementById(id);
const esc = t => String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function tambah(id, d = 1) {
  const p = PRODUK[id]; if (!p) return;
  const n = Math.max(0, Math.min(p.stok, (cart[id] || 0) + d));
  if (n === 0) delete cart[id]; else cart[id] = n;
  render();
}
function setQty(id, v) {
  const p = PRODUK[id]; let n = parseInt(v) || 0;
  n = Math.max(0, Math.min(p.stok, n));
  if (n === 0) delete cart[id]; else cart[id] = n;
  render();
}
function kosongkan() { cart = {}; render(); }
function total() { return Object.entries(cart).reduce((s, [id, n]) => s + PRODUK[id].harga * n, 0); }
function metode() { return document.querySelector('input[name=metode]:checked').value; }

function render() {
  let html = '', hid = '';
  Object.entries(cart).forEach(([id, n]) => {
    const p = PRODUK[id]; if (!p) { delete cart[id]; return; }
    html += `<div class="ci"><span class="em">${esc(p.ikon)}</span><div class="ci-n"><b>${esc(p.nama)}</b><small>${rp(p.harga)} × ${n} = <b>${rp(p.harga * n)}</b></small></div>
      <div class="qty"><button type="button" onclick="tambah(${id},-1)">−</button><input type="number" value="${n}" min="0" max="${p.stok}" onchange="setQty(${id},this.value)"><button type="button" onclick="tambah(${id},1)">+</button></div></div>`;
    hid += `<input type="hidden" name="q[${id}]" value="${n}">`;
  });
  el('items').innerHTML = html || `<?= str_replace(["\n", '`'], '', empty_state('🧺', 'Keranjang kosong', 'Klik produk di rak untuk menambahkan.')) ?>`;
  el('hidden').innerHTML = hid;
  document.querySelectorAll('.prod').forEach(c => {
    const n = cart[c.dataset.id] || 0;
    c.classList.toggle('has', n > 0); c.querySelector('.in-cart').textContent = n;
  });
  const t = total(), tunai = metode() === 'tunai';
  el('cashBox').style.display = tunai ? '' : 'none';
  const bayar = tunai ? (+el('bayar').value || 0) : t;
  el('tot').textContent = rp(t);
  el('kmb').textContent = rp(Math.max(0, bayar - t));
  el('kmb').style.color = tunai && bayar > 0 && bayar < t ? 'var(--r)' : '';
  el('btnBayar').disabled = !(t > 0 && bayar >= t);
  el('btnBayar').textContent = t > 0 && tunai && bayar < t ? '💵 Masukkan uang diterima' : '✅ Bayar & Cetak Nota';
}

document.querySelectorAll('.prod').forEach(c => {
  c.addEventListener('click', () => tambah(c.dataset.id));
  c.addEventListener('keydown', e => { if (e.key === 'Enter') tambah(c.dataset.id); });
});
let katAktif = '';
function saring() {
  const s = el('cari').value.toLowerCase().trim(); let ada = 0;
  document.querySelectorAll('.prod').forEach(c => {
    const ok = (!katAktif || c.dataset.k === katAktif) && (!s || c.dataset.s.includes(s));
    c.style.display = ok ? '' : 'none'; if (ok) ada++;
  });
  el('kosong').style.display = ada ? 'none' : '';
}
el('cari').addEventListener('input', saring);
el('cari').addEventListener('keydown', e => {
  if (e.key !== 'Enter') return; e.preventDefault();
  const s = el('cari').value.toLowerCase().trim();
  const hit = Object.values(PRODUK).find(p => p.sku === s) || [...document.querySelectorAll('.prod')].filter(c => c.style.display !== 'none').map(c => PRODUK[c.dataset.id])[0];
  if (hit) { tambah(hit.id); el('cari').value = ''; saring(); }
});
document.querySelectorAll('#kat .chip').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('#kat .chip').forEach(x => x.classList.remove('on'));
  b.classList.add('on'); katAktif = b.dataset.k; saring();
}));
document.querySelectorAll('input[name=metode]').forEach(r => r.addEventListener('change', render));
el('bayar').addEventListener('input', render);
document.querySelectorAll('[data-cash]').forEach(b => b.addEventListener('click', () => {
  const v = b.dataset.cash === 'pas' ? total() : (+el('bayar').value || 0) + (+b.dataset.cash);
  el('bayar').value = Math.round(v); render();
}));
el('formBayar').addEventListener('submit', e => {
  if (!Object.keys(cart).length) { e.preventDefault(); return; }
  e.target.dataset.confirm = 'Simpan transaksi sebesar ' + rp(total()) + ' (' + metode().toUpperCase() + ')?';
});
window.addEventListener('pageshow', render);
render();
</script>
<?php page_end();

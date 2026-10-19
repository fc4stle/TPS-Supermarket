/* DSS MartOffice — interaksi halaman */
document.addEventListener('submit', function (ev) {
  var f = ev.target, msg = f.dataset.confirm;
  if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
  if (!ev.defaultPrevented) setTimeout(function () {
    f.querySelectorAll('button:not([type=button])').forEach(function (b) { b.disabled = true; });
  }, 0);
});
window.addEventListener('pageshow', function () {
  document.querySelectorAll('button[disabled]').forEach(function (b) { b.disabled = false; });
});
setTimeout(function () {
  document.querySelectorAll('.alert.ok').forEach(function (a) {
    a.style.transition = 'opacity .4s'; a.style.opacity = '0'; setTimeout(function () { a.remove(); }, 400);
  });
}, 6000);

function fmt(n, d) { return Number(n).toLocaleString('id-ID', { minimumFractionDigits: d, maximumFractionDigits: d }); }

/* Halaman Kriteria: total bobot langsung terhitung saat diketik */
function dssBobot() {
  var ins = document.querySelectorAll('.bobot-in');
  if (!ins.length) return;
  var colors = document.querySelectorAll('#wbar i');
  function upd() {
    var tot = 0;
    ins.forEach(function (i) { tot += parseFloat(i.value) || 0; });
    var ok = tot > 0;
    document.getElementById('totalCell').textContent = fmt(tot, tot % 1 ? 2 : 0);
    var b = document.getElementById('totalBadge');
    b.textContent = 'Total bobot ' + fmt(tot, tot % 1 ? 2 : 0); b.className = 'badge ' + (ok ? 'g' : 'r');
    document.getElementById('bobotInfo').textContent = ok ? 'Bobot dinormalisasi otomatis: w = bobot / ' + fmt(tot, tot % 1 ? 2 : 0) : 'Total bobot harus lebih dari 0';
    document.getElementById('btnBobot').disabled = !ok;
    ins.forEach(function (i, k) {
      var v = parseFloat(i.value) || 0;
      var w = document.querySelector('[data-w="' + i.name.split('_')[1] + '"]');
      if (w) w.textContent = fmt(tot ? v / tot : 0, 2);
      if (colors[k]) colors[k].style.flex = v;
    });
  }
  ins.forEach(function (i) { i.addEventListener('input', upd); });
  document.querySelectorAll('.jenis-in').forEach(function (s) {
    s.addEventListener('change', function () { s.className = 'jenis-in ' + s.value; });
  });
  upd();
}

/* Halaman Sensitivitas: SAW dihitung ulang di browser setiap slider digeser */
function dssSimulasi(D) {
  var K = D.kriteria, A = D.alternatif, asli = {}, bobot = {};
  K.forEach(function (k) { asli[k.id] = +k.bobot; bobot[k.id] = +k.bobot; });
  var box = document.getElementById('sliders');
  K.forEach(function (k) {
    var row = document.createElement('div');
    row.className = 'slider';
    row.innerHTML = '<label><code>' + k.kode + '</code> ' + k.nama + ' <span class="badge ' + (k.jenis === 'cost' ? 'o' : '') + '">' + k.jenis + '</span></label>' +
      '<input type="range" min="0" max="100" step="1" value="' + k.bobot + '" data-k="' + k.id + '"><b data-v="' + k.id + '"></b>';
    box.appendChild(row);
  });
  function saw(bb) {
    var tot = 0; K.forEach(function (k) { tot += bb[k.id]; });
    var pemb = {};
    K.forEach(function (k) {
      var col = A.map(function (a) { return a.x[k.id]; });
      pemb[k.id] = k.jenis === 'benefit' ? Math.max.apply(null, col) : Math.min.apply(null, col);
    });
    return A.map(function (a) {
      var v = 0;
      K.forEach(function (k) {
        var x = a.x[k.id], r = k.jenis === 'benefit' ? x / pemb[k.id] : pemb[k.id] / x;
        v += (tot ? bb[k.id] / tot : 0) * r;
      });
      return { a: a, v: v };
    }).sort(function (p, q) { return q.v - p.v || (p.a.kode < q.a.kode ? -1 : 1); });
  }
  var awal = saw(asli);
  function render() {
    var tot = 0;
    K.forEach(function (k) { tot += bobot[k.id]; document.querySelector('[data-v="' + k.id + '"]').textContent = bobot[k.id] + '%'; });
    document.getElementById('simTotal').textContent = tot + '%';
    var r = saw(bobot), max = r[0].v || 1, h = '';
    r.forEach(function (x, i) {
      var pos0 = awal.findIndex(function (y) { return y.a.id === x.a.id; }), geser = pos0 - i;
      h += '<div class="rk ' + (i === 0 ? 'top' : '') + '"><span class="rk-no">' + (['🥇', '🥈', '🥉'][i] || (i + 1)) + '</span><div class="rk-body">' +
        '<div class="rk-head"><b>' + x.a.nama + (geser ? ' <span class="move ' + (geser > 0 ? 'up' : 'down') + '">' + (geser > 0 ? '▲' : '▼') + Math.abs(geser) + '</span>' : '') + '</b><span class="rk-v">' + fmt(x.v, 4) + '</span></div>' +
        '<div class="rk-bar"><i style="width:' + (x.v / max * 100) + '%"></i></div></div></div>';
    });
    document.getElementById('simRank').innerHTML = h;
    var st = document.getElementById('simStatus'), sama = r[0].a.id === awal[0].a.id;
    st.textContent = sama ? 'Juara tetap: ' + r[0].a.nama : 'Juara berubah → ' + r[0].a.nama;
    st.className = 'badge ' + (sama ? 'g' : 'y');
  }
  box.addEventListener('input', function (e) {
    if (e.target.dataset.k) { bobot[e.target.dataset.k] = +e.target.value; render(); }
  });
  function set(bb) {
    K.forEach(function (k) { bobot[k.id] = Math.round(bb[k.id]); box.querySelector('[data-k="' + k.id + '"]').value = bobot[k.id]; });
    render();
  }
  document.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = b.dataset.preset, bb = {}, kunci = p === 'harga' ? /harga/i : /kualitas/i;
      if (p === 'reset') return set(asli);
      if (p === 'rata') { K.forEach(function (k, i) { bb[k.id] = Math.floor(100 / K.length) + (i < 100 % K.length ? 1 : 0); }); return set(bb); }
      var fokus = K.filter(function (k) { return kunci.test(k.nama); });
      if (!fokus.length) return;
      // kriteria fokus mendapat ±50%, sisanya dibagi rata; dibulatkan agar total tepat 100%
      var lain = K.length - fokus.length, tiapLain = lain ? Math.floor(50 / lain) : 0;
      var sisa = 100 - tiapLain * lain, tiapFokus = Math.floor(sisa / fokus.length);
      K.forEach(function (k) { bb[k.id] = fokus.indexOf(k) >= 0 ? tiapFokus : tiapLain; });
      bb[fokus[0].id] += sisa - tiapFokus * fokus.length;
      set(bb);
    });
  });
  render();
}

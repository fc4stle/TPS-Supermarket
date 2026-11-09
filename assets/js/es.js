/* ==========================================================
 *  FRONT END EXPERT SYSTEM (ES) MARTOFFICE
 *  Semua data diambil dari back end ES lewat api/es.php (JSON).
 *  Halaman: konsultasi · riwayat · order · basis
 * ========================================================== */
(function () {
  'use strict';
  var root = document.getElementById('es-app');
  if (!root) return;
  var API = root.dataset.api, TOKEN = root.dataset.token, PAGE = root.dataset.page;
  var CF_MIN = parseFloat(root.dataset.cfMin || '0.4');
  var BASE = root.dataset.base || '';

  /* ---------------- helper ---------------- */
  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(x) { return parseFloat(x) || 0; }
  function pct(x) { return (num(x) * 100).toFixed(1).replace('.', ',') + '%'; }
  function dec(x, n) { return x == null ? '–' : num(x).toFixed(n == null ? 1 : n).replace('.', ','); }
  function rp(n) { return 'Rp ' + Math.round(num(n)).toLocaleString('id-ID'); }
  var HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
  var BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  function tgl(d, jam) {
    if (!d) return '–';
    var t = new Date(String(d).replace(' ', 'T'));
    if (isNaN(t)) return esc(d);
    var s = HARI[t.getDay()] + ', ' + t.getDate() + ' ' + BULAN[t.getMonth()] + ' ' + t.getFullYear();
    return jam ? s + ' ' + String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0') : s;
  }
  function badge(t, c) { return '<span class="badge ' + (c || 'g') + '">' + esc(t) + '</span>'; }
  function empty(ic, judul, teks) {
    return '<div class="empty"><div class="empty-ic">' + ic + '</div><b>' + esc(judul) + '</b>' + (teks ? '<p>' + teks + '</p>' : '') + '</div>';
  }
  function alertBox(el, tipe, html) {
    el.innerHTML = '<div class="alert ' + tipe + '"><div>' + html + '</div></div>';
  }
  function api(aksi, params, body) {
    var q = new URLSearchParams(params || {});
    q.set('aksi', aksi);
    var opt = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body) {
      var fd = body instanceof FormData ? body : new FormData();
      if (!(body instanceof FormData)) Object.keys(body).forEach(function (k) {
        var v = body[k];
        if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
        else if (v && typeof v === 'object') Object.keys(v).forEach(function (kk) { fd.append(k + '[' + kk + ']', v[kk]); });
        else fd.append(k, v);
      });
      fd.append('_token', TOKEN);
      opt.method = 'POST';
      opt.body = fd;
    }
    return fetch(API + '?' + q.toString(), opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, pesan: 'Respons server tidak valid (HTTP ' + r.status + ')' }; })
        .then(function (d) { if (!d.ok) { var e = new Error(d.pesan || 'Permintaan gagal'); e.data = d; throw e; } return d; });
    });
  }
  function cfBar(x) {
    return '<div class="es-cf"><div><i style="width:' + Math.min(100, num(x) * 100).toFixed(1) + '%"></i></div><b>' + pct(x) + '</b></div>';
  }
  function stokBadge(stok, status) {
    if (num(stok) <= 0) return badge('Stok habis', 'r');
    return status === 'menipis' ? badge('Stok ' + stok + ' · menipis', 'o') : badge('Stok ' + stok, 'g');
  }
  var AKSI = { anjurkan: ['Anjurkan', 'g'], hindari: ['Hindari', 'r'], simpulkan: ['Simpulkan', 'b'] };

  /* ==========================================================
   *  1. KONSULTASI PRODUK (pembeli dilayani staf toko)
   * ========================================================== */
  function halamanKonsultasi() {
    var wiz = $('#es-wiz'), panel = $('#es-fakta'), hasil = $('#es-hasil'), form = $('#es-form');
    var Q = [], jawab = {}, step = 0;
    var YAKIN = [[1, 'Yakin'], [0.8, 'Cukup yakin'], [0.6, 'Kurang yakin']];

    api('pertanyaan', { menu: 'REKOMENDASI' }).then(function (d) {
      Q = d.pertanyaan;
      if (!Q.length) { wiz.innerHTML = empty('🧠', 'Belum ada pertanyaan', 'Tabel es_pertanyaan masih kosong.'); return; }
      render();
    }).catch(function (e) { alertBox(wiz, 'err', esc(e.message)); });

    function render() {
      var n = Q.length, steps = '';
      for (var i = 0; i <= n; i++) steps += '<i class="' + (i < step ? 'done' : i === step ? 'now' : '') + '"></i>';
      var h = '<div class="es-steps">' + steps + '</div>';
      if (step < n) {
        var q = Q[step], a = jawab[q.kode] || {};
        h += '<div><div class="es-qno">Pertanyaan ' + (step + 1) + ' dari ' + n + ' · ' + esc(q.kode) + (q.wajib ? '' : ' · boleh dilewati') + '</div>'
          + '<div class="es-q">' + esc(q.pertanyaan) + '</div>' + (q.bantuan ? '<small>' + esc(q.bantuan) + '</small>' : '') + '</div>'
          + '<div class="es-opts">' + q.pilihan.map(function (p) {
            return '<label class="es-opt"><input type="radio" name="opt" value="' + esc(p.kode_fakta) + '"' + (a.fakta === p.kode_fakta ? ' checked' : '')
              + '><span>' + esc(p.label) + '<small>Fakta ' + esc(p.kode_fakta) + '</small></span></label>';
          }).join('') + '</div>'
          + '<div class="es-yakin"><b>Seberapa yakin pembeli dengan jawaban ini?</b>' + YAKIN.map(function (y) {
            var on = (a.cf || 1) === y[0];
            return '<button type="button" class="chip' + (on ? ' on' : '') + '" data-cf="' + y[0] + '">' + y[1] + ' (' + String(y[0]).replace('.', ',') + ')</button>';
          }).join('') + '</div>'
          + '<div class="es-nav"><button type="button" class="ghost" id="es-back"' + (step ? '' : ' disabled') + '>← Kembali</button>'
          + '<button type="button" id="es-next"' + (a.fakta || !q.wajib ? '' : ' disabled') + '>' + (step === n - 1 ? 'Lihat ringkasan →' : 'Lanjut →') + '</button></div>';
      } else {
        h += '<div><div class="es-qno">Langkah terakhir</div><div class="es-q">Periksa jawaban, lalu cari rekomendasi</div></div>'
          + '<div class="es-sum">' + Q.map(function (q) {
            var a = jawab[q.kode];
            return '<div><small>' + esc(q.pertanyaan) + '</small><b>' + (a ? esc(a.label) + ' <span class="muted">(' + esc(a.fakta) + ', CF ' + String(a.cf).replace('.', ',') + ')</span>' : '<span class="muted">dilewati</span>') + '</b></div>';
          }).join('') + '</div>'
          + '<div class="row"><div><label for="es-nama">Nama pelanggan (opsional)</label><input id="es-nama" maxlength="80" placeholder="mis. Bu Rina"></div>'
          + '<div><label for="es-cat">Catatan (opsional)</label><input id="es-cat" maxlength="255" placeholder="mis. untuk sarapan anak sekolah"></div></div>'
          + '<div class="es-nav"><button type="button" class="ghost" id="es-back">← Ubah jawaban</button><span class="actions">'
          + '<button type="button" class="ghost" id="es-coba" title="Menjalankan engine tanpa menyimpan ke riwayat">🧪 Uji coba (tidak disimpan)</button>'
          + '<button type="button" id="es-go">🔍 Cari rekomendasi & simpan</button></span></div>';
      }
      wiz.innerHTML = h;
      renderPanel();
      bind();
    }

    function bind() {
      var q = Q[step];
      $$('input[name=opt]', wiz).forEach(function (r) {
        r.addEventListener('change', function () {
          var p = q.pilihan.filter(function (x) { return x.kode_fakta === r.value; })[0];
          jawab[q.kode] = { fakta: p.kode_fakta, label: p.label, cf: (jawab[q.kode] || {}).cf || 1 };
          $('#es-next').disabled = false;
          renderPanel();
        });
      });
      $$('[data-cf]', wiz).forEach(function (b) {
        b.addEventListener('click', function () {
          $$('[data-cf]', wiz).forEach(function (x) { x.classList.remove('on'); });
          b.classList.add('on');
          if (!jawab[q.kode]) jawab[q.kode] = { cf: num(b.dataset.cf) };
          else jawab[q.kode].cf = num(b.dataset.cf);
          renderPanel();
        });
      });
      var back = $('#es-back'), next = $('#es-next'), go = $('#es-go'), coba = $('#es-coba');
      if (back) back.onclick = function () { step--; render(); };
      if (next) next.onclick = function () {
        if (jawab[q.kode] && !jawab[q.kode].fakta) delete jawab[q.kode];
        step++; render();
      };
      if (go) go.onclick = function () { kirim(true); };
      if (coba) coba.onclick = function () { kirim(false); };
    }

    function renderPanel() {
      var list = Q.filter(function (q) { return jawab[q.kode] && jawab[q.kode].fakta; });
      panel.innerHTML = list.length ? list.map(function (q) {
        var a = jawab[q.kode];
        return '<div class="es-fk"><code>' + esc(a.fakta) + '</code><span>' + esc(a.label) + '</span><em>CF ' + String(a.cf).replace('.', ',') + '</em></div>';
      }).join('') : '<p class="muted mb0">Belum ada fakta. Jawaban pembeli akan muncul di sini sebagai fakta yang dibaca engine.</p>';
    }

    function fakta() {
      var f = {};
      Object.keys(jawab).forEach(function (k) { if (jawab[k].fakta) f[jawab[k].fakta] = jawab[k].cf; });
      return f;
    }

    function kirim(simpan) {
      var btn = $(simpan ? '#es-go' : '#es-coba');
      btn.disabled = true;
      var nama = $('#es-nama').value.trim(), cat = $('#es-cat').value.trim();
      var f = fakta(), param = {};
      Object.keys(f).forEach(function (k) { param['fakta[' + k + ']'] = f[k]; });
      // simpan → POST konsultasi (masuk riwayat); uji coba → GET coba (tidak disimpan), keduanya membawa CF pengguna
      var p = simpan ? api('konsultasi', {}, { fakta: f, nama_pelanggan: nama, catatan: cat }) : api('coba', param);
      p.then(function (d) {
        form.classList.add('es-hide');
        tampilHasil(d, nama);
      }).catch(function (e) {
        btn.disabled = false;
        alertBox($('#es-msg'), 'err', esc(e.message));
      });
    }

    function tampilHasil(d, nama) {
      var labelFakta = {};
      Q.forEach(function (q) { q.pilihan.forEach(function (p) { labelFakta[p.kode_fakta] = q.pertanyaan + ' → ' + p.label; }); });
      var dinilai = d.produk.length, ditampil = d.rekomendasi.length + d.habis.length;
      var bawah = d.produk.filter(function (p) { return p.cf_akhir < CF_MIN; }).length;
      var h = '<div class="card"><div class="card-h"><h3>🧠 ' + (d.disimpan ? 'Hasil konsultasi #' + d.id_konsultasi : 'Hasil uji coba (tidak disimpan)') + (nama ? ' · ' + esc(nama) : '') + '</h3>'
        + '<span class="actions">' + (d.disimpan ? '<a class="btn ghost sm" href="' + BASE + '/es/riwayat.php?id=' + d.id_konsultasi + '">🕘 Lihat di riwayat</a>' : '')
        + '<button type="button" class="sm" id="es-baru">➕ Konsultasi baru</button></span></div>'
        + '<div class="es-sum">' + Object.keys(d.fakta).map(function (k) {
          return '<div><small>' + esc(k) + ' · CF ' + String(d.fakta[k]).replace('.', ',') + '</small><b>' + esc((labelFakta[k] || k).split(' → ')[1] || k) + '</b></div>';
        }).join('') + '</div></div>';

      h += '<div class="section-title">🛒 Produk yang disarankan <span class="badge">' + d.rekomendasi.length + ' produk</span></div>';
      if (!d.rekomendasi.length) {
        h += '<div class="card">' + empty('🔎', 'Tidak ada produk berstok yang cocok', 'Tidak ada produk tersedia dengan keyakinan ≥ ' + pct(CF_MIN) + ' untuk kebutuhan ini. Coba ubah jawaban atau cek produk yang habis di bawah.') + '</div>';
      } else {
        h += '<div class="es-rek">' + d.rekomendasi.map(function (p, i) {
          return '<div class="es-pc' + (i === 0 ? ' top' : '') + '"><span class="es-rank">' + (i === 0 ? '⭐ Paling tepat' : '#' + (i + 1)) + '</span>'
            + '<div class="es-ph"><span>' + esc(p.ikon || '📦') + '</span><div><b>' + esc(p.nama) + '</b><small>' + esc(p.kategori) + ' · ' + rp(p.harga) + '/' + esc(p.satuan) + '</small></div></div>'
            + cfBar(p.cf_akhir)
            + '<div class="es-meta">' + stokBadge(p.stok, p.status_mis) + (p.promo_dss ? badge('🏷️ Kandidat promo DSS', 'y') : '') + '</div>'
            + '<ul class="es-why">' + p.alasan.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ul>'
            + (p.peringatan.length ? '<ul class="es-why warn">' + p.peringatan.map(function (a) { return '<li>⚠️ ' + esc(a) + '</li>'; }).join('') + '</ul>' : '')
            + (p.paket_dss.length ? '<small>📦 Paket bundling DSS: cocok dibeli bersama <b>' + p.paket_dss.map(esc).join(', ') + '</b></small>' : '')
            + '<div class="es-rules">Aturan: ' + p.aturan.map(esc).join(', ') + ' · anjuran ' + pct(p.cf_anjur) + (p.cf_hindari > 0 ? ' · hindaran ' + pct(p.cf_hindari) : '') + '</div></div>';
        }).join('') + '</div>';
      }

      if (d.habis.length) {
        h += '<div class="card" style="margin-top:18px"><div class="card-h"><h3>📭 Cocok tetapi stok habis</h3>' + badge(d.habis.length + ' produk', 'r') + '</div>'
          + '<p class="muted">' + (d.disimpan ? 'Produk ini sudah dicatat sebagai <b>permintaan pembeli</b>. Manajer dapat menugaskan staf gudang lewat menu Riwayat Konsultasi atau MIS, dan ES Keputusan Order ikut menghitungnya.' : 'Pada konsultasi yang disimpan, produk ini dicatat sebagai permintaan pembeli.') + '</p>'
          + '<div class="table-wrap"><table><thead><tr><th>Produk</th><th>Kategori</th><th class="num">Keyakinan</th><th>Alasan</th></tr></thead><tbody>'
          + d.habis.map(function (p) { return '<tr><td>' + esc(p.ikon || '') + ' <b>' + esc(p.nama) + '</b></td><td>' + esc(p.kategori) + '</td><td class="num">' + pct(p.cf_akhir) + '</td><td><small>' + p.alasan.map(esc).join('; ') + '</small></td></tr>'; }).join('')
          + '</tbody></table></div></div>';
      }

      h += '<details class="es-det"><summary>🔍 Penelusuran engine: bagaimana sistem sampai ke hasil ini</summary>'
        + '<p class="muted" style="margin-top:10px">Metode <b>forward chaining</b>: engine mulai dari fakta jawaban, mencari semua aturan yang syaratnya terpenuhi, lalu menghitung <b>Certainty Factor</b> setiap produk.</p>'
        + '<div class="es-rumus">CF aturan = CF pakar × CF jawaban terkecil pada syaratnya<br>CF anjuran produk = 1 − Π(1 − CF aturan × bobot tag produk)<br>'
        + 'CF akhir = CF anjuran × (1 − CF hindaran) · ditampilkan bila ≥ <b>' + pct(CF_MIN) + '</b> dan cocok dengan tujuan belanja</div>'
        + '<b>Aturan yang aktif (' + d.aturan_aktif.length + ')</b>'
        + '<div class="table-wrap"><table><thead><tr><th>Kode</th><th>Aksi</th><th>Tag produk</th><th class="num">CF pakar</th><th class="num">CF aturan</th><th>Penjelasan</th><th>Sumber KMS</th></tr></thead><tbody>'
        + d.aturan_aktif.map(function (a) {
          var x = AKSI[a.aksi] || [a.aksi, 'n'];
          return '<tr><td><b>' + esc(a.kode) + '</b></td><td>' + badge(x[0], x[1]) + '</td><td><code>' + esc(a.tag) + '</code></td><td class="num">' + dec(a.cf_pakar, 2) + '</td><td class="num">' + dec(a.cf_aturan, 2) + '</td><td>' + esc(a.penjelasan) + '</td><td><small>' + esc(a.sumber_kms || '–') + '</small></td></tr>';
        }).join('') + '</tbody></table></div>'
        + '<p class="muted" style="margin:10px 0 0">' + dinilai + ' produk sesuai tujuan belanja dinilai · ' + ditampil + ' lolos batas keyakinan · ' + bawah + ' di bawah batas sehingga tidak ditampilkan.</p></details>';

      hasil.innerHTML = h;
      hasil.classList.remove('es-hide');
      $('#es-baru').onclick = function () { jawab = {}; step = 0; hasil.classList.add('es-hide'); hasil.innerHTML = ''; form.classList.remove('es-hide'); $('#es-msg').innerHTML = ''; render(); window.scrollTo(0, 0); };
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }

  /* ==========================================================
   *  2. RIWAYAT KONSULTASI
   * ========================================================== */
  function halamanRiwayat() {
    var list = $('#es-list'), det = $('#es-detail'), boss = root.dataset.boss === '1';
    var pilih = parseInt(new URLSearchParams(location.search).get('id') || '0', 10);

    api('riwayat').then(function (d) {
      if (!d.riwayat.length) { list.innerHTML = empty('🕘', 'Belum ada konsultasi', 'Konsultasi yang disimpan dari menu Konsultasi Produk akan muncul di sini.'); det.innerHTML = ''; return; }
      list.innerHTML = '<div class="table-wrap"><table class="es-tbl"><thead><tr><th>#</th><th>Waktu</th><th>Pelanggan · kebutuhan</th><th class="num">Saran</th><th class="num">Habis</th></tr></thead><tbody>'
        + d.riwayat.map(function (r) {
          return '<tr data-id="' + r.id + '"><td><b>' + r.id + '</b></td><td class="nowrap">' + tgl(r.waktu, true) + '<br><small>' + esc(r.pegawai) + '</small></td><td>' + esc(r.nama_pelanggan || 'Tanpa nama') + '<br><small>' + esc(ringkasKebutuhan(r.kebutuhan)) + '</small></td>'
            + '<td class="num">' + r.produk + '</td><td class="num">' + (num(r.habis) ? badge(r.habis, 'r') : '0') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      $$('tr[data-id]', list).forEach(function (tr) { tr.onclick = function () { buka(parseInt(tr.dataset.id, 10)); }; });
      buka(pilih || d.riwayat[0].id);
    }).catch(function (e) { alertBox(list, 'err', esc(e.message)); });

    // "Tujuan belanja: sarapan · Jumlah orang: 3–4 · …" → "sarapan · 3–4 · …"
    function ringkasKebutuhan(t) {
      return String(t || '').split(' · ').map(function (x) { return x.split(': ').pop().replace(/\s*\(.*\)$/, '').replace(/^Anggaran /, 'anggaran '); }).join(' · ');
    }
    function buka(id) {
      $$('tr[data-id]', list).forEach(function (tr) { tr.classList.toggle('sel', parseInt(tr.dataset.id, 10) === id); });
      det.innerHTML = '<div class="es-load">Memuat…</div>';
      api('konsultasi', { id: id }).then(function (d) {
        var k = d.konsultasi;
        var st = { baru: ['Baru', 'r'], ditugaskan: ['Ditugaskan ke gudang', 'y'], selesai: ['Sudah berstok', 'g'] };
        det.innerHTML = '<div class="card-h"><h3>Konsultasi #' + k.id + (k.nama_pelanggan ? ' · ' + esc(k.nama_pelanggan) : '') + '</h3><small>' + tgl(k.waktu, true) + ' · ' + esc(k.pegawai) + '</small></div>'
          + (k.catatan ? '<p class="muted">📝 ' + esc(k.catatan) + '</p>' : '')
          + '<b>Fakta dari jawaban pembeli</b><div class="es-trace" style="margin:8px 0 16px">' + k.fakta.map(function (f) {
            return '<div class="es-fk"><code>' + esc(f.kode_fakta) + '</code><span>' + esc(f.nama) + '</span><em>CF ' + dec(f.cf_pengguna, 2) + '</em></div>';
          }).join('') + '</div>'
          + '<b>Rekomendasi yang diberikan</b>'
          + (k.hasil.length ? '<div class="table-wrap"><table><thead><tr><th>#</th><th>Produk & alasan</th><th class="num">CF</th><th class="num">Stok saat itu</th></tr></thead><tbody>'
            + k.hasil.map(function (h) { return '<tr><td>' + h.peringkat + '</td><td class="es-pn"><b>' + esc(h.nama) + '</b><small>' + rp(h.harga) + '/' + esc(h.satuan) + '</small><small class="muted" style="margin-top:3px">' + esc(h.alasan) + '</small></td><td class="num">' + pct(h.cf_akhir) + '</td><td class="num">' + h.stok_saat_itu + '</td></tr>'; }).join('')
            + '</tbody></table></div>' : empty('🔎', 'Tidak ada produk berstok yang cocok', ''))
          + (k.permintaan.length ? '<b style="display:block;margin-top:14px">Produk dicari tetapi habis</b><div class="chips" style="margin-top:8px">'
            + k.permintaan.map(function (r) { var s = st[r.status] || [r.status, 'n']; return '<span class="badge ' + s[1] + '">' + esc(r.nama) + ' · ' + s[0] + (r.id_tugas ? ' (tugas #' + r.id_tugas + ')' : '') + '</span>'; }).join('') + '</div>' : '');
      }).catch(function (e) { alertBox(det, 'err', esc(e.message)); });
    }

    if (boss) muatPermintaan();
    function muatPermintaan() {
      var box = $('#es-minta');
      api('ringkasan').then(function (d) {
        var p = d.permintaan_tertunda;
        if (!p.length) { box.innerHTML = '<p class="muted mb0">✅ Tidak ada permintaan pembeli yang tertunda.</p>'; return; }
        var baru = p.filter(function (x) { return x.status === 'baru'; }).length;
        box.innerHTML = '<div class="chips" style="margin-bottom:12px">' + p.map(function (x) {
          return '<span class="badge ' + (x.status === 'baru' ? 'r' : 'y') + '">' + esc(x.nama) + ' · dicari ' + x.n + 'x · ' + (x.status === 'baru' ? 'baru' : 'ditugaskan') + '</span>';
        }).join('') + '</div>'
          + '<button type="button" class="o" id="es-tugas"' + (baru ? '' : ' disabled') + '>📋 Tugaskan ke staf gudang (OAS)</button>'
          + (baru ? '' : ' <small>Semua permintaan sudah ditugaskan.</small>') + '<div id="es-tmsg" style="margin-top:10px"></div>';
        var b = $('#es-tugas');
        if (b) b.onclick = function () {
          b.disabled = true;
          api('permintaan_tugaskan', {}, {}).then(function (r) {
            alertBox($('#es-tmsg'), 'ok', r.dibuat ? 'Tugas OAS #' + r.id_tugas + ' dibuat untuk <b>' + esc(r.staf) + '</b> (' + r.produk + ' produk). <a href="' + BASE + '/oas/tugas.php">Buka tugas →</a>' : esc(r.pesan));
            setTimeout(muatPermintaan, 2500);
          }).catch(function (e) { b.disabled = false; alertBox($('#es-tmsg'), 'err', esc(e.message)); });
        };
      }).catch(function (e) { alertBox(box, 'err', esc(e.message)); });
    }
  }

  /* ==========================================================
   *  3. KEPUTUSAN WAKTU ORDER (manajer)
   * ========================================================== */
  function halamanOrder() {
    var tbl = $('#es-otbl'), kes = $('#es-okes'), info = $('#es-oinfo'), msg = $('#es-omsg');
    var data = [], filter = '', cari = '', pilihan = {};
    var KES = [];

    Promise.all([api('basis', { menu: 'ORDER' }), api('order')]).then(function (r) {
      KES = r[0].kesimpulan;
      terima(r[1]);
    }).catch(function (e) { alertBox(tbl, 'err', esc(e.message)); });

    function terima(d) {
      data = d.hasil;
      pilihan = {};
      info.innerHTML = d.analisis_terakhir
        ? 'Analisis terakhir: <b>' + tgl(d.analisis_terakhir, true) + '</b> · ' + data.length + ' produk dinilai'
          + (String(d.analisis_terakhir).slice(0, 10) !== new Date().toISOString().slice(0, 10) ? ' · <span class="low">bukan hari ini, jalankan ulang agar sesuai stok terbaru</span>' : '')
        : 'Belum pernah dianalisis. Klik <b>Jalankan analisis</b>.';
      renderKes();
      renderTabel();
    }

    function warna(kode) { var x = data.filter(function (r) { return r.kode_kesimpulan === kode; })[0]; return x ? x.warna : ({ K01: 'r', K02: 'o', K03: 'y', K04: 'g' }[kode] || 'n'); }
    function renderKes() {
      kes.innerHTML = '<button type="button" class="' + (filter ? '' : 'on ') + 'n" data-k=""><small>Semua keputusan</small><strong>' + data.length + '</strong><small>produk</small></button>'
        + KES.map(function (k) {
          var n = data.filter(function (r) { return r.kode_kesimpulan === k.kode; }).length;
          return '<button type="button" class="' + warna(k.kode) + (filter === k.kode ? ' on' : '') + '" data-k="' + k.kode + '" title="' + esc(k.tindakan) + '"><small>' + esc(k.kode) + ' · ' + esc(k.nama) + '</small><strong>' + n + '</strong><small>produk</small></button>';
        }).join('');
      $$('button', kes).forEach(function (b) { b.onclick = function () { filter = b.dataset.k; renderKes(); renderTabel(); }; });
    }

    function bisaTugas(r) { return r.status === 'saran' && num(r.qty_saran) > 0 && ['K01', 'K02', 'K03'].indexOf(r.kode_kesimpulan) >= 0; }
    function renderTabel() {
      var rows = data.filter(function (r) {
        return (!filter || r.kode_kesimpulan === filter) && (!cari || (r.nama + ' ' + r.kategori + ' ' + r.sku).toLowerCase().indexOf(cari) >= 0);
      });
      if (!data.length) { tbl.innerHTML = empty('🗓️', 'Belum ada hasil analisis', 'Klik <b>⚙️ Jalankan analisis</b> agar engine menilai semua produk aktif dari data TPS, MIS, KMS, OAS, dan DSS.'); updTombol(); return; }
      if (!rows.length) { tbl.innerHTML = empty('🔎', 'Tidak ada produk pada filter ini', ''); updTombol(); return; }
      tbl.innerHTML = '<div class="table-wrap"><table class="es-tbl"><thead><tr><th><input type="checkbox" id="es-all" title="Pilih semua yang bisa ditugaskan"></th><th>Produk</th><th>Keputusan ES</th><th class="num">CF</th><th>Tanggal order</th><th class="num">Jumlah</th>'
        + '<th class="num">Stok · sisa</th><th>Supplier (DSS)</th><th>Status</th></tr></thead><tbody>'
        + rows.map(function (r) {
          var ok = bisaTugas(r);
          return '<tr data-id="' + r.id_analisis + '"><td>' + (ok ? '<input type="checkbox" class="es-cb" value="' + r.id_analisis + '"' + (pilihan[r.id_analisis] ? ' checked' : '') + '>' : '') + '</td>'
            + '<td class="es-pn"><b>' + esc(r.nama) + '</b><small>' + esc(r.kategori) + '</small></td>'
            + '<td>' + badge(r.kesimpulan, r.warna) + '</td><td class="num">' + pct(r.cf) + '</td>'
            + '<td class="nowrap">' + (r.tanggal_order ? (r.kode_kesimpulan === 'K04' ? '<small class="muted">tinjau ulang</small><br>' : '') + tgl(r.tanggal_order) : '–') + '</td>'
            + '<td class="num">' + (num(r.qty_saran) ? '<b>' + r.qty_saran + '</b> ' + esc(r.satuan) : '–') + '</td>'
            + '<td class="num"><b class="' + (num(r.stok) <= 0 ? 'low' : '') + '">' + r.stok + '</b><br><small>' + (r.sisa_hari == null ? 'tidak terjual' : dec(r.sisa_hari) + ' hari') + '</small></td>'
            + '<td class="es-sup"><small>' + esc(r.supplier || 'lead bawaan') + '<br>lead ' + dec(r.lead_hari, 0) + ' hari</small></td>'
            + '<td>' + (r.status === 'ditugaskan' ? '<a href="' + BASE + '/oas/tugas.php" onclick="event.stopPropagation()">' + badge('Tugas #' + r.id_tugas, 'b') + '</a>' : badge('Saran', 'n')) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      $$('tr[data-id]', tbl).forEach(function (tr) {
        tr.onclick = function (ev) { if (ev.target.type === 'checkbox') return; detail(parseInt(tr.dataset.id, 10)); };
      });
      $$('.es-cb', tbl).forEach(function (cb) { cb.onchange = function () { if (cb.checked) pilihan[cb.value] = 1; else delete pilihan[cb.value]; updTombol(); }; });
      var all = $('#es-all');
      all.onchange = function () { $$('.es-cb', tbl).forEach(function (cb) { cb.checked = all.checked; cb.onchange(); }); };
      updTombol();
    }
    function updTombol() {
      var n = Object.keys(pilihan).length, b = $('#es-otugas');
      b.disabled = !n;
      b.textContent = n ? '📋 Buat tugas OAS untuk ' + n + ' produk' : '📋 Pilih produk untuk ditugaskan';
    }

    $('#es-ocari').addEventListener('input', function (e) { cari = e.target.value.trim().toLowerCase(); renderTabel(); });

    $('#es-ojalan').onclick = function () {
      var b = this; b.disabled = true; b.textContent = '⏳ Menganalisis…';
      api('order', {}, {}).then(function (d) {
        alertBox(msg, 'ok', 'Analisis selesai untuk <b>' + d.hasil.length + ' produk</b>. DSS: ' + (d.dss.ok ? 'tersinkron (' + d.dss.supplier + ' supplier, ' + d.dss.promo + ' promo, ' + d.dss.bundling + ' bundling)' : esc(d.dss.pesan))
          + (d.mis_tren !== null ? ' · tren transaksi MIS ' + dec(d.mis_tren) + '%' : '') + '.');
        return api('order');
      }).then(terima).catch(function (e) { alertBox(msg, 'err', esc(e.message)); })
        .then(function () { b.disabled = false; b.textContent = '⚙️ Jalankan analisis'; });
    };

    $('#es-osinkron').onclick = function () {
      var b = this; b.disabled = true;
      api('sinkron_dss', {}, {}).then(function (d) {
        alertBox(msg, 'ok', 'Data DSS tersinkron: ' + d.supplier + ' supplier (lead time), ' + d.promo + ' kandidat promo, ' + d.bundling + ' paket bundling, ' + d.restok + ' rencana restok. Jalankan analisis agar keputusan memakai data ini.');
      }).catch(function (e) { alertBox(msg, 'err', esc(e.message)); }).then(function () { b.disabled = false; });
    };

    $('#es-otugas').onclick = function () {
      var b = this, ids = Object.keys(pilihan);
      if (!ids.length) return;
      b.disabled = true;
      api('order_tugaskan', {}, { id: ids }).then(function (d) {
        alertBox(msg, 'ok', d.dibuat ? '<b>' + d.dibuat + ' tugas order</b> dibuat di OAS untuk <b>' + esc(d.staf) + '</b>, tenggat sesuai tanggal order. <a href="' + BASE + '/oas/tugas.php">Buka tugas →</a>' : esc(d.pesan));
        return api('order');
      }).then(terima).catch(function (e) { alertBox(msg, 'err', esc(e.message)); updTombol(); });
    };

    /* ---- panel detail satu produk ---- */
    function detail(id) {
      var m = document.createElement('div');
      m.className = 'es-modal';
      m.innerHTML = '<div><div class="es-load">Memuat perhitungan…</div></div>';
      m.onclick = function (e) { if (e.target === m) m.remove(); };
      document.body.appendChild(m);
      document.addEventListener('keydown', function tutup(e) { if (e.key === 'Escape') { m.remove(); document.removeEventListener('keydown', tutup); } });
      api('order_detail', { id: id }).then(function (d) {
        var a = d.analisis, c = d.perhitungan, sisa = a.sisa_hari == null ? null : num(a.sisa_hari);
        var batas = num(a.lead_hari) + num(c.stok_aman);
        var maxCf = Math.max.apply(null, d.cf_per_kesimpulan.map(function (x) { return x.cf; }).concat([0.0001]));
        var h = '<div class="es-mh"><div><small>' + esc(a.sku) + ' · ' + esc(a.kategori) + ' · dianalisis ' + tgl(a.dianalisis, true) + '</small><h2>' + esc(a.nama) + '</h2></div><button type="button" class="ghost sm" id="es-mx">✕ Tutup</button></div>'
          + '<div class="card"><div class="card-h"><h3>Keputusan</h3>' + badge(a.kesimpulan, a.warna) + '</div>'
          + '<p class="mb0"><b>' + esc(a.tindakan) + '</b></p>'
          + '<div class="es-calc" style="margin-top:12px"><div><small>Tanggal order</small><b>' + (a.tanggal_order ? tgl(a.tanggal_order) : '–') + (a.kode_kesimpulan === 'K04' ? ' <small>(tinjau ulang)</small>' : '') + '</b></div>'
          + '<div><small>Jumlah pesan</small><b>' + (num(a.qty_saran) ? a.qty_saran + ' ' + esc(a.satuan) : '–') + '</b></div><div><small>Keyakinan (CF)</small><b>' + pct(a.cf) + '</b></div></div></div>'

          + '<div class="card"><div class="card-h"><h3>1 · Data yang dibaca</h3></div><div class="es-calc">'
          + '<div><small>Stok (TPS)</small><b>' + a.stok + ' ' + esc(a.satuan) + '</b></div>'
          + '<div><small>Rata jual/hari (30 hari)</small><b>' + dec(a.rata_harian, 2) + '</b></div>'
          + '<div><small>Sisa hari stok</small><b>' + (sisa == null ? '–' : dec(sisa)) + '</b></div>'
          + '<div><small>Lead time (DSS)</small><b>' + dec(a.lead_hari, 0) + ' hari</b></div>'
          + '<div><small>Stok pengaman</small><b>' + dec(c.stok_aman, 0) + ' hari</b></div>'
          + '<div><small>Batas pesan</small><b>' + dec(batas, 0) + ' hari</b></div></div>'
          + '<p class="hint">Supplier: ' + esc(a.supplier || 'belum ada supplier terpilih, memakai lead bawaan') + (c.segar ? ' · produk mudah rusak, siklus ' + dec(c.siklus, 0) + ' hari (SOP KMS)' : ' · siklus ' + dec(c.siklus, 0) + ' hari') + '</p></div>'

          + '<div class="card"><div class="card-h"><h3>2 · Fakta yang terpenuhi</h3>' + badge(d.fakta.length + ' fakta', 'n') + '</div>'
          + (d.fakta.length ? d.fakta.map(function (f) {
            return '<div class="li"><div class="li-body"><b><code>' + esc(f.kode) + '</code> ' + esc(f.nama) + ' <span class="es-src ' + esc(f.sumber) + '">' + esc(f.sumber) + '</span></b><small>Bukti: ' + esc(f.bukti) + '</small></div></div>';
          }).join('') : '<p class="muted mb0">Tidak ada fakta yang terpenuhi.</p>') + '</div>'

          + '<div class="card"><div class="card-h"><h3>3 · Aturan yang aktif</h3>' + badge(d.aturan.length + ' aturan', 'n') + '</div>'
          + (d.aturan.length ? '<div class="table-wrap"><table><thead><tr><th>Kode</th><th>JIKA</th><th>MAKA</th><th class="num">CF</th></tr></thead><tbody>' + d.aturan.map(function (r) {
            var k = KES.filter(function (x) { return x.kode === r.kesimpulan; })[0];
            return '<tr><td><b>' + esc(r.kode) + '</b></td><td><small>' + r.syarat.map(esc).join(' DAN ') + '</small><br><small class="muted">' + esc(r.penjelasan) + '</small></td><td>' + esc(k ? k.nama : r.kesimpulan) + '</td><td class="num">' + dec(r.cf_pakar, 2) + '</td></tr>';
          }).join('') + '</tbody></table></div>' : '<p class="muted mb0">Tidak ada aturan aktif, keputusan bawaan: tunda.</p>') + '</div>'

          + '<div class="card"><div class="card-h"><h3>4 · CF gabungan per kesimpulan</h3></div>'
          + d.cf_per_kesimpulan.map(function (x) {
            return '<div class="es-hbar ' + esc(x.warna) + '"><span>' + esc(x.nama) + '</span><div><i style="width:' + (x.cf / maxCf * 100).toFixed(1) + '%"></i></div><b>' + pct(x.cf) + '</b></div>';
          }).join('')
          + '<div class="es-rumus">CF gabungan = 1 − Π(1 − CF aturan). Kesimpulan dengan CF tertinggi dipilih; bila sama, yang paling mendesak.</div></div>'

          + (num(a.qty_saran) ? '<div class="card"><div class="card-h"><h3>5 · Hitungan jumlah pesan</h3></div><div class="es-rumus">'
            + 'Jumlah = ⌈rata jual × (lead + aman + siklus) − stok saat order⌉<br>= ⌈' + dec(a.rata_harian, 2) + ' × (' + dec(a.lead_hari, 0) + ' + ' + dec(c.stok_aman, 0) + ' + ' + dec(c.siklus, 0) + ') − stok saat order⌉ = <b>' + a.qty_saran + ' ' + esc(a.satuan) + '</b></div></div>' : '');
        m.firstChild.innerHTML = h;
        $('#es-mx', m).onclick = function () { m.remove(); };
      }).catch(function (e) { alertBox(m.firstChild, 'err', esc(e.message)); });
    }
  }

  /* ==========================================================
   *  4. BASIS PENGETAHUAN
   * ========================================================== */
  function halamanBasis() {
    var box = $('#es-basis'), tabs = $('#es-btabs');
    var cache = {};
    $$('a', tabs).forEach(function (a) {
      a.onclick = function (e) { e.preventDefault(); $$('a', tabs).forEach(function (x) { x.classList.toggle('on', x === a); }); muat(a.dataset.menu); history.replaceState(null, '', '?menu=' + a.dataset.menu); };
    });
    var awal = (new URLSearchParams(location.search).get('menu') || 'REKOMENDASI').toUpperCase();
    $$('a', tabs).forEach(function (x) { x.classList.toggle('on', x.dataset.menu === awal); });
    muat(awal === 'ORDER' ? 'ORDER' : 'REKOMENDASI');

    function muat(menu) {
      box.innerHTML = '<div class="es-load">Memuat basis pengetahuan…</div>';
      var p = cache[menu] || Promise.all([api('basis', { menu: menu }), menu === 'REKOMENDASI' ? api('pertanyaan', { menu: menu }) : Promise.resolve({ pertanyaan: [] })]);
      cache[menu] = p;
      p.then(function (r) { box.innerHTML = tampil(menu, r[0], r[1].pertanyaan); }).catch(function (e) { alertBox(box, 'err', esc(e.message)); });
    }

    function tampil(menu, b, Q) {
      var h = '';
      if (menu === 'REKOMENDASI') {
        h += '<div class="card"><div class="card-h"><h3>❓ Pertanyaan → jawaban → fakta</h3>' + badge(Q.length + ' pertanyaan', 'n') + '</div><div class="table-wrap"><table><thead><tr><th>Kode</th><th>Pertanyaan</th><th>Pilihan jawaban (fakta)</th></tr></thead><tbody>'
          + Q.map(function (q) { return '<tr><td><b>' + esc(q.kode) + '</b></td><td>' + esc(q.pertanyaan) + '</td><td><div class="chips">' + q.pilihan.map(function (p) { return '<span class="badge n">' + esc(p.kode_fakta) + ' · ' + esc(p.label) + '</span>'; }).join('') + '</div></td></tr>'; }).join('')
          + '</tbody></table></div></div>';
      } else {
        h += '<div class="card"><div class="card-h"><h3>📥 Fakta otomatis dari modul lain</h3>' + badge(b.fakta.length + ' fakta', 'n') + '</div><p class="muted">Menu ini tidak bertanya. Semua fakta dihitung otomatis dari data TPS, OAS, KMS, MIS, dan DSS setiap kali analisis dijalankan.</p>'
          + '<div class="table-wrap"><table><thead><tr><th>Kode</th><th>Fakta</th><th>Sumber</th><th>Cara dihitung</th></tr></thead><tbody>'
          + b.fakta.map(function (f) { return '<tr><td><b>' + esc(f.kode) + '</b></td><td>' + esc(f.nama) + '</td><td><span class="es-src ' + esc(f.sumber) + '">' + esc(f.sumber) + '</span></td><td><small>' + esc(f.cara || '–') + '</small></td></tr>'; }).join('')
          + '</tbody></table></div></div>'
          + '<div class="card"><div class="card-h"><h3>🎯 Kesimpulan (keputusan)</h3></div><div class="table-wrap"><table><thead><tr><th>Kode</th><th>Keputusan</th><th>Tindakan</th><th class="num">Prioritas</th></tr></thead><tbody>'
          + b.kesimpulan.map(function (k) { return '<tr><td><b>' + esc(k.kode) + '</b></td><td>' + esc(k.nama) + '</td><td>' + esc(k.tindakan) + '</td><td class="num">' + k.prioritas + '</td></tr>'; }).join('')
          + '</tbody></table></div></div>';
      }
      h += '<div class="card"><div class="card-h"><h3>📐 Aturan JIKA–MAKA</h3>' + badge(b.aturan.length + ' aturan', 'n') + '</div><div class="table-wrap"><table><thead><tr><th>Kode</th><th>JIKA</th><th>MAKA</th><th class="num">CF pakar</th><th>Sumber KMS</th></tr></thead><tbody>'
        + b.aturan.map(function (a) { return '<tr><td><b>' + esc(a.kode) + '</b></td><td><small>' + esc(a.jika) + '</small></td><td><small>' + esc(a.maka) + '</small></td><td class="num">' + dec(a.cf_pakar, 2) + '</td><td><small>' + esc(a.sumber_kms || '–') + '</small></td></tr>'; }).join('')
        + '</tbody></table></div></div>'
        + '<div class="card"><div class="card-h"><h3>⚙️ Parameter engine</h3></div><div class="table-wrap"><table><thead><tr><th>Kunci</th><th class="num">Nilai</th><th>Keterangan</th><th>Sumber</th></tr></thead><tbody>'
        + b.pengaturan.map(function (s) { return '<tr><td><code>' + esc(s.kunci) + '</code></td><td class="num"><b>' + dec(s.nilai, 2) + '</b></td><td><small>' + esc(s.keterangan) + '</small></td><td><span class="es-src ' + esc(s.sumber) + '">' + esc(s.sumber) + '</span></td></tr>'; }).join('')
        + '</tbody></table></div></div>';
      return h;
    }
  }

  ({ konsultasi: halamanKonsultasi, riwayat: halamanRiwayat, order: halamanOrder, basis: halamanBasis }[PAGE] || function () {})();
})();

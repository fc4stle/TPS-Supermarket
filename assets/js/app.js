/* MartOffice — interaksi umum */
document.addEventListener('submit', function (ev) {
  var f = ev.target;
  var msg = (ev.submitter && ev.submitter.dataset.confirm) || f.dataset.confirm;
  if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
  // cegah klik ganda: kunci tombol submit setelah form terkirim
  if (!ev.defaultPrevented && !f.hasAttribute('target')) {
    setTimeout(function () {
      f.querySelectorAll('button[type=submit],button:not([type])').forEach(function (b) { b.disabled = true; });
    }, 0);
  }
});

// aktifkan kembali tombol saat halaman dibuka lagi lewat tombol Back
window.addEventListener('pageshow', function () {
  document.querySelectorAll('button[disabled]:not([data-keep-disabled])').forEach(function (b) { b.disabled = false; });
});

// tombol lihat password
document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
  b.addEventListener('click', function () {
    var i = document.getElementById(b.dataset.togglePw);
    i.type = i.type === 'password' ? 'text' : 'password';
    b.textContent = i.type === 'password' ? '👁️' : '🙈';
  });
});

// notifikasi sukses hilang otomatis
setTimeout(function () {
  document.querySelectorAll('.alert.ok').forEach(function (a) {
    a.style.transition = 'opacity .4s'; a.style.opacity = '0';
    setTimeout(function () { a.remove(); }, 400);
  });
}, 5000);

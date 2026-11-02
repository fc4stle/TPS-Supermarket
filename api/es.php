<?php
/* ==========================================================
 *  API BACK END EXPERT SYSTEM (JSON)
 *  Dipakai halaman ES (front end), MIS, dan DSS.
 *
 *  GET  ?aksi=menu                      daftar menu ES
 *  GET  ?aksi=pertanyaan&menu=REKOMENDASI
 *  GET  ?aksi=basis&menu=REKOMENDASI|ORDER   basis pengetahuan (aturan + sumber KMS)
 *  POST ?aksi=konsultasi                menu 1: jawaban pembeli → rekomendasi produk (disimpan)
 *  GET  ?aksi=coba&fakta=F01,F14        menu 1: uji rekomendasi tanpa menyimpan
 *  GET  ?aksi=riwayat | konsultasi&id=  riwayat & detail konsultasi
 *  POST ?aksi=permintaan_tugaskan       produk dicari tapi habis → tugas OAS
 *  POST ?aksi=order                     menu 2: analisis waktu order semua produk (manajer)
 *  GET  ?aksi=order                     hasil analisis order terbaru
 *  GET  ?aksi=order_produk&id=          fakta, aturan, dan keputusan order satu produk (tanpa menyimpan)
 *  POST ?aksi=order_tugaskan            keputusan order → tugas OAS staf gudang
 *  POST ?aksi=sinkron_dss               ambil hasil DSS (supplier, lead time, promo, bundling)
 *  GET  ?aksi=ringkasan                 ringkasan untuk MIS/DSS (sesi login atau HMAC "es:ringkasan")
 *  GET  ?aksi=token                     token CSRF untuk permintaan POST
 *
 *  POST wajib membawa _token (form) atau header X-CSRF-Token.
 * ========================================================== */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/mis.php';
require __DIR__ . '/../includes/sso.php';
require __DIR__ . '/../includes/es.php';

// URL dasar aplikasi (api/es.php ada satu folder di bawah root MartOffice)
if (!defined('BASE_URL')) define('BASE_URL', rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/'));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function json_out(array $data, int $kode = 200)
{
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

set_exception_handler(function (Throwable $ex) {
    $kode = $ex instanceof InvalidArgumentException ? 422 : 500;
    json_out(['ok' => false, 'pesan' => $kode === 422 ? $ex->getMessage() : 'Terjadi kesalahan di server ES'], $kode);
});

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
session_name('MARTOFFICE');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();
$GLOBALS['ME'] = !empty($_SESSION['uid'])
    ? row('SELECT id,nama,username,role,jabatan FROM pegawai WHERE id=? AND aktif=1', [(int) $_SESSION['uid']]) : null;

$aksi = get('aksi', 'menu');
$hmac = $aksi === 'ringkasan' && api_valid('es:ringkasan');
if (!me() && !$hmac) json_out(['ok' => false, 'pesan' => 'Silakan login MartOffice terlebih dahulu'], 401);

function wajib_post(): void
{
    if (!is_post()) json_out(['ok' => false, 'pesan' => 'Gunakan metode POST'], 405);
    $t = (string) ($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $t)) json_out(['ok' => false, 'pesan' => 'Token CSRF tidak valid'], 419);
}

function wajib_role(string ...$r): void
{
    if (!in_array(role(), $r, true)) json_out(['ok' => false, 'pesan' => 'Akses hanya untuk ' . implode('/', $r)], 403);
}

/** Jawaban dari form: fakta[]=F01&fakta[]=F08 atau fakta[F01]=0.8 (CF pengguna). */
function baca_jawaban(): array
{
    $in = $_POST['fakta'] ?? $_GET['fakta'] ?? [];
    if (is_string($in)) $in = array_filter(array_map('trim', explode(',', $in)));
    $j = [];
    foreach ((array) $in as $k => $v) {
        if (is_int($k)) $j[(string) $v] = 1.0;
        else $j[(string) $k] = (float) $v;
    }
    return $j;
}

switch ($aksi) {
    case 'token':
        json_out(['ok' => true, 'token' => csrf_token()]);

    case 'menu':
        json_out(['ok' => true, 'menu' => es_menu_daftar()]);

    case 'pertanyaan':
        json_out(['ok' => true, 'menu' => get('menu', 'REKOMENDASI'), 'pertanyaan' => es_pertanyaan(get('menu', 'REKOMENDASI'))]);

    case 'basis':
        $m = pilih(get('menu', 'REKOMENDASI'), ['REKOMENDASI', 'ORDER'], 'REKOMENDASI');
        json_out(['ok' => true, 'menu' => $m,
            'fakta' => rows('SELECT f.kode, f.nama, f.sumber, f.otomatis, f.cara FROM es_fakta f JOIN es_menu m ON m.id=f.id_menu WHERE m.kode=? ORDER BY f.kode', [$m]),
            'kesimpulan' => rows('SELECT k.kode, k.nama, k.tindakan, k.prioritas FROM es_kesimpulan k JOIN es_menu m ON m.id=k.id_menu WHERE m.kode=? ORDER BY k.prioritas', [$m]),
            'aturan' => rows('SELECT kode, jika, maka, cf_pakar, penjelasan, sumber_kms FROM v_es_basis_pengetahuan WHERE kode_menu=? ORDER BY id', [$m]),
            'pengaturan' => rows('SELECT kunci, nilai, keterangan, sumber, diperbarui FROM es_pengaturan ORDER BY kunci')]);

    case 'coba':
        json_out(['ok' => true, 'disimpan' => false, 'fakta' => es_validasi_jawaban(baca_jawaban())] + es_rekomendasi(es_validasi_jawaban(baca_jawaban())));

    case 'konsultasi':
        if (is_post()) {
            wajib_post();
            $r = es_konsultasi_simpan(uid(), baca_jawaban(), post('nama_pelanggan'), post('catatan'));
            json_out(['ok' => true, 'disimpan' => true] + $r, 201);
        }
        $k = es_konsultasi_detail((int) get('id'));
        $k ? json_out(['ok' => true, 'konsultasi' => $k]) : json_out(['ok' => false, 'pesan' => 'Konsultasi tidak ditemukan'], 404);

    case 'riwayat':
        json_out(['ok' => true, 'riwayat' => rows('SELECT k.id, k.waktu, k.nama_pelanggan, g.nama pegawai,
                (SELECT GROUP_CONCAT(f.nama ORDER BY f.kode SEPARATOR " · ") FROM es_konsultasi_fakta kf JOIN es_fakta f ON f.kode=kf.kode_fakta WHERE kf.id_konsultasi=k.id) kebutuhan,
                (SELECT COUNT(*) FROM es_konsultasi_hasil h WHERE h.id_konsultasi=k.id) produk,
                (SELECT COUNT(*) FROM es_permintaan r WHERE r.id_konsultasi=k.id) habis
            FROM es_konsultasi k JOIN pegawai g ON g.id=k.id_pegawai ORDER BY k.id DESC LIMIT 50')]);

    case 'permintaan_tugaskan':
        wajib_post();
        wajib_role('manajer', 'admin');
        json_out(['ok' => true] + es_permintaan_tugaskan(uid()));

    case 'order':
        wajib_role('manajer', 'admin');
        if (is_post()) {
            wajib_post();
            json_out(['ok' => true] + es_order_jalankan(uid()), 201);
        }
        json_out(['ok' => true, 'analisis_terakhir' => val('SELECT MAX(dianalisis) FROM es_order_analisis'),
            'hasil' => rows('SELECT id id_analisis, id_produk, sku, nama, kategori, kode_kesimpulan, kesimpulan, tindakan, cf, tanggal_order, qty_saran, satuan,
                                    stok, rata_harian, sisa_hari, lead_hari, supplier, aturan, alasan, status, id_tugas, dianalisis
                             FROM v_es_order_terbaru ORDER BY prioritas, tanggal_order, nama')]);

    case 'order_produk':
        wajib_role('manajer', 'admin');
        $p = row('SELECT id, sku, nama, kategori, satuan, harga, stok FROM produk WHERE id=? AND aktif=1', [(int) get('id')]);
        if (!$p) json_out(['ok' => false, 'pesan' => 'Produk tidak ditemukan'], 404);
        $kes = array_column(rows('SELECT kode, nama, tindakan, prioritas FROM es_kesimpulan'), null, 'kode');
        [$info, $fakta] = es_order_fakta($p, es_order_konteks());
        $inf = es_order_inferensi($fakta, es_aturan('ORDER'), $kes);
        [$tgl, $qty] = es_order_jadwal($inf['kesimpulan'], $info);
        json_out(['ok' => true, 'produk' => $p, 'perhitungan' => $info, 'fakta' => $fakta,
            'aturan_aktif' => array_map(fn($a) => ['kode' => $a['kode'], 'kesimpulan' => $a['kode_kesimpulan'], 'cf_pakar' => $a['cf_pakar'], 'penjelasan' => $a['penjelasan']], $inf['aturan']),
            'cf_per_kesimpulan' => $inf['cf_semua'], 'keputusan' => $kes[$inf['kesimpulan']] + ['kode' => $inf['kesimpulan'], 'cf' => $inf['cf']],
            'tanggal_order' => $tgl, 'qty_saran' => $qty]);

    case 'order_tugaskan':
        wajib_post();
        wajib_role('manajer', 'admin');
        $ids = array_map('intval', (array) ($_POST['id'] ?? []));
        json_out(['ok' => true] + es_order_tugaskan(uid(), array_filter($ids)));

    case 'sinkron_dss':
        wajib_post();
        wajib_role('manajer', 'admin');
        $r = es_sinkron_dss();
        json_out(['ok' => $r['ok']] + $r, $r['ok'] ? 200 : 503);

    case 'ringkasan':
        json_out(['ok' => true] + es_ringkasan());
}
json_out(['ok' => false, 'pesan' => 'Aksi tidak dikenal'], 404);

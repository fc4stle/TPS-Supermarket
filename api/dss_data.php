<?php
/* ==========================================================
 *  API DATA MARTOFFICE UNTUK DSS
 *  Dipanggil oleh back end DSS (Flask) untuk mengambil data
 *  pendukung keputusan dari keempat modul sebelumnya:
 *    - TPS : kategori produk, omzet & tren, stok menipis/habis, restok
 *    - MIS : agregasi omzet 7 hari (mis_penjualan_harian) & peringatan stok
 *    - OAS : dokumen pemasok/pengadaan dan tugas terkait stok/pemasok
 *    - KMS : SOP / knowledge yang relevan dengan penerimaan barang & pemasok
 *  Ditambah data rinci untuk kasus DSS lain: penjualan per produk (restok,
 *  promo), kinerja pegawai (pegawai terbaik), pola jam transaksi (kasir per
 *  shift), dan pasangan produk yang dibeli bersamaan (bundling).
 *  Data OAS juga memuat pengumuman & agenda 30 hari serta tugas tindak lanjut
 *  [DSS], dan MIS memuat agregasi harian, rata-rata belanja, dan produk yang
 *  sedang masuk Pusat Peringatan.
 *  Hanya melayani permintaan bertanda tangan HMAC (SSO_SECRET).
 * ========================================================== */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/mis.php';
require __DIR__ . '/../includes/sso.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!api_valid('martoffice:data')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'pesan' => 'Tanda tangan API tidak valid']);
    exit;
}

// kata kunci untuk memilih data OAS/KMS yang berhubungan dengan pemasok & persediaan
const KUNCI = 'pemasok|supplier|retur|stok|stock|penerimaan|gudang|fifo|kedaluwarsa|chiller|restok|opname|bundling|promo|kasir|shift';

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    /* ---------- TPS ---------- */
    $produk = rows('SELECT kategori, MIN(ikon) ikon, COUNT(*) produk, SUM(stok < ?) menipis, SUM(stok = 0) habis
                    FROM produk WHERE aktif=1 GROUP BY kategori', [STOK_MIN]);
    $jual = function (int $dari, int $sampai): array {
        return array_column(rows('SELECT p.kategori, SUM(d.subtotal) omzet, SUM(d.qty) qty
                FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk p ON p.id=d.id_produk
                WHERE DATE(t.waktu) BETWEEN CURDATE() - INTERVAL ? DAY AND CURDATE() - INTERVAL ? DAY
                GROUP BY p.kategori', [$dari, $sampai]), null, 'kategori');
    };
    $kini = $jual(29, 0);
    $lalu = $jual(59, 30);
    $restok = array_column(rows("SELECT p.kategori, SUM(s.perubahan) unit FROM stok_log s JOIN produk p ON p.id=s.id_produk
                WHERE s.jenis='restok' AND s.waktu >= CURDATE() - INTERVAL 29 DAY GROUP BY p.kategori"), 'unit', 'kategori');
    $tps = [];
    foreach ($produk as $p) {
        $k = $p['kategori'];
        $o = (float) ($kini[$k]['omzet'] ?? 0);
        $o0 = (float) ($lalu[$k]['omzet'] ?? 0);
        $tps[] = [
            'nama' => $k, 'ikon' => $p['ikon'], 'produk' => (int) $p['produk'],
            'menipis' => (int) $p['menipis'], 'habis' => (int) $p['habis'],
            'omzet' => $o, 'qty' => (int) ($kini[$k]['qty'] ?? 0), 'omzet_lalu' => $o0,
            'tren' => $o0 > 0 ? round(($o - $o0) / $o0 * 100, 1) : null,
            'restok_unit' => (int) ($restok[$k] ?? 0),
        ];
    }
    usort($tps, fn($a, $b) => $b['omzet'] <=> $a['omzet']);

    /* ---------- MIS ---------- */
    mis_refresh();
    $o7 = (float) val('SELECT COALESCE(SUM(omzet),0) FROM mis_penjualan_harian WHERE tanggal BETWEEN CURDATE()-INTERVAL 6 DAY AND CURDATE()');
    $o7l = (float) val('SELECT COALESCE(SUM(omzet),0) FROM mis_penjualan_harian WHERE tanggal BETWEEN CURDATE()-INTERVAL 13 DAY AND CURDATE()-INTERVAL 7 DAY');
    $alerts = mis_alerts();
    $m30 = row('SELECT COALESCE(SUM(omzet),0) omzet, COALESCE(SUM(jumlah_transaksi),0) trx FROM mis_penjualan_harian
                WHERE tanggal BETWEEN CURDATE()-INTERVAL 29 DAY AND CURDATE()');
    $mis = [
        'omzet_30' => (float) $m30['omzet'], 'transaksi_30' => (int) $m30['trx'],
        'rata_belanja_30' => $m30['trx'] ? round($m30['omzet'] / $m30['trx']) : 0,
        'harian' => array_map(fn($r) => ['tanggal' => $r['tanggal'], 'transaksi' => (int) $r['jumlah_transaksi'], 'omzet' => (float) $r['omzet']],
            rows('SELECT tanggal, jumlah_transaksi, omzet FROM mis_penjualan_harian WHERE tanggal BETWEEN CURDATE()-INTERVAL 13 DAY AND CURDATE() ORDER BY tanggal')),
        // produk yang sedang masuk Pusat Peringatan MIS (stok habis / menipis)
        'produk_peringatan' => array_map(fn($r) => ['id' => (int) $r['id'], 'level' => $r['stok'] == 0 ? 'kritis' : 'perhatian'],
            rows('SELECT id, stok FROM produk WHERE aktif=1 AND stok < ?', [STOK_MIN])),
        'omzet_7' => $o7, 'omzet_7_lalu' => $o7l, 'tren_7' => $o7l > 0 ? round(($o7 - $o7l) / $o7l * 100, 1) : null,
        'jumlah_peringatan' => count($alerts),
        'peringatan_modul' => array_count_values(array_column($alerts, 'modul')),
        'peringatan_stok' => array_values(array_map(fn($a) => ['level' => $a['level'], 'judul' => $a['judul']],
            array_slice(array_filter($alerts, fn($a) => $a['modul'] === 'TPS'), 0, 8))),
    ];

    /* ---------- OAS ---------- */
    $oas = [
        'dokumen' => rows("SELECT d.id, d.judul, d.kategori, DATE_FORMAT(d.dibuat,'%Y-%m-%d') tanggal, g.nama pengunggah
                           FROM dokumen d JOIN pegawai g ON g.id=d.id_pengunggah
                           WHERE d.judul REGEXP ? OR d.deskripsi REGEXP ? ORDER BY d.dibuat DESC LIMIT 10", [KUNCI, KUNCI]),
        'tugas' => rows("SELECT t.judul, t.status, DATE_FORMAT(t.tenggat,'%Y-%m-%d') tenggat, g.nama staff
                         FROM tugas t JOIN pegawai g ON g.id=t.id_staff
                         WHERE t.judul REGEXP ? ORDER BY t.status='selesai', t.tenggat DESC LIMIT 8", [KUNCI]),
        // tindak lanjut keputusan DSS yang sudah dikirim ke OAS (judul diawali [DSS])
        'tugas_dss' => rows("SELECT t.id, t.judul, LEFT(COALESCE(t.deskripsi,''), 1000) deskripsi, t.status, DATE_FORMAT(t.tenggat,'%Y-%m-%d') tenggat, g.nama staff
                             FROM tugas t JOIN pegawai g ON g.id=t.id_staff WHERE t.judul LIKE '[DSS]%' ORDER BY t.id DESC LIMIT 20"),
        'pengumuman' => rows("SELECT id, judul, LEFT(isi, 300) isi, penting, DATE_FORMAT(dibuat,'%Y-%m-%d') tanggal
                              FROM pengumuman WHERE dibuat >= CURDATE() - INTERVAL 30 DAY ORDER BY dibuat DESC LIMIT 8"),
        'agenda' => rows("SELECT id, judul, LEFT(COALESCE(deskripsi,''), 300) deskripsi, DATE_FORMAT(mulai,'%Y-%m-%d %H:%i') mulai, untuk
                          FROM agenda WHERE mulai >= CURDATE() - INTERVAL 1 DAY AND mulai < CURDATE() + INTERVAL 30 DAY ORDER BY mulai LIMIT 8"),
    ];

    /* ---------- KMS ---------- */
    $kms = [
        'artikel' => array_map(fn($r) => ['id' => (int) $r['id'], 'judul' => $r['judul'], 'kategori' => $r['kategori'], 'dilihat' => (int) $r['dilihat'],
                                          'feedback' => (int) $r['fb'], 'membantu' => $r['fb'] ? (int) round($r['ya'] / $r['fb'] * 100) : null],
            rows("SELECT a.id, a.judul, k.nama kategori, a.dilihat, COUNT(f.id) fb, COALESCE(SUM(f.membantu=1),0) ya
                  FROM artikel_kms a JOIN kategori_kms k ON k.id=a.id_kategori LEFT JOIN feedback_kms f ON f.id_artikel=a.id
                  WHERE a.status='published' AND (a.judul REGEXP ? OR a.ringkasan REGEXP ?)
                  GROUP BY a.id, a.judul, k.nama, a.dilihat ORDER BY a.dilihat DESC LIMIT 14", [KUNCI, KUNCI])),
    ];

    /* ---------- Rinci: penjualan per produk (TPS) ---------- */
    $produk_rinci = array_map(fn($r) => [
        'id' => (int) $r['id'], 'sku' => $r['sku'], 'nama' => $r['nama'], 'ikon' => $r['ikon'], 'kategori' => $r['kategori'],
        'satuan' => $r['satuan'], 'harga' => (float) $r['harga'], 'stok' => (int) $r['stok'],
        'terjual_30' => (int) $r['t30'], 'terjual_7' => (int) $r['t7'], 'terjual_lalu' => (int) $r['tl'],
        'omzet_30' => (float) $r['o30'], 'terakhir_jual' => $r['akhir'],
    ], rows("SELECT p.id, p.sku, p.nama, p.ikon, p.kategori, p.satuan, p.harga, p.stok,
                    COALESCE(SUM(CASE WHEN DATE(t.waktu) >= CURDATE() - INTERVAL 29 DAY THEN d.qty END),0) t30,
                    COALESCE(SUM(CASE WHEN DATE(t.waktu) >= CURDATE() - INTERVAL 6 DAY THEN d.qty END),0) t7,
                    COALESCE(SUM(CASE WHEN DATE(t.waktu) BETWEEN CURDATE() - INTERVAL 59 DAY AND CURDATE() - INTERVAL 30 DAY THEN d.qty END),0) tl,
                    COALESCE(SUM(CASE WHEN DATE(t.waktu) >= CURDATE() - INTERVAL 29 DAY THEN d.subtotal END),0) o30,
                    DATE_FORMAT(MAX(t.waktu),'%Y-%m-%d') akhir
             FROM produk p LEFT JOIN detail_transaksi d ON d.id_produk=p.id LEFT JOIN transaksi t ON t.id=d.id_transaksi
             WHERE p.aktif=1 GROUP BY p.id, p.sku, p.nama, p.ikon, p.kategori, p.satuan, p.harga, p.stok ORDER BY p.kategori, p.nama"));

    /* ---------- Rinci: kinerja pegawai (TPS + OAS + KMS) ---------- */
    $pegawai = array_map(fn($r) => array_map(fn($v) => is_numeric($v) ? $v + 0 : $v, $r), rows("SELECT g.id, g.nama, g.jabatan,
            (SELECT COUNT(*) FROM transaksi t WHERE t.id_kasir=g.id AND DATE(t.waktu) >= CURDATE() - INTERVAL 29 DAY) transaksi,
            (SELECT COALESCE(SUM(t.total),0) FROM transaksi t WHERE t.id_kasir=g.id AND DATE(t.waktu) >= CURDATE() - INTERVAL 29 DAY) omzet,
            (SELECT COUNT(*) FROM tugas k WHERE k.id_staff=g.id AND k.status='selesai' AND k.selesai_pada >= CURDATE() - INTERVAL 59 DAY) tugas_selesai,
            (SELECT COUNT(*) FROM tugas k WHERE k.id_staff=g.id AND k.status='selesai' AND k.selesai_pada >= CURDATE() - INTERVAL 59 DAY AND DATE(k.selesai_pada) <= k.tenggat) tepat_waktu,
            (SELECT COUNT(*) FROM tugas k WHERE k.id_staff=g.id AND k.status!='selesai') tugas_aktif,
            (SELECT COUNT(*) FROM tugas k WHERE k.id_staff=g.id AND k.status!='selesai' AND k.tenggat < CURDATE()) terlambat,
            (SELECT COUNT(*) FROM artikel_kms a WHERE a.id_penulis=g.id AND a.status='published') knowledge,
            (SELECT COUNT(*) FROM feedback_kms f JOIN artikel_kms a ON a.id=f.id_artikel WHERE a.id_penulis=g.id AND f.membantu=1) dinilai_membantu
        FROM pegawai g WHERE g.aktif=1 AND g.role='staff' ORDER BY g.nama"));

    /* ---------- Rinci: pola jam transaksi 8 minggu terakhir (TPS) ---------- */
    $jam = array_map(fn($r) => ['hari' => (int) $r['d'], 'jam' => (int) $r['h'], 'transaksi' => (int) $r['n']],
        rows('SELECT DAYOFWEEK(waktu) d, HOUR(waktu) h, COUNT(*) n FROM transaksi WHERE DATE(waktu) >= CURDATE() - INTERVAL 55 DAY GROUP BY d, h'));

    /* ---------- Rinci: pasangan produk dibeli bersamaan 60 hari (TPS) ---------- */
    $nTrx = (int) val('SELECT COUNT(*) FROM transaksi WHERE DATE(waktu) >= CURDATE() - INTERVAL 59 DAY');
    $perProduk = array_column(rows('SELECT d.id_produk, COUNT(DISTINCT d.id_transaksi) n FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi
                    WHERE DATE(t.waktu) >= CURDATE() - INTERVAL 59 DAY GROUP BY d.id_produk'), 'n', 'id_produk');
    $pasangan = array_map(fn($r) => ['a' => (int) $r['pa'], 'b' => (int) $r['pb'], 'bersama' => (int) $r['n'],
                                      'trx_a' => (int) ($perProduk[$r['pa']] ?? 0), 'trx_b' => (int) ($perProduk[$r['pb']] ?? 0)],
        rows('SELECT a.id_produk pa, b.id_produk pb, COUNT(DISTINCT a.id_transaksi) n
              FROM detail_transaksi a JOIN detail_transaksi b ON a.id_transaksi=b.id_transaksi AND a.id_produk < b.id_produk
              JOIN transaksi t ON t.id=a.id_transaksi WHERE DATE(t.waktu) >= CURDATE() - INTERVAL 59 DAY
              GROUP BY a.id_produk, b.id_produk HAVING n >= 3 ORDER BY n DESC LIMIT 80'));

    echo json_encode(['ok' => true, 'waktu' => date('Y-m-d H:i:s'), 'tps' => $tps, 'mis' => $mis, 'oas' => $oas, 'kms' => $kms,
        'produk' => $produk_rinci, 'pegawai' => $pegawai, 'jam' => $jam,
        'keranjang' => ['transaksi' => $nTrx, 'pasangan' => $pasangan]],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'pesan' => 'Database MartOffice tidak dapat dibaca']);
}

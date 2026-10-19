<?php
/* ==========================================================
 *  API DATA MARTOFFICE UNTUK DSS
 *  Dipanggil oleh back end DSS (Flask) untuk mengambil data
 *  pendukung keputusan dari keempat modul sebelumnya:
 *    - TPS : kategori produk, omzet & tren, stok menipis/habis, restok
 *    - MIS : agregasi omzet 7 hari (mis_penjualan_harian) & peringatan stok
 *    - OAS : dokumen pemasok/pengadaan dan tugas terkait stok/pemasok
 *    - KMS : SOP / knowledge yang relevan dengan penerimaan barang & pemasok
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
const KUNCI = 'pemasok|supplier|retur|stok|stock|penerimaan|gudang|fifo|kedaluwarsa|chiller|restok|opname';

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
    $mis = [
        'omzet_7' => $o7, 'omzet_7_lalu' => $o7l, 'tren_7' => $o7l > 0 ? round(($o7 - $o7l) / $o7l * 100, 1) : null,
        'jumlah_peringatan' => count($alerts),
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
    ];

    /* ---------- KMS ---------- */
    $kms = [
        'artikel' => array_map(fn($r) => ['id' => (int) $r['id'], 'judul' => $r['judul'], 'kategori' => $r['kategori'], 'dilihat' => (int) $r['dilihat'],
                                          'feedback' => (int) $r['fb'], 'membantu' => $r['fb'] ? (int) round($r['ya'] / $r['fb'] * 100) : null],
            rows("SELECT a.id, a.judul, k.nama kategori, a.dilihat, COUNT(f.id) fb, COALESCE(SUM(f.membantu=1),0) ya
                  FROM artikel_kms a JOIN kategori_kms k ON k.id=a.id_kategori LEFT JOIN feedback_kms f ON f.id_artikel=a.id
                  WHERE a.status='published' AND (a.judul REGEXP ? OR a.ringkasan REGEXP ?)
                  GROUP BY a.id, a.judul, k.nama, a.dilihat ORDER BY a.dilihat DESC LIMIT 8", [KUNCI, KUNCI])),
    ];

    echo json_encode(['ok' => true, 'waktu' => date('Y-m-d H:i:s'), 'tps' => $tps, 'mis' => $mis, 'oas' => $oas, 'kms' => $kms],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'pesan' => 'Database MartOffice tidak dapat dibaca']);
}

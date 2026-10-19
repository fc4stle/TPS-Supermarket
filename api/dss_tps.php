<?php
/* ==========================================================
 *  API TPS UNTUK DSS
 *  Dipanggil oleh DSS (Flask) untuk mengambil kategori produk,
 *  omzet 30 hari, dan stok menipis dari database MartOffice.
 *  Hanya melayani permintaan bertanda tangan HMAC (SSO_SECRET).
 * ========================================================== */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/sso.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!api_valid('tps:kategori')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'pesan' => 'Tanda tangan API tidak valid']);
    exit;
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $produk = rows('SELECT kategori, MIN(ikon) ikon, COUNT(*) produk, SUM(stok < ?) menipis
                    FROM produk WHERE aktif=1 GROUP BY kategori', [STOK_MIN]);
    $jual = array_column(rows('SELECT p.kategori, SUM(d.subtotal) omzet, SUM(d.qty) qty
                    FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi JOIN produk p ON p.id=d.id_produk
                    WHERE t.waktu >= CURDATE() - INTERVAL 29 DAY GROUP BY p.kategori'), null, 'kategori');
    $out = [];
    foreach ($produk as $p) {
        $out[] = [
            'nama'    => $p['kategori'],
            'produk'  => (int) $p['produk'],
            'menipis' => (int) $p['menipis'],
            'omzet'   => (float) ($jual[$p['kategori']]['omzet'] ?? 0),
            'qty'     => (int) ($jual[$p['kategori']]['qty'] ?? 0),
        ];
    }
    usort($out, fn($a, $b) => $b['omzet'] <=> $a['omzet']);
    echo json_encode(['ok' => true, 'waktu' => date('Y-m-d H:i:s'), 'kategori' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'pesan' => 'Database MartOffice tidak dapat dibaca']);
}

<?php
/* ==========================================================
 *  BACK END EXPERT SYSTEM (ES) MARTOFFICE
 *  Mesin inferensi forward chaining + Certainty Factor.
 *
 *  Menu 1 · REKOMENDASI (pembeli)  : jawaban pembeli → produk yang paling tepat
 *  Menu 2 · ORDER (manajer)        : data TPS/OAS/KMS/MIS/DSS → kapan order barang
 *
 *  Terhubung dengan:
 *   TPS : produk, stok, transaksi, detail_transaksi (fakta produk, penjualan, pola hari)
 *   OAS : tugas & agenda (tugas order/permintaan dibuat ES, agenda stock opname dibaca ES)
 *   KMS : artikel_kms sebagai sumber setiap aturan; SOP kedaluwarsa untuk produk segar
 *   MIS : mis_penjualan_harian (tren transaksi), batas peringatan stok, ringkasan ES di dashboard
 *   DSS : supplier terpilih + lama pengiriman, kandidat promo, paket bundling (api /api/es)
 *
 *  Rumus CF:
 *   CF aturan          = CF pakar × CF jawaban terkecil pada syaratnya
 *   CF gabungan        = 1 − Π(1 − CFi)        (setara CF1 + CF2 × (1 − CF1))
 *   Menu 1 · CF produk = CF anjuran × (1 − CF hindaran), anjuran per aturan = CF aturan × bobot tag produk
 *   Menu 2 · kesimpulan = CF gabungan tertinggi (seri → yang paling mendesak)
 * ========================================================== */

const ES_MUDAH_RUSAK = ['Buah & Sayur', 'Roti & Bakery', 'Daging & Ikan', 'Susu & Olahan'];   // SOP Kedaluwarsa & FIFO (KMS)

/* ---------------------------------------------------------------- umum */
/** true bila tabel ES sudah dibuat (es_database.sql sudah diimpor). */
function es_tersedia(): bool
{
    static $ada = null;
    if ($ada === null) $ada = (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='es_order_analisis'") > 0;
    return $ada;
}

function es_set(): array
{
    static $s = null;
    if ($s === null) $s = array_map('floatval', array_column(rows('SELECT kunci, nilai FROM es_pengaturan'), 'nilai', 'kunci'));
    return $s;
}

function es_cf_gabung(array $cf): float
{
    $sisa = 1.0;
    foreach ($cf as $c) $sisa *= (1 - min(max((float) $c, 0), 1));
    return 1 - $sisa;
}

function es_menu_id(string $kode): int
{
    $id = (int) val('SELECT id FROM es_menu WHERE kode=? AND aktif=1', [$kode]);
    if (!$id) throw new RuntimeException("Menu ES $kode tidak aktif");
    return $id;
}

function es_menu_daftar(): array
{
    return array_map(fn($m) => ['aktif' => (bool) $m['aktif']] + $m,
        rows('SELECT id, kode, nama, deskripsi, metode, pengguna, aktif FROM es_menu ORDER BY urutan'));
}

/** Pertanyaan beserta pilihan jawaban satu menu. */
function es_pertanyaan(string $menu = 'REKOMENDASI'): array
{
    $q = rows('SELECT q.id, q.kode, q.pertanyaan, q.bantuan, q.wajib FROM es_pertanyaan q JOIN es_menu m ON m.id=q.id_menu
               WHERE m.kode=? ORDER BY q.urutan', [$menu]);
    foreach ($q as &$x) {
        $x['pilihan'] = rows('SELECT kode_fakta, label FROM es_pilihan WHERE id_pertanyaan=? ORDER BY urutan', [$x['id']]);
        $x['wajib'] = (bool) $x['wajib'];
    }
    return $q;
}

/** Aturan aktif satu menu beserta daftar syaratnya. */
function es_aturan(string $menu): array
{
    $a = rows('SELECT a.id, a.kode, a.aksi, a.utama, a.kode_tag, a.kode_kesimpulan, a.cf_pakar, a.penjelasan, k.judul sumber_kms, a.id_artikel
               FROM es_aturan a JOIN es_menu m ON m.id=a.id_menu LEFT JOIN artikel_kms k ON k.id=a.id_artikel
               WHERE m.kode=? AND a.aktif=1 ORDER BY a.kode', [$menu]);
    $syarat = [];
    foreach (rows('SELECT s.id_aturan, s.kode_fakta FROM es_aturan_syarat s JOIN es_aturan a ON a.id=s.id_aturan JOIN es_menu m ON m.id=a.id_menu WHERE m.kode=?', [$menu]) as $s)
        $syarat[$s['id_aturan']][] = $s['kode_fakta'];
    foreach ($a as &$x) {
        $x['syarat'] = $syarat[$x['id']] ?? [];
        $x['cf_pakar'] = (float) $x['cf_pakar'];
    }
    return $a;
}

/** Forward chaining: aturan yang semua syaratnya ada di fakta. $fakta = [kode => cf pengguna]. */
function es_aturan_aktif(array $aturan, array $fakta): array
{
    $aktif = [];
    foreach ($aturan as $a) {
        if (!$a['syarat']) continue;
        $cf = [];
        foreach ($a['syarat'] as $f) {
            if (!array_key_exists($f, $fakta)) continue 2;
            $cf[] = (float) $fakta[$f];
        }
        $a['cf_aturan'] = round($a['cf_pakar'] * min($cf), 4);
        $aktif[] = $a;
    }
    return $aktif;
}

/** Data produk untuk ES: stok TPS, status MIS, info DSS (view v_es_produk). */
function es_produk(): array
{
    $p = [];
    foreach (rows('SELECT * FROM v_es_produk') as $r) $p[(int) $r['id']] = $r;
    return $p;
}

/* ================================================================
 *  MENU 1 · REKOMENDASI PRODUK UNTUK PEMBELI
 * ================================================================ */

/**
 * Menjalankan inferensi rekomendasi.
 * @param array $fakta [kode_fakta => cf_pengguna (0..1)]
 * @return array aturan_aktif, produk (semua yang dinilai), rekomendasi (berstok), habis (cocok tapi stok 0)
 */
function es_rekomendasi(array $fakta): array
{
    $set = es_set();
    $aktif = es_aturan_aktif(es_aturan('REKOMENDASI'), $fakta);
    $tag = [];
    foreach (rows('SELECT id_produk, kode_tag, bobot FROM es_produk_tag') as $t) $tag[(int) $t['id_produk']][$t['kode_tag']] = (float) $t['bobot'];
    $produk = es_produk();

    // paket bundling dari DSS: produk → pasangan
    $paket = [];
    foreach (rows("SELECT s.id_produk a, s.id_produk_2 b, s.nilai lift, pa.nama na, pb.nama nb FROM es_dss_sinkron s
                   JOIN produk pa ON pa.id=s.id_produk JOIN produk pb ON pb.id=s.id_produk_2 WHERE s.jenis='bundling'") as $b) {
        $paket[(int) $b['a']][] = $b['nb'];
        $paket[(int) $b['b']][] = $b['na'];
    }

    $hasil = [];
    foreach ($tag as $pid => $tags) {
        if (!isset($produk[$pid])) continue;
        $anjur = $hindar = $utama = $alasan = $peringatan = $kode = [];
        foreach ($aktif as $a) {
            if (!isset($tags[$a['kode_tag']])) continue;
            $cf = $a['cf_aturan'] * $tags[$a['kode_tag']];
            $kode[] = $a['kode'];
            if ($a['aksi'] === 'anjurkan') {
                $anjur[] = $cf;
                $alasan[] = $a['penjelasan'];
                if ($a['utama']) $utama[] = $cf;
            } elseif ($a['aksi'] === 'hindari') {
                $hindar[] = $cf;
                $peringatan[] = $a['penjelasan'];
            }
        }
        if (!$utama) continue;                       // produk harus sesuai tujuan belanja
        $cfA = es_cf_gabung($anjur);
        $cfH = es_cf_gabung($hindar);
        $p = $produk[$pid];
        $hasil[] = [
            'id_produk' => $pid, 'sku' => $p['sku'], 'nama' => $p['nama'], 'ikon' => $p['ikon'], 'kategori' => $p['kategori'],
            'harga' => (float) $p['harga'], 'satuan' => $p['satuan'], 'stok' => (int) $p['stok'], 'status_mis' => $p['status_mis'],
            'cf_anjur' => round($cfA, 4), 'cf_hindari' => round($cfH, 4), 'cf_akhir' => round($cfA * (1 - $cfH), 4),
            'aturan' => array_values(array_unique($kode)), 'alasan' => array_values(array_unique($alasan)),
            'peringatan' => array_values(array_unique($peringatan)),
            'promo_dss' => $p['skor_promo_dss'] !== null, 'paket_dss' => $paket[$pid] ?? [],
        ];
    }
    usort($hasil, fn($x, $y) => $y['cf_akhir'] <=> $x['cf_akhir'] ?: $x['nama'] <=> $y['nama']);
    $lolos = array_filter($hasil, fn($h) => $h['cf_akhir'] >= $set['cf_min']);
    return [
        'aturan_aktif' => array_map(fn($a) => ['kode' => $a['kode'], 'aksi' => $a['aksi'], 'tag' => $a['kode_tag'], 'cf_pakar' => $a['cf_pakar'],
                                               'cf_aturan' => $a['cf_aturan'], 'penjelasan' => $a['penjelasan'], 'sumber_kms' => $a['sumber_kms']], $aktif),
        'produk' => $hasil,
        'rekomendasi' => array_slice(array_values(array_filter($lolos, fn($h) => $h['stok'] > 0)), 0, (int) $set['rekom_maks']),
        'habis' => array_values(array_filter($lolos, fn($h) => $h['stok'] <= 0)),
    ];
}

/** Memeriksa jawaban: semua pertanyaan wajib dijawab tepat satu pilihan yang sah. Mengembalikan [kode => cf]. */
function es_validasi_jawaban(array $jawaban, string $menu = 'REKOMENDASI'): array
{
    $fakta = [];
    foreach (es_pertanyaan($menu) as $q) {
        $boleh = array_column($q['pilihan'], 'kode_fakta');
        $dipilih = array_values(array_intersect($boleh, array_keys($jawaban)));
        if (count($dipilih) > 1) throw new InvalidArgumentException("Pertanyaan {$q['kode']} hanya boleh satu jawaban");
        if (!$dipilih) {
            if ($q['wajib']) throw new InvalidArgumentException("Pertanyaan {$q['kode']} belum dijawab: {$q['pertanyaan']}");
            continue;
        }
        $fakta[$dipilih[0]] = min(1, max(0.1, (float) $jawaban[$dipilih[0]]));
    }
    return $fakta;
}

/** Menyimpan konsultasi pembeli, hasil, dan permintaan produk habis. */
function es_konsultasi_simpan(int $pegawai, array $jawaban, string $pelanggan = '', string $catatan = ''): array
{
    $fakta = es_validasi_jawaban($jawaban);
    $r = es_rekomendasi($fakta);
    $db = db();
    $db->begin_transaction();
    try {
        q('INSERT INTO es_konsultasi (id_menu, id_pegawai, nama_pelanggan, catatan) VALUES (?,?,?,?)',
            [es_menu_id('REKOMENDASI'), $pegawai, mb_substr($pelanggan, 0, 80) ?: null, mb_substr($catatan, 0, 255) ?: null]);
        $kid = last_id();
        foreach ($fakta as $f => $cf) q('INSERT INTO es_konsultasi_fakta (id_konsultasi, kode_fakta, cf_pengguna) VALUES (?,?,?)', [$kid, $f, $cf]);
        foreach ($r['rekomendasi'] as $i => $h)
            q('INSERT INTO es_konsultasi_hasil (id_konsultasi, id_produk, peringkat, cf_akhir, alasan, stok_saat_itu) VALUES (?,?,?,?,?,?)',
                [$kid, $h['id_produk'], $i + 1, $h['cf_akhir'], mb_substr(implode('; ', $h['alasan']), 0, 500), $h['stok']]);
        foreach ($r['habis'] as $h) q('INSERT INTO es_permintaan (id_konsultasi, id_produk) VALUES (?,?)', [$kid, $h['id_produk']]);
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
    return ['id_konsultasi' => $kid, 'fakta' => $fakta] + $r;
}

function es_konsultasi_detail(int $id): ?array
{
    $k = row('SELECT k.*, g.nama pegawai FROM es_konsultasi k JOIN pegawai g ON g.id=k.id_pegawai WHERE k.id=?', [$id]);
    if (!$k) return null;
    $k['fakta'] = rows('SELECT kf.kode_fakta, f.nama, kf.cf_pengguna FROM es_konsultasi_fakta kf JOIN es_fakta f ON f.kode=kf.kode_fakta WHERE kf.id_konsultasi=? ORDER BY f.kode', [$id]);
    $k['hasil'] = rows('SELECT h.peringkat, h.cf_akhir, h.alasan, h.stok_saat_itu, h.dibeli, p.id id_produk, p.nama, p.harga, p.satuan
                        FROM es_konsultasi_hasil h JOIN produk p ON p.id=h.id_produk WHERE h.id_konsultasi=? ORDER BY h.peringkat', [$id]);
    $k['permintaan'] = rows('SELECT r.id, r.status, r.id_tugas, p.nama FROM es_permintaan r JOIN produk p ON p.id=r.id_produk WHERE r.id_konsultasi=?', [$id]);
    return $k;
}

/** Permintaan pembeli yang tidak terpenuhi → satu tugas OAS untuk staf gudang. */
function es_permintaan_tugaskan(int $oleh): array
{
    $baru = rows("SELECT p.id, p.nama, COUNT(*) n FROM es_permintaan r JOIN produk p ON p.id=r.id_produk WHERE r.status='baru' GROUP BY p.id, p.nama ORDER BY n DESC");
    if (!$baru) return ['dibuat' => false, 'pesan' => 'Tidak ada permintaan baru'];
    $staf = es_staf('Gudang');
    $isi = "Produk dicari pembeli lewat Expert System tetapi stoknya habis:\n"
        . implode("\n", array_map(fn($b) => "- {$b['nama']}: dicari {$b['n']}x", $baru))
        . "\nCek stok gudang dan koordinasikan order ke supplier.";
    q("INSERT INTO tugas (judul, deskripsi, id_pemberi, id_staff, prioritas, tenggat) VALUES (?,?,?,?, 'tinggi', ?)",
        [mb_substr('[ES] Permintaan pembeli: ' . implode(', ', array_column(array_slice($baru, 0, 3), 'nama')), 0, 120), $isi, $oleh, $staf, date('Y-m-d', strtotime('+1 day'))]);
    $tid = last_id();
    q("UPDATE es_permintaan SET status='ditugaskan', id_tugas=? WHERE status='baru'", [$tid]);
    return ['dibuat' => true, 'id_tugas' => $tid, 'produk' => count($baru), 'staf' => val('SELECT nama FROM pegawai WHERE id=?', [$staf])];
}

/** Permintaan otomatis selesai setelah produk kembali berstok (direstok lewat TPS). */
function es_permintaan_tutup(): int
{
    q("UPDATE es_permintaan r JOIN produk p ON p.id=r.id_produk SET r.status='selesai' WHERE r.status != 'selesai' AND p.stok > 0");
    return db()->affected_rows;
}

/** Staf aktif sesuai jabatan dengan beban tugas paling ringan. */
function es_staf(string $jabatan): int
{
    return (int) (val("SELECT g.id FROM pegawai g WHERE g.role='staff' AND g.aktif=1 AND g.jabatan LIKE ?
                       ORDER BY (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=g.id AND t.status!='selesai'), g.id LIMIT 1", ['%' . $jabatan . '%'])
        ?: val("SELECT g.id FROM pegawai g WHERE g.role='staff' AND g.aktif=1
                ORDER BY (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=g.id AND t.status!='selesai'), g.id LIMIT 1"));
}

/* ================================================================
 *  MENU 2 · WAKTU ORDER BARANG UNTUK MANAJER
 * ================================================================ */

/** Data pendukung bersama untuk menghitung fakta order semua produk. */
function es_order_konteks(): array
{
    mis_refresh();
    es_permintaan_tutup();
    $set = es_set();
    $jual = [];
    foreach (rows("SELECT d.id_produk,
                      SUM(CASE WHEN t.waktu >= CURDATE() - INTERVAL 29 DAY THEN d.qty ELSE 0 END) q30,
                      SUM(CASE WHEN t.waktu >= CURDATE() - INTERVAL 6 DAY THEN d.qty ELSE 0 END) q7,
                      SUM(CASE WHEN DAYOFWEEK(t.waktu) IN (1,7) THEN d.qty ELSE 0 END) qlibur,
                      SUM(CASE WHEN DAYOFWEEK(t.waktu) NOT IN (1,7) THEN d.qty ELSE 0 END) qkerja
                   FROM detail_transaksi d JOIN transaksi t ON t.id=d.id_transaksi
                   WHERE t.waktu >= CURDATE() - INTERVAL 55 DAY GROUP BY d.id_produk") as $r) $jual[(int) $r['id_produk']] = $r;
    // MIS: transaksi 7 hari terakhir vs 7 hari sebelumnya (hari tanpa transaksi = 0)
    $a = (int) val('SELECT COALESCE(SUM(jumlah_transaksi),0) FROM mis_penjualan_harian WHERE tanggal BETWEEN CURDATE()-INTERVAL 6 DAY AND CURDATE()');
    $b = (int) val('SELECT COALESCE(SUM(jumlah_transaksi),0) FROM mis_penjualan_harian WHERE tanggal BETWEEN CURDATE()-INTERVAL 13 DAY AND CURDATE()-INTERVAL 7 DAY');
    $opname = row("SELECT judul, mulai FROM agenda WHERE judul LIKE '%opname%' AND DATE(mulai) BETWEEN CURDATE() AND CURDATE() + INTERVAL 2 DAY ORDER BY mulai LIMIT 1");
    return [
        'set' => $set,
        'jual' => $jual,
        'mis_tren' => $b ? round(($a - $b) / $b * 100, 1) : null,
        'opname' => $opname,
        'lead' => array_column(rows("SELECT kategori, label, lead_hari FROM es_dss_sinkron WHERE jenis='supplier'"), null, 'kategori'),
        'promo' => array_map('intval', array_column(rows("SELECT id_produk FROM es_dss_sinkron WHERE jenis='promo'"), 'id_produk')),
        'bundling' => array_map('intval', array_merge(array_column(rows("SELECT id_produk FROM es_dss_sinkron WHERE jenis='bundling' AND nilai > 1"), 'id_produk'),
                                                     array_column(rows("SELECT id_produk_2 FROM es_dss_sinkron WHERE jenis='bundling' AND nilai > 1"), 'id_produk_2'))),
        'tugas' => array_column(rows("SELECT CONCAT(judul, ' ', COALESCE(deskripsi,'')) teks FROM tugas
                                      WHERE status != 'selesai' AND (judul LIKE '[ES]%' OR judul LIKE '[DSS]%')"), 'teks'),
        'permintaan' => array_column(rows("SELECT id_produk, COUNT(*) n FROM es_permintaan WHERE status != 'selesai' GROUP BY id_produk"), 'n', 'id_produk'),
    ];
}

/** Menghitung fakta otomatis menu ORDER untuk satu produk. Mengembalikan [info, fakta => bukti]. */
function es_order_fakta(array $p, array $k): array
{
    $set = $k['set'];
    $id = (int) $p['id'];
    $j = $k['jual'][$id] ?? ['q30' => 0, 'q7' => 0, 'qlibur' => 0, 'qkerja' => 0];
    $d30 = $j['q30'] / 30;
    $d7 = $j['q7'] / 7;
    $stok = (int) $p['stok'];
    $sup = $k['lead'][$p['kategori']] ?? null;
    $lead = (float) ($sup['lead_hari'] ?? $set['lead_bawaan']);
    $aman = $set['stok_aman'];
    $segar = in_array($p['kategori'], ES_MUDAH_RUSAK, true);
    $sisa = $d30 > 0 ? $stok / $d30 : null;
    $batas = $lead + $aman;                                    // hari stok minimum saat order (titik pesan ulang)
    $f = [];
    $fmt = fn($x, $n = 1) => number_format((float) $x, $n, ',', '.');

    if ($stok <= 0) $f['O01'] = 'stok 0 ' . $p['satuan'];
    if ($d30 <= 0) {
        if ($stok > 0) $f['O16'] = 'terjual 0 dalam 30 hari';
    } else {
        if ($sisa <= $batas) $f['O02'] = "sisa {$fmt($sisa)} hari ≤ lead {$fmt($lead, 0)} + aman {$fmt($aman, 0)} hari";
        elseif ($sisa <= $batas + 3) $f['O03'] = "sisa {$fmt($sisa)} hari, batas pesan {$fmt($sisa - $batas)} hari lagi";
        else $f['O04'] = "sisa {$fmt($sisa)} hari, batas pesan {$fmt($sisa - $batas)} hari lagi";
        // tren hanya dinilai bila produk masih berstok (penjualan 0 karena stok kosong bukan tanda permintaan turun)
        if ($stok > 0 && $d7 >= $d30 * (1 + $set['naik_persen'] / 100)) $f['O05'] = "jual 7 hari {$fmt($d7)}/hari vs 30 hari {$fmt($d30)}/hari";
        if ($stok > 0 && $d7 <= $d30 * (1 - $set['turun_persen'] / 100)) $f['O06'] = "jual 7 hari {$fmt($d7)}/hari vs 30 hari {$fmt($d30)}/hari";
        // akhir pekan sebelum barang datang: hari ini s/d hari ke-lead mencakup Sabtu/Minggu
        $libur = 0;
        for ($i = 0; $i <= (int) ceil($lead); $i++) if (in_array((int) date('N', strtotime("+$i day")), [6, 7], true)) $libur++;
        $rLibur = $j['qlibur'] / 16;                           // 8 minggu × 2 hari
        $rKerja = $j['qkerja'] / 40;                           // 8 minggu × 5 hari
        if ($libur && $rLibur > $rKerja * 1.1) $f['O07'] = "jual akhir pekan {$fmt($rLibur)}/hari vs hari kerja {$fmt($rKerja)}/hari";
    }
    if ($segar) $f['O08'] = 'kategori ' . $p['kategori'] . ' (SOP Kedaluwarsa & FIFO)';
    if ($stok < STOK_MIN) $f['O09'] = "stok {$stok} < batas MIS " . STOK_MIN;
    if ($k['mis_tren'] !== null && $k['mis_tren'] >= $set['mis_naik']) $f['O10'] = "transaksi toko naik {$fmt($k['mis_tren'])}% (MIS)";
    if (in_array($id, $k['promo'], true)) $f['O11'] = 'kandidat promo DSS';
    if (in_array($id, $k['bundling'], true)) $f['O12'] = 'paket bundling DSS (lift > 1)';
    if ($k['opname']) $f['O13'] = $k['opname']['judul'] . ' · ' . date('d/m H:i', strtotime($k['opname']['mulai']));
    foreach ($k['tugas'] as $t) if (mb_stripos($t, $p['nama']) !== false) { $f['O14'] = 'tugas order masih terbuka di OAS'; break; }
    if (!empty($k['permintaan'][$id])) $f['O15'] = "dicari pembeli {$k['permintaan'][$id]}x (ES)";

    $siklus = $segar ? min($set['siklus'], $set['siklus_segar']) : $set['siklus'];
    return [[
        'd30' => $d30, 'd7' => $d7, 'stok' => $stok, 'lead' => $lead, 'aman' => $aman, 'siklus' => $siklus, 'sisa' => $sisa,
        'batas' => $batas, 'supplier' => $sup['label'] ?? null, 'segar' => $segar,
    ], $f];
}

/** Inferensi menu ORDER untuk satu produk. */
function es_order_inferensi(array $fakta, array $aturan, array $kesimpulan): array
{
    $aktif = es_aturan_aktif($aturan, array_fill_keys(array_keys($fakta), 1.0));
    $per = [];
    foreach ($aktif as $a) $per[$a['kode_kesimpulan']][] = $a['cf_aturan'];
    $cf = array_map('es_cf_gabung', $per);
    uksort($cf, fn($x, $y) => ($cf[$y] <=> $cf[$x]) ?: ($kesimpulan[$x]['prioritas'] <=> $kesimpulan[$y]['prioritas']));
    $pilih = array_key_first($cf) ?? 'K04';
    return ['kesimpulan' => $pilih, 'cf' => round($cf[$pilih] ?? 0, 4), 'cf_semua' => array_map(fn($v) => round($v, 4), $cf), 'aturan' => $aktif];
}

/** Tanggal order dan jumlah pesan sesuai kesimpulan. */
function es_order_jadwal(string $kes, array $i): array
{
    $hari = match ($kes) {
        'K01' => 0, 'K02' => 1,
        'K03' => max(2, (int) floor(($i['sisa'] ?? 0) - $i['batas'])),
        'K04' => max(3, (int) floor(($i['sisa'] ?? 3) - $i['batas'])),
        default => null,
    };
    if ($hari === null) return [null, 0];
    $target = $i['d30'] * ($i['lead'] + $i['aman'] + $i['siklus']);
    $stokSaatOrder = max(0, $i['stok'] - $i['d30'] * $hari);
    $qty = $kes === 'K04' ? 0 : max(0, (int) ceil($target - $stokSaatOrder));
    return [date('Y-m-d', strtotime("+$hari day")), $qty];
}

/** Menjalankan analisis waktu order untuk semua produk aktif dan menyimpannya. */
function es_order_jalankan(?int $oleh = null, bool $sinkron = true): array
{
    $dss = $sinkron ? es_sinkron_dss() : ['ok' => false, 'pesan' => 'dilewati'];
    $k = es_order_konteks();
    $aturan = es_aturan('ORDER');
    $kes = array_column(rows("SELECT kode, nama, tindakan, prioritas, warna FROM es_kesimpulan"), null, 'kode');
    $nama = array_column(rows("SELECT kode, nama FROM es_fakta"), 'nama', 'kode');
    $out = [];
    $db = db();
    $db->begin_transaction();
    try {
        foreach (rows('SELECT id, sku, nama, kategori, satuan, harga, stok FROM produk WHERE aktif=1 ORDER BY nama') as $p) {
            [$info, $fakta] = es_order_fakta($p, $k);
            $inf = es_order_inferensi($fakta, $aturan, $kes);
            [$tgl, $qty] = es_order_jadwal($inf['kesimpulan'], $info);
            $alasan = implode('; ', array_unique(array_map(fn($a) => $a['penjelasan'], array_filter($inf['aturan'], fn($a) => $a['kode_kesimpulan'] === $inf['kesimpulan']))));
            q('INSERT INTO es_order_analisis (id_produk, kode_kesimpulan, cf, tanggal_order, qty_saran, stok, rata_harian, sisa_hari, lead_hari, supplier, aturan, alasan, dibuat_oleh)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [(int) $p['id'], $inf['kesimpulan'], $inf['cf'], $tgl, $qty, $info['stok'], round($info['d30'], 2),
                 $info['sisa'] === null ? null : round($info['sisa'], 2), $info['lead'], $info['supplier'],
                 implode(', ', array_column($inf['aturan'], 'kode')) ?: '-', $alasan ?: 'Tidak ada aturan yang aktif', $oleh]);
            $aid = last_id();
            foreach ($fakta as $f => $bukti) q('INSERT INTO es_order_fakta (id_analisis, kode_fakta, nilai) VALUES (?,?,?)', [$aid, $f, mb_substr($bukti, 0, 120)]);
            $out[] = [
                'id_analisis' => $aid, 'id_produk' => (int) $p['id'], 'sku' => $p['sku'], 'nama' => $p['nama'], 'kategori' => $p['kategori'],
                'kesimpulan' => $inf['kesimpulan'], 'keputusan' => $kes[$inf['kesimpulan']]['nama'], 'tindakan' => $kes[$inf['kesimpulan']]['tindakan'],
                'prioritas' => (int) $kes[$inf['kesimpulan']]['prioritas'], 'cf' => $inf['cf'], 'cf_semua' => $inf['cf_semua'],
                'tanggal_order' => $tgl, 'qty_saran' => $qty, 'satuan' => $p['satuan'], 'stok' => $info['stok'],
                'rata_harian' => round($info['d30'], 2), 'sisa_hari' => $info['sisa'] === null ? null : round($info['sisa'], 2),
                'lead_hari' => $info['lead'], 'supplier' => $info['supplier'],
                'fakta' => array_map(fn($f, $b) => ['kode' => $f, 'nama' => $nama[$f] ?? $f, 'bukti' => $b], array_keys($fakta), $fakta),
                'aturan' => array_map(fn($a) => ['kode' => $a['kode'], 'kesimpulan' => $a['kode_kesimpulan'], 'cf' => $a['cf_aturan'], 'penjelasan' => $a['penjelasan']], $inf['aturan']),
                'alasan' => $alasan,
            ];
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
    usort($out, fn($a, $b) => $a['prioritas'] <=> $b['prioritas'] ?: strcmp((string) $a['tanggal_order'], (string) $b['tanggal_order']) ?: $b['cf'] <=> $a['cf']);
    return ['dss' => $dss, 'mis_tren' => $k['mis_tren'], 'opname' => $k['opname'], 'hasil' => $out,
            'ringkasan' => array_count_values(array_column($out, 'kesimpulan'))];
}

/** Keputusan order → tugas OAS untuk staf gudang. $ids kosong = semua K01/K02 terbaru yang belum ditugaskan. */
function es_order_tugaskan(int $oleh, array $ids = []): array
{
    $sql = "SELECT * FROM v_es_order_terbaru WHERE status='saran' AND qty_saran > 0 AND kode_kesimpulan IN ('K01','K02','K03')";
    $baris = $ids ? array_values(array_filter(rows($sql), fn($r) => in_array((int) $r['id'], $ids, true)))
                  : rows($sql . " AND kode_kesimpulan IN ('K01','K02') ORDER BY prioritas, nama");
    if (!$baris) return ['dibuat' => 0, 'pesan' => 'Tidak ada keputusan order yang perlu ditugaskan'];
    $staf = es_staf('Gudang');
    $dibuat = [];
    foreach ($baris as $r) {
        $prio = in_array($r['kode_kesimpulan'], ['K01', 'K02'], true) ? 'tinggi' : 'sedang';
        $isi = "Keputusan Expert System: {$r['kesimpulan']} (CF " . number_format($r['cf'] * 100, 1, ',', '.') . "%).\n"
            . "Pesan {$r['qty_saran']} {$r['satuan']} {$r['nama']} ke " . ($r['supplier'] ?: 'supplier kategori ' . $r['kategori'])
            . " (lead {$r['lead_hari']} hari).\nAlasan: {$r['alasan']}.\nTerapkan FIFO saat barang diterima (KMS).";
        q("INSERT INTO tugas (judul, deskripsi, id_pemberi, id_staff, prioritas, tenggat) VALUES (?,?,?,?,?,?)",
            [mb_substr("[ES] Order {$r['nama']} {$r['qty_saran']} {$r['satuan']}", 0, 120), $isi, $oleh, $staf, $prio, $r['tanggal_order'] ?: date('Y-m-d')]);
        $tid = last_id();
        q("UPDATE es_order_analisis SET status='ditugaskan', id_tugas=? WHERE id=?", [$tid, $r['id']]);
        $dibuat[] = ['id_analisis' => (int) $r['id'], 'produk' => $r['nama'], 'id_tugas' => $tid, 'tenggat' => $r['tanggal_order']];
    }
    return ['dibuat' => count($dibuat), 'staf' => val('SELECT nama FROM pegawai WHERE id=?', [$staf]), 'tugas' => $dibuat];
}

/* ================================================================
 *  INTEGRASI DSS & MIS
 * ================================================================ */

/** Mengambil hasil DSS (supplier + lead time, promo, bundling, parameter restok) lewat /api/es. */
function es_sinkron_dss(): array
{
    if (!function_exists('dss_hidup') || !dss_hidup()) return ['ok' => false, 'pesan' => 'DSS tidak berjalan; memakai data DSS terakhir'];
    $ts = time();
    $home = martoffice_home();                        // alamat MartOffice agar DSS dapat mengambil data TPS/MIS terbaru
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $json = @file_get_contents(DSS_API . '/api/es?' . http_build_query(['home' => $home, 'ts' => $ts, 'sig' => api_sig('dss:es|' . $home, $ts)]), false, $ctx);
    $d = $json ? json_decode($json, true) : null;
    if (!is_array($d) || empty($d['ok'])) return ['ok' => false, 'pesan' => 'Respons DSS tidak valid'];
    $db = db();
    $db->begin_transaction();
    try {
        q('DELETE FROM es_dss_sinkron');
        foreach ($d['supplier'] as $s)
            q("INSERT INTO es_dss_sinkron (jenis, kategori, label, nilai, lead_hari, keterangan) VALUES ('supplier',?,?,?,?, 'Supplier terpilih SAW')",
                [$s['kategori'], $s['supplier'], $s['v'], $s['lead']]);
        foreach ($d['promo'] as $s)
            q("INSERT INTO es_dss_sinkron (jenis, id_produk, label, nilai, keterangan) SELECT 'promo', id, ?, ?, 'Skor SAW kasus Produk Promo' FROM produk WHERE id=?",
                ['Produk promo DSS: ' . $s['nama'], $s['skor'], $s['id_produk']]);
        foreach ($d['bundling'] as $s)
            q("INSERT INTO es_dss_sinkron (jenis, id_produk, id_produk_2, label, nilai, keterangan) SELECT 'bundling', a.id, b.id, ?, ?, ? FROM produk a, produk b WHERE a.id=? AND b.id=?",
                ['Paket ' . $s['label'], $s['lift'], 'Lift analisis keranjang · harga paket ' . rp($s['harga_paket']), $s['a'], $s['b']]);
        foreach ($d['restok'] as $s)
            q("INSERT INTO es_dss_sinkron (jenis, id_produk, label, nilai, keterangan) SELECT 'restok', id, ?, ?, ? FROM produk WHERE id=?",
                ['Rencana restok DSS: ' . $s['nama'], $s['saran'], $s['status'], $s['id_produk']]);
        foreach ($d['pengaturan'] as $kunci => $v) q("UPDATE es_pengaturan SET nilai=?, sumber='DSS' WHERE kunci=?", [$v, $kunci]);
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        return ['ok' => false, 'pesan' => 'Gagal menyimpan data DSS'];
    }
    return ['ok' => true, 'waktu' => $d['waktu'], 'supplier' => count($d['supplier']), 'promo' => count($d['promo']),
            'bundling' => count($d['bundling']), 'restok' => count($d['restok'])];
}

/** Ringkasan ES untuk MIS dan DSS. */
function es_ringkasan(): array
{
    es_permintaan_tutup();
    return [
        'konsultasi_hari_ini' => (int) val('SELECT COUNT(*) FROM es_konsultasi WHERE DATE(waktu)=CURDATE()'),
        'konsultasi_7_hari' => (int) val('SELECT COUNT(*) FROM es_konsultasi WHERE waktu >= CURDATE() - INTERVAL 6 DAY'),
        'produk_teratas' => rows('SELECT p.id, p.nama, COUNT(*) n FROM es_konsultasi_hasil h JOIN produk p ON p.id=h.id_produk
                                  JOIN es_konsultasi k ON k.id=h.id_konsultasi WHERE k.waktu >= CURDATE() - INTERVAL 29 DAY
                                  GROUP BY p.id, p.nama ORDER BY n DESC, p.nama LIMIT 5'),
        'permintaan_tertunda' => rows("SELECT p.id, p.nama, COUNT(*) n, MIN(r.status) status FROM es_permintaan r JOIN produk p ON p.id=r.id_produk
                                       WHERE r.status != 'selesai' GROUP BY p.id, p.nama ORDER BY n DESC"),
        'order' => rows('SELECT kode, nama, warna, jumlah_produk, total_qty, sudah_ditugaskan FROM v_es_mis_order ORDER BY prioritas'),
        'order_mendesak' => rows("SELECT id_produk, nama, kesimpulan, qty_saran, satuan, tanggal_order, status FROM v_es_order_terbaru
                                  WHERE kode_kesimpulan IN ('K01','K02') ORDER BY prioritas, nama"),
        'analisis_terakhir' => val('SELECT MAX(dianalisis) FROM es_order_analisis'),
    ];
}

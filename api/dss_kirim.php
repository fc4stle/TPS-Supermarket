<?php
/* ==========================================================
 *  API TINDAK LANJUT DSS → MARTOFFICE (OAS)
 *  Keputusan yang disetujui manajer di DSS dikirim balik ke OAS
 *  sebagai pengumuman, tugas untuk staf, atau agenda. Hasilnya
 *  langsung tampil di modul OAS, dipantau MIS (penyelesaian
 *  tugas & peringatan tugas terlambat), dan dibaca lagi oleh DSS.
 *
 *  Permintaan: POST JSON, ditandatangani HMAC-SHA256 dengan nama
 *  "dss:kirim:<sha256 isi body>" sehingga isi tidak bisa diubah.
 * ========================================================== */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/sso.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jawab(int $kode, array $data)
{
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$body = file_get_contents('php://input') ?: '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !api_valid('dss:kirim:' . hash('sha256', $body))) {
    jawab(401, ['ok' => false, 'pesan' => 'Tanda tangan API tidak valid']);
}
$in = json_decode($body, true);
if (!is_array($in)) jawab(400, ['ok' => false, 'pesan' => 'Format JSON tidak valid']);

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    // penulis = manajer yang menyetujui keputusan di DSS (username SSO), cadangan: manajer aktif pertama
    $penulis = (int) val("SELECT id FROM pegawai WHERE username=? AND role IN ('manajer','admin') AND aktif=1", [(string) ($in['oleh'] ?? '')])
        ?: (int) val("SELECT id FROM pegawai WHERE role='manajer' AND aktif=1 ORDER BY id LIMIT 1");
    $judul = mb_substr(trim((string) ($in['judul'] ?? '')), 0, 120);
    $isi = trim((string) ($in['isi'] ?? ''));
    if ($judul === '' || !$penulis) jawab(422, ['ok' => false, 'pesan' => 'Judul atau penulis tidak valid']);

    switch ($in['jenis'] ?? '') {
        case 'pengumuman':
            q('INSERT INTO pengumuman(judul,isi,penting,id_penulis) VALUES(?,?,?,?)', [$judul, $isi ?: $judul, empty($in['penting']) ? 0 : 1, $penulis]);
            jawab(200, ['ok' => true, 'jenis' => 'pengumuman', 'id' => db()->insert_id, 'url' => 'oas/pengumuman.php']);

        case 'tugas':
            $staff = (int) ($in['id_staff'] ?? 0);
            if (!val("SELECT id FROM pegawai WHERE id=? AND role='staff' AND aktif=1", [$staff])) {
                // cari staf menurut jabatan (mis. "gudang", "kasir"); bila tidak ada, staf dengan tugas aktif paling sedikit
                $staff = (int) val("SELECT g.id FROM pegawai g WHERE g.role='staff' AND g.aktif=1 AND g.jabatan LIKE ? ORDER BY
                        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=g.id AND t.status!='selesai'), g.id LIMIT 1", ['%' . ($in['jabatan'] ?? '') . '%'])
                    ?: (int) val("SELECT g.id FROM pegawai g WHERE g.role='staff' AND g.aktif=1 ORDER BY
                        (SELECT COUNT(*) FROM tugas t WHERE t.id_staff=g.id AND t.status!='selesai'), g.id LIMIT 1");
            }
            if (!$staff) jawab(422, ['ok' => false, 'pesan' => 'Tidak ada staf aktif']);
            $tenggat = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['tenggat'] ?? '')) ? $in['tenggat'] : date('Y-m-d', strtotime('+2 day'));
            $prio = in_array($in['prioritas'] ?? '', ['rendah', 'sedang', 'tinggi'], true) ? $in['prioritas'] : 'sedang';
            q('INSERT INTO tugas(judul,deskripsi,id_pemberi,id_staff,prioritas,tenggat) VALUES(?,?,?,?,?,?)',
                [mb_substr('[DSS] ' . $judul, 0, 120), $isi, $penulis, $staff, $prio, $tenggat]);
            jawab(200, ['ok' => true, 'jenis' => 'tugas', 'id' => db()->insert_id, 'url' => 'oas/tugas.php',
                'staff' => val('SELECT nama FROM pegawai WHERE id=?', [$staff])]);

        case 'agenda':
            $mulai = strtotime((string) ($in['mulai'] ?? ''));
            if (!$mulai) jawab(422, ['ok' => false, 'pesan' => 'Waktu mulai agenda tidak valid']);
            $untuk = in_array($in['untuk'] ?? '', ['semua', 'manajemen', 'staff'], true) ? $in['untuk'] : 'semua';
            q('INSERT INTO agenda(judul,deskripsi,lokasi,mulai,selesai,untuk,id_pembuat) VALUES(?,?,?,?,?,?,?)',
                [$judul, $isi, mb_substr((string) ($in['lokasi'] ?? 'MartOffice'), 0, 100), date('Y-m-d H:i:s', $mulai), null, $untuk, $penulis]);
            jawab(200, ['ok' => true, 'jenis' => 'agenda', 'id' => db()->insert_id, 'url' => 'oas/agenda.php']);
    }
    jawab(422, ['ok' => false, 'pesan' => 'Jenis tindak lanjut tidak dikenal']);
} catch (Throwable $ex) {
    jawab(500, ['ok' => false, 'pesan' => 'Gagal menyimpan ke MartOffice']);
}

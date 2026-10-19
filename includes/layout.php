<?php
/* ==========================================================
 *  LAYOUT: sidebar + topbar + konten
 * ========================================================== */

function menu_items(string $active = ''): array
{
    $r = role();
    // Saat manajer berada di dalam MIS, sidebar berganti menjadi menu MIS
    if ($r === 'manajer' && strncmp($active, 'mis', 3) === 0 && function_exists('mis_menu')) return mis_menu();

    $all = ['admin', 'manajer', 'staff'];
    $boss = ['admin', 'manajer'];
    $uid = uid();

    $unread  = (int) val('SELECT COUNT(*) FROM pesan WHERE id_penerima=? AND dibaca=0', [$uid]);
    $review  = in_array($r, $boss) ? (int) val("SELECT COUNT(*) FROM artikel_kms WHERE status='menunggu_review'") : 0;
    $tugas   = $r === 'staff'
        ? (int) val("SELECT COUNT(*) FROM tugas WHERE id_staff=? AND status!='selesai'", [$uid])
        : (int) val("SELECT COUNT(*) FROM tugas WHERE status!='selesai'");
    $menipis = (int) val('SELECT COUNT(*) FROM produk WHERE aktif=1 AND stok<?', [STOK_MIN]);

    $groups = [
        ['Utama', [
            ['beranda', '🏠', 'Beranda', 'beranda.php', $all, 0],
            ['dashboard', '📊', 'Dashboard Saya', home_url(), $all, 0],
            ['mis', '📈', 'MIS · Kinerja Perusahaan', 'mis/index.php', ['manajer'], 0],
        ]],
        ['TPS · Transaksi', [
            ['kasir', '🧾', 'Kasir', 'tps/kasir.php', ['admin', 'staff'], 0],
            ['transaksi', '🗂️', $r === 'staff' ? 'Transaksi Saya' : 'Riwayat Transaksi', 'tps/transaksi.php', $all, 0],
            ['produk', '📦', $r === 'admin' ? 'Produk & Stok' : 'Stok Produk', 'tps/produk.php', $all, $menipis],
            ['laporan', '📊', 'Laporan Penjualan', 'tps/laporan.php', $boss, 0],
        ]],
        ['OAS · Perkantoran', [
            ['tugas', '📋', $r === 'staff' ? 'Tugas Saya' : 'Tugas Staff', 'oas/tugas.php', $all, $tugas],
            ['pengumuman', '📢', 'Pengumuman', 'oas/pengumuman.php', $all, 0],
            ['agenda', '📅', 'Agenda', 'oas/agenda.php', $all, 0],
            ['dokumen', '📁', 'Dokumen', 'oas/dokumen.php', $all, 0],
            ['pesan', '✉️', 'Pesan Internal', 'oas/pesan.php', $all, $unread],
        ]],
        ['KMS · Pengetahuan', [
            ['kms', '📚', 'Knowledge Center', 'kms/index.php', $all, 0],
            ['kms-tulis', '✍️', 'Tulis Knowledge', 'kms/tulis.php', $all, 0],
            ['kms-review', '📝', 'Review Knowledge', 'kms/index.php?tab=review', $boss, $review],
            ['kms-kategori', '🏷️', 'Kategori KMS', 'kms/kategori.php', $boss, 0],
        ]],
        ['Master', [
            ['users', '👥', 'User & Role', 'admin/users.php', ['admin'], 0],
        ]],
    ];
    $out = [];
    foreach ($groups as [$label, $items]) {
        $items = array_values(array_filter($items, fn($i) => in_array($r, $i[4], true)));
        if ($items) $out[] = [$label, $items];
    }
    return $out;
}

function page_start(string $title, string $active = '', string $subtitle = ''): void
{
    $u = me();
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛒</text></svg>">
</head>
<body>
<div class="shell">
  <aside class="side<?= strncmp($active, 'mis', 3) === 0 ? ' compact' : '' ?>" id="side">
    <a class="brand" href="<?= url('beranda.php') ?>">
      <span class="logo">🛒</span>
      <span><b>Mart</b><i>Office</i><small><?= strncmp($active, 'mis', 3) === 0 ? 'Management Info System' : 'Supermarket Suite' ?></small></span>
    </a>
    <nav class="menu">
      <?php foreach (menu_items($active) as [$label, $items]): ?>
        <div class="menu-label"><?= e($label) ?></div>
        <?php foreach ($items as [$key, $ic, $text, $href, , $count]): ?>
          <a href="<?= url($href) ?>" class="<?= $key === $active ? 'on' : '' ?>">
            <span class="mi"><?= $ic ?></span><span class="mt"><?= e($text) ?></span>
            <?php if ($count > 0): ?><span class="count"><?= $count ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="me">
        <span class="avatar"><?= e(inisial($u['nama'])) ?></span>
        <div><b><?= e($u['nama']) ?></b><small><?= e($u['jabatan'] ?: ucfirst($u['role'])) ?></small></div>
      </div>
      <a class="logout" href="<?= url('logout.php') ?>">⎋ Keluar</a>
    </div>
  </aside>
  <div class="side-backdrop" onclick="document.body.classList.remove('menu-open')"></div>

  <div class="main">
    <div class="promo-strip">
      <span>🥬 Segar setiap hari</span><span>🏷️ Promo minggu ini</span><span>🛒 Belanja lengkap</span><span>💚 Selamat bekerja, <?= e(explode(' ', $u['nama'])[0]) ?>!</span>
    </div>
    <header class="topbar">
      <button class="burger" type="button" onclick="document.body.classList.toggle('menu-open')" aria-label="Menu">☰</button>
      <div class="tb-title">
        <h1><?= e($title) ?></h1>
        <?php if ($subtitle): ?><p><?= $subtitle ?></p><?php endif; ?>
      </div>
      <div class="tb-right">
        <span class="today">📅 <?= tgl_panjang() ?></span>
        <span class="role-pill <?= e($u['role']) ?>"><?= e(ucfirst($u['role'])) ?></span>
      </div>
    </header>
    <main class="content">
      <?= render_flash() ?>
<?php
}

function page_end(): void
{
    ?>
    </main>
    <footer class="foot"><?= APP_NAME ?> © <?= date('Y') ?> · TPS · OAS · KMS terintegrasi · Native PHP + MySQL</footer>
  </div>
</div>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>
<?php
}

/** Kartu statistik. */
function stat_card(string $label, $value, string $icon, string $tone = 'g', string $href = '', string $note = ''): string
{
    $in = '<span class="stat-ic">' . $icon . '</span><div class="stat-body"><small>' . e($label) . '</small><strong>' . $value . '</strong>'
        . ($note ? '<em>' . $note . '</em>' : '') . '</div>';
    return $href
        ? '<a class="stat ' . $tone . '" href="' . url($href) . '">' . $in . '<span class="stat-go">→</span></a>'
        : '<div class="stat ' . $tone . '">' . $in . '</div>';
}

/** Kartu akses cepat. */
function quick(string $icon, string $title, string $desc, string $href, string $tone = 'g'): string
{
    return '<a class="quick ' . $tone . '" href="' . url($href) . '"><span class="q-ic">' . $icon . '</span><b>' . e($title) . '</b><small>' . e($desc) . '</small></a>';
}

/** Banner sapaan di dashboard. */
function hero(string $judul, string $teks, string $emoji = '🍎🥖🥛🧀🥕'): string
{
    return '<section class="hero-banner"><div><span class="hb-kicker">' . tgl_panjang() . '</span><h2>' . $judul . '</h2><p>' . $teks . '</p></div>'
        . '<div class="hb-fruit" aria-hidden="true">' . $emoji . '</div></section>';
}

/** Grafik batang sederhana (tanpa library). $data = [[label, nilai], ...] */
function bar_chart(array $data, bool $rupiah = true): string
{
    $max = max(array_merge([1], array_column($data, 1)));
    $b = $l = '';
    $padat = count($data) > 14;
    foreach ($data as $i => [$label, $v]) {
        $h = $v > 0 ? max(3, round($v / $max * 100)) : 0;
        $txt = $rupiah ? ($v >= 1000000 ? number_format($v / 1000000, 1, ',', '.') . 'jt' : ($v >= 1000 ? number_format($v / 1000, 0, ',', '.') . 'rb' : angka($v))) : angka($v);
        $b .= '<div class="bar" title="' . e($label) . ': ' . ($rupiah ? rp($v) : angka($v)) . '"><span>' . ($v > 0 ? $txt : '') . '</span><i style="height:' . $h . '%"></i></div>';
        $l .= '<span>' . (!$padat || $i % 3 === 0 ? e($label) : '') . '</span>';
    }
    return '<div class="bars">' . $b . '</div><div class="bar-labels">' . $l . '</div>';
}

/** Data penjualan per hari untuk N hari terakhir. */
function penjualan_harian(int $hari = 7, ?int $idKasir = null): array
{
    $mulai = date('Y-m-d', strtotime('-' . ($hari - 1) . ' days'));
    $sql = 'SELECT DATE(waktu) d, SUM(total) t FROM transaksi WHERE DATE(waktu)>=?' . ($idKasir ? ' AND id_kasir=?' : '') . ' GROUP BY DATE(waktu)';
    $map = array_column(rows($sql, $idKasir ? [$mulai, $idKasir] : [$mulai]), 't', 'd');
    $nama = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    $out = [];
    for ($i = $hari - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $out[] = [$nama[(int) date('w', strtotime($d))] . ' ' . date('j', strtotime($d)), (float) ($map[$d] ?? 0)];
    }
    return $out;
}

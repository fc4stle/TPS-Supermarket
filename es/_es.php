<?php
/* Bagian bersama halaman front end ES. */

/** Halaman berhenti dengan pesan jujur bila database ES belum diimpor. */
function es_wajib_tersedia(string $judul, string $active): void
{
    if (es_tersedia()) return;
    page_start($judul, $active, 'Expert System MartOffice');
    echo '<div class="card">' . empty_state('🧠', 'Database Expert System belum diimpor',
        'Impor file <code>es_database.sql</code> ke database <b>martoffice</b> (phpMyAdmin → Import), lalu muat ulang halaman ini.') . '</div>';
    page_end();
    exit;
}

/** Wadah aplikasi ES: alamat API back end, token CSRF, dan pengaturan untuk es.js. */
function es_app_open(string $page, array $extra = []): string
{
    $attr = ['page' => $page, 'api' => url('api/es.php'), 'token' => csrf_token(), 'base' => BASE_URL] + $extra;
    $h = '<div id="es-app"';
    foreach ($attr as $k => $v) $h .= ' data-' . $k . '="' . e((string) $v) . '"';
    return $h . '>';
}

function es_app_close(): string
{
    return '</div><script src="' . url('assets/js/es.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/es.js') . '"></script>';
}

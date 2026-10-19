<?php
require __DIR__ . '/../includes/app.php';
need();

$d = row('SELECT * FROM dokumen WHERE id=?', [(int) get('id')]);
$path = $d ? __DIR__ . '/../uploads/dokumen/' . basename($d['nama_file']) : '';
if (!$d || !is_file($path)) {
    flash('err', 'File dokumen tidak ditemukan.');
    redirect('oas/dokumen.php');
}
$mime = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'txt' => 'text/plain', 'csv' => 'text/csv',
][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $d['file_asli']) . '"; filename*=UTF-8\'\'' . rawurlencode($d['file_asli']));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

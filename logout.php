<?php
require __DIR__ . '/includes/app.php';
$_SESSION = [];
session_regenerate_id(true);
flash('ok', 'Anda sudah keluar. Sampai jumpa lagi!');
redirect('login.php');

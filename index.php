<?php
require __DIR__ . '/includes/app.php';
redirect(me() ? 'beranda.php' : 'login.php');

<?php
session_start();

function require_login($role = null) {
    if (!isset($_SESSION['role'])) {
        header("Location: login.php");
        exit;
    }
    if ($role && $_SESSION['role'] !== $role) {
        header("Location: index.php");
        exit;
    }
}
?>

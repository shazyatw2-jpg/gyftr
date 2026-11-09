<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║             GYFTR SUITE ROOT ROUTER / DISPATCHER                 ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

if (file_exists(__DIR__ . '/php/index.php')) {
    require_once __DIR__ . '/php/index.php';
} elseif (file_exists(__DIR__ . '/index.php') && __FILE__ !== __DIR__ . '/index.php') {
    require_once __DIR__ . '/index.php';
} else {
    header("Content-Type: text/html; charset=UTF-8");
    echo "<!DOCTYPE html><html><head><meta http-equiv='refresh' content='0; url=php/index.php'></head><body>Redirecting to <a href='php/index.php'>Dashboard</a>...</body></html>";
}

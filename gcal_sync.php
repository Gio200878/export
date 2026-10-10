<?php
// Endpoint per un cron esterno (es. cron Aruba o cron-job.org): gcal_sync.php?token=...
// Richiede GCAL_SYNC_TOKEN definito in config.php. L'agenda si sincronizza comunque da sola ogni GCAL_SYNC_MINUTI.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/gcal.php';
header('Content-Type: text/plain; charset=utf-8');
if (!defined('GCAL_SYNC_TOKEN') || GCAL_SYNC_TOKEN === '' || !hash_equals(GCAL_SYNC_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    die('Non autorizzato.');
}
[$ok, $msg] = gcal_sync();
http_response_code($ok ? 200 : 500);
echo $msg;

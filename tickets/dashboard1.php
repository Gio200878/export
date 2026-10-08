<?php
session_start();

if (!isset($_SESSION['session_id'])) {
    header('Location: login.html');
    exit;
}

$hm2i    = $_SESSION['session_hm2i'] ?? '';
$new_cod = $_SESSION['session_new_cod'] ?? '';

// Il codice del report deve essere di 6 caratteri alfanumerici minuscoli
// Prefisso del file in base al codice: direzione, area o agente (default)
$codici_direzione = ['wy4b95'];
$codici_area      = ['7o1rwl', '68js2m', 'ucs9m5'];

$report_url = '';
$tickets_url = '';
if (preg_match('/^[a-z0-9]{6}$/', $new_cod)) {
    if (in_array($new_cod, $codici_direzione, true)) {
        $prefisso = 'direzione_';
    } elseif (in_array($new_cod, $codici_area, true)) {
        $prefisso = 'area_';
    } else {
        $prefisso = 'agente_';
        // i report iscritti esistono solo per gli agenti (non per direzione e aree)
        $tickets_url = 'https://www.monacelliitaly.it/tickets/tickets_agente_' . $new_cod . '.html';
    }
    $report_url = 'https://monacelliitaly.it/report/' . $prefisso . $new_cod . '.html';
}

$cruscotti_url = 'dashboard.php?hm2i=' . urlencode($hm2i);
// percorso relativo: resta sullo stesso host della dashboard, cosi' la sessione PHP arriva anche al proxy
$magis_url     = '/progetti/magis-plus-riepilogo-saloni.html?from=dashboard1';
?>
<!DOCTYPE html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>DASHBOARD</title>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Open+Sans&display=swap">
        <link rel="stylesheet" href="/css/style.css">
        <style>
            .menu { display: flex; flex-direction: column; gap: 14px; width: 280px; margin: 0 auto; }
            .menu a.btn {
                display: block; padding: 14px 18px; text-align: center; text-decoration: none;
                font-family: 'Open Sans', sans-serif; font-weight: bold; color: #fff;
                background: #333; border-radius: 6px;
            }
            .menu a.btn:hover { background: #555; }
            .menu .disabled { opacity: .4; pointer-events: none; }
        </style>
    </head>
    <body>
        <h3 style="color: white; text-align: center;">DASHBOARD</h3>
        <div class="menu">
            <a class="btn" href="<?= htmlspecialchars($cruscotti_url, ENT_QUOTES, 'UTF-8') ?>">CRUSCOTTI SALONI</a>

            <?php if ($report_url !== ''): ?>
                <a class="btn" href="<?= htmlspecialchars($report_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">REPORT HM2I</a>
            <?php else: ?>
                <a class="btn disabled" href="#">REPORT HM2I</a>
            <?php endif; ?>

            <?php if ($tickets_url !== ''): ?>
                <a class="btn" href="<?= htmlspecialchars($tickets_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">ISCRIZIONI MONACELLI ACADEMY</a>
            <?php else: ?>
                <a class="btn disabled" href="#">ISCRIZIONI MONACELLI ACADEMY</a>
            <?php endif; ?>

            <a class="btn" href="<?= htmlspecialchars($magis_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">MAGIS PLUS</a>
        </div>
    </body>
</html>

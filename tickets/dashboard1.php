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
            :root {
                --card: #1c1c1e; --card-hover: #2a2a2d; --card-border: #3a3a3e;
                --ink: #fff; --muted: #b8b8bd; --accent: #d2a85a;
            }
            .wrap { width: min(100% - 32px, 380px); margin: 0 auto; padding: 24px 0 40px; }
            h1.titolo { color: #fff; text-align: center; font-family: 'Open Sans', sans-serif;
                font-size: 1.25rem; letter-spacing: .12em; margin: 0 0 20px; }
            .menu { display: flex; flex-direction: column; gap: 12px; }
            .menu a.btn {
                display: flex; align-items: center; gap: 14px; min-height: 64px; padding: 12px 16px;
                text-decoration: none; font-family: 'Open Sans', sans-serif; color: var(--ink);
                background: var(--card); border: 1px solid var(--card-border); border-radius: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,.25);
                transition: background-color .2s ease, border-color .2s ease, transform .2s ease;
            }
            .menu a.btn:hover { background: var(--card-hover); border-color: var(--accent); transform: translateY(-1px); }
            .menu a.btn:active { transform: none; }
            .menu a.btn:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
            .btn .ico { flex: none; width: 40px; height: 40px; display: grid; place-items: center;
                border-radius: 10px; background: rgba(210,168,90,.14); color: var(--accent); }
            .btn .ico svg { width: 22px; height: 22px; }
            .btn .txt { display: flex; flex-direction: column; min-width: 0; }
            .btn .lbl { font-weight: 700; font-size: .95rem; letter-spacing: .04em; }
            .btn .sub { font-size: .8rem; color: var(--muted); margin-top: 2px; }
            .btn .go { margin-left: auto; width: 18px; height: 18px; color: var(--muted); flex: none; }
            .menu a.btn.disabled { opacity: .55; pointer-events: none; cursor: not-allowed; }
            .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
            @media (prefers-reduced-motion: reduce) {
                .menu a.btn { transition: none; }
                .menu a.btn:hover { transform: none; }
            }
        </style>
    </head>
    <body>
        <main class="wrap">
        <h1 class="titolo">DASHBOARD</h1>
        <nav class="menu" aria-label="Sezioni della dashboard">
            <a class="btn" href="<?= htmlspecialchars($cruscotti_url, ENT_QUOTES, 'UTF-8') ?>">
                <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
                <span class="txt"><span class="lbl">CRUSCOTTI SALONI</span><span class="sub">Andamento dei saloni</span></span>
                <svg class="go" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>

            <?php if ($report_url !== ''): ?>
                <a class="btn" href="<?= htmlspecialchars($report_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
            <?php else: ?>
                <a class="btn disabled" href="#" aria-disabled="true" tabindex="-1">
            <?php endif; ?>
                <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg></span>
                <span class="txt"><span class="lbl">REPORT HM2I</span><span class="sub"><?= $report_url !== '' ? 'Report mensile' : 'Non disponibile' ?></span></span>
                <?php if ($report_url !== ''): ?><span class="sr-only">(si apre in una nuova scheda)</span><?php endif; ?>
                <svg class="go" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>

            <?php if ($tickets_url !== ''): ?>
                <a class="btn" href="<?= htmlspecialchars($tickets_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
            <?php else: ?>
                <a class="btn disabled" href="#" aria-disabled="true" tabindex="-1">
            <?php endif; ?>
                <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10 12 5 2 10l10 5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg></span>
                <span class="txt"><span class="lbl">ISCRIZIONI MONACELLI ACADEMY</span><span class="sub"><?= $tickets_url !== '' ? 'Iscritti ai corsi' : 'Non disponibile' ?></span></span>
                <?php if ($tickets_url !== ''): ?><span class="sr-only">(si apre in una nuova scheda)</span><?php endif; ?>
                <svg class="go" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>

            <a class="btn" href="<?= htmlspecialchars($magis_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg></span>
                <span class="txt"><span class="lbl">MAGIS PLUS</span><span class="sub">Riepilogo saloni</span></span>
                <span class="sr-only">(si apre in una nuova scheda)</span>
                <svg class="go" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
            </a>
        </nav>
        </main>
    </body>
</html>

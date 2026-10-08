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
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant:wght@500;600&family=Lato:wght@400;700&display=swap">
        <link rel="stylesheet" href="/css/style.css">
        <style>
            /* palette e stile da mymonacelliitaly.com/professional: nero, azzurro acciaio, grigio chiaro; Cormorant + Lato */
            :root {
                --black: #010101; --steel: #7692a9; --page: #e0f1fb; --band: #e0f1fb; --white: #fff;
                --muted: #4a5a69; --focus: #1e73be;
            }
            body { background: var(--page); margin: 0; font-family: 'Lato', sans-serif; }
            /* barra titolo a tutta pagina con trama a linee, come sul sito */
            header.top { background: var(--band) url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 1901 221%22 preserveAspectRatio=%22xMidYMid slice%22%3E%3Cg fill=%22none%22 stroke=%22%237c97ac%22 stroke-width=%221.5%22%3E%3Cpath d=%22M0 60L50 0%22/%3E%3Cpath d=%22M50 0L110 221%22/%3E%3Cpath d=%22M50 0L235 142%22/%3E%3Cpath d=%22M235 142L448 0%22/%3E%3Cpath d=%22M235 142L190 221%22/%3E%3Cpath d=%22M235 142L305 221%22/%3E%3Cpath d=%22M448 0L410 221%22/%3E%3Cpath d=%22M448 0L478 147%22/%3E%3Cpath d=%22M478 147L540 0%22/%3E%3Cpath d=%22M478 147L436 221%22/%3E%3Cpath d=%22M478 147L570 221%22/%3E%3Cpath d=%22M540 0L645 221%22/%3E%3Cpath d=%22M540 0L695 112%22/%3E%3Cpath d=%22M700 0L693 221%22/%3E%3Cpath d=%22M695 112L865 0%22/%3E%3Cpath d=%22M695 112L838 221%22/%3E%3Cpath d=%22M865 0L935 221%22/%3E%3Cpath d=%22M865 0L1067 162%22/%3E%3Cpath d=%22M1067 162L1127 0%22/%3E%3Cpath d=%22M1067 162L1020 221%22/%3E%3Cpath d=%22M1067 162L1165 221%22/%3E%3Cpath d=%22M1127 0L1267 221%22/%3E%3Cpath d=%22M1127 0L1442 90%22/%3E%3Cpath d=%22M1442 90L1588 0%22/%3E%3Cpath d=%22M1442 90L1375 221%22/%3E%3Cpath d=%22M1442 90L1575 221%22/%3E%3Cpath d=%22M1588 0L1640 221%22/%3E%3Cpath d=%22M1588 0L1772 138%22/%3E%3Cpath d=%22M1772 138L1722 221%22/%3E%3Cpath d=%22M1772 138L1830 221%22/%3E%3Cpath d=%22M1772 138L1901 60%22/%3E%3C/g%3E%3C/svg%3E") center / cover no-repeat;
                min-height: 130px; display: flex; align-items: center; padding: 24px 16px 24px max(16px, 15.8vw); }
            h1.titolo { margin: 0; color: var(--black); font-family: 'Lato', sans-serif; font-weight: 400;
                font-size: clamp(1.5rem, 3vw, 2.3rem); letter-spacing: .03em; text-transform: uppercase; }
            .wrap { width: min(100% - 32px, 420px); margin: 0 auto; padding: 28px 0 48px; }
            .menu { display: flex; flex-direction: column; gap: 12px; }
            .menu a.btn {
                display: flex; align-items: center; gap: 16px; min-height: 68px; padding: 12px 16px;
                text-decoration: none; color: var(--black); background: var(--white);
                border: 1px solid #c9d0d8; border-left: 4px solid var(--steel); border-radius: 2px;
                transition: background-color .2s ease, color .2s ease, border-color .2s ease;
            }
            .menu a.btn:hover { background: var(--black); color: var(--white); border-color: var(--black); }
            .menu a.btn:focus-visible { outline: 3px solid var(--focus); outline-offset: 3px; }
            .btn .ico { flex: none; width: 40px; height: 40px; display: grid; place-items: center;
                background: var(--steel); color: var(--white); }
            .btn .ico svg { width: 22px; height: 22px; }
            .btn .txt { display: flex; flex-direction: column; min-width: 0; }
            .btn .lbl { font-weight: 700; font-size: .9rem; letter-spacing: .08em; text-transform: uppercase; }
            .btn .sub { font-family: 'Cormorant', serif; font-weight: 500; font-size: 1.05rem; color: var(--muted); margin-top: 1px; }
            .menu a.btn:hover .sub, .menu a.btn:hover .go { color: #d7dde3; }
            .btn .go { margin-left: auto; width: 18px; height: 18px; color: var(--steel); flex: none; }
            .menu a.btn.disabled { background: #eef0f3; color: #5f6b77; border-color: #d3d8de; border-left-color: #b4bfca;
                pointer-events: none; cursor: not-allowed; }
            .menu a.btn.disabled .ico { background: #b4bfca; }
            .menu a.btn.disabled .sub { color: #5f6b77; }
            .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
            @media (prefers-reduced-motion: reduce) { .menu a.btn { transition: none; } }
        </style>
    </head>
    <body>
        <header class="top"><h1 class="titolo">My Dashboard</h1></header>
        <main class="wrap">
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

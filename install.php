<?php
/**
 * INSTALLER - da eseguire UNA SOLA VOLTA dopo aver caricato i file su Aruba
 * e configurato config.php con i dati corretti del database.
 * Dopo l'uso, elimina questo file dal server per sicurezza.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/gcal.php';

$sqlFile = __DIR__ . '/schema.sql';
if (!file_exists($sqlFile)) {
    die('File schema.sql non trovato.');
}

$sql = file_get_contents($sqlFile);
// Rimuove i commenti di riga e divide in singole query
$sql = preg_replace('/^--.*$/m', '', $sql);
$queries = array_filter(array_map('trim', explode(';', $sql)));

$pdo = db();
$errori = [];
$eseguite = 0;

foreach ($queries as $q) {
    if ($q === '') continue;
    try {
        $pdo->exec($q);
        $eseguite++;
    } catch (PDOException $e) {
        $errori[] = $e->getMessage();
    }
}

// --- Migrazione per database già installati: colonne aggiunte nelle nuove versioni ---
function colonna_mancante(PDO $pdo, string $tabella, string $colonna): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabella, $colonna]);
    return !$st->fetchColumn();
}
foreach ([
    ['appuntamenti', 'salone_id', 'ALTER TABLE appuntamenti ADD COLUMN salone_id INT NULL AFTER hemi_id'],
    ['saloni', 'hm2i_id', 'ALTER TABLE saloni ADD COLUMN hm2i_id INT NULL AFTER prov'],
    ['messaggi', 'letto', 'ALTER TABLE messaggi ADD COLUMN letto TINYINT(1) NOT NULL DEFAULT 0 AFTER testo'],
] as [$tab, $col, $alter]) {
    try {
        if (colonna_mancante($pdo, $tab, $col)) { $pdo->exec($alter); $eseguite++; }
    } catch (PDOException $e) {
        $errori[] = $e->getMessage();
    }
}

function leggi_csv(string $file): array {
    $righe = [];
    if (is_readable($file) && ($fh = fopen($file, 'r'))) {
        fgetcsv($fh, 0, ';'); // intestazione
        while (($r = fgetcsv($fh, 0, ';')) !== false) {
            if (count($r) > 1) $righe[] = array_map('trim', $r);
        }
        fclose($fh);
    }
    return $righe;
}

// --- Anagrafica HM2I + account di accesso (login = email, password di default) ---
const PASSWORD_DEFAULT_HM2I = 'Monacelli26';
const ZONE_PER_AREA = [
    'NORD' => ['Nord Ovest', 'Nord Est'],
    'CENTRO' => ['Centro'],
    'SUD' => ['Sud'],
];
$hm2iCreati = 0; $accountCreati = 0;
try {
    $hashDefault = password_hash(PASSWORD_DEFAULT_HM2I, PASSWORD_DEFAULT);
    $selAcc = $pdo->prepare('SELECT id FROM accounts WHERE email = ? OR login = ? LIMIT 1');
    $insAcc = $pdo->prepare("INSERT INTO accounts (nome, cognome, ruolo, email, login, password_hash) VALUES (?, ?, 'hm2i', ?, ?, ?)");
    $insHm = $pdo->prepare('INSERT IGNORE INTO hm2i (codice, nome_completo, area, cognome, nome, account_id) VALUES (?,?,?,?,?,?)');
    $updHm = $pdo->prepare('UPDATE hm2i SET account_id = ? WHERE codice = ? AND account_id IS NULL');
    $insZona = $pdo->prepare('INSERT IGNORE INTO account_zone (account_id, zona_id) SELECT ?, id FROM zone WHERE nome = ?');
    foreach (leggi_csv(__DIR__ . '/data/hm2i.csv') as [$codice, $completo, $area, $cognome, $nome, $email]) {
        $selAcc->execute([$email, $email]);
        $accId = $selAcc->fetchColumn();
        if (!$accId) {
            $insAcc->execute([$nome, $cognome, $email, $email, $hashDefault]);
            $accId = (int)$pdo->lastInsertId();
            $accountCreati++;
            foreach (ZONE_PER_AREA[strtoupper($area)] ?? [] as $zn) $insZona->execute([$accId, $zn]);
        }
        $insHm->execute([(int)$codice, $completo, $area, $cognome, $nome, $accId]);
        $hm2iCreati += $insHm->rowCount();
        $updHm->execute([$accId, (int)$codice]);
    }
} catch (PDOException $e) {
    $errori[] = 'HM2I: ' . $e->getMessage();
}

// --- Import anagrafica saloni da data/saloni.csv (codice;nome;prov;hm2i) - idempotente ---
$saloniImportati = 0;
try {
    $ins = $pdo->prepare('INSERT IGNORE INTO saloni (codice, nome, prov) VALUES (?, ?, ?)');
    $upd = $pdo->prepare(
        'UPDATE saloni SET hm2i_id = (SELECT account_id FROM hm2i WHERE LOWER(cognome) = LOWER(?) LIMIT 1)
         WHERE codice = ? AND hm2i_id IS NULL'
    );
    foreach (leggi_csv(__DIR__ . '/data/saloni.csv') as $r) {
        if ($r[1] === '') continue;
        $ins->execute([$r[0] ?: null, $r[1], ($r[2] ?? '') ?: null]);
        $saloniImportati += $ins->rowCount();
        if (!empty($r[3]) && $r[0] !== '') $upd->execute([$r[3], $r[0]]);
    }
} catch (PDOException $e) {
    $errori[] = 'Saloni: ' . $e->getMessage();
}

// --- Prima sincronizzazione eventi Google Calendar (dal 1/10/2026 in poi) ---
$gcalEsito = '';
try {
    [$gcalOk, $gcalMsg] = gcal_sync();
    $gcalEsito = $gcalMsg;
    if (!$gcalOk) $errori[] = 'Google Calendar: ' . $gcalMsg;
} catch (Throwable $e) {
    $errori[] = 'Google Calendar: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Installazione HEMI Gestionale</title></head>
<body style="font-family: sans-serif; max-width: 700px; margin: 40px auto;">
<h1>Installazione database</h1>
<p>Query eseguite con successo: <strong><?= $eseguite ?></strong></p>
<p>HM2I importati: <strong><?= $hm2iCreati ?></strong> - account HM2I creati: <strong><?= $accountCreati ?></strong> (password di default: <code>Monacelli26</code>)</p>
<p>Google Calendar: <strong><?= htmlspecialchars($gcalEsito) ?></strong></p>
<p>Saloni importati: <strong><?= $saloniImportati ?></strong></p>
<?php if ($errori): ?>
  <h3 style="color:red;">Errori:</h3>
  <ul><?php foreach ($errori as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
<?php else: ?>
  <p style="color:green;">Nessun errore. Il database è pronto.</p>
<?php endif; ?>
<p><strong>Account amministratore di default:</strong><br>
Login: <code>admin</code><br>
Password: <code>admin123</code></p>
<p style="color:red;"><strong>IMPORTANTE:</strong> cambia subito la password dell'admin dopo il primo accesso, ed elimina questo file (install.php) dal server.</p>
<p><a href="login.php">Vai al login &raquo;</a></p>
</body>
</html>

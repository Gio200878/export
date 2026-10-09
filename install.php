<?php
/**
 * INSTALLER - da eseguire UNA SOLA VOLTA dopo aver caricato i file su Aruba
 * e configurato config.php con i dati corretti del database.
 * Dopo l'uso, elimina questo file dal server per sicurezza.
 */
require_once __DIR__ . '/lib/db.php';

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
?>
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><title>Installazione HEMI Gestionale</title></head>
<body style="font-family: sans-serif; max-width: 700px; margin: 40px auto;">
<h1>Installazione database</h1>
<p>Query eseguite con successo: <strong><?= $eseguite ?></strong></p>
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

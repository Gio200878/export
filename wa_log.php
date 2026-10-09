<?php
require_once __DIR__ . '/lib/auth.php';
auth_require_admin();

$filtroEsito = $_GET['esito'] ?? '';
$filtroDest = trim($_GET['destinatario'] ?? '');

$where = [];
$params = [];
if ($filtroEsito !== '') {
    if ($filtroEsito === 'inviato') {
        $where[] = "esito = 'inviato'";
    } elseif ($filtroEsito === 'errore') {
        $where[] = "esito LIKE 'errore%'";
    } elseif ($filtroEsito === 'non_inviato') {
        $where[] = "esito = 'non_inviato'";
    }
}
if ($filtroDest !== '') {
    $where[] = "destinatario LIKE ?";
    $params[] = '%' . $filtroDest . '%';
}
$sql = "SELECT l.*, a.data_appuntamento, a.salone
        FROM wa_log l
        LEFT JOIN appuntamenti a ON a.id = l.appuntamento_id"
    . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY l.created_at DESC LIMIT 300';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$log = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log WhatsApp - HEMI Gestionale</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php include __DIR__ . '/lib/header.php'; ?>
<div class="main-content" style="max-width:1200px;margin:0 auto;">
  <h2>Log Messaggi WhatsApp</h2>
  <p style="color:var(--text-light);font-size:13px;">
    WA_ENABLED = <?= WA_ENABLED ? 'true (invio reale attivo)' : 'false (solo simulazione, nessun invio reale)' ?>
  </p>

  <form method="get" class="filters-bar">
    <select name="esito">
      <option value="">Tutti gli esiti</option>
      <option value="inviato" <?= $filtroEsito === 'inviato' ? 'selected' : '' ?>>Inviato</option>
      <option value="errore" <?= $filtroEsito === 'errore' ? 'selected' : '' ?>>Errore</option>
      <option value="non_inviato" <?= $filtroEsito === 'non_inviato' ? 'selected' : '' ?>>Non inviato</option>
    </select>
    <input type="text" name="destinatario" placeholder="Cerca destinatario..." value="<?= htmlspecialchars($filtroDest) ?>">
    <button class="btn btn-secondary" type="submit">Filtra</button>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th>Data/ora</th>
        <th>Destinatario</th>
        <th>Messaggio</th>
        <th>Appuntamento</th>
        <th>Esito</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($log as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
        <td><?= htmlspecialchars($r['destinatario']) ?></td>
        <td><?= htmlspecialchars($r['messaggio']) ?></td>
        <td><?= $r['appuntamento_id'] ? htmlspecialchars(($r['salone'] ?? '') . ' - ' . ($r['data_appuntamento'] ?? '') . ' (#' . $r['appuntamento_id'] . ')') : '-' ?></td>
        <td>
          <?php if ($r['esito'] === 'inviato'): ?>
            <span class="badge badge-verde">Inviato</span>
          <?php elseif (str_starts_with((string)$r['esito'], 'errore')): ?>
            <span class="badge badge-rosso" title="<?= htmlspecialchars($r['esito']) ?>">Errore</span>
          <?php else: ?>
            <span class="badge badge-giallo">Non inviato</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$log): ?>
      <tr><td colspan="5" style="text-align:center;color:var(--text-light);">Nessun messaggio registrato.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>

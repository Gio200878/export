<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logic.php';
auth_check();

$ruolo = current_user_role();
$puoCreare = in_array($ruolo, ['admin', 'sector_manager', 'hm2i'], true);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agenda - HEMI Gestionale</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php include __DIR__ . '/lib/header.php'; ?>

<div class="app-layout">
  <aside class="sidebar">
    <h3>Filtri</h3>
    <label>HM2I</label>
    <select id="filtro-hm2i"><option value="">Tutti</option></select>

    <label>Area d'intervento</label>
    <select id="filtro-area"><option value="">Tutte</option></select>

    <label>HEMI</label>
    <select id="filtro-hemi"><option value="">Tutti</option></select>

    <label>Zona d'Italia</label>
    <select id="filtro-zona"><option value="">Tutte</option></select>

    <?php if ($ruolo === 'admin'): ?>
    <label>Stato</label>
    <select id="filtro-stato">
      <option value="">Tutti</option>
      <option value="da_approvare">Da approvare</option>
      <option value="approvato">Approvato</option>
      <option value="rifiutato">Rifiutato</option>
    </select>
    <?php endif; ?>
  </aside>

  <main class="main-content">
    <div class="calendar-toolbar">
      <div class="nav-buttons">
        <button id="btn-prev">&laquo; Mese prec.</button>
        <button id="btn-today">Oggi</button>
        <button id="btn-next">Mese succ. &raquo;</button>
      </div>
      <h2 id="mese-corrente"></h2>
    </div>
    <div class="calendar-grid" id="calendar-grid"></div>
  </main>
</div>

<!-- MODALE: nuovo/dettaglio appuntamento -->
<div class="modal-overlay" id="modal-appuntamento">
  <div class="modal-box">
    <h2 id="modal-title">Nuovo appuntamento</h2>
    <div id="modal-body"></div>
  </div>
</div>

<script>
const CURRENT_ROLE = <?= json_encode($ruolo) ?>;
const CURRENT_USER_ID = <?= json_encode(current_user_id()) ?>;
const PUO_CREARE = <?= json_encode($puoCreare) ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>

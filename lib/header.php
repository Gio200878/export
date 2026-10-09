<?php
// Richiede che auth.php sia già incluso e che auth_check() sia già stato chiamato
$ruolo = current_user_role();
?>
<header class="app-header">
  <h1>HEMI Gestionale</h1>
  <nav>
    <a href="index.php">Agenda</a>
    <?php if ($ruolo === 'admin'): ?>
      <a href="gestione.php">Gestione</a>
      <a href="appuntamenti_admin.php">Gestione Appuntamenti</a>
      <a href="accounts.php">Account</a>
      <a href="wa_log.php">Log WhatsApp</a>
    <?php endif; ?>
    <span class="user-info"><?= htmlspecialchars(current_user_name()) ?> (<?= htmlspecialchars($ruolo) ?>)</span>
    <a href="logout.php">Esci</a>
  </nav>
</header>

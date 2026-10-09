<?php
// Richiede che auth.php sia già incluso e che auth_check() sia già stato chiamato
$ruolo = current_user_role();
require_once __DIR__ . '/logic.php';
$nonLetti = messaggi_non_letti();
?>
<header class="app-header">
  <h1>HEMI Gestionale</h1>
  <nav>
    <a href="index.php">Agenda<?php if ($nonLetti > 0): ?><span class="notif-badge" title="Messaggi non letti"><?= $nonLetti > 99 ? '99+' : $nonLetti ?></span><?php endif; ?></a>
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

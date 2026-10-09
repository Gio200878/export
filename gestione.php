<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logic.php';
auth_require_admin();

$msg = '';
$tab = $_GET['tab'] ?? 'aree';

// --- Gestione azioni POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entita = $_POST['entita'] ?? ''; // 'area' o 'zona'
    $azione = $_POST['azione'] ?? '';
    $tabella = $entita === 'area' ? 'aree_intervento' : 'zone';

    if ($azione === 'inserisci') {
        $nome = trim($_POST['nome'] ?? '');
        if ($nome !== '') {
            if ($entita === 'area') {
                $colore = $_POST['colore'] ?: '#8a7968';
                $stmt = db()->prepare("INSERT INTO aree_intervento (nome, colore) VALUES (?, ?)");
                $stmt->execute([$nome, $colore]);
            } else {
                $stmt = db()->prepare("INSERT INTO zone (nome) VALUES (?)");
                $stmt->execute([$nome]);
            }
            $msg = 'Elemento inserito.';
        }
    } elseif ($azione === 'modifica') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($entita === 'area') {
            $colore = $_POST['colore'] ?: '#8a7968';
            $stmt = db()->prepare("UPDATE aree_intervento SET nome = ?, colore = ? WHERE id = ?");
            $stmt->execute([$nome, $colore, $id]);
        } else {
            $stmt = db()->prepare("UPDATE zone SET nome = ? WHERE id = ?");
            $stmt->execute([$nome, $id]);
        }
        $msg = 'Elemento modificato.';
    } elseif ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM $tabella WHERE id = ?");
        $stmt->execute([$id]);
        $msg = 'Elemento eliminato.';
    } elseif ($azione === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("UPDATE $tabella SET attiva = 1 - attiva WHERE id = ?");
        $stmt->execute([$id]);
    }
    $tab = $entita === 'area' ? 'aree' : 'zone';
}

$aree = get_aree(false);
$zone = get_zone(false);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestione - HEMI Gestionale</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php include __DIR__ . '/lib/header.php'; ?>
<div class="main-content" style="max-width:900px;margin:0 auto;">
  <h2>Gestione</h2>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="tabs">
    <a href="?tab=aree" class="<?= $tab==='aree'?'active':'' ?>">Aree d'intervento</a>
    <a href="?tab=zone" class="<?= $tab==='zone'?'active':'' ?>">Zone d'Italia</a>
  </div>

  <?php if ($tab === 'aree'): ?>
    <h3>Nuova area d'intervento</h3>
    <form method="post" style="display:flex;gap:10px;align-items:center;margin-bottom:20px;">
      <input type="hidden" name="entita" value="area">
      <input type="hidden" name="azione" value="inserisci">
      <input type="text" name="nome" placeholder="Nome area" required>
      <input type="color" name="colore" value="#8a7968">
      <button class="btn btn-primary" type="submit">Aggiungi</button>
    </form>

    <table class="data-table">
      <thead><tr><th>Nome</th><th>Colore</th><th>Stato</th><th>Azioni</th></tr></thead>
      <tbody>
        <?php foreach ($aree as $a): ?>
        <tr>
          <form method="post">
            <input type="hidden" name="entita" value="area">
            <input type="hidden" name="id" value="<?= $a['id'] ?>">
            <td><input type="text" name="nome" value="<?= htmlspecialchars($a['nome']) ?>" style="border:none;background:transparent;width:160px;"></td>
            <td><input type="color" name="colore" value="<?= htmlspecialchars($a['colore']) ?>"></td>
            <td><?= $a['attiva'] ? 'Attiva' : 'Disattivata' ?></td>
            <td>
              <button class="btn btn-sm btn-primary" name="azione" value="modifica">Salva</button>
              <button class="btn btn-sm btn-secondary" name="azione" value="toggle"><?= $a['attiva'] ? 'Disattiva' : 'Attiva' ?></button>
              <button class="btn btn-sm btn-reject" name="azione" value="elimina" onclick="return confirm('Eliminare definitivamente?');">Elimina</button>
            </td>
          </form>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php else: ?>
    <h3>Nuova zona d'Italia</h3>
    <form method="post" style="display:flex;gap:10px;align-items:center;margin-bottom:20px;">
      <input type="hidden" name="entita" value="zona">
      <input type="hidden" name="azione" value="inserisci">
      <input type="text" name="nome" placeholder="Nome zona" required>
      <button class="btn btn-primary" type="submit">Aggiungi</button>
    </form>

    <table class="data-table">
      <thead><tr><th>Nome</th><th>Stato</th><th>Azioni</th></tr></thead>
      <tbody>
        <?php foreach ($zone as $z): ?>
        <tr>
          <form method="post">
            <input type="hidden" name="entita" value="zona">
            <input type="hidden" name="id" value="<?= $z['id'] ?>">
            <td><input type="text" name="nome" value="<?= htmlspecialchars($z['nome']) ?>" style="border:none;background:transparent;width:160px;"></td>
            <td><?= $z['attiva'] ? 'Attiva' : 'Disattivata' ?></td>
            <td>
              <button class="btn btn-sm btn-primary" name="azione" value="modifica">Salva</button>
              <button class="btn btn-sm btn-secondary" name="azione" value="toggle"><?= $z['attiva'] ? 'Disattiva' : 'Attiva' ?></button>
              <button class="btn btn-sm btn-reject" name="azione" value="elimina" onclick="return confirm('Eliminare definitivamente?');">Elimina</button>
            </td>
          </form>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>

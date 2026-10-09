<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logic.php';
auth_require_admin();

$msg = '';
$errore = '';

function sync_multi(string $tabella, string $colId, int $accountId, array $ids) {
    db()->prepare("DELETE FROM $tabella WHERE account_id = ?")->execute([$accountId]);
    if (!empty($ids)) {
        $stmt = db()->prepare("INSERT INTO $tabella (account_id, $colId) VALUES (?, ?)");
        foreach ($ids as $id) {
            if ($id) $stmt->execute([$accountId, (int)$id]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'elimina') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        $msg = 'Account eliminato.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $cognome = trim($_POST['cognome'] ?? '');
        $ruolo = $_POST['ruolo'] ?? '';
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $oreContratto = $_POST['ore_contratto'] !== '' ? $_POST['ore_contratto'] : null;
        $compensoOrario = $_POST['compenso_orario'] !== '' ? $_POST['compenso_orario'] : null;
        $zoneIds = $_POST['zone'] ?? [];
        $areeIds = $_POST['aree'] ?? [];
        $hm2iIds = $_POST['hm2i_collegati'] ?? [];

        if (!$nome || !$cognome || !$ruolo || !$email || !$login) {
            $errore = 'Compila tutti i campi obbligatori.';
        } else {
            try {
                if ($azione === 'inserisci') {
                    if (!$password) {
                        $errore = 'La password è obbligatoria per un nuovo account.';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = db()->prepare(
                            'INSERT INTO accounts (nome, cognome, ruolo, telefono, email, login, password_hash, ore_contratto, compenso_orario)
                             VALUES (?,?,?,?,?,?,?,?,?)'
                        );
                        $stmt->execute([$nome, $cognome, $ruolo, $telefono, $email, $login, $hash, $oreContratto, $compensoOrario]);
                        $id = (int)db()->lastInsertId();
                        $msg = 'Account creato.';
                    }
                } elseif ($azione === 'modifica') {
                    if ($password) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = db()->prepare(
                            'UPDATE accounts SET nome=?, cognome=?, ruolo=?, telefono=?, email=?, login=?, password_hash=?, ore_contratto=?, compenso_orario=? WHERE id=?'
                        );
                        $stmt->execute([$nome, $cognome, $ruolo, $telefono, $email, $login, $hash, $oreContratto, $compensoOrario, $id]);
                    } else {
                        $stmt = db()->prepare(
                            'UPDATE accounts SET nome=?, cognome=?, ruolo=?, telefono=?, email=?, login=?, ore_contratto=?, compenso_orario=? WHERE id=?'
                        );
                        $stmt->execute([$nome, $cognome, $ruolo, $telefono, $email, $login, $oreContratto, $compensoOrario, $id]);
                    }
                    $msg = 'Account modificato.';
                }

                if ($id && !$errore) {
                    // Zone: richieste per hemi, hm2i, sector_manager
                    if (in_array($ruolo, ['hemi', 'hm2i', 'sector_manager'], true)) {
                        sync_multi('account_zone', 'zona_id', $id, $zoneIds);
                    } else {
                        sync_multi('account_zone', 'zona_id', $id, []);
                    }
                    // Aree: richieste solo per hemi
                    if ($ruolo === 'hemi') {
                        sync_multi('account_area', 'area_id', $id, $areeIds);
                    } else {
                        sync_multi('account_area', 'area_id', $id, []);
                    }
                    // Collegamento HM2I per sector manager
                    if ($ruolo === 'sector_manager') {
                        sync_multi('sector_hm2i', 'hm2i_id', $id, $hm2iIds); // nota: account_id qui è il sector manager
                    } else {
                        db()->prepare('DELETE FROM sector_hm2i WHERE sector_manager_id = ?')->execute([$id]);
                    }
                }
            } catch (PDOException $e) {
                $errore = 'Errore: possibile login o email duplicati.';
            }
        }
    }
}

$accounts = db()->query(
    "SELECT * FROM accounts ORDER BY FIELD(ruolo,'admin','sector_manager','hm2i','hemi'), cognome"
)->fetchAll();

// Zone/aree associate per ciascun account (per precompilare il form in edit lato JS)
$zoneMap = [];
foreach (db()->query('SELECT account_id, zona_id FROM account_zone')->fetchAll() as $r) {
    $zoneMap[$r['account_id']][] = (int)$r['zona_id'];
}
$areaMap = [];
foreach (db()->query('SELECT account_id, area_id FROM account_area')->fetchAll() as $r) {
    $areaMap[$r['account_id']][] = (int)$r['area_id'];
}
$sectorMap = [];
foreach (db()->query('SELECT sector_manager_id, hm2i_id FROM sector_hm2i')->fetchAll() as $r) {
    $sectorMap[$r['sector_manager_id']][] = (int)$r['hm2i_id'];
}

$zone = get_zone(false);
$aree = get_aree(false);
$hm2iTutti = db()->query("SELECT id, nome, cognome FROM accounts WHERE ruolo = 'hm2i' ORDER BY cognome")->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account - HEMI Gestionale</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php include __DIR__ . '/lib/header.php'; ?>
<div class="main-content" style="max-width:1000px;margin:0 auto;">
  <h2>Gestione Account</h2>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($errore): ?><div class="alert alert-error"><?= htmlspecialchars($errore) ?></div><?php endif; ?>

  <button class="btn btn-primary" onclick="apriNuovoAccount()">+ Nuovo account</button>

  <table class="data-table" style="margin-top:16px;">
    <thead><tr><th>Nome</th><th>Ruolo</th><th>Email</th><th>Login</th><th>Azioni</th></tr></thead>
    <tbody>
      <?php foreach ($accounts as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['cognome'].' '.$a['nome']) ?></td>
        <td><?= htmlspecialchars($a['ruolo']) ?></td>
        <td><?= htmlspecialchars($a['email']) ?></td>
        <td><?= htmlspecialchars($a['login']) ?></td>
        <td>
          <button class="btn btn-sm btn-secondary" onclick='apriModificaAccount(<?= json_encode($a) ?>, <?= json_encode($zoneMap[$a['id']] ?? []) ?>, <?= json_encode($areaMap[$a['id']] ?? []) ?>, <?= json_encode($sectorMap[$a['id']] ?? []) ?>)'>Modifica</button>
          <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questo account?');">
            <input type="hidden" name="azione" value="elimina">
            <input type="hidden" name="id" value="<?= $a['id'] ?>">
            <button class="btn btn-sm btn-reject" type="submit">Elimina</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- MODALE form account -->
<div class="modal-overlay" id="modal-account">
  <div class="modal-box">
    <h2 id="modal-account-title">Nuovo account</h2>
    <form method="post" id="form-account">
      <input type="hidden" name="azione" id="f-azione" value="inserisci">
      <input type="hidden" name="id" id="f-id" value="">

      <div class="row">
        <div><label>Nome</label><input type="text" name="nome" id="f-nome" required></div>
        <div><label>Cognome</label><input type="text" name="cognome" id="f-cognome" required></div>
      </div>

      <label>Ruolo</label>
      <select name="ruolo" id="f-ruolo" required onchange="aggiornaCampiRuolo()">
        <option value="admin">Admin</option>
        <option value="sector_manager">Sector Manager</option>
        <option value="hm2i">HM2I</option>
        <option value="hemi">HEMI</option>
      </select>

      <div class="row">
        <div><label>Telefono</label><input type="text" name="telefono" id="f-telefono"></div>
        <div><label>Email</label><input type="email" name="email" id="f-email" required></div>
      </div>

      <div class="row">
        <div><label>Login</label><input type="text" name="login" id="f-login" required></div>
        <div><label>Password <span id="f-password-hint" style="font-weight:400;"></span></label><input type="password" name="password" id="f-password"></div>
      </div>

      <div class="row">
        <div><label>Ore da contratto</label><input type="number" step="0.5" name="ore_contratto" id="f-ore"></div>
        <div><label>Compenso orario (€)</label><input type="number" step="0.01" name="compenso_orario" id="f-compenso"></div>
      </div>

      <div id="blocco-zone" style="display:none;">
        <label>Zone d'Italia (possono essere più di una)</label>
        <div class="chips-multi">
          <?php foreach ($zone as $z): ?>
            <label><input type="checkbox" name="zone[]" value="<?= $z['id'] ?>" class="chk-zona"> <?= htmlspecialchars($z['nome']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="blocco-aree" style="display:none;">
        <label>Aree d'intervento (possono essere più di una)</label>
        <div class="chips-multi">
          <?php foreach ($aree as $ar): ?>
            <label><input type="checkbox" name="aree[]" value="<?= $ar['id'] ?>" class="chk-area"> <?= htmlspecialchars($ar['nome']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="blocco-hm2i-collegati" style="display:none;">
        <label>HM2I collegati (visibili a questo Sector Manager)</label>
        <div class="chips-multi">
          <?php foreach ($hm2iTutti as $h): ?>
            <label><input type="checkbox" name="hm2i_collegati[]" value="<?= $h['id'] ?>" class="chk-hm2i"> <?= htmlspecialchars($h['cognome'].' '.$h['nome']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="chiudiModaleAccount()">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<script>
function aggiornaCampiRuolo() {
  const ruolo = document.getElementById('f-ruolo').value;
  document.getElementById('blocco-zone').style.display = ['hemi','hm2i','sector_manager'].includes(ruolo) ? 'block' : 'none';
  document.getElementById('blocco-aree').style.display = ruolo === 'hemi' ? 'block' : 'none';
  document.getElementById('blocco-hm2i-collegati').style.display = ruolo === 'sector_manager' ? 'block' : 'none';
}

function resetChecks() {
  document.querySelectorAll('.chk-zona, .chk-area, .chk-hm2i').forEach(c => c.checked = false);
}

function apriNuovoAccount() {
  document.getElementById('modal-account-title').textContent = 'Nuovo account';
  document.getElementById('form-account').reset();
  resetChecks();
  document.getElementById('f-azione').value = 'inserisci';
  document.getElementById('f-id').value = '';
  document.getElementById('f-password').required = true;
  document.getElementById('f-password-hint').textContent = '(obbligatoria)';
  aggiornaCampiRuolo();
  document.getElementById('modal-account').classList.add('open');
}

function apriModificaAccount(acc, zoneIds, areaIds, hm2iIds) {
  document.getElementById('modal-account-title').textContent = 'Modifica account';
  document.getElementById('f-azione').value = 'modifica';
  document.getElementById('f-id').value = acc.id;
  document.getElementById('f-nome').value = acc.nome;
  document.getElementById('f-cognome').value = acc.cognome;
  document.getElementById('f-ruolo').value = acc.ruolo;
  document.getElementById('f-telefono').value = acc.telefono || '';
  document.getElementById('f-email').value = acc.email;
  document.getElementById('f-login').value = acc.login;
  document.getElementById('f-password').value = '';
  document.getElementById('f-password').required = false;
  document.getElementById('f-password-hint').textContent = '(lascia vuoto per non modificarla)';
  document.getElementById('f-ore').value = acc.ore_contratto || '';
  document.getElementById('f-compenso').value = acc.compenso_orario || '';

  resetChecks();
  zoneIds.forEach(id => { const el = document.querySelector(`.chk-zona[value="${id}"]`); if (el) el.checked = true; });
  areaIds.forEach(id => { const el = document.querySelector(`.chk-area[value="${id}"]`); if (el) el.checked = true; });
  hm2iIds.forEach(id => { const el = document.querySelector(`.chk-hm2i[value="${id}"]`); if (el) el.checked = true; });

  aggiornaCampiRuolo();
  document.getElementById('modal-account').classList.add('open');
}

function chiudiModaleAccount() {
  document.getElementById('modal-account').classList.remove('open');
}
document.getElementById('modal-account').addEventListener('click', (ev) => {
  if (ev.target.id === 'modal-account') chiudiModaleAccount();
});
</script>
</body>
</html>

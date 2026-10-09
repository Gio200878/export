<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logic.php';
auth_require_admin();

// ------------------------------------------------------------------
// SOSPENSIONI HEMI (giorni interi o solo alcune ore)
// ------------------------------------------------------------------
$erroreSosp = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';
    if ($azione === 'sospensione_elimina') {
        db()->prepare('DELETE FROM hemi_sospensioni WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: appuntamenti_admin.php?sosp=ok#sospensioni');
        exit;
    }
    if ($azione === 'sospensione_aggiungi') {
        $hemiId = (int)($_POST['hemi_id'] ?? 0);
        $dal = $_POST['data_inizio'] ?? '';
        $al = ($_POST['data_fine'] ?? '') ?: $dal;
        $soloOre = !empty($_POST['solo_ore']);
        $oraDa = $soloOre ? ($_POST['ora_inizio'] ?? '') : null;
        $oraA = $soloOre ? ($_POST['ora_fine'] ?? '') : null;
        $motivo = trim($_POST['motivo'] ?? '') ?: null;

        $chk = db()->prepare("SELECT 1 FROM accounts WHERE id = ? AND ruolo = 'hemi'");
        $chk->execute([$hemiId]);
        if (!$chk->fetchColumn()) {
            $erroreSosp = 'Seleziona un HEMI.';
        } elseif (!$dal || strtotime($dal) === false || strtotime($al) === false) {
            $erroreSosp = 'Inserisci le date della sospensione.';
        } elseif ($al < $dal) {
            $erroreSosp = 'La data "al" deve essere uguale o successiva alla data "dal".';
        } elseif ($soloOre && (!$oraDa || !$oraA || $oraA <= $oraDa)) {
            $erroreSosp = 'Inserisci un intervallo orario valido (ora fine successiva all\'ora inizio).';
        } else {
            db()->prepare('INSERT INTO hemi_sospensioni (hemi_id, data_inizio, data_fine, ora_inizio, ora_fine, motivo) VALUES (?,?,?,?,?,?)')
                ->execute([$hemiId, $dal, $al, $oraDa, $oraA, $motivo]);
            header('Location: appuntamenti_admin.php?sosp=ok#sospensioni');
            exit;
        }
    }
}

// Filtri da querystring
$filtri = array_filter([
    'stato' => $_GET['stato'] ?? '',
    'hm2i_id' => $_GET['hm2i_id'] ?? '',
    'hemi_id' => $_GET['hemi_id'] ?? '',
    'area_id' => $_GET['area_id'] ?? '',
    'zona_id' => $_GET['zona_id'] ?? '',
], fn($v) => $v !== '');

// Recupera TUTTI gli appuntamenti (nessun limite di intervallo) applicando i filtri
$sql = "SELECT ap.*, a.nome AS area_nome, hm.nome AS hm2i_nome, hm.cognome AS hm2i_cognome,
               he.nome AS hemi_nome, he.cognome AS hemi_cognome,
               " . SQL_IN_ATTESA . " AS in_attesa
        FROM appuntamenti ap
        JOIN aree_intervento a ON a.id = ap.area_id
        JOIN accounts hm ON hm.id = ap.hm2i_id
        LEFT JOIN accounts he ON he.id = ap.hemi_id
        WHERE 1=1";
$params = [];
if (!empty($filtri['stato'])) { $sql .= ' AND ap.stato = ?'; $params[] = $filtri['stato']; }
if (!empty($filtri['hm2i_id'])) { $sql .= ' AND ap.hm2i_id = ?'; $params[] = $filtri['hm2i_id']; }
if (!empty($filtri['hemi_id'])) { $sql .= ' AND ap.hemi_id = ?'; $params[] = $filtri['hemi_id']; }
if (!empty($filtri['area_id'])) { $sql .= ' AND ap.area_id = ?'; $params[] = $filtri['area_id']; }
if (!empty($filtri['zona_id'])) {
    $sql .= ' AND ap.hm2i_id IN (SELECT account_id FROM account_zone WHERE zona_id = ?)';
    $params[] = $filtri['zona_id'];
}
$sql .= ' ORDER BY ap.data_appuntamento DESC, ap.ora_inizio DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$appuntamenti = $stmt->fetchAll();

// Totali
$totOre = 0; $totCompensi = 0; $totRimborsi = 0;
foreach ($appuntamenti as $a) {
    $inizio = strtotime($a['data_appuntamento'] . ' ' . $a['ora_inizio']);
    $fine = strtotime($a['data_appuntamento'] . ' ' . $a['ora_fine']);
    $totOre += max(0, ($fine - $inizio) / 3600);
    $totCompensi += (float)($a['compenso'] ?? 0);
    $totRimborsi += (float)($a['rimborso_spese'] ?? 0);
}

// Opzioni per i filtri
$aree = get_aree(false);
$zone = get_zone(false);
$hm2iList = db()->query("SELECT id, nome, cognome FROM accounts WHERE ruolo = 'hm2i' ORDER BY cognome")->fetchAll();
$hemiList = db()->query("SELECT id, nome, cognome FROM accounts WHERE ruolo = 'hemi' ORDER BY cognome")->fetchAll();

// ------------------------------------------------------------------
// RIEPILOGO ORE CONSUMATE PER HEMI (solo appuntamenti approvati)
// Periodo: filtro libero, default mese corrente
// ------------------------------------------------------------------
$periodoInizio = $_GET['periodo_inizio'] ?? date('Y-m-01');
$periodoFine = $_GET['periodo_fine'] ?? date('Y-m-t');

$stmtOre = db()->prepare(
    "SELECT ap.hemi_id, he.nome, he.cognome, he.ore_contratto,
            ap.data_appuntamento, ap.ora_inizio, ap.ora_fine
     FROM appuntamenti ap
     JOIN accounts he ON he.id = ap.hemi_id
     WHERE ap.stato = 'approvato'
       AND ap.hemi_id IS NOT NULL
       AND ap.data_appuntamento BETWEEN ? AND ?"
);
$stmtOre->execute([$periodoInizio, $periodoFine]);
$righeOre = $stmtOre->fetchAll();

$riepilogoOre = []; // hemi_id => ['nome'=>..,'cognome'=>..,'ore_contratto'=>..,'ore_consumate'=>..]
foreach ($righeOre as $r) {
    $id = $r['hemi_id'];
    if (!isset($riepilogoOre[$id])) {
        $riepilogoOre[$id] = [
            'nome' => $r['nome'],
            'cognome' => $r['cognome'],
            'ore_contratto' => $r['ore_contratto'],
            'ore_consumate' => 0,
        ];
    }
    $inizio = strtotime($r['data_appuntamento'] . ' ' . $r['ora_inizio']);
    $fine = strtotime($r['data_appuntamento'] . ' ' . $r['ora_fine']);
    $riepilogoOre[$id]['ore_consumate'] += max(0, ($fine - $inizio) / 3600);
}
// Ordina per cognome
uasort($riepilogoOre, fn($a, $b) => strcmp($a['cognome'], $b['cognome']));

$sospensioni = db()->query(
    "SELECT s.*, he.nome, he.cognome FROM hemi_sospensioni s JOIN accounts he ON he.id = s.hemi_id
     WHERE s.data_fine >= CURDATE() ORDER BY s.data_inizio, he.cognome"
)->fetchAll();

function fmt_data_it($d) { return date('d/m/Y', strtotime($d)); }

function badge_stato_html($stato, $inAttesa = false) {
    if ($inAttesa) return '<span class="badge badge-blu">Richiesta info</span>';
    $map = ['da_approvare' => ['badge-giallo','Da approvare'], 'approvato' => ['badge-verde','Approvato'], 'rifiutato' => ['badge-rosso','Rifiutato']];
    [$cls, $label] = $map[$stato] ?? ['','?'];
    return "<span class=\"badge $cls\">$label</span>";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestione Appuntamenti - HEMI Gestionale</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php include __DIR__ . '/lib/header.php'; ?>
<div class="main-content" style="max-width:1200px;margin:0 auto;">
  <h2>Gestione Appuntamenti</h2>

  <form method="get" class="filters-bar">
    <select name="stato">
      <option value="">Stato: tutti</option>
      <option value="da_approvare" <?= ($filtri['stato']??'')==='da_approvare'?'selected':'' ?>>Da approvare</option>
      <option value="approvato" <?= ($filtri['stato']??'')==='approvato'?'selected':'' ?>>Approvato</option>
      <option value="rifiutato" <?= ($filtri['stato']??'')==='rifiutato'?'selected':'' ?>>Rifiutato</option>
    </select>
    <select name="hm2i_id">
      <option value="">HM2I: tutti</option>
      <?php foreach ($hm2iList as $h): ?>
        <option value="<?= $h['id'] ?>" <?= ($filtri['hm2i_id']??'')==$h['id']?'selected':'' ?>><?= htmlspecialchars($h['cognome'].' '.$h['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="hemi_id">
      <option value="">HEMI: tutti</option>
      <?php foreach ($hemiList as $h): ?>
        <option value="<?= $h['id'] ?>" <?= ($filtri['hemi_id']??'')==$h['id']?'selected':'' ?>><?= htmlspecialchars($h['cognome'].' '.$h['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="area_id">
      <option value="">Area: tutte</option>
      <?php foreach ($aree as $a): ?>
        <option value="<?= $a['id'] ?>" <?= ($filtri['area_id']??'')==$a['id']?'selected':'' ?>><?= htmlspecialchars($a['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="zona_id">
      <option value="">Zona: tutte</option>
      <?php foreach ($zone as $z): ?>
        <option value="<?= $z['id'] ?>" <?= ($filtri['zona_id']??'')==$z['id']?'selected':'' ?>><?= htmlspecialchars($z['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Filtra</button>
    <a class="btn btn-secondary" href="appuntamenti_admin.php">Reset</a>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th>Data</th><th>Orario</th><th>Stato</th><th>Area</th><th>Salone</th><th>HM2I</th><th>HEMI</th><th>Compenso</th><th>Rimborso</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($appuntamenti as $a): ?>
      <tr class="<?= $a['in_attesa'] ? 'in-attesa' : '' ?>" onclick="apriDettaglioAdmin(<?= $a['id'] ?>)">
        <td><?= htmlspecialchars($a['data_appuntamento']) ?></td>
        <td><?= substr($a['ora_inizio'],0,5) ?>-<?= substr($a['ora_fine'],0,5) ?></td>
        <td><?= badge_stato_html($a['stato'], $a['in_attesa']) ?></td>
        <td><?= htmlspecialchars($a['area_nome']) ?></td>
        <td><?= htmlspecialchars($a['salone']) ?></td>
        <td><?= htmlspecialchars($a['hm2i_cognome'].' '.$a['hm2i_nome']) ?></td>
        <td><?= $a['hemi_nome'] ? htmlspecialchars($a['hemi_cognome'].' '.$a['hemi_nome']) : '-' ?></td>
        <td><?= $a['compenso'] !== null ? number_format($a['compenso'],2,',','.').' €' : '-' ?></td>
        <td><?= $a['rimborso_spese'] !== null ? number_format($a['rimborso_spese'],2,',','.').' €' : '-' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="7">TOTALI</td>
        <td colspan="1">Ore: <?= number_format($totOre,1,',','.') ?></td>
        <td>Comp: <?= number_format($totCompensi,2,',','.') ?> € / Rimb: <?= number_format($totRimborsi,2,',','.') ?> €</td>
      </tr>
    </tfoot>
  </table>

  <h2 id="sospensioni" style="margin-top:36px;">Sospensioni HEMI</h2>
  <?php if ($erroreSosp): ?><div class="alert alert-error"><?= htmlspecialchars($erroreSosp) ?></div><?php endif; ?>
  <?php if (($_GET['sosp'] ?? '') === 'ok'): ?><div class="alert alert-success">Sospensioni aggiornate.</div><?php endif; ?>
  <form method="post" action="appuntamenti_admin.php#sospensioni" class="filters-bar" style="align-items:center;">
    <input type="hidden" name="azione" value="sospensione_aggiungi">
    <select name="hemi_id" required>
      <option value="">HEMI...</option>
      <?php foreach ($hemiList as $h): ?>
        <option value="<?= $h['id'] ?>" <?= ($_POST['hemi_id'] ?? '')==$h['id']?'selected':'' ?>><?= htmlspecialchars($h['cognome'].' '.$h['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <label style="font-size:13px;color:var(--text-light);">Dal</label>
    <input type="date" name="data_inizio" required value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>">
    <label style="font-size:13px;color:var(--text-light);">al</label>
    <input type="date" name="data_fine" value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>">
    <label style="font-size:13px;"><input type="checkbox" name="solo_ore" id="sosp-solo-ore" value="1" onchange="toggleOreSosp()" <?= !empty($_POST['solo_ore'])?'checked':'' ?>> Solo alcune ore</label>
    <span id="sosp-ore" style="display:none;align-items:center;gap:6px;">
      <label style="font-size:13px;color:var(--text-light);">dalle</label>
      <input type="time" name="ora_inizio" value="<?= htmlspecialchars($_POST['ora_inizio'] ?? '') ?>">
      <label style="font-size:13px;color:var(--text-light);">alle</label>
      <input type="time" name="ora_fine" value="<?= htmlspecialchars($_POST['ora_fine'] ?? '') ?>">
    </span>
    <input type="text" name="motivo" placeholder="Motivo (facoltativo)" maxlength="255" value="<?= htmlspecialchars($_POST['motivo'] ?? '') ?>">
    <button class="btn btn-primary" type="submit">Aggiungi sospensione</button>
  </form>
  <p style="font-size:12px;color:var(--text-light);margin-top:-6px;">Lasciando vuoto "al" la sospensione vale per il solo giorno "dal". Con "Solo alcune ore" l'intervallo orario vale per ogni giorno del periodo.</p>

  <table class="data-table">
    <thead><tr><th>HEMI</th><th>Periodo</th><th>Orario</th><th>Motivo</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($sospensioni)): ?>
        <tr><td colspan="5" style="color:var(--text-light);">Nessuna sospensione in corso o futura.</td></tr>
      <?php endif; ?>
      <?php foreach ($sospensioni as $sp): ?>
        <tr>
          <td><?= htmlspecialchars($sp['cognome'].' '.$sp['nome']) ?></td>
          <td><?= fmt_data_it($sp['data_inizio']) ?><?= $sp['data_fine'] !== $sp['data_inizio'] ? ' - ' . fmt_data_it($sp['data_fine']) : '' ?></td>
          <td><?= ($sp['ora_inizio'] !== null && $sp['ora_fine'] !== null) ? substr($sp['ora_inizio'],0,5).'-'.substr($sp['ora_fine'],0,5) : 'Giorni interi' ?></td>
          <td><?= htmlspecialchars($sp['motivo'] ?? '') ?></td>
          <td>
            <form method="post" action="appuntamenti_admin.php#sospensioni" style="display:inline;" onsubmit="return confirm('Eliminare questa sospensione?');">
              <input type="hidden" name="azione" value="sospensione_elimina">
              <input type="hidden" name="id" value="<?= $sp['id'] ?>">
              <button class="btn btn-sm btn-reject" type="submit">Elimina</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2 style="margin-top:36px;">Ore consumate per HEMI</h2>
  <form method="get" class="filters-bar" style="align-items:center;">
    <?php foreach (['stato','hm2i_id','hemi_id','area_id','zona_id'] as $f): ?>
      <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars($filtri[$f] ?? '') ?>">
    <?php endforeach; ?>
    <label style="font-size:13px;color:var(--text-light);">Dal</label>
    <input type="date" name="periodo_inizio" value="<?= htmlspecialchars($periodoInizio) ?>">
    <label style="font-size:13px;color:var(--text-light);">al</label>
    <input type="date" name="periodo_fine" value="<?= htmlspecialchars($periodoFine) ?>">
    <button class="btn btn-secondary" type="submit">Aggiorna periodo</button>
  </form>
  <p style="font-size:12px;color:var(--text-light);margin-top:-6px;">Calcolato solo sugli appuntamenti <strong>approvati</strong> nel periodo selezionato.</p>

  <table class="data-table">
    <thead>
      <tr><th>HEMI</th><th>Ore da contratto</th><th>Ore consumate nel periodo</th><th>Residue</th></tr>
    </thead>
    <tbody>
      <?php if (empty($riepilogoOre)): ?>
        <tr><td colspan="4" style="color:var(--text-light);">Nessun appuntamento approvato nel periodo selezionato.</td></tr>
      <?php endif; ?>
      <?php foreach ($riepilogoOre as $r): ?>
        <?php
          $contratto = $r['ore_contratto'];
          $residue = $contratto !== null ? $contratto - $r['ore_consumate'] : null;
        ?>
        <tr>
          <td><?= htmlspecialchars($r['cognome'] . ' ' . $r['nome']) ?></td>
          <td><?= $contratto !== null ? number_format($contratto,1,',','.') : '-' ?></td>
          <td><?= number_format($r['ore_consumate'],1,',','.') ?></td>
          <td style="<?= ($residue !== null && $residue < 0) ? 'color:var(--rosso);font-weight:700;' : '' ?>">
            <?= $residue !== null ? number_format($residue,1,',','.') : '-' ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- MODALE dettaglio/gestione -->
<div class="modal-overlay" id="modal-admin">
  <div class="modal-box">
    <h2>Dettaglio appuntamento</h2>
    <div id="modal-admin-body"></div>
  </div>
</div>

<script>
function toggleOreSosp() {
  document.getElementById('sosp-ore').style.display = document.getElementById('sosp-solo-ore').checked ? 'inline-flex' : 'none';
}
toggleOreSosp();

const HEMI_LIST = <?= json_encode($hemiList) ?>;

async function apriDettaglioAdmin(id) {
  const res = await fetch('api.php?action=get_appuntamento&id=' + id);
  const data = await res.json();
  if (!data.ok) return;
  const a = data.appuntamento;

  const badgeMap = { approvato: ['badge-verde','Approvato'], rifiutato: ['badge-rosso','Rifiutato'], da_approvare: ['badge-giallo','Da approvare'] };
  const [cls, label] = badgeMap[a.stato] || ['',''];

  document.getElementById('modal-admin-body').innerHTML = `
    <span class="badge ${cls}">${label}</span>
    <p><strong>${a.salone}</strong> - ${a.area_nome}</p>
    <p>${a.indirizzo} ${a.telefono ? '- Tel: ' + a.telefono : ''} - ZTL: ${a.ztl === 'si' ? 'Si' : 'No'}</p>
    <p>HM2I: ${a.hm2i_cognome} ${a.hm2i_nome}</p>
    ${a.note ? '<p>Note: ' + a.note + '</p>' : ''}

    <form id="form-modifica-admin">
      <input type="hidden" name="id" value="${a.id}">
      <div class="row">
        <div><label>Data</label><input type="date" name="data_appuntamento" value="${a.data_appuntamento}"></div>
      </div>
      <div class="row">
        <div><label>Ora inizio</label><input type="time" name="ora_inizio" value="${a.ora_inizio.slice(0,5)}"></div>
        <div><label>Ora fine</label><input type="time" name="ora_fine" value="${a.ora_fine.slice(0,5)}"></div>
      </div>
      <label>HEMI ${a.stato !== 'approvato' ? '(obbligatorio per approvare)' : ''}</label>
      <select name="hemi_id">
        <option value="">-- nessuno --</option>
        ${HEMI_LIST.map(h => `<option value="${h.id}" ${String(h.id) === String(a.hemi_id) ? 'selected' : ''}>${h.cognome} ${h.nome}</option>`).join('')}
      </select>
      <div class="row">
        <div><label>Compenso (€)</label><input type="number" step="0.01" name="compenso" value="${a.compenso ?? ''}"></div>
        <div><label>Rimborso spese (€)</label><input type="number" step="0.01" name="rimborso_spese" value="${a.rimborso_spese ?? ''}"></div>
      </div>
      <div class="chatbox">
        <h4>Messaggi con l'HEMI ${a.in_attesa ? '<span class="badge badge-blu">in attesa di risposta</span>' : ''}</h4>
        <div class="chat-messaggi" id="chat-messaggi"></div>
        <div id="chat-errore" class="alert alert-error" style="display:none;"></div>
        <div class="chat-form">
          <textarea id="chat-testo" rows="2" maxlength="2000" placeholder="Rispondi all'HEMI..."></textarea>
          <button type="button" class="btn btn-primary" onclick="inviaChat(${a.id})">Rispondi</button>
        </div>
      </div>
      <div id="admin-errore" class="alert alert-error" style="display:none;"></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="chiudiModaleAdmin()">Chiudi</button>
        <button type="submit" class="btn btn-primary">Salva modifiche</button>
      </div>
    </form>

    <div class="modal-actions">
      ${a.stato !== 'approvato' ? `<button class="btn btn-approve" onclick="cambiaStato(${a.id}, 'approvato')">Approva</button>` : ''}
      ${a.stato !== 'rifiutato' ? `<button class="btn btn-reject" onclick="cambiaStato(${a.id}, 'rifiutato')">Rifiuta</button>` : ''}
      <button class="btn btn-reject" onclick="eliminaAppuntamento(${a.id})">Elimina appuntamento</button>
    </div>
  `;

  document.getElementById('form-modifica-admin').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const payload = Object.fromEntries(new FormData(ev.target).entries());
    const res = await fetch('api.php?action=modifica_appuntamento', {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    const d = await res.json();
    if (d.ok) { location.reload(); return; }
    const errEl = document.getElementById('admin-errore');
    errEl.textContent = d.error || 'Errore.';
    errEl.style.display = 'block';
    errEl.style.fontWeight = '700';
  });

  document.getElementById('modal-admin').classList.add('open');
  caricaChat(a.id);
}

function escHtml(t) {
  return String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function renderChat(messaggi) {
  const box = document.getElementById('chat-messaggi');
  box.innerHTML = messaggi.length
    ? messaggi.map(m => `<div class="chat-msg ${m.mittente_ruolo === 'admin' ? 'mio' : 'admin'}">
        <div class="chat-meta">${escHtml(m.mittente_ruolo === 'admin' ? 'ADMIN' : m.cognome + ' ' + m.nome)} - ${escHtml(m.created_at)}</div>
        ${escHtml(m.testo).replace(/\n/g, '<br>')}</div>`).join('')
    : '<div class="chat-vuoto">Nessun messaggio.</div>';
  box.scrollTop = box.scrollHeight;
}

async function caricaChat(appId) {
  const res = await fetch('api.php?action=get_messaggi&appuntamento_id=' + appId);
  const data = await res.json();
  if (data.ok) renderChat(data.messaggi);
}

// Rispondendo, l'appuntamento torna al colore dello stato (verde/giallo/rosso)
async function inviaChat(appId) {
  const txt = document.getElementById('chat-testo');
  const err = document.getElementById('chat-errore');
  err.style.display = 'none';
  const res = await fetch('api.php?action=invia_messaggio', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ appuntamento_id: appId, testo: txt.value }),
  });
  const data = await res.json();
  if (!data.ok) { err.textContent = data.error || 'Errore.'; err.style.display = 'block'; return; }
  location.reload();
}

async function cambiaStato(id, stato) {
  const form = document.getElementById('form-modifica-admin');
  const payload = Object.fromEntries(new FormData(form).entries());
  payload.id = id;
  payload.stato = stato;

  if (stato === 'approvato' && !payload.hemi_id) {
    const errEl = document.getElementById('admin-errore');
    errEl.textContent = 'Seleziona un HEMI per approvare l\'appuntamento.';
    errEl.style.display = 'block';
    return;
  }

  const res = await fetch('api.php?action=aggiorna_stato', {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
  });
  const d = await res.json();
  if (d.ok) {
    location.reload();
  } else {
    const errEl = document.getElementById('admin-errore');
    errEl.textContent = d.error || 'Errore.';
    errEl.style.display = 'block';
    errEl.style.fontWeight = '700';
  }
}

async function eliminaAppuntamento(id) {
  if (!confirm('Eliminare definitivamente questo appuntamento?')) return;
  const res = await fetch('api.php?action=elimina_appuntamento', {
    method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id })
  });
  const d = await res.json();
  if (d.ok) location.reload();
}

function chiudiModaleAdmin() {
  document.getElementById('modal-admin').classList.remove('open');
}
document.getElementById('modal-admin').addEventListener('click', (ev) => {
  if (ev.target.id === 'modal-admin') chiudiModaleAdmin();
});
</script>
</body>
</html>

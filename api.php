<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logic.php';
require_once __DIR__ . '/lib/whatsapp.php';

header('Content-Type: application/json; charset=utf-8');
auth_check();

$action = $_REQUEST['action'] ?? '';

function json_out($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

switch ($action) {

    // -----------------------------------------------------------
    // Restituisce gli appuntamenti visibili in un intervallo di date
    // -----------------------------------------------------------
    case 'get_appuntamenti':
        $inizio = $_GET['inizio'] ?? date('Y-m-01');
        $fine = $_GET['fine'] ?? date('Y-m-t');
        $filtri = [
            'hm2i_id' => $_GET['hm2i_id'] ?? null,
            'area_id' => $_GET['area_id'] ?? null,
            'hemi_id' => $_GET['hemi_id'] ?? null,
            'zona_id' => $_GET['zona_id'] ?? null,
            'stato'   => $_GET['stato'] ?? null,
        ];
        $rows = get_appuntamenti($inizio, $fine, array_filter($filtri, fn($v) => $v !== null && $v !== ''));
        foreach ($rows as &$r) {
            $r['colore'] = $r['_offuscato'] ? '#999999' : (!empty($r['in_attesa']) ? COLORE_IN_ATTESA : colore_stato($r['stato']));
        }
        json_out(['ok' => true, 'appuntamenti' => $rows]);
        break;

    // -----------------------------------------------------------
    // Elenco HM2I selezionabili dall'utente corrente
    // -----------------------------------------------------------
    case 'get_hm2i_selezionabili':
        json_out(['ok' => true, 'hm2i' => get_hm2i_selezionabili()]);
        break;

    // -----------------------------------------------------------
    // Elenco aree e zone (per i filtri e la form)
    // -----------------------------------------------------------
    case 'get_opzioni':
        json_out([
            'ok' => true,
            'aree' => get_aree(),
            'zone' => get_zone(),
        ]);
        break;

    // -----------------------------------------------------------
    // Anagrafica saloni + inserimento nuovo salone
    // -----------------------------------------------------------
    case 'get_saloni':
        json_out(['ok' => true, 'saloni' => get_saloni(((int)($_GET['hm2i_id'] ?? 0)) ?: null)]);
        break;

    case 'crea_salone':
        if (!in_array(current_user_role(), ['admin', 'sector_manager', 'hm2i'], true)) {
            json_out(['ok' => false, 'error' => 'Non autorizzato.'], 403);
        }
        $d = input();
        $nome = trim($d['nome'] ?? '');
        $codice = trim($d['codice'] ?? '');
        $prov = strtoupper(trim($d['prov'] ?? ''));
        if ($nome === '') json_out(['ok' => false, 'error' => 'Il nome del salone è obbligatorio.'], 400);
        $hm2iSalone = (int)($d['hm2i_id'] ?? 0);
        if (current_user_role() === 'hm2i') $hm2iSalone = current_user_id();
        if (!$hm2iSalone || !can_create_for_hm2i($hm2iSalone)) {
            json_out(['ok' => false, 'error' => 'Seleziona un HM2I valido per il nuovo salone.'], 400);
        }
        if ($codice !== '') {
            $stmt = db()->prepare('SELECT 1 FROM saloni WHERE codice = ?');
            $stmt->execute([$codice]);
            if ($stmt->fetchColumn()) json_out(['ok' => false, 'error' => 'Esiste già un salone con questo codice.'], 409);
        }
        db()->prepare('INSERT INTO saloni (codice, nome, prov, hm2i_id) VALUES (?, ?, ?, ?)')
            ->execute([$codice ?: null, $nome, $prov ?: null, $hm2iSalone]);
        $id = (int)db()->lastInsertId();
        json_out(['ok' => true, 'salone' => ['id' => $id, 'codice' => $codice ?: null, 'nome' => $nome, 'prov' => $prov ?: null, 'hm2i_id' => $hm2iSalone]]);
        break;

    // -----------------------------------------------------------
    // HEMI selezionabili per area d'intervento + zone dell'HM2I
    // -----------------------------------------------------------
    case 'get_hemi_per_area':
        $areaId = (int)($_GET['area_id'] ?? 0);
        $hm2iId = (int)($_GET['hm2i_id'] ?? 0);
        if (!$areaId || !$hm2iId) json_out(['ok' => true, 'hemi' => []]);
        json_out(['ok' => true, 'hemi' => get_hemi_per_area_e_hm2i($areaId, $hm2iId)]);
        break;

    // -----------------------------------------------------------
    // Verifica disponibilità HEMI (usata anche in tempo reale dalla form)
    // -----------------------------------------------------------
    case 'check_disponibilita':
        $hemiId = (int)($_GET['hemi_id'] ?? 0);
        $data = $_GET['data'] ?? '';
        $oi = $_GET['ora_inizio'] ?? '';
        $of = $_GET['ora_fine'] ?? '';
        if (!$hemiId || !$data || !$oi || !$of) json_out(['ok' => true, 'disponibile' => true]);
        json_out(['ok' => true, 'disponibile' => hemi_disponibile($hemiId, $data, $oi, $of),
                  'messaggio' => MSG_HEMI_NON_DISPONIBILE]);
        break;

    // -----------------------------------------------------------
    // Crea un nuovo appuntamento
    // -----------------------------------------------------------
    case 'crea_appuntamento':
        $d = input();
        $hm2iId = (int)($d['hm2i_id'] ?? 0);

        if (!can_create_for_hm2i($hm2iId)) {
            json_out(['ok' => false, 'error' => 'Non autorizzato a creare appuntamenti per questo HM2I.'], 403);
        }

        $required = ['area_id', 'hm2i_id', 'salone', 'indirizzo', 'data_appuntamento', 'ora_inizio', 'ora_fine'];
        foreach ($required as $f) {
            if (empty($d[$f])) {
                json_out(['ok' => false, 'error' => "Campo obbligatorio mancante: $f"], 400);
            }
        }

        if ($d['ora_fine'] <= $d['ora_inizio']) {
            json_out(['ok' => false, 'error' => "L'ora di fine deve essere successiva all'ora di inizio."], 400);
        }

        // HEMI scelto (facoltativo): deve essere compatibile con area/zona e disponibile
        $hemiId = (int)($d['hemi_id'] ?? 0) ?: null;
        if ($hemiId) {
            $ammessi = array_column(get_hemi_per_area_e_hm2i((int)$d['area_id'], $hm2iId), 'id');
            if (!in_array($hemiId, array_map('intval', $ammessi), true)) {
                json_out(['ok' => false, 'error' => 'HEMI non valido per l\'area e la zona selezionate.'], 400);
            }
            if (!hemi_disponibile($hemiId, $d['data_appuntamento'], $d['ora_inizio'], $d['ora_fine'])) {
                json_out(['ok' => false, 'error' => MSG_HEMI_NON_DISPONIBILE], 409);
            }
        }
        $saloneId = (int)($d['salone_id'] ?? 0) ?: null;
        if ($saloneId) {
            // Il salone deve appartenere all'HM2I dell'appuntamento (gli admin possono usare anche saloni senza HM2I)
            $stmt = db()->prepare('SELECT hm2i_id FROM saloni WHERE id = ?');
            $stmt->execute([$saloneId]);
            $rowS = $stmt->fetch();
            if (!$rowS || ($rowS['hm2i_id'] !== null && (int)$rowS['hm2i_id'] !== $hm2iId)
                || ($rowS['hm2i_id'] === null && current_user_role() !== 'admin')) {
                json_out(['ok' => false, 'error' => 'Salone non valido per questo HM2I.'], 403);
            }
        }

        // Lo stato resta "da approvare" (giallo) per chi non è admin
        $stato = stato_iniziale_per_ruolo();

        $stmt = db()->prepare(
            'INSERT INTO appuntamenti
                (area_id, hm2i_id, hemi_id, salone_id, salone, indirizzo, telefono, ztl, note, data_appuntamento, ora_inizio, ora_fine, stato, creato_da, approvato_da, approvato_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $approvatoDa = $stato === 'approvato' ? current_user_id() : null;
        $approvatoAt = $stato === 'approvato' ? date('Y-m-d H:i:s') : null;
        $stmt->execute([
            $d['area_id'], $hm2iId, $hemiId, $saloneId, $d['salone'], $d['indirizzo'],
            $d['telefono'] ?? null, ($d['ztl'] ?? 'no') === 'si' ? 'si' : 'no',
            $d['note'] ?? null, $d['data_appuntamento'], $d['ora_inizio'], $d['ora_fine'],
            $stato, current_user_id(), $approvatoDa, $approvatoAt,
        ]);
        $id = (int)db()->lastInsertId();

        if ($stato === 'da_approvare') {
            wa_notifica_nuovo_appuntamento(['id' => $id, 'salone' => $d['salone'], 'data_appuntamento' => $d['data_appuntamento']]);
        }

        json_out(['ok' => true, 'id' => $id, 'stato' => $stato]);
        break;

    // -----------------------------------------------------------
    // Dettaglio di un singolo appuntamento (rispetta le regole di visibilità)
    // -----------------------------------------------------------
    case 'get_appuntamento':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = db()->prepare(
            "SELECT ap.*, a.nome AS area_nome, hm.nome AS hm2i_nome, hm.cognome AS hm2i_cognome,
                    he.nome AS hemi_nome, he.cognome AS hemi_cognome
             FROM appuntamenti ap
             JOIN aree_intervento a ON a.id = ap.area_id
             JOIN accounts hm ON hm.id = ap.hm2i_id
             LEFT JOIN accounts he ON he.id = ap.hemi_id
             WHERE ap.id = ?"
        );
        $stmt->execute([$id]);
        $app = $stmt->fetch();
        if (!$app) json_out(['ok' => false, 'error' => 'Appuntamento non trovato.'], 404);

        // L'HEMI non vede appuntamenti altrui, nemmeno come "occupato"
        if (current_user_role() === 'hemi' && (int)($app['hemi_id'] ?? 0) !== current_user_id()) {
            json_out(['ok' => false, 'error' => 'Appuntamento non trovato.'], 404);
        }
        if (!can_view_dettagli($app)) {
            $nomeVisibile = trim(($app['hemi_nome'] ?? '') . ' ' . ($app['hemi_cognome'] ?? '')) ?: trim($app['hm2i_nome'] . ' ' . $app['hm2i_cognome']);
            json_out(['ok' => true, 'appuntamento' => [
                'id' => $app['id'], 'occupato' => true, 'nome_visibile_occupato' => $nomeVisibile,
                'data_appuntamento' => $app['data_appuntamento'], 'ora_inizio' => $app['ora_inizio'], 'ora_fine' => $app['ora_fine'],
            ]]);
        }
        $app['occupato'] = false;
        $app['in_attesa'] = can_chat_appuntamento($app) && in_attesa_risposta((int)$app['id']);
        $app['puo_chat'] = can_chat_appuntamento($app);
        json_out(['ok' => true, 'appuntamento' => $app]);
        break;

    // -----------------------------------------------------------
    // Chat HEMI <-> ADMIN su un appuntamento
    // -----------------------------------------------------------
    case 'get_messaggi':
    case 'invia_messaggio':
        $d = $action === 'invia_messaggio' ? input() : $_GET;
        $appId = (int)($d['appuntamento_id'] ?? 0);
        $stmt = db()->prepare('SELECT id, hemi_id FROM appuntamenti WHERE id = ?');
        $stmt->execute([$appId]);
        $appChat = $stmt->fetch();
        if (!$appChat || !can_chat_appuntamento($appChat)) {
            json_out(['ok' => false, 'error' => 'Non autorizzato.'], 403);
        }
        if ($action === 'invia_messaggio') {
            $testo = trim((string)($d['testo'] ?? ''));
            if ($testo === '') json_out(['ok' => false, 'error' => 'Scrivi un messaggio.'], 400);
            if (mb_strlen($testo) > 2000) json_out(['ok' => false, 'error' => 'Messaggio troppo lungo (max 2000 caratteri).'], 400);
            db()->prepare('INSERT INTO messaggi (appuntamento_id, mittente_id, mittente_ruolo, testo) VALUES (?,?,?,?)')
                ->execute([$appId, current_user_id(), current_user_role(), $testo]);
        }
        $stmt = db()->prepare(
            'SELECT m.id, m.mittente_ruolo, m.testo, m.created_at, a.nome, a.cognome
             FROM messaggi m JOIN accounts a ON a.id = m.mittente_id
             WHERE m.appuntamento_id = ? ORDER BY m.id'
        );
        $stmt->execute([$appId]);
        json_out(['ok' => true, 'messaggi' => $stmt->fetchAll(), 'in_attesa' => in_attesa_risposta($appId)]);
        break;

    // -----------------------------------------------------------
    // ADMIN: approva / rifiuta / modifica un appuntamento
    // -----------------------------------------------------------
    case 'aggiorna_stato':
        if (current_user_role() !== 'admin') json_out(['ok' => false, 'error' => 'Solo admin.'], 403);
        $d = input();
        $id = (int)($d['id'] ?? 0);
        $nuovoStato = $d['stato'] ?? '';
        if (!in_array($nuovoStato, ['approvato', 'rifiutato'], true)) {
            json_out(['ok' => false, 'error' => 'Stato non valido.'], 400);
        }

        $hemiId = ($d['hemi_id'] ?? '') !== '' ? (int)$d['hemi_id'] : null;
        if ($nuovoStato === 'approvato' && !$hemiId) {
            json_out(['ok' => false, 'error' => 'Seleziona un HEMI per approvare l\'appuntamento.'], 400);
        }
        // Approvando con un HEMI: verifica disponibilità su data/orario registrati (escluso questo appuntamento)
        if ($nuovoStato === 'approvato' && $hemiId) {
            $stmtCur = db()->prepare('SELECT data_appuntamento, ora_inizio, ora_fine FROM appuntamenti WHERE id = ?');
            $stmtCur->execute([$id]);
            $cur = $stmtCur->fetch();
            if (!$cur) json_out(['ok' => false, 'error' => 'Appuntamento non trovato.'], 404);
            if (!hemi_disponibile($hemiId, $cur['data_appuntamento'], $cur['ora_inizio'], $cur['ora_fine'], $id)) {
                json_out(['ok' => false, 'error' => MSG_HEMI_NON_DISPONIBILE], 409);
            }
        }
        $compenso = ($d['compenso'] ?? '') !== '' ? $d['compenso'] : null;
        $rimborso = ($d['rimborso_spese'] ?? '') !== '' ? $d['rimborso_spese'] : null;

        $stmt = db()->prepare(
            'UPDATE appuntamenti SET stato = ?, approvato_da = ?, approvato_at = NOW(), motivo_rifiuto = ?, hemi_id = ?, compenso = ?, rimborso_spese = ? WHERE id = ?'
        );
        $stmt->execute([$nuovoStato, current_user_id(), $d['motivo_rifiuto'] ?? null, $hemiId, $compenso, $rimborso, $id]);

        $stmtSel = db()->prepare(
            'SELECT ap.*, hm.telefono AS hm2i_telefono, he.telefono AS hemi_telefono
             FROM appuntamenti ap
             JOIN accounts hm ON hm.id = ap.hm2i_id
             LEFT JOIN accounts he ON he.id = ap.hemi_id
             WHERE ap.id = ?'
        );
        $stmtSel->execute([$id]);
        $app = $stmtSel->fetch();
        if ($app) {
            if (!empty($app['hm2i_telefono'])) {
                wa_notifica_esito($app, $app['hm2i_telefono']);
            }
            if ($nuovoStato === 'approvato' && !empty($app['hemi_telefono'])) {
                wa_notifica_esito($app, $app['hemi_telefono']);
            }
        }
        json_out(['ok' => true]);
        break;

    case 'modifica_appuntamento':
        if (current_user_role() !== 'admin') json_out(['ok' => false, 'error' => 'Solo admin.'], 403);
        $d = input();
        $id = (int)($d['id'] ?? 0);
        // Con un HEMI assegnato, verifica disponibilità sui nuovi data/orario (escluso questo appuntamento)
        $hemiMod = (int)($d['hemi_id'] ?? 0);
        if ($d['ora_fine'] <= $d['ora_inizio']) {
            json_out(['ok' => false, 'error' => "L'ora di fine deve essere successiva all'ora di inizio."], 400);
        }
        if ($hemiMod && !hemi_disponibile($hemiMod, $d['data_appuntamento'], $d['ora_inizio'], $d['ora_fine'], $id)) {
            json_out(['ok' => false, 'error' => MSG_HEMI_NON_DISPONIBILE], 409);
        }
        $stmt = db()->prepare(
            'UPDATE appuntamenti SET data_appuntamento=?, ora_inizio=?, ora_fine=?, compenso=?, rimborso_spese=?, hemi_id=? WHERE id=?'
        );
        $stmt->execute([
            $d['data_appuntamento'], $d['ora_inizio'], $d['ora_fine'],
            ($d['compenso'] !== '' ? $d['compenso'] : null),
            ($d['rimborso_spese'] !== '' ? $d['rimborso_spese'] : null),
            ($d['hemi_id'] ?: null),
            $id,
        ]);
        json_out(['ok' => true]);
        break;

    case 'elimina_appuntamento':
        if (current_user_role() !== 'admin') json_out(['ok' => false, 'error' => 'Solo admin.'], 403);
        $id = (int)(input()['id'] ?? 0);
        db()->prepare('DELETE FROM appuntamenti WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);
        break;

    default:
        json_out(['ok' => false, 'error' => 'Azione non riconosciuta.'], 400);
}

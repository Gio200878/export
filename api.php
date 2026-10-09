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
            $r['colore'] = $r['_offuscato'] ? '#999999' : colore_stato($r['stato']);
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

        $stato = stato_iniziale_per_ruolo();

        $stmt = db()->prepare(
            'INSERT INTO appuntamenti
                (area_id, hm2i_id, salone, indirizzo, telefono, ztl, note, data_appuntamento, ora_inizio, ora_fine, stato, creato_da, approvato_da, approvato_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $approvatoDa = $stato === 'approvato' ? current_user_id() : null;
        $approvatoAt = $stato === 'approvato' ? date('Y-m-d H:i:s') : null;
        $stmt->execute([
            $d['area_id'], $hm2iId, $d['salone'], $d['indirizzo'],
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

        if (!can_view_dettagli($app)) {
            $nomeVisibile = trim(($app['hemi_nome'] ?? '') . ' ' . ($app['hemi_cognome'] ?? '')) ?: trim($app['hm2i_nome'] . ' ' . $app['hm2i_cognome']);
            json_out(['ok' => true, 'appuntamento' => [
                'id' => $app['id'], 'occupato' => true, 'nome_visibile_occupato' => $nomeVisibile,
                'data_appuntamento' => $app['data_appuntamento'], 'ora_inizio' => $app['ora_inizio'], 'ora_fine' => $app['ora_fine'],
            ]]);
        }
        $app['occupato'] = false;
        json_out(['ok' => true, 'appuntamento' => $app]);
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

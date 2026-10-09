<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Elenco zone attive.
 */
function get_zone(bool $onlyAttive = true): array {
    $sql = 'SELECT * FROM zone' . ($onlyAttive ? ' WHERE attiva = 1' : '') . ' ORDER BY nome';
    return db()->query($sql)->fetchAll();
}

/**
 * Elenco aree intervento attive.
 */
function get_aree(bool $onlyAttive = true): array {
    $sql = 'SELECT * FROM aree_intervento' . ($onlyAttive ? ' WHERE attiva = 1' : '') . ' ORDER BY nome';
    return db()->query($sql)->fetchAll();
}

/**
 * Elenco HM2I selezionabili dall'utente corrente per l'inserimento di un appuntamento.
 * - admin: tutti gli HM2I attivi
 * - sector_manager: solo i suoi HM2I
 * - hm2i: solo se stesso
 * - hemi: nessuno (non inserisce appuntamenti a nome di HM2I)
 */
function get_hm2i_selezionabili(): array {
    $ids = visible_hm2i_ids();
    if (empty($ids)) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id, nome, cognome FROM accounts WHERE id IN ($in) AND attivo = 1 ORDER BY cognome, nome");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/**
 * Elenco educator HEMI selezionabili in base a zona + area (usato per assegnazione, facoltativo in fase di creazione).
 */
function get_hemi_per_zona_area(?int $zonaId, ?int $areaId): array {
    $sql = "SELECT DISTINCT a.id, a.nome, a.cognome FROM accounts a
            JOIN account_zone az ON az.account_id = a.id
            JOIN account_area aa ON aa.account_id = a.id
            WHERE a.ruolo = 'hemi' AND a.attivo = 1";
    $params = [];
    if ($zonaId) { $sql .= ' AND az.zona_id = ?'; $params[] = $zonaId; }
    if ($areaId) { $sql .= ' AND aa.area_id = ?'; $params[] = $areaId; }
    $sql .= ' ORDER BY a.cognome, a.nome';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Determina se l'utente corrente può vedere i DETTAGLI di un dato appuntamento
 * (altrimenti vede solo "occupato" + nome).
 */
function can_view_dettagli(array $app): bool {
    $role = current_user_role();
    $uid = current_user_id();
    if ($role === 'admin' || $role === 'sector_manager') return true;
    if ($role === 'hm2i') return (int)$app['hm2i_id'] === $uid;
    if ($role === 'hemi') return (int)($app['hemi_id'] ?? 0) === $uid;
    return false;
}

/**
 * Determina se l'utente corrente può inserire un appuntamento per un dato hm2i_id.
 */
function can_create_for_hm2i(int $hm2iId): bool {
    $role = current_user_role();
    if ($role === 'admin') return true;
    if ($role === 'sector_manager') return in_array($hm2iId, visible_hm2i_ids(), true);
    if ($role === 'hm2i') return $hm2iId === current_user_id();
    return false; // hemi non crea appuntamenti
}

/**
 * Stato iniziale di un nuovo appuntamento in base al ruolo di chi lo crea.
 */
function stato_iniziale_per_ruolo(): string {
    return current_user_role() === 'admin' ? 'approvato' : 'da_approvare';
}

/**
 * Colore associato allo stato (per il calendario).
 */
function colore_stato(string $stato): string {
    return match ($stato) {
        'approvato' => '#4a8c5f',      // verde
        'da_approvare' => '#d6a726',   // giallo
        'rifiutato' => '#b04a4a',      // rosso
        default => '#999999',
    };
}

/**
 * Recupera gli appuntamenti visibili dall'utente corrente in un intervallo di date,
 * applicando eventuali filtri (hm2i_id, area_id, hemi_id, zona_id, stato).
 */
function get_appuntamenti(string $dataInizio, string $dataFine, array $filtri = []): array {
    $role = current_user_role();
    $uid = current_user_id();

    $sql = "SELECT ap.*, a.nome AS area_nome, a.colore AS area_colore,
                   hm.nome AS hm2i_nome, hm.cognome AS hm2i_cognome,
                   he.nome AS hemi_nome, he.cognome AS hemi_cognome
            FROM appuntamenti ap
            JOIN aree_intervento a ON a.id = ap.area_id
            JOIN accounts hm ON hm.id = ap.hm2i_id
            LEFT JOIN accounts he ON he.id = ap.hemi_id
            WHERE ap.data_appuntamento BETWEEN ? AND ?";
    $params = [$dataInizio, $dataFine];

    // Visibilità per ruolo
    if ($role === 'sector_manager') {
        $ids = visible_hm2i_ids();
        if (empty($ids)) { $ids = [0]; }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND ap.hm2i_id IN ($in)";
        $params = array_merge($params, $ids);
    } elseif ($role === 'hm2i') {
        // Vede i propri con dettagli + tutti gli altri (mostrati come "occupato" lato frontend)
    } elseif ($role === 'hemi') {
        // Vede i propri con dettagli + tutti gli altri come "occupato"
    }

    // Filtri collegati (menu a tendina)
    if (!empty($filtri['hm2i_id'])) { $sql .= ' AND ap.hm2i_id = ?'; $params[] = $filtri['hm2i_id']; }
    if (!empty($filtri['area_id'])) { $sql .= ' AND ap.area_id = ?'; $params[] = $filtri['area_id']; }
    if (!empty($filtri['hemi_id'])) { $sql .= ' AND ap.hemi_id = ?'; $params[] = $filtri['hemi_id']; }
    if (!empty($filtri['zona_id'])) {
        $sql .= ' AND ap.hm2i_id IN (SELECT account_id FROM account_zone WHERE zona_id = ?)';
        $params[] = $filtri['zona_id'];
    }
    if (!empty($filtri['stato'])) { $sql .= ' AND ap.stato = ?'; $params[] = $filtri['stato']; }

    $sql .= ' ORDER BY ap.data_appuntamento, ap.ora_inizio';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Offusca i dettagli per chi non ha diritto a vederli
    foreach ($rows as &$r) {
        if (!can_view_dettagli($r)) {
            $r['_offuscato'] = true;
            $nomeVisibile = trim(($r['hemi_nome'] ?? '') . ' ' . ($r['hemi_cognome'] ?? ''));
            if ($nomeVisibile === '') {
                $nomeVisibile = trim($r['hm2i_nome'] . ' ' . $r['hm2i_cognome']);
            }
            $r['salone'] = 'Occupato';
            $r['indirizzo'] = '';
            $r['telefono'] = '';
            $r['note'] = '';
            $r['compenso'] = null;
            $r['rimborso_spese'] = null;
            $r['nome_visibile_occupato'] = $nomeVisibile;
        } else {
            $r['_offuscato'] = false;
        }
    }

    return $rows;
}

// ============================================================
// DISPONIBILITA' HEMI
// ============================================================

const MSG_HEMI_NON_DISPONIBILE = 'HEMI non disponibile , selezionare altro HEMI o altro giorno';

/**
 * Giorni della settimana lavorabili da un HEMI (0=dom ... 6=sab).
 * Nessuna riga configurata = nessuna restrizione (tutti i giorni).
 */
function get_giorni_hemi(int $hemiId): array {
    $stmt = db()->prepare('SELECT giorno FROM hemi_giorni WHERE account_id = ?');
    $stmt->execute([$hemiId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Verifica se un HEMI è disponibile in una data/fascia oraria.
 * Controlla: giorno della settimana, sospensioni (giorni interi o ore), appuntamenti già presenti.
 *
 * @param int|null $escludiAppId appuntamento da ignorare (in caso di modifica)
 */
function hemi_disponibile(int $hemiId, string $data, string $oraInizio, string $oraFine, ?int $escludiAppId = null): bool {
    $ts = strtotime($data);
    if ($ts === false) return false;

    // 1. Giorno della settimana
    $giorni = get_giorni_hemi($hemiId);
    if (!empty($giorni) && !in_array((int)date('w', $ts), $giorni, true)) return false;

    // 2. Sospensioni: giorno intero (ore NULL) oppure sovrapposizione oraria
    $stmt = db()->prepare(
        'SELECT 1 FROM hemi_sospensioni
         WHERE hemi_id = ? AND ? BETWEEN data_inizio AND data_fine
           AND (ora_inizio IS NULL OR ora_fine IS NULL OR (ora_inizio < ? AND ora_fine > ?))
         LIMIT 1'
    );
    $stmt->execute([$hemiId, $data, $oraFine, $oraInizio]);
    if ($stmt->fetchColumn()) return false;

    // 3. Appuntamenti già assegnati nella stessa fascia (esclusi i rifiutati)
    $sql = "SELECT 1 FROM appuntamenti
            WHERE hemi_id = ? AND data_appuntamento = ? AND stato <> 'rifiutato'
              AND ora_inizio < ? AND ora_fine > ?";
    $params = [$hemiId, $data, $oraFine, $oraInizio];
    if ($escludiAppId) { $sql .= ' AND id <> ?'; $params[] = $escludiAppId; }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return !$stmt->fetchColumn();
}

/**
 * HEMI selezionabili per area d'intervento e zone dell'HM2I richiedente.
 * Se l'HM2I non ha zone configurate non si filtra per zona.
 */
function get_hemi_per_area_e_hm2i(int $areaId, int $hm2iId): array {
    $stmt = db()->prepare('SELECT zona_id FROM account_zone WHERE account_id = ?');
    $stmt->execute([$hm2iId]);
    $zone = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $sql = "SELECT DISTINCT a.id, a.nome, a.cognome FROM accounts a
            JOIN account_area aa ON aa.account_id = a.id AND aa.area_id = ?
            WHERE a.ruolo = 'hemi' AND a.attivo = 1";
    $params = [$areaId];
    if (!empty($zone)) {
        $in = implode(',', array_fill(0, count($zone), '?'));
        $sql .= " AND a.id IN (SELECT account_id FROM account_zone WHERE zona_id IN ($in))";
        $params = array_merge($params, $zone);
    }
    $stmt = db()->prepare($sql . ' ORDER BY a.cognome, a.nome');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Anagrafica saloni (codice + nome) per la form di inserimento appuntamento.
 */
function get_saloni(): array {
    return db()->query('SELECT id, codice, nome, prov FROM saloni ORDER BY nome')->fetchAll();
}

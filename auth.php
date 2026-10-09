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

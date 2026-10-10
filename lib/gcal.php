<?php
require_once __DIR__ . '/db.php';

/**
 * Sincronizzazione eventi Google Calendar (feed ICS pubblico) -> tabella eventi_google.
 * Costanti opzionali in config.php (hanno un valore di default):
 *   GCAL_ICS_URL      URL del feed ICS pubblico del calendario
 *   GCAL_DAL          data minima degli eventi importati (default 2026-10-01)
 *   GCAL_SYNC_MINUTI  ogni quanti minuti l'agenda controlla se ci sono eventi nuovi (default 10)
 *   GCAL_SYNC_TOKEN   se impostato, abilita gcal_sync.php?token=... per un cron esterno
 */
function gcal_url(): string {
    return defined('GCAL_ICS_URL') ? GCAL_ICS_URL
        : 'https://calendar.google.com/calendar/ical/u1bmdggb9dhpnf2khptp3nva3s%40group.calendar.google.com/public/basic.ics';
}
function gcal_dal(): string { return defined('GCAL_DAL') ? GCAL_DAL : '2026-10-01'; }
function gcal_minuti(): int { return defined('GCAL_SYNC_MINUTI') ? (int)GCAL_SYNC_MINUTI : 10; }

/** Scarica il feed ICS. Ritorna null in caso di errore. */
function gcal_scarica(): ?string {
    $ch = curl_init(gcal_url());
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200 && strpos($body, 'BEGIN:VCALENDAR') !== false) ? $body : null;
}

/** Converte un valore ICS (DATE o DATE-TIME) in [Y-m-d, H:i|null], in ora di Roma. */
function gcal_valore_data(string $val, array $params): array {
    $tz = new DateTimeZone('Europe/Rome');
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $val, $m)) return ["$m[1]-$m[2]-$m[3]", null];
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $val, $m)) return [null, null];
    $zona = $m[7] === 'Z' ? new DateTimeZone('UTC') : (isset($params['TZID']) ? @new DateTimeZone($params['TZID']) : $tz);
    $dt = new DateTime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", $zona ?: $tz);
    $dt->setTimezone($tz);
    return [$dt->format('Y-m-d'), $dt->format('H:i')];
}

function gcal_testo(string $t): string {
    return str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $t);
}

/**
 * Interpreta il feed ICS e restituisce gli eventi con data_fine >= $dal, espandendo le ricorrenze
 * semplici (DAILY/WEEKLY/MONTHLY/YEARLY con INTERVAL, COUNT, UNTIL, BYDAY) fino a $fino.
 * Ogni evento: uid, titolo, descrizione, luogo, data_inizio, data_fine (inclusiva), ora_inizio, ora_fine.
 */
function gcal_parse_ics(string $ics, string $dal, string $fino): array {
    $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // unfolding
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocchi);
    $base = []; $override = [];
    foreach ($blocchi[1] as $blk) {
        $p = [];
        foreach (preg_split('/\r?\n/', $blk) as $riga) {
            if (!preg_match('/^([A-Z-]+)((?:;[^:]+)*):(.*)$/', $riga, $m)) continue;
            $par = [];
            foreach (array_filter(explode(';', ltrim($m[2], ';'))) as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                $par[strtoupper($k)] = trim($v, '"');
            }
            $p[$m[1]][] = [$m[3], $par];
        }
        if (empty($p['UID']) || empty($p['DTSTART'])) continue;
        if (($p['STATUS'][0][0] ?? '') === 'CANCELLED') $p['_cancellato'] = true;
        [$d1, $h1] = gcal_valore_data($p['DTSTART'][0][0], $p['DTSTART'][0][1]);
        if (!$d1) continue;
        if (!empty($p['DTEND'])) {
            [$d2, $h2] = gcal_valore_data($p['DTEND'][0][0], $p['DTEND'][0][1]);
        } else { $d2 = $d1; $h2 = $h1; }
        if ($h1 === null) { // giornata intera: DTEND è esclusivo
            $d2 = date('Y-m-d', strtotime(($d2 ?: $d1) . ' -1 day'));
            if ($d2 < $d1) $d2 = $d1;
            $h2 = null;
        }
        $ev = [
            'uid' => $p['UID'][0][0], 'titolo' => gcal_testo($p['SUMMARY'][0][0] ?? '(senza titolo)'),
            'descrizione' => gcal_testo($p['DESCRIPTION'][0][0] ?? ''), 'luogo' => gcal_testo($p['LOCATION'][0][0] ?? ''),
            'data_inizio' => $d1, 'data_fine' => $d2 ?: $d1, 'ora_inizio' => $h1, 'ora_fine' => $h2,
            'cancellato' => !empty($p['_cancellato']),
        ];
        if (!empty($p['RECURRENCE-ID'])) {
            [$rid] = gcal_valore_data($p['RECURRENCE-ID'][0][0], $p['RECURRENCE-ID'][0][1]);
            $override[$ev['uid']][$rid] = $ev;
            continue;
        }
        $ev['rrule'] = $p['RRULE'][0][0] ?? null;
        $ev['exdate'] = [];
        foreach ($p['EXDATE'] ?? [] as [$v, $par]) {
            foreach (explode(',', $v) as $x) $ev['exdate'][] = gcal_valore_data($x, $par)[0];
        }
        $base[] = $ev;
    }

    $out = [];
    foreach ($base as $ev) {
        if (!$ev['rrule']) {
            if (!$ev['cancellato'] && $ev['data_fine'] >= $dal && $ev['data_inizio'] <= $fino) $out[] = $ev;
            continue;
        }
        $regola = [];
        foreach (explode(';', $ev['rrule']) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $regola[$k] = $v; }
        $freq = $regola['FREQ'] ?? ''; $step = max(1, (int)($regola['INTERVAL'] ?? 1));
        $max = isset($regola['COUNT']) ? (int)$regola['COUNT'] : PHP_INT_MAX;
        $until = isset($regola['UNTIL']) ? gcal_valore_data($regola['UNTIL'], [])[0] : null;
        $durata = (int)((strtotime($ev['data_fine']) - strtotime($ev['data_inizio'])) / 86400);
        $mappa = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
        $giorni = [];
        foreach (array_filter(explode(',', $regola['BYDAY'] ?? '')) as $g) $giorni[] = $mappa[substr($g, -2)] ?? null;
        $giorni = array_values(array_filter($giorni, fn($g) => $g !== null));

        $date = []; $n = 0;
        $cur = new DateTime($ev['data_inizio']);
        $limite = new DateTime($fino);
        $guard = 0;
        while ($cur <= $limite && $n < $max && $guard++ < 5000) {
            $d = $cur->format('Y-m-d');
            if ($until !== null && $d > $until) break;
            $ammessa = true;
            if ($freq === 'WEEKLY' && $giorni) {
                $sett = (int)floor((strtotime($d) - strtotime($ev['data_inizio'])) / 604800 + 0.0001);
                $ammessa = in_array((int)$cur->format('w'), $giorni, true) && $sett % $step === 0;
                $cur->modify('+1 day');
            } else {
                $spec = ['DAILY' => "+$step day", 'WEEKLY' => '+' . (7 * $step) . ' day', 'MONTHLY' => "+$step month", 'YEARLY' => "+$step year"][$freq] ?? null;
                if ($spec === null) { $cur = new DateTime('9999-01-01'); }
                else $cur->modify($spec);
            }
            if ($ammessa) { $date[] = $d; $n++; }
        }
        foreach ($date as $d) {
            if (in_array($d, $ev['exdate'], true)) continue;
            $istanza = $ev;
            if (isset($override[$ev['uid']][$d])) $istanza = $override[$ev['uid']][$d] + $ev;
            else {
                $istanza['data_inizio'] = $d;
                $istanza['data_fine'] = date('Y-m-d', strtotime("$d +$durata day"));
            }
            $istanza['uid'] = $ev['uid'] . '_' . $d;
            if (!$istanza['cancellato'] && $istanza['data_fine'] >= $dal && $istanza['data_inizio'] <= $fino) $out[] = $istanza;
        }
    }
    return $out;
}

/**
 * Allinea la tabella eventi_google con il calendario: inserisce/aggiorna gli eventi dal GCAL_DAL
 * in poi e rimuove quelli cancellati su Google. Ritorna [ok, messaggio].
 */
function gcal_sync(): array {
    $pdo = db();
    $ics = gcal_scarica();
    $pdo->prepare("INSERT INTO app_meta (chiave, valore) VALUES ('gcal_ultimo_tentativo', ?) ON DUPLICATE KEY UPDATE valore = VALUES(valore)")
        ->execute([date('Y-m-d H:i:s')]);
    if ($ics === null) return [false, 'Impossibile scaricare il calendario Google (è pubblico?).'];

    $eventi = gcal_parse_ics($ics, gcal_dal(), date('Y-m-d', strtotime('+2 years')));
    $uids = [];
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare(
            'INSERT INTO eventi_google (uid, titolo, descrizione, luogo, data_inizio, data_fine, ora_inizio, ora_fine)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE titolo=VALUES(titolo), descrizione=VALUES(descrizione), luogo=VALUES(luogo),
                data_inizio=VALUES(data_inizio), data_fine=VALUES(data_fine), ora_inizio=VALUES(ora_inizio), ora_fine=VALUES(ora_fine)'
        );
        foreach ($eventi as $e) {
            $uids[] = $e['uid'];
            $up->execute([$e['uid'], mb_substr($e['titolo'], 0, 255), $e['descrizione'] ?: null, mb_substr($e['luogo'], 0, 255) ?: null,
                          $e['data_inizio'], $e['data_fine'], $e['ora_inizio'], $e['ora_fine']]);
        }
        // Rimuove gli eventi non più presenti nel feed (cancellati o spostati fuori finestra)
        $esistenti = $pdo->query('SELECT uid FROM eventi_google')->fetchAll(PDO::FETCH_COLUMN);
        $del = $pdo->prepare('DELETE FROM eventi_google WHERE uid = ?');
        foreach (array_diff($esistenti, $uids) as $u) $del->execute([$u]);
        $pdo->prepare("INSERT INTO app_meta (chiave, valore) VALUES ('gcal_ultima_sync', ?) ON DUPLICATE KEY UPDATE valore = VALUES(valore)")
            ->execute([date('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Errore durante la sincronizzazione: ' . $e->getMessage()];
    }
    return [true, count($eventi) . ' eventi sincronizzati.'];
}

/** Sincronizza solo se l'ultimo tentativo è più vecchio di GCAL_SYNC_MINUTI. Non solleva mai errori. */
function gcal_sync_se_scaduta(): void {
    try {
        $st = db()->prepare("SELECT valore FROM app_meta WHERE chiave = 'gcal_ultimo_tentativo'");
        $st->execute();
        $ultimo = $st->fetchColumn();
        if ($ultimo && strtotime($ultimo) > time() - gcal_minuti() * 60) return;
        gcal_sync();
    } catch (Throwable $e) {
        // tabelle non ancora create (install.php da rieseguire): l'agenda funziona senza eventi Google
    }
}

/** Eventi Google che intersecano l'intervallo richiesto. */
function gcal_eventi(string $inizio, string $fine): array {
    try {
        $st = db()->prepare('SELECT id, uid, titolo, descrizione, luogo, data_inizio, data_fine, ora_inizio, ora_fine
                             FROM eventi_google WHERE data_inizio <= ? AND data_fine >= ? ORDER BY data_inizio, ora_inizio');
        $st->execute([$fine, $inizio]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

<?php
/**
 * Riceve la lista dei cerchi da HEMI.html e la scrive in cerchi.json,
 * nella stessa cartella di questo script. Protetto da una password
 * semplice (non e' un vero sistema di autenticazione: basta a evitare
 * che chiunque trovi l'URL possa modificare il file).
 *
 * IMPORTANTE: cambia PASSWORD_SALVATAGGIO con una password tua prima
 * di caricare questo file sul sito.
 */

const PASSWORD_SALVATAGGIO = 'cambia-questa-password';
const FILE_DESTINAZIONE = __DIR__ . '/cerchi.json';

header('Content-Type: application/json; charset=utf-8');

function risposta($ok, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    risposta(false, ['error' => 'Metodo non consentito, serve POST']);
}

$raw = file_get_contents('php://input');
$dati = json_decode($raw, true);

if (!is_array($dati) || !isset($dati['token']) || !isset($dati['cerchi'])) {
    http_response_code(400);
    risposta(false, ['error' => 'Corpo della richiesta non valido']);
}

if (!hash_equals(PASSWORD_SALVATAGGIO, (string) $dati['token'])) {
    http_response_code(403);
    risposta(false, ['error' => 'Password errata']);
}

$cerchi = $dati['cerchi'];
if (!is_array($cerchi)) {
    http_response_code(400);
    risposta(false, ['error' => 'Formato cerchi non valido, deve essere una lista']);
}

foreach ($cerchi as $c) {
    if (!is_array($c)
        || empty($c['id']) || !is_string($c['id'])
        || empty($c['nome']) || !is_string($c['nome'])
        || empty($c['citta']) || !is_string($c['citta'])
        || !isset($c['lat']) || !is_numeric($c['lat'])
        || !isset($c['lng']) || !is_numeric($c['lng'])
    ) {
        http_response_code(400);
        risposta(false, ['error' => 'Un cerchio manca di un campo obbligatorio (id, nome, citta, lat, lng)']);
    }
}

$json = json_encode($cerchi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    http_response_code(500);
    risposta(false, ['error' => 'Errore nella codifica JSON']);
}

$scritto = @file_put_contents(FILE_DESTINAZIONE, $json);
if ($scritto === false) {
    http_response_code(500);
    risposta(false, ['error' => 'Scrittura di cerchi.json fallita (permessi cartella?)']);
}

risposta(true, ['scritti' => count($cerchi)]);

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

/**
 * Invia un messaggio WhatsApp tramite template, usando Meta Cloud API.
 * Registra sempre l'esito in wa_log, anche se WA_ENABLED è false (utile per debug).
 *
 * @param string $to Numero di telefono destinatario, formato internazionale (es. 39333...)
 * @param array  $params Parametri da inserire nel template (in ordine)
 * @param int|null $appuntamentoId Per collegare il log all'appuntamento
 */
/**
 * Normalizza un numero italiano al formato E.164 senza '+' (es. 393385453631).
 * Aggiunge il prefisso 39 se manca, rimuove '00' iniziale se presente.
 */
function wa_normalizza_numero(string $to): string {
    $digits = preg_replace('/[^0-9]/', '', $to);
    if (substr($digits, 0, 2) === '00') {
        $digits = substr($digits, 2);
    }
    if (substr($digits, 0, 2) !== '39') {
        $digits = '39' . $digits;
    }
    return $digits;
}

function wa_send_template(string $to, array $params, ?int $appuntamentoId = null): bool {
    $messaggio = implode(' | ', $params);
    $esito = 'non_inviato';

    if (WA_ENABLED && WA_PHONE_NUMBER_ID && WA_ACCESS_TOKEN && $to) {
        $url = "https://graph.facebook.com/v19.0/" . WA_PHONE_NUMBER_ID . "/messages";
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => wa_normalizza_numero($to),
            'type' => 'template',
            'template' => [
                'name' => WA_TEMPLATE_NAME,
                'language' => ['code' => WA_TEMPLATE_LANG],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $messaggio]],
                ]],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . WA_ACCESS_TOKEN,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $esito = ($httpCode === 200) ? 'inviato' : 'errore_http_' . $httpCode . ': ' . substr((string)$response, 0, 600);
    }

    try {
        $stmt = db()->prepare('INSERT INTO wa_log (appuntamento_id, destinatario, messaggio, esito) VALUES (?, ?, ?, ?)');
        $stmt->execute([$appuntamentoId, $to, $messaggio, $esito]);
    } catch (Throwable $e) {
        // non bloccare il flusso applicativo se il log fallisce
    }

    return $esito === 'inviato';
}

/**
 * Notifica la creazione di un appuntamento in attesa di approvazione (agli admin).
 */
function wa_notifica_nuovo_appuntamento(array $appuntamento): void {
    $stmt = db()->query("SELECT telefono FROM accounts WHERE ruolo = 'admin' AND attivo = 1 AND telefono IS NOT NULL AND telefono != ''");
    foreach ($stmt->fetchAll() as $admin) {
        wa_send_template(
            $admin['telefono'],
            ['Nuovo appuntamento da approvare', $appuntamento['salone'], $appuntamento['data_appuntamento']],
            $appuntamento['id'] ?? null
        );
    }
}

/**
 * Notifica l'esito (approvato/rifiutato) all'HM2I che ha richiesto l'appuntamento.
 */
function wa_notifica_esito(array $appuntamento, string $telefonoHm2i): void {
    $stato = $appuntamento['stato'] === 'approvato' ? 'APPROVATO' : 'RIFIUTATO';
    wa_send_template(
        $telefonoHm2i,
        ['Appuntamento ' . $stato, $appuntamento['salone'], $appuntamento['data_appuntamento']],
        $appuntamento['id'] ?? null
    );
}

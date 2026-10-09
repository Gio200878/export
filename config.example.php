<?php
/**
 * CONFIGURAZIONE - HEMI Gestionale
 * Modifica questi valori con i dati del tuo hosting Aruba prima di caricare via FTP.
 */

// --- Database ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'nome_database');
define('DB_USER', 'utente_database');
define('DB_PASS', 'password_database');
define('DB_CHARSET', 'utf8mb4');


// --- Sessioni / sicurezza ---
define('SESSION_NAME', 'hemi_session');
define('APP_TIMEZONE', 'Europe/Rome');

// --- WhatsApp (Meta Cloud API) ---
// Vedi https://developers.facebook.com/docs/whatsapp/cloud-api
define('WA_ENABLED', false); // metti true quando avrai le credenziali
define('WA_PHONE_NUMBER_ID', '');
define('WA_ACCESS_TOKEN', '');
define('WA_TEMPLATE_NAME', 'hemi_notifica');
define('WA_TEMPLATE_LANG', 'en');

// --- Percorsi ---
define('BASE_PATH', __DIR__);
define('BASE_URL', 'https://tuodominio.it/hemiapp2'); // es. https://tuodominio.it/hemi  (lascia vuoto se in root)

date_default_timezone_set(APP_TIMEZONE);

session_name(SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '0'); // metti 1 solo in fase di debug locale
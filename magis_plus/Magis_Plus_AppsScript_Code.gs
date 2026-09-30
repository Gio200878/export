/**
 * Magis Plus — Raccolta dati
 * Riceve i moduli inviati dalla pagina HTML del sito, salva il file JSON
 * in una cartella di Google Drive e, se richiesto, invia un'email di
 * notifica con il file allegato.
 *
 * NOVITA': quando la pagina segnala che tutti e tre i moduli sono stati
 * compilati (campo "notifica" nella richiesta), lo script invia a
 * COMPLETION_EMAIL l'email "dati per MAGIS PLUS del cliente ... pronti per
 * essere elaborati" con i dati del salone.
 *
 * INSTALLAZIONE
 * 1. Vai su https://script.google.com (con l'account Google del salone/agenzia)
 *    e crea un nuovo progetto.
 * 2. Cancella il contenuto di Code.gs e incolla questo intero file.
 * 3. Cambia, qui sotto, DRIVE_FOLDER_ID con l'ID della cartella Drive dove
 *    vuoi che arrivino i moduli (apri la cartella su Drive, l'ID è la parte
 *    finale dell'indirizzo: drive.google.com/drive/folders/QUESTO_È_L_ID).
 *    Lascialo vuoto ("") per salvare nella cartella principale del Drive.
 * 4. In alto a destra: Esegui ▸ scegli la funzione "doPost" ▸ Esegui, per
 *    autorizzare lo script ad accedere a Drive e Gmail (una tantum).
 * 5. Distribuisci ▸ Nuova distribuzione ▸ tipo "App web".
 *      - Esegui come: Io (il tuo account)
 *      - Chi può accedere: Chiunque
 *    Distribuisci e copia l'URL dell'app web che ti viene mostrato.
 * 6. Incolla quell'URL nella pagina HTML del sito, nella costante
 *    ENDPOINT_URL in cima allo script.
 *
 * Ogni volta che modifichi questo script, devi creare una NUOVA versione da
 * Distribuisci ▸ Gestisci distribuzioni ▸ modifica (icona matita) ▸
 * Versione: Nuova versione ▸ Distribuisci, altrimenti le modifiche non
 * hanno effetto sull'URL già pubblicato.
 */

const DRIVE_FOLDER_ID = ""; // <-- incolla qui l'ID della cartella Drive, o lascia vuoto
const NOTIFY_EMAIL = "a.monacelli@monacelliitaly.it";
const SEND_EMAIL_NOTIFICATION = false; // false per salvare solo su Drive, senza inviare email

// Email "moduli completati": inviata quando il cliente ha salvato tutti e tre i moduli.
// Il destinatario e' fisso qui: l'indirizzo, l'oggetto e il testo NON vengono presi dalla
// richiesta, cosi' nessuno puo' usare questo endpoint pubblico per spedire email a caso.
const COMPLETION_EMAIL = "info@monacelliitaly.it";

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return jsonResponse({ ok: false, error: "Nessun dato ricevuto" });
    }
    const payload = JSON.parse(e.postData.contents);
    const filename = sanitizeFilename(payload.filename || "Magis Plus Raccolta Dati.json");
    const data = payload.data || payload; // tollera sia {filename,data} sia il solo oggetto dati
    const jsonText = JSON.stringify(data, null, 2);

    // 1) Salva il file su Drive
    const folder = DRIVE_FOLDER_ID
      ? DriveApp.getFolderById(DRIVE_FOLDER_ID)
      : DriveApp.getRootFolder();
    const file = folder.createFile(filename, jsonText, MimeType.PLAIN_TEXT);
    file.setName(filename); // createFile a volte aggiunge un'estensione, forziamo il nome

    // 2) Invia l'email di notifica con il file allegato
    if (SEND_EMAIL_NOTIFICATION) {
      const blob = Utilities.newBlob(jsonText, "application/json", filename);
      GmailApp.sendEmail(
        NOTIFY_EMAIL,
        "Magis Plus — Nuovo modulo: " + filename.replace(/\.json$/, ""),
        "È arrivato un nuovo modulo di raccolta dati.\n\n" +
          "File: " + filename + "\n" +
          "Cartella Drive: " + folder.getUrl() + "\n\n" +
          "Anteprima dati:\n" + jsonText.slice(0, 1500) +
          (jsonText.length > 1500 ? "\n… (vedi il file allegato per il resto)" : ""),
        { attachments: [blob] }
      );
    }

    // 3) Tutti e tre i moduli completati: avvisa Monacelli Italy.
    //    "mail" nella risposta: true = inviata, false = errore, assente = non richiesta.
    const risposta = { ok: true, fileUrl: file.getUrl() };
    if (payload.notifica) {
      risposta.mail = inviaEmailModuliCompleti(data);
    }
    return jsonResponse(risposta);
  } catch (err) {
    return jsonResponse({ ok: false, error: String(err) });
  }
}

/** Email "dati ... pronti per essere elaborati" con i 4 campi di "Dati salone". */
function inviaEmailModuliCompleti(data) {
  try {
    const codice = pulisciRiga(data.CODICE);
    GmailApp.sendEmail(
      COMPLETION_EMAIL,
      "dati per MAGIS PLUS del cliente " + codice + " pronti per essere elaborati",
      "Codice salone: " + codice + "\n" +
        "Nome salone: " + pulisciRiga(data.NOME_SALONE) + "\n" +
        "Nome titolare: " + pulisciRiga(data.NOME_TITOLARE) + "\n" +
        "HM2I: " + pulisciRiga(data.HM2I)
    );
    return true;
  } catch (err) {
    return false;
  }
}

/** Testo su una sola riga (niente a capo), lunghezza limitata. */
function pulisciRiga(v) {
  return String(v == null ? "" : v).replace(/[\r\n]+/g, " ").trim().slice(0, 200);
}

function sanitizeFilename(name) {
  return name.toString().replace(/[\/\\:*?"<>|]+/g, "_").slice(0, 180);
}

function jsonResponse(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(
    ContentService.MimeType.JSON
  );
}

/** Facoltativo: apre l'app web con un GET per verificare che sia online. */
function doGet() {
  return ContentService.createTextOutput(
    "Magis Plus — endpoint attivo. Invia i dati con una richiesta POST."
  );
}

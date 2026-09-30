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

// PAGINA RIEPILOGO SALONI (uso interno): password richiesta per leggere e modificare i dati.
// Impostala qui, oppure (meglio) in Impostazioni progetto ▸ Proprietà script ▸ ADMIN_KEY.
// Se e' vuota la pagina riepilogo e' DISABILITATA (i dati dei saloni non sono mai esposti).
const ADMIN_KEY = "";

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return jsonResponse({ ok: false, error: "Nessun dato ricevuto" });
    }
    const payload = JSON.parse(e.postData.contents);
    if (payload.action) return jsonResponse(gestisciAdmin(payload)); // pagina riepilogo
    const filename = sanitizeFilename(payload.filename || "Magis Plus Raccolta Dati.json");
    const data = payload.data || payload; // tollera sia {filename,data} sia il solo oggetto dati
    const jsonText = JSON.stringify(data, null, 2);

    // 1) Salva su Drive: UN SOLO FILE per salone, che raccoglie tutti e tre i moduli
    //    (i salvataggi successivi aggiornano lo stesso file invece di crearne di nuovi).
    const folder = cartella_();
    const file = PARTI_MODULO[data.modulo] && data.CODICE
      ? salvaFileUnico_(folder, filename, data)
      : creaFile_(folder, filename, jsonText); // dati senza "modulo": comportamento di prima

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

// Quali campi del JSON appartengono a ciascun modulo del sito.
const MODULO_UNICO = "raccolta_dati_completa";
const CAMPI_SALONE = ["CODICE", "NOME_SALONE", "NOME_TITOLARE", "HM2I"];
const PARTI_MODULO = {
  gestione_tariffe: ["gestione", "tariffe"],
  analisi_collaboratore: ["collaboratori"],
  immagine_sogno_salone: ["numero_team", "immagine", "postazioni", "materiale_salone", "materiale_vetrine",
                          "presenza_social", "brand", "proposte_sviluppo", "sogno"]
};

function creaFile_(folder, filename, testo) {
  const file = folder.createFile(filename, testo, MimeType.PLAIN_TEXT);
  file.setName(filename); // createFile a volte aggiunge un'estensione, forziamo il nome
  return file;
}

/** Nome come lo genera la pagina del sito ("safe"): solo lettere, numeri, _ e -. */
function safeCodice_(c) {
  return String(c == null ? "" : c).trim().replace(/[^a-z0-9À-ÿ_-]+/gi, "_");
}

/** Tutti i file JSON della cartella riferiti a quel codice (nome con "(codice)"), piu' vecchi per primi. */
function fileDelSalone_(folder, codice) {
  const trovati = [];
  const it = folder.searchFiles('title contains "(' + safeCodice_(codice) + ')"');
  while (it.hasNext()) {
    const file = it.next();
    if (file.getSize() > 2000000) continue;
    let d;
    try { d = JSON.parse(file.getBlob().getDataAsString()); } catch (err) { continue; }
    if (d && String(d.CODICE) === String(codice)) trovati.push({ file: file, data: d, t: file.getLastUpdated().getTime() });
  }
  return trovati.sort(function (a, b) { return a.t - b.t; });
}

function unisciParti_(dest, src) {
  CAMPI_SALONE.forEach(function (k) { if (src[k] != null && src[k] !== "") dest[k] = src[k]; });
  Object.keys(PARTI_MODULO).forEach(function (modulo) {
    if (src.modulo === modulo || (src.modulo === MODULO_UNICO)) {
      PARTI_MODULO[modulo].forEach(function (k) { if (k in src) dest[k] = src[k]; });
    }
  });
}

/**
 * Aggiorna (o crea) il file unico del salone. Se esiste gia' un file unico lo usa; altrimenti lo
 * costruisce riunendo gli eventuali file dei singoli moduli salvati in precedenza (senza cancellarli).
 */
function salvaFileUnico_(folder, filename, data) {
  const lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    const esistenti = fileDelSalone_(folder, data.CODICE);
    const unici = esistenti.filter(function (e) { return e.data.modulo === MODULO_UNICO; });
    const unico = {};
    let file = null;
    if (unici.length) {
      file = unici[unici.length - 1].file;
      unisciParti_(unico, unici[unici.length - 1].data);
    } else {
      esistenti.forEach(function (e) { unisciParti_(unico, e.data); }); // recupera i moduli gia' salvati a parte
    }
    unisciParti_(unico, data);                       // il modulo appena inviato prevale
    unico.modulo = MODULO_UNICO;
    const prec = unici.length ? (unici[unici.length - 1].data.moduli_compilati || {}) : {};
    esistenti.forEach(function (e) { if (PARTI_MODULO[e.data.modulo] && !prec[e.data.modulo]) prec[e.data.modulo] = e.file.getLastUpdated().toISOString(); });
    prec[data.modulo] = new Date().toISOString();
    unico.moduli_compilati = prec;
    const testo = JSON.stringify(unico, null, 2);
    if (file) {
      file.setContent(testo);
      file.setName(filename);
    } else {
      file = creaFile_(folder, filename, testo);
    }
    return file;
  } finally {
    lock.releaseLock();
  }
}

function cartella_() {
  return DRIVE_FOLDER_ID ? DriveApp.getFolderById(DRIVE_FOLDER_ID) : DriveApp.getRootFolder();
}

// ---------------------------------------------------------------------------
// PAGINA RIEPILOGO SALONI
// ---------------------------------------------------------------------------
function chiaveAdmin_() {
  return PropertiesService.getScriptProperties().getProperty("ADMIN_KEY") || ADMIN_KEY;
}

function gestisciAdmin(p) {
  const chiave = chiaveAdmin_();
  if (!chiave) return { ok: false, error: "Pagina riepilogo non abilitata: imposta ADMIN_KEY nello script." };
  if (String(p.key || "") !== chiave) {
    Utilities.sleep(1500); // rallenta i tentativi a caso
    return { ok: false, error: "Password non valida" };
  }
  if (p.action === "admin_list") return elencaModuli();
  if (p.action === "admin_save") return salvaModulo(p);
  return { ok: false, error: "Azione sconosciuta" };
}

/** Per ogni salone e modulo restituisce il file piu' recente (i salvataggi ripetuti creano piu' file). */
function elencaModuli() {
  const piuRecenti = {};
  const it = cartella_().getFiles();
  while (it.hasNext()) {
    const file = it.next();
    if (file.getSize() > 2000000) continue;
    let d;
    try { d = JSON.parse(file.getBlob().getDataAsString()); } catch (err) { continue; }
    if (!d || !d.modulo || !d.CODICE) continue;
    const t = file.getLastUpdated().getTime();
    const k = d.CODICE + "|" + d.modulo;
    if (!piuRecenti[k] || t > piuRecenti[k].t) {
      piuRecenti[k] = { t: t, fileId: file.getId(), name: file.getName(),
                        updated: file.getLastUpdated().toISOString(), data: d };
    }
  }
  return { ok: true, items: Object.keys(piuRecenti).map(function (k) { return piuRecenti[k]; }) };
}

/** Sovrascrive il contenuto di un file gia' esistente (Drive tiene lo storico delle versioni). */
function salvaModulo(p) {
  const d = p.data;
  if (!p.fileId || !d || !d.modulo || !d.CODICE) return { ok: false, error: "Dati non validi" };
  const file = DriveApp.getFileById(p.fileId);
  const idCartella = cartella_().getId();
  let dentro = false;
  const genitori = file.getParents();
  while (genitori.hasNext()) { if (genitori.next().getId() === idCartella) dentro = true; }
  if (!dentro) return { ok: false, error: "File non appartenente alla cartella dei moduli" };
  file.setContent(JSON.stringify(d, null, 2));
  if (p.filename) file.setName(sanitizeFilename(p.filename));
  return { ok: true, updated: file.getLastUpdated().toISOString() };
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

/**
 * Magis Plus — Raccolta dati
 * Riceve i moduli inviati dalla pagina HTML del sito, salva il file JSON
 * in una cartella di Google Drive e, se richiesto, invia un'email di
 * notifica con il file allegato.
 *
 * FUNZIONI
 * - UN SOLO FILE per salone: i tre moduli (Gestione e Tariffe, Analisi
 *   Collaboratore, Immagine e Sogno) si uniscono nello stesso file, ritrovato
 *   tramite il CODICE salone. I salvataggi successivi lo aggiornano.
 * - Email "dati per MAGIS PLUS del cliente ... pronti per essere elaborati"
 *   a COMPLETION_EMAIL, inviata una sola volta quando il salone ha salvato
 *   tutti e tre i moduli (non serve nessuna modifica alla pagina).
 * - Pagina riepilogo saloni (uso interno), protetta da ADMIN_KEY.
 * - Sondaggio clienti ("Che cosa pensi di noi"): un file per salone con tutte
 *   le risposte.
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

const DRIVE_FOLDER_ID = "1FIOMwIM-41GaSXIjwGjRWhOkiTGYfxZj"; // cartella "Magis Plus" (dentro "export"). Cambiala solo se sposti la cartella
const NOTIFY_EMAIL = "a.monacelli@monacelliitaly.it";
const SEND_EMAIL_NOTIFICATION = false; // true per ricevere un'email con il file allegato a ogni salvataggio
// Se Drive non funziona (cartella non raggiungibile, quota piena, errore del servizio) il file JSON viene
// inviato come allegato a NOTIFY_EMAIL, cosi' il dato non va perso. In condizioni normali non parte nessuna email.
const EMAIL_SE_DRIVE_FALLISCE = true;

// Email "moduli completati": inviata quando il cliente ha salvato tutti e tre i moduli.
// Il destinatario e' fisso qui: l'indirizzo, l'oggetto e il testo NON vengono presi dalla
// richiesta, cosi' nessuno puo' usare questo endpoint pubblico per spedire email a caso.
const COMPLETION_EMAIL = "info@monacelliitaly.it;l.dessi@monacelliitaly.it";

// PAGINA RIEPILOGO SALONI (uso interno): la password NON sta nel codice.
// Impostala in Impostazioni progetto (icona ingranaggio) ▸ Proprietà script ▸ Aggiungi proprietà:
//   Proprietà: ADMIN_KEY    Valore: la password scelta
// Se la proprieta' manca o e' vuota la pagina riepilogo e' DISABILITATA (i dati dei saloni non sono mai esposti).

// Sondaggio clienti: un file per salone "Magis Plus Sondaggio Clienti - <Nome> (<codice>).json"
const SONDAGGIO_PREFIX = "Magis Plus Sondaggio Clienti";
const CODICE_VALIDO_ = /^[A-Za-z0-9_-]{1,20}$/;

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      return jsonResponse({ ok: false, error: "Nessun dato ricevuto" });
    }
    const payload = JSON.parse(e.postData.contents);
    if (payload.action) return jsonResponse(gestisciAdmin(payload)); // pagina riepilogo
    const filename = sanitizeFilename(payload.filename || "Magis Plus Raccolta Dati.json");
    const data = payload.data || payload; // tollera sia {filename,data} sia il solo oggetto dati

    // Sondaggio clienti ("Che cosa pensi di noi"): gestito a parte
    if (data.modulo === "sondaggio_clienti") {
      try {
        return jsonResponse(gestisciSondaggio_(data));
      } catch (errDrive) {
        // solo le risposte dei clienti hanno un dato da salvare: la creazione si puo' semplicemente ripetere
        if (data.azione === "rispondi") return jsonResponse(emailDiEmergenza_(errDrive, "Risposta sondaggio - " + pulisciRiga(data.CODICE) + ".json", data));
        throw errDrive;
      }
    }
    // L'endpoint e' pubblico: i file dei sondaggi non si possono creare o sovrascrivere da qui.
    if (filename.indexOf(SONDAGGIO_PREFIX) === 0) {
      return jsonResponse({ ok: false, error: "Nome file non consentito" });
    }
    const jsonText = JSON.stringify(data, null, 2);

    // 1) Salva su Drive: UN SOLO FILE per salone, che raccoglie tutti e tre i moduli
    //    (i salvataggi successivi aggiornano lo stesso file invece di crearne di nuovi).
    // un modulo senza codice salone non si puo' abbinare a nessun file: lo rifiuto invece di creare file orfani
    if (PARTI_MODULO[data.modulo] && !String(data.CODICE || "").trim()) {
      return jsonResponse({ ok: false, error: "Codice salone mancante" });
    }
    let folder, file, mail;
    try {
      folder = cartella_();
      if (PARTI_MODULO[data.modulo] && data.CODICE) {
        const r = salvaFileUnico_(folder, filename, data);
        file = r.file; mail = r.mail;
      } else {
        file = creaFile_(folder, filename, jsonText); // dati senza "modulo": comportamento di prima
      }
    } catch (errDrive) {
      return jsonResponse(emailDiEmergenza_(errDrive, filename, data));
    }

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

    // 3) "mail" nella risposta: true = email "pronti per essere elaborati" inviata ora,
    //    false = invio fallito, assente = niente da inviare (moduli non ancora tutti compilati).
    const risposta = { ok: true, fileUrl: file.getUrl() };
    if (mail !== undefined) risposta.mail = mail;
    return jsonResponse(risposta);
  } catch (err) {
    return jsonResponse({ ok: false, error: String(err) });
  }
}

// Quali campi del JSON appartengono a ciascun modulo del sito.
const MODULO_UNICO = "raccolta_dati_completa";
const CAMPI_SALONE = ["CODICE", "NOME_SALONE", "NOME_TITOLARE", "HM2I", "TIPO_CLIENTE"];
// I tre moduli che, una volta tutti salvati, fanno partire l'email "pronti per essere elaborati".
// "sogno_salone" (clienti storici, un sogno per persona) va nello stesso file ma non conta per l'email.
const MODULI_COMPLETI = ["gestione_tariffe", "analisi_collaboratore", "immagine_sogno_salone"];
const PARTI_MODULO = {
  sogno_salone: ["sogni"],
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
    if (file.getName().indexOf(SONDAGGIO_PREFIX) === 0) continue; // i sondaggi non sono moduli del salone
    let d;
    try { d = JSON.parse(file.getBlob().getDataAsString()); } catch (err) { continue; }
    if (d && String(d.CODICE) === String(codice)) trovati.push({ file: file, data: d, t: file.getLastUpdated().getTime() });
  }
  return trovati.sort(function (a, b) { return a.t - b.t; });
}

function unisciParti_(dest, src) {
  CAMPI_SALONE.forEach(function (k) { if (src[k] != null && src[k] !== "") dest[k] = src[k]; });
  Object.keys(PARTI_MODULO).forEach(function (modulo) {
    // vecchio formato a blocchi ({ gestione_tariffe: {...}, sogno_salone: {...} }): unisco ogni blocco
    if (src[modulo] && typeof src[modulo] === "object" && !Array.isArray(src[modulo])) unisciParti_(dest, src[modulo]);
    if (src.modulo === modulo || (src.modulo === MODULO_UNICO)) {
      PARTI_MODULO[modulo].forEach(function (k) {
        if (!(k in src)) return;
        // sogni: chi ha gia' un sogno lo aggiorna, chi e' nuovo si aggiunge; nessun sogno gia' presente va perso
        dest[k] = k === "sogni" ? unisciSogni_(dest.sogni, src.sogni) : src[k];
      });
    }
  });
}

/** Sogni: chi ha già un sogno salvato (stesso nome) lo aggiorna, chi è nuovo viene aggiunto. */
function unisciSogni_(esistenti, nuovi) {
  const out = (Array.isArray(esistenti) ? esistenti : []).slice();
  (Array.isArray(nuovi) ? nuovi : []).slice(0, 40).forEach(function (s) {
    if (!s || typeof s !== "object") return;
    const testo = String(s.sogno || "").trim().slice(0, 1500);
    if (!testo) return;
    const nome = String(s.nome || "").trim().slice(0, 80);
    // un sogno gia' salvato (arriva con aggiornato_il) mantiene la sua data; uno appena inviato prende l'ora attuale
    const rec = { nome: nome, sogno: testo, aggiornato_il: s.aggiornato_il || new Date().toISOString() };
    const chiave = nome.toLowerCase();
    const idx = chiave ? out.findIndex(function (x) { return String(x.nome || "").trim().toLowerCase() === chiave; }) : -1;
    if (idx >= 0) { rec.nome = out[idx].nome || nome; out[idx] = rec; } else out.push(rec);   // si tiene il nome come era già scritto
  });
  return out;
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
      // se uno di quei file ha gia' il nome definitivo (es. file a blocchi della versione precedente),
      // lo riuso: tutto il suo contenuto e' stato appena riunito sopra, quindi non nasce un doppione
      const stessoNome = esistenti.filter(function (e) { return e.file.getName() === filename; });
      if (stessoNome.length) file = stessoNome[stessoNome.length - 1].file;
    }
    unisciParti_(unico, data);                       // il modulo appena inviato prevale
    unico.modulo = MODULO_UNICO;
    const prec = unici.length ? (unici[unici.length - 1].data.moduli_compilati || {}) : {};
    esistenti.forEach(function (e) {
      const quando = e.file.getLastUpdated().toISOString();
      if (PARTI_MODULO[e.data.modulo] && !prec[e.data.modulo]) prec[e.data.modulo] = quando;
      Object.keys(PARTI_MODULO).forEach(function (m) {            // vecchio formato a blocchi
        if (e.data[m] && typeof e.data[m] === "object" && !Array.isArray(e.data[m]) && !prec[m]) prec[m] = quando;
      });
    });
    prec[data.modulo] = new Date().toISOString();
    unico.moduli_compilati = prec;
    if (unici.length && unici[unici.length - 1].data.notifica_inviata) {
      unico.notifica_inviata = unici[unici.length - 1].data.notifica_inviata;
    }
    const testo = JSON.stringify(unico, null, 2);
    if (file) {
      file.setContent(testo);
      file.setName(filename);
    } else {
      file = creaFile_(folder, filename, testo);
    }

    // Tutti e tre i moduli compilati: avvisa Monacelli Italy, una sola volta per salone.
    // Lo decide lo script (non la pagina), cosi' nessuno puo' far partire l'email a richiesta.
    // "mail": true = inviata, false = errore (riprova al salvataggio successivo), undefined = niente da inviare.
    let mail;
    const completo = MODULI_COMPLETI.every(function (m) { return prec[m]; });
    if (completo && !unico.notifica_inviata) {
      mail = inviaEmailModuliCompleti(unico);
      if (mail) {
        unico.notifica_inviata = new Date().toISOString();
        file.setContent(JSON.stringify(unico, null, 2));
      }
    }
    return { file: file, mail: mail };
  } finally {
    lock.releaseLock();
  }
}

function cartella_() {
  return DRIVE_FOLDER_ID ? DriveApp.getFolderById(DRIVE_FOLDER_ID) : DriveApp.getRootFolder();
}

// ---------------------------------------------------------------------------
// SONDAGGIO CLIENTI
// Il sondaggio si attiva dal modulo (finestra master): finche' non e' stato
// creato, le risposte vengono rifiutate.
// ---------------------------------------------------------------------------
function leggiJson_(file) {
  try { return JSON.parse(file.getBlob().getDataAsString()); } catch (err) { return {}; }
}

/** Cerca il file del sondaggio di un salone confrontando il codice in modo esatto. */
function trovaFileSondaggio_(folder, codice) {
  const suffisso = " (" + codice + ").json";
  const it = folder.searchFiles("title contains '" + SONDAGGIO_PREFIX + "' and trashed = false");
  while (it.hasNext()) {
    const f = it.next();
    const nome = f.getName();
    if (nome.indexOf(SONDAGGIO_PREFIX) === 0 && nome.slice(-suffisso.length) === suffisso) return f;
  }
  return null;
}

function gestisciSondaggio_(inc) {
  const codice = String(inc.CODICE || "").trim();
  if (!CODICE_VALIDO_.test(codice)) return { ok: false, error: "Codice salone non valido" };
  const folder = cartella_();

  // un solo scrittore per volta: più clienti possono inviare nello stesso momento
  const lock = LockService.getScriptLock();
  lock.waitLock(25000);
  try {
    let file = trovaFileSondaggio_(folder, codice);

    if (inc.azione === "crea") {
      if (file) {
        const d = leggiJson_(file);
        return { ok: true, esistente: true, nome: d.NOME_SALONE || "", risposte: (d.risposte || []).length, fileUrl: file.getUrl() };
      }
      const nome = String(inc.NOME_SALONE || "").trim().slice(0, 80);
      if (!nome) return { ok: false, error: "Nome salone mancante" };
      const nomeFile = sanitizeFilename(SONDAGGIO_PREFIX + " - " + nome + " (" + codice + ").json");
      const d = { CODICE: codice, NOME_SALONE: nome, creato_il: new Date().toISOString(), risposte: [] };
      file = folder.createFile(nomeFile, JSON.stringify(d, null, 2), MimeType.PLAIN_TEXT);
      file.setName(nomeFile);
      return { ok: true, esistente: false, nome: nome, risposte: 0, fileUrl: file.getUrl() };
    }

    if (inc.azione === "rispondi") {
      if (!file) return { ok: false, error: "Sondaggio non attivo per questo salone" };
      const r = inc.risposta;
      if (!r || typeof r !== "object" || Array.isArray(r)) return { ok: false, error: "Risposta mancante" };
      if (JSON.stringify(r).length > 20000) return { ok: false, error: "Risposta troppo lunga" };
      const d = leggiJson_(file);
      d.risposte = d.risposte || [];
      const id = String(r.id || "");
      if (id && d.risposte.some(function (x) { return x.id === id; })) {
        return { ok: true, duplicata: true }; // invio ripetuto dopo un errore di rete: non lo conto due volte
      }
      r.ricevuta_il = new Date().toISOString();
      d.risposte.push(r);
      file.setContent(JSON.stringify(d, null, 2));
      return { ok: true };
    }

    if (inc.azione === "rinomina") {
      // cambia il nome del salone nel sondaggio gia' creato (titolo del QR e della pagina); il codice e le risposte restano
      if (!file) return { ok: false, error: "Sondaggio non ancora creato per questo salone" };
      const nome = String(inc.NOME_SALONE || "").trim().slice(0, 80);
      if (!nome) return { ok: false, error: "Nome salone mancante" };
      const d = leggiJson_(file);
      if (d.NOME_SALONE !== nome) {
        d.nomi_precedenti = (d.nomi_precedenti || []).concat([{ nome: d.NOME_SALONE || "", fino_al: new Date().toISOString() }]).slice(-20);
        d.NOME_SALONE = nome;
        file.setContent(JSON.stringify(d, null, 2));
        file.setName(sanitizeFilename(SONDAGGIO_PREFIX + " - " + nome + " (" + codice + ").json"));
      }
      return { ok: true, nome: nome, risposte: (d.risposte || []).length, fileUrl: file.getUrl() };
    }

    return { ok: false, error: "Azione non riconosciuta" };
  } finally {
    lock.releaseLock();
  }
}

// ---------------------------------------------------------------------------
// PAGINA RIEPILOGO SALONI
// ---------------------------------------------------------------------------
function chiaveAdmin_() {
  return PropertiesService.getScriptProperties().getProperty("ADMIN_KEY") || "";
}

function gestisciAdmin(p) {
  const chiave = chiaveAdmin_();
  if (!chiave) return { ok: false, error: "Pagina riepilogo non abilitata: imposta ADMIN_KEY nelle Proprietà script (Impostazioni progetto)." };
  if (String(p.key || "") !== chiave) {
    Utilities.sleep(1500); // rallenta i tentativi a caso
    return { ok: false, error: "Password non valida" };
  }
  if (p.action === "admin_list") return elencaModuli();
  if (p.action === "admin_save") return salvaModulo(p);
  // crea il sondaggio di un salone dalla pagina riepilogo (se esiste gia' lo riconosce e non lo duplica)
  if (p.action === "admin_sondaggio") return gestisciSondaggio_({ azione: "crea", CODICE: p.CODICE, NOME_SALONE: p.NOME_SALONE });
  return { ok: false, error: "Azione sconosciuta" };
}

/** Per ogni salone e modulo restituisce il file piu' recente (i salvataggi ripetuti creano piu' file). */
function elencaModuli() {
  const piuRecenti = {};
  const sondaggi = {};   // un sondaggio per salone: { codice, nome, creato_il, risposte (numero di questionari inviati) }
  const it = cartella_().getFiles();
  while (it.hasNext()) {
    const file = it.next();
    if (file.getSize() > 2000000) continue;
    let d;
    try { d = JSON.parse(file.getBlob().getDataAsString()); } catch (err) { continue; }
    if (d && d.CODICE && file.getName().indexOf(SONDAGGIO_PREFIX) === 0) {
      const t0 = file.getLastUpdated().getTime();
      if (!sondaggi[d.CODICE] || t0 > sondaggi[d.CODICE].t) {
        sondaggi[d.CODICE] = { t: t0, codice: String(d.CODICE), nome: d.NOME_SALONE || "", creato_il: d.creato_il || "",
                               risposte: (d.risposte || []).length };
      }
      continue;
    }
    if (!d || !d.modulo || !d.CODICE) continue;
    const t = file.getLastUpdated().getTime();
    const k = d.CODICE + "|" + d.modulo;
    if (!piuRecenti[k] || t > piuRecenti[k].t) {
      piuRecenti[k] = { t: t, fileId: file.getId(), name: file.getName(),
                        updated: file.getLastUpdated().toISOString(), data: d };
    }
  }
  return { ok: true, items: Object.keys(piuRecenti).map(function (k) { return piuRecenti[k]; }),
           sondaggi: Object.keys(sondaggi).map(function (k) { const x = sondaggi[k]; delete x.t; return x; }) };
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

/**
 * Drive non ha funzionato: invia il file JSON in allegato a NOTIFY_EMAIL (destinatario fisso, mai preso dalla richiesta).
 * Risposta al sito: ok:true + drive:false se l'email e' partita (il dato e' al sicuro); ok:false se anche l'email fallisce,
 * cosi' la pagina scarica la copia di riserva sul dispositivo.
 */
function emailDiEmergenza_(errDrive, filename, data) {
  const motivo = String(errDrive);
  if (!EMAIL_SE_DRIVE_FALLISCE) return { ok: false, error: motivo };
  try {
    const nomeFile = sanitizeFilename(filename);
    const testo = JSON.stringify(data, null, 2);
    GmailApp.sendEmail(
      NOTIFY_EMAIL,
      "Magis Plus — ATTENZIONE: Drive non raggiungibile, dati ricevuti via email (" + pulisciRiga(nomeFile) + ")",
      "Il salvataggio su Google Drive non e' riuscito, quindi il file e' allegato a questa email.\n" +
        "Salvalo a mano nella cartella Drive dei moduli.\n\n" +
        "File: " + pulisciRiga(nomeFile) + "\n" +
        "Errore Drive: " + pulisciRiga(motivo),
      { attachments: [Utilities.newBlob(testo, "application/json", nomeFile)] }
    );
    return { ok: true, drive: false, email: true };
  } catch (errMail) {
    return { ok: false, error: motivo + " (anche l'email di emergenza non e' partita: " + String(errMail) + ")" };
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

/** GET: senza parametri verifica che l'app sia online; con ?azione=info&c=CODICE
 *  (o &codice=CODICE) dice se il sondaggio del salone è attivo e restituisce il nome. */
function doGet(e) {
  const p = (e && e.parameter) || {};
  if (p.azione === "info") {
    // "codice" e non "c": Google risponde 400 a "?c=<lettere>" prima ancora di eseguire lo script
    const codice = String(p.codice || p.c || "").trim();
    if (!CODICE_VALIDO_.test(codice)) return jsonResponse({ ok: false, error: "Codice non valido" });
    const file = trovaFileSondaggio_(cartella_(), codice);
    if (!file) return jsonResponse({ ok: false, error: "Sondaggio non attivo" });
    const d = leggiJson_(file);
    return jsonResponse({ ok: true, nome: d.NOME_SALONE || "" });
  }
  return ContentService.createTextOutput(
    "Magis Plus — endpoint attivo. Invia i dati con una richiesta POST."
  );
}

/**
 * Aggiunta al Web App di Google Apps Script per l'email di notifica finale.
 *
 * Il modulo (pagina web) invia a doPost un JSON del tipo
 *   { filename, data, notifica }
 * dove "notifica" e' presente SOLO nel salvataggio che completa i tre moduli:
 *   notifica = { to, subject, body }
 *
 * COME USARLA: nel tuo doPost esistente, dopo aver salvato il file su Drive,
 * aggiungi queste righe e includi "mail" nella risposta JSON:
 *
 *   const req = JSON.parse(e.postData.contents);
 *   ... (il tuo salvataggio su Drive) ...
 *   const mail = inviaNotifica_(req.notifica);          // true / false / null
 *   return ContentService.createTextOutput(JSON.stringify({ ok: true, mail: mail }))
 *     .setMimeType(ContentService.MimeType.JSON);
 *
 * Dopo la modifica: Distribuisci > Gestisci distribuzioni > Modifica > Nuova versione,
 * e autorizza il nuovo permesso "Invia email a tuo nome".
 */
function inviaNotifica_(n) {
  if (!n || !n.to) return null;             // salvataggio che non completa i moduli: nessuna email
  try {
    MailApp.sendEmail({ to: n.to, subject: n.subject, body: n.body });
    return true;
  } catch (err) {
    return false;
  }
}

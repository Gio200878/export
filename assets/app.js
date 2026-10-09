// ==========================================================
// HEMI Gestionale - app.js
// Calendario mensile stile Google Calendar + filtri collegati
// ==========================================================

let vistaData = new Date();
let opzioniCache = { aree: [], zone: [] };
let hm2iCache = [];
let appuntamentiCache = [];
let saloniCache = [];

const MESI = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
const GIORNI = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];

document.addEventListener('DOMContentLoaded', async () => {
  await caricaOpzioni();
  await caricaHm2i();
  bindFiltri();
  bindNavigazione();
  await renderCalendario();
});

function bindNavigazione() {
  document.getElementById('btn-prev').addEventListener('click', () => {
    vistaData.setMonth(vistaData.getMonth() - 1);
    renderCalendario();
  });
  document.getElementById('btn-next').addEventListener('click', () => {
    vistaData.setMonth(vistaData.getMonth() + 1);
    renderCalendario();
  });
  document.getElementById('btn-today').addEventListener('click', () => {
    vistaData = new Date();
    renderCalendario();
  });
}

async function caricaOpzioni() {
  const res = await fetch('api.php?action=get_opzioni');
  const data = await res.json();
  if (data.ok) {
    opzioniCache = data;
    const selArea = document.getElementById('filtro-area');
    const selZona = document.getElementById('filtro-zona');
    data.aree.forEach(a => selArea.append(new Option(a.nome, a.id)));
    data.zone.forEach(z => selZona.append(new Option(z.nome, z.id)));
  }
}

async function caricaHm2i() {
  const res = await fetch('api.php?action=get_hm2i_selezionabili');
  const data = await res.json();
  if (data.ok) {
    hm2iCache = data.hm2i;
    const selHm2i = document.getElementById('filtro-hm2i');
    hm2iCache.forEach(h => selHm2i.append(new Option(h.cognome + ' ' + h.nome, h.id)));
  }
}

function bindFiltri() {
  ['filtro-hm2i', 'filtro-area', 'filtro-hemi', 'filtro-zona', 'filtro-stato'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => renderCalendario());
  });
}

function getFiltri() {
  const f = {};
  ['hm2i_id:filtro-hm2i', 'area_id:filtro-area', 'hemi_id:filtro-hemi', 'zona_id:filtro-zona', 'stato:filtro-stato'].forEach(pair => {
    const [key, id] = pair.split(':');
    const el = document.getElementById(id);
    if (el && el.value) f[key] = el.value;
  });
  return f;
}

function primoGiornoMese(d) {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}
function ultimoGiornoMese(d) {
  return new Date(d.getFullYear(), d.getMonth() + 1, 0);
}
function formatISO(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const g = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${g}`;
}

async function renderCalendario() {
  document.getElementById('mese-corrente').textContent = MESI[vistaData.getMonth()] + ' ' + vistaData.getFullYear();

  const primo = primoGiornoMese(vistaData);
  const ultimo = ultimoGiornoMese(vistaData);

  // Calcola la griglia includendo i giorni del mese prec/succ per riempire le settimane (lunedì come primo giorno)
  const startOffset = (primo.getDay() + 6) % 7; // 0 = lunedì
  const gridStart = new Date(primo);
  gridStart.setDate(gridStart.getDate() - startOffset);

  const endOffset = (7 - ((ultimo.getDay() + 6) % 7 + 1)) % 7;
  const gridEnd = new Date(ultimo);
  gridEnd.setDate(gridEnd.getDate() + endOffset);

  const filtri = getFiltri();
  const params = new URLSearchParams({
    action: 'get_appuntamenti',
    inizio: formatISO(gridStart),
    fine: formatISO(gridEnd),
    ...filtri,
  });
  const res = await fetch('api.php?' + params.toString());
  const data = await res.json();
  appuntamentiCache = data.ok ? data.appuntamenti : [];

  const grid = document.getElementById('calendar-grid');
  grid.innerHTML = '';

  GIORNI.forEach(g => {
    const el = document.createElement('div');
    el.className = 'calendar-weekday';
    el.textContent = g;
    grid.appendChild(el);
  });

  const oggiISO = formatISO(new Date());
  let cursor = new Date(gridStart);
  while (cursor <= gridEnd) {
    const iso = formatISO(cursor);
    const isOtherMonth = cursor.getMonth() !== vistaData.getMonth();
    const dayEl = document.createElement('div');
    dayEl.className = 'calendar-day' + (isOtherMonth ? ' other-month' : '') + (iso === oggiISO ? ' today' : '');
    dayEl.innerHTML = `<div class="day-number">${cursor.getDate()}</div>`;

    const apptsGiorno = appuntamentiCache.filter(a => a.data_appuntamento === iso);
    apptsGiorno.forEach(a => {
      const chip = document.createElement('span');
      const occupato = a._offuscato;
      chip.className = 'appt-chip' + (occupato ? ' occupato' : '');
      chip.style.background = occupato ? '#999' : a.colore;
      chip.textContent = occupato
        ? ('Occupato - ' + (a.nome_visibile_occupato || ''))
        : (a.ora_inizio.slice(0,5) + ' ' + a.salone);
      chip.addEventListener('click', (ev) => { ev.stopPropagation(); apriDettaglio(a.id); });
      dayEl.appendChild(chip);
    });

    dayEl.addEventListener('click', () => {
      if (PUO_CREARE) apriNuovo(iso);
    });

    grid.appendChild(dayEl);
    cursor.setDate(cursor.getDate() + 1);
  }
}

// ---------------------------------------------------------
// MODALE: nuovo appuntamento
// ---------------------------------------------------------
// Carica i saloni dell'HM2I indicato (il server limita comunque ai saloni visibili all'utente)
async function caricaSaloni(hm2iId) {
  const res = await fetch('api.php?action=get_saloni' + (hm2iId ? '&hm2i_id=' + encodeURIComponent(hm2iId) : ''));
  const data = await res.json();
  saloniCache = data.ok ? data.saloni : [];
}

async function ricaricaSaloniPerHm2i() {
  await caricaSaloni(getHm2iFormValue());
  aggiornaDatalistSaloni();
  document.getElementById('salone-cerca').value = '';
  selezionaSaloneDaTesto();
}

function etichettaSalone(s) {
  return (s.codice ? s.codice + ' - ' : '') + s.nome;
}

function escHtml(t) {
  return String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function aggiornaDatalistSaloni() {
  document.getElementById('lista-saloni').innerHTML =
    saloniCache.map(s => `<option value="${escHtml(etichettaSalone(s))}"></option>`).join('');
}

// Collega il campo di ricerca salone (codice o nome) a salone_id / salone
function selezionaSaloneDaTesto() {
  const txt = document.getElementById('salone-cerca').value.trim().toLowerCase();
  const s = saloniCache.find(x => etichettaSalone(x).toLowerCase() === txt);
  document.querySelector('[name=salone_id]').value = s ? s.id : '';
  document.querySelector('[name=salone]').value = s ? s.nome : '';
}

async function salvaNuovoSalone() {
  const err = document.getElementById('nuovo-salone-errore');
  err.style.display = 'none';
  const payload = {
    codice: document.getElementById('ns-codice').value,
    nome: document.getElementById('ns-nome').value,
    prov: document.getElementById('ns-prov').value,
    hm2i_id: getHm2iFormValue(),
  };
  const res = await fetch('api.php?action=crea_salone', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!data.ok) {
    err.textContent = data.error || 'Errore nel salvataggio del salone.';
    err.style.display = 'block';
    return;
  }
  saloniCache.push(data.salone);
  saloniCache.sort((a, b) => a.nome.localeCompare(b.nome));
  aggiornaDatalistSaloni();
  document.getElementById('salone-cerca').value = etichettaSalone(data.salone);
  selezionaSaloneDaTesto();
  document.getElementById('box-nuovo-salone').style.display = 'none';
  ['ns-codice', 'ns-nome', 'ns-prov'].forEach(id => document.getElementById(id).value = '');
}

function getHm2iFormValue() {
  const el = document.querySelector('#form-nuovo-appuntamento [name=hm2i_id]');
  return el ? el.value : '';
}

// Ricarica i HEMI compatibili con area d'intervento + zone dell'HM2I richiedente
async function aggiornaListaHemi() {
  const sel = document.getElementById('hemi-select');
  const areaId = document.querySelector('#form-nuovo-appuntamento [name=area_id]').value;
  const hm2iId = getHm2iFormValue();
  const precedente = sel.value;
  sel.innerHTML = '<option value="">-- da assegnare --</option>';
  if (areaId && hm2iId) {
    const res = await fetch(`api.php?action=get_hemi_per_area&area_id=${areaId}&hm2i_id=${hm2iId}`);
    const data = await res.json();
    if (data.ok) {
      data.hemi.forEach(h => sel.append(new Option(h.cognome + ' ' + h.nome, h.id)));
      if (data.hemi.some(h => String(h.id) === precedente)) sel.value = precedente;
    }
  }
  verificaDisponibilitaHemi();
}

// Controllo in tempo reale della disponibilità (il controllo definitivo avviene comunque lato server al salvataggio)
async function verificaDisponibilitaHemi() {
  const avviso = document.getElementById('hemi-non-disponibile');
  avviso.style.display = 'none';
  const f = document.getElementById('form-nuovo-appuntamento');
  const hemiId = f.hemi_id.value;
  if (!hemiId || !f.data_appuntamento.value || !f.ora_inizio.value || !f.ora_fine.value) return;
  const p = new URLSearchParams({
    action: 'check_disponibilita', hemi_id: hemiId, data: f.data_appuntamento.value,
    ora_inizio: f.ora_inizio.value, ora_fine: f.ora_fine.value,
  });
  const res = await fetch('api.php?' + p.toString());
  const data = await res.json();
  if (data.ok && !data.disponibile) {
    avviso.textContent = data.messaggio;
    avviso.style.display = 'block';
  }
}

async function apriNuovo(dataISO) {
  await caricaSaloni(CURRENT_ROLE === 'hm2i' ? CURRENT_USER_ID : (hm2iCache[0] ? hm2iCache[0].id : null));
  document.getElementById('modal-title').textContent = 'Nuovo appuntamento';

  let opzioniHm2i = '';
  if (CURRENT_ROLE === 'hm2i') {
    const io = hm2iCache.find(h => String(h.id) === String(CURRENT_USER_ID));
    opzioniHm2i = `<option value="${CURRENT_USER_ID}">${io ? io.cognome + ' ' + io.nome : 'Io'}</option>`;
  } else {
    opzioniHm2i = hm2iCache.map(h => `<option value="${h.id}">${h.cognome} ${h.nome}</option>`).join('');
  }

  const opzioniAree = opzioniCache.aree.map(a => `<option value="${a.id}">${a.nome}</option>`).join('');

  document.getElementById('modal-body').innerHTML = `
    <form id="form-nuovo-appuntamento">
      <label>Area d'intervento</label>
      <select name="area_id" required>${opzioniAree}</select>

      <label>HEMI (in base ad area d'intervento e zona dell'HM2I)</label>
      <select name="hemi_id" id="hemi-select"><option value="">-- da assegnare --</option></select>
      <div id="hemi-non-disponibile" style="display:none;color:#b04a4a;font-weight:700;margin:4px 0 8px;"></div>

      <label>HM2I</label>
      <select name="hm2i_id" required ${CURRENT_ROLE === 'hm2i' ? 'disabled' : ''}>${opzioniHm2i}</select>
      ${CURRENT_ROLE === 'hm2i' ? `<input type="hidden" name="hm2i_id" value="${CURRENT_USER_ID}">` : ''}

      <label>Salone (codice o nome)</label>
      <input type="text" id="salone-cerca" list="lista-saloni" placeholder="Cerca per codice o nome..." autocomplete="off" required>
      <datalist id="lista-saloni"></datalist>
      <input type="hidden" name="salone_id">
      <input type="hidden" name="salone">
      <a href="#" id="link-nuovo-salone" style="font-size:12px;">+ Nuovo salone</a>
      <div id="box-nuovo-salone" style="display:none;border:1px solid var(--border, #ddd);border-radius:6px;padding:10px;margin:6px 0;">
        <div class="row">
          <div><label>Codice</label><input type="text" id="ns-codice" maxlength="20"></div>
          <div><label>Prov.</label><input type="text" id="ns-prov" maxlength="5"></div>
        </div>
        <label>Nome salone</label>
        <input type="text" id="ns-nome" maxlength="200">
        <div id="nuovo-salone-errore" class="alert alert-error" style="display:none;"></div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('box-nuovo-salone').style.display='none'">Annulla</button>
          <button type="button" class="btn btn-primary" onclick="salvaNuovoSalone()">Aggiungi salone</button>
        </div>
      </div>

      <label>Indirizzo</label>
      <input type="text" name="indirizzo" required>

      <div class="row">
        <div>
          <label>Telefono</label>
          <input type="text" name="telefono">
        </div>
        <div>
          <label>ZTL</label>
          <select name="ztl">
            <option value="no">No</option>
            <option value="si">Si</option>
          </select>
        </div>
      </div>

      <label>Note</label>
      <textarea name="note" rows="2"></textarea>

      <label>Data</label>
      <input type="date" name="data_appuntamento" value="${dataISO}" required>

      <div class="row">
        <div>
          <label>Ora inizio</label>
          <input type="time" name="ora_inizio" required>
        </div>
        <div>
          <label>Ora fine</label>
          <input type="time" name="ora_fine" required>
        </div>
      </div>

      <div id="form-errore" class="alert alert-error" style="display:none;"></div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="chiudiModale()">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  `;

  const formNuovo = document.getElementById('form-nuovo-appuntamento');
  formNuovo.addEventListener('submit', salvaNuovoAppuntamento);
  aggiornaDatalistSaloni();
  document.getElementById('salone-cerca').addEventListener('input', selezionaSaloneDaTesto);
  document.getElementById('link-nuovo-salone').addEventListener('click', (ev) => {
    ev.preventDefault();
    document.getElementById('box-nuovo-salone').style.display = 'block';
  });
  const selHm2i = formNuovo.querySelector('select[name=hm2i_id]');
  if (selHm2i) selHm2i.addEventListener('change', ricaricaSaloniPerHm2i);
  ['area_id', 'hm2i_id'].forEach(n => {
    const el = formNuovo.querySelector(`[name=${n}]`);
    if (el) el.addEventListener('change', aggiornaListaHemi);
  });
  ['hemi_id', 'data_appuntamento', 'ora_inizio', 'ora_fine'].forEach(n =>
    formNuovo[n].addEventListener('change', verificaDisponibilitaHemi));
  aggiornaListaHemi();
  document.getElementById('modal-appuntamento').classList.add('open');
}

async function salvaNuovoAppuntamento(ev) {
  ev.preventDefault();
  const form = ev.target;
  const fd = new FormData(form);
  const payload = Object.fromEntries(fd.entries());

  if (!payload.salone_id) {
    const errEl = document.getElementById('form-errore');
    errEl.textContent = 'Seleziona un salone dall\'elenco oppure aggiungine uno nuovo.';
    errEl.style.display = 'block';
    return;
  }

  const res = await fetch('api.php?action=crea_appuntamento', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!data.ok) {
    const errEl = document.getElementById('form-errore');
    errEl.textContent = data.error || 'Errore nel salvataggio.';
    errEl.style.display = 'block';
    errEl.style.fontWeight = '700';
    return;
  }
  chiudiModale();
  renderCalendario();
}

// ---------------------------------------------------------
// MODALE: dettaglio appuntamento
// ---------------------------------------------------------
async function apriDettaglio(id) {
  const res = await fetch('api.php?action=get_appuntamento&id=' + id);
  const data = await res.json();
  if (!data.ok) return;
  const a = data.appuntamento;

  document.getElementById('modal-title').textContent = 'Dettaglio appuntamento';

  if (a.occupato) {
    document.getElementById('modal-body').innerHTML = `
      <p><strong>Occupato</strong> - ${a.nome_visibile_occupato || ''}</p>
      <p>${a.data_appuntamento} dalle ${a.ora_inizio.slice(0,5)} alle ${a.ora_fine.slice(0,5)}</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="chiudiModale()">Chiudi</button>
      </div>
    `;
  } else {
    const badgeClass = a.stato === 'approvato' ? 'badge-verde' : (a.stato === 'rifiutato' ? 'badge-rosso' : 'badge-giallo');
    const badgeLabel = a.stato === 'approvato' ? 'Approvato' : (a.stato === 'rifiutato' ? 'Rifiutato' : 'Da approvare');

    document.getElementById('modal-body').innerHTML = `
      <span class="badge ${badgeClass}">${badgeLabel}</span>
      <p><strong>${a.salone}</strong> - ${a.area_nome}</p>
      <p>${a.indirizzo} ${a.telefono ? '- Tel: ' + a.telefono : ''}</p>
      <p>ZTL: ${a.ztl === 'si' ? 'Si' : 'No'}</p>
      <p>${a.data_appuntamento} dalle ${a.ora_inizio.slice(0,5)} alle ${a.ora_fine.slice(0,5)}</p>
      <p>HM2I: ${a.hm2i_cognome} ${a.hm2i_nome}</p>
      ${a.hemi_nome ? `<p>HEMI assegnato: ${a.hemi_cognome} ${a.hemi_nome}</p>` : ''}
      ${a.note ? `<p>Note: ${a.note}</p>` : ''}
      ${((CURRENT_ROLE === 'admin' || CURRENT_ROLE === 'hemi') && (a.compenso || a.rimborso_spese)) ? `<p>Compenso: ${a.compenso ?? '-'} € | Rimborso: ${a.rimborso_spese ?? '-'} €</p>` : ''}
      ${a.puo_chat && CURRENT_ROLE === 'hemi' ? boxChatHtml(a.id) : ''}
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="chiudiModale()">Chiudi</button>
      </div>
    `;
    if (a.puo_chat && CURRENT_ROLE === 'hemi') caricaChat(a.id);
  }

  document.getElementById('modal-appuntamento').classList.add('open');
}

// ---------------------------------------------------------
// CHAT HEMI -> ADMIN (richieste di informazioni su un appuntamento)
// ---------------------------------------------------------
function boxChatHtml(appId) {
  return `
    <div class="chatbox">
      <h4>Richiedi informazioni a MONACELLI ITALY</h4>
      <div class="chat-messaggi" id="chat-messaggi"></div>
      <div id="chat-errore" class="alert alert-error" style="display:none;"></div>
      <div class="chat-form">
        <textarea id="chat-testo" rows="2" maxlength="2000" placeholder="Scrivi qui la tua richiesta..."></textarea>
        <button type="button" class="btn btn-primary" onclick="inviaChat(${appId})">Invia</button>
      </div>
    </div>`;
}

function renderChat(messaggi, inAttesa) {
  const box = document.getElementById('chat-messaggi');
  if (!box) return;
  box.innerHTML = messaggi.length
    ? messaggi.map(m => `<div class="chat-msg ${m.mittente_ruolo === 'admin' ? 'admin' : 'mio'}">
        <div class="chat-meta">${escHtml(m.mittente_ruolo === 'admin' ? 'MONACELLI ITALY' : m.cognome + ' ' + m.nome)} - ${escHtml(m.created_at)}</div>
        ${escHtml(m.testo).replace(/\n/g, '<br>')}</div>`).join('')
    : '<div class="chat-vuoto">Nessun messaggio.</div>';
  if (inAttesa) box.insertAdjacentHTML('beforeend', '<div class="chat-attesa">In attesa di risposta da MONACELLI ITALY</div>');
  box.scrollTop = box.scrollHeight;
}

async function caricaChat(appId) {
  const res = await fetch('api.php?action=get_messaggi&appuntamento_id=' + appId);
  const data = await res.json();
  if (data.ok) renderChat(data.messaggi, data.in_attesa);
}

async function inviaChat(appId) {
  const txt = document.getElementById('chat-testo');
  const err = document.getElementById('chat-errore');
  err.style.display = 'none';
  const res = await fetch('api.php?action=invia_messaggio', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ appuntamento_id: appId, testo: txt.value }),
  });
  const data = await res.json();
  if (!data.ok) { err.textContent = data.error || 'Errore.'; err.style.display = 'block'; return; }
  txt.value = '';
  renderChat(data.messaggi, data.in_attesa);
  renderCalendario(); // l'appuntamento diventa blu finché MONACELLI ITALY non risponde
}

function chiudiModale() {
  document.getElementById('modal-appuntamento').classList.remove('open');
}

document.getElementById('modal-appuntamento').addEventListener('click', (ev) => {
  if (ev.target.id === 'modal-appuntamento') chiudiModale();
});

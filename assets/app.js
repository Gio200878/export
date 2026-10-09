// ==========================================================
// HEMI Gestionale - app.js
// Calendario mensile stile Google Calendar + filtri collegati
// ==========================================================

let vistaData = new Date();
let opzioniCache = { aree: [], zone: [] };
let hm2iCache = [];
let appuntamentiCache = [];

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
function apriNuovo(dataISO) {
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

      <label>HM2I</label>
      <select name="hm2i_id" required ${CURRENT_ROLE === 'hm2i' ? 'disabled' : ''}>${opzioniHm2i}</select>
      ${CURRENT_ROLE === 'hm2i' ? `<input type="hidden" name="hm2i_id" value="${CURRENT_USER_ID}">` : ''}

      <label>Salone</label>
      <input type="text" name="salone" required>

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

  document.getElementById('form-nuovo-appuntamento').addEventListener('submit', salvaNuovoAppuntamento);
  document.getElementById('modal-appuntamento').classList.add('open');
}

async function salvaNuovoAppuntamento(ev) {
  ev.preventDefault();
  const form = ev.target;
  const fd = new FormData(form);
  const payload = Object.fromEntries(fd.entries());

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
      ${(CURRENT_ROLE === 'admin' && (a.compenso || a.rimborso_spese)) ? `<p>Compenso: ${a.compenso ?? '-'} € | Rimborso: ${a.rimborso_spese ?? '-'} €</p>` : ''}
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="chiudiModale()">Chiudi</button>
      </div>
    `;
  }

  document.getElementById('modal-appuntamento').classList.add('open');
}

function chiudiModale() {
  document.getElementById('modal-appuntamento').classList.remove('open');
}

document.getElementById('modal-appuntamento').addEventListener('click', (ev) => {
  if (ev.target.id === 'modal-appuntamento') chiudiModale();
});

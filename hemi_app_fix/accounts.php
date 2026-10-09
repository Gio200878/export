<?php
require_once __DIR__ . '/lib/auth.php';
$u = require_login();
if ($u['role'] !== 'admin') { header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Gestione Account · Prenotazioni HEMI</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="topbar">
  <div>
    <h1>Gestione Account</h1>
    <div class="sub">Monacelli Italy · Monacelli Academy · Amministratore</div>
  </div>
  <div class="tabs">
    <a class="tab-btn tab-link" href="index.php">← Torna alle prenotazioni</a>
  </div>
  <div style="display:flex;align-items:center;gap:14px;">
    <div class="values">Qualità · Felicità · Ricchezza</div>
    <a class="btn secondary small" style="border-color:#7a7268;color:#c8c0b4;text-decoration:none;" href="logout.php">Esci</a>
  </div>
</div>

<div class="layout" style="grid-template-columns:360px 1fr;">
  <div class="panel">
    <h2 id="formTitle">Nuovo account</h2>
    <input type="hidden" id="editId" value="">
    <div class="field"><label>Nome da mostrare</label><input type="text" id="displayName" placeholder="Nome e cognome"></div>
    <div class="field" id="usernameField"><label>Username</label><input type="text" id="username" placeholder="es. mario.rossi" autocomplete="off"></div>
    <div class="field"><label id="passLabel">Password (min 6)</label><input type="password" id="password" placeholder="••••••••" autocomplete="new-password"></div>
    <div class="field"><label>WhatsApp (con prefisso, es. 393331234567)</label><input type="text" id="phone" placeholder="39..." autocomplete="off"></div>
    <div class="field">
      <label>Ruolo</label>
      <select id="role" onchange="onRoleChange()">
        <option value="admin">Admin — accesso completo</option>
        <option value="hm2i">HM2I — inserisce prenotazioni</option>
        <option value="responsabile">Responsabile — vede solo i suoi educator</option>
      </select>
    </div>
    <div id="hm2iOptions" style="display:none;">
      <div class="field">
        <label>Mentor collegato (HM2I)</label>
        <select id="mentorId"><option value="">— Nessuno (usa il nome mostrato) —</option></select>
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:13px;font-weight:normal;color:var(--ink);">
          <input type="checkbox" id="canModify" style="width:auto;"> Autorizzato a spostare / eliminare le proprie prenotazioni
        </label>
      </div>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn" style="flex:1;" onclick="saveUser()" id="saveBtn">Crea account</button>
      <button class="btn secondary" onclick="resetForm()" id="cancelBtn" style="display:none;">Annulla</button>
    </div>
    <div id="formMsg" style="font-size:12.5px;margin-top:12px;display:none;"></div>
    <div style="font-size:11.5px;color:var(--muted);margin-top:16px;line-height:1.6;">
      <strong>Admin</strong>: tutto (inserire, modificare, eliminare, gestire account).<br>
      <strong>HM2I</strong>: inserisce nuovi appuntamenti; sposta/elimina solo i propri e solo se autorizzato.<br>
      <strong>Responsabile</strong>: vede in sola lettura solo gli educator a lui assegnati (assegnazione nella scheda Gestione).
    </div>
  </div>

  <div class="panel">
    <h2>Account esistenti</h2>
    <div id="usersTableWrap"></div>
  </div>
</div>

<div class="modal-overlay" id="confirmOverlay">
  <div class="modal" style="max-width:380px;">
    <h3>Conferma</h3>
    <div style="font-size:13.5px;margin:12px 0 20px;" id="confirmMessage"></div>
    <div class="modal-actions">
      <button class="btn secondary" onclick="document.getElementById('confirmOverlay').classList.remove('open')">Annulla</button>
      <button class="btn danger" id="confirmYesBtn">Conferma</button>
    </div>
  </div>
</div>

<script>
let users = [], mentors = [], meId = null;

async function api(action, payload = {}) {
  const res = await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action, ...payload})});
  if (res.status === 401) { window.location.href = 'login.php'; throw new Error('auth'); }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) { const e = new Error(data.error || 'Errore'); e.data = data; throw e; }
  return data;
}
function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

async function load() {
  const d = await api('bootstrap');
  meId = d.me.id;
  users = d.users || [];
  mentors = d.mentors || [];
  const sel = document.getElementById('mentorId');
  sel.innerHTML = '<option value="">— Nessuno (usa il nome mostrato) —</option>';
  mentors.forEach(m => { const o = document.createElement('option'); o.value = m.id; o.textContent = m.name; sel.appendChild(o); });
  renderUsers();
}

function roleLabel(r){return {admin:'Admin',hm2i:'HM2I',responsabile:'Responsabile'}[r]||r;}

function renderUsers() {
  const wrap = document.getElementById('usersTableWrap');
  if (users.length === 0) { wrap.innerHTML = '<div class="empty-state">Nessun account.</div>'; return; }
  let html = '<table class="admin-table"><thead><tr><th>Nome</th><th>Username</th><th>Ruolo</th><th>Dettagli</th><th>Stato</th><th></th></tr></thead><tbody>';
  users.forEach(u => {
    let det = '';
    if (u.role === 'hm2i') {
      const m = mentors.find(x => x.id === u.mentorId);
      det = (m ? 'Mentor: ' + esc(m.name) : 'Mentor: —') + (u.canModify ? ' · può modificare' : ' · sola aggiunta');
    }
    if (u.phone) det += (det ? ' · ' : '') + '📱 ' + esc(u.phone);
    html += `<tr>
      <td>${esc(u.displayName)}</td>
      <td>${esc(u.username)}</td>
      <td><span class="tag ${u.role}">${roleLabel(u.role)}</span></td>
      <td style="font-size:11.5px;color:var(--muted);">${det}</td>
      <td>${u.active ? '<span class="tag responsabile">Attivo</span>' : '<span class="tag off">Disattivo</span>'}</td>
      <td style="white-space:nowrap;">
        <button class="btn secondary small" onclick='editUser(${u.id})'>Modifica</button>
        ${u.id === meId ? '' : `<button class="btn danger small" onclick='deleteUser(${u.id})'>Elimina</button>`}
      </td>
    </tr>`;
  });
  html += '</tbody></table>';
  wrap.innerHTML = html;
}

function onRoleChange() {
  document.getElementById('hm2iOptions').style.display = document.getElementById('role').value === 'hm2i' ? '' : 'none';
}

function editUser(id) {
  const u = users.find(x => x.id === id);
  if (!u) return;
  document.getElementById('editId').value = u.id;
  document.getElementById('formTitle').textContent = 'Modifica account';
  document.getElementById('displayName').value = u.displayName;
  document.getElementById('username').value = u.username;
  document.getElementById('usernameField').style.display = 'none'; // username non modificabile
  document.getElementById('password').value = '';
  document.getElementById('passLabel').textContent = 'Nuova password (lascia vuoto per non cambiarla)';
  document.getElementById('phone').value = u.phone || '';
  document.getElementById('role').value = u.role;
  document.getElementById('mentorId').value = u.mentorId || '';
  document.getElementById('canModify').checked = !!u.canModify;
  onRoleChange();
  document.getElementById('saveBtn').textContent = 'Salva modifiche';
  document.getElementById('cancelBtn').style.display = '';
  window.scrollTo({top:0, behavior:'smooth'});
}

function resetForm() {
  document.getElementById('editId').value = '';
  document.getElementById('formTitle').textContent = 'Nuovo account';
  document.getElementById('displayName').value = '';
  document.getElementById('username').value = '';
  document.getElementById('usernameField').style.display = '';
  document.getElementById('password').value = '';
  document.getElementById('passLabel').textContent = 'Password (min 6)';
  document.getElementById('phone').value = '';
  document.getElementById('role').value = 'admin';
  document.getElementById('mentorId').value = '';
  document.getElementById('canModify').checked = false;
  onRoleChange();
  document.getElementById('saveBtn').textContent = 'Crea account';
  document.getElementById('cancelBtn').style.display = 'none';
  msg('', null);
}

function msg(text, ok) {
  const el = document.getElementById('formMsg');
  if (!text) { el.style.display = 'none'; return; }
  el.textContent = text;
  el.style.display = 'block';
  el.style.color = ok ? '#4d6644' : 'var(--coral)';
}

async function saveUser() {
  const id = document.getElementById('editId').value;
  const displayName = document.getElementById('displayName').value.trim();
  const password = document.getElementById('password').value;
  const role = document.getElementById('role').value;
  const mentorId = document.getElementById('mentorId').value ? parseInt(document.getElementById('mentorId').value, 10) : null;
  const canModify = document.getElementById('canModify').checked;
  const phone = document.getElementById('phone').value.trim();
  try {
    if (id) {
      const payload = {id: parseInt(id, 10), displayName, role, mentorId, canModify, phone};
      if (password) payload.password = password;
      await api('user.update', payload);
    } else {
      const username = document.getElementById('username').value.trim();
      await api('user.create', {username, password, displayName, role, mentorId, canModify, phone});
    }
    resetForm();
    await load();
    msg('Account salvato.', true);
  } catch (e) { msg(e.message, false); }
}

function deleteUser(id) {
  const u = users.find(x => x.id === id);
  const overlay = document.getElementById('confirmOverlay');
  document.getElementById('confirmMessage').textContent = `Eliminare l'account "${u.displayName}"?`;
  overlay.classList.add('open');
  const btn = document.getElementById('confirmYesBtn');
  const nb = btn.cloneNode(true);
  btn.parentNode.replaceChild(nb, btn);
  nb.addEventListener('click', async () => {
    overlay.classList.remove('open');
    try { await api('user.delete', {id}); await load(); } catch (e) { msg(e.message, false); }
  });
}

load().catch(e => { if (e.message !== 'auth') msg('Errore: ' + e.message, false); });
</script>
</body>
</html>

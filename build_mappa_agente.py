#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Genera una mappa_agente_<slug>.html per ciascun HM2I, con nome file
offuscato secondo TABELLA_DECODIFICA_riservata.txt (mai il codice HM2I
o il cognome nell'URL pubblico).

Da lanciare nella cartella EXPORT, dopo che corpo_export_aggregato_mese.csv
e' stato copiato in corpo.csv (stesso file usato da build_mappa.py).
Riusa la logica di build_mappa.py (stessa finestra temporale, stessa
soglia di fatturato, stessa cache di geocodifica) cosi' ogni mappa
individuale mostra esattamente il sottoinsieme dei clienti che compare
anche sulla mappa generale per quell'agente.

Se un codice HM2I presente nei dati non ha uno slug in SLUG_AGENTE
(perche' non e' un vero agente, es. il conto diretto Monacelli Italy,
o perche' manca dalla tabella), quella mappa viene saltata e segnalata
nel log invece di essere pubblicata con un nome indovinabile.
"""

import json
import sys
from pathlib import Path

EXPORT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(EXPORT_DIR))
import build_mappa  # riusa leggi_corpo, leggi_cache, calcola_clienti, CORPO_CSV, CACHE_CSV

# Da TABELLA_DECODIFICA_riservata.txt: codice HM2I -> sei caratteri del nome file.
# Se il codice cambia slug (link finito in giro), aggiorna solo qui.
SLUG_AGENTE = {
    "118": "q48sgj",
    "119": "nwu5j4",
    "120": "g41is0",
    "121": "cdaci6",
    "126": "l5hnna",
    "127": "vpae69",
    "128": "759pz1",
    "129": "pqiwn0",
    "143": "rcxfs5",
    "144": "4rcwni",
    "151": "qi133g",
    "155": "u4s3u3",
    "156": "l8b8r9",
    "907": "gc1ao2",
}

AGENTI = {
    "118": "Vettorato Andrea", "119": "Castellani Giovanni", "120": "Faccioli Andrea",
    "121": "Albino Simone", "126": "Nicodemo Oscar", "127": "Criscio Fortunato",
    "128": "Epifori Luciano", "129": "Macchiarulo Annalisa", "130": "De Sarno Lorenzo",
    "132": "La Cognata Aurelio", "143": "Galli Moris", "144": "Tesconi Diego",
    "151": "Di Pace Giancarlo", "155": "Giambuzzi Antonio",
    "156": "Aleomax SAS di Zanchetta M. & C.", "905": "Bini Leonardo",
    "907": "Falossi Alessandra", "908": "Fina Massimiliano",
    "99": "Monacelli Italy S.R.L. - Soc. Unipersonale",
}

COLORE_DEFAULT = "#469990"


def log(msg):
    print(msg, flush=True)


TEMPLATE = r"""<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>__TITLE__</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDIH-vbefLQRVUqjdyqq8jflYTGX47ikZA"></script>
<script src="https://unpkg.com/leaflet.gridlayer.googlemutant@0.14.1/dist/Leaflet.GoogleMutant.js"></script>
<style>
  :root {
    --ink: #1c2430;
    --paper: #f6f5f1;
    --line: #d9d6cc;
    --accent: #2f5d50;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    height: 100%;
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--paper);
    color: var(--ink);
  }
  body {
    display: flex;
    flex-direction: column;
  }
  header {
    flex: 0 0 auto;
    padding: 14px 24px;
    border-bottom: 1px solid var(--line);
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
  }
  header h1 {
    font-size: 19px;
    margin: 0;
    font-weight: 650;
    letter-spacing: -0.01em;
  }
  header .meta {
    font-size: 13px;
    color: #6b7280;
  }
  .layout {
    display: flex;
    align-items: stretch;
    flex: 1 1 auto;
    min-height: 0;
  }
  #map {
    flex: 1 1 auto;
    height: 100%;
    min-width: 0;
  }
  #listaClienti, #note {
    flex: 0 0 280px;
    width: 280px;
    padding: 16px 18px 16px;
    overflow-y: auto;
    background: #fff;
  }
  #listaClienti { border-right: 1px solid var(--line); }
  #note { border-left: 1px solid var(--line); display: flex; flex-direction: column; }
  #listaClienti h2, #note h2 {
    font-size: 13px;
    text-transform: none;
    font-weight: 650;
    margin: 0 0 10px;
    color: var(--ink);
  }
  .cliente-grid {
    display: flex;
    flex-direction: column;
    gap: 3px;
  }
  .cliente-item {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    font-size: 13px;
    padding: 7px 8px;
    border-radius: 6px;
    cursor: pointer;
    user-select: none;
    border: 1px solid transparent;
    transition: background 0.12s ease, border-color 0.12s ease;
  }
  .cliente-item:hover { background: rgba(0,0,0,0.04); border-color: var(--accent); }
  .cliente-item .nome {
    flex: 1 1 auto;
    min-width: 0;
    line-height: 1.25;
  }
  .cliente-item .nome .citta {
    display: block;
    color: #6b7280;
    font-size: 11.5px;
  }
  .cliente-item .val {
    flex: 0 0 auto;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    white-space: nowrap;
  }
  .list-hint {
    font-size: 12px;
    color: #8a8f98;
    margin-top: 12px;
  }
  #noteText {
    flex: 1 1 auto;
    width: 100%;
    resize: none;
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 10px 12px;
    font-family: inherit;
    font-size: 13.5px;
    color: var(--ink);
    background: var(--paper);
  }
  #noteText:focus {
    outline: 2px solid var(--accent);
    outline-offset: 1px;
  }
  .note-hint {
    font-size: 11.5px;
    color: #8a8f98;
    margin-top: 8px;
  }
  .leaflet-popup-content-wrapper {
    border-radius: 10px;
  }
  .popup-title {
    font-weight: 650;
    font-size: 13.5px;
    margin: 0 0 2px;
  }
  .popup-sub {
    font-size: 12px;
    color: #6b7280;
    margin: 0 0 8px;
  }
  .popup-total {
    font-size: 13px;
    font-weight: 650;
    margin: 6px 0 8px;
    padding-top: 6px;
    border-top: 1px solid #e5e5e5;
  }
  table.popup-lines {
    border-collapse: collapse;
    font-size: 12.5px;
    width: 100%;
  }
  table.popup-lines td {
    padding: 2px 0;
  }
  table.popup-lines td.val {
    text-align: right;
    font-variant-numeric: tabular-nums;
    color: #374151;
  }
  table.popup-lines tr td.name {
    color: #1c2430;
  }
  .bar-bg {
    background: #eee;
    border-radius: 3px;
    height: 5px;
    margin-top: 3px;
    overflow: hidden;
  }
  .bar-fill {
    height: 100%;
    border-radius: 3px;
  }
</style>
</head>
<body>

<header>
  <h1>__H1__</h1>
  <div class="meta">__META__</div>
</header>

<div class="layout">
  <aside id="listaClienti">
    <h2>Clienti &mdash; per fatturato</h2>
    <div class="cliente-grid" id="clientiGrid"></div>
    <div class="list-hint">Clicca un cliente per centrare la mappa e aprirne il dettaglio.</div>
  </aside>
  <div id="map"></div>
  <aside id="note">
    <h2>NOTE</h2>
    <textarea id="noteText" placeholder="Scrivi qui le tue note su questo agente..."></textarea>
    <div class="note-hint">Le note si salvano solo in questo browser (non condivise, non pubblicate).</div>
  </aside>
</div>

<script>
const CLIENTS = __CLIENTS_JSON__;

const COLORE = '__COLORE__';
const LINE_LABELS = {"COL": "Colorazione", "GOLD": "Gold Line", "STY": "Styling", "INP": "Inphinity", "KIT": "Kit", "SOL": "Solari", "TRI": "Trichology", "1.K": "1.K", "BRO": "Brow", "FOR": "Formazione", "GES": "Gestionale", "BEN": "Benvenuto", "ALB": "Album", "VARI": "Varie", "TEN": "Tensioattivi", "FDC": "Fondo Cassa", "CHA": "Cha", "IGIE": "Igienizzanti", "ATT": "Attrezzature", "ONC": "Onc", "CON": "Confezioni", "EMO": "Emo", "GLIC": "Glic", "EMU": "Emulsione", "OLV": "Olv", "GLI": "Gli", "CAT": "Cat", "CIO": "Cio", "PPI": "Ppi", "OLVB": "Olvb", "GOM": "Gom", "NEUT": "Neutro", "SOLV": "Solv", "FIS": "Fis", "LIP": "Lip"};

const fmtEUR = n => n.toLocaleString('it-IT', {style:'currency', currency:'EUR', maximumFractionDigits:0});
const fmtData = iso => iso ? new Date(iso + 'T00:00:00').toLocaleDateString('it-IT') : '';

// jitter dei punti che condividono la stessa città/coordinate
const groups = {};
CLIENTS.forEach(c => {
  const key = c.lat.toFixed(3) + ',' + c.lon.toFixed(3);
  (groups[key] = groups[key] || []).push(c);
});
Object.values(groups).forEach(group => {
  if (group.length === 1) return;
  const R = 0.03 + Math.min(group.length, 20) * 0.0025;
  group.forEach((c, i) => {
    const angle = (2 * Math.PI * i) / group.length;
    c._lat = c.lat + R * Math.cos(angle);
    c._lon = c.lon + R * Math.sin(angle) / Math.cos(c.lat * Math.PI/180);
  });
});
CLIENTS.forEach(c => { if (c._lat === undefined) { c._lat = c.lat; c._lon = c.lon; } });

const map = L.map('map', { scrollWheelZoom: true }).setView([42.2, 12.6], 6);
L.gridLayer.googleMutant({
  type: 'roadmap',
  maxZoom: 20
}).addTo(map);
setTimeout(() => {
  map.invalidateSize();
  if (CLIENTS.length > 0) {
    const bounds = L.latLngBounds(CLIENTS.map(c => [c._lat, c._lon]));
    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 11 });
  }
}, 0);

// cerchi configurabili (vedi HEMI.html), caricati da cerchi.json se presente
fetch('cerchi.json', { cache: 'no-store' })
  .then(r => r.ok ? r.json() : [])
  .catch(() => [])
  .then(lista => {
    if (!Array.isArray(lista)) return;
    lista.forEach(c => {
      const layer = L.circle([c.lat, c.lng], {
        radius: (c.raggio_km || 100) * 1000,
        color: c.colore || '#c0392b',
        weight: 1.5,
        fillColor: c.colore || '#c0392b',
        fillOpacity: c.opacita != null ? c.opacita : 0.15
      }).bindTooltip(`${c.nome} — raggio ${c.raggio_km || 100} km`);
      layer.addTo(map);
      layer.bringToBack();
    });
  });

const markersById = {};

function popupHTML(c) {
  const lines = Object.entries(c.linee)
    .filter(([k,v]) => v !== 0 || true)
    .sort((a,b) => b[1]-a[1]);
  const maxVal = Math.max(...lines.map(l => l[1]), 1);
  const rows = lines.map(([k,v]) => {
    const label = LINE_LABELS[k] || k;
    const pct = Math.max(2, (v / maxVal) * 100);
    return `<tr>
      <td class="name">${label}
        <div class="bar-bg"><div class="bar-fill" style="width:${pct}%; background:${COLORE}"></div></div>
      </td>
      <td class="val">${fmtEUR(v)}</td>
    </tr>`;
  }).join('');
  return `
    <div class="popup-title">${c.nome}</div>
    <div class="popup-sub">${c.citta} (${c.prov})</div>
    <table class="popup-lines">${rows}</table>
    <div class="popup-total">Totale al ${fmtData(c.ultima_data)} &nbsp; ${fmtEUR(c.totale)}</div>
  `;
}

CLIENTS.forEach(c => {
  const marker = L.circleMarker([c._lat, c._lon], {
    radius: 5.5,
    color: '#ffffff',
    weight: 1,
    fillColor: COLORE,
    fillOpacity: 0.9
  });
  marker.bindPopup(popupHTML(c), { maxWidth: 280 });
  marker.addTo(map);
  markersById[c.id] = marker;
});

// elenco clienti a sinistra, gia' ordinato per fatturato decrescente
const clientiGrid = document.getElementById('clientiGrid');
CLIENTS.forEach(c => {
  const item = document.createElement('div');
  item.className = 'cliente-item';
  item.innerHTML = `<span class="nome">${c.nome}<span class="citta">${c.citta} (${c.prov})</span></span>
    <span class="val">${fmtEUR(c.totale)}</span>`;
  item.addEventListener('click', () => {
    map.setView([c._lat, c._lon], 11);
    markersById[c.id].openPopup();
  });
  clientiGrid.appendChild(item);
});

// note personali, salvate solo nel browser locale
const noteEl = document.getElementById('noteText');
const NOTE_KEY = '__NOTE_KEY__';
try { noteEl.value = localStorage.getItem(NOTE_KEY) || ''; } catch (e) {}
noteEl.addEventListener('input', () => {
  try { localStorage.setItem(NOTE_KEY, noteEl.value); } catch (e) {}
});
</script>

</body>
</html>
"""


def genera_html(clients_agente, code, data_max):
    nome = AGENTI.get(code, f"HM2I {code}")
    slug = SLUG_AGENTE[code]
    data_str = data_max.strftime("%d/%m/%Y")

    clients_json = json.dumps(clients_agente, ensure_ascii=False, separators=(", ", ": "))

    html = TEMPLATE
    html = html.replace("__TITLE__", f"Mappa clienti — {nome}")
    html = html.replace("__H1__", f"Clienti {nome} — da inizio anno")
    html = html.replace(
        "__META__",
        f"{len(clients_agente)} clienti &middot; dati aggiornati al {data_str} &middot; "
        "clicca un cliente in elenco o un punto sulla mappa per il dettaglio fatturato"
    )
    html = html.replace("__CLIENTS_JSON__", clients_json)
    html = html.replace("__COLORE__", COLORE_DEFAULT)
    html = html.replace("__NOTE_KEY__", f"note_mappa_agente_{slug}")

    return html


def main():
    if not build_mappa.CORPO_CSV.exists():
        raise SystemExit(f"ERRORE: {build_mappa.CORPO_CSV} non trovato")

    righe = build_mappa.leggi_corpo(build_mappa.CORPO_CSV)
    cache = build_mappa.leggi_cache(build_mappa.CACHE_CSV)
    if not cache:
        log(f"ATTENZIONE: cache coordinate {build_mappa.CACHE_CSV} vuota o assente")

    clients, data_max = build_mappa.calcola_clienti(righe, cache)

    per_agente = {}
    for c in clients:
        per_agente.setdefault(c["hm2i"], []).append(c)
    for code in per_agente:
        per_agente[code].sort(key=lambda c: -c["totale"])

    generati, saltati = 0, []
    for code, clients_agente in sorted(per_agente.items()):
        if code not in SLUG_AGENTE:
            saltati.append((code, len(clients_agente)))
            continue
        html = genera_html(clients_agente, code, data_max)
        out_path = EXPORT_DIR / f"mappa_agente_{SLUG_AGENTE[code]}.html"
        out_path.write_text(html, encoding="utf-8")
        log(f"OK: {out_path.name} ({len(clients_agente)} clienti, {AGENTI.get(code, code)})")
        generati += 1

    if saltati:
        log("")
        log(f"ATTENZIONE: {len(saltati)} codice/i HM2I senza slug in SLUG_AGENTE, mappa NON generata:")
        for code, n in saltati:
            log(f"  - HM2I {code} ({AGENTI.get(code, 'sconosciuto')}, {n} clienti)")
        log("Se e' un vero agente, aggiungi il suo slug in SLUG_AGENTE nello script e rilancia.")
        log("")

    log(f"Totale: {generati} mappe individuali generate su {len(per_agente)} codici HM2I con clienti attivi")


if __name__ == "__main__":
    try:
        main()
    except SystemExit as e:
        log(str(e))
        sys.exit(1)

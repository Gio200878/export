# -*- coding: utf-8 -*-
"""
Magis Plus — Progetto di Sviluppo
==================================
Legge "SITUAZIONE PSD 2026.xlsx" (fogli "psd", "parametri (24)", "parametri (25)")
e, se presente, "corpo_export_aggregato_mese.csv" nella stessa cartella dello script.
Chiede il codice cliente, chiede l'obiettivo stelle (F e M), calcola presenze ideali,
percentuali di servizio e fiche media secondo le "istruzioni_calcolo_PSD2026.md",
e scrive un report HTML nella cartella "output", aprendolo nel browser.

REQUISITI (una tantum):
    pip install openpyxl

CONFIGURAZIONE: metti questo script, "SITUAZIONE PSD 2026.xlsx" e (facoltativo)
"corpo_export_aggregato_mese.csv" nella STESSA cartella, poi lancia il file .bat
allegato (o "python magis_plus.py").
"""
import csv
import datetime as dt
import glob
import json
import os
import re
import sys
import webbrowser

import magis_plus_teoria as teoria
import magis_plus_fonts as fonts

try:
    import openpyxl
except ImportError:
    sys.exit("Serve il pacchetto 'openpyxl'. Installalo con:  pip install openpyxl")

HERE = os.path.dirname(os.path.abspath(__file__))
XLSX_PATH = os.path.join(HERE, "SITUAZIONE PSD 2026.xlsx")
CSV_PATH = os.path.join(HERE, "corpo_export_aggregato_mese.csv")
OUT_DIR = os.path.join(HERE, "output")

# Pagine FISSE prese tali e quali dal modello "Magis_plus2026_modello.pdf" (file estratto con le sole
# pagine 2-7, 11, 13, 14, 19, 20 e 36 del modello). Vengono inserite nel PDF finale senza rielaborarle,
# cosi' risultano identiche al modello.
MODELLO_PATH = os.path.join(HERE, "magis_plus_pagine_modello.pdf")
MODELLO_PAGINE = [2, 3, 4, 5, 6, 7, 11, 13, 14, 19, 20, 36]  # numero pagina nel modello originale

LEVELS = [4, 5, 6, 7, 8, 9]  # colonne delle tabelle "GIUSTI PARAMETRI" / "MEDIA PASSAGGI"


# --------------------------------------------------------------------------- util
def col_letter_to_index(letter):
    """'A'->0, 'B'->1, ... 'AA'->26 ..."""
    n = 0
    for c in letter:
        n = n * 26 + (ord(c.upper()) - 64)
    return n - 1


def cell(ws, row, letter):
    return ws.cell(row=row, column=col_letter_to_index(letter) + 1).value


def as_pct(v):
    """'85%' o 0.85 o 85 -> 0.85"""
    if v is None:
        return None
    if isinstance(v, str):
        v = v.strip().replace(",", ".")
        if v.endswith("%"):
            return float(v[:-1]) / 100
        if v in ("", "-"):
            return None
        v = float(v)
    else:
        v = float(v)
    return v / 100 if v > 1.5 else v


def as_num(v, default=None):
    if v is None or v == "" or v == "-":
        return default
    if isinstance(v, str):
        v = v.strip().replace("€", "").replace(",", ".").strip()
        if v in ("", "-"):
            return default
    try:
        return float(v)
    except ValueError:
        return default


def n0(x):
    return f"{x:,.0f}".replace(",", ".")


def n1(x):
    return f"{x:,.1f}".replace(",", "X").replace(".", ",").replace("X", ".")


def n2(x):
    return f"{x:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def eur(x):
    return "€ " + n2(x) if x is not None else "—"


# --------------------------------------------------------------------- lettura psd
def trova_riga_cliente(ws, codice):
    """Cerca 'codice' nella colonna C (Codice new) o D (cod. old). Ritorna il numero di riga."""
    codice = str(codice).strip()
    for r in range(1, ws.max_row + 1):
        c_new = cell(ws, r, "C")
        c_old = cell(ws, r, "D")
        if c_new is not None and str(c_new).strip() == codice:
            return r
        if c_old is not None and str(c_old).strip() == codice:
            return r
    return None


def leggi_cliente(ws, riga):
    return dict(
        salone=cell(ws, riga, "F"),
        titolare=cell(ws, riga, "G"),
        hm2i=cell(ws, riga, "E"),
        mesi=as_num(cell(ws, riga, "O")),
        gg_lav=as_num(cell(ws, riga, "AF")),
        pas_f=as_num(cell(ws, riga, "BC")),
        pas_m=as_num(cell(ws, riga, "CE")),
        op_f=as_num(cell(ws, riga, "BP")),
        op_m=as_num(cell(ws, riga, "CD")),
        fiche_att_f=as_num(cell(ws, riga, "CG")),
        fiche_att_m=as_num(cell(ws, riga, "CI")),
        att_f=dict(
            Cleansing=as_pct(cell(ws, riga, "BH")), Colore=as_pct(cell(ws, riga, "BL")),
            GOLD20=as_pct(cell(ws, riga, "BD")), Trattamenti=as_pct(cell(ws, riga, "BG")),
            Taglio=as_pct(cell(ws, riga, "BO")), Mantenimento=as_pct(cell(ws, riga, "BI")),
        ),
        att_m=dict(
            Cleansing=as_pct(cell(ws, riga, "BU")), Colore=as_pct(cell(ws, riga, "BY")),
            GOLD20=as_pct(cell(ws, riga, "BQ")), Trattamenti=as_pct(cell(ws, riga, "BT")),
            Taglio=as_pct(cell(ws, riga, "CB")), Mantenimento=as_pct(cell(ws, riga, "BV")),
            Barba=as_pct(cell(ws, riga, "CC")),
        ),
        tar_sal_f=dict(
            Cleansing=as_num(cell(ws, riga, "CW")), Pieghe=as_num(cell(ws, riga, "CP")),
            Taglio=as_num(cell(ws, riga, "CQ")), Colore=as_num(cell(ws, riga, "CS")),
            GOLD20=as_num(cell(ws, riga, "CV")), Trattamenti=as_num(cell(ws, riga, "CU")),
            Mantenimento=as_num(cell(ws, riga, "CR")),
        ),
    )


# ---------------------------------------------------------------- lettura parametri
def leggi_par(ws):
    """Legge una tabella 'parametri (24)' o '(25)': ogni riga di interesse ha
    un'etichetta in una cella e 6 valori nelle 6 celle successive (o dopo qualche
    cella vuota), nell'ordine delle stelle 4,5,6,7,8,9."""
    def trova(label):
        for row in ws.iter_rows():
            for c in row:
                if isinstance(c.value, str) and " ".join(c.value.split()).upper() == " ".join(label.split()).upper():
                    r, col0 = c.row, c.column
                    vals = []
                    col = col0 + 1
                    while len(vals) < 6 and col <= ws.max_column:
                        v = ws.cell(row=r, column=col).value
                        if v not in (None, ""):
                            vals.append(v)
                        col += 1
                    return vals
        return None

    def trova_tariffa_minmax(nome_riga):
        for row in ws.iter_rows():
            for c in row:
                if isinstance(c.value, str) and nome_riga.upper() in c.value.strip().upper():
                    r = c.row
                    vals = [ws.cell(row=r, column=cc).value for cc in range(c.column + 1, ws.max_column + 1)]
                    vals = [as_num(v) for v in vals if v not in (None, "")]
                    return vals[:2] if len(vals) >= 2 else (vals + [None, None])[:2]
        return [None, None]

    media = [as_pct(v) * 100 if isinstance(v, str) and "%" in v else as_num(v) for v in (trova("MEDIA PASSAGGI GIORNALIERI FEMMINILE") or trova("MEDIA PASSAGGI GIORNALIERI MASCHILE") or [])]
    return dict(
        media=media,
        Cleansing=[as_pct(v) * 100 for v in (trova("CLEANSING") or [])],
        Colore=[as_pct(v) * 100 for v in (trova("COLORE") or [])],
        Gold=[as_pct(v) * 100 for v in (trova("GOLD su Colore") or [])],
        Mac=[as_pct(v) * 100 for v in (trova("MAC su Colore") or [])],
        Taglio=[as_pct(v) * 100 for v in (trova("TAGLIO") or [])],
        Trattamenti=[as_pct(v) * 100 for v in (trova("TRATTAMENTI") or [])],
        Styling=[as_pct(v) * 100 for v in (trova("STYLING") or [])] or None,
        Barba=[as_pct(v) * 100 for v in (trova("BARBA") or [])] or None,
        tar_min=trova_tariffa_minmax("CLEANSING"),  # placeholder: vedi NOTE sotto
    )


# ---- Tabella "TARIFFE GIUSTE" (MIN/MAX), righe fisse per nome servizio ----
def leggi_tariffe_giuste(ws):
    righe = ["CLEANSING", "PIEGHE", "TAGLIO", "COLORE", "MECHES", "PERMANENTE",
             "GOLD 20", "TRATTAMENTI", "PRODOTTI"]
    out = {}
    for row in ws.iter_rows():
        for c in row:
            if isinstance(c.value, str):
                lab = c.value.strip().upper()
                for r in righe:
                    if lab == r:
                        vals = [ws.cell(row=c.row, column=cc).value for cc in range(c.column + 1, c.column + 4)]
                        vals = [as_num(v) for v in vals]
                        out[r] = dict(MIN=vals[0], MAX=vals[1] if len(vals) > 1 else None)
    return out


# --------------------------------------------------------------------- Styling CSV
def leggi_styling(codice, mesi):
    if not os.path.exists(CSV_PATH) or not mesi:
        return None
    tot = 0.0
    trovato = False
    with open(CSV_PATH, newline="", encoding="utf-8", errors="replace") as f:
        for row in csv.reader(f, delimiter=";"):
            if len(row) < 6 or row[0] == "Anno":
                continue
            try:
                anno, mese, cod, fam = row[0], int(row[1]), row[2].strip(), row[4].strip()
            except (ValueError, IndexError):
                continue
            if cod == str(codice).strip() and fam.upper() == "STY" and 1 <= mese <= int(mesi):
                tot += as_num(row[5], 0)
                trovato = True
    return tot if trovato else None


# --------------------------------------------------------------------------- calc
IND_9 = LEVELS.index(9)
IND_8 = LEVELS.index(8)


def calcola(genere, op, pas, gg, mesi, att, tar_sal, par, tariffe_giuste, stelle_target, sty_raw=None):
    """Ricalcola presenze/servizi/fiche ideali al livello di stelle richiesto,
    con la tabella dei parametri ufficiali (colonne 4..9 stelle).
    NB: 9 stelle usa, per costruzione, gli stessi valori di 8 stelle (vedi erratum
    nelle istruzioni di calcolo: la colonna dedicata "9 stelle" nel foglio
    coincide con "9 stelle plus", non con "9 stelle")."""
    i = LEVELS.index(stelle_target) if stelle_target in LEVELS else IND_9
    if stelle_target == 9:
        i = IND_8  # applica la correzione: 9 stelle = valori della colonna 8 stelle
    ide = op * gg * par["media"][i]
    eff = ide * 0.9 if genere == "F" else ide
    colore_i = eff * par["Colore"][i] / 100
    cleansing_i = ide * par["Cleansing"][i] / 100
    taglio_i = eff * par["Taglio"][i] / 100
    gold_i = colore_i * par["Gold"][i] / 100
    mac_i = colore_i * par["Mac"][i] / 100
    tratt_i = ide * par["Trattamenti"][i] / 100

    righe = [
        ("Cleansing", att.get("Cleansing"), cleansing_i, "presenze ideali"),
        ("Taglio", att.get("Taglio"), taglio_i, "presenze effettive" if genere == "F" else "presenze ideali"),
        ("Colore", att.get("Colore"), colore_i, "presenze effettive" if genere == "F" else "presenze ideali"),
        ("GOLD20", att.get("GOLD20"), gold_i, "su Colore ideale"),
        ("Trattamenti", att.get("Trattamenti"), tratt_i, "presenze ideali"),
    ]
    if genere == "F":
        sty_i = ide * (par["Styling"][i] if par["Styling"] else 0) / 100
        righe.append(("Styling", None, sty_i, "presenze ideali"))
        sty_att_tot = sty_raw
    else:
        barba_i = ide * (par["Barba"][i] if par["Barba"] else 0) / 100
        righe.append(("Barba", att.get("Barba"), barba_i, "presenze ideali"))
        sty_att_tot = None
    righe.append(("Mantenimento (PROD)", att.get("Mantenimento"), mac_i, "su Colore ideale"))

    # FICHES
    fic = []
    if genere == "F":
        col_tg = "MAX" if stelle_target >= 9 else "MIN"
        mappa = {"Cleansing": "CLEANSING", "Taglio": "TAGLIO", "Colore": "COLORE",
                 "GOLD20": "GOLD 20", "Trattamenti": "TRATTAMENTI", "Mantenimento": "PRODOTTI"}
        vals = {"Cleansing": cleansing_i, "Taglio": taglio_i, "Colore": colore_i,
                "GOLD20": gold_i, "Trattamenti": tratt_i, "Mantenimento": mac_i}
        for nm, tot in vals.items():
            giusta = (tariffe_giuste.get(mappa[nm], {}) or {}).get(col_tg)
            salone = tar_sal.get(nm)
            usata = giusta if salone is None else (max(salone, giusta) if giusta is not None else salone)
            fic.append((nm, tot, salone, giusta, usata, tot * (usata or 0)))
        giusta_p = (tariffe_giuste.get("PIEGHE", {}) or {}).get(col_tg)
        salone_p = tar_sal.get("Pieghe")
        usata_p = giusta_p if salone_p is None else (max(salone_p, giusta_p) if giusta_p is not None else salone_p)
        fic.append(("Pieghe", ide, salone_p, giusta_p, usata_p, ide * (usata_p or 0)))
    else:
        default_m = dict(Cleansing=5, Taglio=25, Colore=25, GOLD20=40, Trattamenti=15, Mantenimento=25, Barba=25)
        vals = {"Cleansing": cleansing_i, "Taglio": taglio_i, "Colore": colore_i, "GOLD20": gold_i,
                "Trattamenti": tratt_i, "Barba": barba_i, "Mantenimento": mac_i}
        for nm, tot in vals.items():
            u = default_m[nm]
            fic.append((nm, tot, u, None, u, tot * u))
    tot_fic = sum(f[5] for f in fic)

    return dict(genere=genere, ide=ide, eff=eff, op=op, pas=pas, gg=gg, mesi=mesi,
                stelle=stelle_target, righe=righe, fic=fic, fic_tot=tot_fic,
                fic_ide=(tot_fic / ide if ide else None), sty_att_tot=sty_att_tot)


# --------------------------------------------------------------- moduli raccolta dati (facoltativi)
def trova_json_modulo(codice, tipo):
    """Cerca 'Magis Plus Raccolta Dati - <nome> (<codice>) - <tipo>.json' nella cartella dello
    script E in tutte le sue sottocartelle (es. una sottocartella "Magis Plus" dove hai scaricato
    i file da Drive)."""
    pattern = f"*({codice})*{tipo}*.json"
    # 1) ricerca diretta nella cartella dello script (più veloce, caso più comune)
    trovati = glob.glob(os.path.join(HERE, pattern))
    # 2) se non trovato, ricerca in ogni sottocartella, a qualunque livello
    if not trovati:
        trovati = glob.glob(os.path.join(HERE, "**", pattern), recursive=True)
    if not trovati:
        return None
    # se ci sono più corrispondenze, prende la più recente
    trovati.sort(key=os.path.getmtime, reverse=True)
    with open(trovati[0], encoding="utf-8") as f:
        return json.load(f)


def pagina_sogno(dati_salone):
    sogno = (dati_salone or {}).get("sogno", "").strip()
    if not sogno:
        return None
    return f"""<h2>Il mio <b>sogno</b></h2>
      <p class="lede">Raccontato dal salone.</p>
      <blockquote style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:12px;
      padding:6mm 7mm;margin:5mm 0;font-size:11pt;position:relative">{teoria.STAR_SVG}<p style="margin:0">{sogno}</p></blockquote>"""


def pagina_immagine(dati_salone):
    if not dati_salone:
        return None
    imm = dati_salone.get("immagine", {}) or {}
    righe = "".join(f'<div style="display:flex;justify-content:space-between;padding:2mm 0;'
                     f'border-bottom:1px solid rgba(255,255,255,.1)"><span>{k}</span><b>{v if v is not None else "—"}</b></div>'
                     for k, v in imm.items())
    pos = dati_salone.get("postazioni", {}) or {}
    pos_html = " · ".join(f"{k.capitalize()}: {v}" for k, v in pos.items() if v)
    extra = ""
    for campo, etichetta in [("materiale_salone", "Materiale nel salone"), ("materiale_vetrine", "Materiale vetrine"),
                             ("presenza_social", "Presenza social"), ("brand", "Brand"),
                             ("proposte_sviluppo", "Proposta di sviluppo")]:
        v = (dati_salone.get(campo) or "").strip()
        if v:
            extra += f'<p style="margin:3mm 0"><b>{etichetta}.</b> {v}</p>'
    team = dati_salone.get("numero_team")
    return f"""<h2>Immagine <b>interna ed esterna</b></h2>
      <p class="lede">{f"Team di {team} persone. " if team else ""}{pos_html}</p>
      <h3>Immagine del salone</h3>{righe}
      {extra}"""


SCORE_GROUPS = {
    "acc": ["Saluto", "Controllo appuntamento in agenda", "Routine accoglienza", "Comunicazione iniziative e promozioni"],
    "cons": ["Connessione", "Ascolto", "Capacità di capire i desideri", "Capacità di consigliare", "Condivisione"],
    "cong": ["Verifica soddisfazione", "Propone/riconferma prodotti", "Quantifica ed elenca servizi",
             "Fissa prossimo appuntamento", "Prende abbigliamento"],
    "img": ["Look coerente", "Capelli", "Trucco/barba", "Divisa", "Eleganza e postura", "Cura e pulizia spazi"],
}
TEC_SERVIZI = ["Lavaggio", "Colore", "Taglio", "Piega"]


def _media(vals):
    v = [x for x in vals if x is not None]
    return sum(v) / len(v) if v else None


def _bars(punteggi, chiavi):
    out = ""
    for k in chiavi:
        v = punteggi.get(k)
        if v is None:
            out += f'<div style="display:flex;justify-content:space-between;padding:1mm 0;font-size:9pt"><span>{k}</span><span>—</span></div>'
        else:
            out += (f'<div style="display:flex;align-items:center;gap:3mm;padding:1mm 0;font-size:9pt">'
                     f'<span style="flex:1">{k}</span><span style="flex:0 0 40mm;height:2.2mm;background:rgba(255,255,255,.12);'
                     f'border-radius:99px;overflow:hidden"><i style="display:block;height:100%;width:{v*10}%;'
                     f'background:{"#FF7C99" if v==0 else "#A08BFF"};border-radius:99px"></i></span>'
                     f'<b style="width:6mm;text-align:right">{v}</b></div>')
    return out


def pagina_team_mappa(collab):
    righe = ""
    for i, c in enumerate(collab):
        p = c.get("punteggi", {})
        tec = _media([p.get(f"{s}_{k}") for s in TEC_SERVIZI for k in ("Tempi", "Metodo", "Risultato")])
        vals = [("Accoglienza", _media([p.get(k) for k in SCORE_GROUPS["acc"]])),
                ("Consulenza", _media([p.get(k) for k in SCORE_GROUPS["cons"]])),
                ("Congedo", _media([p.get(k) for k in SCORE_GROUPS["cong"]])),
                ("Tecnica", tec),
                ("Immagine", _media([p.get(k) for k in SCORE_GROUPS["img"]]))]
        celle = "".join(f'<td style="text-align:center;padding:2mm">{v:.1f}' if v is not None else '<td style="text-align:center">—'
                         for _, v in vals)
        righe += (f'<tr style="border-bottom:1px solid rgba(255,255,255,.1)"><td style="padding:2mm"><b>{c.get("NOME_OPERATORE","")}</b>'
                  f'<br><span style="font-size:8pt;color:#A9ADCF">{c.get("RUOLO","")}</span></td>{celle}</tr>')
    head = "".join(f'<th style="font-size:8.5pt;color:#A9ADCF;text-align:center;padding:2mm">{h}</th>' for h in
                    ["Accoglienza", "Consulenza", "Congedo", "Tecnica", "Immagine"])
    return f"""<h2>Analisi <b>collaboratori</b></h2>
      <p class="lede">Media per area, da 0 a 10.</p>
      <table style="width:100%;border-collapse:collapse;font-size:9pt"><thead><tr><th></th>{head}</tr></thead>
      <tbody>{righe}</tbody></table>"""


def pagina_persona(c, idx, tot, ancora_prec, ancora_succ):
    p = c.get("punteggi", {})
    tec_rows = ""
    for s in TEC_SERVIZI:
        cells = "".join(f'<td style="text-align:center;padding:1.5mm">{p.get(f"{s}_{k}", "—") if p.get(f"{s}_{k}") is not None else "—"}</td>'
                         for k in ("Tempi", "Metodo", "Risultato"))
        tec_rows += f'<tr><td style="padding:1.5mm">{s}</td>{cells}</tr>'
    nav = ""
    if ancora_prec:
        nav += f'<a href="#{ancora_prec}" style="margin-right:4mm">← precedente</a>'
    if ancora_succ:
        nav += f'<a href="#{ancora_succ}">successivo →</a>'
    return f"""<h2>{c.get("NOME_OPERATORE","")}</h2>
      <p class="lede">{c.get("RUOLO","")} · {", ".join(c.get("CLIENTELA", []) or [])}</p>
      <h3>Accoglienza</h3>{_bars(p, SCORE_GROUPS["acc"])}
      <h3>Consulenza</h3>{_bars(p, SCORE_GROUPS["cons"])}
      <h3>Professionalità tecnica</h3>
      <table style="width:100%;border-collapse:collapse;font-size:9pt"><thead><tr><th></th><th>Tempi</th><th>Metodo</th><th>Risultato</th></tr></thead>
      <tbody>{tec_rows}</tbody></table>
      <h3>Congedo</h3>{_bars(p, SCORE_GROUPS["cong"])}
      <h3>Immagine personale</h3>{_bars(p, SCORE_GROUPS["img"])}
      <div class="callout"><b>Proposta formativa.</b> {c.get("proposte_formative") or "da completare."}</div>
      <p class="lede" style="margin-top:5mm">{nav}</p>"""


FONTFACE_CSS = (
    '@font-face { font-family:"BSD"; src:url(data:font/ttf;base64,' + fonts.BSD_B64 + '); font-weight:100 900; }\n'
    '@font-face { font-family:"Fig"; src:url(data:font/ttf;base64,' + fonts.FIG_B64 + '); font-weight:300 900; }\n'
    '.page.cover-bg{background-image:url(data:image/jpeg;base64,' + fonts.COVER_B64 + ');background-size:cover;background-position:center}\n'
    '.page.back-bg{background-image:url(data:image/jpeg;base64,' + fonts.BACK_B64 + ');background-size:cover;background-position:center}\n'
)

CSS = FONTFACE_CSS + """
@page { size: A4; margin: 0; }
*{box-sizing:border-box}
body{margin:0;background:#0F1330;color:#EEEDF8;font-family:"Fig",Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.page{width:210mm;min-height:297mm;position:relative;page-break-after:always;background:linear-gradient(165deg,#141A48 0%,#1B2360 55%,#222C6E 100%)}
.page:last-child{page-break-after:auto}
.in{padding:16mm 15mm 20mm}
h1{font-family:"BSD",sans-serif;font-size:38pt;font-weight:300;margin:0 0 4mm;letter-spacing:.01em}
h1 b{color:#E8C766;font-weight:800}
h2{font-family:"BSD",sans-serif;font-size:26pt;font-weight:300;margin:0 0 3mm}
h2 b{color:#E8C766;font-weight:800}
h3{font-size:12pt;font-weight:700;margin:6mm 0 2mm;border-top:1px solid rgba(255,255,255,.15);padding-top:4mm}
p.lede{color:#A9ADCF;font-size:10pt;margin:0 0 5mm;max-width:170mm}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:rgba(255,255,255,.15);border-radius:10px;overflow:hidden;margin:3mm 0}
.kpis div{background:#1A2158;padding:4mm}
.kpis b{display:block;font-size:16pt;font-weight:800}
.kpis span{font-size:8pt;color:#A9ADCF}
table{border-collapse:collapse;width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:10px;overflow:hidden;font-size:8.6pt}
th,td{padding:2.4mm 2mm;text-align:right;border-bottom:1px solid rgba(255,255,255,.1)}
th:first-child,td:first-child{text-align:left;font-weight:600}
th{background:rgba(255,255,255,.06);color:#A9ADCF;font-weight:600;font-size:7.8pt}
.tg{background:rgba(232,199,102,.16)}
th.tg{color:#E8C766}
tr.tot td{font-weight:700;border-bottom:0}
.fiche{color:#E8C766}
.callout{margin-top:5mm;padding:4mm 5mm;border-left:3px solid #E8C766;background:rgba(255,255,255,.06);border-radius:0 8px 8px 0;font-size:9pt}
.pillars{display:grid;grid-template-columns:repeat(3,1fr);gap:5mm;margin-top:6mm}
.pillars.four{grid-template-columns:repeat(4,1fr)}
.pillars div{border-top:2px solid #E8C766;padding-top:3mm;font-size:9pt}
.pillars b{display:block;font-size:12pt;margin-bottom:1mm}
.nav{position:absolute;left:0;right:0;bottom:0;height:12mm;display:flex;align-items:center;gap:3mm;padding:0 15mm;border-top:1px solid rgba(255,255,255,.15);font-size:8pt}
.nav a{color:#A9ADCF;text-decoration:none}
.nav a:first-child{color:#E8C766;font-weight:700}
.nav .pg{margin-left:auto;color:#E8C766;font-weight:700;font-size:11pt}
.page.cover-bg .in{padding:0}
.page.back-bg .in{padding:0}
.cover{height:257mm;display:flex;flex-direction:column;justify-content:flex-end;padding:0 15mm 16mm}
.cover .kick{color:#EDEBFA;font-size:9pt;margin-bottom:2mm;text-shadow:0 1px 6px rgba(0,0,0,.7)}
.cover .big{font-family:"BSD",sans-serif;font-size:34pt;font-weight:800;line-height:.95;color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.7)}
.cover .big i{display:block;color:#E8C766;font-style:normal}
.cover .sub{font-size:9pt;color:#EDEBFA;margin-top:2mm;text-shadow:0 1px 6px rgba(0,0,0,.7)}
"""

NAV = [("svc-F", "Femminile"), ("svc-M", "Maschile")]


def pg(body, pid, title, nav=True, extra_cls=""):
    navhtml = ""
    if nav:
        navhtml = '<div class="nav">' + "".join(f'<a href="#{i}">{t}</a>' for i, t in NAV) + f'<span class="pg">{title}</span></div>'
    cls = ("page " + extra_cls).strip()
    return f'<section class="{cls}" id="{pid}"><div class="in">{body}</div>{navhtml}</section>'


def tabella_servizi(d):
    head = "<tr><th>Servizio</th><th>Attuale %</th><th>Attuale tot.</th><th class='tg'>Ideale %</th><th class='tg'>Ideale tot.</th><th>Base</th></tr>"
    rows = ""
    for nm, att_pct, tot_ide, base in d["righe"]:
        att_s = f"{att_pct*100:.0f}%" if att_pct is not None else "—"
        att_tot = d["sty_att_tot"] if nm == "Styling" and d["sty_att_tot"] else (att_pct * d["pas"] if att_pct is not None else None)
        rows += (f"<tr><td>{nm}</td><td>{att_s}</td><td>{n0(att_tot) if att_tot is not None else '—'}</td>"
                 f"<td class='tg'></td><td class='tg'>{n0(tot_ide)}</td><td>{base}</td></tr>")
    return f"<table><thead>{head}</thead><tbody>{rows}</tbody></table>"


def tabella_fiche(d):
    rows = "".join(f"<tr><td>{nm}</td><td>{n0(tot)}</td><td>{eur(u)}</td><td>{eur(c)}</td></tr>" for nm, tot, s, g, u, c in d["fic"])
    rows += f"<tr class='tot'><td colspan='3'>÷ presenze ideali ({n1(d['ide'])}) = fiche ideale</td><td class='fiche'>{eur(d['fic_ide'])}</td></tr>"
    return f"<table><thead><tr><th>Servizio</th><th>Totale ideale</th><th>Tariffa usata</th><th>Contributo</th></tr></thead><tbody>{rows}</tbody></table>"


def pagina_sviluppo(nome, gen, d):
    return pg(f"""
      <h2>Sviluppo <b>{nome}</b></h2>
      <p class="lede">Obiettivo {d['stelle']} stelle · {d['mesi']:.0f} mesi rilevati · {d['gg']:.0f} giorni lavorativi.</p>
      <div class="kpis">
        <div><b>{n0(d['pas'])}</b><span>Passaggi nel periodo</span></div>
        <div><b>{n1(d['op'])}</b><span>Operatori</span></div>
        <div><b>{n0(d['ide'])}</b><span>Presenze ideali</span></div>
        <div><b class="fiche">{eur(d['fic_ide'])}</b><span>Fiche media ideale (attuale {eur(d.get('fiche_att'))})</span></div>
      </div>
      <h3>Servizi: attuale e ideale</h3>
      {tabella_servizi(d)}
      <h3>Fiche media ideale</h3>
      {tabella_fiche(d)}
    """, f"svc-{gen}", nome)


def pagina_stelle(nome, gen, righe_livelli, fic_livelli, pot_livelli, stelle_target):
    head = "".join(f"<th class='{'tg' if s==stelle_target else ''}'>{s}</th>" for s in LEVELS)
    nomi = ["Cleansing", "Colore", "Gold su colore", "Mac su colore", "Taglio", "Trattamenti"]
    body = ""
    for i, chiave in enumerate(["Cleansing", "Colore", "Gold", "Mac", "Taglio", "Trattamenti"]):
        cells = "".join(f"<td class='{'tg' if LEVELS[j]==stelle_target else ''}'>{righe_livelli[chiave][j]:.0f}%</td>" for j in range(6))
        body += f"<tr><td>{nomi[i]}</td>{cells}</tr>"
    pot_cells = "".join(f"<td class='{'tg' if LEVELS[j]==stelle_target else ''}'>{n0(pot_livelli[j])}</td>" for j in range(6))
    fic_cells = "".join(f"<td class='{'tg' if LEVELS[j]==stelle_target else ''}'>{eur(fic_livelli[j])}</td>" for j in range(6))
    return pg(f"""
      <h2>{nome}: <b>cammino verso le stelle</b></h2>
      <p class="lede">Confronto fra i livelli di stelle 4→9. La colonna evidenziata è l'obiettivo scelto.</p>
      <table><thead><tr><th></th>{head}</tr></thead><tbody>{body}
      <tr class="tot"><td>Potenziale</td>{pot_cells}</tr>
      <tr class="tot"><td>Fiche media</td>{fic_cells}</tr></tbody></table>
    """, f"stelle-{gen}", f"{nome}: stelle")


def costruisci_pagine(cli, codice, dF, dM, par_f, par_m, tar_sal_f, tar_giuste, op_f, pas_f, gg, mesi, sty_raw,
                      op_m, pas_m, att_f, att_m):
    # moduli di raccolta dati facoltativi (solo se presenti nella cartella)
    mod_salone = trova_json_modulo(codice, "Immagine e Sogno")
    mod_collab = trova_json_modulo(codice, "Analisi Collaboratori")
    collab = (mod_collab or {}).get("collaboratori", [])

    html_sogno = pagina_sogno(mod_salone)
    html_immagine = pagina_immagine(mod_salone)

    # indice dinamico: solo le voci realmente presenti in questo documento
    voci_indice = [("cosa", "Cos\'e\' Magis Plus")]
    if html_sogno:
        voci_indice.append(("sogno", "Il mio sogno"))
    if html_immagine:
        voci_indice.append(("immagine", "Immagine interna ed esterna"))
    if dF or dM:
        voci_indice.append(("sviluppo", "Progetto di sviluppo personalizzato"))
    if collab:
        voci_indice.append(("team", "Analisi team"))
    voci_indice.append(("academy", "Formazione anno corrente e successivo"))

    # barra di navigazione in fondo pagina: stesse voci principali, sempre coerente
    global NAV
    NAV = [("cosa", "Cos\'e\' Magis Plus")]
    if html_sogno:
        NAV.append(("sogno", "Il mio sogno"))
    if html_immagine:
        NAV.append(("immagine", "Immagine"))
    if dF or dM:
        NAV.append(("sviluppo", "Sviluppo"))
    if collab:
        NAV.append(("team", "Collaboratori"))
    NAV.append(("academy", "Academy"))

    # Ogni elemento e' ("H", html, titolo) per una pagina generata, oppure
    # ("M", [pagine del modello], html_di_riserva, titolo) per pagine identiche al modello.
    pagine = []

    def add(html, titolo=None):
        pagine.append(("H", html, titolo))

    def add_modello(nums, fallback_html, titolo=None):
        pagine.append(("M", nums, fallback_html, titolo))

    add(pg(f'''<div class="cover"><div class="kick">Monacelli Quality Salon, Progetto di Sviluppo su Misura</div>
      <div class="big">{(cli['salone'] or codice)}</div><p class="sub">Magis Plus</p></div>''', "cover", "Copertina", nav=False, extra_cls="cover-bg"),
        "Copertina")
    # pagine 2-7 del modello: citazione, introduzione, lettera del CEO, indice, cos'e' Magis Plus,
    # copertina "IL MIO SOGNO"
    add_modello([2], f'<section class="page" id="quote">{teoria.pagina_quote()}</section>', "Citazione")
    add_modello([3], pg(teoria.pagina_introduzione(), "intro", "Introduzione"), "Introduzione")
    add_modello([4], pg(teoria.pagina_lettera_ceo(), "ceo", "Lettera del CEO"), "Lettera del CEO")
    add_modello([5], pg(teoria.pagina_indice(voci_indice), "indice", "Indice"), "Indice")
    add_modello([6], pg(teoria.pagina_cosepla(), "cosa", "Cos\'e\' Magis Plus"), "Cos\'e\' Magis Plus")
    add_modello([7], pg(f'<h1>Il mio <b>sogno</b></h1>', "sogno-cover", "Il mio sogno", nav=False), "Il mio sogno")

    if html_sogno:
        add(pg(html_sogno, "sogno", "Il mio sogno"), "Il mio sogno (salone)")
    if html_immagine:
        # copertina verde "IMMAGINE INTERNA ED ESTERNA" (pagina 11 del modello)
        add_modello([11], pg('<h1>Immagine <b>interna ed esterna</b></h1>', "immagine-cover", "Immagine", nav=False),
                    "Immagine interna ed esterna")
        add(pg(html_immagine, "immagine", "Immagine"), "Immagine del salone")

    if dF or dM:
        # copertina "PROGETTO DI SVILUPPO" + pagina di testo (pagine 13 e 14 del modello)
        add_modello([13], pg('<h1>Progetto <b>di sviluppo</b></h1>', "sviluppo-cover", "Sviluppo", nav=False),
                    "Progetto di sviluppo")
        add_modello([14], pg(f'''<h2>Progetto <b>di sviluppo</b></h2>
          <p class="lede">Situazione attuale e ipotesi di sviluppo del salone, calcolate sui dati PSD.</p>''',
                          "sviluppo", "Sviluppo"), "Progetto di sviluppo: introduzione")

    if dF:
        add(pagina_sviluppo("Femminile", "F", dict(dF, fiche_att=cli["fiche_att_f"])), "Sviluppo Femminile")
        livelli = {k: [] for k in ["Cleansing", "Colore", "Gold", "Mac", "Taglio", "Trattamenti"]}
        pot, fic = [], []
        for s in LEVELS:
            dd = calcola("F", op_f, pas_f, gg, mesi, att_f, tar_sal_f, par_f, tar_giuste, s, sty_raw=sty_raw)
            i = LEVELS.index(s) if s != 9 else IND_8
            for k in livelli:
                livelli[k].append(par_f[k][i])
            pot.append(dd["fic_tot"]); fic.append(dd["fic_ide"])
        add(pagina_stelle("Femminile", "F", livelli, fic, pot, dF["stelle"]), "Femminile: cammino verso le stelle")

    if dM:
        add(pagina_sviluppo("Maschile", "M", dict(dM, fiche_att=cli["fiche_att_m"])), "Sviluppo Maschile")
        livelli = {k: [] for k in ["Cleansing", "Colore", "Gold", "Mac", "Taglio", "Trattamenti"]}
        pot, fic = [], []
        for s in LEVELS:
            dd = calcola("M", op_m, pas_m, gg, mesi, att_m, {}, par_m, {}, s)
            i = LEVELS.index(s) if s != 9 else IND_8
            for k in livelli:
                livelli[k].append(par_m[k][i])
            pot.append(dd["fic_tot"]); fic.append(dd["fic_ide"])
        add(pagina_stelle("Maschile", "M", livelli, fic, pot, dM["stelle"]), "Maschile: cammino verso le stelle")

    if collab:
        # copertina rossa "ANALISI COLLABORATORI" + pagina "ANALISI TEAM" (pagine 19 e 20 del modello)
        add_modello([19], pg('<h1>Analisi <b>collaboratori</b></h1>', "team-cover", "Collaboratori", nav=False),
                    "Analisi collaboratori")
        add_modello([20], pg('<h2>Analisi <b>team</b></h2>', "team-intro", "Collaboratori"), "Analisi team")
        add(pg(pagina_team_mappa(collab), "team", "Collaboratori"), "Mappa collaboratori")
        for i, c in enumerate(collab):
            anc_prec = f"p{i-1}" if i > 0 else None
            anc_succ = f"p{i+1}" if i < len(collab) - 1 else None
            add(pg(pagina_persona(c, i, len(collab), anc_prec, anc_succ), f"p{i}", c.get("NOME_OPERATORE", f"Collaboratore {i+1}")),
                c.get("NOME_OPERATORE", f"Collaboratore {i+1}"))

    add(pg(teoria.pagina_academy(), "academy", "Academy"), "Monacelli Happiness Academy")
    # ultima pagina identica al modello (pagina 36: contatti Monacelli Italy)
    add_modello([36], pg('', "back", "Contatti", nav=False, extra_cls="back-bg"), "Contatti")
    return pagine


def render_html_pdf(cli, codice, pagine):
    """pagine: lista di stringhe HTML (una sezione .page ciascuna)."""
    return f'<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><title>{cli["salone"] or codice} — Magis Plus</title><style>{CSS}</style></head><body>{"".join(pagine)}</body></html>'


# ------------------------------------------------------------------ stampa in PDF
def trova_browser():
    env = os.environ.get("MAGIS_BROWSER")
    candidati = ([env] if env else []) + [
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        "/usr/bin/google-chrome", "/usr/bin/chromium-browser", "/usr/bin/chromium",
    ]
    for c in candidati:
        if os.path.exists(c):
            return c
    return None


def stampa_pdf(html_path, pdf_path):
    exe = trova_browser()
    if not exe:
        return False
    import subprocess
    cmd = [exe, "--headless=new", "--disable-gpu", "--no-pdf-header-footer",
           f"--print-to-pdf={pdf_path}", "file:///" + html_path.replace("\\", "/")]
    if hasattr(os, "geteuid") and os.geteuid() == 0:  # es. container Linux: Chrome non parte come root senza questo
        cmd.insert(1, "--no-sandbox")
    try:
        subprocess.run(cmd, check=True, timeout=60,
                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return os.path.exists(pdf_path)
    except Exception:
        return False


def html_completo(items):
    """Versione tutta-HTML del report (usata come riserva se manca il modello o pypdf)."""
    return [it[1] if it[0] == "H" else it[2] for it in items]


def assembla_pdf(cli, codice, items, pdf_path):
    """Costruisce il PDF finale: le pagine generate vengono stampate dal browser, mentre le pagine
    "M" sono copiate senza modifiche dal modello, nell'ordine previsto. Ritorna True se riuscito."""
    try:
        from pypdf import PdfReader, PdfWriter
    except ImportError:
        print("(nota: 'pip install pypdf' serve per inserire le pagine identiche al modello)")
        return False
    if not os.path.exists(MODELLO_PATH):
        print(f"(nota: manca '{os.path.basename(MODELLO_PATH)}' nella cartella dello script)")
        return False
    modello = PdfReader(MODELLO_PATH)
    w = PdfWriter()
    segnalibri = []
    tmp_files = []

    def stampa_gruppo(gruppo, k):
        tmp_html = os.path.join(OUT_DIR, f"_tmp_{codice}_{k}.html")
        tmp_pdf = os.path.join(OUT_DIR, f"_tmp_{codice}_{k}.pdf")
        tmp_files.extend([tmp_html, tmp_pdf])
        with open(tmp_html, "w", encoding="utf-8") as f:
            f.write(render_html_pdf(cli, codice, [g[1] for g in gruppo]))
        if not stampa_pdf(tmp_html, tmp_pdf):
            return False
        r = PdfReader(tmp_pdf)
        inizio = len(w.pages)
        for p in r.pages:
            w.add_page(p)
        for i, g in enumerate(gruppo):
            if g[2] and inizio + i < len(w.pages):
                segnalibri.append((inizio + i, g[2]))
        return True

    try:
        gruppo, k = [], 0
        for it in items + [None]:
            if it is not None and it[0] == "H":
                gruppo.append(it)
                continue
            if gruppo:
                if not stampa_gruppo(gruppo, k):
                    return False
                gruppo, k = [], k + 1
            if it is None:
                break
            inizio = len(w.pages)
            for n in it[1]:
                w.add_page(modello.pages[MODELLO_PAGINE.index(n)])
            if it[3]:
                segnalibri.append((inizio, it[3]))
        for idx, titolo in segnalibri:
            w.add_outline_item(titolo, idx)
        w.page_mode = "/UseOutlines"
        with open(pdf_path, "wb") as f:
            w.write(f)
        return True
    finally:
        for t in tmp_files:
            try:
                os.remove(t)
            except OSError:
                pass


def aggiungi_segnalibri(pdf_path, elenco):
    """elenco: lista di (indice_pagina, titolo)"""
    try:
        from pypdf import PdfWriter
    except ImportError:
        print("(nota: 'pip install pypdf' per avere anche i segnalibri nel PDF)")
        return
    w = PdfWriter(clone_from=pdf_path)
    for idx, titolo in elenco:
        if idx < len(w.pages):
            w.add_outline_item(titolo, idx)
    w.page_mode = "/UseOutlines"
    w.write(pdf_path)



# --------------------------------------------------------------------------- main
def main():
    print("=== Magis Plus — Progetto di Sviluppo ===")
    if not os.path.exists(XLSX_PATH):
        sys.exit(f"Non trovo '{XLSX_PATH}'. Metti il file .xlsx nella stessa cartella di questo script.")

    codice = input("Codice cliente da sviluppare: ").strip()
    if not codice:
        sys.exit("Codice vuoto, esco.")

    print("Apro il file Excel (può richiedere qualche secondo)...")
    wb = openpyxl.load_workbook(XLSX_PATH, data_only=True)
    ws_psd = wb["psd"]
    riga = trova_riga_cliente(ws_psd, codice)
    if riga is None:
        sys.exit(f"Codice cliente '{codice}' non trovato nel foglio 'psd'.")
    cli = leggi_cliente(ws_psd, riga)
    print(f"Trovato: {cli['salone']} — {cli['titolare']} (riga {riga})")

    par_f = leggi_par(wb["parametri (24)"])
    par_m = leggi_par(wb["parametri (25)"])
    tar_giuste = leggi_tariffe_giuste(wb["parametri (24)"])

    def chiedi_stelle(default):
        s = input(f"Obiettivo stelle (default {default}): ").strip()
        try:
            return int(s) if s else default
        except ValueError:
            return default

    dF = dM = None
    if cli["pas_f"] and cli["op_f"]:
        stelle_f = chiedi_stelle(9)
        sty = leggi_styling(codice, cli["mesi"])
        sty_raw = sty * 10 if sty is not None else None
        dF = calcola("F", cli["op_f"], cli["pas_f"], cli["gg_lav"] or 164, cli["mesi"] or 8,
                     cli["att_f"], cli["tar_sal_f"], par_f, tar_giuste, stelle_f, sty_raw=sty_raw)
    if cli["pas_m"] and cli["op_m"]:
        stelle_m = chiedi_stelle(7)
        dM = calcola("M", cli["op_m"], cli["pas_m"], cli["gg_lav"] or 164, cli["mesi"] or 8,
                     cli["att_m"], {}, par_m, {}, stelle_m)

    os.makedirs(OUT_DIR, exist_ok=True)
    html_path = os.path.join(OUT_DIR, f"Magis_Plus_{codice}.html")
    pdf_path = os.path.join(OUT_DIR, f"Magis_Plus_{codice}.pdf")

    items = costruisci_pagine(cli, codice, dF, dM, par_f, par_m, cli["tar_sal_f"], tar_giuste,
                               cli["op_f"], cli["pas_f"], cli["gg_lav"] or 165, cli["mesi"] or 8,
                               (leggi_styling(codice, cli["mesi"]) or 0) * 10 if cli["pas_f"] else None,
                               cli["op_m"], cli["pas_m"], cli["att_f"], cli["att_m"])
    with open(html_path, "w", encoding="utf-8") as f:
        f.write(render_html_pdf(cli, codice, html_completo(items)))

    print("Stampo il PDF (cerco Edge/Chrome sul PC)...")
    if trova_browser() and assembla_pdf(cli, codice, items, pdf_path):
        print(f"\nFatto! PDF scritto in: {pdf_path}")
        webbrowser.open("file://" + pdf_path)
    elif stampa_pdf(html_path, pdf_path):
        # riserva: PDF tutto generato (senza le pagine identiche al modello)
        aggiungi_segnalibri(pdf_path, [(i, it[-1]) for i, it in enumerate(items) if it[-1]])
        print(f"\nPDF scritto in: {pdf_path} (versione di riserva, senza le pagine del modello)")
        webbrowser.open("file://" + pdf_path)
    else:
        print("\nNon ho trovato Microsoft Edge o Google Chrome sul PC: non posso stampare il PDF automaticamente.")
        print(f"Ho comunque scritto il report in HTML: {html_path}")
        print("Aprilo e usa Stampa > Salva come PDF dal browser per ottenere comunque il PDF.")
        webbrowser.open("file://" + html_path)


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"\nERRORE: {e}")
    input("\nPremi INVIO per chiudere...")

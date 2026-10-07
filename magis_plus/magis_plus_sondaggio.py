# -*- coding: utf-8 -*-
"""Pagine "ELABORAZIONE SONDAGGIO": risposte del sondaggio clienti, domanda per domanda, con grafici.
Il file del sito si chiama 'Magis Plus Sondaggio Clienti - <nome> (<codice>).json' e contiene
{"CODICE", "NOME_SALONE", "risposte": [ {q01_..., q02_..., ...}, ... ]}. Le risposte di piu' clienti
vengono aggregate (conteggi per le scelte, medie per i voti)."""
import html as _html
import math
from collections import Counter

SOGLIA_ROSSO = 6
COLORI = ["#A08BFF", "#E8C766", "#5FD39E", "#FF7C99", "#6FB8FF", "#C9A6FF", "#FFA86B"]


def esc(v):
    return _html.escape("" if v is None else str(v))


def _num(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def f1(x):
    return f"{x:.1f}".replace(".", ",")


def pct(a, b):
    return 100.0 * a / b if b else 0.0


def sondaggio_valido(d):
    return isinstance(d, dict) and isinstance(d.get("risposte"), list) and any(isinstance(r, dict) for r in d["risposte"])


def unisci(lista):
    """Piu' file dello stesso salone: unisce le risposte (senza doppioni, per id)."""
    risp, visti = [], set()
    for d in lista:
        for r in d.get("risposte", []):
            if not isinstance(r, dict):
                continue
            k = r.get("id") or id(r)
            if k in visti:
                continue
            visti.add(k)
            risp.append(r)
    return risp


# ------------------------------------------------------------------ aggregazioni
def conta(risp, chiave):
    c = Counter()
    for r in risp:
        v = r.get(chiave)
        if isinstance(v, str) and v.strip():
            c[v.strip()] += 1
    return c


def medie(risp, chiave):
    """{voce: media} per le domande a voti (dizionario voce -> numero)."""
    somme, n = {}, {}
    for r in risp:
        d = r.get(chiave)
        if not isinstance(d, dict):
            continue
        for k, v in d.items():
            x = _num(v)
            if x is not None:
                somme[k] = somme.get(k, 0) + x
                n[k] = n.get(k, 0) + 1
    return {k: somme[k] / n[k] for k in somme}


def si_no(risp, chiave):
    """{domanda: (n_si, n_no)} per le domande a risposta SI/NO."""
    out = {}
    for r in risp:
        d = r.get(chiave)
        if not isinstance(d, dict):
            continue
        for k, v in d.items():
            s, n = out.get(k, (0, 0))
            t = str(v).strip().upper()
            if t.startswith("S"):
                s += 1
            elif t.startswith("N"):
                n += 1
            out[k] = (s, n)
    return out


def testi(risp, chiave):
    return [str(r[chiave]).strip() for r in risp if str(r.get(chiave) or "").strip()]


def media_gen(d):
    v = list(d.values())
    return sum(v) / len(v) if v else None


# ------------------------------------------------------------------ componenti grafici
def barre_cat(titolo, cont, n):
    if not cont:
        return ""
    tot = max(n, sum(cont.values()), 1)
    righe = ""
    for k, c in cont.most_common():
        p = pct(c, tot)
        righe += (f'<div class="s-row"><span>{esc(k)}</span><div class="s-bar"><i style="width:{p:.0f}%"></i></div>'
                  f'<b>{c} <small>({p:.0f}%)</small></b></div>')
    return f'<h4>{esc(titolo)}</h4>{righe}'


def barre_voto(titolo, med):
    if not med:
        return ""
    m = media_gen(med)
    sub = f'<em class="{"g" if m >= 6.5 else "w"}">media {f1(m)}</em>'
    righe = ""
    for k, v in med.items():
        cl = " low" if v < SOGLIA_ROSSO else ""
        righe += (f'<div class="s-row"><span>{esc(k)}</span><div class="s-bar{cl}"><i style="width:{max(0, min(10, v)) * 10:.0f}%"></i></div>'
                  f'<b>{f1(v)}</b></div>')
    return f'<h4>{esc(titolo)} {sub}</h4>{righe}'


def barre_si_no(titolo, dati):
    if not dati:
        return ""
    righe = ""
    for k, (s, n) in dati.items():
        t = s + n
        ps = pct(s, t)
        righe += (f'<div class="s-q"><span>{esc(k)}</span><div class="s-sn"><i class="si" style="width:{ps:.0f}%"></i>'
                  f'<i class="no" style="width:{100 - ps:.0f}%"></i></div>'
                  f'<b>SI {ps:.0f}% <small>({s}/{t})</small></b></div>')
    return f'<h4>{esc(titolo)}</h4>{righe}'


def donut(titolo, cont):
    if not cont:
        return ""
    tot = sum(cont.values())
    r, c = 15.0, 2 * math.pi * 15.0
    off, archi, leg = 0.0, "", ""
    for i, (k, v) in enumerate(cont.most_common()):
        col = COLORI[i % len(COLORI)]
        frac = v / tot
        archi += (f'<circle cx="20" cy="20" r="{r}" fill="none" stroke="{col}" stroke-width="6" '
                  f'stroke-dasharray="{frac * c:.3f} {c:.3f}" stroke-dashoffset="{-off * c:.3f}" transform="rotate(-90 20 20)"/>')
        off += frac
        leg += f'<div><i style="background:{col}"></i>{esc(k)} <b>{pct(v, tot):.0f}%</b></div>'
    return (f'<h4>{esc(titolo)}</h4><div class="s-don"><svg viewBox="0 0 40 40" width="30mm" height="30mm">'
            f'<circle class="s-trk" cx="20" cy="20" r="{r}" fill="none" stroke-width="6"/>{archi}'
            f'<text x="20" y="21.6" text-anchor="middle" class="s-dt">{tot}</text></svg><div class="s-leg">{leg}</div></div>')


def citazioni(titolo, lista, massimo=4):
    if not lista:
        return ""
    voci = "".join(f'<div class="s-quote">“{esc(t[:220])}{"…" if len(t) > 220 else ""}”</div>' for t in lista[:massimo])
    return f'<h4>{esc(titolo)}</h4>{voci}'


# ------------------------------------------------------------------ pagine
def pagine(risp, nome_salone):
    """Ritorna [(corpo_html, id_pagina, titolo)] delle pagine del sondaggio (la copertina e' del modello)."""
    n = len(risp)
    serv = medie(risp, "q08_servizi")
    val = medie(risp, "q09_valutazioni")
    com = medie(risp, "q10_comunicazione")
    cons = conta(risp, "q16_consiglieresti")
    sodd = conta(risp, "q15_soddisfazione_capelli")
    n_si = sum(c for k, c in cons.items() if k.upper().startswith("S"))
    tot_cons = sum(cons.values())

    kpi = [(str(n), "Questionari ricevuti")]
    if serv:
        kpi.append((f1(media_gen(serv)), "Media servizi (0-10)"))
    if val:
        kpi.append((f1(media_gen(val)), "Media valutazioni (0-10)"))
    if tot_cons:
        kpi.append((f"{pct(n_si, tot_cons):.0f}%", "Consiglierebbero il salone"))
    kpi_html = '<div class="m-kpi">' + "".join(f'<div><b>{esc(a)}</b><span>{esc(b)}</span></div>' for a, b in kpi) + "</div>"

    p1 = f"""<h1 class="ph">Sondaggio <b>clienti</b></h1>
      <p class="lede lp">Le risposte dei clienti di {esc(nome_salone)}: chi sono, da quanto tempo e perché scelgono il salone.</p>
      {kpi_html}
      <div class="m-2">
        <div class="m-card">{barre_cat("Come ci hanno conosciuto", conta(risp, "q01_conosciuto_tramite"), n)}
          {barre_cat("Frequentano il salone da", conta(risp, "q02_frequenti_da"), n)}
          {barre_cat("Frequenza dei servizi", conta(risp, "q03_frequenza_servizi"), n)}</div>
        <div class="m-card">{barre_cat("Perché frequentano il salone", conta(risp, "q04_perche_frequenti"), n)}
          {donut("Chi ha compilato", conta(risp, "q17_compilato_da"))}
          {barre_cat("Fascia d'età", conta(risp, "q18_eta"), n)}</div>
      </div>"""

    p2 = f"""<h1 class="ph">Come ci <b>vedono</b></h1>
      <p class="lede lp">Come i clienti descrivono titolare, personale e salone, e i voti dati a servizi e prestazioni (da 0 a 10).</p>
      <div class="m-2">
        <div class="m-card">{barre_cat("Il titolare è…", conta(risp, "q05_titolare"), n)}
          {barre_cat("Il personale è…", conta(risp, "q06_personale"), n)}
          {barre_cat("Il salone è…", conta(risp, "q07_salone"), n)}</div>
        <div class="m-card">{barre_voto("Valutazione dei servizi", serv)}
          {barre_voto("Valutazione delle prestazioni", val)}</div>
      </div>"""

    sugg_c = citazioni("Suggerimenti sulla comunicazione", testi(risp, "q11_suggerimenti_comunicazione"))
    sugg_m = citazioni("Suggerimenti sul mantenimento a casa", testi(risp, "q13_suggerimenti_mantenimento"))
    p3 = f"""<h1 class="ph">Comunicazione, casa e <b>fiducia</b></h1>
      <p class="lede lp">Giudizio sulla comunicazione del salone, mantenimento a casa, esigenze dei clienti e passaparola.</p>
      <div class="m-2">
        <div class="m-card">{barre_voto("Comunicazione del salone", com)}
          {sugg_c}
          {barre_si_no("Mantenimento a casa", si_no(risp, "q12_mantenimento_a_casa"))}
          {sugg_m}</div>
        <div class="m-card">{barre_si_no("Cosa vorrebbero di più", si_no(risp, "q14_esigenze"))}
          {barre_cat("Soddisfazione per i capelli", sodd, n)}
          {donut("Consiglieresti il salone?", cons)}</div>
      </div>"""
    return [(p1, "sondaggio-1", "Sondaggio: chi sono i clienti"),
            (p2, "sondaggio-2", "Sondaggio: come ci vedono"),
            (p3, "sondaggio-3", "Sondaggio: comunicazione e fiducia")]


CSS = """
.s-row{display:grid;grid-template-columns:34mm 1fr 18mm;gap:2.4mm;align-items:center;font-size:9pt;min-height:6.4mm}
.s-row b{text-align:right;font-weight:600}.s-row small{font-weight:400;color:#A9ADCF;font-size:7.5pt}
.s-bar{height:2.2mm;border-radius:99px;background:rgba(255,255,255,.12);overflow:hidden}
.s-bar i{display:block;height:100%;background:#A08BFF;border-radius:99px}
.s-bar.low i{background:#FF7C99}
.s-q{margin:2.4mm 0 3.6mm;font-size:9pt}.s-q span{display:block;line-height:1.35;margin-bottom:1.4mm}
.s-q b{display:block;font-weight:600;margin-top:1mm;font-size:8.6pt}.s-q small{font-weight:400;color:#A9ADCF;font-size:7.5pt}
.s-sn{display:flex;height:2.6mm;border-radius:99px;overflow:hidden;background:rgba(255,255,255,.12)}
.s-sn i{display:block;height:100%}.s-sn i.si{background:#5FD39E}.s-sn i.no{background:#FF7C99}
.s-don{display:flex;align-items:center;gap:5mm;margin-bottom:3mm}
.s-trk{stroke:rgba(255,255,255,.14)}.s-dt{font-family:"BSD",sans-serif;font-size:8px;fill:currentColor}
.s-leg{font-size:9pt;line-height:1.7}.s-leg i{display:inline-block;width:2.4mm;height:2.4mm;border-radius:50%;margin-right:2mm}
.s-leg b{margin-left:1mm;font-weight:600}
.s-quote{font-size:9pt;line-height:1.45;font-style:italic;padding:2.4mm 3.2mm;margin:1.8mm 0 2.4mm;border-left:.8mm solid #E8C766;background:rgba(255,255,255,.06);border-radius:0 2mm 2mm 0}
"""

# regole aggiuntive per la versione 2 (sfondo bianco)
CSS_V2 = """
.s-row small,.s-q small{color:#5C6280}
.s-bar,.s-sn{background:#DADDEA}.s-bar i{background:#6B4FE0}.s-bar.low i{background:#D63A5F}
.s-sn i.si{background:#1E9A62}.s-sn i.no{background:#D63A5F}
.s-trk{stroke:#DADDEA}
.s-quote{background:#ECEEF6;border-left-color:#B8892A}
"""

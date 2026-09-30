# -*- coding: utf-8 -*-
"""
Pagine "Immagine interna ed esterna" e "Analisi collaboratori" (mappa del team + una scheda per persona)
in stile moderno, come gli esempi Pagina_IMMAGINE.pdf e Pagine_collaboratori_..._interattivo.pdf:
sfondo scuro, schede vetrate, barre a puntini, chip colorati, nomi e punteggi cliccabili.

Ogni funzione restituisce il CORPO della pagina (HTML); la cornice con la barra di navigazione in basso
e il numero di pagina la aggiunge magis_plus.pg().
"""
import html as _html
import re

# ------------------------------------------------------------------ dati e calcoli
ACC = ["Saluto", "Controllo appuntamento in agenda", "Routine accoglienza", "Comunicazione iniziative e promozioni"]
CON = ["Connessione", "Ascolto", "Capacità di capire i desideri", "Capacità di consigliare", "Condivisione"]
CONG = ["Verifica soddisfazione", "Propone/riconferma prodotti", "Quantifica ed elenca servizi",
        "Fissa prossimo appuntamento", "Prende abbigliamento"]
TEC_SERV = [("Lavaggio", "Detergenza e trattamenti"), ("Colore", "Colore"), ("Taglio", "Taglio"), ("Piega", "Piega")]
TEC_RIGHE = [("Tempi", "Tempi esecuzione"), ("Metodo", "Metodo"), ("Risultato", "Risultato finale")]
IMG = ["Look coerente", "Capelli", "Trucco/barba", "Divisa", "Eleganza e postura", "Cura e pulizia spazi"]
ETICHETTE = {  # nomi brevi come negli esempi
    "Saluto": "Saluto e sorriso", "Controllo appuntamento in agenda": "Controllo appuntamento",
    "Routine accoglienza": "Routine di accoglienza", "Comunicazione iniziative e promozioni": "Comunicazione iniziative",
    "Capacità di capire i desideri": "Capire i desideri", "Propone/riconferma prodotti": "Propone/riconferma prodotti",
    "Verifica soddisfazione": "Verifica soddisfazione", "Look coerente": "Look coerente con la filosofia",
    "Eleganza e postura": "Eleganza e postura", "Cura e pulizia spazi": "Cura degli spazi e oggetti"}
GIORNI = ["Lunedì", "Martedì", "Mercoledì", "Giovedì", "Venerdì", "Sabato", "Domenica"]
SOGLIA_ROSSO = 6


def esc(v):
    return _html.escape("" if v is None else str(v))


def f1(x):
    return f"{x:.1f}".replace(".", ",")


def f2(x):
    return f"{x:.2f}".replace(".", ",")


def _vals(punt, chiavi):
    return [punt.get(k) for k in chiavi if isinstance(punt.get(k), (int, float))]


def _media(v):
    return sum(v) / len(v) if v else None


def aree(c):
    """Medie per area (None se nessun dato) e media generale (sulle aree disponibili)."""
    p = c.get("punteggi") or {}
    tec = [p.get(f"{s}_{k}") for s, _ in TEC_SERV for k, _ in TEC_RIGHE]
    a = {"Accoglienza": _media(_vals(p, ACC)), "Consulenza": _media(_vals(p, CON)), "Congedo": _media(_vals(p, CONG)),
         "Tecnica": _media([x for x in tec if isinstance(x, (int, float))]), "Immagine": _media(_vals(p, IMG))}
    dispo = [x for x in a.values() if x is not None]
    return a, (_media(dispo) if dispo else None)


def giudizio(media):
    return "ottimo" if media >= 8 else "buono" if media >= 6.5 else "sufficiente" if media >= 5 else "insufficiente"


def mappa_id(i):
    """id della pagina della mappa che contiene la persona i (la mappa si divide dopo 12, poi 15 righe per pagina)."""
    return "team" if i < 12 else f"team-{2 + (i - 12) // 15}"


def _slug(i):
    return f"p{i}"


# ------------------------------------------------------------------ IMMAGINE INTERNA ED ESTERNA
def _dots(v):
    n = int(round(v)) if isinstance(v, (int, float)) else 0
    n = max(0, min(10, n))
    return "".join(f'<i class="{"on" if k < n else ""}"></i>' for k in range(10))


def _chips(testo):
    """Trasforma un testo libero ("A, B, C (mancano D)") in chip: verdi = presente, rosa = mancante."""
    testo = (testo or "").strip()
    if not testo:
        return ""
    mancanti = []
    def preleva(m):
        mancanti.extend([x.strip() for x in re.split(r",| e ", m.group(1)) if x.strip()])
        return ""
    testo = re.sub(r"\((?:mancano|manca|mancante|mancanti|assente|assenti)\s*:?\s*([^)]*)\)", preleva, testo, flags=re.I)
    def preleva2(m):
        mancanti.append(m.group(1).strip())
        return ""
    testo = re.sub(r"\(([^)]*?)\s+(?:non presente|assente|non attivo)\)", preleva2, testo, flags=re.I)
    presenti = [x.strip(" .") for x in re.split(r"[,;]|\se\s(?=[A-Z])", testo) if x.strip(" .")]
    out = "".join(f'<span class="ch ok"><b>●</b>{esc(x[:1].upper() + x[1:])}</span>' for x in presenti)
    out += "".join(f'<span class="ch no"><b>●</b>{esc(x[:1].upper() + x[1:])}</span>' for x in mancanti)
    return out


def pagina_immagine(d, nome_salone):
    d = d or {}
    imm = d.get("immagine") or {}
    pos = d.get("postazioni") or {}
    voci = list(imm.items())
    vals = [v for _, v in voci if isinstance(v, (int, float))]
    media = _media(vals)
    team = d.get("numero_team")
    pos_txt = [f'<div><b>{esc(pos[k])}</b><span>{lab}</span></div>' for k, lab in
               (("stilistico", "Postazioni stilistiche"), ("tecnico", "Postazioni tecniche"),
                ("lavaggio", "Lavaggi"), ("consulenza", "Consulenza")) if pos.get(k) is not None]
    kpi = ""
    if team is not None or pos_txt:
        kpi = '<div class="m-kpi">' + (f'<div><b>{esc(team)}</b><span>Persone nel team</span></div>' if team is not None else "") \
              + "".join(pos_txt) + "</div>"
    righe = "".join(
        f'<div class="m-row"><span>{esc(k)}</span><div class="m-dots">{_dots(v)}</div><b>{esc(int(v) if isinstance(v, float) and v == int(v) else v) if v is not None else "—"}</b></div>'
        for k, v in voci)
    titolo_media = f'<em class="{"g" if media is not None and media >= 6.5 else "w"}">{f2(media)}, {giudizio(media)}</em>' if media is not None else ""
    mat = _chips(d.get("materiale_salone"))
    vet = _chips(d.get("materiale_vetrine"))
    soc = _chips(d.get("presenza_social"))
    brand = (d.get("brand") or "").strip()
    right = ""
    if mat:
        right += f'<h4>Materiale interno</h4><div class="m-chips">{mat}</div>'
    if vet:
        right += f'<h4>Vetrine</h4><div class="m-chips">{vet}</div>'
    if soc:
        right += f'<h4>Social</h4><div class="m-chips">{soc}</div>'
    if brand:
        right += f'<h4>Brand</h4><div class="m-chips"><span class="ch br"><b>★</b>{esc(brand)}</span></div>'
    prop = (d.get("proposte_sviluppo") or "").strip()
    return f"""<h1 class="ph">Immagine <b>interna ed esterna</b></h1>
      <p class="lede lp">Come il salone si presenta ai clienti: ambiente, materiali, vetrine e presenza online. Punteggi da 0 a 10.</p>
      {kpi}
      <div class="m-2">
        <div class="m-card"><h4>Immagine del salone {titolo_media}</h4>{righe or '<p class="lede">Nessun punteggio inserito.</p>'}</div>
        <div class="m-card">{right or '<p class="lede">Nessun dettaglio inserito.</p>'}</div>
      </div>
      {f'<div class="m-call"><b>Proposta di sviluppo.</b> {esc(prop)}</div>' if prop else ''}"""


# ------------------------------------------------------------------ ANALISI COLLABORATORI: mappa del team
def _clientela(c):
    cl = [x.lower() for x in (c.get("CLIENTELA") or [])]
    return " e ".join(cl)


def _sotto(c):
    r = (c.get("RUOLO") or "").strip()
    cl = _clientela(c)
    return ", ".join(x for x in (r, cl) if x)


def _chip_val(v, href):
    if v is None:
        return '<span class="m-v nd">–</span>'
    if v < SOGLIA_ROSSO:
        return f'<a href="#{href}" class="m-v low">{f1(v)}</a>'
    a = max(0.15, min(0.8, 0.13 * v - 0.47))
    return f'<a href="#{href}" class="m-v" style="background:rgba(160,139,255,{a:.2f})">{f1(v)}</a>'


def _insight(collab):
    if len(collab) < 2:
        return ""
    med = {}
    for c in collab:
        a, _ = aree(c)
        for k, v in a.items():
            if v is not None:
                med.setdefault(k, []).append(v)
    if not med:
        return ""
    media_aree = {k: sum(v) / len(v) for k, v in med.items()}
    debole = min(media_aree, key=media_aree.get)
    frasi = [f"L'area più debole del team è <b>{debole.lower()}</b> (media {f1(media_aree[debole])})."]
    zeri = []
    for k, lab in (("Quantifica ed elenca servizi", "quantifica ed elenca servizi"), ("Fissa prossimo appuntamento", "fissa il prossimo appuntamento"),
                   ("Prende abbigliamento", "prende l'abbigliamento")):
        n = sum(1 for c in collab if (c.get("punteggi") or {}).get(k) == 0)
        if n >= max(2, len(collab) / 2):
            zeri.append(f"«{lab}»")
    if zeri:
        frasi.append("Nel congedo del cliente quasi tutto il team ha 0 su " + " e ".join(zeri) + ".")
    return " ".join(frasi)


def mappa_team(collab, primo_id="team"):
    """Restituisce una lista di (id_sezione, corpo_html): la mappa si divide in piu' pagine se ci sono molte persone."""
    prima, altre = 12, 15
    gruppi, i = [], 0
    n = len(collab)
    take = prima
    while i < n or not gruppi:
        gruppi.append(list(range(i, min(n, i + take))))
        i += take
        take = altre
    pagine = []
    for g, idx in enumerate(gruppi):
        righe = ""
        for i in idx:
            c = collab[i]
            a, _ = aree(c)
            righe += (f'<div class="m-mr"><a class="nm" href="#{_slug(i)}"><b>{esc(c.get("NOME_OPERATORE") or f"Collaboratore {i+1}")}</b>'
                      f'<small>{esc(_sotto(c))}</small></a>'
                      + "".join(f'<div>{_chip_val(a[k], _slug(i))}</div>' for k in ("Accoglienza", "Consulenza", "Congedo", "Tecnica", "Immagine"))
                      + "</div>")
        tabella = ('<div class="m-map"><div class="m-mh"><span>Collaboratore</span>'
                   + "".join(f"<span>{k}</span>" for k in ("Accoglienza", "Consulenza", "Congedo", "Tecnica", "Immagine"))
                   + f"</div>{righe}</div>")
        ultima = g == len(gruppi) - 1
        ins = _insight(collab) if ultima and len(idx) <= (12 if g == 0 else 14) else ""
        if g == 0:
            testa = f"""<h1 class="ph">Analisi <b>collaboratori</b></h1>
              <p class="lede lp2">Monacelli Italy analizza le skill di ogni collaboratore su quattro aree strategiche: professionalità etica,
              professionalità tecnica, immagine personale e proposte formative. La forza di un'azienda si misura dal suo anello più debole.</p>
              <p class="lede lp2">Media per area, da 0 a 10. Il rosso segnala le aree sotto {SOGLIA_ROSSO}. <b>Tocca un nome o un punteggio per aprire la scheda.</b></p>"""
        else:
            testa = f'<h1 class="ph">Analisi <b>collaboratori</b> <small class="seg">({g + 1}/{len(gruppi)})</small></h1>'
        pagine.append((primo_id if g == 0 else f"{primo_id}-{g + 1}", testa + tabella + (f'<div class="m-call sm">{ins}</div>' if ins else "")))
    return pagine


# ------------------------------------------------------------------ scheda del singolo collaboratore
def _giorni(gg):
    gg = [g for g in GIORNI if g in (gg or [])]
    if not gg:
        return "—"
    if len(gg) == 7:
        return "tutti"
    idx = [GIORNI.index(g) for g in gg]
    if idx == list(range(idx[0], idx[-1] + 1)) and len(gg) > 2:
        return f"{gg[0][:3].lower()}–{gg[-1][:3].lower()}"
    return ", ".join(g[:3].lower() for g in gg)


def _bar(lab, v):
    if v is None:
        return f'<div class="m-b"><span>{esc(lab)}</span><div class="bar"></div><b class="nd">–</b></div>'
    zero = v == 0
    w = max(0, min(100, v * 10))
    val = int(v) if isinstance(v, float) and v == int(v) else v
    return (f'<div class="m-b"><span>{esc(lab)}</span><div class="bar"><i style="width:{w}%"></i></div>'
            f'<b class="{"z" if zero else ""}">{esc(val)}</b></div>')


def persona(c, i, collab, id_mappa="team"):
    p = c.get("punteggi") or {}
    a, gen = aree(c)
    nome = (c.get("NOME_OPERATORE") or f"Collaboratore {i + 1}").strip()
    sotto = _sotto(c)
    ore = c.get("ORE_LAVORATE")
    comp = (c.get("compenso_incentivi") or "").strip()
    gruppo = lambda titolo, chiavi: (f'<h4>{titolo}</h4>' + "".join(_bar(ETICHETTE.get(k, k), p.get(k)) for k in chiavi))
    # tabella tecnica
    th = "".join(f"<th>{esc(lab)}</th>" for _, lab in TEC_SERV)
    tr = ""
    for k, lab in TEC_RIGHE:
        tr += f"<tr><td>{lab}</td>" + "".join(
            f'<td>{esc(p.get(f"{s}_{k}") if p.get(f"{s}_{k}") is not None else "–")}</td>' for s, _ in TEC_SERV) + "</tr>"
    prop = (c.get("proposte_formative") or "").strip()
    nav = f'<a class="pill" href="#{id_mappa}">Torna alla mappa del team</a>'
    if i > 0:
        nav += f'<a class="pill" href="#{_slug(i - 1)}">← {esc(collab[i - 1].get("NOME_OPERATORE") or "")}</a>'
    if i < len(collab) - 1:
        nav += f'<a class="pill" href="#{_slug(i + 1)}">{esc(collab[i + 1].get("NOME_OPERATORE") or "")} →</a>'
    return f"""<div class="m-head"><div><h1 class="ph">{esc(nome)}</h1><p class="lede lp">{esc(sotto)}</p></div>
        <div class="m-gen"><b>{f1(gen) if gen is not None else '–'}</b><span>media generale</span></div></div>
      <div class="m-strip"><div><span>Giorni</span><b>{esc(_giorni(c.get("GIORNI")))}</b></div>
        <div><span>Ore al giorno</span><b>{esc(_num(ore))}</b></div>
        <div class="w2"><span>Compenso e incentivi</span><b>{esc(comp) if comp else '—'}</b></div></div>
      <div class="m-cols">
        <div>{gruppo("Accoglienza", ACC)}{gruppo("Consulenza", CON)}{gruppo("Congedo", CONG)}</div>
        <div><h4>Professionalità tecnica</h4><table class="m-tec"><thead><tr><th></th>{th}</tr></thead><tbody>{tr}</tbody></table>
          {gruppo("Immagine personale", IMG)}</div>
      </div>
      {f'<div class="m-call g"><b>Proposta formativa.</b> {esc(prop)}</div>' if prop else '<div class="m-call g"><b>Proposta formativa.</b> da definire.</div>'}
      <div class="m-nav">{nav}</div>"""


def _num(v):
    if v in (None, ""):
        return "—"
    try:
        f = float(v)
    except (TypeError, ValueError):
        return str(v)
    return str(int(f)) if f == int(f) else str(f).replace(".", ",")


# ------------------------------------------------------------------ CSS
CSS = """
.m-2{display:grid;grid-template-columns:1fr 1fr;gap:5mm;margin-top:4mm}
.m-card{background:rgba(255,255,255,.06);border:.3mm solid rgba(255,255,255,.14);border-radius:4mm;padding:5.5mm 6mm 6mm;min-height:60mm}
.m-card h4,.m-cols h4{font-family:"BSD",sans-serif;font-weight:400;font-size:17pt;color:#EEEDF8;margin:0 0 3mm}
.m-card h4:not(:first-child){margin-top:5mm}
.m-card h4 em{font-family:"Fig",sans-serif;font-style:normal;font-weight:700;font-size:9pt;margin-left:2mm}
.m-card h4 em.g{color:#5FD39E}.m-card h4 em.w{color:#E8C766}
.m-row{display:grid;grid-template-columns:1fr 29mm 6mm;gap:2mm;align-items:center;padding:2.6mm 0;border-bottom:.3mm solid rgba(255,255,255,.1);font-size:9.5pt}
.m-row:last-child{border-bottom:0}
.m-row b{text-align:right}
.m-dots{display:flex;gap:.55mm}
.m-dots i{width:2.1mm;height:2.1mm;border-radius:.5mm;background:rgba(255,255,255,.12)}
.m-dots i.on{background:#A08BFF}
.m-chips{display:flex;flex-wrap:wrap;gap:2mm}
.ch{display:inline-flex;align-items:center;gap:1.6mm;border:.3mm solid rgba(255,255,255,.22);border-radius:99px;padding:1.5mm 3mm;font-size:9pt;color:#EEEDF8}
.ch b{font-size:6pt}.ch.ok b{color:#5FD39E}.ch.no{border-color:#FF7C99;color:#FF7C99}.ch.no b{color:#FF7C99}.ch.br b{color:#E8C766}
.m-kpi{display:flex;gap:.3mm;background:rgba(255,255,255,.14);border:.3mm solid rgba(255,255,255,.14);border-radius:3mm;overflow:hidden;margin-top:1mm}
.m-kpi div{background:#1A2158;padding:3.2mm 4mm;flex:1}
.m-kpi b{display:block;font-family:"BSD",sans-serif;font-weight:500;font-size:19pt;color:#EEEDF8;line-height:1.1}
.m-kpi span{font-size:8pt;color:#A9ADCF}
.m-call{margin-top:5mm;padding:4.5mm 6mm;border-left:.9mm solid rgba(255,255,255,.25);background:rgba(255,255,255,.06);border-radius:0 3mm 3mm 0;font-size:10.5pt;line-height:1.5}
.m-call.sm{margin-top:4mm;padding:3.2mm 5mm;font-size:9pt}
.m-call.g{border:.3mm solid rgba(232,199,102,.35);background:rgba(232,199,102,.12);border-radius:3mm;font-size:10pt}
p.lede.lp2{font-size:11pt;max-width:none;margin:0 0 3mm}
.seg{font-size:16pt;color:#A9ADCF;font-weight:300}
.m-map{margin-top:5mm;background:rgba(255,255,255,.06);border:.3mm solid rgba(255,255,255,.14);border-radius:4mm;overflow:hidden}
.m-mh,.m-mr{display:grid;grid-template-columns:54mm repeat(5,1fr);align-items:center;padding:0 4mm}
.m-mh{height:9.3mm;font-size:8.5pt;color:#A9ADCF;border-bottom:.3mm solid rgba(255,255,255,.14)}
.m-mh span+span,.m-mr div{text-align:center}
.m-mr{height:14.7mm;border-bottom:.3mm solid rgba(255,255,255,.1)}
.m-mr:last-child{border-bottom:0}
a.nm{text-decoration:none;color:#EEEDF8;display:block}
a.nm b{display:block;font-size:9.5pt;font-weight:600}a.nm small{display:block;font-size:8pt;color:#A9ADCF}
.m-v{display:inline-block;min-width:13mm;padding:1.7mm 0;border-radius:1.6mm;font-size:9.5pt;text-decoration:none;color:#fff;text-align:center}
.m-v.low{background:rgba(255,124,153,.2);color:#FFB3C4}.m-v.nd{color:#A9ADCF}
.m-head{display:flex;justify-content:space-between;align-items:flex-start}
.m-gen{text-align:right}.m-gen b{display:block;font-family:"BSD",sans-serif;font-weight:500;font-size:40pt;line-height:1;color:#E8C766;margin-top:1mm}
.m-gen span{font-size:8pt;color:#A9ADCF}
.m-strip{display:grid;grid-template-columns:1fr 1fr 2fr;gap:4mm;border-top:.3mm solid rgba(255,255,255,.14);border-bottom:.3mm solid rgba(255,255,255,.14);padding:3.4mm 0;margin:1mm 0 7mm}
.m-strip span{display:block;font-size:8.3pt;color:#A9ADCF}.m-strip b{font-size:10pt;font-weight:400}
.m-cols{display:grid;grid-template-columns:90mm 1fr;gap:5mm}
.m-cols h4{font-family:"Fig",sans-serif;font-weight:700;font-size:10pt;margin:0 0 2.2mm}
.m-cols>div>h4:not(:first-child),.m-cols table+h4{margin-top:6mm}
.m-b{display:grid;grid-template-columns:1fr 29mm 6mm;gap:3mm;align-items:center;font-size:9pt;color:#A9ADCF;height:6.6mm}
.m-b .bar{height:1.3mm;border-radius:99px;background:rgba(255,255,255,.12);overflow:hidden}
.m-b .bar i{display:block;height:100%;background:#A08BFF;border-radius:99px}
.m-b b{text-align:right;font-weight:500}.m-b b.z{color:#FF7C99}.m-b b.nd{color:#A9ADCF}
.m-tec{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;background:rgba(255,255,255,.06);border-radius:3mm;overflow:hidden;font-size:9pt;margin-bottom:0}
.m-tec th{font-size:7.6pt;color:#A9ADCF;font-weight:400;padding:2.4mm 1mm;text-align:center;border-bottom:.3mm solid rgba(255,255,255,.14);background:none}
.m-tec th:first-child,.m-tec td:first-child{width:19mm;text-align:left;padding-left:3mm}
.m-tec td{padding:3mm 1mm;text-align:center;border-bottom:.3mm solid rgba(255,255,255,.1)}
.m-tec tr:last-child td{border-bottom:0}
.m-nav{display:flex;gap:2.7mm;margin-top:6mm;flex-wrap:wrap}
a.pill{border:.75pt solid #E8C766;border-radius:99px;color:#E8C766;font-size:9pt;font-weight:600;text-decoration:none;padding:2.4mm 4.3mm}
"""

# -*- coding: utf-8 -*-
"""
Pagine "IL MIO SOGNO", "IMMAGINE INTERNA ED ESTERNA" e "ANALISI COLLABORATORI" (una per persona),
ricostruite con lo stesso layout del modello Magis_plus2026_modello.pdf (pagine 8, 12 e 21):
fascia blu in alto con il logo, pagina bianca, riquadri e colori identici. Le posizioni sono in mm
sull'A4 (come nel modello); i testi arrivano dai moduli di raccolta dati (file JSON).
"""
import html as _html

BANNER_B64 = "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TSeIL/EZK2cXEaevuayL/VdQ1J917dyTH0Y8fl0qlTUerBvogoooqiQooooASilpKBhRRRQBtX//AB8n6Cq1Wb//AI+T9BVauqn8COafxMKKKK0JCkpaSkAGkpaKAEopaSgYlBpaKBjaKXFJSAKKKKACiiikMKAaKSkBIrkHg1JhZOQdrelV6UGgCQgqcMMUlPSYY2yDcv6inNFkbo/mX9RTTE0RUtJRVEi0UUUAFFFFAC0UUUCGlc03GKkoIBpDuR0UrKRSUigooooASilpKACiiigAooopAFFFFAwooooAQ0lOpKACikpaQBSUtJQAUUUUAFFFFABRRRQAUlLRQMSiiikAUUUUAFJS0UDEooooAKKKKQBRRRQAUUUUAJRS0lABRRRSAKKKKBhRRRQAlFFFIAooooAKKKKACiiigBKWkpaBi0lGaKQBRRRQAUhpaQ0AJRRRQAUUUUDCiiikAUlLSUAJS0UUgCkpaSgAooooGFFFFABRRRSAKKKKACiiigBKKKKBhRRRQAlFFFIAooooAKKKKACkpaSgAooooGbV/wD8fJ+gqtRRXXS+BHNP4mLSUUVZAUUUUAFFFFIYhpKKKBhRRRQMKSiigBKKKKQBRRRSAKSiigYUUUUgCno7IwKnBoooAuRxrcxsSNrr3Heqo60UULcGJS0UVZAUUUUAFLRRQIKKKKAFprAUUUmNDKKKKRQUlFFABRRRQAUUUUAFFFFIYUUUUAFJRRQAlFFFAC0UUUgEooooAKKKKACiiigAooooGJRRRSAKKKKACiiigYlFFFABRRRSAKKKKACiiigAooooASiiikAUUUUDCiiigAooopAFJRRQAUlFFA0LSUUUCFpKKKACloooGFFFFIApKKKAEooooAKKKKACkoopDCiiigAooooAKKKKQCUUUUDCiiigAooooAKKKKQBRRRQAhooooGFFFFABSUUUgCiiigAooooAKKKKAEooooGf//Z"

ABS_CSS = """
.page.ap{position:relative;width:210mm;height:297mm;min-height:0;overflow:hidden;background:#fff;
  font-family:Calibri,Carlito,"Segoe UI",Arial,sans-serif;color:#16365C;page-break-after:always}
.ap .b{position:absolute;display:flex;align-items:center;justify-content:center;text-align:center;overflow:hidden;
  line-height:1.05;font-size:7.9pt;box-sizing:border-box}
.ap .bd{background:#fff;border:.35mm solid #BCBCBC}
.ap .dk{background:#0B3041;color:#fff;font-weight:700;border:.35mm solid #BCBCBC}
.ap .dk2{background:#254061;color:#fff;font-weight:700}
.ap .lb{background:#B8D4F0;color:#071420;font-weight:700;border:.35mm solid #BCBCBC}
.ap .lt{background:#DCE6F2;color:#16365C;border:.35mm solid #BCBCBC}
.ap .tx{justify-content:flex-start;align-items:flex-start;text-align:left;padding:1mm 1.5mm;line-height:1.2}
.ap .bn{position:absolute}
.ap .bt{position:absolute;color:#fff;white-space:nowrap}
.ap .nm{position:absolute;text-align:right;color:#071420;white-space:nowrap}
.ap b.v{font-weight:400}
"""


def esc(v):
    return _html.escape("" if v is None else str(v))


def _num(v):
    """8 -> '8', 7.5 -> '7,5', None -> '—'"""
    if v is None or v == "":
        return "—"
    try:
        f = float(v)
    except (TypeError, ValueError):
        return esc(v)
    return str(int(f)) if f == int(f) else str(f).replace(".", ",")


def _box(x0, y0, x1, y1, txt="", cls="bd", size=None, style=""):
    st = f"left:{x0}mm;top:{y0}mm;width:{x1 - x0}mm;height:{y1 - y0}mm;"
    if size:
        st += f"font-size:{size}pt;"
    return f'<div class="b {cls}" style="{st}{style}">{txt}</div>'


def _fit(txt, corto, medio, lungo):
    """Riduce il corpo del testo se il contenuto e' lungo (pt)."""
    n = len(txt or "")
    return corto if n <= 260 else medio if n <= 420 else lungo


def _pagina(pid, corpo):
    return f'<section class="page ap" id="{pid}">{corpo}</section>'


def _fascia(x0, y0, larg, titolo_x, titolo_y, titolo, nome, nome_right, nome_y, size_t, size_n):
    alt = larg / 9.27
    return (f'<img class="bn" src="data:image/jpeg;base64,{BANNER_B64}" style="left:{x0}mm;top:{y0}mm;width:{larg}mm;height:{alt:.1f}mm">'
            f'<div class="bt" style="left:{titolo_x}mm;top:{titolo_y}mm;font-size:{size_t}pt">| MAGIS PLUS | {esc(titolo)}</div>'
            f'<div class="nm" style="right:{210 - nome_right}mm;top:{nome_y}mm;font-size:{size_n}pt">{esc(nome)}</div>')


# ----------------------------------------------------------------------------- IL MIO SOGNO
def pagina_sogno(dati_salone, nome_salone):
    d = dati_salone or {}
    sogno = (d.get("sogno") or "").strip()
    if not sogno:
        return None
    autore = (d.get("NOME_TITOLARE") or "").strip()
    n = len(sogno)
    corpo_pt = 15 if n <= 350 else 13 if n <= 800 else 11
    c = _fascia(32.8, 11.2, 145.7, 36.0, 20.7, "IL MIO SOGNO", nome_salone, 174.4, 27.3, 8.3, 8.3)
    c += (f'<div style="position:absolute;left:32.8mm;top:36mm;width:145.7mm;box-sizing:border-box;padding:9mm 10mm 7mm;'
          f'background:#FAFAF6;border:.35mm solid #D9D9D2;font-family:Georgia,\'Times New Roman\',serif;font-style:italic;'
          f'font-size:{corpo_pt}pt;line-height:1.55;color:#16365C;white-space:pre-wrap">{esc(sogno)}'
          f'<div style="margin-top:8mm;text-align:center;font-family:Calibri,Carlito,Arial,sans-serif;font-style:normal;'
          f'font-weight:700;font-size:14pt;color:#E8452C">{esc(autore)}</div></div>')
    return _pagina("sogno-pg", c)


# ----------------------------------------------------------------------------- IMMAGINE INTERNA ED ESTERNA
IMMAGINE_VOCI = ["Pulizia, ordine, organizzazione", "Immagine", "Armonia cromatica", "Vetrina",
                 "Comunicazione web", "Armonia ed energia percepita"]
POSTAZIONI = [("stilistico", "Stilistico"), ("tecnico", "Tecnico"), ("lavaggio", "Lavaggio"), ("consulenza", "Consulenza")]


def giudizio(media):
    """Etichetta sotto "MEDIA AREA": >=8 OTTIMO, >=6,5 BUONO, >=5 SUFFICIENTE, altrimenti INSUFFICIENTE."""
    return "OTTIMO" if media >= 8 else "BUONO" if media >= 6.5 else "SUFFICIENTE" if media >= 5 else "INSUFFICIENTE"


def pagina_immagine(dati_salone, nome_salone):
    d = dati_salone or {}
    if not d:
        return None
    imm = d.get("immagine") or {}
    pos = d.get("postazioni") or {}
    team = d.get("numero_team")
    c = _fascia(1.1, 2.3, 207.2, 3.6, 17.3, "IMMAGINE INTERNA ED ESTERNA", nome_salone, 205.8, 25.9, 7.6, 9)
    # numero componenti + titolo
    c += _box(20, 43, 100, 52, "NUMERO COMPONENTI TEAM", "", 10.6, "font-weight:700;background:none;border:0;justify-content:flex-end;padding-right:2mm")
    c += _box(100.6, 42.5, 172, 52, esc(_num(team)) if team is not None else "", "", 12, "background:none;border:0;justify-content:flex-start;padding-left:3mm")
    c += _box(60, 58, 150, 72, "SALONE", "", 20.1, "font-weight:700;background:none;border:0;color:#071420")
    # postazioni
    c += _box(16.4, 81.9, 80.7, 87.1, "POSTAZIONI", "lt", 9, "font-weight:700")
    for (k, lab), x in zip(POSTAZIONI, (17.0, 65.0, 112.6, 160.8)):
        v = pos.get(k)
        c += _box(x, 90.8, x + 32.1, 96.2, lab, "lt", 7.6)
        c += _box(x, 97.2, x + 32.1, 102.5, esc(_num(v)) if v is not None else "", "bd", 9)
    # immagine del salone
    c += _box(16.4, 110.9, 99.2, 115.9, "IMMAGINE DEL SALONE", "lt", 9, "font-weight:700")
    ys = (119.5, 126.2, 132.6, 139.2, 145.7, 152.2)
    valori = []
    for lab, y in zip(IMMAGINE_VOCI, ys):
        v = imm.get(lab)
        if isinstance(v, (int, float)):
            valori.append(float(v))
        c += _box(18.0, y, 56.0, y + 5.4, esc(lab), "bd", 6.7 if len(lab) > 22 else 7.6)
        c += _box(65.0, y, 97.2, y + 5.4, esc(_num(v)) if v is not None else "", "bd", 9)
    media = sum(valori) / len(valori) if valori else None
    c += _box(17.2, 168.9, 55.8, 177.3, "MEDIA AREA", "dk2", 12)
    c += _box(64.6, 168.9, 96.7, 177.3, esc(f"{media:.2f}".replace(".", ",")) if media is not None else "", "bd", 13.6)
    c += _box(162.0, 168.8, 194.1, 177.3, giudizio(media) if media is not None else "", "bd", 13.6) if media is not None else ""
    # materiale interno (testo libero del salone)
    mat = (d.get("materiale_salone") or "").strip()
    c += _box(112.6, 109.8, 195.5, 114.8, "MATERIALE INTERNO", "lt", 9, "font-weight:700")
    c += _box(113.0, 119.5, 194.3, 164.0, esc(mat), "bd tx", _fit(mat, 9, 8, 7))
    # materiale esterno
    c += _box(15.9, 182.3, 191.2, 187.4, "MATERIALE ESTERNO", "lt", 9, "font-weight:700")
    c += _box(15.9, 189.9, 95.9, 195.4, "VETRINE", "bd", 9, "font-weight:700")
    c += _box(111.9, 190.3, 191.9, 195.9, "SOCIAL", "bd", 9, "font-weight:700")
    vet = (d.get("materiale_vetrine") or "").strip()
    soc = (d.get("presenza_social") or "").strip()
    c += _box(15.9, 199.1, 95.9, 232.0, esc(vet), "bd tx", _fit(vet, 9, 8, 7))
    c += _box(111.9, 199.6, 191.3, 232.0, esc(soc), "bd tx", _fit(soc, 9, 8, 7))
    # brand
    brand = (d.get("brand") or "").strip()
    c += _box(14.9, 236.6, 53.5, 245.2, "BRAND", "dk2", 12)
    c += _box(63.5, 236.6, 191.3, 245.2, esc(brand), "bd", 11)
    # proposte di sviluppo
    prop = (d.get("proposte_sviluppo") or "").strip()
    c += _box(1.1, 269.5, 48.8, 289.4, "PROPOSTE SVILUPPO", "lt", 9, "font-weight:700")
    c += _box(51.4, 270.1, 206.4, 290.2, esc(prop), "bd tx", _fit(prop, 9, 8, 7))
    return _pagina("immagine", c)


# ----------------------------------------------------------------------------- ANALISI COLLABORATORI (una pagina a persona)
ETICA = [  # (titolo, x_label0, x_label1, x_val0, x_val1, [(chiave, etichetta, y)])
    ("ACCOGLIENZA", 13.5, 70.3, 73.8, 83.4, 12.7, 41.1, 41.7, 70.1, [
        ("Saluto", "Saluto/sorriso", 85.5), ("Controllo appuntamento in agenda", "Controllo appuntamento", 96.1),
        ("Routine accoglienza", "Routine accoglienza", 106.3), ("Comunicazione iniziative e promozioni", "Comunicazione iniziative", 116.5)]),
    ("CONSULENZA", 77.0, 134.0, 73.8, 83.4, 76.2, 104.6, 105.4, 133.9, [
        ("Connessione", "Connessione", 85.7), ("Ascolto", "Ascolto", 95.8),
        ("Capacità di capire i desideri", "Capacità di capire i desideri", 106.0),
        ("Capacità di consigliare", "Capacità di consigliare", 116.2), ("Condivisione", "Condivisione", 126.4)]),
    ("CONGEDO", 138.0, 195.0, 74.6, 84.2, 138.1, 166.5, 166.8, 195.3, [
        ("Verifica soddisfazione", "Verifica soddisfazione", 85.7), ("Propone/riconferma prodotti", "Propone/riconferme MAC", 95.8),
        ("Quantifica ed elenca servizi", "Quantifica e elenca servizi", 106.0),
        ("Fissa prossimo appuntamento", "Fissa prox appuntamento", 116.2), ("Prende abbigliamento", "Prende abbigliamento", 126.6)]),
]
TECNICA_COL = [("Lavaggio", "DETERGENZA E TRATTAMENTI", 57.3, 85.8), ("Colore", "COLORE", 88.9, 117.4),
               ("Taglio", "TAGLIO", 120.6, 149.1), ("Piega", "PIEGA", 151.3, 179.8)]
TECNICA_RIGHE = [("Tempi", "Tempi esecuzione", 163.4), ("Metodo", "Metodo", 173.8), ("Risultato", "Risultato finale", 184.2)]
IMMAGINE_PERS = [("Look coerente", "Look coerente con filosofia aziendale", 208.2), ("Capelli", "Capelli", 218.4),
                 ("Trucco/barba", "Trucco/barba", 228.4), ("Divisa", "Divisa", 238.8),
                 ("Eleganza e postura", "Eleganza postura", 249.0), ("Cura e pulizia spazi", "Cura e pulizia degli spazi/oggetti", 259.0)]
GIORNI_ABBR = {"Lunedì": "Lun", "Martedì": "Mar", "Mercoledì": "Mer", "Giovedì": "Gio", "Venerdì": "Ven", "Sabato": "Sab", "Domenica": "Dom"}


def pagina_collaboratore(c, nome_salone, idx):
    p = c.get("punteggi") or {}
    S = lambda k: _num(p.get(k))
    cli = " / ".join(c.get("CLIENTELA") or [])
    gg = c.get("GIORNI") or []
    giorni = "TUTTI" if len(gg) >= 7 else ", ".join(GIORNI_ABBR.get(g, g) for g in gg)
    ore = _num(c.get("ORE_LAVORATE")) if c.get("ORE_LAVORATE") not in (None, "") else ""
    out = _fascia(12.7, 0.8, 184.6, 15.0, 15.0, "ANALISI COLLABORATORI", nome_salone, 197.3, 22.4, 6.6, 7.9)
    out += _box(13, 30.3, 197.3, 38.3, f'<span style="font-weight:700">NOME OPERATORE:&nbsp;</span>{esc(c.get("NOME_OPERATORE"))}',
                "", 15.8, "background:none;border:0;color:#16365C")
    # tabella dati identificativi
    info = [((44.1, 48.9), ("RUOLO", (c.get("RUOLO") or "").upper()), ("CLIENTELA GESTITA", cli)),
            ((49.3, 54.1), ("GIORNI LAVORATI", giorni), ("ORE LAVORATE", ore))]
    for (y0, y1), (l1, v1), (l2, v2) in info:
        out += _box(14.4, y0, 57.4, y1, l1, "dk", 7.9) + _box(58.3, y0, 101.4, y1, esc(v1), "bd", 9.3 if len(v1) < 14 else 7.9)
        out += _box(107.8, y0, 150.7, y1, l2, "dk", 7.9) + _box(151.6, y0, 194.6, y1, esc(v2), "bd", 9.3)
    comp = (c.get("compenso_incentivi") or "").strip()
    out += _box(14.6, 54.5, 57.5, 59.3, "COMPENSO E INCENTIVI", "dk", 7.9) + _box(58.4, 54.5, 194.9, 59.3, esc(comp), "bd", 9.3)
    # professionalita' etica
    out += _box(14.7, 62.1, 196.0, 70.7, "PROFESSIONALITA' ETICA", "lb", 7.9)
    for tit, hx0, hx1, hy0, hy1, lx0, lx1, vx0, vx1, voci in ETICA:
        out += _box(hx0, hy0, hx1, hy1, tit, "bd", 7.9, "font-weight:700")
        for k, lab, y in voci:
            out += _box(lx0, y, lx1, y + 9.6, esc(lab), "bd", 7.9) + _box(vx0, y, vx1, y + 9.6, S(k), "bd", 10.5)
    # professionalita' tecnica
    out += _box(14.1, 140.8, 195.8, 148.2, "PROFESSIONALITA' TECNICA", "lb", 7.9)
    for serv, tit, x0, x1 in TECNICA_COL:
        out += _box(x0, 153.0, x1, 162.4, tit, "bd", 7.9, "font-weight:700")
        for k, _l, y in TECNICA_RIGHE:
            out += _box(x0, y, x1, y + 9.4, S(f"{serv}_{k}"), "bd", 7.9)
    for _k, lab, y in TECNICA_RIGHE:
        out += _box(26.8, y, 55.4, y + 9.4, lab, "bd", 7.9)
    # immagine personale
    out += _box(14.9, 197.7, 196.6, 205.1, "IMMAGINE PERSONALE", "lb", 7.9)
    for k, lab, y in IMMAGINE_PERS:
        out += _box(55.0, y, 124.4, y + 9.0, esc(lab), "bd", 7.9, "font-weight:700") + _box(126.0, y, 154.4, y + 9.0, S(k), "bd", 7.9)
    # proposte formative
    prop = (c.get("proposte_formative") or "").strip()
    out += _box(12.7, 272.8, 55.0, 291.1, "PROPOSTE FORMATIVE", "dk", 9.3)
    out += _box(55.8, 273.0, 196.5, 291.6, esc(prop), "bd tx", _fit(prop, 9.3, 8.3, 7.3), "color:#0B3041")
    return _pagina(f"p{idx}", out)

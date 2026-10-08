#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Monacelli Italy - report iscritti ai corsi, uno per ogni HM2I.

Legge il CSV dei ticket e la tabella di decodifica (riservata) e scrive in OUT
un file  tickets_agente_xxxxxx.html  per ogni HM2I della tabella, con un box
per corso (3 per riga): titolo e data in grande, sotto gli iscritti.
Con --upload i file vengono caricati via FTP (FTPS se possibile).

La tabella di decodifica NON va copiata nel repository: lo script la legge dal
percorso indicato (--tabella), cosi' la corrispondenza codice <-> HM2I resta
solo sul PC.

Uso:
  python genera_tickets.py --csv tickets_latest.csv --tabella TABELLA_DECODIFICA_riservata.txt --out out [--upload]

Credenziali FTP (solo con --upload), da variabili d'ambiente:
  FTP_HOST, FTP_USER, FTP_PASS, FTP_DIR (default /tickets), FTP_TLS (1 = FTPS, default 1)
"""
import argparse
import csv
import difflib
import html
import os
import re
import sys
import unicodedata
from collections import defaultdict
from datetime import date, datetime

MESI = ["gennaio", "febbraio", "marzo", "aprile", "maggio", "giugno", "luglio",
        "agosto", "settembre", "ottobre", "novembre", "dicembre"]
GIORNI = ["lunedì", "martedì", "mercoledì", "giovedì", "venerdì", "sabato", "domenica"]


def leggi_testo(path):
    raw = open(path, "rb").read()
    for enc in ("utf-8-sig", "cp1252"):
        try:
            return raw.decode(enc)
        except UnicodeDecodeError:
            pass
    return raw.decode("latin-1")


def norm(s):
    s = unicodedata.normalize("NFKD", s or "")
    s = "".join(c for c in s if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9]", "", s.lower())


def leggi_tabella(path):
    """-> lista di dict {cod, cognome, email, slug} dalla tabella di decodifica."""
    rx = re.compile(r"^\s*(\d+)\s+(.+?)\s{2,}(\S+@\S+)\s+(agente_[a-z0-9]+)\.html\s*$")
    agenti = []
    for riga in leggi_testo(path).splitlines():
        m = rx.match(riga)
        if m:
            agenti.append({"cod": m.group(1), "cognome": m.group(2).strip(),
                           "email": m.group(3), "slug": m.group(4)})
    if not agenti:
        sys.exit("Nessuna riga valida trovata in " + path)
    return agenti


def trova_agente(nome_hm2i, agenti):
    """Abbina il nome completo del CSV (es. 'Giancarlo di Pace') al cognome della tabella."""
    n = norm(nome_hm2i)
    migliori = sorted(agenti, key=lambda a: -len(norm(a["cognome"])))
    for a in migliori:                       # 1) il nome finisce con il cognome
        if n.endswith(norm(a["cognome"])):
            return a
    best, best_r = None, 0.0                 # 2) tollera refusi (Macchiarullo/Macchiarulo)
    for a in agenti:
        c = norm(a["cognome"])
        r = difflib.SequenceMatcher(None, n[-(len(c) + 1):], c).ratio()
        if r > best_r:
            best, best_r = a, r
    return best if best_r >= 0.85 else None


def parse_data(s):
    try:
        return datetime.strptime(s.strip(), "%d/%m/%Y").date()
    except ValueError:
        return None


def data_estesa(d, grezza):
    if not d:
        return grezza or "data da definire"
    return "%s %d %s %d" % (GIORNI[d.weekday()], d.day, MESI[d.month - 1], d.year)


def ingresso_valido(s):
    """True se Ingresso1 contiene una data valida (gg/mm/aaaa, con o senza ora)."""
    s = (s or "").strip()
    for fmt in ("%d/%m/%Y %H:%M", "%d/%m/%Y %H:%M:%S", "%d/%m/%Y"):
        try:
            datetime.strptime(s, fmt)
            return True
        except ValueError:
            pass
    return False


def esito(t):
    """Per i corsi passati: voto se presente, '--' se manca, 'X' se non c'e' un ingresso valido."""
    if not ingresso_valido(t.get("Ingresso1")):
        return "X"
    voto = (t.get("Voto") or "").strip()
    return voto if voto and voto != "-" else "--"


def bella(s):
    """'cinzia germino' -> 'Cinzia Germino' (solo se il CSV e' tutto maiuscolo/minuscolo)."""
    s = " ".join((s or "").split())
    return s.title() if s == s.lower() or s == s.upper() else s


def leggi_ticket(path):
    testo = leggi_testo(path)
    righe = csv.DictReader(testo.splitlines(), delimiter=";")
    return list(righe)


def carica_corsi(ticket):
    """-> {nome_hm2i: {id_corso: corso}}, ignorando i ticket non pagati/non attivi."""
    per_hm2i = defaultdict(dict)
    scartati = 0
    for t in ticket:
        hm2i = (t.get("HM2I") or "").strip()
        if not hm2i:
            scartati += 1
            continue
        if (t.get("Pagato") or "").strip().upper() != "SI" or (t.get("Attivo") or "").strip().upper() != "SI":
            continue
        idc = (t.get("#Corso") or "").strip()
        corso = per_hm2i[hm2i].setdefault(idc, {
            "id": idc,
            "titolo": " ".join((t.get("Corso") or "").split()),
            "luogo": " ".join((t.get("Luogo") or "").split()),
            "data_raw": (t.get("Data Corso") or "").strip(),
            "data": parse_data(t.get("Data Corso") or ""),
            "iscritti": [],
            "_visti": set(),
        })
        nome = bella(t.get("Nome") or "") or "(nome mancante)"
        salone = " ".join(((t.get("Gruppo") or "").strip() or (t.get("RagioneSociale") or "").strip()).split())
        chiave = ((t.get("Email") or "").strip().lower() or nome.lower())
        if chiave in corso["_visti"]:
            continue
        corso["_visti"].add(chiave)
        corso["iscritti"].append((nome, salone, esito(t)))
    return per_hm2i, scartati


CSS = """
:root{--bg:#f4f1ec;--card:#fff;--ink:#1d1b19;--muted:#6f6a63;--line:#e4dfd7;--accent:#8a6a2f;--chip:#f1ece3}
@media (prefers-color-scheme:dark){:root{--bg:#161513;--card:#201e1b;--ink:#f1ede6;--muted:#a19a8f;--line:#33302b;--accent:#d2a85a;--chip:#2a2723}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:1280px;margin:0 auto;padding:24px 16px 48px}
header{margin-bottom:20px}
h1{font-size:22px;margin:0 0 4px}
.sub{color:var(--muted);font-size:13px}
h2{font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:28px 0 12px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
@media (max-width:980px){.grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:640px){.grid{grid-template-columns:1fr}}
.box{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;display:flex;flex-direction:column;min-width:0}
.titolo{font-size:20px;font-weight:700;line-height:1.2;margin:0 0 6px;overflow-wrap:anywhere}
.data{font-size:17px;font-weight:600;color:var(--accent)}
.luogo{font-size:13px;color:var(--muted);margin-top:2px}
.tot{margin:14px 0 8px;padding-top:12px;border-top:1px solid var(--line);font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.tot b{font-size:15px;color:var(--ink)}
ul{list-style:none;margin:0;padding:0}
.saloni>li{padding:8px 0;border-bottom:1px solid var(--line)}
.saloni>li:last-child{border-bottom:0}
.s{display:block;font-weight:700;overflow-wrap:anywhere}
.s+ul{margin-top:2px}
.n{font-weight:400;overflow-wrap:anywhere;padding:1px 0 1px 10px}
.n{display:flex;justify-content:space-between;gap:8px}
.n b{font-size:13px;min-width:24px;text-align:right}
.n b.x{color:#b3261e}.n b.nv{color:var(--muted)}.n b.v{color:var(--accent)}
.leg{font-size:12px;color:var(--muted);margin:-4px 0 12px}
.passati .box{opacity:.92}
.vuoto{color:var(--muted);padding:24px 0}
"""


def riga(nome, es, passato):
    if not passato:
        return '<li class="n">%s</li>' % html.escape(nome)
    cl = "x" if es == "X" else ("nv" if es == "--" else "v")
    return '<li class="n"><span>%s</span><b class="%s">%s</b></li>' % (html.escape(nome), cl, html.escape(es))


def box(c, passato=False):
    e = html.escape
    gruppi = {}
    for nome, salone, es in c["iscritti"]:
        chiave = norm(salone)
        gruppi.setdefault(chiave, [salone or "Senza salone", []])[1].append((nome, es))
    # saloni in ordine alfabetico ("Senza salone" in fondo), partecipanti in ordine alfabetico
    ordinati = sorted(gruppi.items(), key=lambda kv: (kv[0] == "", kv[1][0].lower()))
    blocchi = "".join('<li><span class="s">%s</span><ul>%s</ul></li>' % (
        e(salone), "".join(riga(n, es, passato) for n, es in sorted(nomi, key=lambda x: x[0].lower())))
        for _, (salone, nomi) in ordinati)
    luogo = '<div class="luogo">%s</div>' % e(c["luogo"]) if c["luogo"] else ""
    return ('<section class="box"><h3 class="titolo">%s</h3><div class="data">%s</div>%s'
            '<div class="tot">Iscritti <b>%d</b></div><ul class="saloni">%s</ul></section>') % (
        e(c["titolo"]), e(data_estesa(c["data"], c["data_raw"])), luogo, len(c["iscritti"]), blocchi)


def pagina(agente, corsi, oggi, aggiornato):
    prossimi = sorted([c for c in corsi if not c["data"] or c["data"] >= oggi],
                      key=lambda c: (c["data"] or date.max, c["titolo"]))
    passati = sorted([c for c in corsi if c["data"] and c["data"] < oggi],
                     key=lambda c: (c["data"], c["titolo"]), reverse=True)
    parti = []
    if prossimi:
        parti.append('<h2>Prossimi corsi</h2><div class="grid">%s</div>' % "".join(box(c) for c in prossimi))
    if passati:
        parti.append('<h2>Corsi passati</h2><p class="leg">Voto del partecipante &middot; <b>--</b> presente, voto non assegnato &middot; <b>X</b> assente (ingresso non registrato)</p><div class="grid passati">%s</div>' % "".join(box(c, True) for c in passati))
    if not parti:
        parti.append('<p class="vuoto">Nessun iscritto al momento.</p>')
    return """<!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Iscritti ai corsi - %(nome)s</title>
<style>%(css)s</style></head><body><div class="wrap">
<header><h1>Iscritti ai corsi - %(nome)s</h1>
<div class="sub">Monacelli Italy &middot; aggiornato il %(agg)s</div></header>
%(corpo)s
</div></body></html>
""" % {"nome": html.escape(agente["cognome"]), "css": CSS, "agg": aggiornato, "corpo": "\n".join(parti)}


def carica_ftp(cartella, file_list):
    import ftplib
    host, user, pwd = (os.environ.get(k) for k in ("FTP_HOST", "FTP_USER", "FTP_PASS"))
    if not (host and user and pwd):
        sys.exit("Upload: servono FTP_HOST, FTP_USER, FTP_PASS")
    dest = os.environ.get("FTP_DIR", "/tickets")
    tls = os.environ.get("FTP_TLS", "1") != "0"
    ftp = ftplib.FTP_TLS(host, timeout=60) if tls else ftplib.FTP(host, timeout=60)
    ftp.login(user, pwd)
    if tls:
        ftp.prot_p()
    try:
        ftp.cwd(dest)
    except ftplib.error_perm:
        ftp.mkd(dest)
        ftp.cwd(dest)
    for nome in file_list:
        with open(os.path.join(cartella, nome), "rb") as f:
            ftp.storbinary("STOR " + nome, f)
        print("caricato", nome)
    ftp.quit()


def main():
    qui = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--csv", default=os.path.join(qui, "tickets_latest.csv"))
    ap.add_argument("--tabella", default=os.path.join(qui, "TABELLA_DECODIFICA_riservata.txt"))
    ap.add_argument("--out", default=os.path.join(qui, "out"))
    ap.add_argument("--upload", action="store_true", help="carica i file via FTP")
    a = ap.parse_args()

    agenti = leggi_tabella(a.tabella)
    ticket = leggi_ticket(a.csv)
    per_hm2i, senza_hm2i = carica_corsi(ticket)

    corsi_agente = defaultdict(list)
    for nome, corsi in per_hm2i.items():
        ag = trova_agente(nome, agenti)
        if not ag:
            print("ATTENZIONE: HM2I '%s' non presente nella tabella, ticket ignorati" % nome, file=sys.stderr)
            continue
        corsi_agente[ag["slug"]].extend(corsi.values())

    os.makedirs(a.out, exist_ok=True)
    oggi = date.today()
    agg = datetime.now().strftime("%d/%m/%Y %H:%M")
    scritti = []
    for ag in agenti:
        nome_file = "tickets_%s.html" % ag["slug"]
        corsi = corsi_agente.get(ag["slug"], [])
        # 'cod' condiviso (es. 129): il report e' per persona, quindi per slug.
        with open(os.path.join(a.out, nome_file), "w", encoding="utf-8", newline="\n") as f:
            f.write(pagina(ag, corsi, oggi, agg))
        scritti.append(nome_file)
        print("%s  %-12s %3d corsi, %4d iscritti" % (nome_file, ag["cognome"], len(corsi),
                                                      sum(len(c["iscritti"]) for c in corsi)))
    # pagina vuota per non mostrare l'elenco della cartella
    with open(os.path.join(a.out, "index.html"), "w", encoding="utf-8") as f:
        f.write('<!DOCTYPE html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Monacelli Italy</title>')
    scritti.append("index.html")
    print("Ticket senza HM2I (non assegnati a nessun report): %d" % senza_hm2i)

    if a.upload:
        carica_ftp(a.out, scritti)


if __name__ == "__main__":
    main()

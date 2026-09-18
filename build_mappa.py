#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Rigenera mappa_clienti_italia.html a partire da corpo.csv (aggregato mensile)
e dalla cache di geocodifica clienti_geocodificati.csv.

Da lanciare nella cartella EXPORT, dopo che pubblica_report.bat ha copiato
corpo_export_aggregato_mese.csv in corpo.csv. Non tocca gli altri report.

Logica (identica a quella usata per la prima versione della mappa):
  1. Attivi ultimi 12 mesi: righe di corpo.csv con UltimaData >= (data piu'
     recente nel file - 365 giorni).
  2. Fatturato per linea (SottoFam) aggregato per Cod_cliente sulle sole
     righe entro i 12 mesi.
  3. Esclusi i clienti con fatturato totale 12 mesi < SOGLIA_FATTURATO.
  4. Nome/citta'/provincia/coordinate presi dalla cache clienti_geocodificati.csv
     (chiave Cod_cliente). Un cliente attivo ma assente dalla cache viene
     escluso dalla mappa e segnalato a schermo (va geocodificato a mano con
     geocodifica_indirizzi.html e aggiunto alla cache).
  5. Il codice HM2I usato e' quello della riga piu' recente del cliente
     entro la finestra dei 12 mesi (l'agente puo' cambiare nel tempo).
"""

import csv
import datetime
import json
import re
import sys
from pathlib import Path

EXPORT_DIR = Path(__file__).resolve().parent
CORPO_CSV = EXPORT_DIR / "corpo.csv"
CACHE_CSV = EXPORT_DIR / "clienti_geocodificati.csv"
MAPPA_HTML = EXPORT_DIR / "mappa_clienti_italia.html"

SOGLIA_FATTURATO = 880.0
GIORNI_FINESTRA = 365


def log(msg):
    print(msg, flush=True)


def parse_float(s):
    s = (s or "").strip().replace(",", ".")
    return float(s) if s else 0.0


def leggi_corpo(path):
    righe = []
    with open(path, encoding="utf-8-sig") as f:
        reader = csv.DictReader(f, delimiter=";")
        for row in reader:
            cod = (row.get("Cod_cliente") or "").strip()
            if not cod:
                continue
            data_str = (row.get("UltimaData") or "").strip()
            try:
                data = datetime.date.fromisoformat(data_str)
            except ValueError:
                continue
            righe.append({
                "cod": int(cod),
                "hm2i": (row.get("HM2I") or "").strip(),
                "sottofam": (row.get("SottoFam") or "").strip(),
                "imp": parse_float(row.get("ImpNetto")),
                "data": data,
            })
    return righe


def leggi_cache(path):
    cache = {}
    if not path.exists():
        return cache
    with open(path, encoding="utf-8-sig") as f:
        reader = csv.DictReader(f, delimiter=";")
        for row in reader:
            cod = (row.get("Cod_cliente") or "").strip()
            if not cod:
                continue
            try:
                lat = float(row["Lat"])
                lng = float(row["Lng"])
            except (KeyError, ValueError):
                continue
            cache[int(cod)] = {
                "nome": (row.get("Ragione_Sociale") or "").strip(),
                "citta": (row.get("Citta") or "").strip(),
                "prov": (row.get("Provincia") or "").strip(),
                "lat": lat,
                "lon": lng,
                "geo_precisione": (row.get("Precisione_geocoding") or "").strip(),
            }
    return cache


def calcola_clienti(righe, cache):
    if not righe:
        raise SystemExit("corpo.csv vuoto o non leggibile: nessuna riga valida trovata")

    data_max = max(r["data"] for r in righe)
    finestra_inizio = data_max - datetime.timedelta(days=GIORNI_FINESTRA)
    log(f"Data piu' recente nei dati: {data_max.isoformat()}")
    log(f"Finestra 12 mesi: dal {finestra_inizio.isoformat()} al {data_max.isoformat()}")

    per_cliente = {}
    for r in righe:
        if r["data"] < finestra_inizio:
            continue
        c = per_cliente.setdefault(r["cod"], {"linee": {}, "totale": 0.0, "hm2i": r["hm2i"], "ultima": r["data"]})
        c["linee"][r["sottofam"]] = round(c["linee"].get(r["sottofam"], 0.0) + r["imp"], 2)
        c["totale"] = round(c["totale"] + r["imp"], 2)
        if r["data"] >= c["ultima"]:
            c["ultima"] = r["data"]
            c["hm2i"] = r["hm2i"]

    attivi = {cod: c for cod, c in per_cliente.items() if c["totale"] >= SOGLIA_FATTURATO}
    log(f"Righe totali: {len(righe)} / clienti con almeno una riga in finestra: {len(per_cliente)} / "
        f"clienti sopra soglia {SOGLIA_FATTURATO:.0f}EUR: {len(attivi)}")

    clients = []
    mancanti_cache = []
    for cod, c in sorted(attivi.items()):
        info = cache.get(cod)
        if not info:
            mancanti_cache.append((cod, c["totale"]))
            continue
        clients.append({
            "id": cod,
            "nome": info["nome"],
            "citta": info["citta"],
            "prov": info["prov"],
            "hm2i": c["hm2i"],
            "lat": round(info["lat"], 6),
            "lon": round(info["lon"], 6),
            "totale": c["totale"],
            "linee": c["linee"],
            "geo_precisione": info["geo_precisione"],
        })

    if mancanti_cache:
        log("")
        log(f"ATTENZIONE: {len(mancanti_cache)} cliente/i attivo/i ma senza coordinate in cache, esclusi dalla mappa:")
        for cod, tot in mancanti_cache:
            log(f"  - Cod_cliente {cod}  (fatturato 12 mesi: {tot:.2f} EUR)")
        log("Geocodificali con geocodifica_indirizzi.html e aggiungili a clienti_geocodificati.csv, poi rilancia.")
        log("")

    return clients


def aggiorna_html(clients, html_path):
    html = html_path.read_text(encoding="utf-8")
    m = re.search(r"const CLIENTS = (\[.*?\]);\n", html, re.S)
    if not m:
        raise SystemExit(f"Non trovo 'const CLIENTS = [...]' in {html_path}, file inatteso")

    nuovo_json = json.dumps(clients, ensure_ascii=False, separators=(", ", ": "))
    nuovo_html = html[:m.start(1)] + nuovo_json + html[m.end(1):]

    meta_pattern = re.compile(r"(\d+) clienti &middot; colore per agente")
    nuovo_html = meta_pattern.sub(f"{len(clients)} clienti &middot; colore per agente", nuovo_html)

    html_path.write_text(nuovo_html, encoding="utf-8")


def main():
    if not CORPO_CSV.exists():
        raise SystemExit(f"ERRORE: {CORPO_CSV} non trovato")
    if not MAPPA_HTML.exists():
        raise SystemExit(f"ERRORE: {MAPPA_HTML} non trovato (serve come template)")

    righe = leggi_corpo(CORPO_CSV)
    cache = leggi_cache(CACHE_CSV)
    if not cache:
        log(f"ATTENZIONE: cache coordinate {CACHE_CSV} vuota o assente, nessun cliente verra' mostrato")

    clients = calcola_clienti(righe, cache)
    aggiorna_html(clients, MAPPA_HTML)
    log(f"OK: mappa_clienti_italia.html aggiornata con {len(clients)} clienti")


if __name__ == "__main__":
    try:
        main()
    except SystemExit as e:
        log(str(e))
        sys.exit(1)

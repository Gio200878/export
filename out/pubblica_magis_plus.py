# -*- coding: utf-8 -*-
"""
Monacelli Italy - supporto a pubblica_magis_plus.bat

Due comandi, chiamati dal .bat (non serve lanciarli a mano):

  prepara  <cartella_output> <cartella_pubblicati>
      Cerca in <cartella_output> i PDF creati da magis_plus.py (Magis_Plus_<codice>_<nome>.pdf),
      ne tiene uno per codice (il piu' recente) e lo copia in <cartella_pubblicati> con un nome
      pubblico NON indovinabile: magis_plus_<codice>_<8 caratteri casuali>.pdf.
      Il nome casuale di un codice resta lo stesso a ogni pubblicazione, quindi il link non cambia.
      Scrive in <cartella_pubblicati>\\da_caricare.txt l'elenco dei file nuovi o aggiornati.

  registra <cartella_pubblicati> <indirizzo_servizio> <file_password> <file_caricati>
      Segna come pubblicati i file elencati in <file_caricati> (quelli caricati con successo)
      e comunica al servizio Google l'elenco completo dei Magis Plus pubblicati: e' da li' che la
      pagina "Riepilogo saloni" prende i link (visibili solo dopo aver inserito la password).

Solo libreria standard di Python 3.
"""
import datetime as dt
import json
import os
import re
import secrets
import shutil
import sys
import urllib.error
import urllib.request

# Magis_Plus_<codice>_<nome>.pdf   oppure   Magis_Plus_<codice>.pdf
PDF_RE = re.compile(r"^Magis_Plus_([0-9A-Za-z-]+)(?:_.*)?\.pdf$")


def adesso():
    return dt.datetime.now().isoformat(timespec="seconds")


def carica_indice(stage):
    try:
        with open(os.path.join(stage, "indice.json"), encoding="utf-8") as f:
            d = json.load(f)
            return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def salva_indice(stage, idx):
    tmp = os.path.join(stage, "indice.json.tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(idx, f, ensure_ascii=False, indent=2)
    os.replace(tmp, os.path.join(stage, "indice.json"))


def prepara(out, stage):
    os.makedirs(stage, exist_ok=True)
    migliori = {}   # codice -> (data modifica, percorso, nome)
    for nome in os.listdir(out):
        m = PDF_RE.match(nome)
        if not m or nome.startswith("_tmp_") or nome[:-4].endswith("_v2"):
            continue
        percorso = os.path.join(out, nome)
        mt = os.path.getmtime(percorso)
        codice = m.group(1)
        if codice not in migliori or mt > migliori[codice][0]:
            migliori[codice] = (mt, percorso, nome)

    idx = carica_indice(stage)
    da_caricare = []
    for codice, (mt, percorso, nome) in sorted(migliori.items()):
        e = idx.get(codice) or {}
        token = e.get("token") or secrets.token_hex(4)
        file = f"magis_plus_{codice}_{token}.pdf"
        if e.get("sorgente_mtime") != mt or not e.get("caricato_il") or not os.path.exists(os.path.join(stage, file)):
            shutil.copy2(percorso, os.path.join(stage, file))
            e.update(token=token, file=file, sorgente=nome, sorgente_mtime=mt,
                     aggiornato=dt.datetime.fromtimestamp(mt).isoformat(timespec="seconds"), caricato_il=None)
            da_caricare.append(file)
        idx[codice] = e
    salva_indice(stage, idx)
    with open(os.path.join(stage, "da_caricare.txt"), "w", encoding="utf-8") as f:
        f.write("".join(n + "\n" for n in da_caricare))
    print(f"PDF trovati: {len(migliori)} - da caricare: {len(da_caricare)}")
    for n in da_caricare:
        print("  da caricare", n)
    return 0


def registra(stage, endpoint, file_password, file_caricati):
    idx = carica_indice(stage)
    try:
        with open(file_caricati, encoding="utf-8") as f:
            caricati = {r.strip() for r in f if r.strip()}
    except OSError:
        caricati = set()
    for e in idx.values():
        if e.get("file") in caricati:
            e["caricato_il"] = adesso()
    salva_indice(stage, idx)

    voci = [{"codice": c, "file": e["file"], "aggiornato": e.get("aggiornato", "")}
            for c, e in sorted(idx.items()) if e.get("caricato_il") and e.get("file")]
    if not voci:
        print("Nessun Magis Plus pubblicato da registrare.")
        return 0
    try:
        with open(file_password, encoding="utf-8-sig") as f:
            chiave = f.readline().strip()
    except OSError:
        print(f"ERRORE: file password non trovato: {file_password}")
        return 1
    if not chiave:
        print(f"ERRORE: il file password e' vuoto: {file_password}")
        return 1
    corpo = json.dumps({"action": "admin_magisplus", "key": chiave, "voci": voci}).encode("utf-8")
    # text/plain come fanno le pagine del sito: Apps Script risponde con un reindirizzamento, che urllib segue
    richiesta = urllib.request.Request(endpoint, data=corpo, headers={"Content-Type": "text/plain;charset=utf-8"})
    try:
        with urllib.request.urlopen(richiesta, timeout=90) as r:
            risp = json.loads(r.read().decode("utf-8"))
    except (urllib.error.URLError, ValueError, OSError) as e:
        print(f"ERRORE: registrazione sul servizio non riuscita: {e}")
        return 1
    if not risp.get("ok"):
        print(f"ERRORE: il servizio ha risposto: {risp.get('error', risp)}")
        return 1
    print(f"Registrati nel Riepilogo saloni: {risp.get('registrati', len(voci))} Magis Plus")
    return 0


def main(argv):
    try:
        if len(argv) == 4 and argv[1] == "prepara":
            return prepara(argv[2], argv[3])
        if len(argv) == 6 and argv[1] == "registra":
            return registra(argv[2], argv[3], argv[4], argv[5])
    except Exception as e:   # il .bat legge solo il codice di uscita
        print(f"ERRORE: {e}")
        return 1
    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))

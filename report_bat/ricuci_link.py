# -*- coding: utf-8 -*-
"""
Monacelli Italy - ricuce i link dentro le copie datate e di fine mese.

Il quadro di direzione porta il link al report di ogni area e di ogni agente.
Nel file corrente quel link e' al nome fisso (area_68js2m.html), che e' sempre
la versione di oggi. Le copie datate e quelle di fine mese pero' nascono da un
copy del file corrente, quindi si porterebbero dietro lo stesso link: aprendo
dallo storico la direzione del 12 settembre e cliccando un'area si finirebbe
sui numeri di oggi, non su quelli del 12.

Questo script gira subito dopo le copie e, dentro ogni copia, fa puntare i
link alla copia con lo stesso suffisso: nel file _2026-09-12 i link portano ai
_2026-09-12, nel file _agosto portano agli _agosto. Il file corrente non viene
mai toccato.

Uso:  python ricuci_link.py <suffisso> <nome> [<nome> ...]
      python ricuci_link.py 2026-09-18 direzione_wy4b95 area_68js2m ...
      python ricuci_link.py agosto direzione_wy4b95 area_68js2m ...

I nomi sono senza estensione e li passa pubblica_report.bat con %TUTTI%, che
resta l'unico elenco da tenere aggiornato: qui dentro non ce n'e' una copia.

Ricuce sia gli href="..." sia i link nei dati JSON della pagina (const LINKS e
"pagina"): il template attuale usa questi ultimi.

Esce con 1 se manca un file o se non si riesce a scrivere. Un file che non
contiene nessun link non e' un errore: i report di area e individuali non ne
hanno, e vengono lasciati com'erano.
"""

import io
import os
import re
import sys


def main(argv):
    if len(argv) < 3:
        print("   ERRORE: uso: ricuci_link.py <suffisso> <nome> [<nome> ...]")
        return 1

    suffisso = argv[1].strip()
    nomi = [n.strip() for n in argv[2:] if n.strip()]
    if not suffisso or not nomi:
        print("   ERRORE: suffisso o elenco dei nomi vuoto")
        return 1

    # Le sostituzioni: href="area_68js2m.html" -> href="area_68js2m_agosto.html".
    # Si cerca l'href per intero, non il solo nome del file, cosi' non si tocca
    # nessun altro punto della pagina in cui quel nome possa comparire.
    cambi = [('href="%s.html"' % n, 'href="%s_%s.html"' % (n, suffisso))
             for n in nomi]

    # Il report attuale non scrive piu' href="..." nell'HTML: i link stanno nei
    # dati JSON della pagina, nella riga "const LINKS = {...}" (aree e agenti) e
    # nel campo "pagina" del riquadro Progressus. Si ricuciono anche quelli.
    nomi_re = "|".join(re.escape(n) for n in nomi)
    pat_riga = re.compile(r'^(const LINKS = )(.*)$', re.M)
    pat_link = re.compile(r'"(%s)\.html"' % nomi_re)
    pat_pag = re.compile(r'("pagina": ")(%s)\.html"' % nomi_re)

    errori = 0
    ritoccati = 0
    for nome in nomi:
        copia = "%s_%s.html" % (nome, suffisso)
        if not os.path.exists(copia):
            print("   ERRORE: %s non trovato" % copia)
            errori += 1
            continue

        try:
            # I report sono scritti con il BOM (vedi build_report.py): letti con
            # utf-8-sig e riscritti con utf-8-sig il BOM resta dov'era.
            with io.open(copia, encoding="utf-8-sig", newline="") as f:
                testo = f.read()
        except Exception as e:
            print("   ERRORE: %s non leggibile: %s" % (copia, e))
            errori += 1
            continue

        fatti = 0
        for vecchio, nuovo in cambi:
            n = testo.count(vecchio)
            if n:
                testo = testo.replace(vecchio, nuovo)
                fatti += n

        m = pat_riga.search(testo)
        if m:
            riga, n = pat_link.subn(
                lambda x: '"%s_%s.html"' % (x.group(1), suffisso), m.group(2))
            testo = testo[:m.start(2)] + riga + testo[m.end(2):]
            fatti += n
        testo, n = pat_pag.subn(
            lambda x: '%s%s_%s.html"' % (x.group(1), x.group(2), suffisso), testo)
        fatti += n

        if not fatti:
            continue

        try:
            with io.open(copia, "w", encoding="utf-8-sig", newline="") as f:
                f.write(testo)
        except Exception as e:
            print("   ERRORE: %s non riscrivibile: %s" % (copia, e))
            errori += 1
            continue

        print("   %s: %d link ricuciti" % (copia, fatti))
        ritoccati += 1

    if errori:
        print("   ESITO: %d file in errore sul suffisso %s" % (errori, suffisso))
        return 1

    print("   link del suffisso %s a posto (%d file ritoccati su %d)"
          % (suffisso, ritoccati, len(nomi)))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

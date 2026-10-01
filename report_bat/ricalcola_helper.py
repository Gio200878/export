"""Supporto a ricalcola_mese_precedente.bat. Solo libreria standard.

  date [AAAA-MM]        scrive _ric.tmp: "<mese> <anno> <AAAA-MM-GG ultimo giorno>"
                        (senza argomento: il mese precedente a oggi)
  sql IN OUT AAAA-MM-GG copia la query SQL sostituendo GETDATE() & simili con
                        la data fissa; esce con 1 se non trova nessuna funzione-data
  verifica CSV AAAA MM  controlla che il csv contenga righe del mese richiesto
  ricuci SUFFISSO NOME... in ogni NOME_SUFFISSO.html (cartella corrente) fa puntare
                        i link del quadro (const LINKS) alle copie NOME_SUFFISSO.html
"""
import csv, datetime as dt, calendar, re, sys

MESI = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio',
        'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre']


def cmd_date(arg):
    oggi = dt.date.today()
    if arg:
        try:
            a, m = [int(x) for x in arg.split('-')]
            primo = dt.date(a, m, 1)
        except Exception:
            print('ERRORE: mese non valido, usa il formato AAAA-MM (es. 2026-09)')
            return 1
    else:
        primo = (oggi.replace(day=1) - dt.timedelta(days=1)).replace(day=1)
    ultimo = dt.date(primo.year, primo.month, calendar.monthrange(primo.year, primo.month)[1])
    if ultimo > oggi:
        ultimo = oggi
    open('_ric.tmp', 'w').write(f'{MESI[primo.month - 1]} {primo.year} {ultimo.isoformat()}')
    return 0


def cmd_sql(src, dst, giorno):
    raw = open(src, 'rb').read()
    if raw[:2] in (b'\xff\xfe', b'\xfe\xff'):
        enc = 'utf-16'
    else:
        enc = 'utf-8-sig'
        try:
            raw.decode(enc)
        except UnicodeDecodeError:
            enc = 'cp1252'
    testo = raw.decode(enc)
    dtm = f"CONVERT(datetime,'{giorno}T23:59:59',126)"
    dtm2 = f"CONVERT(datetime2,'{giorno}T23:59:59',126)"
    pat = re.compile(r'GETDATE\s*\(\s*\)|CURRENT_TIMESTAMP|SYSDATETIME\s*\(\s*\)', re.I)
    n = len(pat.findall(testo))
    if n == 0:
        print('ERRORE: nella query SQL non trovo GETDATE()/CURRENT_TIMESTAMP/SYSDATETIME().')
        print('        Non so come forzare il mese: servono due righe a mano nel .bat (vedi README).')
        return 1
    nuovo = pat.sub(lambda m: dtm2 if m.group(0).upper().startswith('SYSDATE') else dtm, testo)
    open(dst, 'wb').write(nuovo.encode(enc))
    print(f'query ricalcolata: {n} riferimenti alla data di oggi sostituiti con {giorno}')
    return 0


def cmd_verifica(path, anno, mese):
    anno, mese = int(anno), int(mese)
    n = 0
    tot = 0.0
    with open(path, encoding='utf-8-sig', errors='replace', newline='') as f:
        for r in csv.DictReader(f, delimiter=';'):
            try:
                if int(r['Anno']) == anno and int(r['Mese']) == mese:
                    n += 1
                    tot += float(r['ImpNetto'] or 0)
            except (ValueError, KeyError, TypeError):
                pass
    print(f'verifica csv: {n} righe di {MESI[mese - 1]} {anno}, ImpNetto totale {tot:,.2f}')
    if n == 0:
        print('ERRORE: il csv non contiene righe del mese richiesto, mi fermo.')
        return 1
    return 0


def cmd_ricuci(suffisso, *nomi):
    pat_riga = re.compile(r'^(const LINKS = )(.*)$', re.M)
    pat_link = re.compile(r'"((?:direzione|progressus|area|agente)_[a-z0-9]+)\.html"')
    ko = 0
    for nome in nomi:
        path = f'{nome}_{suffisso}.html'
        try:
            testo = open(path, encoding='utf-8-sig').read()
        except OSError:
            print(f'ERRORE: {path} non trovato')
            ko += 1
            continue
        m = pat_riga.search(testo)
        if not m:
            print(f'ERRORE: {path}: riga "const LINKS" non trovata')
            ko += 1
            continue
        riga, n = pat_link.subn(lambda x: f'"{x.group(1)}_{suffisso}.html"', m.group(2))
        testo = testo[:m.start(2)] + riga + testo[m.end(2):]
        # il riquadro Progressus ha il suo link ("pagina") fuori da LINKS
        testo, n2 = re.subn(r'("pagina": ")(progressus_[a-z0-9]+)\.html"',
                            lambda x: f'{x.group(1)}{x.group(2)}_{suffisso}.html"', testo)
        n += n2
        open(path, 'w', encoding='utf-8-sig', newline='').write(testo)
        print(f'{path}: {n} link ricuciti')
    return 1 if ko else 0


if __name__ == '__main__':
    c, a = sys.argv[1], sys.argv[2:]
    sys.exit({'date': lambda: cmd_date(a[0] if a else ''),
              'sql': lambda: cmd_sql(*a),
              'verifica': lambda: cmd_verifica(*a),
              'ricuci': lambda: cmd_ricuci(*a)}[c]())

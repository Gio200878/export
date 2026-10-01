RICALCOLA MESE PRECEDENTE
=========================
Copiare in C:\Users\Menichetti\Desktop\export :
  - ricalcola_mese_precedente.bat
  - ricalcola_helper.py

Uso (doppio clic oppure da prompt):
  ricalcola_mese_precedente.bat                -> mese precedente a oggi
  ricalcola_mese_precedente.bat 2026-09        -> un mese qualsiasi
  ricalcola_mese_precedente.bat 2026-09 noupload  -> solo in locale, niente sito

Chiede conferma (S/N), poi: rifa' la query con "oggi" bloccato all'ultimo giorno
del mese, genera i 22 report in export\ricalcolo_temp, salva e carica sul sito
SOLO <nome>_settembre.html e <nome>_settembre_2026.html (esempio).
Non tocca report correnti, copie datate, corpo_export_aggregato_mese.csv,
stato_report.json. Log: export\ricalcola_mese.log

Limiti noti:
 - Il blocco della data funziona se la query SQL usa GETDATE()/CURRENT_TIMESTAMP/
   SYSDATETIME(). Altrimenti si ferma con un errore e non pubblica nulla.
 - corpo_export_aggregato_mese_PROGRESSUS.csv e' copiato cosi' com'e': se lo
   genera un'altra estrazione, va rifatta per il mese voluto prima di lanciare.
 - Se il mese e' quello in corso, "oggi" resta la data di oggi.

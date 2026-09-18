@echo off
setlocal enabledelayedexpansion

rem ===============================================================
rem  Monacelli Italy - aggiorna la mappa clienti e la pubblica sul sito
rem  Indipendente da pubblica_report.bat: aggiorna solo mappa_clienti_italia.html
rem  a partire da corpo_export_aggregato_mese.csv e clienti_geocodificati.csv.
rem  Da lanciare DOPO aggregato_MESE.bat (Utilita' di pianificazione),
rem  cosi' come pubblica_report.bat.
rem ===============================================================

rem ----------------- DA CONFIGURARE ----------------------------------
rem Cartella locale sincronizzata con la cartella "export" di Google Drive
set "EXPORT=C:\Users\Menichetti\Desktop\export"

rem Comando python (se non e' nel PATH, metti il percorso completo)
set "PY=python"

rem Cartella di destinazione sul sito (deve finire con /)
set "REMOTO=ftp://ftp.monacelliitaly.it/www.monacelliitaly.it/report/"

rem File con le credenziali FTP, accanto a questo .bat (lo stesso usato
rem da pubblica_report.bat: se e' altrove aggiorna il percorso qui sotto)
set "CRED=%~dp0ftp_credenziali.txt"
rem --------------------------------------------------------------

set "LOG=%EXPORT%\aggiorna_mappa.log"

echo.>> "%LOG%"
echo ================================================>> "%LOG%"
"%PY%" -c "import datetime;print('AVVIO',datetime.datetime.now().isoformat(timespec='seconds'))">> "%LOG%" 2>&1

if not exist "%EXPORT%\" (
  echo ERRORE: cartella export non trovata: %EXPORT%>> "%LOG%"
  exit /b 1
)
if not exist "%CRED%" (
  echo ERRORE: file credenziali non trovato: %CRED%>> "%LOG%"
  exit /b 1
)

cd /d "%EXPORT%"

if not exist "build_mappa.py" (
  echo ERRORE: build_mappa.py mancante nella cartella export>> "%LOG%"
  exit /b 1
)
if not exist "mappa_clienti_italia.html" (
  echo ERRORE: mappa_clienti_italia.html mancante nella cartella export>> "%LOG%"
  exit /b 1
)
if not exist "corpo_export_aggregato_mese.csv" (
  echo ERRORE: corpo_export_aggregato_mese.csv mancante. aggregato_MESE.bat ha girato?>> "%LOG%"
  exit /b 1
)
if not exist "clienti_geocodificati.csv" (
  echo ERRORE: clienti_geocodificati.csv mancante, nessuna coordinata disponibile>> "%LOG%"
  exit /b 1
)

rem Il builder si aspetta il file di dati con il nome corpo.csv
copy /y "corpo_export_aggregato_mese.csv" "corpo.csv" >nul

rem --- rigenerazione della mappa ---------------------------------------
"%PY%" build_mappa.py>> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: build_mappa.py fallito, mappa NON aggiornata>> "%LOG%"
  exit /b 1
)

rem --- upload sul sito --------------------------------------------------
rem --ssl-reqd impone FTPS: se il server non lo supporta, togli l'opzione
rem (ma in quel caso la password viaggia in chiaro).
curl --ssl-reqd -k --silent --show-error --config "%CRED%" ^
   --upload-file "%EXPORT%\mappa_clienti_italia.html" "%REMOTO%mappa_clienti_italia.html">> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: upload di mappa_clienti_italia.html fallito>> "%LOG%"
  exit /b 1
)
echo caricata mappa_clienti_italia.html>> "%LOG%"

echo ESITO: mappa aggiornata e pubblicata correttamente>> "%LOG%"
exit /b 0

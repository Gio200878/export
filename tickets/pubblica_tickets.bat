@echo off
setlocal enabledelayedexpansion

rem ===============================================================
rem  Monacelli Italy - report iscritti ai corsi, uno per ogni HM2I
rem  (tickets_agente_xxxxxx.html) pubblicati su /tickets del sito.
rem  Da pianificare ogni notte, DOPO l'export di tickets_latest.csv.
rem  Stesse credenziali FTP di pubblica_report.bat.
rem ===============================================================

rem ----------------- DA CONFIGURARE ----------------------------------
rem Cartella locale "export" (la stessa di pubblica_report.bat)
set "EXPORT=C:\Users\Menichetti\Desktop\export"

rem Comando python
set "PY=python"

rem CSV dei ticket e tabella di decodifica.
rem ATTENZIONE: se la tabella sta dentro export viene sincronizzata su
rem Google Drive. Meglio tenerla in una cartella non sincronizzata.
set "CSV=%EXPORT%\tickets_latest.csv"
set "TABELLA=%EXPORT%\TABELLA_DECODIFICA_riservata.txt"

rem Cartella dove vengono scritti i report (locale)
set "OUT=%EXPORT%\tickets_out"

rem Destinazione sul sito (deve finire con /). La cartella viene creata se manca.
set "REMOTO=ftp://ftp.monacelliitaly.it/www.monacelliitaly.it/tickets/"

rem File con le credenziali FTP, accanto a questo .bat
set "CRED=%~dp0ftp_credenziali.txt"
rem --------------------------------------------------------------

set "LOG=%EXPORT%\pubblica_tickets.log"

echo.>> "%LOG%"
echo ================================================>> "%LOG%"
"%PY%" -c "import datetime;print('AVVIO',datetime.datetime.now().isoformat(timespec='seconds'))">> "%LOG%" 2>&1

if not exist "%CRED%" (
  echo ERRORE: file credenziali non trovato: %CRED%>> "%LOG%"
  exit /b 1
)
if not exist "%CSV%" (
  echo ERRORE: CSV non trovato: %CSV%>> "%LOG%"
  exit /b 1
)
if not exist "%TABELLA%" (
  echo ERRORE: tabella di decodifica non trovata: %TABELLA%>> "%LOG%"
  exit /b 1
)
if not exist "%~dp0genera_tickets.py" (
  echo ERRORE: genera_tickets.py non trovato accanto al .bat>> "%LOG%"
  exit /b 1
)

rem --- generazione ----------------------------------------------------
rem Prima di rigenerare svuoto la cartella: un file vecchio non deve essere
rem ricaricato se un agente esce dalla tabella.
if exist "%OUT%\" del /q "%OUT%\*.html" >nul 2>nul
"%PY%" "%~dp0genera_tickets.py" --csv "%CSV%" --tabella "%TABELLA%" --out "%OUT%">> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: generazione dei report tickets fallita>> "%LOG%"
  exit /b 1
)

rem --- upload ---------------------------------------------------------
rem Tutti i tickets_agente_*.html prodotti, piu' index.html (pagina vuota,
rem per non far vedere l'elenco della cartella).
set /a ERRORI=0
set /a CARICATI=0
for %%F in ("%OUT%\tickets_agente_*.html" "%OUT%\index.html") do (
  curl --ssl-reqd -k --silent --show-error --ftp-create-dirs --config "%CRED%" ^
     --upload-file "%%F" "%REMOTO%%%~nxF">> "%LOG%" 2>&1
  if errorlevel 1 (
    echo ERRORE: upload di %%~nxF fallito>> "%LOG%"
    set /a ERRORI+=1
  ) else (
    echo caricato %%~nxF>> "%LOG%"
    set /a CARICATI+=1
  )
)

if !CARICATI! EQU 0 (
  echo ERRORE: nessun file caricato>> "%LOG%"
  exit /b 1
)
if !ERRORI! GTR 0 (
  echo ESITO: !ERRORI! upload falliti>> "%LOG%"
  exit /b 1
)

echo ESITO: !CARICATI! file tickets pubblicati correttamente>> "%LOG%"
exit /b 0

@echo off
rem ---------------------------------------------------------------
rem  Genera i report tickets_agente_xxxxxx.html e li pubblica su
rem  www.monacelliitaly.it/tickets  (da schedulare ogni notte)
rem ---------------------------------------------------------------
setlocal
cd /d "%~dp0"

rem --- PERCORSI: modificare qui ---
set CSV=C:\export\tickets_latest.csv
set TABELLA=C:\export\TABELLA_DECODIFICA_riservata.txt
set OUT=%~dp0out

rem --- CREDENZIALI FTP: modificare qui (FTP_TLS=0 se il server non supporta FTPS) ---
set FTP_HOST=ftp.monacelliitaly.it
set FTP_USER=CAMBIAMI
set FTP_PASS=CAMBIAMI
set FTP_DIR=/www/tickets
set FTP_TLS=1

python genera_tickets.py --csv "%CSV%" --tabella "%TABELLA%" --out "%OUT%" --upload >> "%~dp0pubblica_tickets.log" 2>&1
if errorlevel 1 (
  echo %date% %time% ERRORE >> "%~dp0pubblica_tickets.log"
  exit /b 1
)
echo %date% %time% OK >> "%~dp0pubblica_tickets.log"
endlocal

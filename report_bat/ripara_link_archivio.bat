@echo off
setlocal enabledelayedexpansion
rem Ripara i link interni di un archivio mensile GIA' creato (senza rifare i dati)
rem e lo ricarica sul sito.   Uso: ripara_link_archivio.bat 2026-09 [noupload]
rem Mettere nella cartella export, accanto a ricalcola_helper.py.
set "EXPORT=C:\Users\Menichetti\Desktop\export"
set "PY=python"
set "REMOTO=ftp://ftp.monacelliitaly.it/www.monacelliitaly.it/report/"
set "CRED=%~dp0ftp_credenziali.txt"
if not exist "%CRED%" set "CRED=%EXPORT%\ftp_credenziali.txt"
set "TUTTI=direzione_wy4b95 progressus_be0yfx area_68js2m area_7o1rwl area_ucs9m5 area_8jsicn agente_q48sgj agente_nwu5j4 agente_g41is0 agente_cdaci6 agente_l5hnna agente_vpae69 agente_759pz1 agente_pqiwn0 agente_qzbu85 agente_rcxfs5 agente_4rcwni agente_qi133g agente_u4s3u3 agente_l8b8r9 agente_gc1ao2 agente_wkx73w"
set "LOG=%EXPORT%\ricalcola_mese.log"
cd /d "%EXPORT%"
del "_ric.tmp" >nul 2>nul
"%PY%" ricalcola_helper.py date "%~1"
if errorlevel 1 exit /b 1
for /f "tokens=1,2,3" %%A in (_ric.tmp) do (set "MESE=%%A" & set "ANNO=%%B")
del "_ric.tmp" >nul 2>nul
echo Riparo i link di !MESE! !ANNO! nei file *_!MESE!.html e *_!MESE!_!ANNO!.html
"%PY%" ricalcola_helper.py ricuci "!MESE!" %TUTTI%>> "%LOG%" 2>&1
if errorlevel 1 (echo ERRORE, vedi %LOG% & exit /b 1)
"%PY%" ricalcola_helper.py ricuci "!MESE!_!ANNO!" %TUTTI%>> "%LOG%" 2>&1
if errorlevel 1 (echo ERRORE, vedi %LOG% & exit /b 1)
if /i "%~2"=="noupload" (echo Fatto, upload saltato. & exit /b 0)
set /a ERRORI=0
for %%F in (%TUTTI%) do (
  for %%S in (!MESE! !MESE!_!ANNO!) do (
    curl --ssl-reqd -k --silent --show-error --config "%CRED%" --upload-file "%EXPORT%\%%F_%%S.html" "%REMOTO%%%F_%%S.html">> "%LOG%" 2>&1
    if errorlevel 1 (echo ERRORE upload %%F_%%S.html & set /a ERRORI+=1)
  )
)
if !ERRORI! GTR 0 exit /b 1
echo Fatto: link riparati e ricaricati.

@echo off
setlocal enabledelayedexpansion

rem ===============================================================
rem  Monacelli Italy - RICALCOLA IL MESE PRECEDENTE (o un mese a scelta)
rem
rem  Uso:
rem    ricalcola_mese_precedente.bat               mese precedente a oggi
rem    ricalcola_mese_precedente.bat 2026-09       un mese qualsiasi
rem    ricalcola_mese_precedente.bat 2026-09 noupload   solo in locale
rem
rem  Cosa fa: rifa' l'estrazione SQL del mese richiesto (con la data di
rem  "oggi" bloccata all'ultimo giorno del mese), rigenera tutti e 22 i
rem  report in una cartella di lavoro e li salva/pubblica SOLO come
rem  archivio del mese:  <nome>_<mese>.html  e  <nome>_<mese>_<anno>.html
rem
rem  NON tocca: i report correnti, le copie datate, il file
rem  corpo_export_aggregato_mese.csv del mese in corso, stato_report.json,
rem  piano_settimana.json. Si puo' lanciare in qualsiasi momento.
rem
rem  Mettere questo file e ricalcola_helper.py nella cartella export.
rem ===============================================================

rem ----------------- DA CONFIGURARE (uguali a pubblica_report.bat) ---
set "EXPORT=C:\Users\Menichetti\Desktop\export"
set "PY=python"
set "SQLFILE=%EXPORT%\query_exp_aggregato_MESE_con_NC_mese_intero.sql"
set "REMOTO=ftp://ftp.monacelliitaly.it/www.monacelliitaly.it/report/"
set "CRED=%~dp0ftp_credenziali.txt"
if not exist "%CRED%" set "CRED=%EXPORT%\ftp_credenziali.txt"
rem -------------------------------------------------------------------

set "PRINCIPALI=direzione_wy4b95 progressus_be0yfx area_68js2m area_7o1rwl area_ucs9m5 area_8jsicn"
set "AGENTI=agente_q48sgj agente_nwu5j4 agente_g41is0 agente_cdaci6 agente_l5hnna agente_vpae69 agente_759pz1 agente_pqiwn0 agente_qzbu85 agente_rcxfs5 agente_4rcwni agente_qi133g agente_u4s3u3 agente_l8b8r9 agente_gc1ao2 agente_wkx73w"
set "TUTTI=%PRINCIPALI% %AGENTI%"

set "RIF=%~1"
set "OPZ=%~2"
set "WORK=%EXPORT%\ricalcolo_temp"
set "LOG=%EXPORT%\ricalcola_mese.log"

if not exist "%EXPORT%\" (
  echo ERRORE: cartella export non trovata: %EXPORT%
  exit /b 1
)
cd /d "%EXPORT%"
if not exist "ricalcola_helper.py" (
  echo ERRORE: ricalcola_helper.py manca nella cartella export
  exit /b 1
)
if not exist "%SQLFILE%" (
  echo ERRORE: query SQL non trovata: %SQLFILE%
  exit /b 1
)
if not exist "%CRED%" if /i not "%OPZ%"=="noupload" (
  echo ERRORE: file credenziali FTP non trovato: %CRED%
  exit /b 1
)

echo.>> "%LOG%"
echo ================================================>> "%LOG%"
"%PY%" -c "import datetime;print('AVVIO ricalcolo',datetime.datetime.now().isoformat(timespec='seconds'))">> "%LOG%" 2>&1

rem --- quale mese -------------------------------------------------------
del "_ric.tmp" >nul 2>nul
"%PY%" ricalcola_helper.py date "%RIF%"
if errorlevel 1 exit /b 1
set "MESE="
set "ANNO="
set "FINE="
for /f "tokens=1,2,3" %%A in (_ric.tmp) do (
  set "MESE=%%A"
  set "ANNO=%%B"
  set "FINE=%%C"
)
del "_ric.tmp" >nul 2>nul
if not defined FINE (
  echo ERRORE: calcolo della data fallito
  exit /b 1
)
set "NUMMESE=!FINE:~5,2!"
set "ANNOQ=!FINE:~0,4!"

echo.
echo  Ricalcolo del mese:  !MESE! !ANNO!   (dati fino al !FINE!)
echo  Verranno SOVRASCRITTI in export e sul sito i file:
echo      *_!MESE!.html   e   *_!MESE!_!ANNO!.html
echo  I report correnti e le copie datate NON vengono toccati.
echo.
set "CONF="
set /p "CONF=Continuare? (S/N): "
if /i not "!CONF!"=="S" (
  echo Annullato.
  exit /b 0
)
echo ricalcolo !MESE! !ANNO! fino al !FINE!>> "%LOG%"

rem --- cartella di lavoro: copia di export senza html e log ---------------
if exist "%WORK%\" rmdir /s /q "%WORK%"
robocopy "%EXPORT%" "%WORK%" /E /XD "%WORK%" /XF *.html *.log piano_settimana.json stato_report.json >nul
if errorlevel 8 (
  echo ERRORE: copia della cartella di lavoro fallita>> "%LOG%"
  echo ERRORE: copia della cartella di lavoro fallita
  exit /b 1
)
del "%WORK%\corpo.csv" >nul 2>nul
cd /d "%WORK%"

rem --- estrazione SQL con la data bloccata -------------------------------
"%PY%" "%EXPORT%\ricalcola_helper.py" sql "%SQLFILE%" "%WORK%\query_ric.sql" !FINE!>> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: query non adattabile, vedi %LOG%
  type "%LOG%" | findstr /c:"ERRORE"
  goto :pulizia_errore
)

set "OUTFILE=%WORK%\corpo_export_aggregato_mese.csv"
echo Anno;Mese;Cod_cliente;HM2I;SottoFam;Qta;ImpNetto;NumFatture;UltimaData;ImpNetto_mese;> "%OUTFILE%"
sqlcmd -S localhost -U sa -P %SQLPWD% -d GAMMA -i "%WORK%\query_ric.sql" -s ";" -h -1 -W -f 65001 >> "%OUTFILE%" 2>> "%LOG%"
if errorlevel 1 (
  echo ERRORE: sqlcmd fallito>> "%LOG%"
  echo ERRORE: estrazione SQL fallita
  goto :pulizia_errore
)

"%PY%" "%EXPORT%\ricalcola_helper.py" verifica "%OUTFILE%" !ANNOQ! !NUMMESE!>> "%LOG%" 2>&1
set "VER=!errorlevel!"
"%PY%" "%EXPORT%\ricalcola_helper.py" verifica "%OUTFILE%" !ANNOQ! !NUMMESE!
if not "!VER!"=="0" goto :pulizia_errore

rem --- generazione dei report con "oggi" = ultimo giorno del mese ---------
set "OGGI=!FINE!"
set "FRESCO=1"
"%PY%" build_report.py>> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: generazione dei report fallita>> "%LOG%"
  echo ERRORE: generazione dei report fallita, vedi %LOG%
  goto :pulizia_errore
)
for %%A in (118 119 120 121 126 127 128 129 132 143 144 151 155 156 907 908) do (
  set "SOLO_HM2I=%%A"
  "%PY%" build_report.py>> "%LOG%" 2>&1
  if errorlevel 1 (
    echo ERRORE: report agente %%A fallito>> "%LOG%"
    echo ERRORE: report agente %%A fallito, vedi %LOG%
    goto :pulizia_errore
  )
)
set "SOLO_HM2I="
"%PY%" crea_progressus.py>> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: pagina Progressus non generata>> "%LOG%"
  echo ERRORE: pagina Progressus non generata, vedi %LOG%
  goto :pulizia_errore
)
set "OGGI="

for %%F in (%TUTTI%) do (
  if not exist "%WORK%\%%F.html" (
    echo ERRORE: %%F.html non e' stato generato>> "%LOG%"
    echo ERRORE: %%F.html non e' stato generato
    goto :pulizia_errore
  )
)

rem --- archivio del mese: le due copie, con i link ricuciti ---------------
for %%F in (%TUTTI%) do (
  copy /y "%%F.html" "%%F_!MESE!.html" >nul
  copy /y "%%F.html" "%%F_!MESE!_!ANNO!.html" >nul
)
"%PY%" ricuci_link.py "!MESE!" %TUTTI%>> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: link dell'archivio !MESE! non ricuciti>> "%LOG%"
  echo ERRORE: link dell'archivio !MESE! non ricuciti
  goto :pulizia_errore
)
"%PY%" ricuci_link.py "!MESE!_!ANNO!" %TUTTI%>> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: link dell'archivio annuale non ricuciti>> "%LOG%"
  echo ERRORE: link dell'archivio annuale non ricuciti
  goto :pulizia_errore
)

rem --- salvataggio in export ---------------------------------------------
for %%F in (%TUTTI%) do (
  copy /y "%WORK%\%%F_!MESE!.html" "%EXPORT%\%%F_!MESE!.html" >nul
  copy /y "%WORK%\%%F_!MESE!_!ANNO!.html" "%EXPORT%\%%F_!MESE!_!ANNO!.html" >nul
)
echo archivio !MESE! !ANNO! salvato in export>> "%LOG%"
echo Archivio !MESE! !ANNO! salvato in export.

rem --- upload ------------------------------------------------------------
if /i "%OPZ%"=="noupload" (
  echo Upload saltato ^(noupload^).
  echo upload saltato>> "%LOG%"
  goto :fine_ok
)
set /a ERRORI=0
for %%F in (%TUTTI%) do (
  curl --ssl-reqd -k --silent --show-error --config "%CRED%" ^
     --upload-file "%EXPORT%\%%F_!MESE!.html" "%REMOTO%%%F_!MESE!.html">> "%LOG%" 2>&1
  if errorlevel 1 (
    echo ERRORE: upload di %%F_!MESE!.html fallito>> "%LOG%"
    set /a ERRORI+=1
  ) else (
    echo caricato %%F_!MESE!.html>> "%LOG%"
  )
  curl --ssl-reqd -k --silent --show-error --config "%CRED%" ^
     --upload-file "%EXPORT%\%%F_!MESE!_!ANNO!.html" "%REMOTO%%%F_!MESE!_!ANNO!.html">> "%LOG%" 2>&1
  if errorlevel 1 (
    echo ERRORE: upload di %%F_!MESE!_!ANNO!.html fallito>> "%LOG%"
    set /a ERRORI+=1
  ) else (
    echo caricato %%F_!MESE!_!ANNO!.html>> "%LOG%"
  )
)
if !ERRORI! GTR 0 (
  echo ESITO: !ERRORI! upload falliti, vedi %LOG%>> "%LOG%"
  echo ATTENZIONE: !ERRORI! upload falliti, vedi %LOG%
  cd /d "%EXPORT%"
  rmdir /s /q "%WORK%"
  exit /b 1
)

:fine_ok
cd /d "%EXPORT%"
rmdir /s /q "%WORK%"
echo ESITO: ricalcolo di !MESE! !ANNO! completato>> "%LOG%"
echo.
echo Fatto: ricalcolo di !MESE! !ANNO! completato.
exit /b 0

:pulizia_errore
cd /d "%EXPORT%"
echo La cartella %WORK% e' stata lasciata per l'analisi.
exit /b 1

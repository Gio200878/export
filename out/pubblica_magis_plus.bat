@echo off
setlocal enabledelayedexpansion

rem ===============================================================
rem  Monacelli Italy - pubblica sul sito i MAGIS PLUS (PDF) creati con
rem  magis_plus.py e li collega alla pagina "Riepilogo saloni".
rem  Da lanciare DOPO aver creato uno o piu' Magis Plus con magis_plus.py
rem  (doppio clic, oppure "pubblica_magis_plus.bat auto" in una routine).
rem
rem  Cosa fa:
rem   1. cerca in "output" i PDF Magis_Plus_<codice>_<nome>.pdf (uno per
rem      codice, il piu' recente) e li copia in "pubblicati" con un nome
rem      pubblico non indovinabile: magis_plus_<codice>_<8 caratteri>.pdf
rem      (il nome di un codice resta lo stesso: il link non cambia mai);
rem   2. carica sul sito, via FTP, solo i PDF nuovi o aggiornati;
rem   3. comunica al servizio Google l'elenco dei Magis Plus pubblicati:
rem      la colonna "Magis Plus" del Riepilogo saloni mostra il link.
rem  Un upload fallito viene ritentato alla prossima esecuzione.
rem ===============================================================

rem ----------------- DA CONFIGURARE ----------------------------------
rem Cartella di magis_plus.py (di default quella di questo .bat)
set "BASE=%~dp0"

rem Cartella dove magis_plus.py scrive i PDF
set "OUT=%BASE%output"

rem Cartella di lavoro: copie con il nome pubblico e indice di cio' che e' online
set "STAGE=%BASE%pubblicati"

rem Comando python (se non e' nel PATH, metti il percorso completo)
set "PY=python"

rem Cartella di destinazione sul sito (deve finire con /).
rem Deve corrispondere a "magis-plus/" accanto alla pagina Riepilogo saloni,
rem cioe' https://www.monacelliitaly.it/progetti/magis-plus/
set "REMOTO=ftp://ftp.monacelliitaly.it/www.monacelliitaly.it/progetti/magis-plus/"

rem File con le credenziali FTP (stesso formato di pubblica_report.bat)
set "CRED=%BASE%ftp_credenziali.txt"

rem Indirizzo del servizio Google (lo stesso ENDPOINT_URL delle pagine del sito)
set "ENDPOINT=https://script.google.com/macros/s/AKfycbwI2Thi9vz-oUKLafmxXGvImS_cvQO3_t9eh5_F1cf0RO75BrGy8yldS5YxO7yl8blw/exec"

rem File di testo con la password della pagina Riepilogo saloni (una sola riga).
rem Tienilo solo su questo PC, non nella cartella sincronizzata con Drive.
set "PWFILE=%BASE%magis_plus_password.txt"

rem Script di supporto, accanto a questo .bat
set "HELPER=%BASE%pubblica_magis_plus.py"

set "LOG=%BASE%pubblica_magis_plus.log"
rem --------------------------------------------------------------

echo.>> "%LOG%"
echo ================================================>> "%LOG%"
"%PY%" -c "import datetime;print('AVVIO',datetime.datetime.now().isoformat(timespec='seconds'))">> "%LOG%" 2>&1

if not exist "%OUT%\" (
  echo ERRORE: cartella dei PDF non trovata: !OUT!
  echo ERRORE: cartella dei PDF non trovata: !OUT!>> "!LOG!"
  goto :fine_errore
)
if not exist "%CRED%" (
  echo ERRORE: file credenziali FTP non trovato: !CRED!
  echo ERRORE: file credenziali FTP non trovato: !CRED!>> "!LOG!"
  goto :fine_errore
)
if not exist "%PWFILE%" (
  echo ERRORE: file password non trovato: !PWFILE!
  echo ERRORE: file password non trovato: !PWFILE!>> "!LOG!"
  goto :fine_errore
)
if not exist "%HELPER%" (
  echo ERRORE: pubblica_magis_plus.py mancante accanto a questo .bat
  echo ERRORE: pubblica_magis_plus.py mancante accanto a questo .bat>> "!LOG!"
  goto :fine_errore
)

if not exist "%STAGE%\" mkdir "%STAGE%"
del "%STAGE%\da_caricare.txt" >nul 2>nul
del "%STAGE%\caricati.txt" >nul 2>nul

rem --- 1. quali PDF vanno pubblicati ----------------------------------
"%PY%" "%HELPER%" prepara "%OUT%" "%STAGE%">> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: preparazione dei PDF fallita, vedi !LOG!
  echo ERRORE: preparazione dei PDF fallita>> "!LOG!"
  goto :fine_errore
)

rem --- 2. upload sul sito ----------------------------------------------
rem --ssl-reqd impone FTPS: se il server non lo supporta, togli l'opzione
rem (ma in quel caso la password viaggia in chiaro).
rem --ftp-create-dirs crea la cartella magis-plus sul sito se non esiste.
set /a ERRORI=0
set /a CARICATI=0
if exist "%STAGE%\da_caricare.txt" (
  for /f "usebackq delims=" %%F in ("!STAGE!\da_caricare.txt") do (
    curl --ssl-reqd -k --silent --show-error --ftp-create-dirs --config "!CRED!" ^
       --upload-file "!STAGE!\%%F" "!REMOTO!%%F">> "!LOG!" 2>&1
    if errorlevel 1 (
      echo ERRORE: upload di %%F fallito>> "!LOG!"
      echo ERRORE: upload di %%F fallito
      set /a ERRORI+=1
    ) else (
      echo caricato %%F>> "!LOG!"
      echo caricato %%F
      echo %%F>> "!STAGE!\caricati.txt"
      set /a CARICATI+=1
    )
  )
)

rem --- 3. elenco dei Magis Plus pubblicati per il Riepilogo saloni ------
rem Si fa anche se non c'e' nulla di nuovo, cosi' l'elenco resta allineato.
if not exist "%STAGE%\caricati.txt" type nul > "%STAGE%\caricati.txt"
"%PY%" "%HELPER%" registra "%STAGE%" "%ENDPOINT%" "%PWFILE%" "%STAGE%\caricati.txt">> "%LOG%" 2>&1
if errorlevel 1 (
  echo ERRORE: registrazione nel Riepilogo saloni non riuscita, vedi !LOG!
  echo ERRORE: registrazione nel Riepilogo saloni non riuscita>> "!LOG!"
  set /a ERRORI+=1
)

if !ERRORI! GTR 0 (
  echo ESITO: !ERRORI! errori, !CARICATI! PDF caricati>> "!LOG!"
  echo.
  echo FINITO CON ERRORI: !ERRORI! - dettagli in !LOG!
  goto :fine_errore
)

echo ESITO: !CARICATI! PDF caricati, elenco del Riepilogo saloni aggiornato>> "%LOG%"
echo.
echo FATTO: !CARICATI! PDF caricati, elenco del Riepilogo saloni aggiornato.
if /i not "%~1"=="auto" pause
exit /b 0

:fine_errore
if /i not "%~1"=="auto" pause
exit /b 1

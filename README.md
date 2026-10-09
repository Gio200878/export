# HEMI Gestionale - Agenda Appuntamenti Educator

Webapp in PHP 8 + MySQL, stile calendario Google Calendar, per la gestione degli appuntamenti degli educator HEMI.

## Requisiti
- Hosting con PHP 8.0+ e MySQL 5.7+/MariaDB 10.3+ (va bene un piano Aruba con hosting Linux + database MySQL)
- Accesso FTP e phpMyAdmin (o accesso diretto al DB)

## Passi per la pubblicazione su Aruba

1. **Crea il database MySQL** dal pannello Aruba (o phpMyAdmin) e annota: host, nome database, utente, password.
2. **Carica via FTP** tutta la cartella `hemi_app/` nella directory del tuo dominio (es. `/web/htdocs/www.tuodominio.it/`).
3. Apri `config.php` e inserisci i dati del database:
   ```php
   define('DB_HOST', 'localhost'); // di solito è localhost su Aruba
   define('DB_NAME', 'nome_del_tuo_database');
   define('DB_USER', 'nome_utente_database');
   define('DB_PASS', 'password_database');
   ```
4. Visita `https://tuodominio.it/install.php` per creare automaticamente tutte le tabelle e i dati di base.
5. Fai login con:
   - **Login:** `admin`
   - **Password:** `admin123`
6. **Cambia subito la password** dell'admin dalla pagina Account.
7. **Elimina il file `install.php`** dal server via FTP (per sicurezza, non deve restare accessibile).

## Struttura del progetto

```
hemi_app/
├── config.php              # Configurazione DB e WhatsApp (DA MODIFICARE)
├── schema.sql              # Schema database + dati iniziali
├── install.php             # Installer automatico (eliminare dopo l'uso)
├── login.php / logout.php  # Autenticazione
├── index.php                # Home: calendario + filtri
├── gestione.php             # Admin: Aree intervento + Zone d'Italia
├── appuntamenti_admin.php   # Admin: elenco completo, approvazioni, compensi
├── accounts.php             # Admin: gestione utenti e ruoli
├── api.php                  # Endpoint AJAX (JSON) usato dal calendario
├── lib/
│   ├── db.php               # Connessione PDO
│   ├── auth.php             # Login/sessioni/permessi
│   ├── logic.php            # Query e regole di business condivise
│   ├── whatsapp.php         # Notifiche via Meta Cloud API
│   └── header.php           # Menu di navigazione
└── assets/
    ├── style.css
    └── app.js                # Calendario, filtri collegati, modali
```

## Ruoli

| Ruolo | Cosa vede | Cosa può fare |
|---|---|---|
| **Admin** | Tutto | Approva/rifiuta, modifica data/ora/compenso/rimborso, elimina, gestisce aree/zone/account |
| **Sector Manager** | Appuntamenti dei suoi HM2I | Inserisce appuntamenti per i suoi HM2I (stato "da approvare") |
| **HM2I** | I propri appuntamenti in dettaglio, gli altri come "Occupato" | Inserisce solo i propri appuntamenti (stato "da approvare") |
| **HEMI** | I propri appuntamenti in dettaglio, gli altri come "Occupato" | Sola visualizzazione |

Un HM2I può essere collegato a più Sector Manager (in base alle zone condivise) — il collegamento si gestisce dalla pagina Account, selezionando gli HM2I visibili per ciascun Sector Manager.

## Stati appuntamento e colori
- 🟡 **Da approvare** — inserito da Sector Manager o HM2I
- 🟢 **Approvato** — automatico se inserito da Admin, oppure dopo approvazione manuale
- 🔴 **Rifiutato** — impostato dall'Admin

## WhatsApp (opzionale)
In `config.php`, imposta `WA_ENABLED` a `true` e inserisci le credenziali Meta Cloud API (`WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`) per attivare le notifiche automatiche di nuovo appuntamento/approvazione/rifiuto. Finché resta `false`, i messaggi vengono comunque registrati nella tabella `wa_log` per test, ma non inviati realmente.

## Note di sicurezza
- Cambia subito la password admin di default.
- Elimina `install.php` dopo il primo utilizzo.
- Verifica che `display_errors` in `config.php` resti `0` in produzione.

## Configurazione (repository)
`config.php` contiene credenziali e **non è versionato**. Copia `config.example.php` in `config.php` e inserisci i tuoi dati (DB e token WhatsApp).

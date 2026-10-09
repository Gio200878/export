-- ============================================================
-- HEMI GESTIONALE - Schema Database MySQL
-- Compatibile con MySQL 5.7+ / MariaDB 10.3+ (Aruba hosting)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- ZONE D'ITALIA
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zone (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    attiva TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- AREE D'INTERVENTO
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aree_intervento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    colore VARCHAR(7) NOT NULL DEFAULT '#8a7968', -- colore identificativo per il calendario
    attiva TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ACCOUNT (utenti di ogni ruolo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    ruolo ENUM('admin','sector_manager','hm2i','hemi') NOT NULL,
    telefono VARCHAR(30) NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    login VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    ore_contratto DECIMAL(6,2) NULL,          -- ore da contratto (mensili/settimanali a scelta)
    compenso_orario DECIMAL(8,2) NULL,        -- compenso orario di riferimento
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- RELAZIONE: account HEMI <-> Zone (molti a molti)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS account_zone (
    account_id INT NOT NULL,
    zona_id INT NOT NULL,
    PRIMARY KEY (account_id, zona_id),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (zona_id) REFERENCES zone(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- RELAZIONE: account HEMI <-> Aree intervento (molti a molti)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS account_area (
    account_id INT NOT NULL,
    area_id INT NOT NULL,
    PRIMARY KEY (account_id, area_id),
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id) REFERENCES aree_intervento(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- RELAZIONE: Sector Manager <-> HM2I a lui collegati
-- Un HM2I può ricadere sotto più Sector Manager (zone condivise)
-- Questa tabella viene popolata/calcolata in base alle zone in comune,
-- ma la lasciamo esplicita per permettere override manuali dall'admin.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sector_hm2i (
    sector_manager_id INT NOT NULL,
    hm2i_id INT NOT NULL,
    PRIMARY KEY (sector_manager_id, hm2i_id),
    FOREIGN KEY (sector_manager_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (hm2i_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- APPUNTAMENTI
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appuntamenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    area_id INT NOT NULL,
    hm2i_id INT NOT NULL,             -- l'agente HM2I titolare dell'appuntamento
    hemi_id INT NULL,                 -- l'educator assegnato (se già noto)
    salone VARCHAR(200) NOT NULL,
    indirizzo VARCHAR(255) NOT NULL,
    telefono VARCHAR(30) NULL,
    ztl ENUM('si','no') NOT NULL DEFAULT 'no',
    note TEXT NULL,
    data_appuntamento DATE NOT NULL,
    ora_inizio TIME NOT NULL,
    ora_fine TIME NOT NULL,
    stato ENUM('da_approvare','approvato','rifiutato') NOT NULL DEFAULT 'da_approvare',
    compenso DECIMAL(8,2) NULL,
    rimborso_spese DECIMAL(8,2) NULL,
    creato_da INT NOT NULL,           -- account che ha inserito l'appuntamento
    approvato_da INT NULL,
    approvato_at TIMESTAMP NULL,
    motivo_rifiuto VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (area_id) REFERENCES aree_intervento(id),
    FOREIGN KEY (hm2i_id) REFERENCES accounts(id),
    FOREIGN KEY (hemi_id) REFERENCES accounts(id),
    FOREIGN KEY (creato_da) REFERENCES accounts(id),
    FOREIGN KEY (approvato_da) REFERENCES accounts(id),
    INDEX idx_data (data_appuntamento),
    INDEX idx_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- LOG NOTIFICHE WHATSAPP (facoltativo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wa_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appuntamento_id INT NULL,
    destinatario VARCHAR(30) NOT NULL,
    messaggio TEXT NOT NULL,
    esito VARCHAR(600) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- DATI DI BASE (esempio, modificabile da pannello ADMIN)
-- ------------------------------------------------------------
INSERT INTO zone (nome) VALUES
('Nord Ovest'), ('Nord Est'), ('Centro'), ('Sud'), ('Isole')
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO aree_intervento (nome, colore) VALUES
('Taglio', '#8a7968'),
('Colore', '#b08d57'),
('Detergenza e Trattamenti', '#6f8a7d'),
('Happycromia', '#a15c5c'),
('Gestione', '#5c6f8a')
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

-- Account admin di default (login: admin / password: DA CAMBIARE SUBITO)
-- Password hash generato con password_hash('admin123', PASSWORD_DEFAULT) — CAMBIARE DOPO IL PRIMO ACCESSO
INSERT INTO accounts (nome, cognome, ruolo, email, login, password_hash, attivo)
VALUES ('Admin', 'Principale', 'admin', 'admin@monacelli.it', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1)
ON DUPLICATE KEY UPDATE login=VALUES(login);

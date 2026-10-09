<?php
require_once __DIR__ . '/db.php';

/**
 * Effettua il login verificando login/password contro la tabella accounts.
 * Ritorna true/false.
 */
function auth_login(string $login, string $password): bool {
    $stmt = db()->prepare('SELECT * FROM accounts WHERE login = ? AND attivo = 1 LIMIT 1');
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_ruolo'] = $user['ruolo'];
    $_SESSION['user_nome'] = $user['nome'] . ' ' . $user['cognome'];
    return true;
}

function auth_logout(): void {
    $_SESSION = [];
    session_destroy();
}

function auth_check(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function auth_require_admin(): void {
    auth_check();
    if ($_SESSION['user_ruolo'] !== 'admin') {
        http_response_code(403);
        die('Accesso riservato agli amministratori.');
    }
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_user_role(): string {
    return $_SESSION['user_ruolo'] ?? '';
}

function current_user_name(): string {
    return $_SESSION['user_nome'] ?? '';
}

/**
 * Restituisce il record completo dell'utente corrente.
 */
function current_user(): ?array {
    static $u = null;
    if ($u === null && current_user_id()) {
        $stmt = db()->prepare('SELECT * FROM accounts WHERE id = ?');
        $stmt->execute([current_user_id()]);
        $u = $stmt->fetch() ?: null;
    }
    return $u;
}

/**
 * Elenco degli ID di zona associati all'utente corrente (per hm2i, hemi, sector_manager).
 */
function current_user_zone_ids(): array {
    $stmt = db()->prepare('SELECT zona_id FROM account_zone WHERE account_id = ?');
    $stmt->execute([current_user_id()]);
    return array_map('intval', array_column($stmt->fetchAll(), 'zona_id'));
}

/**
 * Elenco degli ID HM2I visibili/gestibili dall'utente corrente, in base al ruolo.
 * - admin: tutti gli HM2I
 * - sector_manager: gli HM2I collegati a lui in sector_hm2i
 * - hm2i: solo se stesso
 * - hemi: nessuno (non gestisce HM2I)
 */
function visible_hm2i_ids(): array {
    $role = current_user_role();
    if ($role === 'admin') {
        $stmt = db()->query("SELECT id FROM accounts WHERE ruolo = 'hm2i' AND attivo = 1");
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
    if ($role === 'sector_manager') {
        $stmt = db()->prepare('SELECT hm2i_id FROM sector_hm2i WHERE sector_manager_id = ?');
        $stmt->execute([current_user_id()]);
        return array_map('intval', array_column($stmt->fetchAll(), 'hm2i_id'));
    }
    if ($role === 'hm2i') {
        return [current_user_id()];
    }
    return [];
}

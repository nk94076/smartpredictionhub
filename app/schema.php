<?php
/**
 * Database schema + safe migrations, run by setup.php.
 * Safe to run repeatedly (IF NOT EXISTS / column checks).
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

function column_exists(string $table, string $column): bool
{
    return (bool)q('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column])->fetchColumn();
}

function run_migrations(): array
{
    $pdo = db();
    $log = [];
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $tables = [
        // ---------- Admin side ----------
        'admin_roles' => "CREATE TABLE IF NOT EXISTS admin_roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(60) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) $E",
        'admin_role_permissions' => "CREATE TABLE IF NOT EXISTS admin_role_permissions (
            role_id INT UNSIGNED NOT NULL,
            perm VARCHAR(60) NOT NULL,
            PRIMARY KEY (role_id, perm)
        ) $E",
        'admin_users' => "CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            last_login_ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) $E",
        'admin_user_permissions' => "CREATE TABLE IF NOT EXISTS admin_user_permissions (
            admin_id INT UNSIGNED NOT NULL,
            perm VARCHAR(60) NOT NULL,
            PRIMARY KEY (admin_id, perm)
        ) $E",
        'admin_ip_blocks' => "CREATE TABLE IF NOT EXISTS admin_ip_blocks (
            ip VARCHAR(45) NOT NULL PRIMARY KEY,
            fail_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_fail_at DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            INDEX idx_blocked_until (blocked_until)
        ) $E",
        'admin_login_log' => "CREATE TABLE IF NOT EXISTS admin_login_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            username VARCHAR(100) NOT NULL,
            success TINYINT(1) NOT NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_ip (ip)
        ) $E",
        'admin_activity_log' => "CREATE TABLE IF NOT EXISTS admin_activity_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NULL,
            admin_username VARCHAR(50) NULL,
            action VARCHAR(60) NOT NULL,
            target_type VARCHAR(30) NULL,
            target_id VARCHAR(64) NULL,
            details VARCHAR(500) NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_admin (admin_id)
        ) $E",

        // ---------- Site users ----------
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            phone VARCHAR(20) NULL,
            password_hash VARCHAR(255) NOT NULL,
            status ENUM('active','blocked') NOT NULL DEFAULT 'active',
            marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            reg_ip VARCHAR(45) NULL,
            reg_country VARCHAR(80) NULL,
            reg_country_code CHAR(2) NULL,
            reg_region VARCHAR(80) NULL,
            reg_city VARCHAR(80) NULL,
            reg_device VARCHAR(20) NULL,
            reg_browser VARCHAR(40) NULL,
            reg_os VARCHAR(40) NULL,
            reg_user_agent VARCHAR(255) NULL,
            reg_source VARCHAR(100) NULL,
            reg_medium VARCHAR(100) NULL,
            reg_campaign VARCHAR(150) NULL,
            reg_referrer VARCHAR(500) NULL,
            reg_landing_page VARCHAR(500) NULL,
            last_login_at DATETIME NULL,
            last_login_ip VARCHAR(45) NULL,
            last_seen_at DATETIME NULL,
            login_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_country (reg_country_code),
            INDEX idx_source (reg_source),
            INDEX idx_last_seen (last_seen_at)
        ) $E",
        'user_sessions' => "CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            login_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            logout_at DATETIME NULL,
            logout_type ENUM('manual','expired','forced','admin_exit') NULL,
            expires_at DATETIME NOT NULL,
            ip VARCHAR(45) NULL,
            country VARCHAR(80) NULL,
            country_code CHAR(2) NULL,
            city VARCHAR(80) NULL,
            device VARCHAR(20) NULL,
            browser VARCHAR(40) NULL,
            os VARCHAR(40) NULL,
            user_agent VARCHAR(255) NULL,
            impersonated_by INT UNSIGNED NULL,
            INDEX idx_user (user_id),
            INDEX idx_login (login_at),
            INDEX idx_open (logout_at, last_seen_at)
        ) $E",
        'user_login_attempts' => "CREATE TABLE IF NOT EXISTS user_login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(190) NULL,
            success TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, created_at)
        ) $E",
        'impersonation_tokens' => "CREATE TABLE IF NOT EXISTS impersonation_tokens (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            admin_id INT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL
        ) $E",
        'ip_geo_cache' => "CREATE TABLE IF NOT EXISTS ip_geo_cache (
            ip VARCHAR(45) NOT NULL PRIMARY KEY,
            country VARCHAR(80) NULL,
            country_code CHAR(2) NULL,
            region VARCHAR(80) NULL,
            city VARCHAR(80) NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) $E",
    ];

    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        $log[] = "Table ready: $name";
    }

    // v1 -> v2: new columns on admin_users
    $adminCols = [
        'name'       => "ALTER TABLE admin_users ADD COLUMN name VARCHAR(100) NULL AFTER id",
        'email'      => "ALTER TABLE admin_users ADD COLUMN email VARCHAR(190) NULL AFTER username",
        'is_super'   => "ALTER TABLE admin_users ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash",
        'role_id'    => "ALTER TABLE admin_users ADD COLUMN role_id INT UNSIGNED NULL AFTER is_super",
        'perm_mode'  => "ALTER TABLE admin_users ADD COLUMN perm_mode ENUM('role','custom') NOT NULL DEFAULT 'role' AFTER role_id",
        'created_by' => "ALTER TABLE admin_users ADD COLUMN created_by INT UNSIGNED NULL",
        'session_version' => "ALTER TABLE admin_users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0",
    ];
    foreach ($adminCols as $col => $sql) {
        if (!column_exists('admin_users', $col)) {
            $pdo->exec($sql);
            $log[] = "Column added: admin_users.$col";
        }
    }

    // Newer columns on users
    if (!column_exists('users', 'marketing_opt_in')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        $log[] = 'Column added: users.marketing_opt_in';
    }

    // Default roles (first run only)
    if ((int)q('SELECT COUNT(*) FROM admin_roles')->fetchColumn() === 0) {
        $defaults = [
            ['Manager', 'Users, logs and security — everything except managing admins',
                ['users.view', 'users.edit', 'users.delete', 'users.impersonate', 'users.export', 'sessions.view', 'audit.view', 'security.view', 'security.manage']],
            ['Support', 'View users and sign in to their accounts to help them',
                ['users.view', 'users.impersonate', 'sessions.view']],
            ['Viewer', 'Read-only access, no changes',
                ['users.view', 'sessions.view']],
        ];
        foreach ($defaults as [$n, $d, $perms]) {
            q('INSERT INTO admin_roles (name, description) VALUES (?, ?)', [$n, $d]);
            $rid = (int)$pdo->lastInsertId();
            foreach ($perms as $p) {
                q('INSERT INTO admin_role_permissions (role_id, perm) VALUES (?, ?)', [$rid, $p]);
            }
        }
        $log[] = 'Default roles created: Manager, Support, Viewer';
    }

    // Admins exist but no Super Admin (v1 upgrade) -> promote the oldest admin
    $supers = (int)q('SELECT COUNT(*) FROM admin_users WHERE is_super = 1')->fetchColumn();
    if ($supers === 0) {
        $first = q('SELECT id, username FROM admin_users ORDER BY id ASC LIMIT 1')->fetch();
        if ($first) {
            q('UPDATE admin_users SET is_super = 1 WHERE id = ?', [$first['id']]);
            $log[] = "Existing admin '{$first['username']}' promoted to Super Admin";
        }
    }

    return $log;
}

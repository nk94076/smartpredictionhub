<?php
/**
 * Smart Prediction Hub — shared config (used by both admin and user areas)
 * The app/ folder is blocked from public access via .htaccess.
 */
if (!defined('SPH_APP')) { http_response_code(403); exit('Forbidden'); }

// ---------- Database ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'smartpredictionhub');
define('DB_USER', 'smartpredictionhub');
define('DB_PASS', 'No0zuXQCnAJUxUf3F8y7');

// ---------- Site ----------
define('SITE_NAME', 'Smart Prediction Hub');
define('ADMIN_PATH', '/admin');   // URL path of the admin folder
define('USER_PATH', '/user');     // URL path of the user (frontend account) folder

// ---------- Admin security ----------
define('MAX_LOGIN_ATTEMPTS', 3);           // admin: block IP after this many failed attempts
define('BLOCK_DURATION_HOURS', 24);        // block duration in hours
define('SESSION_IDLE_TIMEOUT', 1800);      // admin: sign out after 30 min of inactivity
define('SESSION_ABSOLUTE_TIMEOUT', 28800); // admin: force re-login after 8 hours
define('ADMIN_SESSION_NAME', 'SPH_ADMSESS');

// ---------- User (site visitors) ----------
define('USER_COOKIE', 'sph_user');
define('USER_SESSION_DAYS', 30);           // "keep me signed in" duration in days
define('USER_IDLE_HOURS', 72);             // session expires after this many idle hours
define('USER_MAX_FAILED_LOGINS', 5);       // failed logins in 15 min before temporary rate limit
define('USER_MAX_REG_PER_HOUR', 5);        // max registrations per IP per hour
define('ONLINE_WINDOW_MIN', 5);            // active in the last 5 min = "online"

// Set to true only if the site is behind Cloudflare. Never enable it without Cloudflare.
define('TRUST_CLOUDFLARE', false);

// IP-to-location lookup (ipwho.is, fallback ip-api.com). Set to false to disable.
define('GEO_LOOKUP_ENABLED', true);

// Key required to run setup.php. DELETE setup.php after setup.
define('SETUP_KEY', 'YTHcdN9PQS({qOHH');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Kolkata');

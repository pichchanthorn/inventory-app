<?php
// ================================================
// Session cookie configuration (Phase K2-B)
//
// This file is the ONE place the session cookie is configured, and it
// must be required immediately BEFORE session_start() at every entry
// point that opens a session. Once a session is active PHP ignores
// session_set_cookie_params() entirely, so configuration that lands
// after session_start() is silently a no-op - hence the guard below and
// the placement at the three real call sites:
//
//   includes/lang.php   - the first session_start() for every page that
//                         renders UI (auth_check.php requires lang.php
//                         before its own guarded start, so auth_check
//                         must NOT repeat this - its start never runs)
//   index.php           - the "/" entry redirect
//   auth/logout.php     - sign-out
//
// Before this file existed the application shipped no session
// configuration at all, so every cookie it emitted was a bare
// "PHPSESSID=...; path=/" - no HttpOnly, no SameSite, no Secure -
// inherited from PHP's compiled defaults. Those defaults are not a
// deployment accident that a production php.ini would fix: PHP's own
// php.ini-production ships session.cookie_httponly, session.cookie_samesite
// and session.use_strict_mode unset too. If this application wants the
// attributes, this application has to set them.
// ================================================

// Is the CURRENT request actually running over TLS?
//
// This is deliberately derived per-request rather than hard-coded or
// driven by an environment variable. Both shipped deployments are plain
// HTTP today (docker/nginx/default.conf listens on 80; the README's
// XAMPP setup is http://localhost/inventory-app), so an unconditional
// Secure flag would stop the browser from ever returning the cookie and
// would break login outright. Deriving it means the HTTP deployments
// keep working untouched and the cookie hardens by itself the day TLS
// is put in front, with nothing for a deployer to remember to set.
//
// X-Forwarded-Proto is included because the documented Docker topology
// already terminates requests in Nginx and forwards to PHP-FPM, so
// $_SERVER['HTTPS'] alone would be blind behind that proxy. The header
// is client-supplied and only trustworthy behind a proxy that
// overwrites it - but the failure direction here is safe: a spoofed
// header can only make the SENDER's own cookie Secure, which locks that
// client out of its own session rather than exposing anyone else's.
//
// For that reason this signal is for the cookie flag ONLY. It must
// never be used for authorization, access control, or any decision that
// grants something.
if (!function_exists('request_is_https')) {
    function request_is_https(): bool {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        // A proxy chain appends to this header, so the ORIGINAL client
        // scheme is the first entry ("https, http"), not the last.
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwarded !== '') {
            $first = strtolower(trim(explode(',', (string) $forwarded)[0]));
            if ($first === 'https') {
                return true;
            }
        }
        return false;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        // Unchanged from the previous effective values - the app is
        // served from the repository root, the host-only domain is the
        // tightest option, and 0 keeps it a browser-session cookie.
        'path'     => '/',
        'domain'   => '',
        'lifetime' => 0,

        // New in K2-B.
        'secure'   => request_is_https(),
        'httponly' => true,
        // Lax, not Strict: this application has no cross-site workflow
        // at all (no absolute form actions, no iframes, no third-party
        // callbacks), and its real CSRF defence is the synchronizer
        // token in includes/csrf.php. Strict would additionally withhold
        // the cookie on ordinary inbound links to an authenticated page,
        // bouncing an already-signed-in user to the login screen, which
        // is not worth the marginal gain. Declaring the value also stops
        // the behaviour depending on each browser's Lax-by-default.
        'samesite' => 'Lax',
    ]);

    // Refuse session IDs the server never issued. PHP's default is 0,
    // which means an ID invented by the client is adopted and a session
    // file is created for it. K2-A's session_regenerate_id() at login
    // already stops such an ID from becoming an AUTHENTICATED session;
    // this closes the remaining pre-authentication case, where the
    // anonymous session (which carries the CSRF token) could be fixed
    // by an attacker. Must be set before session_start() to take effect.
    ini_set('session.use_strict_mode', '1');
}

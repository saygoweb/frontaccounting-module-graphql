<?php

namespace FA\GraphQL\Fa;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\SessionGate;

/**
 * Makes this request a FrontAccounting user, the way includes/session.inc would
 * have, so that FrontAccounting's own functions find the $_SESSION they check and
 * record.
 */
class FaSession implements SessionGate
{
    private const BAD_CREDENTIALS = 'The user name or password is incorrect.';
    private const ONE_COMPANY = 'One company per request: send a request for each company.';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Load FrontAccounting in-process. FaSessionMiddleware calls this on every
     * request to the GraphQL route, anonymous ones included; Bootstrap::boot() is
     * idempotent. Nobody is logged in afterwards.
     */
    public function boot(): void
    {
        Bootstrap::boot($this->config->faRoot !== '' ? $this->config->faRoot : Bootstrap::defaultRoot());
    }

    /**
     * Select a company: its connection, its active extensions and their hooks.
     * Nobody is logged in afterwards.
     *
     * One company per request (spec §3.6): once a company is open, any other is
     * refused, before anything about it is looked at. Mutation fields run one
     * after another in one request and share the container, whose \PDO is built
     * for the company open when it is first needed; a field that switched company
     * would carry that connection, or an identity, across. Re-opening the same
     * company is allowed: tokenRefresh opens it, then enter() opens it again.
     */
    public function openCompany(int $company): void
    {
        if (CompanyContext::isSet() && CompanyContext::company() !== $company) {
            throw new Unauthenticated(self::ONE_COMPANY);
        }
        $this->boot();

        if (!isset($GLOBALS['db_connections'][$company])) {
            throw new Unauthenticated('That company does not exist.');
        }

        $_SESSION['wa_current_user'] = new \current_user();
        $_SESSION['wa_current_user']->set_company($company);
        set_global_connection($company);

        // Before any login: hook_authenticate() dispatches through $Hooks, which
        // install_hooks() fills from the extensions active for this company.
        $extensions = $GLOBALS['path_to_root'] . '/company/' . $company . '/installed_extensions.php';
        if (is_file($extensions)) {
            $GLOBALS['installed_extensions'] = self::extensionsFrom($extensions);
        }
        install_hooks();
        // Without this $security_areas has no SA_GRAPHQL and can_access() is false
        // for everyone. Core calls it only from index.php and the roles page.
        add_access_extensions();

        $encoding = strtolower((string) $_SESSION['language']->encoding);
        CompanyContext::set(
            $company,
            $GLOBALS['db_connections'][$company],
            $encoding === 'iso-8859-1' ? 'latin1' : 'utf8'
        );
    }

    public function enter(Claims $claims): void
    {
        $this->openCompany($claims->company);

        // A verified token is not a login attempt. current_user::login() calls
        // write_login_filelog() whenever $SysPrefs->login_delay > 0, and that
        // rewrites tmp/faillog.php, the web UI's brute-force throttle: with no
        // counters loaded, every API request would reset everyone's. login_delay
        // is the switch FrontAccounting itself reads, so it is off for this one
        // call and restored in the same finally that clears the flag.
        $prefs = $GLOBALS['SysPrefs'];
        $loginDelay = $prefs->login_delay;
        $prefs->login_delay = 0;
        VerifiedIdentity::set($claims->company, $claims->login);
        try {
            $ok = $_SESSION['wa_current_user']->login($claims->company, $claims->login, '');
        } finally {
            VerifiedIdentity::clear();
            $prefs->login_delay = $loginDelay;
        }
        if (!$ok) {
            throw new Unauthenticated('This user can no longer sign in.');
        }

        $this->finish();
    }

    public function loginWithPassword(int $company, string $login, string $password): void
    {
        $this->openCompany($company);
        self::loadFailLog();

        // FrontAccounting only counts failures and greys out its login button
        // client-side (access/login.php calls this same check_faillog() to decide
        // that); it never refuses a throttled attempt on the server. An API is
        // scriptable, so the throttle is enforced here, before the password is
        // checked, with the same single message a wrong password gets — no reason
        // to tell a script it is throttled rather than simply wrong.
        if (check_faillog()) {
            throw new Unauthenticated(self::BAD_CREDENTIALS);
        }

        if ($login === '' || !$_SESSION['wa_current_user']->login($company, $login, $password)) {
            throw new Unauthenticated(self::BAD_CREDENTIALS);
        }

        $this->finish();
    }

    public function user(): \current_user
    {
        return $_SESSION['wa_current_user'];
    }

    public function userId(): int
    {
        return (int) $_SESSION['wa_current_user']->user;
    }

    private function finish(): void
    {
        if (!$_SESSION['wa_current_user']->can_access('SA_GRAPHQL')) {
            throw new Forbidden('This user does not have GraphQL API access.');
        }
        if (!isset($_SESSION['App'])) {
            $_SESSION['App'] = new \front_accounting();
            $_SESSION['App']->init();
        }
    }

    /**
     * As includes/session.inc does before any login: write_login_filelog() updates
     * the counters in $login_faillog and writes the whole array back, so without
     * them loaded a password login would erase every other entry.
     */
    private static function loadFailLog(): void
    {
        $file = VARLIB_PATH . '/faillog.php';
        if ($GLOBALS['SysPrefs']->login_delay > 0 && is_file($file)) {
            $login_faillog = [];
            include $file;
            $GLOBALS['login_faillog'] = $login_faillog;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function extensionsFrom(string $file): array
    {
        $installed_extensions = [];
        include $file;

        return $installed_extensions;
    }
}

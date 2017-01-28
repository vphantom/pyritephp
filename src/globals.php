<?php

/**
 * Global definitions
 *
 * Defines $PPHP[], grab() and pass()
 *
 * PHP version 5
 *
 * @category  Application
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */

$GLOBALS['PPHP'] = array();

// Supplements to sphido/event

/**
 * Trigger an event and get the last return value
 *
 * Parameters are passed as-is to trigger().
 *
 * @return mixed The last return value of the result stack.
 */
function grab()
{
    $result = call_user_func_array('trigger', func_get_args());
    return array_pop($result);
};

/**
 * Trigger an event and test falsehood of the last return value
 *
 * Parameters are passed as-is to trigger()
 *
 * @return bool Whether the last result wasn't false
 */
function pass()
{
    $result = call_user_func_array('trigger', func_get_args());
    return array_pop($result) !== false;
};

/**
 * Pyrite class
 *
 * @category  Application
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */
class Pyrite
{
    /**
     * Bootstrap request processing
     *
     * First part of the 3-step life cycle.
     *
     * @param string $dir Your own __DIR__
     *
     * @return null
     */
    public static function bootstrap($dir)
    {
        global $PPHP;

        $PPHP['dir'] = $dir;

        // Load configuration
        $PPHP['config'] = parse_ini_file('config.ini', true);

        // Work around limitation of PHP's handling of true and false entries
        $PPHP['config']['global']['debug'] = (bool)$PPHP['config']['global']['debug'];
        $PPHP['config']['global']['production'] = (bool)$PPHP['config']['global']['production'];

        // Pass project's global __DIR__ a.k.a. document root
        $PPHP['config']['global']['docroot'] = $dir . '/';

        // Watchdog
        $watchdog = new Pyrite\Core\Watchdog();
        if (array_key_exists('mail_errors_to', $PPHP['config']['global'])) {
            $watchdog->notify($PPHP['config']['global']['mail_errors_to']);
        };

        // Database
        $PPHP['db'] = new Pyrite\Core\PDB($PPHP['config']['db']['type'] . ':' . $PPHP['dir'] . '/' . $PPHP['config']['db']['sqlite_path']);
        $PPHP['db']->exec("PRAGMA foreign_keys = ON");

        // Load local install's modules
        foreach (glob($PPHP['dir'] . '/modules/*.php') as $fname) {
            include_once $fname;
        };

        // From the command line means install mode
        if (php_sapi_name() === 'cli') {
            trigger('install');
            exit;
        };

        // Start up
        trigger('startup');
        if (array_key_exists('name', $PPHP['config']['global'])) {
            trigger('title', $PPHP['config']['global']['name']);
        };
    }

    /**
     * Run
     *
     * Second part of the 3-step life cycle.  Runs the router.
     *
     * @return null
     */
    public static function run()
    {
        Pyrite\Router::run();
    }

    /**
     * Shut down
     *
     * Last part of the 3-step life cycle.
     *
     * @return null
     */
    public static function shutdown()
    {
        trigger('shutdown');
    }

    /**
     * Sanitize file name
     *
     * This is for base file names: even dots are filtered out.
     *
     * Spaces are reduced and translated into underscores.
     *
     * CAVEAT: does not allow accented characters, commas, and anything else
     * beyond alphanumeric, underscore and hyphen characters.
     *
     * @param string $name String to filter
     *
     * @return string
     */
    function cleanFilename($name)
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', preg_replace('/\s+/', '_', $name));
    }

    /**
     * Sanitize e-mail address
     *
     * @param string $email String to filter
     *
     * @return string
     */
    function cleanEmail($email)
    {
        // filter_var()'s FILTER_SANITIZE_EMAIL is way too permissive
        return preg_replace('/[^a-zA-Z0-9@.,_+-]/', '', $email);
    }

    /**
     * Strip low-ASCII and <>`|\"' from string
     *
     * @param string $name String to filter
     *
     * @return string
     */
    function cleanName($name)
    {
        return preg_replace(
            '/[<>`|\\"\']/',
            '',
            filter_var($name, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES|FILTER_FLAG_STRIP_LOW)
        );
    }

    /**
     * Hide most of the user part of an e-mail address
     *
     * @param string $email String to filter
     *
     * @return string
     */
    function protectEmail($email)
    {
        $chunks = explode('@', $email);
        $chunks[0] = $chunks[0][0] . '******';
        return implode('@', $chunks);
    }
}

add_filter('clean_filename', 'Pyrite::cleanFilename');
add_filter('clean_email',    'Pyrite::cleanEmail');
add_filter('clean_name',     'Pyrite::cleanName');
add_filter('protect_email',  'Pyrite::protectEmail');

// Included modules which have start-up definitions
Pyrite\ACL::bootstrap();
Pyrite\AuditTrail::bootstrap();
Pyrite\Router::bootstrap();
Pyrite\Session::bootstrap();
Pyrite\Sendmail::bootstrap();
Pyrite\Templating::bootstrap();
Pyrite\Users::bootstrap();


// Framework-related routes

on(
    'route/page',
    function ($path) {
        $req = grab('request');
        return trigger('render', 'pages/' . filter('clean_filename', $path[0]) . '.html');
    }
);

on(
    'route/login',
    function () {
        $req = grab('request');

        if (isset($_GET['email']) && isset($_GET['onetime'])) {

            // Account creation validation link
            if (!pass('login', $_GET['email'], null, $_GET['onetime'])) return trigger('http_status', 403);
        } else {

            // Normal login
            if (!pass('form_validate', 'login-form')) return trigger('http_status', 440);
            usleep(500000);
            if (isset($_POST['password']) && strlen($_POST['password']) > 0) {
                if (!pass('login', $_POST['email'], $_POST['password'])) return trigger('http_status', 403);
            } else {
                if (($user = grab('user_fromemail', $_POST['email'])) !== false) {
                    if (($onetime = grab('user_update', $user['id'], array('onetime' => true))) !== false) {
                        $link = 'login?' . http_build_query(array( 'email' => $_POST['email'], 'onetime' => $onetime));
                        trigger(
                            'sendmail',
                            "{$user['name']} <{$_POST['email']}>",
                            'confirmlink',
                            array(
                                'validation_link' => $link
                            )
                        );
                    };
                };
                return trigger('render', 'login.html');
            };
        };

        trigger('http_redirect', $req['base'] . '/');
    }
);

on(
    'route/logout',
    function () {
        $req = grab('request');
        trigger('logout');
        trigger('http_redirect', $req['base'] . '/');
    }
);

on(
    'route/user+prefs',
    function () {
        if (!$_SESSION['identified']) return trigger('http_status', 403);
        $saved = false;
        $success = false;

        // Settings & Information
        if (isset($_POST['name'])) {
            if (!pass('form_validate', 'user_prefs')) return trigger('http_status', 440);
            $saved = true;
            $_POST['name'] = filter('clean_name', $_POST['name']);
            $success = pass('user_update', $_SESSION['user']['id'], $_POST);
        };

        // Change e-mail or password
        if (isset($_POST['email'])) {
            $_POST['email'] = filter('clean_email', $_POST['email']);
            if (!pass('form_validate', 'user_passmail')) return trigger('http_status', 440);
            $saved = true;
            $oldEmail = filter('clean_email', $_SESSION['user']['email']);
            if (pass('login', $oldEmail, $_POST['password'])) {
                if ($success = pass('user_update', $_SESSION['user']['id'], $_POST)) {
                    $name = filter('clean_name', $_SESSION['user']['name']);
                    trigger(
                        'sendmail',
                        "{$name} <{$oldEmail}>",
                        'editaccount'
                    );
                    $newEmail = $_POST['email'];
                    if ($newEmail !== false  &&  $newEmail !== $oldEmail) {
                        trigger(
                            'sendmail',
                            "{$name} <{$newEmail}>",
                            'editaccount'
                        );
                    };
                    if ($oldEmail !== $newEmail) {
                        trigger('log', 'user', $_SESSION['user']['id'], 'modified', 'email', $oldEmail, $newEmail);
                    };
                    if (strlen($_POST['newpassword1']) >= 8) {
                        trigger('log', 'user', $_SESSION['user']['id'], 'modified', 'password');
                    };
                };
            };
        };

        trigger(
            'render',
            'user_prefs.html',
            array(
                'saved' => $saved,
                'success' => $success,
                'user' => $_SESSION['user']
            )
        );
    }
);

on(
    'route/user+history',
    function () {
        if (!$_SESSION['identified']) return trigger('http_status', 403);
        $history = grab(
            'history',
            array(
                'objectType' => 'user',
                'objectId' => $_SESSION['user']['id'],
                'order' => 'DESC',
                'max' => 20
            )
        );
        trigger(
            'render',
            'user_history.html',
            array(
                'history' => $history
            )
        );
    }
);

on(
    'route/register',
    function () {
        $created = false;
        $success = false;
        if (isset($_POST['email'])) {
            if (!pass('form_validate', 'registration')) return trigger('http_status', 440);
            $created = true;
            $_POST['email'] = filter('clean_email', $_POST['email']);
            $_POST['name'] = filter('clean_name', $_POST['name']);
            $_POST['onetime'] = true;
            if (($onetime = grab('user_create', $_POST)) !== false) {
                $success = true;
                if (pass('can', 'create', 'user')) {
                    trigger(
                        'sendmail',
                        "{$_POST['name']} <{$_POST['email']}>",
                        'invitation'
                    );
                } else {
                    $link = 'login?' . http_build_query(array( 'email' => $_POST['email'], 'onetime' => $onetime));
                    trigger(
                        'sendmail',
                        "{$_POST['name']} <{$_POST['email']}>",
                        'confirmlink',
                        array(
                            'validation_link' => $link
                        )
                    );
                };
            } else {
                if (($user = grab('user_fromemail', $_POST['email'])) !== false) {
                    // Onetime failed because user exists, warn of duplicate
                    // attempt via e-mail, don't hint that the user exists on
                    // the web though!
                    $success = true;
                    trigger(
                        'sendmail',
                        "{$user['name']} <{$user['email']}>",
                        'duplicate'
                    );
                };
            };
        };
        trigger(
            'render',
            'register.html',
            array(
                'created' => $created,
                'success' => $success
            )
        );
    }
);

on(
    'route/password_reset',
    function () {
        $inprogress = false;
        $emailed = false;
        $saved = false;
        $valid = false;
        $success = false;
        $email = '';
        $onetime = '';

        /*
         * 1.1: Display form
         * 1.2: Using form's $email, generate one-time password and e-mail it if user is valid
         * 2.1: From e-mailed link, display password update form if one-time password checks out
         *      Generate yet another one-time password for that form, because ours expired upon verification
         * 2.2: From update form, update user's password if one-time password checks out
         *
         * This is because A) we can trust 'email' but not an ID from such a
         * public form, B) we want to keep the form tied to the user at all
         * times and C) we don't want to authenticate the user in $_SESSION at
         * this stage.
         */

        if (isset($_POST['email']) && isset($_POST['onetime']) && isset($_POST['newpassword1']) && isset($_POST['newpassword2'])) {
            // 2.2 Form submitted from a valid onetime
            $inprogress = true;
            $saved = true;
            if (($user = grab('authenticate', $_POST['email'], null, $_POST['onetime'])) !== false) {
                $success = pass(
                    'user_update',
                    $user['id'],
                    array(
                        'newpassword1' => $_POST['newpassword1'],
                        'newpassword2' => $_POST['newpassword2']
                    )
                );
            };
        } elseif (isset($_POST['email'])) {
            // 1.2 Form submitted to tell us whom to reset
            $emailed = true;
            $success = true;  // Always pretend it worked
            if (($user = grab('user_fromemail', $_POST['email'])) !== false) {
                if (($onetime = grab('user_update', $user['id'], array('onetime' => true))) !== false) {
                    $link = 'password_reset?' . http_build_query(array( 'email' => $_POST['email'], 'onetime' => $onetime));
                    trigger(
                        'sendmail',
                        "{$user['name']} <{$_POST['email']}>",
                        'confirmlink',
                        array(
                            'validation_link' => $link
                        )
                    );
                };
            };
        } elseif (isset($_GET['email']) && isset($_GET['onetime'])) {
            // 2.1 Link from e-mail clicked, display form if onetime valid
            $inprogress = true;
            $saved = false;
            $email = filter('clean_email', $_GET['email']);
            if (($user = grab('authenticate', $_GET['email'], null, $_GET['onetime'])) !== false) {
                $valid = true;
                if (($onetime = grab('user_update', $user['id'], array('onetime' => true))) === false) {
                    $onetime = '';
                };
            };
        };

        trigger(
            'render',
            'password_reset.html',
            array(
                'inprogress' => $inprogress,
                'emailed'    => $emailed,
                'saved'      => $saved,
                'valid'      => $valid,
                'success'    => $success,
                'email'      => $email,
                'onetime'    => $onetime
            )
        );
    }
);

on(
    'route/admin+users',
    function ($path) {
        global $PPHP;

        if (!(pass('can', 'view', 'user') || pass('can', 'create', 'user'))) return trigger('http_status', 403);

        $f = array_shift($path);
        switch ($f) {

        case 'edit':
            $saved = false;
            $added = false;
            $deleted = false;
            $success = false;
            $history = array();
            $user = array();
            $rights = array();

            if (!pass('can', 'edit', 'user')) return trigger('http_status', 403);

            if (isset($_POST['name']) && isset($_GET['id'])) {
                if (!pass('form_validate', 'user_prefs')) return trigger('http_status', 440);
                $saved = true;
                $success = pass('user_update', $_GET['id'], $_POST);
            };

            if (isset($_GET['id'])) {
                $user = \Pyrite\Users::resolve($_GET['id']);
                if (!$user) {
                    return trigger('http_status', 404);
                };

                $user = \Pyrite\Users::fromEmail($user['email'], false);
                if (!$user) {
                    return trigger('http_status', 404);
                };

                if (isset($_POST['f'])) {
                    switch ($_POST['f']) {

                    case 'add':
                        $added = true;
                        $success = pass('grant', $_GET['id'], null, $_POST['action'], $_POST['objectType'], $_POST['objectId']);
                        break;

                    case 'del':
                        $deleted = true;
                        $success = pass('revoke', $_GET['id'], null, $_POST['action'], $_POST['objectType'], $_POST['objectId']);
                        break;

                    default:
                    };
                };

                if (isset($_POST['addrole'])) {
                    $added = true;
                    $success = pass('grant', $_GET['id'], $_POST['addrole']);
                } elseif (isset($_POST['delrole'])) {
                    $deleted = true;
                    $success = pass('revoke', $_GET['id'], $_POST['delrole']);
                } elseif (isset($_POST['unban'])) {
                    $saved = true;
                    $user['active'] = 1;
                    $success = pass('unban_user', $_GET['id']);
                    trigger('log', 'user', $_GET['id'], 'activated');
                } elseif (isset($_POST['ban'])) {
                    $saved = true;
                    $user['active'] = 0;
                    $success = pass('ban_user', $_GET['id']);
                    trigger('log', 'user', $_GET['id'], 'deactivated');
                };

                $history = grab(
                    'history',
                    array(
                        'userId' => $_GET['id'],
                        'order' => 'DESC',
                        'max' => 20
                    )
                );
                $rights = grab('user_rights', $_GET['id']);
                $roles = grab('user_roles', $_GET['id']);
            };

            trigger(
                'render',
                'admin_users_edit.html',
                array(
                    'actions'     => $PPHP['config']['acl']['actions'],
                    'objectTypes' => $PPHP['config']['acl']['objectTypes'],
                    'history'     => $history,
                    'user'        => $user,
                    'saved'       => $saved,
                    'added'       => $added,
                    'deleted'     => $deleted,
                    'success'     => $success,
                    'rights'      => $rights,
                    'user_roles'  => $roles,
                    'roles'       => $PPHP['config']['acl']['roles']
                )
            );
            break;

        default:
            $users = \Pyrite\Users::search(
                isset($_POST['email']) && strlen($_POST['email']) > 2 ? $_POST['email'] : null,
                isset($_POST['name']) && strlen($_POST['name']) > 2 ? $_POST['name'] : null
            );
            trigger(
                'render',
                'admin_users.html',
                array(
                    'users' => $users
                )
            );

        };
    }
);

on(
    'route/admin+roles',
    function ($path) {
        global $PPHP;

        if (!pass('can', 'view', 'role')) return trigger('http_status', 403);

        $roleId = array_shift($path);
        if ($roleId === null) $roleId = 'admin';

        $f = isset($_POST['f']) ? $_POST['f'] : null;
        $success = false;
        $added = false;
        $deleted = false;
        switch ($f) {

        case 'add':
            if (!pass('can', 'edit', 'role')) return trigger('http_status', 403);

            $added = true;
            $success = pass('grant', null, $roleId, $_POST['action'], $_POST['objectType'], $_POST['objectId']);
            break;

        case 'del':
            if (!pass('can', 'edit', 'role')) return trigger('http_status', 403);

            $deleted = true;
            $success = pass('revoke', null, $roleId, $_POST['action'], $_POST['objectType'], $_POST['objectId']);
            break;

        default:
        };

        trigger(
            'render',
            'admin_roles.html',
            array(
                'actions'     => $PPHP['config']['acl']['actions'],
                'objectTypes' => $PPHP['config']['acl']['objectTypes'],
                'role'        => $roleId,
                'roles'       => $PPHP['config']['acl']['roles'],
                'rights'      => grab('role_rights', $roleId),
                'success'     => $success,
                'added'       => $added,
                'deleted'     => $deleted
            )
        );
    }
);

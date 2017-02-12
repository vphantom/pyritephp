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

// Supplements to PHP itself

/**
 * Split a string by delimiter
 *
 * Wrapper around PHP's `explode()` which takes care of returning an empty
 * array when `$str` is empty, false or null.
 *
 * @param string      $delim Delimiter
 * @param string|null $str   String to split
 *
 * @return array
 */
function dejoin($delim, $str)
{
    $out = array();
    if (is_string($delim) && is_string($str) && strlen($str) > 0) {
        $out = explode($delim, $str);
    };
    return $out;
}

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
        $PPHP['config']['global']['force_outbox'] = (bool)$PPHP['config']['global']['force_outbox'];

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
}

add_filter('clean_filename', 'Pyrite\Core\Filters::cleanFilename');
add_filter('clean_email',    'Pyrite\Core\Filters::cleanEmail');
add_filter('clean_name',     'Pyrite\Core\Filters::cleanName');
add_filter('protect_email',  'Pyrite\Core\Filters::protectEmail');
add_filter('html_to_text',   'Pyrite\Core\Filters::html2text');

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

        if (isset($req['get']['email']) && isset($req['get']['onetime'])) {

            // Account creation validation link
            if (!pass('login', $req['get']['email'], null, $req['get']['onetime'])) return trigger('http_status', 403);
        } else {

            // Normal login
            if (!pass('form_validate', 'login-form')) return trigger('http_status', 440);
            usleep(500000);
            if (isset($req['post']['password']) && strlen($req['post']['password']) > 0) {
                if (!pass('login', $req['post']['email'], $req['post']['password'])) return trigger('http_status', 403);
            } else {
                if (($user = grab('user_fromemail', $req['post']['email'])) !== false) {
                    if (($onetime = grab('user_update', $user['id'], array('onetime' => true))) !== false) {
                        $link = 'login?' . http_build_query(array( 'email' => $req['post']['email'], 'onetime' => $onetime));
                        trigger(
                            'sendmail',
                            "{$user['name']} <{$req['post']['email']}>",
                            'confirmlink',
                            array(
                                'validation_link' => $link
                            )
                        );
                    };
                };
                return trigger('http_status', 449);
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
        $req = grab('request');
        $saved = false;
        $success = false;

        // Settings & Information
        if (isset($req['post']['name'])) {
            if (!pass('form_validate', 'user_prefs')) return trigger('http_status', 440);
            $saved = true;
            $req['post']['name'] = filter('clean_name', $req['post']['name']);
            $success = pass('user_update', $_SESSION['user']['id'], $req['post']);
        };

        // Change e-mail or password
        if (isset($req['post']['email'])) {
            $req['post']['email'] = filter('clean_email', $req['post']['email']);
            if (!pass('form_validate', 'user_passmail')) return trigger('http_status', 440);
            $saved = true;
            $oldEmail = filter('clean_email', $_SESSION['user']['email']);
            if (pass('login', $oldEmail, $req['post']['password'])) {
                if ($success = pass('user_update', $_SESSION['user']['id'], $req['post'])) {
                    $name = filter('clean_name', $_SESSION['user']['name']);
                    trigger(
                        'sendmail',
                        "{$name} <{$oldEmail}>",
                        'editaccount'
                    );
                    $newEmail = $req['post']['email'];
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
                    if (strlen($req['post']['newpassword1']) >= 8) {
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
        $req = grab('request');
        $created = false;
        $success = false;
        if (isset($req['post']['email'])) {
            if (!pass('form_validate', 'registration')) return trigger('http_status', 440);
            $created = true;
            $req['post']['email'] = filter('clean_email', $req['post']['email']);
            $req['post']['name'] = filter('clean_name', $req['post']['name']);
            $req['post']['onetime'] = true;
            if (($onetime = grab('user_create', $req['post'])) !== false) {
                $success = true;
                trigger('http_status', 201);
                if (pass('can', 'create', 'user')) {
                    trigger(
                        'sendmail',
                        "{$req['post']['name']} <{$req['post']['email']}>",
                        'invitation'
                    );
                } else {
                    $link = 'login?' . http_build_query(array( 'email' => $req['post']['email'], 'onetime' => $onetime));
                    trigger(
                        'sendmail',
                        "{$req['post']['name']} <{$req['post']['email']}>",
                        'confirmlink',
                        array(
                            'validation_link' => $link
                        )
                    );
                };
            } else {
                if (($user = grab('user_fromemail', $req['post']['email'])) !== false) {
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
        $req = grab('request');
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

        if (isset($req['post']['email']) && isset($req['post']['onetime']) && isset($req['post']['newpassword1']) && isset($req['post']['newpassword2'])) {
            // 2.2 Form submitted from a valid onetime
            $inprogress = true;
            $saved = true;
            if (($user = grab('authenticate', $req['post']['email'], null, $req['post']['onetime'])) !== false) {
                $success = pass(
                    'user_update',
                    $user['id'],
                    array(
                        'newpassword1' => $req['post']['newpassword1'],
                        'newpassword2' => $req['post']['newpassword2']
                    )
                );
            };
        } elseif (isset($req['post']['email'])) {
            // 1.2 Form submitted to tell us whom to reset
            $emailed = true;
            $success = true;  // Always pretend it worked
            if (($user = grab('user_fromemail', $req['post']['email'])) !== false) {
                if (($onetime = grab('user_update', $user['id'], array('onetime' => true))) !== false) {
                    $link = 'password_reset?' . http_build_query(array( 'email' => $req['post']['email'], 'onetime' => $onetime));
                    trigger(
                        'sendmail',
                        "{$user['name']} <{$req['post']['email']}>",
                        'confirmlink',
                        array(
                            'validation_link' => $link
                        )
                    );
                };
            };
        } elseif (isset($req['get']['email']) && isset($req['get']['onetime'])) {
            // 2.1 Link from e-mail clicked, display form if onetime valid
            $inprogress = true;
            $saved = false;
            $email = filter('clean_email', $req['get']['email']);
            if (($user = grab('authenticate', $req['get']['email'], null, $req['get']['onetime'])) !== false) {
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
        $req = grab('request');

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

            if (isset($req['post']['name']) && isset($req['get']['id'])) {
                if (!pass('form_validate', 'user_prefs')) return trigger('http_status', 440);
                $saved = true;
                $success = pass('user_update', $req['get']['id'], $req['post']);
            };

            if (isset($req['get']['id'])) {
                $user = \Pyrite\Users::resolve($req['get']['id']);
                if (!$user) {
                    return trigger('http_status', 404);
                };

                $user = \Pyrite\Users::fromEmail($user['email'], false);
                if (!$user) {
                    return trigger('http_status', 404);
                };

                if (isset($req['post']['f'])) {
                    switch ($req['post']['f']) {

                    case 'add':
                        if (!pass('form_validate', 'admin_user_acl_add')) return trigger('http_status', 440);
                        $added = true;
                        $success = pass('grant', $req['get']['id'], null, $req['post']['action'], $req['post']['objectType'], $req['post']['objectId']);
                        break;

                    case 'del':
                        if (!pass('form_validate', 'admin_user_acl_del')) return trigger('http_status', 440);
                        $deleted = true;
                        $success = pass('revoke', $req['get']['id'], null, $req['post']['action'], $req['post']['objectType'], $req['post']['objectId']);
                        break;

                    default:
                    };
                };

                if (isset($req['post']['addrole'])) {
                    if (!pass('form_validate', 'admin_user_role')) return trigger('http_status', 440);
                    $added = true;
                    $success = pass('grant', $req['get']['id'], $req['post']['addrole']);
                } elseif (isset($req['post']['delrole'])) {
                    if (!pass('form_validate', 'admin_user_role')) return trigger('http_status', 440);
                    $deleted = true;
                    $success = pass('revoke', $req['get']['id'], $req['post']['delrole']);
                } elseif (isset($req['post']['unban'])) {
                    if (!pass('form_validate', 'admin_user_ban')) return trigger('http_status', 440);
                    $saved = true;
                    $user['active'] = 1;
                    $success = pass('unban_user', $req['get']['id']);
                    trigger('log', 'user', $req['get']['id'], 'activated');
                } elseif (isset($req['post']['ban'])) {
                    if (!pass('form_validate', 'admin_user_ban')) return trigger('http_status', 440);
                    $saved = true;
                    $user['active'] = 0;
                    $success = pass('ban_user', $req['get']['id']);
                    trigger('log', 'user', $req['get']['id'], 'deactivated');
                };

                $history = grab(
                    'history',
                    array(
                        'userId' => $req['get']['id'],
                        'order' => 'DESC',
                        'max' => 20
                    )
                );
                $rights = grab('user_rights', $req['get']['id']);
                $roles = grab('user_roles', $req['get']['id']);
            };

            trigger(
                'render',
                'users_edit.html',
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
            $keyword = null;
            if (isset($req['post']['keyword'])) {
                if (!pass('form_validate', 'user_search')) return trigger('http_status', 440);
                if (strlen($req['post']['keyword']) > 2) {
                    $keyword = $req['post']['keyword'];
                };
            };
            $users = \Pyrite\Users::search($keyword);
            trigger(
                'render',
                'users.html',
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
        $req = grab('request');

        $roleId = array_shift($path);
        if ($roleId === null) $roleId = 'admin';

        $f = isset($req['post']['f']) ? $req['post']['f'] : null;
        $success = false;
        $added = false;
        $deleted = false;
        switch ($f) {

        case 'add':
            if (!pass('form_validate', 'admin_role_acl_add')) return trigger('http_status', 440);
            if (!pass('can', 'edit', 'role')) return trigger('http_status', 403);

            $added = true;
            $success = pass('grant', null, $roleId, $req['post']['action'], $req['post']['objectType'], $req['post']['objectId']);
            break;

        case 'del':
            if (!pass('form_validate', 'admin_role_acl_del')) return trigger('http_status', 440);
            if (!pass('can', 'edit', 'role')) return trigger('http_status', 403);

            $deleted = true;
            $success = pass('revoke', null, $roleId, $req['post']['action'], $req['post']['objectType'], $req['post']['objectId']);
            break;

        default:
        };

        trigger(
            'render',
            'roles.html',
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

on(
    'route/outbox',
    function ($path) {
        if (!$_SESSION['identified']) return trigger('http_status', 403);
        if (!pass('can', 'edit', 'email')) return trigger('http_status', 403);

        $req = grab('request');
        $id = array_shift($path);
        $email = null;
        $emails = null;
        $all = false;
        $sent = false;
        $success = false;

        if (is_numeric($id)) {
            if (isset($req['post']['subject'])) {
                $sent = true;
                if (pass(
                    'outbox_save',
                    $id,
                    $req['post']['recipients'],
                    $req['post']['ccs'],
                    $req['post']['bccs'],
                    $req['post']['subject'],
                    $req['post']['html']
                ) !== false
                ) {
                    $success = pass('outbox_send', $id);
                };
            };
            if (!$success) {
                $email = grab('outbox_email', $id);
            };
        } elseif ($id === 'all' && pass('has_role', 'admin')) {
            $emails = grab('outbox', true);
            $all = true;
        } else {
            $emails = $_SESSION['outbox'];
        };

        trigger(
            'render',
            'outbox.html',
            array(
                'sent'    => $sent,
                'success' => $success,
                'email'   => $email,
                'emails'  => $emails,
                'all'     => $all
            )
        );
    }
);

// If config.global.force_outbox and there are pending messages, force user to
// '/outbox' instead of anywhere else
on(
    'validate_request',
    function ($route) {
        global $PPHP;
        $force_outbox = $PPHP['config']['global']['force_outbox'];

        if ($route !== 'route/outbox'
            && $force_outbox
            && $_SESSION['identified']
            && isset($_SESSION['outbox'])
            && count($_SESSION['outbox']) > 0
        ) {
            $req = grab('request');
            trigger('http_redirect', $req['base'] . '/outbox');
            return false;
        };
        return true;
    }
);

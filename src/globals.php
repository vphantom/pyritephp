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
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
 */

$GLOBALS['PPHP'] = array();

global $PPHP;

$PPHP['version'] = 'v1.1.0';

$PPHP['license'] = <<<EOS
PyritePHP {$PPHP['version']}
Copyright (c) 2017 Stephane Lavergne <https://github.com/vphantom>

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

EOS;

$PPHP['help'] = <<<EOS

PyritePHP {$PPHP['version']}

Valid options:

-h, --help
        This usage message.

-V, --version
        Current version information.

-tevent, --trigger event
        Trigger event and exit normally.

        Built-in event "install" creates any missing database tables.


EOS;

$PPHP['contextType'] = null;
$PPHP['contextId'] = null;

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

/**
 * Append indexed arrays to another
 *
 * @param array $dest       Destination array to modify
 * @param array ...$sources Source arrays to append (one or many)
 *
 * @return array $dest itself
 */
// @codingStandardsIgnoreStart
function array_merge_indexed(&$dest, ...$sources)
{
    // @codingStandardsIgnoreEnd
    if (!is_array($dest)) {
        return $dest;
    };
    foreach ($sources as $src) {
        if (is_array($src)) {
            foreach ($src as $v) {
                $dest[] = $v;
            };
        };
    };
    return $dest;
}

/**
 * Append associative arrays to another
 *
 * @param array $dest       Destination array to modify
 * @param array ...$sources Source arrays to append (one or many)
 *
 * @return array $dest itself
 */
// @codingStandardsIgnoreStart
function array_merge_assoc(&$dest, ...$sources)
{
    // @codingStandardsIgnoreEnd
    if (!is_array($dest)) {
        return $dest;
    };
    foreach ($sources as $src) {
        if (is_array($src)) {
            foreach ($src as $k => $v) {
                $dest[$k] = $v;
            };
        };
    };
    return $dest;
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
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
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
        $PPHP['config'] = parse_ini_file("{$dir}/var/config.ini", true);

        // Work around limitation of PHP's handling of true and false entries
        $PPHP['config']['global']['debug'] = (bool)$PPHP['config']['global']['debug'];
        $PPHP['config']['global']['production'] = (bool)$PPHP['config']['global']['production'];
        $PPHP['config']['global']['use_ssl'] = (bool)$PPHP['config']['global']['use_ssl'];
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

        // From the command line, don't do web startup
        if (php_sapi_name() === 'cli') {
            $options = getopt('hVt:', array('help', 'version', 'trigger:'));

            if (isset($options['help']) || isset($options['h'])) {
                echo $PPHP['help'];
                exit;
            };

            if (isset($options['version']) || isset($options['V'])) {
                echo $PPHP['license'];
                exit;
            };

            // CLI start up
            trigger('cli_startup');

            $trigger = null;
            if (isset($options['t'])) {
                $trigger = $options['t'];
            };
            if (isset($options['trigger'])) {
                $trigger = $options['trigger'];
            };
            if ($trigger !== null) {
                trigger($trigger);
                exit;
            };

            echo "Error: no action specified!\n";
            exit(1);
        };

        // Web start up
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
    'install',
    function () {
        global $PPHP;
        echo "\n    Don't forget to chmod/chgrp {$PPHP['config']['db']['sqlite_path']}!\n\n";
    },
    99
);

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
                            $user['id'],
                            null,
                            null,
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
                if (!pass('can', 'edit', 'email')) {
                    // We can only warn the "before" e-mail in a no-queue scenario
                    trigger(
                        'sendmail',
                        $_SESSION['user']['id'],
                        null,
                        null,
                        'editaccount'
                    );
                };
                if ($success = pass('user_update', $_SESSION['user']['id'], $req['post'])) {
                    $name = filter('clean_name', $_SESSION['user']['name']);
                    $newEmail = $req['post']['email'];
                    if ($newEmail !== false  &&  $newEmail !== $oldEmail) {
                        trigger(
                            'sendmail',
                            $_SESSION['user']['id'],
                            null,
                            null,
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
        global $PPHP;
        $config = $PPHP['config'];
        $req = grab('request');
        $created = false;
        $success = false;
        if (isset($req['post']['email'])) {
            if (!pass('form_validate', 'registration')) return trigger('http_status', 440);
            $created = true;
            $req['post']['email'] = filter('clean_email', $req['post']['email']);
            $req['post']['name'] = filter('clean_name', $req['post']['name']);
            if (($newbie = grab('user_create', $req['post'])) !== false) {
                $id = $newbie[0];
                $success = true;
                trigger('http_status', 201);
                if (pass('can', 'create', 'user')) {
                    trigger('send_invite', 'invitation', $id);
                    $roles = preg_split('/[\s]+/', $config['acl']['invited_auto_roles'], null, PREG_SPLIT_NO_EMPTY);
                } else {
                    trigger('send_invite', 'confirmlink', $id);
                    $roles = preg_split('/[\s]+/', $config['acl']['registered_auto_roles'], null, PREG_SPLIT_NO_EMPTY);
                };
                foreach ($roles as $role) {
                    trigger('grant', $id, $role);
                };
            } else {
                if (($user = grab('user_fromemail', $req['post']['email'])) !== false) {
                    // Onetime failed because user exists, warn of duplicate
                    // attempt via e-mail, don't hint that the user exists on
                    // the web though!
                    $success = true;
                    trigger(
                        'sendmail',
                        $user['id'],
                        null,
                        null,
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
                        $user['id'],
                        null,
                        null,
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
            $history_id = null;
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
                $history_id = $req['get']['id'];
                $rights = grab('user_rights', $req['get']['id']);
                $roles = grab('user_roles', $req['get']['id']);
            };

            trigger(
                'render',
                'users_edit.html',
                array(
                    'actions'      => $PPHP['config']['acl']['actions'],
                    'objectTypes'  => $PPHP['config']['acl']['objectTypes'],
                    'history'      => $history,
                    'history_type' => 'user',
                    'history_id'   => $history_id,
                    'user'         => $user,
                    'saved'        => $saved,
                    'added'        => $added,
                    'deleted'      => $deleted,
                    'success'      => $success,
                    'rights'       => $rights,
                    'user_roles'   => $roles,
                    'roles'        => $PPHP['config']['acl']['roles']
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
        $deleted = false;
        $success = false;

        if (is_numeric($id)) {
            if (isset($req['post']['delete'])) {
                $deleted = true;
                $success = trigger('outbox_delete', $id);
            } elseif (isset($req['post']['subject'])) {
                $sent = true;
                if (pass(
                    'outbox_save',
                    $id,
                    $req['post']['recipients'],
                    (isset($req['post']['ccs']) ? $req['post']['ccs'] : null),
                    (isset($req['post']['bccs']) ? $req['post']['bccs'] : null),
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
            $emails = $_SESSION['outbox'];
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
                'deleted' => $deleted,
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

on(
    'send_invite',
    /**
     * Send invitation e-mail with onetime login link to user
     *
     * @param string      $template The template to e-mail
     * @param int         $userId   The user to invite
     * @param array|null  $args     Arguments to pass to template
     * @param string|null $onetime  Don't generate onetime, use this one
     * @param bool|null   $nodelay  Bypass outbox and send immediately
     *
     * @return bool|int The result of event 'sendmail'
     */
    function ($template, $userId, $args = array(), $onetime = null, $nodelay = false) {
        global $PPHP;
        $config = $PPHP['config'];
        if ($onetime === null) {
            $onetime = grab('user_update', $userId, array('onetime' => $config['global']['invite_lifetime'] * 24 * 3600));
        };
        $user = grab('user_resolve', $userId);
        if (!$user || !$onetime) {
            return false;
        };
        $link = 'login?' . http_build_query(array( 'email' => $user['email'], 'onetime' => $onetime));
        $args['validation_link'] = $link;
        return grab('sendmail', $userId, null, null, $template, $args, array(), $nodelay);
    }
);

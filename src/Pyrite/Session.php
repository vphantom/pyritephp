<?php

/**
 * Session
 *
 * PHP version 5
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */

namespace Pyrite;

/**
 * Session class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */

class Session
{
    /**
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('startup',       'Pyrite\Session::startup', 10);
        on('shutdown',      'Pyrite\Session::shutdown', 99);
        on('login',         'Pyrite\Session::login', 1);
        on('logout',        'Pyrite\Session::reset', 1);
        on('user_changed',  'Pyrite\Session::reloadUser', 1);
        on('form_begin',    'Pyrite\Session::beginForm');
        on('form_validate', 'Pyrite\Session::validateForm');
    }

    /**
     * Magic string to help prevent session hijacking
     *
     * Inspired by: https://www.mind-it.info/2012/08/01/using-browser-fingerprints-for-session-encryption/
     *
     * He goes further by encrypting the session using this magic string as a
     * key, but we do not intend to store highly sensitive information in
     * sessions, so hijack prevention without the computing cost of
     * server-side theft prevetion seems a good compromise.
     *
     * @return string
     */
    private static function _magic()
    {
        // HTTP_ACCEPT_ENCODING changes on Chrome 54 between GET and POST requests
        // HTTP_ACCEPT should change only in IE 6, so we'll tolerate it
        $magic
            = $_SERVER['HTTP_ACCEPT_LANGUAGE']
            . $_SERVER['HTTP_ACCEPT']
            . $_SERVER['HTTP_USER_AGENT']
        ;

        // This is more sophisticated than just $_SERVER['REMOTE_ADDR']
        $req = grab('request');
        $magic .= $req['remote_addr'];

        return md5($magic);
    }

    /**
     * Discover and initialize session
     *
     * @return null
     */
    public static function startup()
    {
        global $PPHP;

        $sessionSeconds = $PPHP['config']['session']['gc_maxlifetime'] * 60;
        // Start a PHP-handled session and bind it to the current remote IP address as
        // a precaution per https://www.owasp.org/index.php/PHP_Security_Cheat_Sheet
        // We'll go one step further in _magic() and throw in User Agent details.
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_gc_divisor', 1000);
        ini_set('session.gc_maxlifetime', $sessionSeconds);
        session_save_path($PPHP['dir'] . '/var/sessions');
        ini_set('session.cookie_lifetime', 0);  // Session
        ini_set('session.cookie_httponly', true);
        session_start();
        if (isset($_SESSION['magic'])) {
            if ($_SESSION['magic'] !== self::_magic()) {
                self::reset();
            };
        } else {
            self::_init();
        };
    }

    /**
     * Clean up and save session
     *
     * @return null
     */
    public static function shutdown()
    {
        session_write_close();
    }

    /**
     * Populate session with fresh starting values
     *
     * @return null
     */
    private static function _init()
    {
        $_SESSION['magic'] = self::_magic();
        $_SESSION['user'] = null;
        $_SESSION['identified'] = false;
    }

    /**
     * Wipe out and re-initialize current session
     *
     * @return null
     */
    public static function reset()
    {
        session_unset();
        self::_init();
    }

    /**
     * Attempt to attach a user to current session
     *
     * @param string $email    E-mail address
     * @param string $password Plain text password (supplied via web form)
     * @param string $onetime  One-time password instead of password
     *
     * @return bool Whether the operation succeeded
     */
    public static function login($email, $password, $onetime = '')
    {
        $oldId = false;
        if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
            $oldId = $_SESSION['user']['id'];
        };
        if (is_array($user = grab('authenticate', $email, $password, $onetime))) {
            if ($oldId !== $user['id']) {
                trigger(
                    'log',
                    array(
                        'userId'     => $user['id'],
                        'objectType' => 'user',
                        'objectId'   => $user['id'],
                        'action'     => 'login'
                    )
                );
                self::reset();
            };
            $_SESSION['user'] = $user;
            $_SESSION['identified'] = true;
            trigger('newuser');
            if (pass('can', 'login')) {
                return true;
            } else {
                self::reset();
                return false;
            };
        } else {
            return false;
        };
    }

    /**
     * Refresh session cache of user data
     *
     * @param array $data New user information
     *
     * @return null
     */
    public static function reloadUser($data)
    {
        $_SESSION['user'] = $data;
    }

    /**
     * Produce an opaque form name unique to current session
     *
     * @param string $form_name Application-wide unique name for this form
     *
     * @return string
     */
    private static function _formHash($form_name)
    {
        return 'form' . md5($form_name . session_id());
    }

    /**
     * Generate hidden input for form validation
     *
     * @param string $name Name/ID of the form
     *
     * @return string The HTML
     */
    public static function beginForm($name)
    {
        $name = self::_formHash($name);
        $token = md5(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
        $_SESSION[$name] = $token;
        return '<input type="hidden" name="'.$name.'" value="'.$token.'" />';
    }

    /**
     * Validate POST form based on name/session
     *
     * @param string $name Name/ID of the form
     *
     * @return bool
     */
    public static function validateForm($name)
    {
        $req = grab('request');
        $name = self::_formHash($name);
        $sess = (isset($_SESSION[$name]) ? $_SESSION[$name] : false);
        $_SESSION[$name] = ' ';
        unset($_SESSION[$name]);
        if ($sess && isset($req['post'][$name]) && $req['post'][$name] === $sess) {
            unset($req['post'][$name]);
            return true;
        } else {
            $req['post'] = array();
            return false;
        };
    }
}

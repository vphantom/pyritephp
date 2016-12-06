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
    return array_pop(call_user_func_array('trigger', func_get_args()));
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
    return array_pop(call_user_func_array('trigger', func_get_args())) !== false;
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

        // Watchdog
        $watchdog = new Pyrite\Core\Watchdog();
        if (array_key_exists('mail_errors_to', $PPHP['config']['global'])) {
            $watchdog->notify($PPHP['config']['global']['mail_errors_to']);
        };

        // Database
        $PPHP['db'] = new Pyrite\Core\PDB($PPHP['config']['db']['type'] . ':' . $PPHP['dir'] . '/' . $PPHP['config']['db']['sqlite_path']);

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
        if (array_key_exists('base_title', $PPHP['config']['global'])) {
            trigger('title', $PPHP['config']['global']['base_title']);
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
}

add_filter('clean_email', 'Pyrite::cleanEmail');
add_filter('clean_name',  'Pyrite::cleanName');

// Included modules which have start-up definitions
Pyrite\ACL::bootstrap();
Pyrite\AuditTrail::bootstrap();
Pyrite\Router::bootstrap();
Pyrite\Session::bootstrap();
Pyrite\Sendmail::bootstrap();
Pyrite\Templating::bootstrap();
Pyrite\Users::bootstrap();

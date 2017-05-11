<?php

/**
 * URL Router
 *
 * PHP version 5
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
 */

namespace Pyrite;

/**
 * Router class
 *
 * PHP version 5
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
 */
class Router
{
    private static $_base = null;
    private static $_PATH = array();
    private static $_req = array();

    /**
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('startup',           'Pyrite\Router::initRequest', 1);
        on('cli_startup',       'Pyrite\Router::initCLI', 1);
        on('startup',           'Pyrite\Router::startup', 50);
        on('request',           'Pyrite\Router::getRequest');
        on('http_status',       'Pyrite\Router::setStatus');
        on('http_redirect',     'Pyrite\Router::setRedirect');
        on('warning',           'Pyrite\Router::addWarning');
        on('no_fatal_warnings', 'Pyrite\Router::checkNoFatalWarnings');
    }

    /**
     * Get probable browser IP address
     *
     * @return string
     */
    private static function _remoteIP()
    {
        // Catch all possible hints of the client's IP address, not just REMOTE_ADDR
        foreach (
            array(
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'REMOTE_ADDR'
            )
            as $key
        ) {
            if (array_key_exists($key, $_SERVER)) {
                foreach (dejoin(',', $_SERVER[$key]) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        // Return the first one found
                        return $ip;
                    };
                };
            };
        };
        return null;
    }

    /**
     * Initialization common to CLI and web
     *
     * @return null
     */
    private static function _initCommon()
    {
        global $PPHP;

        self::$_req['warnings'] = array();
        self::$_req['status'] = 200;
        self::$_req['redirect'] = false;
        self::$_req['protocol'] = (self::$_req['ssl'] ? 'https' : 'http');
    }

    /**
     * Initialize request for CLI runs
     *
     * @return null
     */
    public static function initCLI()
    {
        global $PPHP;

        self::$_PATH = array();
        self::$_req['lang']         = $PPHP['config']['global']['default_lang'];
        self::$_req['default_lang'] = $PPHP['config']['global']['default_lang'];
        self::$_req['base'] = '';
        self::$_req['binary'] = true;  // Keep layout template quiet
        self::$_req['path'] = '';
        self::$_req['query'] = '';
        self::$_req['host'] = $PPHP['config']['global']['host'];
        self::$_req['remote_addr'] = '127.0.0.1';
        self::$_req['ssl'] = $PPHP['config']['global']['use_ssl'];
        self::$_req['get'] = array();
        self::$_req['post'] = array();
        self::$_req['path_args'] = array();

        self::_initCommon();

        trigger('language', self::$_req['lang']);
    }

    /**
     * Start populating request details
     *
     * @return null
     */
    public static function initRequest()
    {
        global $PPHP;

        $parsedURL = parse_url($_SERVER['REQUEST_URI']);
        self::$_PATH = dejoin('/', trim($parsedURL['path'], '/'));
        while (count(self::$_PATH) > 0 && self::$_PATH[0] === '') {
            array_shift(self::$_PATH);
        };

        // Eat up initial directory as language if it's 2 characters
        $lang = $PPHP['config']['global']['default_lang'];
        if (isset(self::$_PATH[0]) && strlen(self::$_PATH[0]) === 2) {
            $lang = strtolower(array_shift(self::$_PATH));
        };
        self::$_req['lang'] = $lang;
        self::$_req['default_lang'] = $PPHP['config']['global']['default_lang'];
        self::$_req['base'] = ($lang === $PPHP['config']['global']['default_lang'] ? '' : "/{$lang}");

        // Eat up initial directories as long as they contain request flags
        self::$_req['binary'] = false;
        while (count(self::$_PATH) > 0 && self::$_PATH[0][0] === '=') {
            $flag = strtolower(array_shift(self::$_PATH));
            if ($flag === '=bin') {
                self::$_req['binary'] = true;
            };
        };

        self::$_req['path'] = implode('/', self::$_PATH);
        self::$_req['query'] = (isset($parsedURL['query']) ? '?' . $parsedURL['query'] : '');
        self::$_req['host'] = $_SERVER['HTTP_HOST'];
        self::$_req['remote_addr'] = self::_remoteIP();
        self::$_req['ssl']
            = (
                (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                ||
                (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
                ||
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                ||
                (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            );
        self::$_req['get'] = $_GET;
        self::$_req['post'] = $_POST;

        foreach (array('get', 'post') as $method) {
            if (isset(self::$_req[$method]['__arrays'])) {
                foreach (self::$_req[$method]['__arrays'] as $key) {
                    if (!isset(self::$_req[$method][$key])) {
                        self::$_req[$method][$key] = array();
                    } elseif (!is_array(self::$_req[$method][$key])) {
                        self::$_req[$method][$key] = array(self::$_req[$method][$key]);
                    };
                };
            };
        };

        // Process file uploads
        self::$_req['files'] = array();
        if (isset($_FILES) && count($_FILES) > 0) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            foreach ($_FILES as $key => $info) {
                if (is_uploaded_file($info['tmp_name'])) {
                    self::$_req['files'][$key] = $info;
                    // Overwrite client's MIME Type with server's determination
                    self::$_req['files'][$key]['type'] = finfo_file($finfo, $info['tmp_name']);
                    foreach (pathinfo($info['name']) as $pik => $piv) {
                        self::$_req['files'][$key][$pik] = $piv;
                    };
                };
            };
            finfo_close($finfo);
        };

        self::_initCommon();
    }

    /**
     * Build route from requested URL
     *
     * - Extract language from initial '/xx/'
     * - Build route/foo+bar if handled, route/foo otherwise
     * - Default to route/main
     * - Trigger 404 status if not handled at all
     *
     * @return null
     */
    public static function startup()
    {
        global $PPHP;

        trigger('language', self::$_req['lang']);

        if (isset(self::$_PATH[1]) && listeners('route/' . self::$_PATH[0] . '+' . self::$_PATH[1])) {
            self::$_base = array_shift(self::$_PATH) . '+' . array_shift(self::$_PATH);
        } elseif (isset(self::$_PATH[0])) {
            if (listeners('route/' . self::$_PATH[0])) {
                self::$_base = array_shift(self::$_PATH);
            } else {
                trigger('http_status', 404);
            };
        } elseif (listeners('route/main')) {
            self::$_base = 'main';
        } else {
            trigger('http_status', 404);
        };
        self::$_req['path_args'] = self::$_PATH;
    }

    /**
     * Trigger handler for current route
     *
     * @return null
     */
    public static function run()
    {
        if (!pass('validate_request', 'route/' . self::$_base)) {
            return;
        };

        if (self::$_base !== null  &&  !pass('route/' . self::$_base, self::$_PATH)) {
            trigger('http_status', 500);
        };
    }

    /**
     * Return request data
     *
     * Keys provided:
     *
     * lang: Current language code
     * base: Prepend this before '/' to get an absolute URL for current language
     * path: Current component's URL
     * query: GET query string, if any
     * status: Integer of current HTTP status
     * redirect: False or string intended for URL redirection
     *
     * @return array Associative data
     */
    public static function getRequest()
    {
        return self::$_req;
    }

    /**
     * Set HTTP response status code
     *
     * @param int $code New code (between 100 and 599)
     *
     * @return null
     */
    public static function setStatus($code)
    {
        if ($code >= 100  &&  $code < 600) {
            self::$_req['status'] = $code;
        };
    }

    /**
     * Set HTTP redirect URL
     *
     * This only sets req.redirect: it is up to other components or templates
     * to act upon it.
     *
     * @param string $url The new location (can be relative)
     *
     * @return null
     */
    public static function setRedirect($url)
    {
        self::$_req['redirect'] = $url;
    }

    /**
     * Add warning to the list
     *
     * Severity level should be one of:
     *
     * 3: success
     * 2: info
     * 1: warning
     * 0: fatal
     *
     * Any other value will be replaced with 1.
     *
     * If $args is not an array, it will be used directly as a single argument
     * for convenience.
     *
     * @param string     $code  Warning code string
     * @param int|null   $level Lowest=worst severity level
     * @param mixed|null $args  Optional list of arguments
     *
     * @return null
     */
    public static function addWarning($code, $level = 1, $args = null)
    {
        if ($args === null) {
            // Callers may use null as well.
            $args = array();
        };
        if (!is_array($args)) {
            $args = array($args);
        };
        switch ($level) {
        case 0:
        case 1:
        case 2:
        case 3:
            break;
        default:
            $level = 1;
        };
        self::$_req['warnings'][] = array($level, $code, $args);
    }

    /**
     * Check that there are no fatal warnings pending
     *
     * @return bool Returns true if NO fatal warnings are registered
     */
    public static function checkNoFatalWarnings()
    {
        foreach (self::$_req['warnings'] as $warning) {
            if ($warning[0] == 0) {
                return false;
            };
        };
        return true;
    }
}

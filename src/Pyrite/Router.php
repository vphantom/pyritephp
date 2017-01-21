<?php

/**
 * URL Router
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
 * Router class
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
        on('startup',       'Pyrite\Router::initRequest', 1);
        on('startup',       'Pyrite\Router::startup', 50);
        on('request',       'Pyrite\Router::getRequest');
        on('http_status',   'Pyrite\Router::setStatus');
        on('http_redirect', 'Pyrite\Router::setRedirect');
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
                foreach (explode(',', $_SERVER[$key]) as $ip) {
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
     * Start populating request details
     *
     * @return null
     */
    public static function initRequest()
    {
        global $PPHP;

        self::$_req['status'] = 200;
        self::$_req['redirect'] = false;
        $parsedURL = parse_url($_SERVER['REQUEST_URI']);
        self::$_PATH = explode('/', trim($parsedURL['path'], '/'));
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
        while (self::$_PATH[0][0] === '=') {
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
        self::$_req['protocol'] = (self::$_req['ssl'] ? 'https' : 'http');
        self::$_req['get'] = $_GET;
        self::$_req['post'] = $_POST;

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
}

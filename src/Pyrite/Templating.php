<?php

/**
 * Templating
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
 * Templating class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */
class Templating
{
    private static $_twig;
    private static $_title = '';
    private static $_status = 200;
    private static $_template;
    private static $_safeBody = '';
    private static $_lang = 'en';  // Could be '', paranoid precaution

    /**
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('startup',       'Pyrite\Templating::startup', 99);
        on('shutdown',      'Pyrite\Templating::shutdown', 1);
        on('render',        'Pyrite\Templating::render');
        on('render_blocks', 'Pyrite\Templating::renderBlocks');
        on('title',         'Pyrite\Templating::title');
        on('http_status',   'Pyrite\Templating::status');
        on('language',      'Pyrite\Templating::setLang');
    }

    /**
     * Initialize wrapper around Twig templating and display headers
     *
     * @return null
     */
    public static function startup()
    {
        global $PPHP;
        $req = grab('request');

        self::$_lang = $req['lang'];
        $tplBase = $PPHP['dir'] . '/templates';

        $twigLoader = new \Twig_Loader_Filesystem();

        // Don't choke if language from URL is bogus
        try {
            $twigLoader->addPath($tplBase . '/' . self::$_lang);
        } catch (\Exception $e) {
        };

        // Be nice, don't even choke if templates aren't sorted by language
        if (self::$_lang !== $PPHP['config']['global']['default_lang']) {
            try {
                $twigLoader->addPath($tplBase . '/' . $PPHP['config']['global']['default_lang']);
            } catch (\Exception $e) {
            };
        };

        $twigLoader->addPath($tplBase);
        $twig = new \Twig_Environment(
            $twigLoader,
            array(
                // 'cache' => __DIR__ . '/var/twig_cache',
                'autoescape' => true,
                'debug' => $PPHP['config']['global']['debug']
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'grab', function () {
                    return array_pop(call_user_func_array('trigger', func_get_args()));
                }
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'pass', function () {
                    return array_pop(call_user_func_array('trigger', func_get_args())) !== false;
                }
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'filter', function () {
                    return call_user_func_array('filter', func_get_args());
                }
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'title', function () {
                    return call_user_func_array('self::title', func_get_args());
                }
            )
        );

        // Add debugging tools
        if ($PPHP['config']['global']['debug']) {
            $twig->addExtension(new \Twig_Extension_Debug());
            $twig->addFunction(
                new \Twig_SimpleFunction(
                    'debug', function () {
                        return implode('', func_get_args());
                    }
                )
            );
        } else {
            $twig->addFunction(
                new \Twig_SimpleFunction(
                    'debug', function () {
                        return '';
                    }
                )
            );
            // Don't trust Twig to always mute dump() when debugging's off.
            $twig->addFunction(
                new \Twig_SimpleFunction(
                    'dump', function () {
                        return '';
                    }
                )
            );
        };

        // Load utilities globally
        try {
            $twig->addGlobal('lib', $twig->loadTemplate('lib'));
        } catch (\Exception $e) {
            echo $e->getMessage();
        };

        self::$_twig = $twig;

        try {
            self::$_template = $twig->loadTemplate('layout.html');

            if (self::$_status !== 200) {
                http_response_code(self::$_status);
            };
            self::$_template->displayBlock(
                'head',
                array(
                    'session' => $_SESSION,
                    'req' => grab('request')
                )
            );
        } catch (\Exception $e) {
            echo $e->getMessage();
        };
        flush();
        ob_start();
    }

    /**
     * Clean up content capture and display main template
     *
     * @return null
     */
    public static function shutdown()
    {
        $body = ob_get_contents();
        ob_end_clean();
        try {
            self::$_template->displayBlock(
                'body',
                array(
                    'title' => self::$_title,
                    'body' => self::$_safeBody,
                    'stdout' => $body,
                    'session' => $_SESSION,
                    'req' => grab('request')
                )
            );
        } catch (\Exception $e) {
            echo $e->getMessage();
        };
    }

    /**
     * Set current language
     *
     * @param string $code Two-letter language code
     *
     * @return null
     */
    public static function setLang($code)
    {
        self::$_lang = $code;
    }

    /**
     * Set HTTP response status code
     *
     * @param int $code New code (between 100 and 599)
     *
     * @return null
     */
    public static function status($code)
    {
        if ($code >= 100  &&  $code < 600) {
            self::$_status = (int)$code;
        };
    }

    /**
     * Prepend new section to page title
     *
     * @param string $prepend New section of title text
     * @param string $sep     Separator with current title
     *
     * @return null
     */
    public static function title($prepend, $sep = ' - ')
    {
        self::$_title = $prepend . (self::$_title !== '' ? ($sep . self::$_title) : '');
    }

    /**
     * Render a template file
     *
     * @param string $name File name from within templates/
     * @param array  $args Associative array of variables to pass along
     *
     * @return null
     */
    public static function render($name, $args = array())
    {
        $env = array_merge(
            $args,
            array(
                'title' => self::$_title,
                'session' => $_SESSION,
                'req' => grab('request')
            )
        );
        try {
            self::$_safeBody .= self::$_twig->render($name, $env);
        } catch (\Exception $e) {
            echo $e->getMessage();
        };
    }

    /**
     * Render all blocks from a template
     *
     * @param string $name File name from within templates/
     * @param array  $args Associative array of variables to pass along
     *
     * @return array Associative array of all blocks rendered from the template
     */
    public static function renderBlocks($name, $args = array())
    {
        $env = array_merge(
            $args,
            array(
                'session' => $_SESSION,
                'req' => grab('request')
            )
        );
        try {
            $template = self::$_twig->loadTemplate($name);
            $blockNames = $template->getBlockNames($env);
            $results = array();
            foreach ($blockNames as $blockName) {
                $results[$blockName] = $template->renderBlock($blockName, $env);
            };
        } catch (\Exception $e) {
            echo $e->getMessage();
        };
        return $results;
    }
}

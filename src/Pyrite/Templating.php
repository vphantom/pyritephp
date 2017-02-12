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
 * Pyrite_Twig_ExitNode class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */
class Pyrite_Twig_ExitNode extends \Twig_Node
{
    /**
     * Constructor
     *
     * @param object $line from getLine()
     * @param object $tag  from getTag()
     */
    public function __construct($line, $tag = null)
    {
        parent::__construct(array(), array(), $line, $tag);
    }

    /**
     * Compile tag into PHP
     *
     * @param object $compiler Twig_Compiler
     *
     * @return null
     */
    public function compile(\Twig_Compiler $compiler)
    {
        $compiler->addDebugInfo($this)->write("return;\n");
    }
}

/**
 * Pyrite_Twig_TokenParser class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */
class Pyrite_Twig_TokenParser extends \Twig_TokenParser
{
    /**
     * Invoked by parser when "exit" tag encountered.
     *
     * @param object $token Current token
     *
     * @return object
     */
    public function parse(\Twig_Token $token)
    {
        $parser = $this->parser;
        $stream = $parser->getStream();

        $stream->expect(\Twig_Token::BLOCK_END_TYPE);

        return new Pyrite_Twig_ExitNode($token->getLine(), $this->getTag());
    }

    /**
     * Returns the tag we want to parse
     *
     * @return string
     */
    public function getTag()
    {
        return 'exit';
    }
}


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
    private static $_gettext;
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
        $twigConfig = array(
            'autoescape' => 'html',
            'debug' => $PPHP['config']['global']['debug']
        );
        if ($PPHP['config']['global']['production'] === true) {
            $twigConfig['cache'] = $PPHP['config']['global']['docroot'] . $PPHP['config']['global']['twig_path'];
        };
        $twig = new \Twig_Environment($twigLoader, $twigConfig);
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'grab', function () {
                    $result = call_user_func_array('trigger', func_get_args());
                    return array_pop($result);
                }
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'pass', function () {
                    $result = call_user_func_array('trigger', func_get_args());
                    return array_pop($result) !== false;
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
                '__', function () {
                    return call_user_func_array('self::gettext', func_get_args());
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

        $twig->addExtension(new \Twig_Extensions_Extension_Text());

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

        // exit tag
        $twig->addTokenParser(new Pyrite_Twig_TokenParser());

        self::$_twig = $twig;

        // Non-system gettext init
        $localeDir = $PPHP['dir'] . '/locales/';
        self::$_gettext = new \Gettext\Translator();
        $pos = array($localeDir . self::$_lang . '.po');
        if (self::$_lang !== $PPHP['config']['global']['default_lang']) {
            $pos[] = $localeDir . $PPHP['config']['global']['default_lang'] . '.po';
        };
        foreach ($pos as $po) {
            if (file_exists($po)) {
                try {
                    self::$_gettext->loadTranslations(\Gettext\Translations::fromPoFile($po));
                    break;
                } catch (\Exception $e) {
                    echo $e->getMessage();
                };
            };
        };

        $req = grab('request');
        if (self::$_status !== 200) {
            http_response_code(self::$_status);
        };
        if (!$req['binary']) {
            try {
                self::$_template = $twig->loadTemplate('layout.html');

                self::$_template->displayBlock(
                    'head',
                    array(
                        'config' => $PPHP['config'],
                        'session' => $_SESSION,
                        'req' => $req
                    )
                );
            } catch (\Exception $e) {
                echo $e->getMessage();
            };
            flush();
            ob_start();
        };
    }

    /**
     * Clean up content capture and display main template
     *
     * @return null
     */
    public static function shutdown()
    {
        global $PPHP;
        $req = grab('request');

        if (!$req['binary']) {
            $body = ob_get_contents();
            ob_end_clean();
            try {
                self::$_template->displayBlock(
                    'body',
                    array(
                        'title' => self::$_title,
                        'body' => self::$_safeBody,
                        'stdout' => $body,
                        'config' => $PPHP['config'],
                        'session' => $_SESSION,
                        'req' => grab('request')
                    )
                );
            } catch (\Exception $e) {
                echo $e->getMessage();
            };
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
     * Simple no-plurals translation lookup in config.ini
     *
     * @param string $string String
     *
     * @return string Translated string in current locale
     */
    function gettext($string)
    {
        global $PPHP;
        $req = grab('request');

        $default = $PPHP['config']['global']['default_lang'];
        $current = $req['lang'];

        $res = $string;
        try {
            if (self::$_gettext !== null) {
                $res = self::$_gettext->gettext($string);
            };
        } catch (\Exception $e) {
            echo $e->getMessage();
        };
        return $res;
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
        global $PPHP;

        $env = array_merge(
            $args,
            array(
                'title' => self::$_title,
                'config' => $PPHP['config'],
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
        global $PPHP;

        $env = array_merge(
            $args,
            array(
                'config' => $PPHP['config'],
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

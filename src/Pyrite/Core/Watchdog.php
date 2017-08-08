<?php

/**
 * Watchdog
 *
 * Receive e-mail backtraces when web application crashes
 *
 * Just require watchdog.php if you wish to enable it.
 *
 * Call $watchdog->notify('your@email.address') to enable e-mail reporting.
 * (Optionally, add a second "from address" argument.)
 *
 * PHP version 5
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2006-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/pyritephp
 */

namespace Pyrite\Core;

/**
 * Watchdog class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2006-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/pyritephp
 */
class Watchdog
{
    private $_email;
    private $_from;
    private $_tripped;

    /**
     * Constructor
     *
     * @return ErrorHandler
     */
    public function __construct()
    {
        ini_set('ignore_repeated_errors', true);
        $this->_tripped = false;
        $this->_email = null;
        $this->_file = null;
        // Remove E_NOTICE if you don't want to be _insanely_ picky.
        set_error_handler(
            array(&$this, 'handler'),
            E_WARNING | E_USER_ERROR | E_USER_WARNING
        );
    }

    /**
     * Enable saving to a log file
     *
     * @param string $path Path of the file to append to
     *
     * @return null
     */
    public function logfile($path)
    {
        $this->_file = $path;
    }

    /**
     * Enable e-mail notification
     *
     * @param string      $email E-mail address to report to
     * @param string|null $from  Address the message shoule be "from"
     *
     * @return null
     */
    public function notify($email, $from = null)
    {
        $this->_email = $email;
        $this->_from = $from;
    }

    /**
     * Callback for PHP's error management
     *
     * @param int    $level   Level
     * @param string $message Message
     * @param string $file    File
     * @param int    $line    Line number
     * @param mixed  $ctx     Unused, but required
     *
     * @return null
     */
    public function handler($level, $message, $file, $line, $ctx)
    {
        if ($this->_tripped) {
            return;
        }
        $this->_tripped = true;
        $out = '';
        if (!$this->_email) {
            $out .= "<pre>";
        }
        $out .= wordwrap(strip_tags($message), 76)."\n\n";
        @$info = Array(
            'Client IP'     => $_SERVER['REMOTE_ADDR'],
            'User agent'    => $_SERVER['HTTP_USER_AGENT'],
            'Accept'        => $_SERVER['HTTP_ACCEPT'],
            'Accept encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'],
            'Accept language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'],
            'Host'          => $_SERVER['HTTP_HOST'],
            'URI'           => $_SERVER['REQUEST_URI'],
            'Path info'     => $_SERVER['PATH_INFO'],
            'Method'        => $_SERVER['REQUEST_METHOD'],
            'Referer'       => (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : ''),
            'Cookie'        => $_SERVER['HTTP_COOKIE']
        );
        foreach ($info as $name => $val) {
            if ($val) {
                $out .= " {$name}: {$val}\n";
            }
        }

        $out .= "\nCGI PARAMETERS:\n";
        foreach ($_REQUEST as $name => $val) {
            $out .= " {$name} = " . print_r($val, true) . "\n";
        }

        $stack = debug_backtrace();
        $i = 0;
        $out .= "\nSTACK:\n";
        foreach ($stack as $point) {
            // Ignoring key 'args'
            $out .= " " . $i++ . ": "
                . (array_key_exists('file', $point) ? $point['file'] . '#' . $point['line'] . ': ' : '')
                . (array_key_exists('class', $point) ? $point['class'] . '::' : '')
                . $point['function'] . "("
                . (array_key_exists('args', $point) ? $this->_args($point['args']) : '')
                . ")\n";
        }
        $out .= "\nFULL ENVIRONMENT:\n" . print_r($GLOBALS, true);
        if ($this->_email || $this->_file) {

            // E-mail bug report
            if ($this->_email) {
                if ($this->_from) {
                    mail(
                        $this->_email,
                        "Error in {$_SERVER['PHP_SELF']}",
                        $out,
                        'From: ' . $this->_from
                    );
                } else {
                    mail($this->_email, "Error in {$_SERVER['PHP_SELF']}", $out);
                }
            };

            // Save bug report to file
            if ($this->_file) {
                try {
                    file_put_contents(
                        $this->_file,
                        "\n----- BEGIN CRASH REPORT -----\n    Timestamp: " . date('Y-m-d H:i:s O') . "\n    Source: {$_SERVER['PHP_SELF']}\n{$out}\n----- END CRASH REPORT -----\n",
                        FILE_APPEND | LOCK_EX
                    );
                } catch (\Exception $e) {
                };
            };

            // Terminate request, telling user that the error was logged.
            @header('Content-Type: text/html');
            echo "<p>It looks like an error occurred in our application.\n";
            echo "We're very sorry about that.  Details about the error\n";
            echo "have been logged and sent to a technician right away.</p>\n";
            exit();
        } else {
            echo "{$out}\n</pre>\n";
        }
    }

    /**
     * Unroll arguments
     *
     * @param array $array Arguments
     *
     * @return string
     */
    private function _args($array)
    {
        $result = '';
        if (is_array($array)) foreach ($array as $arg) {
            if ($result != '') {
                $result .= ', ';
            }
            $type = gettype($arg);
            switch ($type) {

            case 'boolean':
            case 'integer':
            case 'double':
                $result .= $arg;
                break;

            case 'string':
                $xarg = trim($arg, "\x00..\x1F");
                if (strlen($xarg) > 64) {
                    $result .= "'". htmlspecialchars(substr($xarg, 0, 64)) ."...'";
                } else {
                    $result .= "'" . $xarg . "'";
                }
                break;

            case 'array':
                $result .= '_ARRAY_';
                break;

            case 'object':
                $result .= '_OBJECT_';
                break;

            case 'resource':
                $result .= '_RESOURCE_';
                break;

            case 'NULL':
                $result .= '_NULL_';
                break;

            default:

            }
        }
        return($result);
    }

}

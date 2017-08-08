<?php

/**
 * Filters
 *
 * Various utility filters
 *
 * PHP Version 5
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2008-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/pyritephp
 */

namespace Pyrite\Core;

/**
 * Filters
 *
 * Various utility filters
 *
 * PHP Version 5
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2008-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/pyritephp
 */

class Filters
{

    /**
     * Sanitize file name
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
    public static function cleanFilename($name)
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '', preg_replace('/\s+/', '_', $name));
    }

    /**
     * Sanitize file name
     *
     * This is for BASE file names: in addition to the file name filter, this
     * also removes dots.
     *
     * @param string $name String to filter
     *
     * @return string
     */
    public static function cleanBaseFilename($name)
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', preg_replace('/\s+/', '_', $name));
    }

    /**
     * Sanitize and lowercase e-mail address
     *
     * @param string $email String to filter
     *
     * @return string
     */
    public static function cleanEmail($email)
    {
        // filter_var()'s FILTER_SANITIZE_EMAIL is way too permissive
        return strtolower(preg_replace('/[^a-zA-Z0-9@.,_+-]/', '', $email));
    }

    /**
     * Strip low-ASCII and <>`|\"' from string
     *
     * @param string $name String to filter
     *
     * @return string
     */
    public static function cleanName($name)
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
    public static function protectEmail($email)
    {
        $chunks = explode('@', $email);
        $chunks[0] = substr($chunks[0], 0, 2) . '****';
        return implode('@', $chunks);
    }

    /**
     * Convert an HTML string to a plain text approximation
     *
     * This is a quick and dirty hack for the purpose of creating text/plain
     * alternatives to text/html E-mail messages.  There are a lot of edge
     * cases which are not handled well or at all.
     *
     * @param string $html Source HTML document (with or without HTML/HEAD/BODY)
     * 
     * @return string
     */
    public static function html2text($html)
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();

        // Hack to force UTF-8 processing on incomplete documents
        // From: http://php.net/manual/en/domdocument.loadhtml.php#95251
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($doc->childNodes as $item) {
            if ($item->nodeType == XML_PI_NODE) {
                $doc->removeChild($item);  // Remove the hack we inserted above
                break;  // We know there is only one
            };
        };
        $doc->encoding = 'UTF-8';  // Proper way to set encoding

        return self::_dom2text($doc);
    }

    /**
     * Recursively traverse a DOMNode to generate text
     *
     * @param DOMNode $doc    Document
     * @param bool    $inline (Optional, used in recursion) Current inline status
     * @param int     $indent (Optional, used in recursion) Current indent depth
     * @param bool    $child  (Optional) Whether this is a recursive invocation
     *
     * @return string
     */
    private static function _dom2text(\DOMNode $doc, $inline = false, $indent = 0, $child = false)
    {
        $out = '';
        $lis = 0;

        foreach ($doc->childNodes as $node) {
            // Skip if it has class 'no-text'
            if ($node->nodeType === XML_ELEMENT_NODE
                && $node->hasAttribute('class')
                && preg_match('/(^no-text$)|(^no-text\s)|(\sno-text$)|(\sno-text\s)/', $node->getAttribute('class')) === 1
            ) {
                continue;
            };

            $inner = preg_replace('/\s+/', ' ', $node->nodeValue);

            // 1. Preparation phase
            switch ($node->nodeName) {
            case 'blockquote':
                $indent++;
            case 'p':
            case 'div':
            case 'li':
                $lis++;
            case 'dt':
            case 'dd':
                $inline = true;
                break;

            case 'ul':
            case 'ol':
            case 'dl':
                $indent++;
                break;
            };

            // 2. Fetch contents
            if ($node->hasChildNodes()) {
                $inner = self::_dom2text($node, $inline, $indent, true);
            };

            // 3. Output
            switch ($node->nodeName) {

            // INLINE
            case 'br':
                $out .= "<:break:>";
                break;

            case 'i':
            case 'em':
                $out .= '_' . $inner . '_';
                break;

            case 'b':
            case 'strong':
                $out .= '__' . $inner . '__';
                break;

            case 'img':
                if ($node->hasAttribute('alt')) {
                    $out .= '[' . $node->getAttribute('alt') . ']';
                };
                break;

            case 'a':
                if ($node->hasAttribute('href')) {
                    $out .= '<' . $node->getAttribute('href') . '>';
                } else {
                    $out .= $inner;
                };
                break;

            // BLOCK
            case 'ol':
            case 'ul':
                $indent--;
                $out .= '' . trim($inner);
                if ($indent == 0) {
                    $out .= "<:break:><:break:>";
                };
                break;

            case 'p':
            case 'div':
                $inline = false;
                $out .= '' . trim($inner) . "<:break:><:break:>";
                break;

            case 'blockquote':
                if ($indent > 1) {
                    $out .= "<:break:>";
                };
                $out .= str_repeat('> ', $indent) . trim($inner);
                $out .= "<:break:>";
                $indent--;
                if ($indent > 0) {
                    $out .= str_repeat('> ', $indent);
                };
                if ($indent == 0) {
                    $out .= "<:break:>";
                    $inline = false;
                };
                break;

            case 'dl':
                $indent--;
                $out .= '' . trim($inner);
                break;

            case 'li':
                if ($indent > 1 && $lis < 2) {
                    $out .= "<:break:>";
                };
                if ($indent > 0 && $lis > 1) {
                    $out .= "<:break:>";
                };
                $out .= '' . str_repeat('<:indent:>', $indent) . "* " . trim($inner);
                if ($indent == 0) {
                    $inline = false;
                };
                break;

            case 'dt':
                $out .= trim($inner) . ":<:break:>";
                $inline = false;
                break;
            case 'dd':
                $out .= str_repeat('<:indent:>', $indent) . trim($inner) . "<:break:><:break:>";
                $inline = false;
                break;

            default:
                $out .= '' . ($inline ? $inner : trim($inner)) . '';
            };
        };
        return $child ? $out : preg_replace(array('/<:break:>/', '/<:indent:>/'), array("\n", '  '), $out);
    }
}

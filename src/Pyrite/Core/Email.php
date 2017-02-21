<?php

/**
 * Email
 *
 * Create/send multipart MIME messages
 *
 * Facilitates the creation of MIME compatible messages. It has useful
 * features like easy creation of alternative bodies (i.e. plain+html) and
 * multiple file attachments. It is *not* a complete implementation, but it is
 * very small which suits my needs.
 * 
 * Example:
 * 
 *     require_once 'email.php';
 *     mb_internal_encoding('UTF-8');
 *     $msg = new Email();
 *     $msg->charset = 'UTF-8';
 *     $msg->to = "Someone <foo@example.com>";
 *     $msg->from = "Myself <bar@example.com>";
 *     $msg->subject = "Friendly reminder service";
 *     $msg->addText("Hello Someone,\n\nThis is your friendly reminder.\n");
 *     $msg->addFile('image/png', '/tmp/test-file.png', 'reminder.png');
 *     $msg->send();
 * 
 * Basically, you instantiate the <email> class, set a few headers, add some
 * content parts (at least one) and either build to a string using <build()>
 * or send using PHP's mail() with <send()>.
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
 * Class Email
 *
 * Create/send multipart MIME messages
 *
 * PHP Version 5
 *
 * @category  Library
 * @package   Email
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2008-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/php-library
 */
class Email
{
    // Used by <buildParts()> to output MIME greeting only once.
    protected $bpRoot;

    // Arrayhash of headers. Values are not encoded here.
    protected $envelope;

    // Indexed array of parts. Each part is an arrayhash with plenty of header
    // information possibly included (see <email_parse()> for a full list) and
    // a 'data' key with either a string or another indexed array to represent
    // sub-parts.
    protected $parts;

    // Character set to assume for all text parts attached. Default: 'UTF-8'.
    // Be sure to explicitly set PHP's mb_internal_encoding() to the same
    // character set as this property, or else headers will not be encoded
    // properly.
    public $charset;

    /**
     * Property overloading
     *
     * To define any header, set a property of the same name.  If the header name contains
     * dashes, use underscores instead and they will be converted to dashes.  For example:
     *
     *     $msg = new Email();
     *     $msg->X_Mailer_Info = "My Custom Mailer v0.15";
     * 
     * @param string $name  Name which will have underscores changed to dashes
     * @param string $value Contents of the header
     *
     * @return null
     */
    public function __set($name, $value)
    {
        $this->envelope[strtr(strtolower($name), '_', '-')] = $value;
    }

    /**
     * Constructor
     *
     * @return object Email instance
     */
    public function __construct()
    {
        $this->envelope = Array();
        $this->parts = Array();
        $this->MIME_Version = '1.0';
        $this->charset = 'UTF-8';
        $this->bpRoot = true;
    }

    /**
     * Attempt Q and B encodings, returns shortest with preference for Q.
     *
     * @param string $s String to encode
     *
     * @return string
     */
    protected function qBestEncoding($s)
    {
        $q = mb_encode_mimeheader($s, $this->charset, 'Q', "\n");
        $b = mb_encode_mimeheader($s, $this->charset, 'B', "\n");
        return (strlen($q) > strlen($b)) ? $b : $q;
    }

    /**
     * Encode a header part with max-length in mind
     *
     * Encode a header part with max-length in mind. PHP's
     * mb_encode_mimeheader() used by <qBestEncoding()> splits lines to stay
     * within 75 characters wide. This doesn't work with our <headerEncode()>
     * which takes care of encoding the shortest pieces possible, instead of
     * PHP's default behavior of encoding the entire header or nothing.
     *
     * As a work-around, this wrapper is given a prefix which should be the
     * header line(s), including name, prior to the portion to be encoded. A
     * bogus prefix is added temporarily to PHP's mb_encode_mimeheader() and
     * is subsequently removed to guarantee that the final header line(s) will
     * not exceed 75 characters.
     *
     * @param string $prefix The header string so far
     * @param string $string The new portion to be encoded
     *
     * @return string Encoded string with shortest of 'Q' or 'B' encoding, * preferring 'Q'.
     */
    protected function qEncode($prefix, $string)
    {
        if (strlen($prefix) < 68  &&  strpos($prefix, "\n") === false) {
            $p = str_repeat('X', strlen($prefix)-1) . ': ';
            return substr($this->qBestEncoding($p . $string), strlen($p));
        } else {
            return $this->qBestEncoding($string);
        };
    }

    /**
     * Prepare a header line for RFC822 compliance
     * 
     * The header's value is encoded if high-ASCII characters are found, for
     * the shortest portions possible (not splitting words). Addresses are
     * never encoded. A maximum width of 75 characters is respected.
     *
     * @param string $name  The header's name (left of ':')
     * @param string $value The header's full value
     *
     * @return string which should be RFC822-compliant (width, encoding, etc.)
     */
    protected function headerEncode($name, $value)
    {
        $out = ucfirst($name) . ':';
        $words = explode(' ', $value);
        $bin = '';
        $tmp = '';
        foreach ($words as $word) {
            if ($bin) {
                if (ctype_print($word)) {
                    if ($word[0] == '<') {
                        $out .= ' ' . $this->qEncode($out, $bin) . $tmp . ' ' . $word;
                        $bin = '';
                        $tmp = '';
                    } else {
                        $tmp .= ' ' . $word;
                    };
                } else {
                    $bin .= $tmp . ' ' . $word;
                    $tmp = '';
                };
            } else {
                if (ctype_print($word)) {
                    $out .= ' ' . $word;
                } else {
                    $bin = $word;
                };
            };
        };
        if ($bin) {
            if ($out[strlen($out)-1] != ' ') $out .= ' ';
            $out .= $this->qEncode($out, $bin) . $tmp;
        };
        return wordwrap($out, 75, "\n ");
    }

    /**
     * Add useful defaults to a part arrayhash
     *
     * Specifically, it adds character set information if it's missing. It
     * also adds "filename" if "name" is supplied but not "filename". Finally,
     * if "name" is supplied and type isn't "message/*", "data" is encoded in
     * Base64, "encoding" is changed accordingly, and "disposition" is set to
     * "attachment".
     *
     * @param array $part The arrayhash to augment
     *
     * @return array The input part with possibly some defaults added
     */
    protected function mkPart($part)
    {
        if (preg_match('/^text\//i', $part['type'])) {
            if (!isset($part['charset'])) $part['charset'] = $this->charset;
        } elseif (!preg_match('/^message\//i', $part['type'])) {
            $part['data'] = chunk_split(base64_encode($part['data']));
            $part['encoding'] = 'base64';
            $part['disposition'] = 'attachment';
            if (!isset($part['filename']) && isset($part['name'])) $part['filename'] = $part['name'];
        };
        return $part;
    }

    /**
     * Build body parts of message
     *
     * Invoked by <build()> after headers are created. This is the core of the
     * multipart support. It is called recursively if sub-parts are
     * encountered in the source structure.
     *
     * @param array  $parts Parts to output
     * @param string $type  MIME type of the current context
     *
     * @return string The MIME compliant body content
     */
    protected function buildParts($parts, $type = 'multipart/mixed')
    {
        $out = '';
        $boundary = uniqid('PHPemail');
        if (count($parts) > 1) {
            $out .= "Content-Type: {$type}; boundary=\"$boundary\"\n\n";
            if ($this->bpRoot) {
                $out .= "This is a multipart message in MIME format.\n\n";
                $this->bpRoot = false;
            };
            $out .= '--' . $boundary;
            foreach ($parts as $part) {
                if (is_array($part['data'])) {
                    $out .= "\n" . $this->buildParts($part['data'], $part['type']);
                } else {
                    $out .= "\nContent-Type: {$part['type']}";
                    if (isset($part['charset'])) {
                        $out .= "; charset=\"{$part['charset']}\"";
                    };
                    if (isset($part['name'])) {
                        $out .= "; name=\"{$part['name']}\"";
                    };
                    if (isset($part['disposition'])) {
                        $out .= "\nContent-Disposition: {$part['disposition']}";
                        if (isset($part['filename'])) {
                            $out .= "; filename=\"{$part['filename']}\"";
                        };
                    };
                    if (isset($part['encoding'])) {
                        $out .= "\nContent-Transfer-Encoding: {$part['encoding']}";
                    } else {
                        $out .= "\nContent-Transfer-Encoding: 8bit";
                    };
                    $out .= "\n\n" . $part['data'];
                };
                $out .= "\n--{$boundary}";
            };
            $out .= "--\n";
        } elseif (count($parts) == 1) {
            if (is_array($parts[0]['data'])) {
                $out .= $this->buildParts($parts[0]['data'], $parts[0]['type']);
            } else {
                $out .= "Content-Type: {$parts[0]['type']}";
                if (isset($parts[0]['charset'])) {
                    $out .= "; charset=\"{$parts[0]['charset']}\"";
                };
                if (isset($parts[0]['name'])) {
                    $out .= "; name=\"{$parts[0]['name']}\"";
                };
                if (isset($parts[0]['disposition'])) {
                    $out .= "\nContent-Disposition: {$parts[0]['disposition']}";
                    if (isset($parts[0]['filename'])) {
                        $out .= "; filename=\"{$parts[0]['name']}\"";
                    };
                };
                if (isset($parts[0]['encoding'])) {
                    $out .= "\nContent-Transfer-Encoding: {$part[0]['encoding']}";
                } else {
                    $out .= "\nContent-Transfer-Encoding: 8bit";
                };
                $out .= "\n\n{$parts[0]['data']}";
            };
        };
        return $out;
    }

    /**
     * Add a raw data part to message
     *
     * NETIQUETTE: You should add text and HTML parts before any binary file
     * attachments.
     *
     * @param string $type        MIME type of the attachment (i.e. 'text/plain')
     * @param string $displayname File name to suggest to user
     * @param mixed  $data        Actual raw data to attach
     *
     * @return null
     */
    public function addData($type, $displayname, $data)
    {
        $this->parts[] = $this->mkPart(Array( 'type' => $type, 'name' => $displayname, 'data' => $data));
    }

    /**
     * Attach a file part to message
     *
     * NETIQUETTE: You should add text and HTML parts before any binary file
     * attachments.
     *
     * Note that the MIME type is automatically detected from the file itself.
     *
     * @param string $filepath    Path on local file system
     * @param string $displayname File name to suggest to user
     *
     * @return null
     */
    public function addFile($filepath, $displayname)
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileContents = file_get_contents($filepath);
        $mimeType = $finfo->buffer($fileContents);
        $this->addData($mimeType, $displayname, $fileContents);
    }

    /**
     * Attach plain text part to message
     *
     * @param string $text Content to attach
     *
     * @return null
     */
    public function addText($text)
    {
        $this->parts[] = $this->mkPart(Array( 'type' => 'text/plain', 'data' => $text));
    }

    /**
     * Attach HTML part to message
     *
     * @param string $html Content to attach
     *
     * @return null
     */
    public function addHTML($html)
    {
        $this->parts[] = $this->mkPart(Array( 'type' => 'text/html', 'data' => $html));
    }

    /**
     * Attach a pair of text and HTML equivalents to message
     *
     * This implements the 'multipart/alternative' type so viewers can expect
     * the text and HTML to represent the same content.
     *
     * @param string $text The plain text content
     * @param string $html The HTML equivalent content
     *
     * @return null
     */
    public function addTextHTML($text, $html)
    {
        $this->parts[] = Array(
            'type' => 'multipart/alternative',
            'data' => Array(
                $this->mkPart(Array( 'type' => 'text/plain', 'data' => $text )),
                $this->mkPart(Array( 'type' => 'text/html', 'data' => $html ))
            )
        );
    }

    /**
     * Build message to a string
     *
     * CAVEAT: If you intend to use PHP's mail(), you will need to split
     * headers from the body yourself since PHP needs headers separately.
     * Something like this:
     *
     *     // Assuming your email is $msg:
     *     $parts = preg_split('/\r?\n\r?\n/', $msg->build(true), 2);
     *     mail($msg->getTo(), $msg->getSubject(), $parts[1], $parts[0]);
     *
     * @param bool $skipTS Skip 'To:' and 'Subject:' headers. Useful for PHP's mail().
     *
     * @return string The entire message ready to send (i.e. via sendmail)
     */
    public function build($skipTS = false)
    {
        $out = '';
        foreach ($this->envelope as $name => $value) {
            if (!($skipTS && (($name == 'to') || ($name == 'subject')))) {
                $out .= $this->headerEncode($name, $value) . "\n";
            };
        };
        $this->bpRoot = true;
        $out .= $this->buildParts($this->parts);
        return $out;
    }

    /**
     * Build and immediately send message
     *
     * Note that you can modify some headers and call <build()> or <send()>
     * again on the current message.  This can be handy for mailing lists
     * where only the destination changes (and where using the "Bcc" field
     * isn't appropriate, that is.)
     *
     * Internally, this uses PHP's popen() to invoke your PHP configuration's
     * "sendmail_path" directly. This avoids the extra overhead and formatting
     * limitations of PHP's built-in mail().
     *
     * @return mixed FALSE if the pipe couldn't be opened, the termination status of the sendmail process otherwise.
     */
    public function send()
    {
        $result = false;
        if ($fp = popen(ini_get('sendmail_path'), 'w')) {
            fwrite($fp, $this->build());
            $result = pclose($fp);
        };
        return $result;
    }
}

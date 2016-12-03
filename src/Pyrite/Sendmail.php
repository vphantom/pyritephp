<?php

/**
 * Sendmail
 *
 * Send e-mails based on the Email class and templating events.
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
 * Sendmail class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */
class Sendmail
{
    /**
     * Send e-mail
     *
     * The template is not displayed directly, instead can contain blocks:
     *
     * subject - The subject line
     * text    - The plain text version, optional
     * html    - The rich text version, optional
     *
     * While it is recommended to provide both plain text and HTML versions
     * (lower SPAM scoring, more user-friendly under some circumstances)
     * either can be omitted.  If both are provided, they will be assembled in
     * a proper 'multipart/alternative' attachment.
     *
     * @param string $to       Destination e-mail address (or "Name <email>" combo)
     * @param string $template Template to load in 'templates/email/' (i.e. 'confirmlink')
     * @param array  $args     Arguments to pass to template
     *
     * @return bool Whether sending succeeded
     */
    public static function send($to, $template, $args = array())
    {
        global $PPHP;

        $blocks = grab('render_blocks', 'email/' . $template, $args);
        $msg = new \Pyrite\Core\Email();
        $msg->X_Mailer_Info = 'PyritePHP v1.0';
        $msg->to = $to;
        $msg->from = $PPHP['config']['global']['mail_from'];
        $msg->subject = $blocks['subject'];

        if (array_key_exists('text', $blocks) && array_key_exists('html', $blocks)) {
            $msg->addTextHTML($blocks['text'], $blocks['html']);
        } elseif (array_key_exists('html', $blocks)) {
            $msg->addHTML($blocks['html']);
        } elseif (array_key_exists('text', $blocks)) {
            $msg->addText($blocks['text']);
        } else {
            return false;
        };

        return ($msg->send() === 0);
    }

    /**
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('sendmail', 'Pyrite\Sendmail::send');
    }
}


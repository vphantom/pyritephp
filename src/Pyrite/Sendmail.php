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
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('install',      'Pyrite\Sendmail::install');
        on('outbox',       'Pyrite\Sendmail::getOutbox');
        on('outbox_email', 'Pyrite\Sendmail::getOutboxEmail');
        on('outbox_save',  'Pyrite\Sendmail::setOutboxEmail');
        on('outbox_send',  'Pyrite\Sendmail::sendOutboxEmail');
        on('sendmail',     'Pyrite\Sendmail::send');
    }

    /**
     * Create database tables if necessary
     *
     * @return null
     */
    public static function install()
    {
        global $PPHP;
        $db = $PPHP['db'];

        echo "    Installing emails...";
        $db->begin();
        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'emails' (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                sender     INTEGER NOT NULL DEFAULT '0',
                isSent     BOOL NOT NULL DEFAULT '0',
                modified   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                recipients VARCHAR(255) NOT NULL DEFAULT '',
                ccs        VARCHAR(255) NOT NULL DEFAULT '',
                bccs       VARCHAR(255) NOT NULL DEFAULT '',
                subject    TEXT NOT NULL DEFAULT '',
                html       TEXT NOT NULL DEFAULT '',
                FOREIGN KEY(sender) REFERENCES users(id)
            )
            "
        );
        $db->commit();
        echo "    done!\n";
    }

    /**
     * Get outbox e-mails, oldest first
     *
     * Only e-mails which were queued by the current user will be returned
     * normally.  If the user is admin and $all is true, then the whole queue
     * is returned.
     *
     * Omitted from the resulting data is the 'html' column.
     *
     * Added to the resulting data is a 'roles' array of relevant role names
     * for each e-mail.  A relevant role is any of users present in
     * 'recipients' field excluding the system-level 'admin' and 'member'
     * ones.
     *
     * Note that 'modified' is in UTC and 'localmodified' is added to the
     * results in the server's local timezone for convenience.
     *
     * @param bool $all (Optional.) Request the full mail queue
     *
     * @return array
     */
    public static function getOutbox($all = false)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $q = $db->query("SELECT id, sender, isSent, modified, datetime(modified, 'localtime') AS localmodified, recipients, ccs, bccs, subject FROM emails");
        $q->where('NOT isSent');
        if (!$all) {
            $q->and('sender = ?', $_SESSION['user']['id']);
        };
        $outbox = $db->selectArray($q);
        foreach ($outbox as $key => $email) {
            $roles = array();
            foreach (array('recipients', 'ccs', 'bccs') as $col) {
                $outbox[$key][$col] = explode(';', $outbox[$key][$col]);
                foreach ($outbox[$key][$col] as $uid) {
                    foreach (grab('user_roles', $uid) as $role) {
                        $roles[$role] = true;
                    };
                };
            };
            unset($roles['admin'], $roles['member']);
            $outbox[$key]['roles'] = array_keys($roles);
        };
        return $outbox;
    }

    /**
     * Fetch a single e-mail from user's outbox
     *
     * The e-mail will only be returned if it was queued by the current user,
     * unless the user has role 'admin'.
     *
     * @param int $id E-mail ID
     *
     * @return bool|array E-mail or false on failure
     */
    public static function getOutboxEmail($id)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $q = $db->query("SELECT *, datetime(modified, 'localtime') AS localmodified FROM emails");
        $q->where('id = ?', $id);
        if (!pass('has_role', 'admin')) {
            $q->and('sender = ?', $_SESSION['user']['id']);
        };
        $email = $db->selectSingleArray($q);
        if ($email !== false) {
            foreach (array('recipients', 'ccs', 'bccs') as $col) {
                $email[$col] = explode(';', $email[$col]);
            };
        };
        return $email;
    }

    /**
     * Insert/update an outbox e-mail
     *
     * @param int    $id      E-mail ID (null to create)
     * @param array  $to      Destination userIDs
     * @param array  $cc      Carbon-copy userIDs
     * @param array  $bcc     Blind carbon-copy userIDs
     * @param string $subject The subject line, ready to send
     * @param string $html    Rich text content, ready to send
     *
     * @return bool Whether the update was successful (possibly ID on success)
     */
    public static function setOutboxEmail($id, $to, $cc, $bcc, $subject, $html)
    {
        global $PPHP;
        $db = $PPHP['db'];

        if (!pass('can', 'edit', 'email', $id)) {
            return false;
        };

        $cols = array(
            'recipients' => implode(';', $to),
            'subject' => $subject,
            'html' => $html
        );
        if (is_array($cc)) {
            $cols['ccs'] = implode(';', $cc);
        };
        if (is_array($bcc)) {
            $cols['bccs'] = implode(';', $bcc);
        };

        if ($id) {
            $res = $db->update('emails', $cols, ", modified=datetime('now') WHERE id=?", array($id));
        } else {
            $res = $db->insert('emails', $cols);
        };

        return $res;
    }

    /**
     * Send an e-mail from the user's outbox
     *
     * @param int $id The e-mail ID
     *
     * @return bool Whether it succeeded
     */
    public static function sendOutboxEmail($id)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $cc = null;
        $bcc = null;

        $email = self::getOutboxEmail($id);
        if (!$email) {
            return false;
        };

        $to = self::_usersToRecipients($email['recipients']);
        $cc = self::_usersToRecipients($email['ccs']);
        $bcc = self::_usersToRecipients($email['bccs']);
        if (self::_sendmail($to, $cc, $bcc, $email['subject'], $email['html'])) {
            $db->begin();
            $db->update('emails', array('isSent' => true), 'WHERE id=?', array($id));
            trigger(
                'log',
                array(
                    'action' => 'send',
                    'objectType' => 'email',
                    'objectId' => $id
                )
            );
            trigger('outbox_changed');
            $db->commit();
            return true;
        };

        return false;
    }

    /**
     * Actually send an e-mail
     *
     * This is the utility function which invokes Pyrite\Core\Email per se.
     *
     * @param string $to      Destination e-mail address(es) (or "Name <email"> combos)
     * @param string $cc      Carbon-copy addresses (set null or '' to avoid)
     * @param string $bcc     Blind carbon-copy addresses (null/'' to avoid)
     * @param string $subject The subject line
     * @param string $html    Rich text content
     *
     * @return bool Whether it succeeded
     */
    private static function _sendmail($to, $cc, $bcc, $subject, $html)
    {
        global $PPHP;

        $msg = new \Pyrite\Core\Email();
        $msg->X_Mailer_Info = 'PyritePHP v1.0';
        $msg->to = $to;
        if ($cc && $cc !== '') {
            $msg->cc = $cc;
        };
        if ($bcc && $bcc !== '') {
            $msg->bcc = $bcc;
        };
        $msg->from = $PPHP['config']['global']['mail_from'];
        $msg->subject = $subject;
        $msg->addTextHTML(filter('html_to_text', $html), $html);
        return ($msg->send() === 0);
    }

    /**
     * Convert an array of userIDs to an RFC822 to/cc/bcc string
     *
     * @param array $users List of userIDs
     *
     * @return string The resulting string
     */
    private static function _usersToRecipients($users)
    {
        $out = array();
        foreach ($users as $id) {
            $user = grab('user_resolve', $id);
            if ($user !== false) {
                if ($user['name'] !== '') {
                    $out[] = '' . filter('clean_name', $user['name']) . ' <' . $user['email'] . '>';
                } else {
                    $out[] = $user['email'];
                };
            };
        };

        return implode(', ', $out);
    }

    /**
     * Send e-mail
     *
     * The template is not displayed directly, instead can contain blocks:
     *
     * subject - The subject line
     * html    - The rich text contents
     *
     * A rudimentary text version will be derived from the HTML version in
     * order to build a proper 'multipart/alternative' attachment.
     *
     * @param array|int      $to       Destination userID(s)
     * @param array|int|null $cc       (Optional) Carbon-copy userIDs
     * @param array|int|null $bcc      (Optional) Blind carbon-copy userIDs
     * @param string         $template Template to load in 'templates/email/' (i.e. 'confirmlink')
     * @param array          $args     Arguments to pass to template
     *
     * @return bool|int Whether sending succeeded, e-mail ID if one was created
     */
    public static function send($to, $cc, $bcc, $template, $args = array())
    {
        global $PPHP;

        $blocks = grab('render_blocks', 'email/' . $template, $args);

        if (!is_array($to)) {
            $to = array($to);
        };
        if ($cc !== null && !is_array($cc)) {
            $cc = array($cc);
        };
        if ($bcc !== null && !is_array($bcc)) {
            $bcc = array($bcc);
        };

        if (pass('can', 'edit', 'email')) {
            trigger('outbox_changed');
            return self::setOutboxEmail(null, $to, $cc, $bcc, $blocks['subject'], $blocks['html']);
        } else {
            $to = self::_usersToRecipients($to);
            $cc = self::_usersToRecipients($cc);
            $bcc = self::_usersToRecipients($bcc);
            return self::_sendmail($to, $cc, $bcc, $blocks['subject'], $blocks['html']);
        };
    }
}

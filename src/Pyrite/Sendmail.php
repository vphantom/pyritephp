<?php

/**
 * Sendmail
 *
 * Send e-mails based on the Email class and templating events, keeping a full
 * copy archived.
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
 * Sendmail class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
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
        on('install',       'Pyrite\Sendmail::install');
        on('outbox',        'Pyrite\Sendmail::getOutbox');
        on('outbox_email',  'Pyrite\Sendmail::getOutboxEmail');
        on('outbox_save',   'Pyrite\Sendmail::setOutboxEmail');
        on('outbox_delete', 'Pyrite\Sendmail::deleteOutboxEmail');
        on('outbox_send',   'Pyrite\Sendmail::sendOutboxEmail');
        on('sendmail',      'Pyrite\Sendmail::send');
        on('hourly',        'Pyrite\Sendmail::mailq');
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
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                sender      INTEGER NOT NULL DEFAULT '0',
                isSent      BOOL NOT NULL DEFAULT '0',
                modified    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                contextType VARCHAR(64) DEFAULT NULL,
                contextId   INTEGER DEFAULT NULL,
                mailfrom    INTEGER NOT NULL DEFAULT '0',
                recipients  VARCHAR(255) NOT NULL DEFAULT '',
                ccs         VARCHAR(255) NOT NULL DEFAULT '',
                bccs        VARCHAR(255) NOT NULL DEFAULT '',
                subject     TEXT NOT NULL DEFAULT '',
                html        TEXT NOT NULL DEFAULT '',
                files       BLOB,
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

        $q = $db->query("SELECT *, datetime(modified, 'localtime') AS localmodified FROM emails");
        $q->where('NOT isSent');
        if (!$all) {
            $q->and('sender = ?', $_SESSION['user']['id']);
        };
        $outbox = $db->selectArray($q);
        foreach ($outbox as $key => $email) {
            $roles = array();
            foreach (array('recipients', 'ccs', 'bccs') as $col) {
                $outbox[$key][$col] = dejoin(';', $outbox[$key][$col]);
                foreach ($outbox[$key][$col] as $uid) {
                    foreach (grab('user_roles', $uid) as $role) {
                        $roles[$role] = true;
                    };
                };
            };
            $outbox[$key]['files'] = json_decode($email['files']);
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
        $email = $db->selectSingleArray($q);
        if ($email !== false) {
            foreach (array('recipients', 'ccs', 'bccs') as $col) {
                $email[$col] = dejoin(';', $email[$col]);
            };
        };
        $email['files'] = json_decode($email['files'], true);
        return $email;
    }

    /**
     * Insert/update an outbox e-mail
     *
     * @param int    $id       E-mail ID (null to create)
     * @param int    $mailfrom Reply-To address
     * @param array  $to       Destination userIDs
     * @param array  $cc       Carbon-copy userIDs
     * @param array  $bcc      Blind carbon-copy userIDs
     * @param string $subject  The subject line, ready to send
     * @param string $html     Rich text content, ready to send
     * @param array  $files    (Optional) List of [name,bytes,type] associative arrays
     *
     * @return bool Whether the update was successful (possibly ID on success)
     */
    public static function setOutboxEmail($id, $mailfrom, $to, $cc, $bcc, $subject, $html, $files = null)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $sender = 0;
        if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
            $sender = $_SESSION['user']['id'];
        };
        $cols = array(
            'sender' => $sender,
            'recipients' => implode(';', $to),
            'subject' => $subject,
            'html' => $html
        );
        if ($mailfrom) {
                $cols['mailfrom'] = $mailfrom;
        };
        if (is_array($cc)) {
            $cols['ccs'] = implode(';', $cc);
        };
        if (is_array($bcc)) {
            $cols['bccs'] = implode(';', $bcc);
        };
        if (is_array($files)) {
            $cols['files'] = json_encode($files);
        };

        if ($id) {
            $res = $db->update('emails', $cols, ", modified=datetime('now') WHERE id=?", array($id));
        } else {
            // Define context if we're creating a new e-mail
            $cols['contextType'] = $PPHP['contextType'];
            $cols['contextId'] = $PPHP['contextId'];

            if ($files === null) {
                $cols['files'] = json_encode(array());
            };
            $res = $db->insert('emails', $cols);
        };

        trigger('outbox_changed');
        return $res;
    }

    /**
     * Delete an e-mail from outbox
     *
     * Limited to the current user's messages, unless user has role 'admin' in
     * which case all e-mails are fair game.
     *
     * @param int $id E-mail ID
     *
     * @return bool Whether the deletion was successful
     */
    public static function deleteOutboxEmail($id)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $q = $db->query('DELETE FROM emails WHERE id=?', $id);
        if (!pass('has_role', 'admin')) {
            $q->and('sender=?', $_SESSION['user']['id']);
        };
        $res = $db->exec($q);
        if ($res !== false) {
            trigger('outbox_changed');
        };
        return $res;
    }

    /**
     * Send an e-mail from the user's outbox
     *
     * Note that $tampered is TRUE by default to match v1.0.0 API where only
     * messages spooled in manual outboxes were then sent with this function.
     *
     * @param int       $id       The e-mail ID
     * @param bool|null $tampered Did it go through manual outbox?
     *
     * @return bool Whether it succeeded
     */
    public static function sendOutboxEmail($id, $tampered = true)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $mailfrom = null;
        $cc = null;
        $bcc = null;

        if (!$id) {
            return false;
        };
        $email = self::getOutboxEmail($id);
        if (!$email) {
            return false;
        };

        if ($email['mailfrom']) {
            $mailfrom = self::_usersToRecipients($email['mailfrom']);
        };
        $to = self::_usersToRecipients($email['recipients']);
        $cc = self::_usersToRecipients($email['ccs']);
        $bcc = self::_usersToRecipients($email['bccs']);
        if (self::_sendmail($mailfrom, $to, $cc, $bcc, $email['subject'], $email['html'], $email['files'])) {
            $db->update('emails', array('isSent' => true), 'WHERE id=?', array($id));
            $logData = array(
                'action' => 'emailed',
                'newValue' => $id
            );
            if (!$tampered) {
                $logData['userId'] = 0;
            };
            if ($email['contextType'] !== null && $email['contextId'] !== null) {
                $logData['objectType'] = $email['contextType'];
                $logData['objectId'] = $email['contextId'];
            };
            trigger('log', $logData);
            trigger('outbox_changed');
            return true;
        };

        return false;
    }

    /**
     * Actually send an e-mail
     *
     * This is the utility function which invokes Email() per se.
     *
     * Note that $file['path'] here is relative to the current document root.
     * Note that config.global.mail is used for the From field at all times.
     *
     * @param string $mailfrom Reply-To address
     * @param string $to       Destination e-mail address(es) (or "Name <email"> combos)
     * @param string $cc       Carbon-copy addresses (set null or '' to avoid)
     * @param string $bcc      Blind carbon-copy addresses (null/'' to avoid)
     * @param string $subject  The subject line
     * @param string $html     Rich text content
     * @param array  $files    List of [path,name,bytes,type] associative arrays
     *
     * @return bool Whether it succeeded
     */
    private static function _sendmail($mailfrom, $to, $cc, $bcc, $subject, $html, $files = array())
    {
        global $PPHP;

        $msg = new \Email();
        $msg->X_Mailer_Info = 'PyritePHP v1.0';
        $msg->to = $to;
        if ($cc && $cc !== '') {
            $msg->cc = $cc;
        };
        if ($bcc && $bcc !== '') {
            $msg->bcc = $bcc;
        };
        $msg->from = $PPHP['config']['global']['mail_from'];
        if ($mailfrom) {
            $msg->reply_to = $mailfrom;
        };
        $msg->subject = $subject;
        $msg->addTextHTML(filter('html_to_text', $html), $html);
        foreach ($files as $file) {
            $msg->addFile("{$PPHP['dir']}/{$file['path']}/{$file['name']}", $file['name'], $file['type']);
        };
        return ($msg->send() === 0);
    }

    /**
     * Convert an array of userIDs to an RFC822 to/cc/bcc string
     *
     * @param array $users List of userIDs
     *
     * @return string The resulting string, null on failure
     */
    private static function _usersToRecipients($users)
    {
        $out = array();

        if (!is_array($users)) {
            $users = array($users);
        };

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

        return (count($out) > 0) ? implode(', ', $out) : null;
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
     * @param array          $files    (Optional) List of [name,bytes,type] associative arrays
     * @param bool|null      $nodelay  (Optional) Set true to bypass outbox
     *
     * @return bool|int Whether sending succeeded, e-mail ID if one was created
     */
    public static function send($to, $cc, $bcc, $template, $args = array(), $files = array(), $nodelay = false)
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

        $email = self::setOutboxEmail(null, 0, $to, $cc, $bcc, $blocks['subject'], $blocks['html'], $files);

        if (pass('can', 'edit', 'email') && !$nodelay) {
            return $email;
        };
        return self::sendOutboxEmail($email, false);
    }

    /**
     * Mail queue run
     *
     * Any unsent messages last modified more than 60 minutes ago, with no
     * sender ('0') are sent because they were likely temporary failures.
     *
     * @return void
     */
    public static function mailq()
    {
        global $PPHP;
        $db = $PPHP['db'];

        $q = $db->query("SELECT id FROM emails");
        $q->where('NOT isSent');
        $q->and('sender = ?', 0);
        $q->and("modified < datetime('now', '-1 hour')");
        $queue = $db->selectList($q);
        foreach ($queue as $emailId) {
            self::sendOutboxEmail($emailId, false);
        };
    }
}

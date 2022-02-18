<?php

/**
 * AuditTrail
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
 * AuditTrail class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
 */
class AuditTrail
{
    /**
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('install',    'Pyrite\AuditTrail::install');
        on('log',        'Pyrite\AuditTrail::add');
        on('history',    'Pyrite\AuditTrail::get');
        on('user_seen',  'Pyrite\AuditTrail::getLastLogin');
        on('in_history', 'Pyrite\AuditTrail::getObjectIds');
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
        echo "    Installing log... ";

        $db->begin();
        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'transactions' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                userId INTEGER NOT NULL DEFAULT '0',
                actingUserId INTEGER NOT NULL DEFAULT '0',
                ip VARCHAR(16) NOT NULL DEFAULT '127.0.0.1',
                objectType VARCHAR(64) DEFAULT NULL,
                objectId INTEGER DEFAULT NULL,
                action VARCHAR(64) NOT NULL DEFAULT '',
                fieldName VARCHAR(64) DEFAULT NULL,
                oldValue VARCHAR(255) DEFAULT NULL,
                newValue VARCHAR(255) DEFAULT NULL,
                content TEXT NOT NULL DEFAULT ''
            )
            "
        );
        $db->exec(
            "
            CREATE INDEX idx_txn_peers
                ON transactions (userId, objectType, action, newValue)
            "
        );
        $db->commit();
        self::add(null, null, 'installed');
        echo "    done!\n";
    }

    /**
     * Add a new transaction to the audit trail
     *
     * Suggested minimum set of actions:
     *
     *     created
     *     modified
     *     deleted
     *
     * You can either use these positional arguments or specify a single
     * associative array argument with only the keys you need defined.
     *
     * At least an action should be specified (i.e. 'rebooted', perhaps) and
     * typically also objectType and objectId.  The rest is accessory.
     *
     * @param array|string    $objectType Class of object this applies to (*or args, see above)
     * @param string|int|null $objectId   Specific instance acted upon
     * @param string          $action     Type of action performed
     * @param string|null     $fieldName  Specific field affected
     * @param string|int|null $oldValue   Previous value for affected field
     * @param string|int|null $newValue   New value for affected field
     * @param string|int|null $userId     Over-ride session userId with this one
     * @param string|null     $content    Additional text to store in the entry
     *
     * @return null
     */
    public static function add($objectType, $objectId = null, $action = null, $fieldName = null, $oldValue = null, $newValue = null, $userId = 0, $content = '')
    {
        global $PPHP;
        $db = $PPHP['db'];

        $ip = '127.0.0.1';
        $req = grab('request');
        if (isset($req['remote_addr'])) {
            $ip = $req['remote_addr'];
        };

        // First argument could contain named arguments
        if (is_array($objectType)) {
            if (isset($objectType['objectId']))  $objectId  = $objectType['objectId'];
            if (isset($objectType['action']))    $action    = $objectType['action'];
            if (isset($objectType['fieldName'])) $fieldName = $objectType['fieldName'];
            if (isset($objectType['oldValue']))  $oldValue  = $objectType['oldValue'];
            if (isset($objectType['newValue']))  $newValue  = $objectType['newValue'];
            if (isset($objectType['userId']))    $userId    = $objectType['userId'];
            if (isset($objectType['content']))   $content   = $objectType['content'];
            if (isset($objectType['objectType'])) {
                $objectType = $objectType['objectType'];
            } else {
                $objectType = null;
            };
        };
        if ($objectType === null && $objectId === null && $PPHP['contextType'] !== null && $PPHP['contextId'] !== null) {
            $objectType = $PPHP['contextType'];
            $objectId = $PPHP['contextId'];
        };

        if (isset($_SESSION['user']['id'])) {
            $actingUserId = $_SESSION['user']['id'];
            if ($userId === 0) {
                $userId = $actingUserId;
            };
        };

        // Enforce size limit on field values
        $oldValue = substr($oldValue, 0, 250);
        $newValue = substr($newValue, 0, 250);

        $db->exec(
            "
            INSERT INTO transactions
            (actingUserId, userId, ip, objectType, objectId, action, fieldName, oldValue, newValue, content)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ",
            array(
                $actingUserId,
                $userId,
                $ip,
                $objectType,
                $objectId,
                $action,
                $fieldName,
                $oldValue,
                $newValue,
                $content
            )
        );
    }

    /**
     * Get chronological history for a given filter
     *
     * Any supplied argument represents a restriction to apply.  At least one
     * restriction is required; it is not allowed to load the entire global
     * history at once.
     *
     * Any argument can be a value to restrict for, or an array of values.
     * For example, to get logins and object creations of any type, $action
     * could be array('login','created').
     *
     * Note that 'timestamp' is in UTC and 'localtimestamp' is added to the
     * results in the server's local timezone for convenience.
     *
     * You can either use these positional arguments or specify a single
     * associative array argument with only the keys you need defined.
     *
     * @param array|int|null  $userId     Specific actor OR object (*or args, see above)
     * @param string|null     $objectType Class of object this applies to
     * @param string|int|null $objectId   Specific instance acted upon
     * @param string|null     $action     Type of action performed
     * @param string|null     $fieldName  Specific field affected
     * @param string|null     $order      Either 'DESC' or 'ASC'
     * @param int|null        $max        LIMIT rows returned
     * @param bool|null       $today      Restrict to current day
     * @param string|null     $begin      Oldest admissible date or timestamp
     * @param string|null     $end        Latest admissible date or timestamp
     *
     * @return array List of associative arrays, one per entry
     */
    public static function get($userId, $objectType = null, $objectId = null, $action = null, $fieldName = null, $order = 'ASC', $max = null, $today = false, $begin = null, $end = null)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $args = array();
        if (is_array($userId) && !isset($userId[0])) {
            $args = $userId;
            if (isset($args['order'])) {
                $order = $args['order'];
                unset($args['order']);
            };
            if (isset($args['max'])) {
                $max = $args['max'];
                unset($args['max']);
            };
            if (isset($args['today'])) {
                $today = $args['today'];
                unset($args['today']);
            };
            if (isset($args['begin'])) {
                $begin = $args['begin'];
                unset($args['begin']);
            };
            if (isset($args['end'])) {
                $end = $args['end'];
                unset($args['end']);
            };
        } else {
            if ($userId !== null)     $args['userId']     = $userId;
            if ($objectType !== null) $args['objectType'] = $objectType;
            if ($objectId !== null)   $args['objectId']   = $objectId;
            if ($action !== null)     $args['action']     = $action;
            if ($fieldName !== null)  $args['fieldName']  = $fieldName;
        };

        if (count($args) < 1 && $begin === null && $end === null) {
            return array();
        };

        $query = $db->query("SELECT *, datetime(timestamp, 'localtime') AS localtimestamp FROM transactions WHERE");
        $conditions = array();
        foreach ($args as $key => $val) {
            if (is_array($val)) {
                $chunkAlts = array();
                foreach ($val as $altVal) {
                    $chunkAlts[] = $db->query("{$key}=?", $altVal);
                };
                $conditions[] = $db->query()->implodeClosed('OR', $chunkAlts);
            } else {
                if ($key === 'userId') {
                    $chunkAlts = array();
                    $chunkAlts[] = $db->query('userId=?', $val);
                    $chunkAlts[] = $db->query("(objectType='user' AND objectId=?)", $val);
                    $conditions[] = $db->query()->implodeClosed('OR', $chunkAlts);
                } else {
                    $conditions[] = $db->query("{$key}=?", $val);
                };
            };
        };
        if ($today) {
            $conditions[] = $db->query("date(timestamp)=date('now')");
        };
        if ($begin) {
            $conditions[] = $db->query("date(timestamp) >= date(?)", $begin);
        };
        if ($end) {
            $conditions[] = $db->query("date(timestamp) <= date(?)", $end);
        };
        $query->implode('AND', $conditions);
        if (isset($args['objectType']) && ($begin !== null || $end !== null)) {
            $query->order_by("objectId $order, id $order");
        } else {
            $query->order_by('id ' . $order);
        };
        if ($max !== null) {
            $query->limit($max);
        };

        return $db->selectArray($query);
    }

    /**
     * Get user's last login details
     *
     * The last login is an associative array with the following keys:
     *
     * timestamp      - UTC
     * localtimestamp - Local time zone
     * ip             - IP address
     *
     * @param int $userId User ID
     *
     * @return array Last login, if any
     */
    public static function getLastLogin($userId)
    {
        global $PPHP;
        $db = $PPHP['db'];
        $last = $db->selectSingleArray(
            "
            SELECT timestamp, ip, datetime(timestamp, 'localtime') AS localtimestamp
            FROM transactions
            WHERE objectType='user' AND objectId=? AND action='login'
            ORDER BY id DESC
            LIMIT 1
            ",
            array(
                $userId
            )
        );
        return $last !== false ? $last : array('timestamp' => null, 'ip' => null);
    }

    /**
     * Get IDs with activity present
     *
     * Get all objectIds of $objectType for which there is activity.  If
     * $begin is specified, search period is restricted to transactions on and
     * after that date.  If $end is also specified, search period is further
     * restricted to end on that date, inclusively.
     *
     * @param string      $objectType Type of object to search for
     * @param string|null $begin      ('YYYY-MM-DD') Earliest date to match
     * @param string|null $end        ('YYYY-MM-DD') Last date to match
     *
     * @return array List of objectIds found
     */
    public static function getObjectIds($objectType, $begin = null, $end = null)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $q = $db->query("SELECT DISTINCT(objectId) FROM transactions WHERE objectType=?", $objectType);
        if ($begin !== null && $end !== null) {
            $q->and("timestamp BETWEEN date(?) AND date(?, '+1 day')", array($begin, $end));
        } elseif ($begin !== null) {
            $q->and("timestamp >= date(?)", $begin);
        } elseif ($end !== null) {
            $q->and("timestamp <= date(?, '+1 day')", $end);
        };
        $q->order_by('objectId ASC');

        return $db->selectList($q);
    }
}

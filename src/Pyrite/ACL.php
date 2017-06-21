<?php

/**
 * ACL
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
 * ACL class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
 */
class ACL
{

    /**
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('install',      'Pyrite\ACL::install');
        on('cli_startup',  'Pyrite\ACL::loadCLI', 20);
        on('newuser',      'Pyrite\ACL::reload');
        on('can',          'Pyrite\ACL::can');
        on('can_any',      'Pyrite\ACL::canAny');
        on('can_sql',      'Pyrite\ACL::sqlCondition');
        on('has_role',     'Pyrite\ACL::hasRole');
        on('grant',        'Pyrite\ACL::grant');
        on('revoke',       'Pyrite\ACL::revoke');
        on('user_roles',   'Pyrite\ACL::getRoles');
        on('user_rights',  'Pyrite\ACL::getUserACL');
        on('role_rights',  'Pyrite\ACL::getRoleACL');
        on('role_users',   'Pyrite\ACL::getRoleUsers');
        on('object_users', 'Pyrite\ACL::getObjectUsers');
        on('ban_user',     'Pyrite\ACL::banUser');
        on('unban_user',   'Pyrite\ACL::unbanUser');
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
        echo "    Installing ACL...";

        $db->begin();

        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'acl_roles' (
                role       VARCHAR(64) NOT NULL DEFAULT '',
                action     VARCHAR(64) NOT NULL DEFAULT '*',
                objectType VARCHAR(64) NOT NULL DEFAULT '*',
                objectId   INTEGER     NOT NULL DEFAULT '0'
            )
            "
        );
        $db->exec(
            "
            CREATE UNIQUE INDEX IF NOT EXISTS idx_acl_roles
            ON acl_roles (role, action, objectType, objectId)
            "
        );
        foreach ($PPHP['config']['acl']['grant'] as $right) {
            $rightParts = preg_split('/[\s]+/', $right, null, PREG_SPLIT_NO_EMPTY);
            if (!$db->selectAtom("SELECT role FROM acl_roles WHERE role=? AND action=? AND objectType=? AND objectId='0'", $rightParts)) {
                $db->exec("INSERT INTO acl_roles VALUES (?,?,?,'0')", $rightParts);
            };
        };

        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'acl_users' (
                userId     INTEGER     NOT NULL DEFAULT '0',
                action     VARCHAR(64) NOT NULL DEFAULT '*',
                objectType VARCHAR(64) NOT NULL DEFAULT '*',
                objectId   INTEGER     NOT NULL DEFAULT '0'
            )
            "
        );
        $db->exec(
            "
            CREATE UNIQUE INDEX IF NOT EXISTS idx_acl_users
            ON acl_users (userId, action, objectType, objectId)
            "
        );

        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'users_roles' (
                userId     INTEGER     NOT NULL DEFAULT '0',
                role       VARCHAR(64) NOT NULL DEFAULT ''
            )
            "
        );
        $db->exec(
            "
            CREATE UNIQUE INDEX IF NOT EXISTS idx_users_roles
            ON users_roles (userId, role)
            "
        );
        if (!$db->selectAtom("SELECT userId FROM users_roles WHERE userId='1' AND role='admin'")) {
            $db->exec("INSERT INTO users_roles VALUES ('1', 'admin')");
        };

        $db->commit();
        echo "    done!\n";
    }

    /**
     * Load a block of ACL rules to in-memory tree
     *
     * A right is described as the triplet: action, objectType, objectId.
     *
     * @param array $flat List of associative arrays describing rights
     *
     * @return null
     */
    private static function _load($flat)
    {
        if (is_array($flat) && !is_array($_SESSION['ACL_INFO']) && count($flat) > 0) {
            $_SESSION['ACL_INFO'] = array();
        };
        foreach ($flat as $row) {
            if (!array_key_exists($row['action'], $_SESSION['ACL_INFO'])) {
                $_SESSION['ACL_INFO'][$row['action']] = Array();
            }
            if (!array_key_exists($row['objectType'], $_SESSION['ACL_INFO'][$row['action']])) {
                $_SESSION['ACL_INFO'][$row['action']][$row['objectType']] = Array();
            };
            if (!in_array($row['objectId'], $_SESSION['ACL_INFO'][$row['action']][$row['objectType']])) {
                $_SESSION['ACL_INFO'][$row['action']][$row['objectType']][] = $row['objectId'];
            };
        };
    }

    /**
     * Create admin in-memory rights tree for CLI sessions
     *
     * @return null
     */
    public static function loadCLI()
    {
        $_SESSION['ACL_INFO'] = array(
            '*' => array(
                '*' => array(0)
            )
        );
        $_SESSION['ACL_ROLES'] = array('admin', 'member');
    }

    /**
     * Re-create in-memory rights tree based on session's current user
     *
     * @return null
     */
    public static function reload()
    {
        global $PPHP;
        $db = $PPHP['db'];
        $_SESSION['ACL_INFO'] = null;
        if (!array_key_exists('id', $_SESSION['user'])) {
            return;
        };
        $userId = $_SESSION['user']['id'];

        $flat = $db->selectArray(
            "
            SELECT action, objectType, objectId
            FROM acl_users
            WHERE userId=?
            ",
            array($userId)
        );
        self::_load($flat);

        $flat = $db->selectArray(
            "
            SELECT action, objectType, objectId
            FROM users_roles
            INNER JOIN acl_roles ON acl_roles.role=users_roles.role
            WHERE users_roles.userId=?
            ",
            array($userId)
        );
        self::_load($flat);

        $_SESSION['ACL_ROLES'] = self::getRoles($userId);
    }

    /**
     * Tests whether current user is a member of specified role
     *
     * @param string $role Role to test
     *
     * @return bool Whether the user has that role
     */
    public static function hasRole($role)
    {
        if (!isset($_SESSION['user'])) {
            return false;
        };
        if (!isset($_SESSION['ACL_ROLES'])) {
            return false;
        };
        return in_array($role, $_SESSION['ACL_ROLES']);
    }

    /**
     * Test whether current user is allowed an action
     *
     * An action is defined as the triplet: action, objectType, objectId.  At
     * least an action must be specified.  If no objectType is specified, the
     * right to all objectTypes for the action is required to succeed.
     * Similarly, if an objectType but no objectId is specified, the right to
     * all objects of that type is required to succeed.
     *
     * @param string $action     Action to test
     * @param string $objectType Class of object this applies to
     * @param string $objectId   Specific instance to be acted upon
     *
     * @return bool Whether the action is allowed
     */
    public static function can($action, $objectType = null, $objectId = null)
    {
        if (!isset($_SESSION['ACL_INFO'])) {
            return false;
        };
        $acl = $_SESSION['ACL_INFO'];

        foreach (array('*', $action) as $act) {
            if (array_key_exists($act, $acl)) {
                $acl2 = $acl[$act];
                foreach (array('*', $objectType) as $typ) {
                    if (array_key_exists($typ, $acl2)) {
                        $acl3 = $acl2[$typ];
                        if (in_array(0, $acl3) || in_array($objectId, $acl3)) {
                            return true;
                        };
                    };
                };
            };
        };

        return false;
    }

    /**
     * Is the current user allowed any specific IDs for this action/type?
     *
     * @param string $action     Action to test
     * @param string $objectType Class of object this applies to
     *
     * @return bool True if there are any explicit IDs for user or his role
     */
    public static function canAny($action, $objectType)
    {
        if (!isset($_SESSION['ACL_INFO'])) {
            return false;
        };
        $acl = $_SESSION['ACL_INFO'];
        foreach (array('*', $action) as $act) {
            if (array_key_exists($act, $acl)) {
                $acl2 = $acl[$act];
                foreach (array('*', $objectType) as $typ) {
                    if (array_key_exists($typ, $acl2)) {
                        foreach ($acl2[$typ] as $id) {
                            return true;
                        };
                    };
                };
            };
        };
        return false;
    }

    /**
     * Get SQL condition for matching objectIds user has right to
     *
     * This behaves identically to can(), except it returns one of:
     *
     * When there is no right: "(1=2)"
     * When a single Id is allowed: "{$columnName}=?"
     * When multiple Ids are allowed: "{$columnName} IN (?, ...)"
     * When every Id is allowed: "(1=1)"
     *
     * @param string $columnName Name of column to match Ids in your query
     * @param string $action     Action to test
     * @param string $objectType Class of object this applies to
     *
     * @return object PDBquery object
     */
    public static function sqlCondition($columnName, $action, $objectType = null)
    {
        global $PPHP;
        $db = $PPHP['db'];
        $sqlTRUE = '(1=1)';
        $sqlFALSE = '(1=2)';

        if (!isset($_SESSION['ACL_INFO'])) {
            return $db->query($sqlFALSE);
        };
        $acl = $_SESSION['ACL_INFO'];

        $granted = array();
        foreach (array('*', $action) as $act) {
            if (array_key_exists($act, $acl)) {
                $acl2 = $acl[$act];
                foreach (array('*', $objectType) as $typ) {
                    if (array_key_exists($typ, $acl2)) {
                        $granted = array_merge($granted, $acl2[$typ]);
                    };
                };
            };
        };
        if (in_array(0, $granted)) {
            return $db->query($sqlTRUE);
        } elseif (count($granted) === 1) {
            return $db->query("{$columnName}=?", $granted[0]);
        } elseif (count($granted) > 0) {
            return $db->query("{$columnName} IN")->varsClosed($granted);
        };
        return $db->query($sqlFALSE);
    }

    /**
     * Grant new right or role membership
     *
     * Three possible signatures:
     *
     * $userId, $role
     * ...grants role to user
     *
     * $userId, null, $action[, $objectType[, $objectId]]
     * ...grants right to user
     *
     * null, $role, $action[, $objectType[, $objectId]]
     * ...grants right to role
     *
     * @param int|null    $userId     User ID
     * @param string|null $role       Role
     * @param string|null $action     Action
     * @param string|null $objectType Object class
     * @param int|null    $objectId   Object ID
     *
     * @return bool Result of operation
     */
    public static function grant($userId = null, $role = null, $action = null, $objectType = '*', $objectId = 0)
    {
        global $PPHP;
        $db = $PPHP['db'];
        $success = false;

        if ($objectId === '') {
            $objectId = 0;
        };
        if ($userId !== null  &&  $role !== null) {
            $success = $db->insert(
                'users_roles',
                array(
                    'userId' => $userId,
                    'role'   => $role
                )
            );
        } elseif ($userId !== null  &&  $action !== null) {
            $success = $db->insert(
                'acl_users',
                array(
                    'userId'     => $userId,
                    'action'     => $action,
                    'objectType' => $objectType,
                    'objectId'   => $objectId
                )
            );
        } elseif ($role !== null  &&  $action !== null) {
            $success = $db->insert(
                'acl_roles',
                array(
                    'role'       => $role,
                    'action'     => $action,
                    'objectType' => $objectType,
                    'objectId'   => $objectId
                )
            );
        };

        if (($userId !== null
            && (isset($_SESSION['user']) && $_SESSION['user']['id'] == $userId))
            || ($userId === null && $role !== null && self::hasRole($role))
        ) {
            self::reload();
        };

        return $success;
    }

    /**
     * Revoke existing right or role membership
     *
     * Three possible signatures:
     *
     * $userId, $role
     * ...removes role from user
     *
     * $userId, null, $action[, $objectType[, $objectId]]
     * ... removes right from user
     *
     * null, $role, $action[, $objectType[, $objectId]]
     * ...removes right from role
     *
     * @param int|null    $userId     User ID
     * @param string|null $role       Role
     * @param string|null $action     Action
     * @param string|null $objectType Object class
     * @param int|null    $objectId   Object ID
     *
     * @return bool|int Result of operation
     */
    public static function revoke($userId = null, $role = null, $action = null, $objectType = '*', $objectId = 0)
    {
        global $PPHP;
        $success = $db = $PPHP['db'];

        if ($objectId === '') {
            $objectId = 0;
        };
        if ($userId !== null  &&  $role !== null) {
            $success = $db->exec(
                "
                DELETE FROM users_roles WHERE userId=? AND role=?
                ",
                array(
                    $userId,
                    $role
                )
            );
        } elseif ($userId !== null  &&  $action !== null) {
            $success = $db->exec(
                "
                DELETE FROM acl_users
                WHERE userId=? AND action=? AND objectType=? AND objectId=?
                ",
                array(
                    $userId,
                    $action,
                    $objectType,
                    $objectId
                )
            );
        } elseif ($role !== null  &&  $action !== null) {
            $success = $db->exec(
                "
                DELETE FROM acl_roles
                WHERE role=? AND action=? AND objectType=? AND objectId=?
                ",
                array(
                    $role,
                    $action,
                    $objectType,
                    $objectId
                )
            );
        };

        if (($userId !== null && $_SESSION['user']['id'] == $userId)
            || ($role !== null && self::hasRole($role))
        ) {
            self::reload();
        };

        return $success;
    }

    /**
     * Get roles for a user
     *
     * @param int $userId User ID
     *
     * @return array Any roles found
     */
    public static function getRoles($userId)
    {
        global $PPHP;
        $db = $PPHP['db'];
        $roles = $db->selectList("SELECT role FROM users_roles WHERE userId=? ORDER BY role ASC", array($userId));
        return $roles !== false ? $roles : array();
    }

    /**
     * Get permissions associated with a role
     *
     * Each permissions is an associative array with keys: action, objectType,
     * objectId.  Wildcards are respectively '*', '*' and 0.
     *
     * @param string $role Name of role
     *
     * @return array
     */
    public static function getRoleACL($role)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $flat = $db->selectArray(
            "
            SELECT action, objectType, objectId
            FROM acl_roles
            WHERE role=?
            ",
            array($role)
        );
        return is_array($flat) ? $flat : array();
    }

    /**
     * Get permissions associated with a user
     *
     * Each permissions is an associative array with keys: action, objectType,
     * objectId.  Wildcards are respectively '*', '*' and 0.
     *
     * @param string $userId Name of role
     *
     * @return array
     */
    public static function getUserACL($userId)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $flat = $db->selectArray(
            "
            SELECT action, objectType, objectId
            FROM acl_users
            WHERE userId=?
            ",
            array($userId)
        );
        return is_array($flat) ? $flat : array();
    }

    /**
     * List users which have a given role
     *
     * @param string $role Role to get users for
     *
     * @return array List of userIds
     */
    public static function getRoleUsers($role)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $out = $db->selectList(
            "
            SELECT userId FROM users_roles
            WHERE role=?
            ORDER BY userId ASC
            ",
            array($role)
        );
        return (is_array($out) ? $out : array());
    }

    /**
     * List users which have a specific right
     *
     * @param string $action     Action
     * @param string $objectType Object class
     * @param int    $objectId   Object ID
     *
     * @return array List of userIds
     */
    public static function getObjectUsers($action, $objectType, $objectId)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $out = $db->selectList(
            "
            SELECT userId FROM acl_users
            WHERE action=? AND objectType=? AND objectId=?
            ORDER BY userId ASC
            ",
            array(
                $action,
                $objectType,
                $objectId
            )
        );
        return (is_array($out) ? $out : array());
    }

    /**
     * When a user is banned, remove it from all ACL tables
     *
     * @param int $id User ID
     *
     * @return bool Success of the underlying operations
     */
    public static function banUser($id)
    {
        global $PPHP;
        $db = $PPHP['db'];
        $db->exec("DELETE FROM acl_users WHERE userId=?", array($id));
        $db->exec("DELETE FROM users_roles WHERE userId=?", array($id));
        return true;
    }

    /**
     * When a user is unbanned, add it to member group
     *
     * @param int $id User ID
     *
     * @return bool Success of the underlying operations
     */
    public static function unbanUser($id)
    {
        global $PPHP;
        $db = $PPHP['db'];
        trigger('grant', $id, 'member');
        return true;
    }
}

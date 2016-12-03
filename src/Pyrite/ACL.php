<?php

/**
 * ACL
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
 * ACL class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
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
        on('install',     'Pyrite\ACL::install');
        on('newuser',     'Pyrite\ACL::reload');
        on('can',         'Pyrite\ACL::can');
        on('grant',       'Pyrite\ACL::grant');
        on('revoke',      'Pyrite\ACL::revoke');
        on('user_roles',  'Pyrite\ACL::getRoles');
        on('user_rights', 'Pyrite\ACL::getUserACL');
        on('role_rights', 'Pyrite\ACL::getRoleACL');
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
        if (!$db->selectAtom("SELECT role FROM acl_roles WHERE role='admin'")) {
            $db->exec("INSERT INTO acl_roles VALUES ('admin', '*', '*', '0')");
        };
        if (!$db->selectAtom("SELECT role FROM acl_roles WHERE role='member'")) {
            $db->exec("INSERT INTO acl_roles VALUES ('member', 'login '*', '0')");
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

        if (array_key_exists('*', $acl)) {
            return true;
        };
        if (array_key_exists($action, $acl)) {
            $acl2 = $acl[$action];
            if (array_key_exists('*', $acl2)) {
                return true;
            };
            if (array_key_exists($objectType, $acl2)) {
                $acl3 = $acl2[$objectType];
                if (in_array(0, $acl3) || in_array($objectId, $acl3)) {
                    return true;
                };
            };
        };

        return false;
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

        if ($objectId === '') {
            $objectId = 0;
        };
        if ($userId !== null  &&  $role !== null) {
            return $db->insert(
                'users_roles',
                array(
                    'userId' => $userId,
                    'role'   => $role
                )
            );
        } elseif ($userId !== null  &&  $action !== null) {
            return $db->insert(
                'acl_users',
                array(
                    'userId'     => $userId,
                    'action'     => $action,
                    'objectType' => $objectType,
                    'objectId'   => $objectId
                )
            );
        } elseif ($role !== null  &&  $action !== null) {
            return $db->insert(
                'acl_roles',
                array(
                    'role'       => $role,
                    'action'     => $action,
                    'objectType' => $objectType,
                    'objectId'   => $objectId
                )
            );
        };

        return false;
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
        $db = $PPHP['db'];

        if ($objectId === '') {
            $objectId = 0;
        };
        if ($userId !== null  &&  $role !== null) {
            return $db->exec(
                "
                DELETE FROM users_roles WHERE userId=? AND role=?
                ",
                array(
                    $userId,
                    $role
                )
            );
        } elseif ($userId !== null  &&  $action !== null) {
            return $db->exec(
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
            return $db->exec(
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

        return false;
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
        $roles = $db->selectList("SELECT role FROM users_roles WHERE userId=?", array($userId));
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
}

<?php

/**
 * Users
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
 * Users class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyritephp
 */
class Users
{
    private static $_selectFrom = "SELECT *, CAST((julianday('now') - julianday(onetimeTime)) * 24 * 3600 AS INTEGER) AS onetimeElapsed FROM users";
    private static $_resolved = array();

    /**
     * Bootstrap: define event handlers
     *
     * @return null
     */
    public static function bootstrap()
    {
        on('install',        'Pyrite\Users::install');
        on('user_fromemail', 'Pyrite\Users::fromEmail');
        on('user_resolve',   'Pyrite\Users::resolve');
        on('authenticate',   'Pyrite\Users::login');
        on('user_update',    'Pyrite\Users::update');
        on('user_create',    'Pyrite\Users::create');
        on('user_search',    'Pyrite\Users::search');
        on('ban_user',       'Pyrite\Users::ban');
        on('unban_user',     'Pyrite\Users::unban');
        on('clean_userids',  'Pyrite\Users::cleanList');
        on('all_users',      'Pyrite\Users::listAll');
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

        echo "    Installing users...\n";
        $db->begin();
        $customs = '';
        if (isset($PPHP['config']['users']['fields'])) {
            foreach ($PPHP['config']['users']['fields'] as $name => $definition) {
                $customs .= "                {$name} {$definition},\n";
            };
        };
        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'users' (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                email        VARCHAR(255) NOT NULL DEFAULT '',
                active       BOOL NOT NULL DEFAULT '1',
                passwordHash VARCHAR(255) NOT NULL DEFAULT '*',
                onetimeHash  VARCHAR(255) NOT NULL DEFAULT '*',
                onetimeTime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                onetimeMax   INTEGER NOT NULL DEFAULT '0',
                {$customs}
                name         VARCHAR(255) NOT NULL DEFAULT ''
            )
            "
        );
        $db->exec(
            "
            CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email
            ON users (email)
            "
        );
        if (!$db->selectAtom("SELECT id FROM users WHERE id='1'")) {
            echo "Creating admin user...\n";
            $email = strtolower(readline("E-mail address: "));
            $pass1 = true;
            $pass2 = false;
            while ($pass1 !== $pass2) {
                if ($pass1 !== true) {
                    echo "  * Password confirmation mis-match.\n";
                };
                $pass1 = readline("Password: ");
                $pass2 = readline("Password again: ");
            };
            $db->exec(
                "
                INSERT INTO users
                (id, email, passwordHash, name)
                VALUES
                (1, ?, ?, ?)
                ",
                array(
                    $email,
                    password_hash($pass1, PASSWORD_DEFAULT),
                    'Administrator'
                )
            );
        };
        if (!$db->selectAtom("SELECT id FROM users WHERE id='0'")) {
            // Yes it's auto-increment, but we need clean foreign keys and a
            // default "system" to be 0.
            $db->exec(
                "
                INSERT INTO users (id, active, email, name)
                VALUES (?, ?, ?)
                ",
                array(
                    0,
                    0,
                    '*',
                    'System'
                )
            );
        };
        $db->commit();
        echo "    done!\n";
    }

    /**
     * Resolve a user ID to basic information
     *
     * This is a cached resolver, useful for display purposes but not for
     * fetching the most up to date user information.
     *
     * @param int $id User ID to resolve
     *
     * @return array|bool Associative array for the user or false if not found
     */
    public static function resolve($id)
    {
        global $PPHP;

        if (array_key_exists($id, self::$_resolved)) {
            return self::$_resolved[$id];
        };

        $db = $PPHP['db'];
        if ($user = $db->selectSingleArray("SELECT * FROM users WHERE id=?", array($id))) {
            return self::$_resolved[$id] = $user;
        };
        return false;
    }

    /**
     * Load a user by e-mail
     *
     * @param string $email  E-mail address
     * @param bool   $active (Optional) Require user be active, default true
     *
     * @return array|bool Associative array for the user or false if not found
     */
    public static function fromEmail($email, $active = true)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $email = strtolower($email);
        $isActive = ($active ? ' AND active ' : '');
        if ($user = $db->selectSingleArray(self::$_selectFrom . " WHERE email=? {$isActive}", array($email))) {
            return $user;
        };
        return false;
    }

    /**
     * Load and authenticate a user
     *
     * Does not trigger any events, so it is safe to use for temporary user
     * manipulation.  Authenticating using $onetime, however, immediately
     * invalidates that password.
     *
     * @param string $email    E-mail address
     * @param string $password Plain text password (supplied via web form)
     * @param string $onetime  (Optional) Use this one-time password instead
     *
     * @return array|bool Associative array for the user or false if not authorized
     */
    public static function login($email, $password, $onetime = '')
    {
        global $PPHP;
        $db = $PPHP['db'];
        $onetimeMaxLow  = $PPHP['config']['global']['onetime_lifetime'] * 60;
        $onetimeMaxHigh = $PPHP['config']['global']['invite_lifetime'] * 24 * 3600;

        if (($user = self::fromEmail($email)) !== false) {
            if ($onetime !== '') {
                // Make sure DB's onetimeMax is between our short onetime and long invite lifetimes.
                $onetimeMax = min(max($onetimeMaxLow, $user['onetimeMax']), $onetimeMaxHigh);
                if ($user['onetimeElapsed'] < $onetimeMax  &&  password_verify($onetime, $user['onetimeHash'])) {

                    // Invalidate immediately, don't wait for expiration
                    if (!$PPHP['config']['global']['onetime_multiple']) {
                        $db->update('users', array('onetimeHash' => '*'), 'WHERE id=?', array($user['id']));
                    };

                    // Make sure user has role 'member' now that a onetime worked
                    trigger('grant', $user['id'], 'member');
                    return $user;
                };
            } else {
                if (password_verify($password, $user['passwordHash'])) {
                    return $user;
                };
            };
        };

        return false;
    }

    /**
     * Update an existing user's information
     *
     * If an 'id' key is present in $cols, it is silently ignored.
     *
     * Special keys 'newpassword1' and 'newpassword2' trigger a re-computing
     * of 'passwordHash'.
     *
     * Special key 'onetime' triggers the creation of a new onetime token and
     * its 'onetimeHash', resets 'onetimeTime'.  If it contains a number, it
     * will be set as the preferred expiration, in seconds.
     *
     * @param int   $id   ID of the user to update
     * @param array $cols Associative array of columns to update
     *
     * @return string|bool Whether it succeeded, the one time token if appropriate
     */
    public static function update($id, $cols = array())
    {
        global $PPHP;
        $db = $PPHP['db'];
        $sessUserId = 0;

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $sessUserId = $_SESSION['user']['id'];
        };

        if (isset($cols['id'])) {
            unset($cols['id']);
        };

        if (isset($cols['newpassword1'])
            && strlen($cols['newpassword1']) >= 8
            && isset($cols['newpassword2'])
            && $cols['newpassword1'] === $cols['newpassword2']
        ) {
            $cols['passwordHash'] = password_hash($cols['newpassword1'], PASSWORD_DEFAULT);
            // Entries 'newpassword[12]' will be safely skipped by $db->update()
        };
        $ott = '';
        $onetime = null;
        if (isset($cols['onetime'])) {
            $ott = ", onetimeTime=datetime('now') ";
            $onetime = md5(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
            $cols['onetimeHash'] = password_hash($onetime, PASSWORD_DEFAULT);
            if (is_numeric($cols['onetime'])) {
                $cols['onetimeMax'] = $cols['onetime'];
            };
        };
        $result = $db->update(
            'users',
            $cols,
            $ott . 'WHERE id=?',
            array($id)
        );
        if ($result) {
            if ($sessUserId === $id) {
                trigger('user_changed', $db->selectSingleArray(self::$_selectFrom . " WHERE id=?", array($id)));
            };
            return ($onetime !== null ? $onetime : true);
        };

        return false;
    }

    /**
     * Create new user from information
     *
     * If an 'id' key is present in $cols, it is silently ignored.
     *
     * Special key 'password' is used to create 'passwordHash', instead of
     * being inserted directly.  Key 'token' is synonymous with 'password'.
     *
     * On success, returns an array containing the new userID.  This is to
     * make room for additional return values in certain circumstances.
     *
     * If special key 'onetime' is present, it will trigger the generation of
     * a one time password, which will be added to the resulting array.
     *
     * @param array $cols Associative array of columns to set
     *
     * @return array|bool Info on success, false on failure
     */
    public static function create($cols = array())
    {
        global $PPHP;
        $db = $PPHP['db'];
        $onetime = null;

        if (isset($cols['id'])) {
            unset($cols['id']);
        };

        $cols['email'] = strtolower($cols['email']);
        if (isset($cols['token']) && strlen($cols['token']) > 0) {
            $cols['password'] = $cols['token'];
        };
        if (isset($cols['password']) && strlen($cols['password']) > 0) {
            $cols['passwordHash'] = password_hash($cols['password'], PASSWORD_DEFAULT);
            // Else passwordHash will be '*' per table definition
        };
        if (isset($cols['onetime'])) {
            $onetime = md5(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
            $cols['onetimeHash'] = password_hash($onetime, PASSWORD_DEFAULT);
        };
        if (($result = $db->insert('users', $cols)) !== false) {
            $creator = $result;
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $creator = $_SESSION['user']['id'];
            };
            trigger(
                'log',
                array(
                    'userId'     => $creator,
                    'objectType' => 'user',
                    'objectId'   => $result,
                    'action'     => 'created'
                )
            );
            // If we're creating someone else's account, automatic approval of the e-mail address
            if (pass('can', 'create', 'user')) {
                trigger('grant', $result, 'member');
            };

        };
        return $result ? array($result, $onetime) : $result;
    }

    /**
     * Search directory of users
     *
     * For each matching user, each row returned is limited to id, active,
     * email and name.  Specifying no keyword is valid and returns all users.
     *
     * Keyword matching is done on e-mail, name, profession and employer.
     *
     * No more than 100 users will be returned.
     *
     * @param string|null $keyword Substring search
     *
     * @return array
     */
    public static function search($keyword = null)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $query = $db->query('SELECT id, email, active, name FROM users');
        $conditions = array();

        // Keyword matching
        if ($keyword !== null) {
            $search = array();
            $search[] = $db->query('email LIKE ?', strtolower("%{$keyword}%"));
            $search[] = $db->query('name LIKE ?', "%{$keyword}%");
            $search[] = $db->query('profession LIKE ?', "%{$keyword}%");
            $search[] = $db->query('employer LIKE ?', "%{$keyword}%");
            $conditions[] = $db->query()->implodeClosed('OR', $search);
        };

        if (count($conditions) > 0) {
            $query->append('WHERE')->implode('AND', $conditions);
        } else {
            $query->where('active');
        };
        $query->order_by('id DESC')->limit(100);
        return $db->selectArray($query);
    }

    /**
     * Ban user by turning off its active flag
     *
     * @param int $id User ID
     *
     * @return bool Success of the underlying operation
     */
    public static function ban($id)
    {
        global $PPHP;
        $db = $PPHP['db'];
        $res = $db->exec("UPDATE users SET active='0' WHERE id=?", array($id));
        return $res;
    }

    /**
     * Unban user by turning on its active flag
     *
     * @param int $id User ID
     *
     * @return bool Success of the underlying operation
     */
    public static function unban($id)
    {
        global $PPHP;
        $db = $PPHP['db'];
        return $db->exec("UPDATE users SET active='1' WHERE id=?", array($id));
    }

    /**
     * Resolve all items of a list into userIDs, preserving order
     *
     * - If an item is a valid active userID, it is left intact;
     *
     * - If an item is numeric but not a valid active userID, it is removed;
     *
     * - If an item is the e-mail address of a valid user, it is replaced with
     * that userID;
     *
     * - If an item is otherwise a string resembling an e-mail address, a new
     * user is created with that address and supplemental columns found in
     * $userData[$email] in order to replace it with a freshly created userID.
     *
     * - If $template is defined, any created user gets e-mailed an invitation
     * with a 'validation_link' present in that e-mail template.
     *
     * @param array       $list     Mixed list of numbers and e-mail addresses
     * @param array       $userData Extra columns for each user keyed by e-mail
     * @param string|null $template (Optional) Which template to e-mail new users
     *
     * @return array Clean list of valid userIDs
     */
    public static function cleanList($list, $userData, $template = null)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $list = array_map('strtolower', $list);

        $db->begin();

        $out = array();
        foreach ($list as $id) {
            if (is_numeric($id)) {
                $cleanId = $db->selectAtom('SELECT id FROM users WHERE id=?', array($id));
                if ($cleanId !== false) {
                    $out[] = $cleanId;
                };
            } elseif (preg_match('/^[^@ ]+@[^@ .]+\.[^@ ]+$/', $email) == 1) {
                $cleanId = $db->selectAtom('SELECT id FROM users WHERE email=?', array($id));
                if ($cleanId !== false) {
                    $out[] = $cleanId;
                } else {
                    if (isset($userData[$email])) {
                        $cols = $userData[$email];
                    } else {
                        $cols = array();
                    };
                    $cols['email'] = $email;
                    $newbie = self::create($cols);
                    if ($newbie !== false) {
                        $out[] = $newbie[0];
                        if ($template !== null) {
                            trigger('send_invite', $template, $newbie[0]);
                        };
                    };
                };
            };
        };

        $db->commit();

        return $out;
    }

    /**
     * List all active userIDs
     *
     * @return array List of active userIDs
     */
    public static function listAll()
    {
        global $PPHP;
        $db = $PPHP['db'];
        return $db->selectList('SELECT id FROM users WHERE active ORDER BY id ASC');
    }
}

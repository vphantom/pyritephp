<?php

/**
 * PDB
 *
 * Thin wrapper around PDO with convenience methods
 *
 * PHP Version 5
 *
 * @category  Library
 * @package   PDB
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/php-library
 */

namespace Pyrite\Core;


/**
 * PDBquery class
 *
 * @category  Library
 * @package   PDB
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/php-library
 */

class PDBquery
{
    private $_query;
    private $_args;

    /**
     * Constructor
     *
     * @param string $query (Optional) String portion of partial query
     * @param array  $args  (Optional) List of arguments for placeholders
     *
     * @return object PDBquery object
     */
    public function __construct($query = null, $args = null)
    {
        $this->_query = ($query !== null ? $query : '');
        $this->_args = array();
        if (is_array($args)) {
            foreach ($args as $arg) {
                $this->_args[] = $arg;  // Faster than array_merge() which copies
            };
        } elseif ($args !== null) {
            $this->_args[] = $args;
        };
    }

    /**
     * Get current query string
     *
     * @return string
     */
    public function getQuery()
    {
        return $this->_query;
    }

    /**
     * Get current list of arguments
     *
     * @return array
     */
    public function getArgs()
    {
        return $this->_args;
    }

    /**
     * Append to current query
     *
     * @param string|object $query String portion or other query
     * @param array         $args  (Optional) List of arguments
     *
     * @return object PDBquery this same object, for chaining
     */
    public function append($query, $args = null)
    {
        if ($query instanceof PDBquery) {
            $args = $query->getArgs();
            $query = $query->getQuery();
        };
        $this->_query .= ' ' . $query;
        if (is_array($args)) {
            foreach ($args as $arg) {
                $this->_args[] = $arg;  // Faster than array_merge() which copies
            };
        } elseif ($args !== null) {
            $this->_args[] = $args;
        };
        return $this;
    }

    /**
     * Append to query, with convenience keyword prepend
     *
     * Calling any unknown method appends the uppercased method's name to the
     * query and wraps to append() with its arguments.  Underscores in the
     * name are replaced with spaces, i.e. "order_by()" becomes "ORDER BY".
     *
     * Calling without any arguments is allowed.
     *
     * @param string $name   Per PHP docs, this is the method name
     * @param array  $params Per PHP docs, this lists any supplied arguments
     *
     * @return object PDBquery this same object, for chaining
     */
    public function __call($name, $params)
    {
        $name = strtoupper($name);
        $name = strtr($name, '_', ' ');
        $this->_query .= ' ' . $name;
        if (isset($params[0])) {
            return $this->append($params[0], isset($params[1]) ? $params[1] : null);
        };
        return $this;
    }

    /**
     * Append one/multiple straight values
     *
     * If there are multiple values in the array, they will be separated by
     * commas.
     *
     * @param array $args List of values to append (with placeholders)
     *
     * @return object PDBquery this same object, for chaining
     */
    public function vars($args)
    {
        $this->_query .= implode(',', array_fill(0, count($args), '?'));
        foreach ($args as $arg) {
            $this->_args[] = $arg;
        };
        return $this;
    }

    /**
     * Append multiple PDBquery objects
     *
     * @param string $glue    What to insert between joined queries
     * @param array  $queries PDBquery objects or strings to join
     *
     * @return object PDBquery this same object, for chaining
     */
    public function implode($glue, $queries)
    {
        $first = true;
        foreach ($queries as $query) {
            if ($first) {
                $first = false;
            } else {
                $this->_query .= ' ' . $glue;
            };
            $this->append($query);
        };
        return $this;
    }

    /**
     * Append one/multiple straight values, wrapped in parenthesis
     *
     * This is identical to vars() except '(' and ')' are wrapped
     * around the insertion.
     *
     * @param array $args List of values to append (with placeholders)
     *
     * @return object PDBquery this same object, for chaining
     */
    public function varsClosed($args)
    {
        $this->_query .= ' (';
        $this->vars($args);
        $this->_query .= ' )';
        return $this;
    }

    /**
     * Append multiple PDBquery objects, wrapped in parenthesis
     *
     * This is identical to our implode() except '(' and ')' are wrapped
     * around the insertion.
     *
     * @param string $glue    What to insert between joined queries
     * @param array  $queries PDBquery objects or strings to join
     *
     * @return object PDBquery this same object, for chaining
     */
    public function implodeClosed($glue, $queries)
    {
        $this->_query .= ' (';
        $this->implode($glue, $queries);
        $this->_query .= ' )';
        return $this;
    }
}


/**
 * PDB class
 *
 * @category  Library
 * @package   PDB
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016-2017 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT License
 * @link      https://github.com/vphantom/php-library
 */

class PDB
{
    private $_dbh;
    private $_sth;
    private $_err;
    private $_tables;

    /**
     * Constructor
     *
     * @param string $dsn  Database specification to pass to PDO
     * @param string $user (Optional) Username for database
     * @param string $pass (Optional) Password for database
     *
     * @return object PDB object
     */
    public function __construct($dsn, $user = null, $pass = null)
    {
        $this->_tables = array();
        $this->_err = null;
        $this->_sth = null;
        $this->_dbh = new \PDO($dsn, $user, $pass);  // Letting PDOException bubble up
    }

    /**
     * Convenience constructor for PDBquery objects
     *
     * @param string $query String portion of partial query
     * @param array  $args  (Optional) List of arguments for placeholders
     *
     * @return object PDBquery object
     */
    public function query($query = null, $args = null)
    {
        return new PDBquery($query, $args);
    }

    /**
     * Begin transaction
     *
     * @return object PDB object (for chaining)
     */
    public function begin()
    {
        $this->_dbh->beginTransaction();
        return $this;
    }

    /**
     * Commit pending transaction
     *
     * @return object PDB object (for chaining)
     */
    public function commit()
    {
        $this->_dbh->commit();
        return $this;
    }

    /**
     * Rollback pending transaction
     *
     * @return object PDB object (for chaining)
     */
    public function rollback()
    {
        $this->_dbh->rollBack();
        return $this;
    }

    /**
     * Get list of column names for a table
     *
     * Cached in $this to minimize I/O
     *
     * @param string $table Name of table to inspect
     *
     * @return array List of column names, false on failure
     */
    private function _getColumns($table)
    {
        if (isset($this->_tables[$table])) {
            return $this->_tables[$table];
        };
        $this->_tables[$table] = false;
        if ($rows = $this->selectArray("PRAGMA table_info({$table})")) {
            $cols = array();
            foreach ($rows as $row) {
                $cols[] = $row['name'];
            };
            if (count($cols) > 0) {
                $this->_tables[$table] = $cols;
            };
        };
        return $this->_tables[$table];
    }

    /**
     * Prepare a statement
     *
     * @param string $q SQL query with '?' value placeholders
     *
     * @return object PDB object (for chaining)
     */
    private function _prepare($q)
    {
        $this->_err = null;
        if (!$this->_sth = $this->_dbh->prepare($q)) {
            $this->_err = implode(' ', $this->_dbh->errorInfo());
        };
        return $this;
    }

    /**
     * Execute stored prepared statement
     *
     * @param array $args (Optional) List of values corresponding to placeholders
     *
     * @return bool Whether it succeeded
     */
    private function _execute($args = array())
    {
        if ($this->_err) {
            return false;
        };
        if ($this->_sth) {
            if ($this->_sth->execute($args)) {
                return true;
            } else {
                $this->_err = implode(' ', $this->_sth->errorInfo());
                return false;
            };
        } else {
            $this->_err = "No statement to execute.";
        };
        return false;
    }

    /**
     * Execute a result-less statement
     *
     * Typically INSERT, UPDATE, DELETE.
     *
     * @param string|object $q    SQL query with '?' placeholders or PDBquery object
     * @param array         $args (Optional) List of values corresponding to placeholders
     *
     * @return mixed Number of affected rows, false on error
     */
    public function exec($q, $args = array())
    {
        if ($q instanceof PDBquery) {
            $args = $q->getArgs();
            $q = $q->getQuery();
        };
        return $this->_prepare($q)->_execute($args) ? $this->_sth->rowCount() : false;
    }

    /**
     * Fetch last INSERT/UPDATE auto_increment ID
     *
     * This is a shortcut to the API value, to avoid performing a SELECT
     * LAST_INSERT_ID() round-trip manually.
     *
     * @return string Last inserted ID if supported/available
     */
    public function lastInsertId()
    {
        return $this->_dbh->lastInsertId();
    }

    /**
     * Fetch last error explanation, if any
     *
     * @return string Last error if there is one, null otherwise
     */
    public function lastError()
    {
        return $this->_err ? $this->_err : null;
    }

    /**
     * Execute INSERT statement with variable associative column data
     *
     * Note that the first time a table is referenced with insert() or
     * update(), its list of columns will be fetched from the database to
     * create a whitelist.  It is thus safe to pass a form result directly to
     * $values.
     *
     * @param string $table  Name of table to update
     * @param array  $values Associative list of columns/values to set
     *
     * @return mixed New ID if supported/available, false on failure
     */
    public function insert($table, $values)
    {
        $colOK = $this->_getColumns($table);
        $query = $this->query('INSERT INTO ' . $table);
        $colQs = array();
        $cols = array();
        if (is_array($values)) {
            foreach ($values as $key => $val) {
                if (in_array($key, $colOK)) {
                    $cols[] = $key;
                    $colQs[] = $this->query('?', $val);
                };
            };
        };
        $query->implodeClosed(',', $cols)->append('VALUES')->implodeClosed(',', $colQs);
        return
            $this->_prepare($query->getQuery())->_execute($query->getArgs())
            ? $this->_dbh->lastInsertId()
            : false
        ;
    }

    /**
     * Execute UPDATE statement with variable associative column data
     *
     * Note that the first time a table is referenced with insert() or
     * update(), its list of columns will be fetched from the database to
     * create a whitelist.  It is thus safe to pass a form result directly to
     * $values.
     *
     * Also, note that if you want to append custom column assignments, it is
     * up to you to prepend ", " to $tail before your WHERE clause.
     *
     * $tail can be a string (in which case $tailArgs specifies any arguments)
     * or a PDBquery object.
     *
     * @param string        $table    Name of table to update
     * @param array         $values   Associative list of columns/values to set
     * @param string|object $tail     Final part of SQL query (i.e. custom columns, WHERE clause)
     * @param array         $tailArgs (Optional) List of values corresponding to placeholders in $tail
     *
     * @return mixed Last ID if supported/available, false on failure
     */
    public function update($table, $values, $tail, $tailArgs = array())
    {
        $colOK = $this->_getColumns($table);
        $query = $this->query('UPDATE ' . $table . ' SET');
        $cols = array();

        if (!($tail instanceof PDBquery)) {
            $tail = $this->query($tail, $tailArgs);
        };

        if (is_array($values)) {
            foreach ($values as $key => $val) {
                if (in_array($key, $colOK)) {
                    $cols[] = $this->query("{$key}=?", array($val));  // Array to allow NULL
                };
            };
        };
        $query->implode(',', $cols)->append($tail);
        return
            $this->_prepare($query->getQuery())->_execute($query->getArgs())
            ? $this->_sth->rowCount()
            : false
        ;
    }

    /**
     * Fetch a single value
     *
     * The result is the value returned in row 0, column 0.  Useful for
     * COUNT(*) and such.  Extra columns/rows are safely ignored.
     *
     * @param string $q    SQL query with '?' value placeholders
     * @param array  $args (Optional) List of values corresponding to placeholders
     *
     * @return mixed Single result cell, false if no results
     */
    public function selectAtom($q, $args = array())
    {
        $this->exec($q, $args);
        // FIXME: Test if it is indeed NULL
        return $this->_sth ? $this->_sth->fetchColumn() : false;
    }

    /**
     * Fetch a simple list of result values
     *
     * The result is a list of the values found in the first column of each
     * row.
     *
     * @param string $q    SQL query with '?' value placeholders
     * @param array  $args (Optional) List of values corresponding to placeholders
     *
     * @return array
     */
    public function selectList($q, $args = array())
    {
        $this->exec($q, $args);
        return $this->_sth ? $this->_sth->fetchAll(\PDO::FETCH_COLUMN, 0) : false;
    }

    /**
     * Fetch a single row as associative array
     *
     * Fetches the first row of results, so from the caller's side it's
     * equivalent to selectArray()[0] however only the first row is ever
     * fetched from the server.
     *
     * Note that if you're not selecting by a unique ID, a LIMIT of 1 should
     * still be specified in SQL for optimal performance.
     *
     * @param string $q    SQL query with '?' value placeholders
     * @param array  $args (Optional) List of values corresponding to placeholders
     *
     * @return array Single associative row
     */
    public function selectSingleArray($q, $args = array())
    {
        $this->exec($q, $args);
        return $this->_sth ? $this->_sth->fetch(\PDO::FETCH_ASSOC) : false;
    }

    /**
     * Fetch all results in an associative array
     *
     * @param string $q    SQL query with '?' value placeholders
     * @param array  $args (Optional) List of values corresponding to placeholders
     *
     * @return array All associative rows
     */
    public function selectArray($q, $args = array())
    {
        $this->exec($q, $args);
        return $this->_sth ? $this->_sth->fetchAll(\PDO::FETCH_ASSOC) : false;
    }

    /**
     * Fetch all results in an associative array, index by first column
     *
     * Whereas selectArray() returns a list of associative rows, this returns
     * an associative array keyed on the first column of each row.
     *
     * @param string $q    SQL query with '?' value placeholders
     * @param array  $args (Optional) List of values corresponding to placeholders
     *
     * @return array All associative rows, keyed on first column
     */
    public function selectArrayIndexed($q, $args = array())
    {
        $this->exec($q, $args);
        if ($this->_sth) {
            $result = array();
            while ($row = $this->_sth->fetch(\PDO::FETCH_ASSOC)) {
                $result[$row[key($row)]] = $row;
            };
            return $result;
        } else {
            return false;
        };
    }

    /**
     * Fetch 2-column result into associative array
     *
     * Create one key per row, indexed on the first column, containing the
     * second column.  Handy for retreiving key/value pairs.
     *
     * @param string $q    SQL query with '?' value placeholders
     * @param array  $args (Optional) List of values corresponding to placeholders
     *
     * @return array Associative pairs
     */
    public function selectArrayPairs($q, $args = array())
    {
        $this->exec($q, $args);
        return $this->_sth ? $this->_sth->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP) : false;
    }

}

<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Authentication\User;

use Exception;
use Icinga\Data\Inspectable;
use Icinga\Data\Inspection;
use Icinga\Data\Filter\Filter;
use Icinga\Exception\AuthenticationException;
use Icinga\Repository\DbRepository;
use Icinga\User;
use PDO;
use Zend_Db_Expr;

class DbUserBackend extends DbRepository implements UserBackendInterface, Inspectable
{
    /**
     * The query columns being provided
     *
     * @var array
     */
    protected $queryColumns = array(
        'user' => array(
            'user'          => 'name COLLATE utf8mb4_general_ci',
            'user_name'     => 'name',
            'is_active'     => 'active',
            'created_at'    => 'UNIX_TIMESTAMP(ctime)',
            'last_modified' => 'UNIX_TIMESTAMP(mtime)'
        )
    );

    /**
     * The statement columns being provided
     *
     * @var array
     */
    protected $statementColumns = array(
        'user' => array(
            'password'      => 'password_hash',
            'created_at'    => 'ctime',
            'last_modified' => 'mtime'
        )
    );

    /**
     * The columns which are not permitted to be queried
     *
     * @var array
     */
    protected $blacklistedQueryColumns = array('user');

    /**
     * The search columns being provided
     *
     * @var array
     */
    protected $searchColumns = array('user');

    /**
     * The default sort rules to be applied on a query
     *
     * @var array
     */
    protected $sortRules = array(
        'user_name' => array(
            'columns'   => array(
                'is_active desc',
                'user_name'
            )
        )
    );

    /**
     * The value conversion rules to apply on a query or statement
     *
     * @var array
     */
    protected $conversionRules = array(
        'user' => array(
            'password'
        )
    );

    /**
     * Initialize this database user backend
     */
    protected function init()
    {
        if (! $this->ds->getTablePrefix()) {
            $this->ds->setTablePrefix('icingaweb_');
        }
    }

    /**
     * Initialize this repository's filter columns
     *
     * @return  array
     */
    protected function initializeFilterColumns()
    {
        $userLabel = t('Username') . ' ' . t('(Case insensitive)');
        return array(
            $userLabel          => 'user',
            t('Username')       => 'user_name',
            t('Active')         => 'is_active',
            t('Created at')     => 'created_at',
            t('Last modified')  => 'last_modified'
        );
    }

    /**
     * Insert a table row with the given data
     *
     * @param   string $table
     * @param   array  $bind
     *
     * @return void
     */
    public function insert($table, array $bind, array $types = array())
    {
        $this->requireTable($table);
        $bind['created_at'] = date('Y-m-d H:i:s');
        $this->ds->insert(
            $this->prependTablePrefix($table),
            $this->requireStatementColumns($table, $bind),
            array(
                'active'        => PDO::PARAM_INT,
                'password_hash' => PDO::PARAM_LOB
            )
        );
    }

    /**
     * Update table rows with the given data, optionally limited by using a filter
     *
     * @param   string  $table
     * @param   array   $bind
     * @param   Filter  $filter
     */
    public function update($table, array $bind, Filter $filter = null, array $types = array())
    {
        $this->requireTable($table);
        $bind['last_modified'] = date('Y-m-d H:i:s');
        if ($filter) {
            $filter = $this->requireFilter($table, $filter);
        }

        $this->ds->update(
            $this->prependTablePrefix($table),
            $this->requireStatementColumns($table, $bind),
            $filter,
            array(
                'active'        => PDO::PARAM_INT,
                'password_hash' => PDO::PARAM_LOB
            )
        );
    }

    /**
     * Hash and return the given password
     *
     * @param   string  $value
     *
     * @return  string
     */
    protected function persistPassword($value)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * Fetch the hashed password for the given user
     *
     * @param   string  $username   The name of the user
     *
     * @return  string
     */
    protected function getPasswordHash($username)
    {
        if ($this->ds->getDbType() === 'pgsql') {
            // Since PostgreSQL version 9.0 the default value for bytea_output is 'hex' instead of 'escape'
            $columns = ['password_hash' => new Zend_Db_Expr('ENCODE(password_hash, \'escape\')')];
        } else {
            // password_hash is intentionally not a valid query column,
            // by wrapping it in an expression it is not validated
            $columns = ['password_hash' => new Zend_Db_Expr('password_hash')];
        }

        $query = $this
            ->select()
            ->from('user', $columns)
            ->where('active', true);

        if ($this->ds->getDbType() === 'mysql') {
            $username = strtolower($username);
            $nameColumn = new Zend_Db_Expr('BINARY LOWER(name)');

            $query->getQuery()->where($nameColumn, $username);
        } else { // pgsql
            $query->where('user', $username);
        }

        $statement = $this->ds->getDbAdapter()->prepare($query->getQuery()->getSelectQuery());
        $statement->execute();
        $statement->bindColumn(1, $lob, PDO::PARAM_LOB);
        $statement->fetch(PDO::FETCH_BOUND);
        if (is_resource($lob)) {
            $lob = stream_get_contents($lob);
        }

        if ($lob === null) {
            return '';
        }

        return $this->ds->getDbType() === 'pgsql' ? pg_unescape_bytea($lob) : $lob;
    }

    /**
     * Authenticate the given user
     *
     * @param   User        $user
     * @param   string      $password
     *
     * @return  bool                        True on success, false on failure
     *
     * @throws  AuthenticationException     In case authentication is not possible due to an error
     */
    public function authenticate(User $user, $password)
    {
        try {
            return password_verify(
                $password,
                $this->getPasswordHash($user->getUsername())
            );
        } catch (Exception $e) {
            throw new AuthenticationException(
                'Failed to authenticate user "%s" against backend "%s". An exception was thrown:',
                $user->getUsername(),
                $this->getName(),
                $e
            );
        }
    }

    /**
     * Inspect this object to gain extended information about its health
     *
     * @return Inspection           The inspection result
     */
    public function inspect()
    {
        $insp = new Inspection('Db User Backend');
        $insp->write($this->ds->inspect());
        try {
            $insp->write(sprintf('%s active users', $this->select()->where('is_active', true)->count()));
        } catch (Exception $e) {
            $insp->error(sprintf('Query failed: %s', $e->getMessage()));
        }
        return $insp;
    }
}

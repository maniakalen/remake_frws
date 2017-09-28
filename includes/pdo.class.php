<?php
/**
 * Created by PhpStorm.
 * User: maniakalen
 * Date: 26/09/2017
 * Time: 23:58
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
class DbPdo
{
    private static $instance;

    private $defaults;
    private $pdo = [];
    private $active;
    private $lastException;
    private $callback;

    private function __construct($schema = null, $user = null, $pass = null, $host = null)
    {
        if (!$this->defaults) {
            $this->defaults = $this->getDefaultConnectionData();
        }
        $defaults = $this->defaults;
        if ($schema) {
            $defaults['schema'] = $schema;
        }
        if ($user) {
            $defaults['user'] = $user;
        }
        if ($pass) {
            $defaults['pass'] = $pass;
        }
        if ($host) {
            $defaults['host'] = $host;
        }
        try {
            $pdo = new PDO(
                "mysql:host={$defaults['host']};dbname={$defaults['schema']}", $defaults['user'], $defaults['pass']
            );
            $this->active = $pdo;
            $this->pdo[$defaults['schema']] = $pdo;
        } catch (PDOException $ex) {
            $this->lastException = $ex;
            $this->callErrorCallback($ex);
        }
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function selectDb($schema, $host = null, $user = null, $pass = null)
    {
        try {
            if (!isset($this->pdo[$schema])) {
                $this->pdo[$schema] = new self($schema, $user, $pass, $host);
            }
            $this->active = $this->pdo[$schema];

            return ($this->active instanceof PDO);
        } catch (PDOException $ex) {
            $this->lastException = $ex;
            $this->callErrorCallback($ex);
            return false;
        }
    }

    public function __call($name, $arguments)
    {
        try {
            if (is_callable([$this->pdo, $name])) {
                $result = call_user_func_array([$this->pdo, $name], $arguments);
                $this->lastException = null;
                return $result;
            }

            throw new Exception("Unknown method called");
        } catch (Exception $ex) {
            $this->lastException = $ex;
            $this->callErrorCallback($ex);
        }
        return false;
    }

    public function __clone()
    {
        throw new Exception("Not allowed to clone a singleton");
    }

    private function getDefaultConnectionData()
    {
        global $C;
        if (!isset($C['db_hostname']) || !isset($C['db_name']) || !isset($C['db_username']) || !isset($C['db_password'])) {
            throw new Exception("Missing db config params");
        }

        return array(
            'host' => $C['db_hostname'],
            'schema' => $C['db_name'],
            'user' => $C['db_username'],
            'pass' => $C['db_password']
        );
    }

    public function error()
    {
        return $this->hasError()?$this->lastException->getMessage():null;
    }

    public function hasError()
    {
        return $this->lastException instanceof Exception;
    }

    public function setErrorCallback($callback)
    {
        $this->callback = $callback;
    }

    public function callErrorCallback($ex)
    {
        if (is_callable($this->callback)) {
            call_user_func($this->callback, $ex);
        }
    }

    public function prepareAndExecute($query, $binds)
    {
        $readyToBind = false;
        foreach ($binds as $k => $v) {
            if (strpos($query, $k) !== false) {
                $readyToBind = true;
            }
        }

        if (!is_numeric(reset(array_keys($binds)))) {
            $binds = array($binds);
        }
        if (!$readyToBind) {
            $query = $this->prepareForBinding($query, reset($binds));
        }
        $statement = $this->pdo->prepare($query);
        $results = array();
        foreach ($binds as $bind) {
            $statement->execute($bind);
            $results[] = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->clearCursor();
        }
        unset($statement);
        return $results;
    }

    private function prepareForBinding($query, $binds)
    {
        $query = str_replace('?', '%s', $query);
        $query = call_user_func_array('sprintf', array_merge($query, array_keys($binds)));
        return $query;
    }
}
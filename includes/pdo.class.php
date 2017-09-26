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

    private $pdo;
    private function __construct()
    {
        $config = Config::getInstance()->getDbParams();

        if (!isset($config['host']) || !isset($config['schema']) || !isset($config['user']) || !isset($config['pass'])) {
            throw new Exception("Missing db config params");
        }
        $this->pdo = new PDO("mysql:host={$config['host']};dbname={$config['schema']}", $config['user'], $config['user']);
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __call($name, $arguments)
    {
        if (is_callable([$this->pdo, $name])) {
            return call_user_func_array([$this->pdo,$name], $arguments);
        }

        return false;
    }

    public function __clone()
    {
        throw new Exception("Not allowed to clone a singleton");
    }
}
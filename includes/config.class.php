<?php
/**
 * Created by PhpStorm.
 * User: maniakalen
 * Date: 27/09/2017
 * Time: 00:18
 */

class Config
{
    private static $instance;

    private $data = array();
    private function __construct()
    {
        !defined("ROOT_DIR") && define("ROOT_DIR", realpath(dirname(__FILE__). "/.."));
        $this->data = parse_ini_file(ROOT_DIR . "/config/config.ini", true);
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getDbParams()
    {
        if (!isset($this->data['db']) || empty($this->data['db'])) {
            throw new Exception("No database configuration found");
        }
        return $this->data['db'];
    }
}
<?php
include "includes/config.class.php";
include "includes/pdo.class.php";
foreach (DbPdo::getInstance()->query("SHOW TABLES") as $row) print_r($row);
die();
header('Location: accounts.php');
?>
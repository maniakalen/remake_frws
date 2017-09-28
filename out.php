<?php
// Copyright 2011 JMB Software, Inc.
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//    http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


// Initialization
if( !defined('E_STRICT') ) define('E_STRICT', 2048);
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
@set_time_limit(0);
@set_magic_quotes_runtime(0);
if( function_exists('date_default_timezone_set') )
{
    date_default_timezone_set('America/Chicago');
}


// Prepare request data
if( get_magic_quotes_gpc() == 1 )
{
    foreach($_GET as $key => $value)
    {
        $_GET[$key] = stripslashes($value);
    }
}

// Load configuration settings
require_once('includes/config.php');

$send_to = $C['alternate_out_url'];

// Only allow GET requests
if( $_SERVER['REQUEST_METHOD'] == 'GET' )
{
    $raw_out = FALSE;
    $raw_click = FALSE;
    $account = null;
    $referrer_account = (!empty($_COOKIE['tlxreferrer']) ? $_COOKIE['tlxreferrer'] : null);
    $first_click = (empty($_COOKIE['tlxfirst']) ? TRUE : FALSE);
    $sites_sent_to = (!empty($_COOKIE['tlxsent']) ? unserialize(stripslashes($_COOKIE['tlxsent'])) : array());
    $send_to_trade = TRUE;
    $now = time() + 3600 * $C['timezone'];
    $today = gmdate('Y-m-d', $now);
    $this_hour = gmdate('G', $now);
    $datetime = "$today-$this_hour";

    // Connect to database
    $pdo = DbPdo::getInstance();
    if (!$pdo->selectDb($C['db_name'], $C['db_hostname'], $C['db_username'], $C['db_password'])) {
        die($pdo->error());
    }


    if( !$C['using_cron'] )
    {
        // Check if it is time for a page rebuild
        $result = $pdo->query("SELECT `value` FROM `tlx_stored_values` WHERE `name`='last_rebuild'");
        if ($pdo->hasError()) { die($pdo->error()); }
        list($last_rebuild) = $result->fetchColumn(0);
        $result->closeCursor();
        unset($result);

        if( $last_rebuild <= $now - $C['rebuild_interval'] )
        {
            shell_exec("{$C['php_cli']} admin/cron.php --rebuild >/dev/null 2>&1 &");
        }

        // Check if it is time for a daily or hourly update
        $result = $pdo->query("SELECT `value` FROM `tlx_stored_values` WHERE `name`='last_updates'");
        if ($pdo->hasError()) { die($pdo->error()); }
        list($last_updates) = $result->fetchColumn(0);
        $last_updates = unserialize($last_updates);
        $result->closeCursor();
        unset($result);

        if( $last_updates['daily'] != $today )
        {
            shell_exec("{$C['php_cli']} admin/cron.php --daily-stats >/dev/null 2>&1 &");
        }

        if( $last_updates['hourly'] != $datetime )
        {
            shell_exec("{$C['php_cli']} admin/cron.php --hourly-stats >/dev/null 2>&1 &");
        }
    }

    // SKIM MODE
    if( $_GET['s'] || $_GET['f'] )
    {
        // Set the first click cookie
        setcookie('tlxfirst', '1', time()+86400, '/', $C['cookie_domain']);

        $_GET['s'] = !is_numeric($_GET['s']) ? 70 : $_GET['s'];

        // Skim is set to 100 or this is a first click
        if( $_GET['s'] == 100 || ($_GET['f'] && $first_click) )
        {
            $send_to_trade = FALSE;
            $send_to = $_GET['u'];
        }
        else
        {
            // Check ratio of trades to links
            $result = $pdo->query('SELECT (`sent_trades`/`sent_total`)*100 AS `trade_percent` FROM `tlx_skim_ratio`');
            if ($pdo->hasError()) { die($pdo->error()); }
            if( $result )
            {
                list($trade_percent) = $result->fetchColumn(0);
            }

            // Determine - based on ratio - if we should send to a trade
            if( 100 - $_GET['s'] < $trade_percent )
            {
                $send_to_trade = FALSE;
                $send_to = $_GET['u'];
            }
            else
            {
                $sites_sent_to[$referrer_account] = 1;

                // Select the click tracking mode
                switch($_GET['m'])
                {
                    default:
                        $owed = '(`clicks_total`-`unique_out_total`)*`return_percent`';
                        $where = '`clicks_total` > `unique_out_total`';
                        break;
                }

                $result = $pdo->query("SELECT *,$owed AS `owed` FROM `tlx_accounts` JOIN `tlx_account_hourly_stats` USING (`username`) WHERE $where ORDER BY `owed` DESC");

                if( $result )
                {
                    while( $row = $result->fetch(PDO::FETCH_ASSOC) )
                    {
                        if( $sites_sent_to[$row['username']] )
                        {
                            continue;
                        }

                        $account = $row;
                        break;
                    }
                    $result->closeCursor();
                    unset($result);
                }
            }
            $statement = $pdo->prepare('UPDATE `tlx_skim_ratio` SET `sent_total`=`sent_total`+1,`sent_trades`=`sent_trades`+' . ($send_to_trade ? '1' : '0'));
			$statement->execute();
        }
    }


    // SEND TO RANDOM ACCOUNT
    else if( $_GET['rand'] )
    {
        // Get a random account
        $result = $pdo->query('SELECT * FROM `tlx_accounts` WHERE `status`="active" AND `disabled`=0 ORDER BY RAND() LIMIT 1');
        if ($pdo->hasError()) { die($pdo->error()); }
        $account = $result->fetch(PDO::FETCH_ASSOC);
    }



    // TOPLIST MODE
    else
    {
        $statement = $pdo->prepare('SELECT * FROM `tlx_accounts` WHERE `username`=:userid');
        $statement->execute(array(':userid' => $_GET['id']));
        if (substr($statement->errorCode(), -3) !== '000') {
            die(reset($statement->errorInfo()));
        }
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
    }

    $long_ip = sprintf('%u', ip2long($_SERVER['REMOTE_ADDR']));

    // Account that surfer is being sent to has been selected
    if( $send_to_trade && $account )
    {
        $send_to = $account['site_url'];

        // Check if surfer has been sent to this site already
        if( isset($sites_sent_to[$account['username']]) )
        {
            $raw_out = TRUE;
        }

        // GeoIP lookup
        $statement = $pdo->prepare('SELECT * FROM `tlx_ip2country` WHERE `ip_end` >= :ip');
        $result = $statement->execute(array(':ip' => $long_ip));

        if( $result ) {
            $geoip = $statement->fetch(PDO::FETCH_ASSOC);
        } else {
            die(reset($statement->errorInfo()));
        }
        $statement->closeCursor();
        // Update the IP log
        $statement = $pdo->prepare('UPDATE `tlx_ip_log_out` SET `raw_out`=`raw_out`+1,`last_visit`=NOW() WHERE `username`=:user AND `ip_address`=:ip');
        $statement->execute(array(':user' => $account['username'], ':ip' => $long_ip));
        if( $statement->rowCount() == 0 )
        {
            $statement = $pdo->prepare('INSERT INTO `tlx_ip_log_out` VALUES (:user,:ip,:num,NOW())');
            $statement->execute(array(':user' => $account['username'], ':ip' => $long_ip, ':num' => 1));
            $statement->closeCursor();
        }
        else
        {
            $raw_out = TRUE;
        }
        $pdo->setErrorCallback(function($ex) { die($ex->getMessage()); });
        // Update raw and unique click counts
        if( $raw_out )
        {
            $pdo->prepareAndExecute(sprintf('UPDATE `tlx_account_hourly_stats` SET `%s`=%s+1,`raw_out_total`=`raw_out_total`+1 WHERE `username`=:user', "raw_out_$this_hour", "raw_out_$this_hour"), array(':user' => $account['username']));

            $stmt = $pdo->prepare('UPDATE `tlx_account_country_stats` SET `raw_out`=`raw_out`+1 WHERE `username`=:user AND `country`=:country');
            $stmt->execute(array(':user' => $account['username'], ':country' => $geoip['country']));

            if( $stmt->rowCount() == 0 )
            {
                $pdo->prepareAndExecute('INSERT INTO `tlx_account_country_stats` VALUES (?,?,?,?,?,?,?)', array(':user' => $account['username'], ':country' => $geoip['country'], ':num1' => 0, ':num2' => 0, ':num3' => 1, ':num4' => 1, ':num5' => 0));
            }
            $stmt->closeCursor();
            unset($stmt);
            $pdo->prepareAndExecute('UPDATE `tlx_country_stats` SET `raw_out`=`raw_out`+1 WHERE `country`=:country', array(':country' => $geoip['country']));
        }
        else
        {
            $pdo->prepareAndExecute(sprintf('UPDATE `tlx_account_hourly_stats` SET `%s`=`%s`+1,`%s`=%s+1,`raw_out_total`=`raw_out_total`+1,`unique_out_total`=`unique_out_total`+1 WHERE `username`=?', "raw_out_$this_hour", "raw_out_$this_hour","unique_out_$this_hour", "unique_out_$this_hour"), array(':user' => $account['username']));
            $stmt = $pdo->prepare('UPDATE `tlx_account_country_stats` SET `raw_out`=`raw_out`+1,`unique_out`=`unique_out`+1 WHERE `username`=:user AND `country`=:country');
            $stmt->execute(array(':user' => $account['username'], ':country' => $geoip['country']));

            if( $stmt->rowCount() == 0 )
            {
                $pdo->prepareAndExecute('INSERT INTO `tlx_account_country_stats` VALUES (?,?,?,?,?,?,?)', array(':user' => $account['username'], ':country' => $geoip['country'], ':num1' => 0, ':num2' => 0, ':num3' => 1, ':num4' => 1, ':num5' => 0));
            }
            $stmt->closeCursor();
            unset($stmt);
            $pdo->prepareAndExecute('UPDATE `tlx_country_stats` SET `raw_out`=`raw_out`+1,`unique_out`=`unique_out`+1 WHERE `country`=?', array(':c' => $geoip['country']));
        }

        // Update cookie to mark that surfer has been sent to this site
        $sites_sent_to[$account['username']] = 1;
        setcookie('tlxsent', serialize($sites_sent_to), time()+86400, '/', $C['cookie_domain']);
    }

    // Update stats for the referrer account
    if( $referrer_account && $referrer_account != $account['username'] )
    {
        // Update the IP click log
        $stmt = $pdo->prepare('UPDATE `tlx_ip_log_clicks` SET `clicks`=`clicks`+1,`last_visit`=NOW() WHERE `username`=:u AND `ip_address`=:i AND `url_hash`=:h');
        $stmt->execute(array(':u' => $referrer_account, ':i' => $long_ip, ':h' => sha1($send_to)));
        if( $stmt->rowCount() == 0 )
        {
            $stmt->closeCursor();
            $pdo->prepareAndExecute('INSERT INTO `tlx_ip_log_clicks` VALUES (?,?,?,?,NOW())', array(':r' => $referrer_account, ':i' => $long_ip, ':h' => sha1($send_to), ':n' => 1));
            $pdo->prepareAndExecute(sprintf('UPDATE `tlx_account_hourly_stats` SET %s=%s+1,`clicks_total`=`clicks_total`+1 WHERE `username`=?', "clicks_$this_hour", "clicks_$this_hour"), array(':r' => $referrer_account));
        } else {
            $stmt->closeCursor();
        }
        unset($stmt);
    }
}


if( !isset($C['redirect_code']) )
{
    $C['redirect_code'] = 301;
}

header("Location: $send_to", true, $C['redirect_code']);

function mysql_prepare($query, $binds)
{
    $query_result = '';
    $index = 0;

    $pieces = preg_split('/(\?|#)/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach( $pieces as $piece )
    {
        if( $piece == '?' )
        {
            if( $binds[$index] === NULL )
                $query_result .= 'NULL';
            else if( is_numeric($binds[$index]) )
                $query_result .= $binds[$index];
            else
                $query_result .= "'" . DbPdo::getInstance()->quote($binds[$index]) . "'";

            $index++;
        }
        else if( $piece == '#' )
        {
            $binds[$index] = str_replace('`', '\`', $binds[$index]);
            $query_result .= "`" . $binds[$index] . "`";
            $index++;
        }
        else
        {
            $query_result .= $piece;
        }
    }

    return $query_result;
}
?>
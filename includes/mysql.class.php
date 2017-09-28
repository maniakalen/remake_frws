<?PHP
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

class DB
{
    /** @var DbPdo */
    public $handle;
    public $hostname;
    public $username;
    public $password;
    public $connected;
    public $database;

    function DB($hostname, $username, $password, $database)
    {
        $this->handle = 0;
        $this->connected = FALSE;
        $this->hostname = $hostname;
        $this->password = $password;
        $this->username = $username;
        $this->database = $database;
    }

    function Connect()
    {
        if( !$this->connected )
        {
            $this->handle = DbPdo::getInstance();
            $this->connected = TRUE;
        }
    }

    function IsConnected()
    {
        return $this->connected;
    }

    function Disconnect()
    {
        if( $this->connected )
        {
            $this->handle    = 0;
            $this->connected = FALSE;
        }
    }

    function SelectDB($database)
    {
        $this->database = $database;

        if( !$this->handle->selectDb($this->database) )
        {
            trigger_error($this->handle->error(), E_USER_ERROR);
        }
    }

    function Row($query, $binds = array())
    {
        $query = $this->Prepare($query, $binds);
        $result = $query->execute($binds);

        if( !$result )
        {
            trigger_error($this->handle->error() . "<br />$query", E_USER_ERROR);
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        unset($query);

        return $row;
    }

    function Count($query, $binds = array())
    {
        $query = $this->Prepare($query, $binds);
        $result = $query->execute($query, $binds);

        if( !$result ) {
            trigger_error($this->handle->error() . "<br />$query", E_USER_ERROR);
        }

        $row = $query->fetch(PDO::FETCH_NUM);

        unset($query);

        return $row[0];
    }

    function Query($query, $binds = array())
    {
        $query = $this->Prepare($query, $binds);
        $result = $query->execute($binds);

        if( !$result )
        {
            trigger_error($this->handle->error() . "<br />$query", E_USER_ERROR);
        }

        return $query;
    }

    function QueryWithPagination($query, $binds = array(), $page = 1, $per_page = 10, $nolimit = FALSE)
    {
        global $C;
        
        $result = array();
        
        // Get total number of results
        $count_query = preg_replace(array('~SELECT (.*?) FROM~i', '~ ORDER BY (.*?)$~i'), array('SELECT COUNT(*) FROM', ''), $query);
        
        if( stristr($count_query, 'GROUP BY') )
        {
            $temp_result = $this->Query($count_query, $binds);
            $result['total'] = $this->NumRows($temp_result);
        }
        else
        {
            $result['total'] = $this->Count($count_query, $binds);
        }
        
        // Calculate pagination
        $result['pages'] = ceil($result['total']/$per_page);
        $result['page'] = min(max($page, 1), $result['pages']);
        $result['limit'] = max(($result['page'] - 1) * $per_page, 0);
        $result['start'] = max(($result['page'] - 1) * $per_page + 1, 0);
        $result['end'] = min($result['start'] - 1 + $per_page, $result['total']);
        $result['prev'] = ($result['page'] > 1);
        $result['next'] = ($result['end'] < $result['total']);
        
        if( $result['next'] )
            $result['next_page'] = $result['page'] + 1;
        
        if( $result['prev'] )
            $result['prev_page'] = $result['page'] - 1;
        
        if( $result['total'] > 0 )
            $result['result'] = $this->Query($query . ($nolimit ? '' : " LIMIT {$result['limit']},{$per_page}"), $binds);
        else
            $result['result'] = FALSE;
            
        // Format
        $result['fpages'] = number_format($result['pages'], 0, $C['dec_point'], $C['thousands_sep']);
        $result['start'] = number_format($result['start'], 0, $C['dec_point'], $C['thousands_sep']);
        $result['end'] = number_format($result['end'], 0, $C['dec_point'], $C['thousands_sep']);
        $result['ftotal'] = number_format($result['total'], 0, $C['dec_point'], $C['thousands_sep']);
        
        return $result;
    }

    function &FetchAll($query, $binds = array(), $key = null)
    {
        $result = $this->Query($query, $binds);
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        if ($key) {
            $all = array();
            foreach ($rows as $row) {
                $all[$row[$key]] = $row;
            }
            return $all;
        }

        return $rows;
    }

    function Update($query, $binds = array())
    {
        /** @var PDOStatement $query */
        $query = $this->Prepare($query, $binds);
        if ($query->execute($binds)) {
            return $query->rowCount();
        }

        trigger_error("Failet to fetch rows count", E_USER_ERROR);
    }

    function NextRow($result)
    {
        if (!($result instanceof PDOStatement)) {
            trigger_error("Trying to fetch data from incorrect source", E_USER_ERROR);
        }
        return $result->fetch(PDO::FETCH_ASSOC);
    }

    function Free($result)
    {
        if ($result instanceof PDOStatement) {
            $result->closeCursor();
        }
    }

    function InsertID()
    {
        return $this->handle->lastInsertId();
    }

    function NumRows($result)
    {
        return ($result instanceof PDOStatement)?$result->rowCount():0;
    }

    function FetchArray($result)
    {
        return ($result instanceof PDOStatement)?$result->fetch(PDO::FETCH_BOTH):null;
    }

    function Seek($result, $where)
    {

    }

    function BindList($count)
    {
        $list = "''";
        
        if( $count > 0 )
        {
            $list = join(',', array_fill(0, $count, '?'));
        }
        
        return $list;        
    }

    function Prepare($query, &$binds)
    {
        $query_result = '';
        $index = 0;
        
        $pieces = preg_split('/(\?|#)/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        $params = array();
        foreach( $pieces as $piece )
        {
            if( $piece == '?' )
            {
                if( $binds[$index] === NULL ) {
                    $query_result .= 'NULL';
                } else {
                    $param = ':param' . $index;
                    $params[$param] = $binds[$index];
                    $query_result .= $param;
                }

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
        if (!empty($params)) {
            $binds = $params;
        }
        return $this->handle->prepare($query_result);
    }

    function Escape($string)
    {
        return addslashes($string);
    }

    function GetTables()
    {
        $tables = array();
        $result = $this->Query('SHOW TABLES');
        $field = $result->getColumnMeta(0);
        if (!$field['name']) return null;
        $field = $field['name'];

        while( $row = $this->NextRow($result) )
        {
            $tables[$row[$field]] = $row[$field];
        }

        $this->Free($result);

        return $tables;
    }

    function GetColumns($table, $as_hash = FALSE, $with_backticks = FALSE)
    {
        $columns = array();
        $result = $this->Query('DESCRIBE #', array($table));
        $field = $result->getColumnMeta(0);
        if (!$field['name']) return null;
        $field = $field['name'];

        while( $column = $this->NextRow($result) )
        {
            if( $as_hash )
            {
                $columns[$column[$field]] = $with_backticks ? "`{$column[$field]}`" : $column[$field];
            }
            else
            {
                $columns[] = $with_backticks ? "`{$column[$field]}`" : $column[$field];
            }
        }
        
        $this->Free($result);
        
        return $columns;
    }
}

?>
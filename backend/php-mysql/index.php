<?php

set_time_limit(0);

// Combined setup function with configuration arrays
function get_db_config($type = 'import')
{
    $configs = [
        'saveloadlist' => [
            'server' => 'localhost',
            'user' => 'mysql',
            'password' => '',
            'db' => 'home',
            'table' => 'wwwsqldesigner'
        ],
        'import' => [
            'server' => 'localhost',
            'user' => 'mysql',
            'password' => 'TimdbPW.Icm$0.',
            'db' => 'budman'
        ]
    ];
    return $configs[$type];
}

class mysqlDB
{
    public $_conn;
    public function connect()
    {
        $config = get_db_config('import');
        $conn = mysqli_connect($config['server'], $config['user'], $config['password']);
        $this->setLink($conn);
        if (!$this->getLink()) {
            return false;
        }
        $res = mysqli_select_db($this->getLink(), $config['db']);
        if (!$res) {
            echo "You have to configure the DataBase";
            return false;
        }
        return true;
    }
    public function getLink()
    {
        return $this->_conn;
    }
    public function setLink($conn)
    {
        $this->_conn = $conn;
    }

    public function import()
    {
        $db = (isset($_GET["database"]) ? $_GET["database"] : "information_schema");
        $db = mysqli_real_escape_string($this->getLink(), $db);

        // Debug output
        error_log("Importing database: " . $db);

        $xml = "";
        $arr = array();
        @ $datatypes = file("../../db/mysql/datatypes.xml");
        $arr[] = $datatypes[0];
        $arr[] = '<sql db="mysql">';

        // Get tables
        $tables_query = "SELECT TABLE_NAME, TABLE_COMMENT 
                        FROM INFORMATION_SCHEMA.TABLES 
                        WHERE TABLE_SCHEMA = '".$db."'";

        error_log("Executing query: " . $tables_query);
        $result = mysqli_query($this->getLink(), $tables_query);

        if (!$result) {
            error_log("MySQL Error: " . mysqli_error($this->getLink()));
            return "Error fetching tables";
        }

        while ($row = mysqli_fetch_array($result)) {
            $table = $row["TABLE_NAME"];
            $xml .= '<table name="'.$table.'">';

            // Get columns
            $columns_query = "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT,
                             EXTRA
                             FROM INFORMATION_SCHEMA.COLUMNS 
                             WHERE TABLE_SCHEMA = '".$db."' 
                             AND TABLE_NAME = '".$table."'";

            $result2 = mysqli_query($this->getLink(), $columns_query);

            while ($row2 = mysqli_fetch_array($result2)) {
                $name = $row2["COLUMN_NAME"];
                $type = $row2["COLUMN_TYPE"];
                $null = ($row2["IS_NULLABLE"] == "YES" ? "1" : "0");
                $def = $row2["COLUMN_DEFAULT"];
                $ai = (strpos($row2["EXTRA"], 'auto_increment') !== false ? "1" : "0");

                $xml .= '<row name="'.$name.'" null="'.$null.'" autoincrement="'.$ai.'">';
                $xml .= '<datatype>'.strtoupper($type).'</datatype>';
                if ($def !== null) {
                    $xml .= '<default>'.htmlspecialchars($def).'</default>';
                }
                $xml .= '</row>';
            }

            // Get foreign keys
            $fk_query = "SELECT 
                         COLUMN_NAME,
                         REFERENCED_TABLE_NAME,
                         REFERENCED_COLUMN_NAME
                         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = '".$db."'
                         AND TABLE_NAME = '".$table."'
                         AND REFERENCED_TABLE_NAME IS NOT NULL";

            $result3 = mysqli_query($this->getLink(), $fk_query);
            while ($row3 = mysqli_fetch_array($result3)) {
                $xml .= '<row name="'.$row3["COLUMN_NAME"].'">';
                $xml .= '<relation table="'.$row3["REFERENCED_TABLE_NAME"].'" 
                         row="'.$row3["REFERENCED_COLUMN_NAME"].'" />';
                $xml .= '</row>';
            }

            $xml .= "</table>";
        }

        $arr[] = $xml;
        $arr[] = '</sql>';
        return implode("\n", $arr);
    }
}


$a = (isset($_GET["action"]) ? $_GET["action"] : false);
switch ($a) {
    case "list":
        $DBHandler = new mysqlDB();
        if (!$DBHandler->connect()) {
            header("HTTP/1.0 503 Service Unavailable");
            break;
        }
        $result = mysqli_query($DBHandler->getLink(), "SELECT keyword FROM ".TABLE." ORDER BY dt DESC");
        while ($row = mysqli_fetch_assoc($result)) {
            echo $row["keyword"]."\n";
        }
        break;
    case "save":
        $DBHandler = new mysqlDB();
        if (!$DBHandler->connect()) {
            header("HTTP/1.0 503 Service Unavailable");
            break;
        }
        $keyword = (isset($_GET["keyword"]) ? $_GET["keyword"] : "");
        $keyword = mysqli_real_escape_string($DBHandler->getLink(), $keyword);
        $data = file_get_contents("php://input");
        $data = mysqli_real_escape_string($DBHandler->getLink(), $data);
        $r = mysqli_query($DBHandler->getLink(), "SELECT * FROM ".TABLE." WHERE keyword = '".$keyword."'");
        if (mysqli_num_rows($r) > 0) {
            $res = mysqli_query($DBHandler->getLink(), "UPDATE ".TABLE." SET data = '".$data."' WHERE keyword = '".$keyword."'");
        } else {
            $res = mysqli_query($DBHandler->getLink(), "INSERT INTO ".TABLE." (keyword, data) VALUES ('".$keyword."', '".$data."')");
        }
        if (!$res) {
            header("HTTP/1.0 500 Internal Server Error");
        } else {
            header("HTTP/1.0 201 Created");
        }
        break;
    case "load":
        $DBHandler = new mysqlDB();
        if (!$DBHandler->connect()) {
            header("HTTP/1.0 503 Service Unavailable");
            break;
        }
        $keyword = (isset($_GET["keyword"]) ? $_GET["keyword"] : "");
        $keyword = mysqli_real_escape_string($DBHandler->getLink(), $keyword);
        $result = mysqli_query($DBHandler->getLink(), "SELECT `data` FROM ".TABLE." WHERE keyword = '".$keyword."'");
        $row = mysqli_fetch_assoc($result);
        if (!$row) {
            header("HTTP/1.0 404 Not Found");
        } else {
            header("Content-type: text/xml");
            echo $row["data"];
        }
        break;
    case "import":
        $DBHandler = new mysqlDB();
        if (!$DBHandler->connect()) {
            header("HTTP/1.0 503 Service Unavailable");
            break;
        }

        header("Content-type: text/xml");
        echo $DBHandler->import();
        break;
    default: header("HTTP/1.0 501 Not Implemented");
}


/*
    list: 501/200
    load: 501/200/404
    save: 501/201
    import: 501/200
*/

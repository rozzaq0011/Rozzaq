<?php
/**
 * Install Controller Class
 * 
 * PHP version 5.4
 *
 * @category PHP
 * @package  Controller
 * @author   cyberziko <cyberziko@gmail.com>
 * @license  commercial http://getcyberworks.com/
 * @link     http://getcyberworks.com/
 */
class InstallerController extends BaseController
{

    /**
     * Load install page
     * 
     * @return view
     */
    public function index()
    {
        if (File::exists(app_path() . "/config/config.inc.php")) {
            return Redirect::route('front.index');
        } else {
            return View::make('install.index');
        }
    }

    /**
     * Start install
     * 
     * @return type
     */
    public function startInstall()
    {
        $input = Input::all();
        $rules = $rules = ['host' => 'required',
            'name' => 'required',
            'username' => 'required'
        ];

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return Response::json(
                array(
                    'success' => false,
                    'errors' => $validator->getMessageBag()->toArray()
                )
            );
        }

        try {
            $database_host = $input['host'];
            $database_name = $input['name'];
            $database_username = $input['username'];
            $database_password = $input['password'];

            // config
            $sql_dump = app_path() . "/views/install/db_dump.sql";
            $config_file_directory = app_path() . "/config/";
            $config_file_name = "config.inc.php";
            $config_file_default = app_path() . "/views/install/config.default";
            $config_file_path = $config_file_directory . $config_file_name;

            $config_file = file_get_contents($config_file_default);
            $config_file = str_replace("_DB_HOST_", $database_host, $config_file);
            $config_file = str_replace("_DB_NAME_", $database_name, $config_file);
            $config_file = str_replace("_DB_USER_", $database_username, $config_file);
            $config_file = str_replace("_DB_PASSWORD_", $database_password, $config_file);

            $connection = mysql_connect($database_host, $database_username, $database_password, true);
            if ($connection) {
                if (mysql_select_db($database_name)) {
                    $f = fopen($config_file_path, "w+");
                    if (fwrite($f, $config_file) > 0) {
                        if (false == ($db_error = $this->apphp_db_install($database_name, $sql_dump, $connection))) {
                            return Response::json(
                                array(
                                    'success' => false,
                                    'errors' => ["Could not read file " . $sql_dump . "! Please check if the file exists."]
                                ), 
                                400
                            );
                        } else {
                            fclose($f);
                            return Response::json(array('success' => true));
                        }
                    }
                    fclose($f);
                } else {
                    return Response::json(
                        array(
                            'success' => false,
                            'errors' => ["Error : Database connecting error! Check your database exists."]
                        ), 
                        400
                    );
                }
                mysql_close();
            }
        } catch (Exception $e) {
            return Response::json(
                array(
                    'success' => false,
                    'errors' => ["Error : Exception [" . $e->getMessage() . "] on file (" . $e->getFile() . ") - line:" . $e->getLine()]
                ), 
                400
            );
        }
    }

    /**
     * Import dump file
     * 
     * @param type $database   database
     * @param type $sql_file   sql file
     * @param type $connection connection
     * 
     * @return boolean
     */
    private function apphp_db_install($database, $sql_file, $connection)
    {
        $db_error = false;
        if (!$this->apphp_db_select_db($database)) {
            if ($this->apphp_db_query('create database ' . $database)) {
                $this->apphp_db_select_db($database);
            } else {
                $db_error = mysql_error();
                return false;
            }
        }

        if (!$db_error) {
            if (file_exists($sql_file)) {
                $fd = fopen($sql_file, 'rb');
                $restore_query = fread($fd, filesize($sql_file));
                fclose($fd);
            } else {
                $db_error = 'SQL file does not exist: ' . $sql_file;
                return false;
            }

            $sql_array = array();
            $sql_length = strlen($restore_query);
            $pos = strpos($restore_query, ';');
            for ($i = $pos; $i < $sql_length; $i++) {
                if ($restore_query[0] == '#') {
                    $restore_query = ltrim(substr($restore_query, strpos($restore_query, "\n")));
                    $sql_length = strlen($restore_query);
                    $i = strpos($restore_query, ';') - 1;
                    continue;
                }
                $next = '';
                if ($restore_query[($i + 1)] == "\n") {
                    for ($j = ($i + 2); $j < $sql_length; $j++) {
                        if (trim($restore_query[$j]) != '') {
                            $next = substr($restore_query, $j, 6);
                            if ($next[0] == '#') {
                                // find out where the break position is so we can remove this line (#comment line)
                                for ($k = $j; $k < $sql_length; $k++) {
                                    if ($restore_query[$k] == "\n")
                                        break;
                                }
                                $query = substr($restore_query, 0, $i + 1);
                                $restore_query = substr($restore_query, $k);
                                // join the query before the comment appeared, with the rest of the dump
                                $restore_query = $query . $restore_query;
                                $sql_length = strlen($restore_query);
                                $i = strpos($restore_query, ';') - 1;
                                continue 2;
                            }
                            break;
                        }
                    }
                    if ($next == '') { // get the last insert query
                        $next = 'insert';
                    }
                    if ((preg_match('/create/i', $next)) || (preg_match('/insert/i', $next)) || (preg_match('/drop t/i', $next))) {
                        $next = '';
                        $sql_array[] = substr($restore_query, 0, $i);
                        $restore_query = ltrim(substr($restore_query, $i + 1));
                        $sql_length = strlen($restore_query);
                        $i = strpos($restore_query, ';') - 1;
                    }
                }
            }

            for ($i = 0; $i < sizeof($sql_array); $i++) {
                $this->apphp_db_query($sql_array[$i], $connection);
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Select database
     * 
     * @param type $database database
     * 
     * @return type
     */
    private function apphp_db_select_db($database)
    {
        return mysql_select_db($database);
    }

    /**
     * Execute query
     * 
     * @param type $query      query
     * @param type $connection connection
     * 
     * @return type
     */
    private function apphp_db_query($query, $connection)
    {
        $res = mysql_query($query, $connection);
        return $res;
    }
}

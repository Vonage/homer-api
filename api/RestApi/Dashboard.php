<?php
/*
 * HOMER API Engine
 *
 * Copyright (C) 2011-2015 Alexandr Dubovikov <alexandr.dubovikov@gmail.com>
 * Copyright (C) 2011-2015 Lorenzo Mangani <lorenzo.mangani@gmail.com> QXIP B.V.
 *
 * The Initial Developers of the Original Code are
 *
 * Alexandr Dubovikov <alexandr.dubovikov@gmail.com>
 * Lorenzo Mangani <lorenzo.mangani@gmail.com> QXIP B.V.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
*/

namespace RestAPI;

function sessionSearchParamsPersist() {
    if (!isset($_SESSION['uid'])) {
        return;
    }
    if (!isset($_SESSION["saved_searches"])) {
        return;
    }
    if (!isset($_SESSION["current_callid"])) {
        return;
    }
    $callid = $_SESSION["current_callid"];
    if (!isset($_SESSION["saved_searches"][$callid])) {
        return;
    }
    $fromto = $_SESSION["saved_searches"][$callid];
    if (!isset($fromto["from"])) {
        return;
    }
    if (!isset($fromto["to"])) {
        return;
    }
    $from = $fromto["from"];
    $to   = $fromto["to"];
    $uid  = $_SESSION['uid'];

    $callid_arr = array("callid" => $callid);
    $fromto_arr = array("from" => date("Y-m-d\TH:i:s", $from), "to" => date("Y-m-d\TH:i:s", $to));

    $containerClass = sprintf("Database\\".DATABASE_CONNECTOR);
    $db = new $containerClass();
    $db->dbconnect();

    $sql  = $db->makeQuery("DELETE FROM setting WHERE uid = '?' AND param_name = 'search'", $uid);
    $db->executeQuery($sql);

    $sql  = $db->makeQuery("DELETE FROM setting WHERE uid = '?' AND param_name = 'timerange'", $uid);
    $db->executeQuery($sql); 

    $sql  = $db->makeQuery("INSERT INTO setting (uid, param_name, param_value) VALUES ('?', 'search', '?');", $uid, json_encode($callid_arr));
    $db->executeQuery($sql);

    $sql  = $db->makeQuery("INSERT INTO setting (uid, param_name, param_value) VALUES ('?', 'timerange', '?');", $uid, json_encode($fromto_arr));
    $db->executeQuery($sql);
}
function sessionSearchParamsSave($queryParams) {
    if (!isset($_SESSION["saved_searches"])) {
        $_SESSION["saved_searches"] = array();
    }
    $callid = "";
    $from   = "";
    $to     = "";
    foreach ($queryParams as $param_name => $param_value) {
        if ($param_name == "from") {
            $from = $param_value;
        }
        else if ($param_name == "to") {
            $to = $param_value;
        }
        else if ($param_name == "callid") {
            $callid = $param_value;
        }
    }
    if ($callid != "") {
        $_SESSION["saved_searches"][$callid] = array("callid" => $callid, "from" => $from, "to" => $to);
        $_SESSION["current_callid"] = $callid;
    }
}
function sessionSearchParamsLog($message) {
    $request_params   = "<not-set>";
    $session_login    = "<not-set>";
    $current_callid   = "<not-set>";
    $store_search     = "<not-set>";
    $store_timerange  = "<not-set>";

    if (isset($_REQUEST)) {
        $request_params = json_encode($_REQUEST);
    }
    if (isset($_SESSION["current_callid"])) {
        $current_callid = $_SESSION["current_callid"];
    }
    if (isset($_SESSION["loggedin"])) {
        $session_login = $_SESSION["loggedin"];
    }

    if (isset($_SESSION['uid'])) {
        $uid = $_SESSION['uid'];
        $containerClass = sprintf("Database\\".DATABASE_CONNECTOR);
        $db = new $containerClass();
        $db->dbconnect();

        $sql = $db->makeQuery("SELECT param_name, param_value FROM setting WHERE uid = '?' AND param_name IN ( 'timerange', 'search')", $uid);

        $rows = $db->loadObjectArray($sql);
	    foreach($rows as $row) {
            $param_name  = $row["param_name"];
            $param_value = $row["param_value"];

            if ($param_name == "timerange") {
                $store_timerange = $param_value;
            }
            elseif ($param_name == "search") {
                $store_search = $param_value;
            }
        }
    }
    error_log("\n" . date("Y-m-d H:i:s") . " - login=" . $session_login . " current_callid=" . $current_callid . " message=" . $message . "\n" .
        "    Request: (params=" . $request_params . ") \n" .
        "    Store:   (search=" . $store_search . ", timerange=" . $store_timerange . ")\n",
        3, "/var/tmp/homer_flow.log");

    if (isset($_SESSION["saved_searches"])) {
        $saved_searches = $_SESSION["saved_searches"];
        foreach ($saved_searches as $saved_search) {
            error_log("    Session:  (" . json_encode($saved_search) . ")\n");
        }
    }
}

class Dashboard {
    
    private $authmodule = true;
    private $_instance = array();

    /**
    * Clears the cache of the app.
    *
    * @param boolean $withIndex If true, it clears the search index too.
    * @return boolean True if the cache has been cleared.
    */
    public function clearCache($withIndex = false){
        return true;
    }
    
    /**
    * Checks if a user is logged in.
    *
    * @return boolean
    */
    public function getLoggedIn(){
        sessionSearchParamsLog("Dashboard.getLoggedIn()");

	$answer = array();

	if($this->authmodule == false) return $answer;

        if(!$this->getContainer('auth')->checkSession())
	{
		$answer['sid'] = session_id();
                $answer['auth'] = 'false';
                $answer['status'] = 403;
                $answer['message'] = 'bad session';
                $answer['data'] = array();
	}

	return $answer;
    }
    

    public function getDashboard(){
        sessionSearchParamsLog("Dashboard.getDashboard()");

        //if (!is_string($json)) $json = json_encode($json);                                

         if(count(($adata = $this->getLoggedIn()))) return $adata;

        $globalmenu = array();        
	$boards = array();	
	$menu = array();

	$db = $this->getContainer('db');
        $db->select_db(DB_CONFIGURATION);
        $db->dbconnect();

        $table = "user_menu";
        $query = "SELECT id,name,icon,active,alias FROM ".$table." WHERE active = 1 order by weight ASC";
        $data = $db->loadObjectArray($query);
        $skipDashboards = array();

	foreach($data as $row) {
		$menu = array();
		$menu['name'] = $row['name'];
		$menu['href'] = !empty($row['alias']) ? $row['alias'] : $row['id'];
		$menu['class'] = "fa ".$row['icon'];
		$menu['id'] = $row['id'];
		$skipDashboards[$row['id']] = 1;
		$globalmenu[] = $menu;
        }


	/*
	$menu['name'] = 'Home';
	$menu['href'] = 'home';
	$menu['class'] = 'fa fa-home';
	//	$menu['badgeclass'] = 'badge pull-right bg-red';
	//	$menu['badgeinfo'] = 'new';	
	$globalmenu[] = $menu;
	
	$menu = array();	
	$menu['name'] = 'Search';
	$menu['href'] = 'search';
	$menu['class'] = 'fa fa-search';
	$globalmenu[] = $menu;	
	
	$menu['name'] = 'Alarms';
	$menu['href'] = 'alarms';
	$menu['class'] = 'fa fa-home';
	$menu['badgeclass'] = 'badge pull-right bg-red';
	$menu['badgeinfo'] = '5 new';	
	$globalmenu[] = $menu;
	*/


	$menu = array();	
	$menu['name'] = 'Panels';
	$menu['href'] = '#';
	$menu['class'] = 'fa fa-dashboard';
	$menu['rowclass'] = 'fa fa-angle-left pull-right';
	
	/* $parent = new \stdClass;
	$parent->boardid = 1;
	$parent->name = "new";
	$parent->class = "fa fa-plus-circle";
	$boards[] = $parent;
	*/


        /* store */    
        if ($handle = opendir(DASHBOARD_PARAM)) {
	   while (false !== ($file = readdir($handle))) {
		if ($file[0] == "_") {
		
		    $boardid = str_replace(".json", "", $file);
		    
		    if(isset($skipDashboards[$boardid]) && $skipDashboards[$boardid] == 1) continue;
		
		    $dar = DASHBOARD_PARAM."/".$file;
		    $myfile = fopen($dar, "r") or die("Unable to open file!");
		    
		    $text =  fread($myfile, filesize($dar));
		    $json = json_decode($text, true);	
		    
		    $parent = new \stdClass;
                    $parent->boardid = $boardid;		    		    
		    
	            $parent->name = $json['title'];
	            $parent->class = "fa fa-angle-double-right";
		    $boards[] = $parent; 
		    fclose($myfile);		
        	}
	    }
	    closedir($handle);	     	    	    
	}
	
	$menu['subItems'] = $boards;
		
	$globalmenu[] = $menu;	

        return $globalmenu;
    }
    
    public function getIdDashboard($id){
        sessionSearchParamsLog("Dashboard.getIdDashboard()");
        
        if(count(($adata = $this->getLoggedIn()))) return $adata;
         
        //if (!is_string($json)) $json = json_encode($json);                                        

	$db = $this->getContainer('db');
        $db->select_db(DB_CONFIGURATION);
        $db->dbconnect();

        $table = "user_menu";
        $query = "SELECT id FROM user_menu WHERE active = 1 AND alias='?' limit 1";
        $query  = $db->makeQuery($query, $id);
        $data = $db->loadObjectArray($query);
        foreach($data as $row) {  $id = $row['id']; }

	$dar = DASHBOARD_PARAM."/".$id.".json";
	$json = "";
	
	if(file_exists($dar)) 
	{	
        	$myfile = fopen($dar, "r") or die("Unable to open file!");
        	$text =  fread($myfile, filesize($dar));
        	$json = json_decode($text, true);	
        	fclose($myfile);		
        }
        
        return $json;
    }
    
    public function showNewSearch($query) {
        sessionSearchParamsLog("Dashboard.showNewSearch() - before");
        sessionSearchParamsSave($query);
        sessionSearchParamsPersist();
        sessionSearchParamsLog("Dashboard.showNewSearch() - after");

        if (array_key_exists("HTTP_VIA", $_SERVER)) {
            $via_elems = explode(" ", $_SERVER['HTTP_VIA']);
            if (array_key_exists(1, $via_elems)) {
                $via = trim($via_elems[1]);
                $send_to = "http://$via/#/dashboard/search";
                syslog(LOG_NOTICE, "Dashboard.showNewSearch(): getting via $via from headers");
            }
            else {
                $server_name = $_SERVER['SERVER_NAME'];
                $server_port = $_SERVER['SERVER_PORT'];
                $send_to = "http://$server_name:$server_port/#/dashboard/search";
                syslog(LOG_WARNING, "Dashboard.showNewSearch(): via header $via should have at least two components, only seeing one. sending to requested server name");
            }
        }
        elseif (array_key_exists("via", $_GET)) {
            $via = $_GET["via"];
            $send_to = "http://$via/#/dashboard/search";
            syslog(LOG_NOTICE, "Dashboard.showNewSearch(): getting via $via from query parameters");
        }
        else {
            $server_name = $_SERVER['SERVER_NAME'];
            $server_port = $_SERVER['SERVER_PORT'];
            $send_to = "http://$server_name:$server_port/#/dashboard/search";
            syslog(LOG_NOTICE, "Dashboard.showNewSearch(): no via, sending to requested server name");
        }
        syslog(LOG_NOTICE, "Dashboard:showNewSearch(): redirecting to $send_to");
        header("Location: $send_to");
        exit();
    }

    public function newDashboard(){
        
        //if (!is_string($json)) $json = json_encode($json);                                        
	$dar = DASHBOARD_PARAM."/".$id.".json";
	$json = "";
	
	if(file_exists($dar)) 
	{	
        	$myfile = fopen($dar, "r") or die("Unable to open file!");
        	$text =  fread($myfile, filesize($dar));
        	$json = json_decode($text, true);	
        	fclose($myfile);		
        }
        
        return $json;
    }
    
    public function postDashboard(){
        
        //if (!is_string($json)) $json = json_encode($json);                                

        if(count(($adata = $this->getLoggedIn()))) return $adata;
         
	$boards = array();
        
                
        if ($handle = opendir(DASHBOARD_PARAM)) {
	   while (false !== ($file = readdir($handle))) {
		if ($file != "." && $file != "..") {
		    $dar = DASHBOARD_PARAM."/".$file;
		    $myfile = fopen($dar, "r") or die("Unable to open file!");
		    $text =  fread($myfile, filesize($dar));
		    $json = json_decode($text, true);	
		    $parent = new \stdClass;
		    $parent->id = str_replace(".json", "", $file);
	            $parent->title = $json->title;
		    $boards[] = $parent; 
		    fclose($myfile);		
        	}
	    }
	    closedir($handle);
	}

        return $boards;
    }
    
    public function uploadDashboard(){
        
        //if (!is_string($json)) $json = json_encode($json);                                
        if(count(($adata = $this->getLoggedIn()))) return $adata;
  
	$answer = array();
      
 	if ( !empty( $_FILES ) ) {
		$tempPath = $_FILES[ 'file' ][ 'tmp_name' ];
		$uploadPath = DASHBOARD_PARAM."/_".time().".json";
		move_uploaded_file( $tempPath, $uploadPath );
		
		$answer['sid'] = session_id();
                $answer['auth'] = 'true';
                $answer['status'] = 200;
                $answer['message'] = 'File transfer completed';
                $answer['data'] = array();
        }
	else {
		$answer['sid'] = session_id();
                $answer['auth'] = 'true';
                $answer['status'] = 200;
                $answer['message'] = 'no file';
                $answer['data'] = array();
	}
        
        return $answer;
    }
    
    public function postIdDashboard($id){
        
        if(count(($adata = $this->getLoggedIn()))) return $adata;

        $db = $this->getContainer('db');
        $db->select_db(DB_CONFIGURATION);
        $db->dbconnect();
                  
        $table = "user_menu";
        $query = "SELECT id FROM user_menu WHERE active = 1 AND alias='?' limit 1";
        $query  = $db->makeQuery($query, $id);
        $data = $db->loadObjectArray($query);
        foreach($data as $row) {  $id = $row['id']; }
        
        //if (!is_string($json)) $json = json_encode($json);                                        
	$dar = DASHBOARD_PARAM."/".$id.".json";

	$json = file_get_contents("php://input");	   
	    
	if(isset($json)){
        	$jd = json_decode($json);
        	if(json_last_error() == JSON_ERROR_NONE) {
                        $myfile = fopen($dar, "w") or die("Unable to open file!");
                        fwrite($myfile, $json);
                        fclose($myfile);		        
                }
        }
  
        $boards = array();    
        return $boards;
    }    
    
    public function postMenuDashboard($id, $param){
        
	$db = $this->getContainer('db');
        $db->select_db(DB_CONFIGURATION);
        $db->dbconnect();
        
        $data = array();

        $search = array();
        $callwhere = array();
        $calldata = array();
        $arrwhere = "";

        $protect = getVar('protect', false, $param, 'bool');
        $icon = getVar('icon', "", $param, 'string');
        $name = getVar('title', "", $param, 'string');
        $alias = getVar('alias', "", $param, 'string');
        $weight = getVar('weight', 10, $param, 'int');

        $table = "user_menu";
        if($protect)
        {        
            $query = "SELECT id FROM user_menu WHERE active = 1 AND alias='?' limit 1";
            $query  = $db->makeQuery($query, $id);
            $data = $db->loadObjectArray($query);
            foreach($data as $row) {  $id = $row['id']; }
        
            $query = "DELETE FROM ".$table." WHERE id = '?'";
            $query  = $db->makeQuery($query, $id);            
            $db->executeQuery($query);
                                        
            $query = "INSERT INTO ".$table." (id, name, icon, weight, alias) VALUES ('?','?','?',?,'?');";
            $query  = $db->makeQuery($query, $id, $name, $icon, $weight, $alias);            
        }
        else {
            $query = "DELETE FROM ".$table." WHERE id='?'";
            $query  = $db->makeQuery($query, $id);            
        }
                
        $db->executeQuery($query);
        $boards = array();
        return $boards;
    }    
    
    public function getNode(){
        sessionSearchParamsLog("Dashboard.getNode()");

	$db = $this->getContainer('db');
        $db->select_db(DB_CONFIGURATION);
        $db->dbconnect();

        $table = "node";
        $query = "SELECT id,name FROM ".$table." WHERE status = 1 order by id ASC";
        $data = $db->loadObjectArray($query);

	$answer = array();

        if(empty($data)) {

                $answer['sid'] = session_id();
                $answer['auth'] = 'true';
                $answer['status'] = 200;
                $answer['message'] = 'no data';
                $answer['data'] = $data;
                $answer['count'] = count($data);
        }
        else {
                $answer['status'] = 200;
                $answer['sid'] = session_id();
                $answer['auth'] = 'true';
                $answer['message'] = 'ok';
                $answer['data'] = $data;
                $answer['count'] = count($data);
        }

        return $answer;

    }    
    
    
    public function deleteIdDashboard($id){
        
        if(count(($adata = $this->getLoggedIn()))) return $adata;

        $db = $this->getContainer('db');
        $db->select_db(DB_CONFIGURATION);
        $db->dbconnect();

        $table = "user_menu";
        $query = "SELECT id FROM user_menu WHERE active = 1 AND alias='?' limit 1";
        $query  = $db->makeQuery($query, $id);
        $data = $db->loadObjectArray($query);
        foreach($data as $row) {  $id = $row['id']; }
        
        $query = "DELETE FROM user_menu WHERE id='?'";
        $query  = $db->makeQuery($query, $id);
        $db->executeQuery($query);

        //if (!is_string($json)) $json = json_encode($json);                                        
	$dar = DASHBOARD_PARAM."/".$id.".json";
	$json = "";
	
	if(file_exists($dar)) {	
	        unlink($dar);
        }
        
        return $json;
    }
    
    
    public function getContainer($name)
    {
        sessionSearchParamsLog("Dashboard.getContainer()");

        if (!$this->_instance || !isset($this->_instance[$name]) || $this->_instance[$name] === null) {
            //$config = \Config::factory('configs/config.ini', APPLICATION_ENV, 'auth');
            if($name == "auth") $containerClass = sprintf("Authentication\\".AUTHENTICATION);
            else if($name == "layer") $containerClass = sprintf("Database\\Layer\\".DATABASE_DRIVER);                        
            else if($name == "db") $containerClass = sprintf("Database\\".DATABASE_CONNECTOR);
            $this->_instance[$name] = new $containerClass();
        }
        return $this->_instance[$name];
    }
    
}

?>

#!/usr/bin/php
<?php

/**
 * Adding PHP include
 */
set_include_path(get_include_path() . PATH_SEPARATOR . "/usr/local/mgr5/include/php");
define('__MODULE__', "pmteamspeak");

require_once 'bill_util.php';
require_once 'ts3admin.php';

/**
 * [$longopts description]
 * @var array
 */
$longopts  = array
(
    "command:",
    "subcommand:",
    "id:",
    "item:",
    "lang:",
    "module:",
    "itemtype:",
    "intname:",
    "param:",
    "value:",
    "runningoperation:",
    "level:",
    "addon:",
);

$options = getopt("", $longopts);

function GetConnection() {
	$param = LocalQuery("paramlist", array());
	$result = $param->xpath('//elem/*');

	$param_map = array();
	$param_map["DBHost"] = "localhost";
	$param_map["DBUser"] = "root";
	$param_map["DBPassword"] = "";
	$param_map["DBName"] = "billmgr";

	while(list( , $node) = each($result)) {
	    $param_map[$node->getName()] = $node;
	}
	
	return new DB($param_map["DBHost"], $param_map["DBUser"], $param_map["DBPassword"], $param_map["DBName"]);
}
function GetConfig($iid){
	$db = GetConnection();

	$res = $db->query("SELECT * from item where id = ".$iid);
	
	while($row = $res->fetch_assoc())
	{
		foreach($row as $key => $value){
			$p[$key] = $value;
		}
	} 
	// Получение зашифрованных данных.
	#$crypt_param_res = $db->query("SELECT * FROM processingcryptedparam where processingmodule = ".$p['processingmodule']);

	// Получение параметров
	$param_res = $db->query("SELECT * FROM processingparam where processingmodule = ".$p['processingmodule']);
    while ($row = $param_res->fetch_assoc()) {
    	$param[$row["intname"]] = $row["value"];
	}
	// Подключение к контейнеру ТС 3
	$tsAdmin = new ts3admin($param['query_ip'], $param['query_port']);	
		if($tsAdmin->getElement('success', $tsAdmin->connect())) {
		 $tsAdmin->login($param['username'], $param['password']);
		return $tsAdmin;
	}
}

function ItemParam($db, $iid) {
	$res = $db->query("SELECT i.id AS item_id, i.processingmodule AS item_module, i.period AS item_period, i.status AS item_status, i.expiredate, 
							  tld.name AS tld_name 
					   FROM item i 
					   JOIN pricelist p ON p.id = i.pricelist 
					   JOIN tld ON tld.id = p.intname 
					   WHERE i.id=" . $iid);

	if ($res == FALSE)
		throw new Error("query", $db->error);

	#$param = $res->fetch_assoc();
	$param_i = $db->query("SELECT * FROM item WHERE parent = ".$iid);
	while ($row1 = $param_i->fetch_assoc())
	{
		var_dump($param);
		$param['slots'] = $row1['intvalue'];
	}
    $param_res = $db->query("SELECT intname, value FROM itemparam WHERE item = ".$iid);
    while ($row = $param_res->fetch_assoc()) {
    	$param[$row["intname"]] = $row["value"];
    }
    return $param;
}

try {
	$command = $options['command'];
	$runningoperation = array_key_exists("runningoperation", $options) ? (int)$options['runningoperation'] : 0;
	$item = array_key_exists("item", $options) ? (int)$options['item'] : 0;

	Debug("command ". $options['command'] . ", item: " . $item . ", operation: " . $runningoperation);

	if ($command == "features") {

		$config_xml = simplexml_load_string($default_xml_string);

		$itemtypes_node = $config_xml->addChild("itemtypes");
		$itemtypes_node->addChild("itemtype")->addAttribute("name", "voip");

		$params_node = $config_xml->addChild("params");

		$params_node->addChild("param")->addAttribute("name", "host");	
		$params_node->addChild("param")->addAttribute("name", "query_ip");	
		$params_node->addChild("param")->addAttribute("name", "query_port");
		$params_node->addChild("param")->addAttribute("name", "username");	
	
		$password = $params_node->addChild("param");				
		$password->addAttribute("name", "password");
		$password->addAttribute("crypted", "yes");

		$features_node = $config_xml->addChild("features");
		$features_node->addChild("feature")->addAttribute("name", "datacenter"); // Рапределение услуг по дата-центрам
		$features_node->addChild("feature")->addAttribute("name", "check_connection");
		$features_node->addChild("feature")->addAttribute("name", "open");
		$features_node->addChild("feature")->addAttribute("name", "suspend");					
		$features_node->addChild("feature")->addAttribute("name", "resume");
		$features_node->addChild("feature")->addAttribute("name", "close");
		$features_node->addChild("feature")->addAttribute("name", "start");
		$features_node->addChild("feature")->addAttribute("name", "stop");
		$features_node->addChild("feature")->addAttribute("name", "reboot");
		$features_node->addChild("feature")->addAttribute("name", "setparam");	
		echo $config_xml->asXML();
	} elseif ($command == "tune_connection") {
		
	} elseif ($command == "check_connection") {
		$connection_param = simplexml_load_string(file_get_contents('php://stdin'));

		$query_ip = $connection_param->processingmodule->query_ip;
		$query_port = $connection_param->processingmodule->query_port;
		$username = $connection_param->processingmodule->username;
		$password = $connection_param->processingmodule->password;

		$tsAdmin = new ts3admin($query_ip, $query_port);	
		if($tsAdmin->getElement('success', $tsAdmin->connect())) {
			if(!$tsAdmin->getElement('success', $tsAdmin->login($username, $password))){
				throw new Error("invalid_login_or_passwd");
			}
		}

		echo $default_xml_string;

	}
	elseif ($command == "start")
	{
		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item= ItemParam($db, $iid);
		$teamspeak = $ts->serverStart($item['sid']);
		LocalQuery("service.poststart", array("elid" => $iid, "sok" => "ok", ));
	}
	elseif ($command == "stop")
	{
		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item= ItemParam($db, $iid);
		$teamspeak = $ts->serverStop($item['sid']);
		LocalQuery("service.poststop", array("elid" => $iid, "sok" => "ok", ));
	}
	elseif ($command == "reboot"){

		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item= ItemParam($db, $iid);
		$teamspeak = $ts->serverStop($item['sid']);
		$teamspeak = $ts->serverStart($item['sid']);
		LocalQuery("service.postreboot", array("elid" => $iid, "sok" => "ok", ));
	}
	elseif ($command == "open") {

		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item = ItemParam($db, $iid);
		$data = ['virtualserver_name' => $item['name'], 'virtualserver_maxclients' => $item['slots']];
		$teamspeak = $ts->serverCreate($data);
		LocalQuery("voip.open", ['elid' => $iid, 'sid' => $teamspeak['data']['sid'], 'serverId' => 'TS3_'.rand(100000, 999999), 'token' => $teamspeak['data']['token'], 'port' => $teamspeak['data']['virtualserver_port'], 'sok' => 'ok']);
		
	} elseif ($command == "suspend") {
	
		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item= ItemParam($db, $iid);
		$teamspeak = $ts->serverStop($item['sid']);
		LocalQuery("service.postsuspend", array("elid" => $iid, "sok" => "ok", ));

	} elseif ($command == "resume") {

		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item= ItemParam($db, $iid);
		$teamspeak = $ts->serverStart($item['sid']);
		LocalQuery("service.postresume", array("elid" => $iid, "sok" => "ok", ));

	} elseif ($command == "close") {

		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item= ItemParam($db, $iid);
		$teamspeak = $ts->serverDelete($item['sid']);
		LocalQuery("service.postclose", array("elid" => $iid, "sok" => "ok", ));
		
	} elseif ($command == "setparam") {

		$iid = $options['item'];
		$db = GetConnection();
		$ts = GetConfig($iid);
		$item= ItemParam($db, $iid);
		
		$data = ['virtualserver_name' => $item['name'], 'virtualserver_maxclients' => $item['slots']];
		$ts->selectServer($item['sid'], 'serverId');
		$ts->serverEdit($data);
		#var_dump($data);
		LocalQuery("service.postsetparam", array("elid" => $iid, "sok" => "ok", ));

	} elseif ($command == "import") {
		
	}
} catch (Exception $e) {
	if ($runningoperation > 0) {
		// save error message for operation in BILLmanager
		LocalQuery("runningoperation.edit", array("sok" => "ok", "elid" => $runningoperation, "errorxml" => $e,));

		if ($item > 0) {
			// set manual rerung
			LocalQuery("runningoperation.setmanual", array("elid" => $runningoperation,));

			// create task
			$task_type = LocalQuery("task.gettype", array("operation" => $command,))->task_type;
			if ($task_type != "") {
				LocalQuery("task.edit", array("sok" => "ok", "item" => $item, "runningoperation" => $runningoperation, "type" => $task_type, ));
			}
		}
	}

	echo $e;
}

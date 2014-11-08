<?php
// TWEET NEST
// Download archive

error_reporting(E_ALL ^ E_NOTICE); ini_set("display_errors", true); // For easy debugging, this is not a production page
@set_time_limit(0);

require_once "mpreheader.php";
$p = "";

// LOGGING
// The below is not important, so errors surpressed
$f = @fopen("loadlog.txt", "a"); @fwrite($f, "Attempted load " . date("r") . "\n"); @fclose($f);

// Header
$pageTitle = "Esporting tweets from archive";
require "mheader.php";

// Identifying user
if(!empty($_GET['userid']) && is_numeric($_GET['userid'])){
	$q = $db->query(
		"SELECT * FROM `".DTP."tweetusers` WHERE `userid` = '" . $db->s($_GET['userid']) .
			"' LIMIT 1"
	);
	if($db->numRows($q) > 0){
		$p = "user_id=" . preg_replace("/[^0-9]+/", "", $_GET['userid']);
	} else {
		dieout(l(bad("Please load the user first.")));
	}
} else {
	if(!empty($_GET['screenname'])){
		$q = $db->query(
			"SELECT * FROM `".DTP."tweetusers` WHERE `screenname` = '" . $db->s($_GET['screenname']) .
				"' LIMIT 1"
		);
		if($db->numRows($q) > 0){
			$p = "screen_name=" . preg_replace("/[^0-9a-zA-Z_-]+/", "", $_GET['screenname']);
		} else {
			dieout(l(bad("Please load the user first.")));
		}
	}
}

function exportTweets($p){
	global $db, $config, $access, $search;
	$p = trim($p);
    $table = DTP."tweets";
    $type = Array(
        "tweet",
        "reply",
        "retweet"
    );

    echo l("Exporting table {$table}:\n");

    $q = $db->query("SELECT `userid`, `tweetid`, `time` AS 'timestamp', FROM_UNIXTIME(`time`) AS 'datetime', `text`, `type` FROM `".$table."` ORDER BY `time` DESC LIMIT 10");
    if($db->numRows($q) > 0) {
        echo "userid\ttweetid\ttimestamp\tdatetime\ttext\ttype\n";
        while($u = $db->fetch($q)) {
            foreach($u as $key => $val) {
                echo ($key == "type" ? $type[$val] : str_replace(["\n","\t"]," ",trim($val))) . "\t";
            }
            echo "\n";
        }
    }

}

if($p){
	exportTweets($p);
} else {
	$q = $db->query("SELECT * FROM `".DTP."tweetusers` WHERE `enabled` = '1'");
	if($db->numRows($q) > 0){
		while($u = $db->fetch($q)){
			$uid = preg_replace("/[^0-9]+/", "", $u['userid']);
			echo l("<strong>Trying to grab from user_id=" . $uid . "...</strong>\n");
			exportTweets("user_id=" . $uid);
		}
	} else {
		echo l(bad("No users to import to!"));
	}
}

require "mfooter.php";

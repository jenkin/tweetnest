<?php
	// TWEET NEST
	// Load tweets
	
	error_reporting(E_ALL ^ E_NOTICE); ini_set("display_errors", true); // For easy debugging, this is not a production page
	@set_time_limit(0);
	
	require_once "mpreheader.php";
	$p = "";
	
	// LOGGING
	// The below is not important, so errors surpressed
	$f = @fopen("loadlog.txt", "a"); @fwrite($f, "Attempted load " . date("r") . "\n"); @fclose($f);
	
	// Header
	$pageTitle = "Loading tweets";
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
	
	// Define import routines
	function totalTweets($p){
		global $twitterApi;
		$p = trim($p);
		if(!$twitterApi->validateUserParam($p)){ return false; }
        $p = explode('=', $p);
        $data = $twitterApi->query('users/show', array($p[0] => $p[1]));
		if(is_array($data) && $data[0] === false){
			dieout(l(bad('Error: ' . $data[1] . '/' . $data[2])));
		}
		return $data->statuses_count;
	}
	
	function importTweets($p){
		global $twitterApi, $db, $config, $access, $search;
		$p = trim($p);
		if(!$twitterApi->validateUserParam($p)){ return false; }
		$maxCount = (empty($config['q']) ? 200 : 100); // Search API limits are lower than the User timeline one
		$tweets   = array();
		$sinceID  = 0;
		$maxID    = 0;

		// Check for authentication
		if(!isset($config['consumer_key']) || !isset($config['consumer_secret'])){
			die("Consumer key and secret not found. These are required for authentication to Twitter. \n" .
				"Please point your browser to the authorize.php file to configure these.\n");
		}

        list($userparam, $uservalue) = explode('=', $p);
		
		echo l("Importing:\n");
		
		// Do we already have tweets?
		$pd = $twitterApi->getUserParam($p);
		if($pd['name'] == "screen_name"){
			$uid        = $twitterApi->getUserId($pd['value']);
			$screenname = $pd['value'];
		} else {
			$uid        = $pd['value'];
			$screenname = $twitterApi->getScreenName($pd['value']);
		}
        if (isset($config['q'])) {
            $tiQ = $db->query("SELECT `tweetid` FROM `".DTP."tweets` ORDER BY `time` DESC LIMIT 1");
        } else {
            $tiQ = $db->query("SELECT `tweetid` FROM `".DTP."tweets` WHERE `userid` = '" . $db->s($uid) . "' ORDER BY `time` DESC LIMIT 1");
        }
		if($db->numRows($tiQ) > 0){
			$ti      = $db->fetch($tiQ);
			$sinceID = $ti['tweetid'];
		}
		
		echo l("User ID: " . $uid . "\n");

        if(empty($config['q'])) {
    		// Find total number of tweets
	    	$total = totalTweets($p);
		    if(is_numeric($total)){
		    	if($total > 3200){ $total = 3200; } // Due to current Twitter limitation
			    $pages = ceil($total / $maxCount);

    			echo l("Total tweets: <strong>" . $total . "</strong>, Approx. page total: <strong>" . $pages . "</strong>\n");
            }
        }

		if($sinceID){
			echo l("Newest tweet I've got: <strong>" . $sinceID . "</strong>\n");
		}
		
		$page = 1;
		
		// Retrieve tweets
		do {
			// Announce
			echo l("Retrieving page <strong>#" . $page . "</strong>:\n");
			// Get data
            $params = array(
                $userparam         => $uservalue,
                'include_rts'      => true,
                'include_entities' => true,
                'count'            => $maxCount
            );

            if($sinceID){
                $params['since_id'] = $sinceID;
            }
            if($maxID){
                $params['max_id']   = $maxID;
            }
            if(!empty($config['q'])) {
                $params['q'] = $config['q'];
                $params['result_type'] = 'recent';
            }

            if(isset($params['q'])) {
                $data = $twitterApi->query('search/tweets', $params);
                if (is_object($data) && property_exists($data,'statuses')) {
                    $data = $data->statuses;
                } else { // Drop out on connection error
                    dieout(l(bad("Error: " . $data[1] . "/" . $data[2])));
                }
            } else {
                $data = $twitterApi->query('statuses/user_timeline', $params);
			    // Drop out on connection error
    			if(is_array($data) && $data[0] === false){ dieout(l(bad("Error: " . $data[1] . "/" . $data[2]))); }
            }
            
			// Start parsing
			echo l("<strong>" . ($data ? count($data) : 0) . "</strong> new tweets on this page\n");
			if(!empty($data)){
				echo l("<ul>");
				foreach($data as $i => $tweet){

                    // First, let's check if an API error occured
                    if(is_array($tweet) && is_object($tweet[0]) && property_exists($tweet[0], 'message')){
                        dieout(l(bad('A Twitter API error occured: ' . $tweet[0]->message)));
                    }

					// Shield against duplicate tweet from max_id
					if(!IS64BIT && $i == 0 && $maxID == $tweet->id_str){ unset($data[0]); continue; }
					// List tweet
					echo l("<li>" . $tweet->id_str . " " . $tweet->created_at . "</li>\n");
					// Create tweet element and add to list
					$tweets[] = $twitterApi->transformTweet($tweet);
					// Determine new max_id
					$maxID    = $tweet->id_str;
					// Subtracting 1 from max_id to prevent duplicate, but only if we support 64-bit integer handling
					if(IS64BIT){
						$maxID = (int)$tweet->id - 1;
					}
				}
				echo l("</ul>");
			}
			$page++;
		} while(!empty($data));
		
		if(count($tweets) > 0){
			// Ascending sort, oldest first
			$tweets = array_reverse($tweets);
			echo l("<strong>All tweets collected. Reconnecting to DB...</strong>\n");
			$db->reconnect(); // Sometimes, DB connection times out during tweet loading. This is our counter-action
			echo l("Inserting into DB...\n");
			$error = false;
			foreach($tweets as $tweet){
				$q = $db->query($twitterApi->insertQuery($tweet));
				if(!$q){
					dieout(l(bad("DATABASE ERROR: " . $db->error())));
				}
				$text = $tweet['text'];
				$te   = $tweet['extra'];
				if(is_string($te)){ $te = @unserialize($tweet['extra']); }
				if(is_array($te)){
					// Because retweets might get cut off otherwise
					$text = (array_key_exists("rt", $te) && !empty($te['rt']) && !empty($te['rt']['screenname']) && !empty($te['rt']['text']))
						? "RT @" . $te['rt']['screenname'] . ": " . $te['rt']['text']
						: $tweet['text'];
				}
				$search->index($db->insertID(), $text);
			}
			echo !$error ? l(good("Done!\n")) : "";
		} else {
			echo l(bad("Nothing to insert.\n"));
		}

        if(empty($config['q'])) {    
    		// Checking personal favorites -- scanning all
	    	echo l("\n<strong>Syncing favourites...</strong>\n");
		    // Resetting these
    		$favs  = array(); $maxID = 0; $sinceID = 0; $page = 1;
	    	do {
		    	echo l("Retrieving page <strong>#" . $page . "</strong>:\n");

                $params = array(
                    $userparam => $uservalue,
                    'count'    => $maxCount
                );
    
                if($maxID){
                    $params['max_id']   = $maxID;
                }

    			$data = $twitterApi->query('favorites/list', $params);
    
	    		if(is_array($data) && $data[0] === false){ dieout(l(bad("Error: " . $data[1] . "/" . $data[2]))); }
		    	echo l("<strong>" . ($data ? count($data) : 0) . "</strong> total favorite tweets on this page\n");

    			if(!empty($data)){
	    			echo l("<ul>");
		    		foreach($data as $i => $tweet){
    
                        // First, let's check if an API error occured
                        if(is_array($tweet) && is_object($tweet[0]) && property_exists($tweet[0], 'message')){
                            dieout(l(bad('A Twitter API error occured: ' . $tweet[0]->message)));
                        }

    					if(!IS64BIT && $i == 0 && $maxID == $tweet->id_str){ unset($data[0]); continue; }
	    				if($tweet->user->id_str == $uid){
		    				echo l("<li>" . $tweet->id_str . " " . $tweet->created_at . "</li>\n");
			    			$favs[] = $tweet->id_str;
				    	}
    					$maxID = $tweet->id_str;
	    				if(IS64BIT){
		    				$maxID = (int)$tweet->id - 1;
			    		}
				    }
    				echo l("</ul>");
	    		}
		    	echo l("<strong>" . count($favs) . "</strong> favorite own tweets so far\n");
			    $page++;
    		} while(!empty($data));
            
            // Blank all favorites
    		$db->query("UPDATE `".DTP."tweets` SET `favorite` = '0'");
	    	// Insert favorites into DB
		    $db->query("UPDATE `".DTP."tweets` SET `favorite` = '1' WHERE `tweetid` IN ('" . implode("', '", $favs) . "')");
    		echo l(good("Updated favorites!"));
        }
	}
	
	if($p){
		importTweets($p);
	} else {
		$q = $db->query("SELECT * FROM `".DTP."tweetusers` WHERE `enabled` = '1'");
		if($db->numRows($q) > 0){
			while($u = $db->fetch($q)){
				$uid = preg_replace("/[^0-9]+/", "", $u['userid']);
				//echo l("<strong>Trying to grab from user_id=" . $uid . "...</strong>\n");
                if (!empty($config['q'])) {
				    echo l("<strong>Trying to grab using query = '" . $config['q'] . "' from user_id=" . $uid . "...</strong>\n");
                } else {
                    echo l("<strong>Trying to grab from user_id=" . $uid . "...</strong>\n");
                }
				importTweets("user_id=" . $uid);
			}
		} else {
			echo l(bad("No users to import to!"));
		}
	}
	
	require "mfooter.php";

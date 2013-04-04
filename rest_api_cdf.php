<?php

//REST API server object class; initialize in index.php
class REST_API {
    public  $_content_type = "application/json";
    public  $_request = array();
    private $_code = 200;

    //DB connection
    private $_db = NULL;    

    // Constructor:
    public function __construct() {
		//connect to database
		$this->dbConnect();
        switch( $_SERVER['REQUEST_METHOD'] ) {
            case "POST": //request POST; is either new blog or update
				if ($_SERVER['REQUEST_URI'] == '/blog' and isset($_POST['blog'])) 	//new blog to track
					$this->trackPostsFromHost($_POST['blog']);
				else if ($_SERVER['REQUEST_URI'] == '/update') 
					$this->updateTracking();
				else { //bad request
					echo "Malformed request, try again.\nThe only accepted POST request is /blog; you must also provide a valid blog parameter to track.\nTry: curl -X POST -d blog={base-hostname} http://localhost/blog.";
					$this->response('', 400);
				}
                break;
            case "GET": //request GET; query request
				if (preg_match("#\/blog\/(.*)\/trends(.*)|\/blogs\/trends(.*)#", $_SERVER['REQUEST_URI'])) //check if well-formed
					$this->getPosts();
				else //bad form
					echo "Poorly formed url, try again.\nRequest should be either GET /blog/{base-hostname}/trends or GET /blogs/trends; both of which requires the 'order' parameter as either 'trending' or 'recent'. You may also provide optional 'limit' paramter as an int; otherwise the default is 10.";
					$this->response('', 400);
            default:
                $this->response('',406);
                break;
        }  
    }


    /* Database connection */
    private function dbConnect() {
        // If the db is null, connect to the database
        if (!$this->_db) {
			$this->_db = new mysqli("dbsrv1.cdf.toronto.edu", "c1kwongk", "ietheiki", "csc309h_c1kwongk", 3306);
			if ($this->_db->connect_errno) { 
				echo "Failed to connect to MySQL: (" . $this->_db->connect_errno . ") " . $this->_db->connect_error;
			} 
        }
    }    


	//Add new blog hostname to track posts from;
	//calls updateTracking at the end for sequence sync.
	private function trackPostsFromHost($hostname) {
		if (!$hostname) {
			echo "no blog hostname provided!";
			return;
		}
		// get all posts liked by blog from tumblr
		// error supressed; is custom handled after
		$jsonData = @file_get_contents("http://api.tumblr.com/v2/blog/".$hostname."/likes?api_key=X4OwRgt85WvYhvIF8vFT4GeU8olMvf3HWKZvS6OKaMbeHBoXtG");
		if (!$jsonData) { //no response from tumblr
			echo 'unable to retrieve blog info from tumblr';
			$this->response('', 404);
			return;
		}
		$phpData = json_decode($jsonData);
		//print ($phpData->response->liked_posts->type);
		// Now need to query + insert result to database;
		// table "a2_post" will store all the "static" info
		// about a post; ie. url, image, text.
		// "dynamic" info will be stored in "a2_tracking";
		// ie. timestamps, likes, changes in likes, sequence, etc.
		$this->newPostTable();
		foreach ($phpData->response->liked_posts as $post) {
			//get image link if exists; some posts dont have them, indicate as such
			if ($post->type == 'photo') 
				$image = $post->photos[0]->alt_sizes[0]->url;
			else
				$image = "none image post";
			$pieces = explode('/', $post->post_url); //slug not always present; use last part of url as text instead.
			$query = "INSERT INTO a2_posts VALUES ('".$hostname."', '".$post->post_url."', '".$post->date."', '".$image."', '".end($pieces)."')";
			$this->sendQuery($query);
		}
		//update tracking for sequence sync
		$this->updateTracking();
		$this->response('', 200); //accepted response
	}

	// Update trackings of a2_tracking by:
	// 1. loop through all unique hostnames in a2_posts
	// 2. get updated info from tumblr
	// 3. compare old note_count from most recent stamp with new likes from step  //    2, store as increment.
	// 4. also calculate and store: timestamp, new note_count, sequence.
	private function updateTracking () {
		$this->newTrackingTable(); //create table if not exist
		$query = "SELECT DISTINCT hostname FROM a2_posts"; //get all hostnames to loop through.
		$res = $this->sqlResToArray($this->sendQuery($query));
		//loop through each hostname stored.
		foreach ($res as $temp) { //look through each hostname
			$hostname = $temp["hostname"];
			// query tumblr for updated info
			$jsonData = file_get_contents("http://api.tumblr.com/v2/blog/".$hostname."/likes?api_key=X4OwRgt85WvYhvIF8vFT4GeU8olMvf3HWKZvS6OKaMbeHBoXtG");
			$newData = json_decode($jsonData);

			//loop through each post liked by blog (hostname)
			foreach ($newData->response->liked_posts as $post) {
				$query = "DROP VIEW IF EXISTS temp"; //drop previous temp view
				$this->sendQuery($query);		
				//query the most recent tracking for this post from a2_tracking
				$query = "CREATE VIEW temp AS SELECT * FROM a2_tracking WHERE url = '".$post->post_url."'"; 
				$this->sendQuery($query);
				//most recent tracking has max id #; due to sync.
				$query = "SELECT t1.* FROM temp t1 LEFT OUTER JOIN temp t2 ON (t1.url = t2.url AND t1.id < t2.id) WHERE t2.url IS NULL";
				$res = $this->sendQuery($query);
				$previousTracking = $this->sqlResToArray($res);
				$timestamp = date("Y-m-d h:i:s a", time()); //get timestamp
				
				//check if there is previous tracking, if not create new tracking entry
				if (!$previousTracking) { //no tracking exists, insert as the first
					$query = "INSERT INTO a2_tracking (hostname, url, timestamp, sequence, increment, count) VALUES ('".$hostname."', '".$post->post_url."', '".$timestamp."', 1, 0, '".$post->note_count."')";
					$this->sendQuery($query);
				} else { //tracking exists, need to update.
					//calculate new values
					$newSequence = $previousTracking[0]["sequence"] + 1;
					$newCount = $post->note_count;
					$increment = $newCount - $previousTracking[0]["count"];
					// insert new tracking entry
					$query = "INSERT INTO a2_tracking (hostname, url, timestamp, sequence, increment, count) VALUES ('".$hostname."', '".$post->post_url."', '".$timestamp."', '".$newSequence."', '".$increment."', '".$newCount."')";
					$this->sendQuery($query);
				}
			}
		} 
	}


	// Creates a new table for storing dynamic information of a post;
	// Has: hostname, url of post, timestamp, sequence, increment, count
	private function newTrackingTable () {
		//set timezone for timestamps
		date_default_timezone_set('America/Toronto');
		$query = 
			"CREATE TABLE IF NOT EXISTS a2_tracking (id INTEGER AUTO_INCREMENT, hostname VARCHAR(100), url VARCHAR(100), timestamp VARCHAR(20), sequence INTEGER, increment INTEGER, count INTEGER, PRIMARY KEY (id))";
		$this->sendQuery($query);
	}


	// Creates a new post table if not exist already, else do nothing.
	// url as primary key; should not be able to insert same post
	// This table tracks static information of a post.
	private function newPostTable () {
		$query = 
			"CREATE TABLE IF NOT EXISTS a2_posts (hostname VARCHAR(100), url VARCHAR(100), date VARCHAR(20), image VARCHAR(100), text VARCHAR(100), PRIMARY KEY (url))";
		$this->sendQuery($query);
	}


	// Function to handle SQL queries; takes a query as arg, prints error msgs if any.
	// Returns query result.
	private function sendQuery ($query) {
		$res = $this->_db->query($query);
		if (!$res) {
			//echo $this->_db->error."\n";
			return null;
		} else {
			return $res;
		}
	}

	// Function to query database for posts based on parameters give;
	// Client must provide parameter "order" as either "recent" or "trending;
	// optional base-hostname as part of the url and optional limit as parameter.
	// 
	// "recent" returns posts recently added to tracking
	// "trending" returns posts that have the greatest "increment"
	// optional "base-hostname" tracks post from only specified blog host;
	// default all
	// optional "limit" limits number of posts returned; default 10.
	private function getPosts () {
		//separate url string
		$pieces = explode("/", $_SERVER['REQUEST_URI']);
		if ($pieces[1] == 'blog') //request is for single blog  
			$hostname = $pieces[2];
		else //request is for any blog
			$hostname = null;
		if (isset($_GET['limit'])) 
			$limit = $_GET['limit'];
		 else 
			$limit = 10;
		if (isset($_GET['order'])) 
			$format = $_GET['order'];
		 else { //no ordering provided; error response
			echo "You must provide valid 'order' parameter as either 'recent' or 'trending' no results\n";
			$this->response('', 400);
			return;
		}

		//customize query to include only results with specified hostname
		if ($hostname)
			$restrict = " WHERE hostname = '".$hostname."'";
		else 
			$restrict = "";

		//get post table
		$query = "SELECT * FROM a2_posts".$restrict;
		$res = $this->sendQuery($query);
		$postsArray = $this->sqlResToArray($res);

		if (!$postsArray) { //could not query db for host name
			echo "That blog hostname is invalid or not being tracked; no results\n";
			$this->response('', 400);		
			return;
		}

		//get tracking table
		$query = "SELECT * FROM a2_tracking".$restrict;
		$res = $this->sendQuery($query);
		$trackingArray = $this->sqlResToArray($res);

		$topLayer = array(); //top level of JSON response
		$postPackage = array(); //array for individual post

		//loop through each entry in a2_posts, insert each post into array
		//include all extra info
		foreach ($postsArray as $post) {
			$singlePost = array();
			$singlePost["url"] = $post["url"];
			$singlePost["text"] = $post["text"];
			$singlePost["image"] = $post["image"];
			$singlePost["date"] = $post["date"];
			
			//create the individual tracking array for this post ...
			$trackingCollection = array();
			foreach ($trackingArray as $temp) {
				$singleTracking = array(); //array for this tracking
				if ($temp["url"] == $post["url"]) {
					$singleTracking["timestamp"] = $temp["timestamp"];
					$singleTracking["sequence"] = $temp["sequence"];
					$singleTracking["increment"] = $temp["increment"];
					$singleTracking["count"] = $temp["count"];
					array_push($trackingCollection, $singleTracking);
				}
			}

			//sort trackings by sequence
			//must reset $sequence array here or previous data will carry over and bug out the cmp
			$sequence = array(); 
			foreach ($trackingCollection as $key => $row) {
				$sequence[$key] = $row['sequence'];
			}

			array_multisort($sequence, SORT_DESC, $trackingCollection);
			//push last_track and last_count
			$singlePost["last_track"] = $trackingCollection[0]["timestamp"];
			$singlePost["last_count"] = $trackingCollection[0]["count"];
			//push tracking array to this post's array
			$singlePost["tracking"] = $trackingCollection;
			//push this post array to the top array
			array_push($postPackage, $singlePost); 
		}

		//formatting branch for either "trending" or "recent"
		if ($format == "trending" || $format == "Trending") {
			//comparison function for trending (ie. max increment in last tracking)
			function compareByRecentIncrement ($a, $b) {
				return $b["tracking"][0]["increment"] - $a["tracking"][0]["increment"];
			}
			//sort top layer with cmp function
			usort($postPackage, "compareByRecentIncrement");
			//slice by limit
			$topLayer["trending"] = array_slice($postPackage, 0, $limit);
			$order = "Trending";

		} else if ($format == "recent" || $format == "Recent") {
			//comparison function for most recent (ie. lowest max sequence)
			function compareBySequence ($a, $b) {
				return $a["tracking"][0]["sequence"] - $b["tracking"][0]["sequence"];
			}
			//sort top layer with cmp function
			usort($postPackage, "compareBySequence");
			$topLayer["recent"] = array_slice($postPackage, 0, $limit);
			$order = "Recent";
		}
		//tack on the rest of top layer info
		$topLayer["order"] = $order;
		$topLayer["limit"] = $limit;

		$jsonData = json_encode($topLayer);
		$this->response($jsonData, 200);
	}

	
	//Parses SQL query result and return as a PHP array
	private function sqlResToArray($res) { //appends sql query result to php array
		$phpArray = array();
		$res->data_seek(0);
        while ($row = $res->fetch_assoc()) {
            $phpArray[] = $row;
        }
		return $phpArray;
	}


	//Response function
    public function response($data, $status=null)
    {
        $this->_code = $status ? $status : 200;
        # Set http header
        header("HTTP/1.1 ".$this->_code);
        header("Content-Type:".$this->_content_type);
        echo $data;
    }    
}	
?>

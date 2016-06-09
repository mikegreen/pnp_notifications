<?php

// this checks AWS DB for the latest topic, then gets the next higher numbered topic_id in the PNP DB
// then, it inserts into the AWS DB (just a few fields we need)

// include forum config file for DB info
include "settings.php";
include ($configPath);
include "xport_functions.php";

echo "Environment: $environment"; 
newline();

// define the prefix of each log message
$logType = '[topic xport]'; 

// get DB creds from forum config
$f_username=$dbuser;
$f_password=$dbpasswd;
$f_database=$dbname;
$f_server=$dbhost;

// hardcode creds for AWS DB
// TODO: move these to config file
// $aws_username
// $aws_password 
// $aws_database
// $aws_server


// define tables, we could use phpbb's constants.php but unsure how that will work with upgrade
$table_topics = 'phpbb_topics'; 
$table_aws_topics = 'pnp_topics' ; // table in AWS that holds replicated topic data, but only columns we need
$table_notif = 'pnp_trip_notif_status' ; // table that knows if we sent a notif to a user for a topic yet
$forum_id = '5' ; // we only care about the trip request forum

// define forum mysqli connection
$f_mysqli = new mysqli($f_server, $f_username, $f_password, $f_database);

echo nl2br ("Forum database: $f_server/$f_database \n" ) ; 

 // Check forum connection
if (mysqli_connect_errno($f_mysqli))
  {
  echo "Failed to connect to forum MySQL: " . mysqli_connect_error();
  } else { } ;

// define AWS mysqli connection
$aws_mysqli = new mysqli($aws_server, $aws_username, $aws_password, $aws_database);

echo nl2br ("AWS database: $aws_server/$aws_database \n\n" ) ; 

 // Check AWS connection
if (mysqli_connect_errno($aws_mysqli))
  {
  echo "Failed to connect to AWS MySQL: " . mysqli_connect_error();
  } else { } ;

$maxAWSTopicId = getMaxAWS();
$nextForumTopicId = getNextForum($maxAWSTopicId);

if($nextForumTopicId > $maxAWSTopicId) {
	echo nl2br ("forum has new topic\n\n");
	$topicDetails = getNextTopicDetails($nextForumTopicId); 
};

function getMaxAWS()
{
	global $forum_id, $aws_mysqli, $table_aws_topics, $f_database;
	// get max topic_id from AWS topics - make sure its the same DB (xpilotspaws-forum, for ex)
	$query_get_max_aws_topic = "SELECT max(topic_id) as max_topic_id " .
		" FROM $table_aws_topics " . 
		" WHERE forum_id = $forum_id and source_database = '$f_database'" .
		" HAVING max_topic_id IS NOT NULL" ; 
	echo nl2br ("AWS max topic query: $query_get_max_aws_topic \n" ) ;
	$result = $aws_mysqli->query($query_get_max_aws_topic) or die ($aws_mysqli->error);

	$rowsReturned = $result->num_rows; 
	echo nl2br ("Rows returned: $rowsReturned \n") ; 

	if($rowsReturned == 0) {
		echo logEvent("AWS has no topics, starting from 0");
		newLine();
		$topicId = 0;
	}
		else {
		while($row = $result->fetch_assoc()){
			$topicId = $row['max_topic_id'];
			echo logEvent("Max topic_id from AWS: $topic_id");
			newline();
		}
	}

	return $topicId;

}

function getNextForum($maxTopic)
{	
	global $forum_id, $f_mysqli, $table_topics;

	// from forum db, get the next higher # topic 
	$query_get_next_topic = "SELECT min(topic_id) as min_topic_id, max(topic_id) as max_topic_id " .
		" FROM $table_topics WHERE forum_id = $forum_id AND topic_id > $maxTopic" ;
	echo nl2br("Forum query: $query_get_next_topic\n");

	$result = $f_mysqli->query($query_get_next_topic) or die ($f_mysqli->error);

	$rowsReturned = $result->num_rows; 
	echo nl2br ("Rows returned: $rowsReturned \n") ; 

	// exit if we dont get any new topics
	//if($rowsReturned < 1) {
	//	echo "Nothing to do! Stopping. ";
	//	die;
	//	}

	while($row = $result->fetch_assoc()){
		echo $row['min_topic_id'];
		echo logEvent("Next topic_id in forum DB: " . $row['min_topic_id']);
		$topic_id = $row['min_topic_id'];
		newLine();
		echo logEvent("Greatest topic_id in forum DB: " . $row['max_topic_id']);
		newline();
	}

	// echo ($topic_id - $maxAWSTopicId);

	return $topic_id;
}

function getNextTopicDetails($topic_id)
{	
	global $forum_id, $f_mysqli, $table_topics;

	$startTS = microtime(true);
	echo "Start microtime: $startTS";
	newline();

	$rowsSuccessCounter = 0;

	// from forum db, get the next higher # topic 
	$query_fields = " topic_id, forum_id, topic_title, topic_first_poster_name, pnp_sendZip, pnp_recZip " ;
	$query_get_next_topic = "SELECT $query_fields FROM $table_topics " .
		" WHERE forum_id = $forum_id AND topic_id >= $topic_id " .
		" ORDER BY topic_id LIMIT 100" ;
	echo nl2br("Forum details query: $query_get_next_topic\n");

	$result = $f_mysqli->query($query_get_next_topic) or die ($f_mysqli->error);

	$rowsReturned = $result->num_rows; 
	echo nl2br ("Rows returned: $rowsReturned \n") ; 

	while($row = $result->fetch_assoc()){
		echo logEvent("Next topic_id in forum DB: " . $row['topic_id' ]);
		newline();
		$topic_id = $row['topic_id'];
		$forum_id = $row['forum_id'];
		$topic_title = $f_mysqli->real_escape_string($row['topic_title']);
		$topic_first_poster_name = $f_mysqli->real_escape_string($row['topic_first_poster_name']);
		$pnp_sendZip = $row['pnp_sendZip'];
		$pnp_recZip = $row['pnp_recZip'];

		// insert into AWS
		insertTopic($topic_id, $forum_id, $topic_title, $topic_first_poster_name, $pnp_sendZip, $pnp_recZip);
		$rowsSuccessCounter = $rowsSuccessCounter + 1; 

	}

	$endTS = microtime(true);
	echo "Ending microtime: $endTS";
	newline();
	$durationTime = $endTS - $startTS;
	echo logEvent("Duration: $durationTime seconds for $rowsSuccessCounter rows");
	newLine();

}

function insertTopic($topic_id, $forum_id, $topic_title, $topic_first_poster_name, $pnp_sendZip, $pnp_recZip)
{
	// insert this topic and details into AWS
	global $forum_id, $aws_mysqli, $table_aws_topics, $f_server, $f_database, $rowsSuccessCounter;

	$insertFields = " topic_id, forum_id, topic_title, topic_first_poster_name, pnp_sendZip, pnp_recZip, source_server, source_database " ;
	$insertQuery = "INSERT INTO $table_aws_topics ($insertFields) VALUES ( '$topic_id', '$forum_id', '$topic_title', '$topic_first_poster_name', '$pnp_sendZip', '$pnp_recZip', '$f_server', '$f_database')"; 

	echo nl2br ("Insert to AWS: $insertQuery \n\n ");

	$result = $aws_mysqli->query($insertQuery) ;
	if(!$result) {
			echo logEvent("Error: $aws_mysqli->error for insert: $insertQuery");
		} else
		{
			echo logEvent("Success: $insertQuery");
			$rowsSuccessCounter = $rowsSuccessCounter + 1; 
		
		}
}


// close connections
$f_mysqli->close();
$aws_mysqli->close();


?>

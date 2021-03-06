<?php

//  this copies users from the forum into the AWS database so we can send distance notifications

// include forum config file for DB info
include "settings.php";
include ($configPath);
include "xport_functions.php";
include "pnp_db.php";

echo "Environment: $environment"; 
newline();

// define the prefix of each log message
$logType = '[user xport]'; 

// define tables, we could use phpbb's constants.php but unsure how that will work with upgrade
$table_users = 'phpbb_users'; 
$table_users_details = 'vw_volunteers';  // view with location data
$table_aws_users = 'pnp_users' ; // table in AWS that holds replicated topic data, but only columns we need
$table_notif = 'pnp_trip_notif_status' ; // table that knows if we sent a notif to a user for a topic yet

$maxUserForum = getMaxUserForum();
$maxUserAWS = getMaxUserAWS();

if($maxUserForum > $maxUserAWS) {
	echo nl2br ("Forum has newer users than AWS ");
	newLine();
	getNextUserForum($maxUserAWS); // give the max user id that AWS has as a starting point 
};

// TODO: Get highest user ID in forum
function getMaxUserForum()
{
	global $table_users, $f_database, $f_mysqli;
	$queryMaxUserForum = "SELECT max(user_id)as max_user_id from $table_users;" ;
	echo $queryMaxUserForum;
	newLine();
	$result = $f_mysqli->query($queryMaxUserForum) ; //or die ($f_mysqli->error);

	if(!$result) {
		echo logEvent("Error $f_mysqli->error to get max user id from forum, exiting. Query: $queryMaxUserForum");
	} else {
		while($row = $result->fetch_assoc()){ 
			$user_id = $row['max_user_id'];
			echo logEvent("Max user_id from forum: $user_id");
			newLine();
		}
			return $user_id;		
	}
} // end getMaxUserForum

function getMaxUserAWS()
{
	global $table_aws_users, $f_database, $aws_mysqli;
	$queryMaxUserAWS = "SELECT max(user_id) AS max_user_id " .
		" FROM $table_aws_users WHERE source_database = '$f_database' " .
		" HAVING max_user_id IS NOT NULL;" ;
	echo $queryMaxUserAWS;
	newLine();
	$result = $aws_mysqli->query($queryMaxUserAWS); // or die ($aws_mysqli->error);

	if(!$result) {
			echo logEvent("Error $aws_mysqli->error to get max user id from AWS, exiting. Query: $queryMaxUserAWS");
			exit();
	} else {
			$rowsReturned = $result->num_rows; 
			echo nl2br ("Rows returned: $rowsReturned \n") ; 

			if($rowsReturned == 0) {
				echo logEvent("AWS has no users, starting from 0");
				newLine();
				$userId = 0;
			}
				else {
				while($row = $result->fetch_assoc()){
					$userId = $row['max_user_id'];
					echo logEvent("Max user_id from AWS: $userId");
					newline();
				}
			}
	}
	
	return $userId;
}

// TODO: If forum has new user, extract and load that user forum->AWS
function getNextUserForum($maxUserAWS) 
{
	global $table_users, $table_aws_users, $f_server, $f_database, $f_mysqli, $aws_mysqli, $table_users_details;

	//get start time to see how long this takes for logging
	$startTS = microtime(true);
	echo "Start microtime: $startTS";
	newline();

	$rowsSuccessCounter = 0;

	$queryNextUserForum = "SELECT last_visit, user_id,user_email,user_regdate,username,pf_flying_radius, " .
		" pf_foster_yn, pf_pilot_yn, apt_id, apt_name, zip, COALESCE(lat,0) as lat , COALESCE(lon,0) as lon, " .
		" city, state, CURRENT_TIMESTAMP, user_inactive_reason " . 
 		" FROM $table_users_details " .
 		" WHERE user_id > $maxUserAWS " .
		" 	and user_inactive_reason in (0,1)  /* include active and just-registered only, exclude deactivated users */ " .
 		" ORDER BY user_id LIMIT 300 "; // increase once we know it won't blow up
	echo "getNextUserForum: $queryNextUserForum" ;
	newLine();
	$result = $f_mysqli->query($queryNextUserForum) or die ($f_mysqli->error);

	while($row = $result->fetch_assoc()){ 
		$userId = $row['user_id'];
		$lastVisit = $row['last_visit'];
		$userEmail = $f_mysqli->real_escape_string($row['user_email']);
		$userRegdate = $row['user_regdate'];
		$username = $f_mysqli->real_escape_string($row['username']);
		$userInactiveReason = $row['user_inactive_reason'];
		$flyingRadius = $row['pf_flying_radius'];
		$foster = $row['pf_foster_yn'];
		$pilot = $row['pf_pilot_yn'];
		$aptId = $row['apt_id'];
		$aptName = $f_mysqli->real_escape_string($row['apt_name']);
		$zip = $row['zip'];
		$lat = $row['lat'];
		$lon = $row['lon'];
		$city = $f_mysqli->real_escape_string($row['city']);
		$state = $row['state'];
		$currentTimestamp = $row['CURRENT_TIMESTAMP'];
		echo logEvent("Next user_id from forum: $userId");
		newLine();

	// insert user into AWS 

		$insertFields = " user_id, last_visit, user_email, user_regdate, username, pf_flying_radius, " .
			" pf_foster_yn, pf_pilot_yn, apt_id, apt_name, zip, lat, lon, location_point, " .
			" city, state, updated_source_ts, source_server, source_database, user_inactive_reason " ; 
		$queryInsert = " INSERT INTO $table_aws_users ($insertFields) VALUES " .
			" ( $userId, '$lastVisit', '$userEmail', '$userRegdate', '$username', '$flyingRadius', " . 
			" '$foster', '$pilot', '$aptId', '$aptName', '$zip', '$lat', '$lon', " .
			" ST_GeomFromText('POINT($lon $lat)'), '$city', '$state', '$currentTimestamp', " .
			" '$f_server', '$f_database', $userInactiveReason); ";

		$insertResult = $aws_mysqli->query($queryInsert) ; // or die ($aws_mysqli->error);

		if(!$insertResult) {
				echo logEvent("Error: $aws_mysqli->error for insert: $queryInsert");
			} else
			{
				echo logEvent("Success: $queryInsert");
				$rowsSuccessCounter = $rowsSuccessCounter + 1; 
			}

		newLine();

	}

	$endTS = microtime(true);
	echo "Ending microtime: $endTS";
	newline();
	$durationTime = round($endTS - $startTS, 2);
	echo logEvent("Duration: $durationTime seconds for $rowsSuccessCounter rows");
	newLine();

	// function logStathat($stathatAccount, $statName, $statValue, $statType, $environment) 
	logStathat2('notifUsersAdded', $rowsSuccessCounter, 'value');
	newLine();
	
	return($durationTime);

}

// close connections
$f_mysqli->close();
$aws_mysqli->close();

?>

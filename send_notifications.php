<?php

// this checks the AWS DB and sends notifications
// this is intended to replace the custom functions_posting.php for distance based notif's only
// new activity/forum-following notifs will continue to come from the phpbb functionality

//todo - show trip map image - like https://maps.googleapis.com/maps/api/staticmap?size=600x400&path=color:0xff0000ff|weight:5|91406|96150&key=KEY_HERE

// define the prefix of each log message
$logType = '[send notif]'; 


$scriptPath = realpath(dirname(__FILE__));

// include forum config file for DB info
include "xport_functions.php";

if (file_exists($scriptPath . '/' . "settings.php")) {
		include ("settings.php");
	} else {
		echo logEvent("Error. Settings file not found where expected: $scriptPath - check for settings.php file");
		exit ("Error. Exiting.");
	}

// config path is absolute
if (file_exists($configPath)) {
		include ($configPath);
	} else {
		echo logEvent("Error. Config file not found where expected: $configPath - check for config.php file");
		exit ("Error. Exiting.");
	}

if (file_exists($scriptPath . '/' . "pnp_db.php")) {
		include "pnp_db.php";
	} else {
		echo logEvent("Error. pnp_db config file not found. Expected in same folder.");
		exit ("Error. Exiting.");
	}


require 'vendor/autoload.php';
include "email_trip_notif_template.php";


echo "Environment: $environment"; 
newline();
echo "Send mail: " ;
echo $sendMailFlag ? 'true' : 'false';
newLine();
echo "Send mail to actual email addresses: " ;
echo $sendMailRecipients ? 'true' : 'false';
newLine();
newLine();

// show IP for now in dev, just so we know when it changes to manage the AWS firewall
// showIP();



// define tables, we could use phpbb's constants.php but unsure how that will work with upgrade
// TODO might want to make these actually constants instead of vars
$tableTopics = 'phpbb_topics'; 
$tableAWSTopics = 'pnp_topics' ; // table in AWS that holds replicated topic data, but only columns we need
$tableNotif = 'pnp_trip_notif_status' ; // table that knows if we sent a notif to a user for a topic yet
$forum_id = '5' ; // we only care about the trip request forum

// email contents constants (say that five times fast)
define("topicUrlPrefix","https://www.pilotsnpaws.org/forum/viewtopic.php?t=");
define("mapUrlPrefix","https://www.pilotsnpaws.org/maps/maps_single_trip.php?topic="); //add topicId to end of this to show map
define("forumUcpUrl","https://www.pilotsnpaws.org/forum/ucp.php?i=164");
define("forumTechUrl","https://www.pilotsnpaws.org/forum/viewforum.php?f=17");

$topicId = getNextTopic();

$queryGetTopicDetails = "select t.topic_id, t.topic_title, t.pnp_sendZip, t.pnp_recZip,
    ROUND((ST_distance_sphere(t.send_location_point, t.rec_location_point) / 1609),0) as trip_dist 
from pnp_topics t
where t.topic_id = $topicId " .
	" and t.source_server = '$f_server' and t.source_database = '$f_database' ;";

$result = $aws_mysqli->query($queryGetTopicDetails);

if(!$result) {
		echo logEvent("Error $aws_mysqli->error to get topic details AWS, exiting. Query: $queryGetTopicDetails");
		exit();
	} else {
		$rowsReturned = $result->num_rows; 
		if($rowsReturned == 0) {
			echo logEvent("Topic details query returned no rows, query: $queryGetTopicDetails");
			exit();
		}
			elseif($rowsReturned == 1) {
				while($row = $result->fetch_assoc()){
					$topicId          = $row['topic_id'];
					$topicTitle       = $row['topic_title'];
					$sendZip          = $row['pnp_sendZip'];
					$recZip           = $row['pnp_recZip'];
					$topicDistance    = $row['trip_dist'];
				}
			}
			else { 
				echo logEvent("Error. Topic details query returned > 1 row. Something is wrong. Query: $queryGetTopicDetails");
				exit(); // dont do anything else in this case. gotta fix. 
		}
	
	if($topicDistance > 1000) {
		echo logEvent("Warning. Topic $topicId is $topicDistance miles. No emails sent for trips over 1000 miles. Exiting.");
		newLine();
		exit();
	}
	
	// log trip distance to stathat
	// function logStathat($stathatAccount, $statName, $statValue, $statType, $environment) 
	logStathat2('tripDistance', $topicDistance, 'value');
	newLine();
		
}

 

// magic is called from here
$topicFromToText = getFromToText($sendZip, $recZip);
$mail = buildEmails($topicId, $topicFromToText);
// end magic


// figure out what topics haven't been sent
function getNextTopic() {
  global $aws_mysqli, $f_server, $f_database, $sendHoursBack;
	$nextTopicQuery = "SELECT min(t.topic_id) as min_topic_id" .
		" FROM pnp_topics t " .
		"		LEFT OUTER JOIN pnp_trip_notif_status n on t.topic_id = n.topic_id " .
		" WHERE t.topic_time_ts > date_add(CURRENT_TIMESTAMP, INTERVAL -$sendHoursBack HOUR) " . // dont remove the - here, we need to go back
		" and t.source_server = '$f_server'  " .
		" and t.source_database = '$f_database' " .
		" and (n.notify_status is null OR n.notify_status = 0) " .
		" HAVING min_topic_id IS NOT NULL; " ;
	
	$nextTopicResults = $aws_mysqli->query($nextTopicQuery) or die ($aws_mysqli->error);
	
	$rowsReturned = $nextTopicResults->num_rows; 
	
	if($rowsReturned == 0) {
			echo logEvent("No results for a new topic in the last $sendHoursBack hours. Exiting.");
			newLine();
			echo ("Query: $nextTopicQuery");
			exit();
		}
		else {
			$row = $nextTopicResults->fetch_assoc();
			$nextTopicId = $row['min_topic_id'];
			echo logEvent("Next topic is: $nextTopicId");
			newLine();
			// $nextTopicId = "41217"; //41343
			return $nextTopicId;
		}
}

function buildEmails($topicId, $topicFromToText) {

	//get start time to see how long this takes for logging
	$startTS = microtime(true);

	global $aws_server, $aws_database, $aws_mysqli, $f_server, $f_database,  $emailHead, $emailBody, 
		$sendMailFlag, $notificationEmailSendGridCategory, $sendMailRecipients;
	
	$emailSentCounter = 0;
	
		// TODO figure out what users get that topic's notif based on their settings
		$queryUsersToNotify = "select DISTINCT t.topic_id, n.notify_status,
				u.user_id, u.user_email, u.username, u.pf_flying_radius, u.apt_id, 
				ROUND((ST_distance_sphere(u.location_point, t.send_location_point) / 1609),0) as send_dist,
				ROUND((ST_distance_sphere(u.location_point, t.rec_location_point) / 1609),0) as rec_dist, t.topic_title, 
				ROUND((ST_distance_sphere(t.send_location_point, t.rec_location_point) / 1609),0) as trip_dist,
				u.location_point, t.send_location_point, t.rec_location_point,
				ST_buffer(u.location_point, pf_flying_radius * 0.01455581689886) as flying_circle,
				topic_linestring,
				ST_Intersects(ST_buffer(u.location_point, pf_flying_radius * 0.01455581689886), topic_linestring) as intersects, t.breed_weight 
		from pnp_topics t 
			JOIN pnp_users u on t.source_server = u.source_server and t.source_database = u.source_database
			LEFT OUTER JOIN pnp_trip_notif_status n on t.topic_id = n.topic_id AND u.user_id = n.user_id
			    AND t.source_server = n.source_server and t.source_database = n.source_database
		where 1=1
			and t.topic_id = $topicId
			and pf_flying_radius > 0 
			and pf_pilot_yn = 1
			and ST_Intersects(ST_buffer(u.location_point, pf_flying_radius * 0.01455581689886), topic_linestring) = 1
			and t.source_server = '$f_server' 
			and t.source_database = '$f_database'
			and (n.notify_status is null OR n.notify_status = 0)
			and ROUND((ST_distance_sphere(t.send_location_point, t.rec_location_point) / 1609),0) BETWEEN 75 AND 1000  /* dont notify on test/suprt short trips, exclude notifs on trips longer than 1000 */
			and user_inactive_reason = 0 /* include active only, exclude deactivated users */
		order by t.topic_id, u.user_id ;" ;

		echo $queryUsersToNotify;
		newLine();

		$result = $aws_mysqli->query($queryUsersToNotify) or die ($aws_mysqli->error);;

			$rowsReturned = $result->num_rows; 
			echo nl2br ("Rows returned: $rowsReturned \n");

			if($rowsReturned == 0) {
				echo logEvent("No results");
				newLine();
			}
				else {  // start iterating thru users, one per row
				while($row = $result->fetch_assoc()){
					$userId						= $row['user_id'];
					$userEmail        = $row['user_email'];
					$userName         = htmlspecialchars_decode($row['username']);
					$userFlyingDistance = $row['pf_flying_radius'];
					$userHomeAirport  = strToUpper($row['apt_id']);
					$userDistSend     = $row['send_dist'];
					$userDistRec      = $row['rec_dist'];
					$topicId          = $row['topic_id'];
					$topicTitle       = $row['topic_title'];
					$topicDistance    = $row['trip_dist'];
					$topicWeight 	  = $row['breed_weight'];

					// build HTML content from template email_trip_notif_template.php
					$emailHTMLContent = "$emailHead $emailBody </html>" ;

					// replace the placeholders with real data
					$emailHTMLContent = str_replace("{notif_userId}", $userId, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_userEmail}", $userEmail, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_userName}", $userName, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_userFlyingDistance}", $userFlyingDistance, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_userHomeAirport}", $userHomeAirport, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_userDistSend}", $userDistSend, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_userDistRec}", $userDistRec, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_topicId}", $topicId, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_topicTitle}", $topicTitle, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_topicFromToText}", $topicFromToText, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_topicDistance}", $topicDistance, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_topicUrlPrefix}", topicUrlPrefix, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_mapUrlPrefix}", mapUrlPrefix, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_forumUcpUrl}", forumUcpUrl, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_forumTechUrl}", forumTechUrl, $emailHTMLContent) ;
					$emailHTMLContent = str_replace("{notif_UserTotalDist}", $userDistSend + $userDistRec + $topicDistance , $emailHTMLContent) ;

					// clean up $topicWeight as it comes from forum and has color/bold tags
					$topicWeight = preg_replace('/\[(.*?)\]/',"",$topicWeight); 

					$emailHTMLContent = str_replace("{notif_topicWeight}", $topicWeight, $emailHTMLContent) ;

					// show email 
					// echo $emailHTMLContent;

					$mail = new SendGrid\Mail\Mail();

					$topicUrlPrefix = topicUrlPrefix;

					// TODO plain text email? do we even need plain text anymore?
					$mail->addContent("text/plain","This message should be viewed in HTML. To view this on the forum, click here ". $topicUrlPrefix . $topicId );

					$mail->addContent("text/html", $emailHTMLContent);

					$mail->setFrom("forum@pilotsnpaws.org", "Pilots N Paws forum");

					// $personalization = new Personalization();
					// send to real emails or test? set in settings.php 
					// this doesnt control if we actually send a message - that is by $sendMailFlag
					if ($sendMailRecipients) {
						$mail->addTo(new SendGrid\Mail\To($userEmail, $userName));
						$mail->setSubject("PNP New Trip: $topicFromToText");
					} else {
						$mail->addTo(new SendGrid\Mail\To("nekbet+$userName@gmail.com", "Mike+$userName"));
						$mail->setSubject("[TEST] PNP New Trip: $topicFromToText");
					}
					// $emailTo->addTo($email);
					// $mail->addTos($emailTo);

					// for debugging - show the results
					// echo json_encode($mail, JSON_PRETTY_PRINT);

					// categories, category comes from settings.php
					// TODO add more data as we can for tracking in emails
					$mail->addCategory($notificationEmailSendGridCategory);
					$tracking_settings = new SendGrid\Mail\TrackingSettings();
					// google analytics
					$ganalytics = new SendGrid\Mail\Ganalytics();
					$ganalytics->setEnable(true);
					$ganalytics->setCampaignSource("trip-notification");
					// // $ganalytics->setCampaignTerm("unused");
					$ganalytics->setCampaignContent("distance-notification"); // TODO this should be set by which notif fires the email, home airport, dist, etc
					// // $ganalytics->setCampaignName("unused");
					$ganalytics->setCampaignMedium("email");
					$tracking_settings->setGanalytics($ganalytics);
					$mail->setTrackingSettings($tracking_settings);

					// flag for dev - false = no email sent
					// this is set in settings.php
					echo "Send mail for real? $sendMailFlag";
					newLine();
						// send the email if we desire
					if($sendMailFlag) {
						$sendResult = sendMail($mail);
						logSend($topicId, $userId, $sendResult, $f_server, $f_database);
						$emailSentCounter++;
						echo logEvent("Email sent for topic $topicId to user $userId");
						newLine();
								}
					else { // if false we mock a 202 response for testing
						$sendResult = '202';
						logSend($topicId, $userId, $sendResult, $f_server, $f_database);
						echo logEvent("Error Email NOT sent for topic $topicId to user $userId");
					}

	
				} // end of iterate thru db
			} // end of else
	
	$endTS = microtime(true);
	$durationTime = round($endTS - $startTS , 2);
	echo logEvent("Users returned: $rowsReturned, Emails sent: $emailSentCounter Duration: $durationTime seconds");
	newLine(); 
	// todo - add check here to make sure it matches
	
	// function logStathat($stathatAccount, $statName, $statValue, $statType, $environment) 
	logStathat2('notifEmailsSent', $emailSentCounter, 'count');
	newLine();
	
} // end buildEmails function

function sendMail($mail) {
	//get start time to see how long this takes for logging
	$startTS = microtime(true);

	global $sgApiKey ; 
	$apiKey = $sgApiKey;
	$sg = new \SendGrid($apiKey);
	$response = $sg->client->mail()->send()->post($mail);
	
	$httpStatusCode = $response->statusCode();
	
	echo "Status code: " . $response->statusCode();
	newline();
	// echo "Headers: " . $response->headers();
	// newline();
	echo "Body: " . $response->body();
	newline();

	$endTS = microtime(true);
	$durationTime = round($endTS - $startTS , 3);
	echo logEvent("Duration: $durationTime seconds for sendMail");
	newLine();
	
	return $httpStatusCode;
}
  
// TODO make sure we log when sent

function logSend($topicId, $userId, $statusCode, $serverName, $databaseName) {
	global $aws_mysqli, $tableNotif;
	
	if($statusCode == '202') {
			$notifyStatus = '1';
		} else {
			$notifyStatus = '2';	
			echo logEvent("Error. SendGrid status code returned ($statusCode) was not 202. We didn't send emails!");
		  logStathat2('notifEmailError', 1, 'count');
			newLine();
		}
	echo ("Sendgrid result for topic $topicId for user $userId was $statusCode");
	newLine();
	// save to db so we don't send to them again
	$logQuery = "INSERT INTO $tableNotif (topic_id, user_id, notify_status, status_code, created_ts, source_server, source_database) " .
					" VALUES ('$topicId', '$userId', $notifyStatus, '$statusCode', CURRENT_TIMESTAMP, '$serverName', '$databaseName'  );";
	$logQueryResult = $aws_mysqli->query($logQuery)  or die ($aws_mysqli->error);
	$rowsInserted = $aws_mysqli->affected_rows; 
	echo nl2br ("Rows inserted: $rowsInserted \n");

	if($rowsInserted == 0) {
		echo logEvent("Error. Logging send failed insert: $logQuery");
		newLine();
		// TODO notify here if we didn't insert anything, would be a problem
	}

}

// close connections
$f_mysqli->close();
$aws_mysqli->close();



?>

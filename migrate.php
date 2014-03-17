<?php
/* 
 *	RubiKS migration script
 */

function insert($table, $assoc) {
	global $new;
	$q = "INSERT INTO " . $table . " (" . join(', ', array_keys($assoc)) . ") VALUES (" . join(', ', array_map(function ($e) { global $new; if ($e === NULL) { return 'NULL'; } return "'" . $new->escape_string($e) . "'"; }, $assoc)) . ")";
	if (!$new->query($q)) {
		var_dump($q);
		die($new->error);
	} else {
		return true;
	}
}



/* Connect */
$old = new mysqli('localhost', 'root', '', 'ddinfo_rubik');
if ($old->connect_error) die('Connection Error (' . $old->connect_errno . ') ' . $old->connect_error);
if (!$old->set_charset("utf8")) die("Error loading character set utf8: " . $old->error);

$new = new mysqli('localhost', 'root', '', 'rubiks');
if ($new->connect_error) die('Connection Error (' . $new->connect_errno . ') ' . $new->connect_error);
if (!$new->set_charset("utf8")) die("Error loading character set utf8: " . $new->error);



/* Users
 *	tekmovalci => users
 *
 * Če se uporabnik hoče loginat, morajo biti izpolnjeni naslednji pogoji (??):
 *		(1) status > 0
 *		(2) level > 0
 * 		(3) banned_date == '0000-00-00' (ampak to je verjetno posledica (2))	
 */
$new->query("TRUNCATE TABLE users");
$users = array(); // club_id => user_id
if ($result = $old->query("SELECT * FROM tekmovalci")) {
	while ($row = $result->fetch_assoc()) {
		$user = array(
			'club_id' => $row['zrksid'],
			'password' => '',
			'name' => $row['ime'],
			'last_name' => $row['priimek'],
			'gender' => $row['spol'] == 'moški' ? 'm' : 'f',
			'nationality' => $row['drzavljanstvo'],
			/* Tu bi naj 'slovensko' zamenjali s 'SI' ? */

			'birth_date' => $row['rd'],
			'city' => $row['kraj'],
			'email' => $row['mail'],
			'notes' => $row['opombe'],
			'status' => '-----',
			/* $row['status'] - kaj natančno to pomeni?
				0 neregistriran, se še ne more prijaviti, 
				1 registriran,
				2 registriran, ima izključeno obveščanje z maili */

			'joined_date' => $row['datum'],
			'level' => '-----',
			/* $row['level'] pravice na portalu:
				5 admin - vse
				4 delegat - tekme
				3 urednik - novice
				2 član - prednost prijav
				1 - tekmovalec
				0 - neregistriran
				-1 - zaklenjen */

			'banned_date' => $row['datumreg'], // Ja, to je pravilno. :-)
			'forum_nickname' => $row['vzdevek'],
			'club_authority' => $row['organ'],
			'membership_year' => $row['lclan']
		);
		//if ($row['vzdevek'] == 'Adut') var_dump($row, $user);
		
		insert('users', $user);

		$user['user_id'] = $new->insert_id;
		$users[$user['club_id']] = $user['user_id'];
	}
} else {
	die("Could not select `tekmovalci`.");
}
unset($result, $row, $user);



/* 
 * Events
 *	discipline => events
 */
$new->query("TRUNCATE TABLE events");
$events = array();
if ($result = $old->query("SELECT * FROM discipline")) {
	while ($row = $result->fetch_assoc()) {
		$event = array(
			'readable_id' => $row['iddiscipline'],
			'short_name' => $row['kratica'],
			'name' => $row['naziv'],
			'attempts' => $row['stmerjenj'],
			'type' => $row['tip'],
			'time_limit' => $row['limit'],
			'description' => $row['opis'],
			'help' => $row['url']
		);
		insert('events', $event);
		$event['event_id'] = $new->insert_id;
		$events[$event['event_id']] = $event;

		//var_dump($events);
	}
} else {
	die('Could not select `discipline`.');
}
unset($result, $row, $event);



/*
 * Competitions
 *	tekme => competitions
 */
function club_id2user_id($cid) {
	global $users;
	return $cid === '' ? NULL : $users[$cid];
}

function formatEvents($events)
{
	// Trenutno je $events npr. '333 FM, 333, 333 BLD', mi pa hočemo to pretvoriti v '333FM 333 333BLD'
	// readable_id dobiš iz short_name tako, da odstraniš presledke. -- Zaenkrat! To je veljalo še vsaj 17. 3. 2014!
	return join(' ', array_map(function($event) { return str_replace(' ', '', $event); }, explode(', ', $events)));
}

$new->query("TRUNCATE TABLE competitions");
$competitions = array();
if ($result = $old->query("SELECT * FROM tekme")) {
	while ($row = $result->fetch_assoc()) {
		$competition = array(
			'short_name' => $row['idtekme'],
			'name' => $row['naziv'],
			'date' => $row['datum'],
			'time' => $row['ura'],
			'max_competitors' => $row['studelezencev'],
			'events' => formatEvents($row['discipline']),
			'city' => $row['kraj'],
			'venue' => $row['prizorisce'],
			// Pretvori <br /> v PHP_EOL ?

			'organiser' => $row['organizator'],
			'delegate1' => club_id2user_id($row['delegat1']),
			'delegate2' => club_id2user_id($row['delegat2']),
			'delegate3' => club_id2user_id($row['rezerva']),
			'algorithms_url' => '', 
			// ???
			//'algorithms_string' => $row['algoritmi'],

			'description' => $row['opis'],
			'registration_fee' => $row['prijavnina'],
			'country' => $row['drzava'],
			'status' => $row['status']
		);
		insert('competitions', $competition);
		$competition['competition_id'] = $new->insert_id;
		$competitions[$competition['competition_id']] = $competition;

		//var_dump($row);
	}
} else {
	die('Could not select `tekme`.');
}
unset($result, $row, $competition);
var_dump($competitions);



/* Close connections */
$old->close();
$new->close();
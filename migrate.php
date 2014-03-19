<?php
/* 
 *	RubiKS migration script
 */

include 'config.php';

function _log($msg)
{
	print('<b>' . $msg . '</b><br>' . PHP_EOL);
}

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
$old = new mysqli($old['host'], $old['user'], $old['pass'], $old['db']);
if ($old->connect_error) die('Connection Error (' . $old->connect_errno . ') ' . $old->connect_error);
if (!$old->set_charset("utf8")) die("Error loading character set utf8: " . $old->error);

$new = new mysqli($new['host'], $new['user'], $new['pass'], $new['db']);
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
_log('Users...');
$new->query("TRUNCATE TABLE users");
$users = array(); // club_id => id
if ($result = $old->query("SELECT * FROM tekmovalci")) {
	while ($row = $result->fetch_assoc()) {
		$user = array(
			'club_id' => $row['zrksid'],
			'password' => '',
			'name' => $row['ime'],
			'last_name' => $row['priimek'],
			'gender' => $row['spol'] == 'moški' ? 'm' : 'f',
			'nationality' => substr($row['zrksid'], 0, 2), // $row['drzavljanstvo']
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

		$user['id'] = $new->insert_id;
		$users[$user['club_id']] = $user['id'];
	}
} else {
	die("Could not select `tekmovalci`.");
}
unset($result, $row, $user);



/* 
 * Events
 *	discipline => events
 */
_log('Events...');
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
			// Obstajati bi moral še en stolpec, ki bi povedal, ali je disciplina single_only, ali pa ima tudi average.

			'time_limit' => $row['limit'],
			'description' => $row['opis'],
			'help' => $row['url']
		);
		insert('events', $event);
		$event['id'] = $new->insert_id;
		$events[$event['readable_id']] = $event;

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

_log('Competitions...');
$new->query("TRUNCATE TABLE competitions");
$competitions = array();
$competitionsShortName2Id = array();
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
			'algorithms_url' => '', // This column should probably be removed!

			'description' => $row['opis'],
			'registration_fee' => $row['prijavnina'],
			'country' => $row['drzava'],
			'status' => $row['status']
			/*	-1 zaklenjeno, vse obdelano, iz vseh vidikov zaključena tekma, vrže link do algoritmov
				0 prijave končane, oziroma končana tekma
				1 prijave odprte
				2 vnesena nova tekma, a še neodprte prijave */
		);
		insert('competitions', $competition);
		$competition['id'] = $new->insert_id;
		$competitions[$competition['id']] = $competition;
		$competitionsShortName2Id[$competition['short_name']] = $competition['id'];

		//var_dump($row);
	}
} else {
	die('Could not select `tekme`.');
}
unset($result, $row, $competition);



/*
 * Registrations
 *	prijave => registrations
 */
_log('Registrations...');
$new->query("TRUNCATE TABLE registrations");
if ($result = $old->query("SELECT * FROM prijave")) {
	while ($row = $result->fetch_assoc()) {
		$registration = array(
			'competition_id' => $competitionsShortName2Id[$row['tekmaid']],
			'user_id' => $users[$row['zrksid']],
			'events' => formatEvents($row['disc']),
			'status' => $row['status'],
			'notes' => $row['gledalci'],
			'created_at' => $row['datumprijave'] . ' 00:00:00'
		);
		insert('registrations', $registration);
	}
} else {
	die('Could not select `prijave`.');
}
unset($result, $row, $registration);



/*
 * Results
 *	casi => results
 */
_log('Results...');
$new->query("TRUNCATE TABLE results");
if ($result = $old->query("SELECT * FROM casi")) {
	while ($row = $result->fetch_assoc()) {
		$r = array(
			'competition_id' => $competitionsShortName2Id[$row['idtekme']],
			'event_id' => $events[$row['disc']]['id'],
			'round' => '0',
			'user_id' => $users[$row['zrksid']],
			'single' => $row['best'],
			'average' => $row['avg'],
			'results' => $row['vsiavg'],
			'single_nr' => $row['nr'] === 'NR' ? '1' : '0',
			'single_pb' => $row['pr'] === 'PB' ? '1' : '0',
			'average_nr' => $row['nravg'] === 'NR' ? '1' : '0',
			'average_pb' => $row['pravg'] === 'PB' ? '1' : '0',
			'medal' => $row['pozicija'],
			'date' => $row['datum'],
			'championship_rank' => $row['rubiks']
		);

		if ($row['disc'] === '33310MIN') $r['average'] = $row['stmeritev'];
		if ($row['disc'] === '333FM') $r['average'] = $row['stmeritev'];
		insert('results', $r);

		//var_dump($row, $r);
	}
} else {
	die('Could not select `casi`.');
}
unset($result, $row, $r);



/*
 * Delegates
 *	delegat => delegates
 */
function delegateDegree($d)
{
	if ($d[strlen($d)-1] == '*') return 'K';
	return $d[0];
}

_log('Delegates...');
$new->query("TRUNCATE TABLE delegates");
if ($result = $old->query("SELECT * FROM delegat")) {
	while ($row = $result->fetch_assoc()) {
		$delegate = array(
			'user_id' => $users[$row['zrksid']],
			'degree' => delegateDegree($row['status']),
			'contact' => $row['kontakt'],
			'region' => $row['regija'],
			'activity' => '1'
		);
		insert('delegates', $delegate);
	}
} else {
	die('Could not select `delegat`.');
}
unset($result, $row, $delegate);



/*
 * News
 *	novice => news
 */
function createUrlSlug($str)
{
	$str = strtolower($str);
	$str = str_replace(['š', 'Š', 'č', 'Č', 'ć', 'Ć', 'ž', 'Ž'], ['s', 'S', 'c', 'c', 'c', 'c', 'z', 'z' ], $str);
	$str = preg_replace('/[^a-z0-9-]+/', '-', $str);
	return $str;
}

_log('News...');
$new->query("TRUNCATE TABLE news");
if ($result = $old->query("SELECT * FROM novice")) {
	while ($row = $result->fetch_assoc()) {
		$article = array(
			'title' => $row['naslov'],
			'text' => $row['novica'], // Popravi vse linke, ki vsebujejo 'rubik.si/klub/index.php'
			'user_id' => 1, // POPRAVI TO!
			'created_at' => $row['datum'],
			'url_slug' => createUrlSlug($row['naslov']),
			'visible' => '1'
		);
		insert('news', $article);
	}
} else {
	die('Could not select `novice`.');
}
unset($result, $row, $article);



/*
 * Save algorithms from `tekme` table
 */
_log("Algorithms...");
$competitions = array();
$competitionsShortName2Id = array();
if (!mkdir('algorithms')) die('Could not create new directory.');
if ($result = $old->query("SELECT * FROM tekme")) {
	while ($row = $result->fetch_assoc()) {
		if (!mkdir('algorithms/' . $row['idtekme'])) die('Could not create new directory.');
		
		if ($row['algoritmi'] !== '') {

			if (!$handle = fopen('algorithms/' . $row['idtekme'] . '/scrambles.html', 'w+')) {
				die('Could not open/create file.');
			}

			if (fwrite($handle, nl2br($row['algoritmi'])) === FALSE) {
				die('Cannot write to file.');
			}

			fclose($handle);

			//var_dump($row['algoritmi']);
		}
	}
} else {
	die('Could not select `tekme` (II).');
}
unset($result, $row);



/* Done */
_log('Done!');



/* Close connections */
$old->close();
$new->close();
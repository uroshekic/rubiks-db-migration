<?php
/* 
 *	RubiKS migration script
 */

include 'config.php';

function _log($msg)
{
	print('<b>' . $msg . '</b><br>' . PHP_EOL);
}

function insert($table, $assoc)
{
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
			'membership_year' => $row['lclan'],
			'confirmed' => 1,
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
			'show_average' => in_array($row['iddiscipline'], array('33310MIN', '333FM', '333BLD', '2345')) ? '0' : '1',
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
function club_id2user_id($cid)
{
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
			'description' => $row['opis'],
			'registration_fee' => $row['prijavnina'],
			'country' => $row['drzava'],
			'status' => (int) substr($row['datum'], 0, 4) < 2014 ? '-1' : $row['status']
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
			'confirmed' => $row['status'],
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
function format33310min($stKock, $cas)
{
	$a = (string) (400 - $stKock);
	$b = (string) $cas;
	if (strlen($b) < 5) $b = str_pad($b, 5, '0');
	return ($a . $b);
}

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

		if ($row['disc'] === '333FM') {
			$r['single'] = $row['stmeritev']; // St. potez
		} elseif ($row['disc'] === '33310MIN') {
			//$r['single'] = $row['stmeritev']; // St. resenih kock
			//$r['average'] = $row['best']; // Cas

			/* FORMAT BI MORALI POPRAVITI TAKO, DA BI VELJALO MANJ JE BOLJŠE!
			 * Čas je dolžine največ 5 znakov (60000 = 10 * 60 * 100)
			 * DNF je v bazi predstavljen z nizom, dolgim 8 znakov: 77777777 (DNS in DSQ sta še večja od DNF)
			 * Ideja:
			 * 	Število kock, ki jih tekmovalec lahko reši je navzgor omejeno z 200.
			 *		To bi pomenilo 20 kock/minuto ~ 3s za eno kocko - vključno s pobiranjem in predogledom.
			 * 	I) Če bi v bazo zapisali 200 - N in recimo, da nekdo reši 101 kocko v 59 999s.
			 * 		Dobimo 9 959 999. Nekdo pa reši 1 kocko v 6000s, dobimo 1 996 000 - zadnji rezultat je manjši
			 *		od prvega, čeprav ne bi smel biti.
			 *		Tudi če se dogovorimo, da čas 6000s zapišemo kot 06000 (fiksna dolžina 5 znakov),
			 *		19 906 000 - spet težave.
			 *	II) Če tekmovalec reši N kock, zapišemo v bazo 400 - N. 
			 *		Če je N med 0 in 200, bo 400 - N med 200 in 400 => 400 - N bo vedno tromestno število!
			 *		Čas pa vedno zapišemo kot šestmestno število (na levo stran po potrebi dodamo ničle)!
			 *	To nam omogoča, da najslabši (tekmovalec reši 0 kock v 10 minutah) rezultat
			 * 	zapišemo kot '400' . '60000' (400 - 0 = 200)
			 *		
			 *	Uporabimo II) način!
			 */

			$r['single'] = format33310min($row['stmeritev'], $row['best']);
		}
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
	//$str = str_replace(['š', 'č', 'ć', 'ž' ], ['s', 'c', 'c', 'z' ], $str); // Doesn't work!
	$str = preg_replace('/[^a-z0-9-]+/', '-', $str);
	return $str;
}

function fixArticle($article)
{
	return $article; // Testing

	$article = preg_replace_callback(
		'|href=["\'](http\://www.rubik.si/klub/index.php.*?)["\']|', 
		function ($matches)
		{
			return 'href="' . newUrls($matches[1]) . '"';
		},
		$article);
	return $article;
}

function newUrls($url)
{
	$u = 'http://www.rubik.si/klub/index.php';
	$matches = array();

	// Competitions
	if (preg_match('/page=competitions(&amp;|&)id=([a-z0-9]+)/i', $url, $matches)) {
		return $u . '/competitions/' . $matches[2];
	}

	// Competitors
	if (preg_match('/page=persons(&amp;|&)id=([a-z0-9]+)/i', $url, $matches)) {
		return $u . '/competitors/' . $matches[2];
	}

	// Misc
	if (preg_match('/page=(events|league|wca|startnina|clanstvo|prvenstvo|prijava|obvestila|persons)/i', $url, $matches)) {
		$matches[1] = str_replace(
			['obvestila', 'persons'], 
			['news', 'competitors'], 
			$matches[1]
		);
		//return $u . '/' . $matches[1] . '/';
	}

	return $u;
}

_log('News...');
$new->query("TRUNCATE TABLE news");
if ($result = $old->query("SELECT * FROM novice")) {
	while ($row = $result->fetch_assoc()) {
		$article = array(
			'title' => $row['naslov'],
			'text' => fixArticle($row['novica']),
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
 * Credits
 */
_log("Credits");
$new->query("TRUNCATE TABLE credits");
$contents = file_get_contents("http://www.rubik.si/klub/index.php?page=zahvale");
$matches = array();
preg_match_all("|<td width='50%' .*?>(.*?)</td>|is", $contents, $matches);
//var_dump($matches);
foreach ($matches[1] as $match) {
	$data = array();
	preg_match_all("|<b>(.*?)</b><br>(.*?)<br>\s*<a .*?>(.*?)</a>|is", $match, $data);
	if (count($data[0]) == 0) continue;
	$data = array(
		'organization' => $data[1][0],
		'address' => $data[2][0],
		'url' => $data[3][0],
		'visible' => '1'
	);
	insert('credits', $data);
	//var_dump($data);
}
unset($contents, $matches, $match, $data);



/*
 * Save algorithms from `tekme` table
 */
_log("Algorithms...");
if (!is_dir('algorithms') && !mkdir('algorithms')) die('Could not create new directory.');
if ($result = $old->query("SELECT * FROM tekme")) {
	while ($row = $result->fetch_assoc()) {
		$dir = 'algorithms/' . $row['idtekme'];
		if (!is_dir($dir) && !mkdir($dir)) die('Could not create new directory.');
		
		if ($row['algoritmi'] !== '') {

			if (!$handle = fopen($dir . '/scrambles.html', 'w+')) {
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
unset($result, $row, $result, $dir, $row, $handle);



/* Done */
_log('Done!');



/* Close connections */
$old->close();
$new->close();
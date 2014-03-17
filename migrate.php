<?php
/* 
 *	RubiKS migration script
 */

function insert($table, $assoc) {
	global $new;
	$q = "INSERT INTO " . $table . " (" . join(', ', array_keys($assoc)) . ") VALUES (" . join(', ', array_map(function ($e) { global $new; return "'" . $new->escape_string($e) . "'"; }, $assoc)) . ")";
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



/* Close connections */
$old->close();
$new->close();
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *
 *
 * @package    local
 * @subpackage paperattendance
 * @copyright  2016 Jorge Cabané (jcabane@alumnos.uai.cl)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once $CFG->dirroot . '/local/paperattendance/locallib.php';
require_once $CFG->libdir . '/clilib.php';

global $DB, $CFG;

// Now get cli options
list($options, $unrecognized) = cli_get_params(
    array('help' => false),
    array('h' => 'help')
);
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}
// Text to the paperattendance console
if ($options['help']) {
    $help =
    // Todo: localize - to be translated later when everything is finished
    "Sync attendances from webcursos to omega.
	Options:
	-h, --help            Print out this help
	Example:
	\$sudo /usr/bin/php /local/paperattendance/cli/omegasync.php";
    echo $help;
    die();
}
//heading
cli_heading('Paper Attendance syncing to omega'); // TODO: localize
echo "\nSearching for unsynced attendances...\n";
echo "\nStarting at " . date("F j, Y, G:i:s") . "\n";

$initialtime = time();
$processedfirst = 0;
$foundfirst = 0;

// Sql that brings the unsynced sessions (with omega)
$sqlunsynced =
    "SELECT sess.id AS id, sess.courseid AS courseid
	FROM {paperattendance_session} AS sess
	WHERE sess.status = ?
	ORDER BY sess.lastmodified ASC";

// Parameters for the previous query
$params = array(PAPERATTENDANCE_STATUS_PROCESSED);

// sync students with synctask function
if ($resources = $DB->get_records_sql($sqlunsynced, $params)) {
    $path = $CFG->dataroot . "/temp/local/paperattendance/unread";
    foreach ($resources as $session) {
        //found an other one
        $foundfirst++;
        if ($process = paperattendance_synctask($session->courseid, $session->id)) {
            //processed an other one
            $processedfirst++;
            $session->status = 2;
            $DB->update_record("paperattendance_session", $session);
        }
    }
}

$processedsecond = 0;
$foundsecond = 0;
//SECOND PART
// Sql that brings the unsychronized attendances
$sqlunsicronizedpresences =
    "SELECT p.id,
	s.id AS sessionid,
	u.username,
	s.courseid,
	p.status
	FROM {paperattendance_session} s
	INNER JOIN {paperattendance_presence} p ON (p.sessionid = s.id)
	INNER JOIN {user} u ON (u.id = p.userid)
	WHERE p.omegasync = ?";
	
$unsyncrhonizedpresences = $DB->get_records_sql($sqlunsicronizedpresences, array(0));

foreach ($unsyncrhonizedpresences as $presence) {
    $foundsecond++;

    $arrayalumnos = array();
    $line = array();
    $line["emailAlumno"] = $presence->username;
    $line['resultado'] = "true";
    if ($presence->status) {
        $line['asistencia'] = "true";
    } else {
        $line['asistencia'] = "false";
    }

    $arrayalumnos[] = $line;
    if (paperattendance_omegacreateattendance($presence->courseid, $arrayalumnos, $presence->sessionid)) {
        $processedsecond++;
    }
}

echo $foundfirst . " Att found first part. \n";
echo $processedfirst . " Processed first part. \n";
echo $foundsecond . " Att found second part. \n";
echo $processedsecond . " Processed second part. \n";

// Displays the time required to complete the process
$finaltime = time();
$executiontime = $finaltime - $initialtime;

echo "Execution time: " . $executiontime . " seconds. \n";

exit(0);

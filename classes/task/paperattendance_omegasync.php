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
 * @copyright  2016 Hans Jeria <hansjeria@gmail.com>
 * @copyright  2016 Jorge Cabané (jcabane@alumnos.uai.cl)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paperattendance\task;

class paperattendance_omegasync extends \core\task\scheduled_task
{

    public function get_name()
    {
        return get_string('taskomegasync', 'local_paperattendance');
    }

    public function execute()
    {
        global $CFG, $DB;

        require_once $CFG->dirroot . '/local/paperattendance/locallib.php';

        // Sql that brings the unsynced sessions (with omega)
        $sqlunsynced = "SELECT sess.id AS id, sess.courseid AS courseid
	 				FROM {paperattendance_session} AS sess
					WHERE sess.status = ?
					ORDER BY sess.lastmodified ASC";
        // Parameters for the previous query
        $params = array(PAPERATTENDANCE_STATUS_PROCESSED);

        // syn students with synctask function
        if ($resources = $DB->get_records_sql($sqlunsynced, $params)) {
            $path = $CFG->dataroot . "/temp/local/paperattendance/unread";
            foreach ($resources as $session) {
                $initialtime = time();

                $session->status = 1; // -> processed but not synced
                if ($process = paperattendance_synctask($session->courseid, $session->id)) {
                    $session->status = 2; // -> processed and synced
                    $DB->update_record("paperattendance_session", $session);
                }
                $finaltime = time();
                $executiontime = $finaltime - $initialtime;

                $result = "sessionid: " . $session->id . "--syncedstatus: " . $session->status;

                paperattendance_cronlog('omegasync:bycourse', $result, time(), $executiontime);
            }
        }

        // Sql that brings the unsychronized attendances
        $sqlunsicronizedpresences =
            "SELECT u.username,
			s.id AS sessionid,
			s.courseid,
			p.status
			FROM {paperattendance_session} s
			INNER JOIN {paperattendance_presence} p ON (p.sessionid = s.id)
			INNER JOIN {user} u ON (u.id = p.userid)
			WHERE p.omegasync <> ?";
			
        $unsyncrhonizedpresences = $DB->get_records_sql($sqlunsicronizedpresences, array(1));

        foreach ($unsyncrhonizedpresences as $presence) {
            $initialtime = time();
            $result = "false";

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

            if (paperattendance_omegacreateattendance($presence->courseid, $arrayalumnos, $presence->sessionid, false)) {
                $result = "true";
            }

            $finaltime = time();
            $executiontime = $finaltime - $initialtime;

            $result = "username: " . $presence->username . "--sessionid: " . $presence->sessionid . "-- resultsynced: " . $result;

            paperattendance_cronlog('omegasync:bystudent', $result, time(), $executiontime);
        }
    }
}

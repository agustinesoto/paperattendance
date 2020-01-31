<?php
namespace local_paperattendance\task;

class omegasync extends \core\task\scheduled_task
{
    public function get_name()
    {
        return get_string('taskomegasync', 'local_paperattendance');
    }

    public function execute()
    {
        global $DB, $CFG;
        require_once "$CFG->dirroot/local/paperattendance/locallib.php";
        echo "\n== Omega Sync ==\n";

        $omegaTime = time();

        //FIRST PART
        //Syncs processed sessions

        echo "= Sync sessions =\n";

        $foundfirst = 0;
        $processedfirst = 0;

        $sqlunsynced =
            "SELECT sess.id AS id, sess.courseid AS courseid
		FROM {paperattendance_session} AS sess
		WHERE sess.status = ?
		ORDER BY sess.lastmodified ASC";

        //Parameters for the previous query
        $params = array(PAPERATTENDANCE_STATUS_PROCESSED);

        //sync students with synctask function
        if ($resources = $DB->get_records_sql($sqlunsynced, $params)) {
            foreach ($resources as $session) {
                //found an other one
                $foundfirst++;

                // Sql that brings the unsynced students
                $sqlstudents =
                    "SELECT p.id, p.userid AS userid, p.status AS status, s.username AS username
	 		        FROM {paperattendance_presence} AS p
			        INNER JOIN {user} AS s on ( p.userid = s.id AND p.sessionid = ? )";

                if ($resources = $DB->get_records_sql($sqlstudents, array($session->id))) {
                    $arrayalumnos = array();

                    foreach ($resources as $student) {

                        $line = array();
                        $line['emailAlumno'] = $student->username;
                        $line['resultado'] = "true";

                        if ($student->status == 1) {
                            $line['asistencia'] = "true";
                        } else {
                            $line['asistencia'] = "false";
                        }

                        $arrayalumnos[] = $line;
                    }

                    if (\paperattendance_omegacreateattendance($session->courseid, $arrayalumnos, $session->id)) {
                        $processedfirst++;
                        $session->status = PAPERATTENDANCE_STATUS_SYNC;
                        $DB->update_record("paperattendance_session", $session);
                        echo "Synced session: $session->id\n";
                    } else {
                        echo "Failed to sync session: $session->id\n";
                        //should we continue to attempt to sync the session?
                    }
                } else {
                    //this error means that there are no students in the session
                    //I'm not sure how it could happen
                    //if the teacher inserts a student later it will be synced manually by the second part, so there is no problem in just never trying again to sync this session
                    echo "ERROR: The session $session->id doesnt exists in the presence table\n";

                    //dont try to sync again, it wont work.
                    $session->status = PAPERATTENDANCE_STATUS_SYNC;
                    $DB->update_record("paperattendance_session", $session);
                }
            }
        }

        //SECOND PART
        //Syncs unsynced presences (omegasync 0)
        //As far as I can tell this is mostly for inserted students, since their courses have already been synced and wont be synced again.

        echo "\n= Sync presences =\n";

        $foundsecond = 0;
        $processedsecond = 0;

        //Sql that brings the unsychronized attendances
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

        $unsynchronizedpresences = $DB->get_records_sql($sqlunsicronizedpresences, array(0));

        foreach ($unsynchronizedpresences as $presence) {
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
            if (\paperattendance_omegacreateattendance($presence->courseid, $arrayalumnos, $presence->sessionid)) {
                $processedsecond++;
                echo "Synced presence: $presence->username\n";
            } else {
                echo "Failed to sync presence $presence->username\n";
            }
        }

        echo "\n$foundfirst Att found first part\n";
        echo "$processedfirst Processed first part\n";
        echo "$foundsecond Att found second part\n";
        echo "$processedsecond Processed second part\n";

        $finalTime = time() - $omegaTime;
        echo "\nSynchronized with Omega in $finalTime seconds\n";
    }
}

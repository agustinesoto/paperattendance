<?php
namespace local_paperattendance\task;

class presence extends \core\task\scheduled_task
{
    public function get_name()
    {
        return get_string('taskpresence', 'local_paperattendance');
    }

    public function execute()
    {
        global $CFG, $DB;
        require_once "$CFG->dirroot/local/paperattendance/locallib.php";

        echo ("\n== Searching for insert students ==\n");

        $insertTime = time();

        //select the lastest verified id
        $sqllastverified =
            "SELECT MAX(id) AS id, result
    	    FROM {paperattendance_cronlog}
    	    WHERE task = ?";

        if ($resultverified = $DB->get_record_sql($sqllastverified, array("presence"))) {
            //if this task has already run at least once
            $lastsessionid = $resultverified->result;
        } else {
            //just check all sessions
            $lastsessionid = 0;
        }

        $sqlsessions =
            "SELECT id,
    	    courseid
    	    FROM {paperattendance_session}
    	    WHERE id > ?";

        //select all unverified sessions
        if ($sessionstoverify = $DB->get_records_sql($sqlsessions, array($lastsessionid))) {
            //if there is at least one session, check if there is a student enrolled but not on the list
            foreach ($sessionstoverify as $session) {
                $sessionid = $session->id;
                $courseid = $session->courseid;

                $enrolincludes = explode(",", $CFG->paperattendance_enrolmethod);
                list($enrolmethod, $paramenrol) = $DB->get_in_or_equal($enrolincludes);
                $parameters = array_merge(array($courseid), $paramenrol, array($sessionid));

                $querystudentsnotinlist =
                    "SELECT u.id
                    FROM {user_enrolments} ue
                    INNER JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = ?)
                    INNER JOIN {context} c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
                    INNER JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.roleid = 5 AND ra.userid = ue.userid)
                    INNER JOIN {user} u ON (ue.userid = u.id)
                    WHERE e.enrol $enrolmethod AND u.id NOT IN (SELECT userid FROM  {paperattendance_presence} WHERE sessionid = ?)
                    GROUP BY u.id
                    ORDER BY lastname ASC";

                //If we find students enrolled but not on the list we add him as not present
                if ($studentsnotinlist = $DB->get_records_sql($querystudentsnotinlist, $parameters)) {
                    foreach ($studentsnotinlist as $student) {
                        \paperattendance_save_student_presence($sessionid, $student->id, '0');
                    }
                    \paperattendance_cronlog("presence", $session->id, time());
                }
            }
            $finalTime = time() - $insertTime;
            echo "\nVerified sessions for insert students in $finalTime seconds\n";
        }
    }
}

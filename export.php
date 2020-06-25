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
 * @copyright  2016 Cristobal Silva (cristobal.isilvap@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
//Belongs to plugin PaperAttendance

require_once dirname(dirname(dirname(__FILE__))) . "/config.php";
require_once "$CFG->dirroot/local/paperattendance/locallib.php";
require_once "$CFG->libdir/excellib.class.php";
require_once "$CFG->dirroot/local/paperattendance/forms/export_form.php";

global $DB, $PAGE, $OUTPUT, $USER, $CFG;

// Possible actions -> view, scan or students attendance . Standard is view mode
$action = optional_param("action", "viewform", PARAM_TEXT);
$courseid = required_param('courseid', PARAM_INT);

$context = context_course::instance($COURSE->id);
$url = new moodle_url("/local/paperattendance/export.php", array('courseid' => $courseid));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout("standard");

$contextsystem = context_system::instance();

//for navbar
$course = $DB->get_record("course", array("id" => $courseid));
$categorycontext = context_coursecat::instance($course->category);
$nodata = false;

require_login();
$isteacher = paperattendance_getteacherfromcourse($courseid, $USER->id);

if (isguestuser() || (!$isteacher && !is_siteadmin($USER) && !has_capability('local/paperattendance:printsecre', $categorycontext))) {
    die();
}

//breadcrumb for navigation
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(get_string('courses', 'local_paperattendance'), new moodle_url('/course/index.php'));
$PAGE->navbar->add($course->shortname, new moodle_url('/course/view.php', array("id" => $courseid)));
$PAGE->navbar->add(get_string('pluginname', 'local_paperattendance'));
$PAGE->navbar->add(get_string('exporttitle', 'local_paperattendance'), new moodle_url("/local/paperattendance/export.php", array("courseid" => $courseid)));

$exportform = new paperattendance_export_form(null, array("courseid" => $courseid));
if ($formdata = $exportform->get_data()) {
    //form data analysis
    $types = array();
    foreach ($formdata->sesstype as $sesstype => $type) {
        $types[] = $sesstype;
    }
    list($selectedtypes, $paramsesstypes) = $DB->get_in_or_equal($types);
    $parametros = array_merge($paramsesstypes, array($courseid));
    //excel parameters
    $filename = $course->fullname . "_attendances_" . date('dmYHi');
    $tabs = array("Attendances", "Summary");
    $title = $course->fullname;
    $header = array(array("LastName", "FirstName", "Email"), array("LastName", "FirstName", "Email"));
    $header[1] = array_merge($header[1], array("Total percentage"));
    $data = array(array(), array());
    $descriptions = array(array(), array());
    $dates = array(array(), array());
    //Select all students from the last list
    $enrolincludes = explode(",", $CFG->paperattendance_enrolmethod);
    list($enrolmethod, $paramenrol) = $DB->get_in_or_equal($enrolincludes);
    $parameters = array_merge(array($course->id), $paramenrol);
    $querystudent =
        "SELECT u.id,
		u.email,
		u.firstname,
		u.lastname
		FROM {user_enrolments} ue
		INNER JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = ?)
		INNER JOIN {context} c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
		INNER JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.roleid = $CFG->paperattendance_studentrole AND ra.userid = ue.userid)
		INNER JOIN {user} u ON (ue.userid = u.id)
		WHERE e.enrol $enrolmethod
		GROUP BY u.id
		ORDER BY lastname, firstname, id ASC";

    $studentlist = $DB->get_records_sql($querystudent, $parameters);
    $list = new stdClass();
    $list->lastnames = array();
    $list->firstnames = array();
    $list->emails = array();
    $list->studentsid = array();
    foreach ($studentlist as $student) {
        $list->studentsid[] = $student->id;
        $list->lastnames[] = $student->lastname;
        $list->firstnames[] = $student->firstname;
        $list->emails[] = $student->email;
    }
    foreach ($tabs as $i => $tab) {
        $data[$i][] = $list->lastnames;
        $data[$i][] = $list->firstnames;
        $data[$i][] = $list->emails;
    }
    //Select course sessions
    $parametros = array_merge($parametros, array($formdata->initdate, $formdata->enddate));
    $getsessions =
        "SELECT s.id,
		sm.date,
		CONCAT( m.initialtime, '-', m.endtime) AS hour,
		s.description AS description
		FROM {paperattendance_session} AS s
		INNER JOIN {paperattendance_sessmodule} AS sm ON (s.id = sm.sessionid)
		INNER JOIN {paperattendance_module} AS m ON (sm.moduleid = m.id)
		WHERE s.description $selectedtypes AND s.courseid = ? AND sm.date BETWEEN ? AND ?
		ORDER BY sm.date ASC";

    $sessions = $DB->get_records_sql($getsessions, $parametros);
    //sql in for presences of studdents for each session
    list($studentids, $paramstudentsid) = $DB->get_in_or_equal($list->studentsid);
    foreach ($sessions as $session) {
        $params = array_merge(array($session->id), $paramstudentsid);
        $descriptions[0][] = paperattendance_returnattendancedescription(false, $session->description);
        $dates[0][] = date('d-m-Y', $session->date);
        $header[0][] = $session->hour;
        //get session attendances
        $getpresences =
            "SELECT  u.id,
			IFNULL(p.status,0) AS status
			FROM {paperattendance_presence} AS p
			RIGHT JOIN {user} AS u ON (u.id = p.userid AND p.sessionid = ?)
			WHERE u.id $studentids
			ORDER BY u.lastname, u.firstname, u.id ASC";

        $presences = $DB->get_records_sql($getpresences, $params);
        $sess = array();
        foreach ($presences as $presence) {
            $sess[] = $presence->status;
        }
        $data[0][] = $sess;
    }
    list($statusprocessed, $paramstatus) = $DB->get_in_or_equal(array(1, 2));
    $totalpercentage = array();
    foreach ($list->studentsid as $studentid) {
        $paramscountpercentage = array_merge(array($course->id), $paramstatus, array($course->id), $paramstatus, array($studentid));
        $sqlpercentage =
            "SELECT ROUND((COUNT(*)/
			(SELECT COUNT(*)
			FROM {paperattendance_session} s
			WHERE s.courseid = ? AND s.status $statusprocessed))*100,0) AS percentage
			FROM {paperattendance_session} s
			INNER JOIN {paperattendance_presence} p ON (s.id = p.sessionid AND s.courseid =? AND s.status $statusprocessed AND p.userid= ? AND p.status=1)";

        $totalpercentage[] = ($DB->get_record_sql($sqlpercentage, $paramscountpercentage)->percentage) . "%";
    }
    $data[1][] = $totalpercentage;
    if (count($sessions) == 0) {
        $nodata = true;
    } else {
        $nodata = false;

        //export Excel
        $workbook = new MoodleExcelWorkbook("-");
        $workbook->send($filename);
        foreach ($tabs as $index => $tab) {
            $attxls = $workbook->add_worksheet($tab);
            $i = 0; //y axis
            $j = 0; //x axis
            $titleformat = $workbook->add_format();
            $titleformat->set_bold(1);
            $titleformat->set_size(12);
            $attxls->write($i, $j, $title, $titleformat);
            $i = 1;
            $j = 3;
            $headerformat = $workbook->add_format();
            $headerformat->set_bold(1);
            $headerformat->set_size(10);
            foreach ($descriptions[$index] as $descr) {
                $attxls->write($i, $j, $descr, $headerformat);
                $j++;
            }
            $i = 2;
            $j = 3;
            foreach ($dates[$index] as $date) {
                $attxls->write($i, $j, $date, $headerformat);
                $j++;
            }
            $i = 3;
            $j = 0;
            foreach ($header[$index] as $cell) {
                $attxls->write($i, $j, $cell, $headerformat);
                $j++;
            }
            $i = 4;
            $j = 0;
            foreach ($data[$index] as $row) {
                foreach ($row as $cell) {
                    $attxls->write($i, $j, $cell);
                    $i++;
                }
                $j++;
                $i = 4;
            }
        }

        //apparently this function is the one that actually sends the file to download
        $workbook->close();
        exit();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->tabtree(paperattendance_history_tabs($course->id), "export");
if ($nodata) {
    echo html_writer::div(get_string("nodatatoexport", "local_paperattendance"), "alert alert-danger", array("role" => "alert"));
}
$exportform->display();
echo $OUTPUT->footer();

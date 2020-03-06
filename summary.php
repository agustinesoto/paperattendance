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

require_once (dirname(dirname(dirname(__FILE__)))."/config.php");
require_once($CFG->dirroot . '/local/paperattendance/locallib.php');

global $DB, $PAGE, $OUTPUT, $USER, $CFG;

// Possible actions -> view, scan or students attendance . Standard is view mode
$action = optional_param("action", "view", PARAM_TEXT);
$courseid = required_param('courseid', PARAM_INT);

$context = context_course::instance($COURSE->id);
$url = new moodle_url("/local/paperattendance/summary.php", array('courseid' => $courseid));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout("standard");

$contextsystem = context_system::instance();

//Page
$page = optional_param('page', 0, PARAM_INT);
$perpage = 26;
//for navbar
$course = $DB->get_record("course",array("id" => $courseid));
$categorycontext = context_coursecat::instance($course->category);

require_login();
if (isguestuser()){
	die();
}

//Begins Teacher's View
$isteacher = paperattendance_getteacherfromcourse($courseid, $USER->id);

$isstudent = paperattendance_getstudentfromcourse($courseid, $USER->id);

if( $isteacher || is_siteadmin($USER) || has_capability('local/paperattendance:printsecre', $categorycontext)) {
	//breadcrumb for navigation
	$PAGE->navbar->ignore_active();
	$PAGE->navbar->add(get_string('courses', 'local_paperattendance'), new moodle_url('/course/index.php'));
	$PAGE->navbar->add($course->shortname, new moodle_url('/course/view.php', array("id" => $courseid)));
	$PAGE->navbar->add(get_string('pluginname', 'local_paperattendance'));
	$PAGE->navbar->add(get_string('summarytitle', 'local_paperattendance'), new moodle_url("/local/paperattendance/summary.php", array("courseid" => $courseid)));
	
	$enrolincludes = explode("," ,$CFG->paperattendance_enrolmethod);
	list($enrolmethod, $paramenrol) = $DB->get_in_or_equal($enrolincludes);
	$parameters = array_merge(array($course->id), $paramenrol);
	$querystudent = "SELECT u.id,
					u.idnumber,
					u.firstname,
					u.lastname,
					u.email
					FROM {user_enrolments} ue
					INNER JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = ?)
					INNER JOIN {context} c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
					INNER JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.roleid = $CFG->paperattendance_studentrole AND ra.userid = ue.userid)
					INNER JOIN {user} u ON (ue.userid = u.id)
					WHERE e.enrol $enrolmethod
					GROUP BY u.id
					ORDER BY lastname, firstname, u.id ASC";
	$nstudents = count($DB->get_records_sql($querystudent, $parameters));
	$students = $DB->get_records_sql($querystudent, $parameters, $page*$perpage, $perpage);
	$table = new html_table();
	$table->head =array(
			get_string("hashtag", "local_paperattendance"),
			get_string("studentname", "local_paperattendance"),
			get_string("mail", "local_paperattendance"),
			get_string("presentattendance", "local_paperattendance"),
			get_string("absentattendance", "local_paperattendance"),
			get_string("percentagestudent", "local_paperattendance")
	);
	$table->size = array(
			'7%',
			'23%',
			'40%',
			'10%',
			'10%',
			'10%'
	);
	list($statusprocessed, $paramstatus) = $DB->get_in_or_equal(array(1,2));
	$params = array_merge(array($course->id), $paramstatus);
	$sqlsession = "SELECT s.*
				FROM {paperattendance_session} s
				WHERE s.courseid = ? AND s.status $statusprocessed";
	$sessions = count($DB->get_records_sql($sqlsession, $params));
	$rowcount = $page*$perpage+1;
	foreach($students as $student){
		if($sessions == 0 || $sessions == null){
			$present = 0;
			$absent = 0;
			$percentagestudent = '-';
		}
		else{
			//student summary sql
			$present = "SELECT COUNT(*)
			FROM {paperattendance_presence} AS p
			INNER JOIN {paperattendance_session} AS s ON (s.id = p.sessionid AND p.status = 1  AND s.courseid = ? AND s.status $statusprocessed  AND p.userid = ?)";
			$paramspresent = array();
			$paramspresent = array_merge($params, array($student->id));
			$present = $DB->count_records_sql($present, $paramspresent);
			$absent = $sessions - $present;
			$percentagestudent = round(($present/$sessions)*100)."%";
		}
		$table->data[] = array(
				$rowcount,
				$student->lastname." ".$student->firstname,
				$student->email,
				$present,
				$absent,
				$percentagestudent
		);
		$rowcount++;
	}
	$buttonurl = new moodle_url("/course/view.php", array("id" => $courseid));
	$PAGE->set_title(get_string('summarytitle', 'local_paperattendance'));
	$PAGE->set_heading(get_string('summarytitle', 'local_paperattendance'));

	echo $OUTPUT->header();
	echo $OUTPUT->tabtree(paperattendance_history_tabs($course->id), "studentssummary");
	echo html_writer::nonempty_tag("h4", get_string('totalattendances', 'local_paperattendance').": ".$sessions, array("align" => "left"));
	if ($nstudents>0){
		echo html_writer::table($table);
		echo $OUTPUT->paging_bar($nstudents, $page, $perpage, $url);
	}
	else{
		echo $OUTPUT->notification(get_string("nonexistintingrecords", "local_paperattendance"));
	}
	echo html_writer::nonempty_tag("div", $OUTPUT->single_button($buttonurl, get_string('backtocourse', 'local_paperattendance')), array("align" => "left", "style"=>"margin-top:20px"));
	echo $OUTPUT->footer();


}

else if ($isstudent) {
	$goback = new moodle_url("/course/view.php", array(
			"id" => $courseid
	));
	redirect($goback);
}
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
 * @copyright  2017 Jorge Cabané (jcabane@alumnos.uai.cl)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(dirname(dirname(dirname(__FILE__))) . "/config.php");
require_once("$CFG->libdir/pdflib.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi_bridge.php");
require_once("locallib.php");

global $DB, $PAGE, $OUTPUT, $USER, $CFG;

require_login();
if (isguestuser()) {
    print_error(get_string('notallowedprint', 'local_paperattendance'));
    die();
}

$courseid = optional_param("courseid", 1616, PARAM_INT);
$action = optional_param("action", "add", PARAM_TEXT);
$category = optional_param('categoryid', 1, PARAM_INT);

if ($courseid > 1) {
    if ($course = $DB->get_record("course", array("id" => $courseid))) {
        if ($course->idnumber != null) {
            $context = context_coursecat::instance($course->category);
        }
    } else {
        $context = context_system::instance();
    }
// The category of the courses start at 1
} else if ($category > 1) {
    $context = context_coursecat::instance($category);
} else {
    $context = context_system::instance();
}

if (!has_capability("local/paperattendance:printsecre", $context) && !$isteacher && !is_siteadmin($USER) && !has_capability("local/paperattendance:print", $context)) {
    print_error(get_string('notallowedprint', 'local_paperattendance'));
}
$urlprint = new moodle_url("/local/paperattendance/quickprint.php", array(
    "courseid" => $courseid,
    "categoryid" => $category,
));
// Page navigation and URL settings.
$pagetitle = get_string('printtitle', 'local_paperattendance');
$PAGE->set_context($context);
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');
$PAGE->requires->jquery_plugin('ui-css');
$PAGE->set_url($urlprint);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($pagetitle);

$course = $DB->get_record("course", array("id" => $courseid));

$url = $CFG->paperattendance_omegagetmoduloshorariosurl;

$fields = array(
    "diaSemana" => date('w'),
    "seccionId" => $course->idnumber,
);

$result = paperattendance_curl($url, $fields, false);
$modules = json_decode($result);

if (!is_array($modules) || count($modules) == 0) {
    echo get_string("nothingtoprint", "local_paperattendance");
    die();
}

$teachersparam = array(
    $CFG->paperattendance_profesoreditorrole,
    $courseid,
    'database',
);
//select teacher from course
$teachersquery = "SELECT u.id AS userid,c.id AS courseid,e.enrol,
						CONCAT(u.firstname, ' ', u.lastname) AS name FROM {user} u
						INNER JOIN {user_enrolments} ue ON (ue.userid = u.id)
		      			INNER JOIN {enrol} e ON (e.id = ue.enrolid)
						INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
						INNER JOIN {context} ct ON (ct.id = ra.contextid)
						INNER JOIN {course} c ON (c.id = ct.instanceid AND e.courseid = c.id)
						INNER JOIN {role} r ON (r.id = ra.roleid)
						WHERE r.id = ? AND c.id = ? AND e.enrol = ?";

$teachers = $DB->get_records_sql($teachersquery, $teachersparam);

$enrolincludes = explode(",", $CFG->paperattendance_enrolmethod);

foreach ($teachers as $teacher) {

    $enrolment = explode(",", $teacher->enrol);
    // Verifies that the teacher is enrolled through a valid enrolment and that we haven't added him yet.
    if (count(array_intersect($enrolment, $enrolincludes)) == 0 || isset($arrayteachers[$teacher->userid])) {
        continue;
    }
    $requestor = $teacher->userid;
}
//parameter needed to use the function "paperattendance_draw_student_list"
$requestorinfo = $DB->get_record("user", array("id" => $requestor));

//session date from today in unix
$sessiondate = strtotime(date('Y-m-d'));

//Curricular class
$description = 0;

$path = $CFG->dataroot . "/temp/local/paperattendance/";

$uailogopath = $CFG->dirroot . '/local/paperattendance/img/uai.jpeg';
$webcursospath = $CFG->dirroot . '/local/paperattendance/img/webcursos.jpg';
$timepdf = time();
$attendancepdffile = $path . "/print/paperattendance_" . $courseid . "_" . $timepdf . ".pdf";

if (!file_exists($path . "/print/")) {
    // 0777 its the directory permission on the linux server
    mkdir($path . "/print/", 0777, true);
}

$pdf = new PDF();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Get student for the list
$studentinfo = paperattendance_students_list($context->id, $course);

// We validate the number of students as we are filtering by enrolment.
// Type after getting the data.
$numberstudents = count($studentinfo);
if ($numberstudents == 0) {
    throw new Exception('No students to print');
}

// Contruction string for QR encode
foreach ($modules as $module) {
    $modquery = $DB->get_record("paperattendance_module", array("initialtime" => $module->horaInicio));
    $moduleid = $modquery->id;

    $key = $moduleid . "*" . $module->horaInicio . "*" . $module->horaFin;
    $stringqr = $courseid . "*" . $requestor . "*" . $moduleid . "*" . $sessiondate . "*";

    $printid = paperattendance_print_save($courseid, $moduleid, $sessiondate, $requestor);
    paperattendance_draw_student_list($pdf, $uailogopath, $course, $studentinfo, $requestorinfo, $key, $path, $stringqr, $webcursospath, $sessiondate, $description, $printid);
}

// Created new pdf
$pdf->Output($attendancepdffile, "F");

$fs = get_file_storage();
$file_record = array(
    'contextid' => $context->id,
    'component' => 'local_paperattendance',
    'filearea' => 'draft',
    'itemid' => 0,
    'filepath' => '/',
    'filename' => "paperattendance_" . $courseid . "_" . $timepdf . ".pdf",
    'timecreated' => time(),
    'timemodified' => time(),
    'userid' => $USER->id,
    'author' => $USER->firstname . " " . $USER->lastname,
    'license' => 'allrightsreserved',
);

// If the file already exists we delete it
if ($fs->file_exists($context->id, 'local_paperattendance', 'draft', 0, '/', "paperattendance_" . $courseid . "_" . $timepdf . ".pdf")) {
    $previousfile = $fs->get_file($context->id, 'local_paperattendance', 'draft', 0, '/', "paperattendance_" . $courseid . "_" . $timepdf . ".pdf");
    $previousfile->delete();
}
// Info for the new file
$fileinfo = $fs->create_file_from_pathname($file_record, $attendancepdffile);

$url = moodle_url::make_pluginfile_url($context->id, 'local_paperattendance', 'draft', 0, '/', "paperattendance_" . $courseid . "_" . $timepdf . ".pdf");
$viewerpdf = html_writer::nonempty_tag("iframe", " ", array(
    "id" => "pdf-iframe",
    "src" => $url
));

$reminder = get_string("printersettings", "local_paperattendance");
$downloadText = get_string("downloadprint", "local_paperattendance");

echo("
    $reminder
    <a href='$url' id='download-button' target='_blank' rel='noopener noreferrer' class='btn btn-primary'> $downloadText </a>
    $viewerpdf
");

?>

<script>
$( document ).on( "click", ".printbutton", function() {
    document.getElementById('pdf-iframe').contentWindow.print();
});
</script>

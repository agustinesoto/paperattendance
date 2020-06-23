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
* @copyright  2017 Cristóbal Silva (cristobal.isilvap@gmail.com)
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
require_once (dirname(dirname(dirname(__FILE__))) . "/config.php");
require_once ($CFG->libdir . '/pdflib.php');
require_once ($CFG->dirroot . '/mod/assign/feedback/editpdf/fpdi/fpdi.php');
require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi_bridge.php");
require_once ("locallib.php");

global $DB, $PAGE, $OUTPUT, $USER, $CFG;

require_login();
if (isguestuser()) {
	print_error("ACCESS DENIED");
	die();
}

$lists = $_REQUEST['lists'];

$path = $CFG -> dataroot. "/temp/local/paperattendance/";

$uailogopath = $CFG->dirroot . '/local/paperattendance/img/uai.jpeg';
$webcursospath = $CFG->dirroot . '/local/paperattendance/img/webcursos.jpg';
$timepdf = time();
$attendancepdffile = $path . "/print/paperattendance_".$timepdf.".pdf";

if (!file_exists($path . "/print/")) {
	mkdir($path . "/print/", 0777, true);
}

$pdf = new PDF();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

foreach ($lists as $list){
	$courseid = $list['courseid'];
	$requestor = $list['requestorid'];
	$sessiondate = strtotime($list['date']);
	$modules = $list['modules'];
	$description = 0;

	$course = $DB->get_record("course",array("id" => $courseid));
	$requestorinfo = $DB->get_record("user", array("id" => $requestor));
	$context = context_coursecat::instance($course->category);
	// Get students for the list
	$studentinfo = paperattendance_students_list($context->id, $course);

	foreach ($modules as $module){
		foreach ($module as $key => $value){
			if($value == 1){
				$schedule = explode("*", $key);
				$arraymodule = $schedule[0];
				$stringqr = $courseid."*".$requestor."*".$arraymodule."*".$sessiondate."*";
				$printid = paperattendance_print_save($courseid, $arraymodule, $sessiondate, $requestor);
				paperattendance_draw_student_list($pdf, $uailogopath, $course, $studentinfo, $requestorinfo, $key, $path, $stringqr, $webcursospath, $sessiondate, $description, $printid);
			}
		}
	}
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
		'filename' => "paperattendance_".$timepdf.".pdf",
		'timecreated' => time(),
		'timemodified' => time(),
		'userid' => $USER->id,
		'author' => $USER->firstname." ".$USER->lastname,
		'license' => 'allrightsreserved'
);

// If the file already exists we delete it
if ($fs->file_exists($context->id, 'local_paperattendance', 'draft', 0, '/', "paperattendance_".$timepdf.".pdf")) {
	$previousfile = $fs->get_file($context->id, 'local_paperattendance', 'draft', 0, '/', "paperattendance_".$timepdf.".pdf");
	$previousfile->delete();
}
// Info for the new file
$fileinfo = $fs->create_file_from_pathname($file_record, $attendancepdffile);

//	echo $OUTPUT->header();

$url = moodle_url::make_pluginfile_url($context->id, 'local_paperattendance', 'draft', 0, '/', "paperattendance_".$timepdf.".pdf");
$viewerpdf = html_writer::nonempty_tag("iframe", " ", array(
		"id" => "pdf-iframe",
		"src" => $url,
		"style" => "flex-grow: 1"
));
$reminder = get_string("printersettings", "local_paperattendance");
$downloadText = get_string("downloadprint", "local_paperattendance");

echo ("
    $reminder
	<a href='$url' style='margin-bottom: 15px;width: fit-content;' target='_blank' rel='noopener noreferrer' class='btn btn-primary'> $downloadText </a>
    $viewerpdf
");

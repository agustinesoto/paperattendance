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
* @copyright  2016 Hans Jeria (hansjeria@gmail.com)
* @copyright  2016 Matías Queirolo (mqueirolo@alumnos.uai.cl)  	
* @copyright  2016 Cristobal Silva (cristobal.isilvap@gmail.com) 				
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
//Pertenece al plugin PaperAttendance
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/local/paperattendance/forms/upload_form.php');
require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
require_once ($CFG->dirroot . "/repository/lib.php");
require_once ($CFG->libdir . '/pdflib.php');
require_once ($CFG->dirroot . '/mod/assign/feedback/editpdf/fpdi/fpdi.php');
require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi_bridge.php");
//require_once ($CFG->dirroot . "/mod/emarking/lib/openbub/ans_pdf_open.php");
require_once ($CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi.php");
require_once ($CFG->dirroot . '/local/paperattendance/phpdecoder/QrReader.php');
global $DB, $OUTPUT, $USER;
// User must be logged in.
require_login();
if (isguestuser()) {
    print_error(get_string('notallowedupload', 'local_paperattendance'));
    die();
}
$courseid = optional_param('courseid',1, PARAM_INT);
$categoryid = optional_param('categoryid', $CFG->paperattendance_categoryid, PARAM_INT);
$action = optional_param('action', 'viewform', PARAM_TEXT);


$contextsystem = context_system::instance();

if(is_siteadmin() || has_capability('local/paperattendance:adminacademic', $contextsystem)){
    $context = context_system::instance();
}
else{
    $sqlcategory = "SELECT cc.*
                FROM {course_categories} cc
                INNER JOIN {role_assignments} ra ON (ra.userid = ?)
                INNER JOIN {role} r ON (r.id = ra.roleid AND r.shortname = ?)
                INNER JOIN {context} co ON (co.id = ra.contextid  AND  co.instanceid = cc.id  )";

    $categoryparams = array($USER->id, "secrepaper");

    $categorys = $DB->get_records_sql($sqlcategory, $categoryparams);
    $categoryscount = count($categorys);
    if($categorys){
        foreach($categorys as $category){
            $categoryids[] = $category->id;
        }
        $categoryid = $categoryids[0];
    }else{
        print_error(get_string('notallowedupload', 'local_paperattendance'));
    }
    $context = context_coursecat::instance($categoryid);
}

$contextsystem = context_system::instance();

if (! has_capability('local/paperattendance:upload', $context) && ! has_capability('local/paperattendance:upload', $contextsystem)) {
    print_error(get_string('notallowedupload', 'local_paperattendance'));
}
// This page url.
$url = new moodle_url('/local/paperattendance/upload.php', array(
    'courseid' => $courseid,
	"categoryid" => $categoryid
));
$pagetitle = get_string('uploadtitle', 'local_paperattendance');
$PAGE->navbar->add(get_string('uploadtitle', 'local_paperattendance'));
$PAGE->navbar->add(get_string('header', 'local_paperattendance'),$url);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($pagetitle);

// Add the upload form for the course.
$addform = new paperattendance_upload_form (null, array(
		"courseid" => $courseid, 
		"categoryid" => $categoryid
));
// If the form is cancelled, refresh the instante.
if ($addform->is_cancelled()) {
	$courseurl = new moodle_url('/course/view.php', array(
			'id' => $courseid));
    redirect($courseurl);
    die();
} 
if ($addform->get_data()) {
	require_capability('local/paperattendance:upload', $context);
	
	$data = $addform -> get_data();
	
	$path = $CFG -> dataroot. "/temp/local/paperattendance";
	if (!file_exists($path . "/unread/")) {
			mkdir($path . "/unread/", 0777, true);
	}
	if ($draftid = file_get_submitted_draft_itemid('file')) {
		file_save_draft_area_files($draftid, $context->id, 'local_paperattendance', 'file', 0, array('subdirs' => 0, 'maxfiles' => 50));
	}
	$fs = get_file_storage();
	if ($files = $fs->get_area_files($context->id, 'local_paperattendance', 'file', '0', 'sortorder', false)) {
		$filecount = 1;
		$messages = array();
		foreach ($files as $file) {
			$time = strtotime(date("d-m-Y H:s:i"));
			$filename = "paperattendance_".$courseid."_".$time."_".$filecount.".pdf";
			$messages[] = paperattendance_uploadattendances($file, $path, $filename, $context, $contextsystem);
			$file->delete();
			$filecount++;
		}
	}
	$action = "viewmessages";

	//queue the pdf processing task after uploading the pdf
	$task = new \local_paperattendance\task\processpdf();
	\core\task\manager::queue_adhoc_task($task);
}
// If there is no data or is it not cancelled show the header, the tabs and the form.
echo $OUTPUT->header();
if($action == "viewform"){
	echo $OUTPUT->heading("Subir lista escaneada ");
	// Display the form.
	$addform->display();
}
if($action == "viewmessages"){
	foreach($messages as $message){
		echo $message;
	}
	echo $OUTPUT->single_button($url, get_string("printgoback","local_paperattendance"));
}

echo $OUTPUT->footer();

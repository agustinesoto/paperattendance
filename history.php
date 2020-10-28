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
 * @copyright  2016 Jorge Caban� (jcabane@alumnos.uai.cl)
 * @copyright  2016 Mat�as Queirolo (mqueirolo@alumnos.uai.cl)
 * @copyright  2016 Cristobal Silva (cristobal.isilvap@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
//Belongs to plugin PaperAttendance
require_once(dirname(dirname(dirname(__FILE__)))."/config.php");
require_once("$CFG->dirroot/local/paperattendance/forms/history_form.php");
require_once("$CFG->dirroot/local/paperattendance/forms/addstudent_form.php");
require_once("$CFG->dirroot/local/paperattendance/forms/reviewattendance_form.php");
require_once("$CFG->dirroot/local/paperattendance/locallib.php");
require_once("$CFG->dirroot/repository/lib.php");
require_once("$CFG->libdir/pdflib.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi_bridge.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi.php");
global $DB, $PAGE, $OUTPUT, $USER, $CFG;
//Possible actions -> view, scan or students attendance . Standard is view mode
$action = optional_param("action", "view", PARAM_TEXT);
$attendanceid = optional_param("attendanceid", 0, PARAM_INT);
$presenceid = optional_param("presenceid", null, PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$isdigital = optional_param('type', 0, PARAM_INT);
//Context definition
$context = context_course::instance($COURSE->id);
//Page settings
$url = new moodle_url("/local/paperattendance/history.php", array('courseid' => $courseid));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout("standard");
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin ( 'ui' );
$PAGE->requires->jquery_plugin ( 'ui-css' );

$PAGE->requires->js(new moodle_url('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js') );
$PAGE->requires->css(new moodle_url('https://netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.css') );
$PAGE->requires->css(new moodle_url('css/history.css'));

$contextsystem = context_system::instance();
//Page pagination
$page = optional_param('page', 0, PARAM_INT);
$perpage = 26;
//for navbar
$course = $DB->get_record("course",array("id" => $courseid));
$categorycontext = context_coursecat::instance($course->category);
//Login require
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
    $PAGE->navbar->add(get_string('historytitle', 'local_paperattendance'), new moodle_url("/local/paperattendance/history.php", array("courseid" => $courseid)));
    // action-> Students Attendance
    if ($action == "studentsattendance"){
        $PAGE->navbar->add(get_string('studentsattendance', 'local_paperattendance'),
            new moodle_url("/local/paperattendance/history.php", array("courseid" => $courseid , "attendanceid" => $attendanceid, "action" => $action)));
        //Query for the total count of attendances
        $getstudentsattendancecount = 'SELECT
				count(*)
				FROM {paperattendance_presence} AS p
				INNER JOIN {user} AS u ON (u.id = p.userid)
				WHERE p.sessionid = ? ';
        $attendancescount = $DB->count_records_sql($getstudentsattendancecount, array($attendanceid));
        $enrolincludes = explode("," ,$CFG->paperattendance_enrolmethod);
        list ( $sqlin, $param1 ) = $DB->get_in_or_equal ( $enrolincludes );
        $param2 = array(
            $courseid,
            $attendanceid
        );
        $param = array_merge($param2,$param1);
        //Query to get the table data of attendances
        $getstudentsattendance = "SELECT
		p.id AS idp
		FROM {paperattendance_presence} AS p
		INNER JOIN {user} AS u ON (u.id = p.userid)
		INNER JOIN {user_enrolments} ue ON ue.userid = u.id
		INNER JOIN {enrol} e ON e.id = ue.enrolid
		INNER JOIN {role_assignments} ra ON ra.userid = u.id
		INNER JOIN {context} ct ON ct.id = ra.contextid AND ct.contextlevel = 50
		INNER JOIN {course} c ON c.id=? AND c.id = ct.instanceid AND e.courseid = c.id
		INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
		WHERE p.sessionid = ? AND e.enrol $sqlin AND e.status = 0 AND u.suspended = 0 AND u.deleted = 0
        GROUP BY p.id
		ORDER BY u.lastname ASC"; //**Nose si quitar (AND e.enrol = "database") para que tambien muestre a los enrolados manualmente
        $attendances = $DB->get_records_sql($getstudentsattendance, $param);
        //$studentsid = array_values($attendances);
        $gettotalstudentsattendances = "SELECT
				p.id AS idp,
				u.lastname,
				u.firstname,
				u.email,
				p.status,
				p.omegasync
				FROM {paperattendance_presence} AS p
 				INNER JOIN {user} AS u ON (u.id = p.userid)
				WHERE p.sessionid = ?
				ORDER BY u.lastname ASC";
        $originalattendances = $DB->get_records_sql($gettotalstudentsattendances, array($attendanceid), $page * $perpage, $perpage);
        
        $attendancestable = new html_table();
        
        //Check if we have at least one attendance in the selected session
        if ($attendancescount > 0){
            
            $urlattendancechange = new moodle_url("#");
            $statusiconchange = new pix_icon("e/restore_draft", get_string('changepresence', 'local_paperattendance'));
            
            $statusiconactionchange = $OUTPUT->action_icon(
                $urlattendancechange,
                $statusiconchange
                );
            
            $setpresence = 1;
            $changeallattendance = html_writer::div(get_string('changeall', 'local_paperattendance'), "changeall", array("style"=>"display:none; cursor:pointer; text-decoration: underline; color: blue;","setpresence"=>"$setpresence"));
            
            $tableheadattendance = html_writer::div( get_string('attendance', 'local_paperattendance').$statusiconactionchange, "changeall");
            
            $attendancestable->head = array(
                get_string('hashtag', 'local_paperattendance'),
                get_string('student', 'local_paperattendance'),
                get_string('mail', 'local_paperattendance'),
                $changeallattendance.$tableheadattendance,
                get_string('changepresence', 'local_paperattendance'),
                get_string('omegasync', 'local_paperattendance')
            );
            
            //A mere counter for de number of records in the table
            $counter = $page * $perpage + 1;
            $studentnumberinarray = 0;
            foreach ($originalattendances as $attendance){
                $name = ($attendance->firstname.' '.$attendance->lastname);
                
                //define email table data: class email, attr email.
                $email = html_writer::div($attendance->email, "email", array("email"=>"$attendance->email"));
                
                //Define synchronized or unsynchronized icon
                $urlomegasync = new moodle_url("#");
                if ($attendance->omegasync){
                    $synchronizedicon = new pix_icon("i/checkpermissions", get_string('synchronized', 'local_paperattendance'));
                }
                else{
                    $synchronizedicon = new pix_icon(" i/duration", get_string('unsynchronized', 'local_paperattendance'));
                }
                $synchronizediconaction = $OUTPUT->action_icon(
                    $urlomegasync,
                    $synchronizedicon
                    );
                //Check if student is matriculated or not
                if (array_key_exists($attendance->idp, $attendances)) {
                    //Define presente or ausente icon
                    $urlattendance = new moodle_url("#");
                    
                    if($attendance->status){
                        $statusicon = new pix_icon("i/valid", get_string('presentattendance', 'local_paperattendance'));
                        $msgstatus = get_string('absentattendance', 'local_paperattendance');
                        $setstudentpresence = 0;
                    }
                    else{
                        $statusicon = new pix_icon("i/invalid", get_string('absentattendance', 'local_paperattendance'));
                        $msgstatus = get_string('presentattendance', 'local_paperattendance');
                        $setstudentpresence = 1;
                    }
                    
                    
                    $statusiconaction = $OUTPUT->action_icon(
                        $urlattendance,
                        $statusicon
                        );
                    // Define edition icon and url
                    $editactionasistencia = html_writer::div($msgstatus, "presencehover ", array("style"=>"display:yes; cursor:pointer; text-decoration: underline; color: blue;", "presenceid"=>"$attendance->idp", "setstudentpresence"=>"$setstudentpresence"));
                    // 				$editurlattendance = new moodle_url("/local/paperattendance/history.php", array(
                    // 						"action" => "edit",
                    // 						"presenceid" => $attendance->idp,
                    // 						"attendanceid" => $attendanceid,
                    // 						"courseid" => $courseid
                    // 				));
                    // 				$editiconattendance = new pix_icon("i/edit", get_string('edithistory', 'local_paperattendance'));
                    // 				$editactionasistencia = $OUTPUT->action_icon(
                    // 						$editurlattendance,
                        // 						$editiconattendance
                        // 						);
                        
                        $attendancestable->data[] = array(
                            $counter,
                            $name,
                            $email,
                            $statusiconaction,
                            $editactionasistencia,
                            $synchronizediconaction
                        );				}
                        else{
                            $studentnumberinarray--;
                            $attendancestable->data[] = array(
                                "<strike>$counter</strike>",
                                "<strike>$name</strike>",
                                "<strike>$email</strike>",
                                "<strike>Desmatriculado</strike>", //Add lan
                                null,
                                $synchronizediconaction
                            );
                        }
                        $counter++;
                        $studentnumberinarray++;
            }
        }
        
        $modaltable = new html_table();
        $modaltable->head = array(
            get_string('hashtag', 'local_paperattendance'),
            get_string('student', 'local_paperattendance'),
            get_string('addstudent', 'local_paperattendance')
        );
        $modaltable->id = "modaltable";
        
        //Query to get the original paper attendances students
        $gettotalstudentsattendancesmodal = "SELECT
				u.id AS userid,
				u.lastname,
				u.firstname
				FROM {paperattendance_presence} AS p
 				INNER JOIN {user} AS u ON (u.id = p.userid)
				WHERE p.sessionid = ?
				GROUP BY userid
				ORDER BY u.lastname ASC";
        $originalattendancesmodal = $DB->get_records_sql($gettotalstudentsattendancesmodal, array($attendanceid));
        
        
        list ( $sqlin, $param1 ) = $DB->get_in_or_equal ( $enrolincludes );
        $param2 = array(
            $courseid
        );
        $param = array_merge($param2,$param1);
        //Query to get the actual enrolled students in the course
        $getstudentsattendance = "SELECT
			u.id AS userid,
			u.lastname,
			u.firstname,
			u.email AS email
			FROM {user} AS u
			INNER JOIN {user_enrolments} ue ON ue.userid = u.id
			INNER JOIN {enrol} e ON e.id = ue.enrolid
			INNER JOIN {role_assignments} ra ON ra.userid = u.id
			INNER JOIN {context} ct ON ct.id = ra.contextid AND ct.contextlevel = 50
			INNER JOIN {course} c ON c.id=? AND c.id = ct.instanceid AND e.courseid = c.id
			INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
			WHERE e.enrol $sqlin AND e.status = 0 AND u.suspended = 0 AND u.deleted = 0
			ORDER BY u.lastname ASC"; //**Nose si quitar (AND e.enrol = "database") para que tambien muestre a los enrolados manualmente
        $enrolledstudents = $DB->get_records_sql($getstudentsattendance, $param);
        $counter = 0;
        foreach ($enrolledstudents as $enrolledstudent){
            $name = ($enrolledstudent->firstname.' '.$enrolledstudent->lastname);
            
            if (!array_key_exists($enrolledstudent->userid, $originalattendancesmodal)) {
                $counter++;
                
                $modaltable->data[] = array(
                    $counter,
                    $name,
                    '<input type="checkbox" class="usercheck" value="'.$enrolledstudent->userid.'" emailmodal="'.$enrolledstudent->email.'">',
                );
            }
        }
        $insertstudentmodal= '<div class="modal fade" id="insertstudentmodal" role="dialog">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
									<div class="modal-header">
          								<h4 class="modal-title">'.get_string("insertstudentmanually", "local_paperattendance").' </h4>
          								<button type="button" class="close" data-dismiss="modal">&times;</button>
       								</div>
							        <div class="modal-body">';
        if($counter>0){
            $insertstudentmodal .= '<div class="alert alert-info">'.get_string("insertstudentinfomodal", "local_paperattendance").'</div>
				  							'.html_writer::table($modaltable).'
		        							</div>
	        								<div class="modal-footer">
	          								<button id="saveinsertstudent" type="button" class="btn btn-default" data-dismiss="modal">'.get_string("save", "local_paperattendance").'</button>';
        }else{
            $insertstudentmodal .= '<div class="alert alert-danger">'.get_string("insertstudenterror", "local_paperattendance").'</div>
					</div>
					<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>';
        }
        $insertstudentmodal .='</div>
						      </div>
						    </div>
						  </div>';
        
        $viewbackbutton = new moodle_url("/local/paperattendance/history.php", array("action" => "view", "courseid" => $courseid));
        $insertstudenturl = new moodle_url("/local/paperattendance/history.php", array(
            "action" => "insertstudent",
            "courseid" => $courseid,
            "attendanceid" => $attendanceid
        ));
    }
    
    // Edits an existent record for the students attendance view
    if($action == "edit"){
        if($presenceid == null){
            print_error(get_string('nonselectedstudent', 'local_paperattendance'));
            $cancelled = new moodle_url("/local/paperattendance/history.php", array(
                "attendanceid" => $attendanceid,
                "courseid" => $courseid
            ));
            redirect($cancelled);
        }
        else{
            
            if($attendance = $DB->get_record("paperattendance_presence", array("id" => $presenceid)) ){
                
                $editform = new paperattendance_editattendance_form(null, array(
                    "attendanceid" => $attendanceid,
                    "courseid" => $courseid,
                    "presenceid" => $presenceid
                ));
                if($editform->is_cancelled()){
                    $cancelled = new moodle_url("/local/paperattendance/history.php", array(
                        "action" => "studentsattendance",
                        "attendanceid" => $attendanceid,
                        "courseid" => $courseid
                    ));
                    redirect($cancelled);
                }
                else if($data = $editform->get_data()){
                    $record = new stdClass();
                    $record->id = $presenceid;
                    $record->lastmodified = time();
                    $record->status = $data->status;
                    $DB->update_record("paperattendance_presence", $record);
                    
                    $modifieduserid = $attendance -> userid;
                    $omegaid = $attendance -> omegaid;
                    
                    $url =  $CFG->paperattendance_omegaupdateattendanceurl;
                    if($data->status == 1){
                        $status = "true";
                    }
                    else{
                        $status = "false";
                    }
                    
                    $fields = array (
                        "asistenciaId" => $omegaid,
                        "asistencia" => $status
                    );

                    paperattendance_curl($url, $fields);
                    
                    $backurl = new moodle_url("/local/paperattendance/history.php", array(
                        "action" => "studentsattendance",
                        "attendanceid" => $attendanceid,
                        "courseid" => $courseid
                    ));
                    redirect($backurl);
                }
            }
            else{
                print_error(get_string('nonexiststudent', 'local_paperattendance'));
                $canceled = new moodle_url("/local/paperattendance/history.php", array(
                    "action" => "studentsattendance",
                    "attendanceid" => $attendanceid,
                    "courseid" => $courseid
                ));
                redirect($canceled);
                
            }
        }
    }
    //Scan view
    if($action == "scan"){
        $backurl = new moodle_url("/local/paperattendance/history.php", array(
            "action" => "view",
            "attendanceid" => $attendanceid,
            "courseid" => $courseid
        ));
        $viewbackbutton = html_writer::nonempty_tag(
            "div",
            $OUTPUT->single_button($backurl, get_string('back', 'local_paperattendance')),
            array("align" => "left"
            ));
        $path = $CFG -> dataroot. "/temp/local/paperattendance/";
        $timepdf = time();
        $attendancepdffile = $path . "/print/paperattendance_".$courseid."_".$timepdf.".pdf";
        if (!file_exists($path . "/print/")) {
            mkdir($path . "/print/", 0777, true);
        }
        $pdfnamesql = "SELECT *
					   FROM {paperattendance_sessionpages} sp
					   WHERE sp.sessionid = ?
					   ORDER BY qrpage ASC";
        $pdfnames = $DB->get_records_sql($pdfnamesql, array($attendanceid));
        $pdf = new FPDI();
        foreach($pdfnames as $pdfname){
            $hashnamesql = "SELECT contenthash
							FROM {files}
							WHERE filename = ? AND component = ?";
            $hashname = $DB->get_record_sql($hashnamesql, array($pdfname->pdfname, 'local_paperattendance'));
            if($hashname){
                $newpdfname = $hashname->contenthash;
                $f1 = substr($newpdfname, 0 , 2);
                $f2 = substr($newpdfname, 2, 2);
                $filepath = $f1."/".$f2."/".$newpdfname;
                $pages = $pdfname->pagenum;
                //$originalpdf = $CFG -> dataroot. "/temp/local/paperattendance/unread/".$pdfname->pdfname;
                $originalpdf = $CFG -> dataroot. "/filedir/".$filepath;
                
                $pageCount = $pdf->setSourceFile($originalpdf);
                // import a page
                $templateId = $pdf->importPage($pages);
                // get the size of the imported page
                $size = $pdf->getTemplateSize($templateId);
                //Add page on portrait position
                $pdf->AddPage('P', array($size['w'], $size['h']));
                // use the imported page
                $pdf->useTemplate($templateId);
            }
        }
        // Preview PDF
        $pdf->Output($attendancepdffile, "F");
        $fs = get_file_storage();
        $file_record = array(
            'contextid' => $context->id,
            'component' => 'local_paperattendance',
            'filearea' => 'scan',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => "paperattendance_".$courseid."_".$timepdf.".pdf"
        );
        // If the file already exists we delete it
        if ($fs->file_exists($context->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$courseid."_".$timepdf.".pdf")) {
            $previousfile = $fs->get_file($context->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$courseid."_".$timepdf.".pdf");
            $previousfile->delete();
        }
        // Info for the new file
        $fileinfo = $fs->create_file_from_pathname($file_record, $attendancepdffile);
        $url = moodle_url::make_pluginfile_url($context->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$courseid."_".$timepdf.".pdf");
        $viewerpdf = html_writer::nonempty_tag("embed", " ", array(
            "src" => $url,
            "style" => "height:75vh; width:60vw"
        ));
        unlink($attendancepdffile);
    }

    //show sessions
    if ($action == "view"){
        $getattendances = "SELECT s.id,
						   sm.date,
						   m.name,
						   CONCAT( m.initialtime, ' - ', m.endtime) AS hour,
				 		   s.pdf,
						   s.status AS status,
						   s.description AS description,
                           s.type as type
						   FROM {paperattendance_session} AS s
						   INNER JOIN {paperattendance_sessmodule} AS sm ON (s.id = sm.sessionid)
						   INNER JOIN {paperattendance_module} AS m ON (sm.moduleid = m.id)
						   WHERE s.courseid = ?
						   ORDER BY sm.date DESC, m.name DESC";
        $attendances = $DB->get_records_sql($getattendances, array($courseid));

        $attendancestable = new html_table();
        //we check if we have attendances for the selected course
        if (count($attendances) > 0){
            $attendancestable->head = array(
                get_string('hashtag', 'local_paperattendance'),
                get_string('date', 'local_paperattendance'),
                get_string('time', 'local_paperattendance'),
                get_string('module', 'local_paperattendance'),
                get_string('description', 'local_paperattendance'),
                get_string('percentagestudent', 'local_paperattendance'),
                get_string('type', 'local_paperattendance'),
                get_string('studentsattendance', 'local_paperattendance'),
                get_string('omegasync', 'local_paperattendance')
            );
            $attendancestable->size = array(
                '5%',
                '27%',
                '13%',
                '8%',
                '13%',
                '10%',
                '8%',
                '8%',
                '8%'
            );
            $attendancestable->align = array(
                'left',
                'left',
                'left',
                'center',
                'center',
                'center',
                'center',
                'center',
                'center'
            );
            //A mere counter for the number of records
            $counter = 1;
            foreach ($attendances as $attendance)
            {
                //Query to get the real attendance (without desmatriculated students)
                $enrolincludes = explode("," ,$CFG->paperattendance_enrolmethod);
                list ( $sqlin, $param1 ) = $DB->get_in_or_equal ( $enrolincludes );
                $param2 = array(
                    $courseid,
                    $attendance->id
                );
                $param = array_merge($param2,$param1, $param2, $param1);
                
                $percentagequery = "SELECT TRUNCATE((SUM(p.status)/(COUNT(*))*100),0) AS percentage
				FROM {paperattendance_presence} AS p
				INNER JOIN {user} AS u ON (u.id = p.userid)
				INNER JOIN {user_enrolments} ue ON ue.userid = u.id
				INNER JOIN {enrol} e ON e.id = ue.enrolid
				INNER JOIN {role_assignments} ra ON ra.userid = u.id
				INNER JOIN {context} ct ON ct.id = ra.contextid AND ct.contextlevel = 50
				INNER JOIN {course} c ON c.id=? AND c.id = ct.instanceid AND e.courseid = c.id
				INNER JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
				WHERE p.sessionid = ? AND e.enrol $sqlin AND e.status = 0 AND u.suspended = 0 AND u.deleted = 0
				";
                
                $percentage = $DB->get_record_sql($percentagequery, $param);
                
                
                // Define scan icon and url
                if ($attendance->type == 0){
                    $scanurl_attendance = new moodle_url("/local/paperattendance/history.php", array(
                        "action" => "scan",
                        "attendanceid" => $attendance->id,
                        "courseid" => $courseid
                    ));
                    $scanicon_attendance = new pix_icon("e/new_document", get_string('see', 'local_paperattendance'));
                    $scanaction_attendance = $OUTPUT->action_icon(
                        $scanurl_attendance,
                        $scanicon_attendance
                        );
                }
                else{
                    //Define digital icon
                    $digitalicon_attendance = new pix_icon("e/bullet_list", get_string('see_digital', 'local_paperattendance')); // change icon
                    $digitalaction_attendance = $OUTPUT->action_icon(
                        "#",
                        $digitalicon_attendance
                        );
                }
                // Define Asistencia alumnos icon and url
                $studentsattendanceurl_attendance = new moodle_url("/local/paperattendance/history.php", array(
                    "action" => "studentsattendance",
                    "attendanceid" => $attendance->id,
                    "courseid" => $courseid,
                    "type" => $attendance->type
                ));
                $studentsattendanceicon_attendance = new pix_icon("i/log", get_string('seestudents', 'local_paperattendance'));
                $studentsattendanceaction_attendance = $OUTPUT->action_icon(
                    $studentsattendanceurl_attendance,
                    $studentsattendanceicon_attendance
                    );
                //Convert the unix date to a local date
                $date= $attendance->date;
                $dateconverted = paperattendance_convertdate($date);
                $attendancestatus = $attendance -> status;
                //Define synchronized or unsynchronized url
                $urlomegasync = new moodle_url("#");
                if ( $attendancestatus == 2){
                    $synchronizedicon = new pix_icon("i/checked", get_string('synchronized', 'local_paperattendance'));
                }
                else{
                    $synchronizedicon = new pix_icon(" i/duration", get_string('unsynchronized', 'local_paperattendance'));
                }
                $synchronizediconaction = $OUTPUT->action_icon(
                    $urlomegasync,
                    $synchronizedicon
                    );
                
                if($attendance->description && is_numeric($attendance->description)){
                    $attdescription = paperattendance_returnattendancedescription(false, $attendance->description);
                }
                else{
                    $attdescription = get_string('class', 'local_paperattendance');
                }
                if ($attendance->type == 0){
                    $type = $scanaction_attendance;
                }
                else if ($attendance->type == 1){
                    $type = $digitalaction_attendance;
                }
                $attendancestable->data[] = array(
                    $counter,
                    $dateconverted,
                    $attendance->hour,
                    $attendance->name,
                    $attdescription,
                    $percentage->percentage."%",
                    $type,
                    $studentsattendanceaction_attendance,
                    $synchronizediconaction
                );
                $counter++;
            }
        }
        $buttonurl = new moodle_url("/course/view.php", array("id" => $courseid));
    }
    if($action == "insertstudent"){
        $mform = new paperattendance_addstudent_form(null, array(
            "courseid" => $courseid,
            "attendanceid" => $attendanceid
        ));
        if($mform->is_cancelled()){
            $goback = new moodle_url("/local/paperattendance/history.php", array(
                "action" => "studentsattendance",
                "attendanceid" => $attendanceid,
                "courseid" => $courseid
            ));
            redirect($goback);
        }
        else if($data = $mform->get_data()){
            $insertby = $data->insertby;
            $filter = $data->filter;
            $status = $data->status;
            $user = $DB->get_record("user", array($insertby => $filter));
            $addstudent = new stdClass();
            $addstudent->sessionid = $attendanceid;
            $addstudent->userid = $user->id;
            $addstudent->status = $status;
            $addstudent->lastmodified = time();
            $addstudent->omegasync = 0;
            $insertattendance = $DB->insert_record("paperattendance_presence", $addstudent, false);
            $goback = new moodle_url("/local/paperattendance/history.php", array(
                "action" => "studentsattendance",
                "attendanceid" => $attendanceid,
                "courseid" => $courseid
            ));
            redirect($goback);
        }
    }
    $PAGE->set_title(get_string('historytitle', 'local_paperattendance'));
    $PAGE->set_heading(get_string('historyheading', 'local_paperattendance'));
    echo $OUTPUT->header();
    echo $OUTPUT->tabtree(paperattendance_history_tabs($course->id), "attendancelist");
    // Displays Students Attendance view
    if ($action == "studentsattendance"){
        if (count($attendances) == 0){
            echo html_writer::nonempty_tag("h4", get_string('nonprocessingattendance', 'local_paperattendance'), array("align" => "left"));
        }
        else{
            
            $sqlstudents = "SELECT sm.id,
						   sm.date AS smdate,
						   CONCAT( m.initialtime, ' - ', m.endtime) AS hour,
						   s.description AS description
						   FROM {paperattendance_module} AS m
						   INNER JOIN {paperattendance_sessmodule} AS sm ON (sm.moduleid = m.id AND sm.sessionid = ?)
	    				   INNER JOIN {paperattendance_session} AS s ON (sm.sessionid = s.id)";
            
            $resources = $DB->get_record_sql($sqlstudents, array($attendanceid));
            
            if($resources->description && is_numeric($resources->description)){
                $summdescription = paperattendance_returnattendancedescription(false, $resources->description);
            }
            else{
                $summdescription = get_string('class', 'local_paperattendance');
            }
            
            // add scanbutton for the teacher to open scan on new window
            if ($isdigital == 0){
                $scanurl_attendance = new moodle_url("/local/paperattendance/history.php", array(
                    "action" => "scan",
                    "attendanceid" => $attendanceid,
                    "courseid" => $courseid
                    
                ));
                $scanicon_attendance = new pix_icon("e/new_document", get_string('see', 'local_paperattendance'));
                $scanaction_attendance = $OUTPUT->action_icon(
                    $scanurl_attendance,
                    $scanicon_attendance,
                    null,
                    array("target" => "_blank")
                    );
            }
            else{
                $digitallist = get_string('digitallist', 'local_paperattendance');
                $digitalicon_attendance = new pix_icon("e/bullet_list", get_string('see_digital', 'local_paperattendance')); // change icon
                $digitalaction_attendance = $OUTPUT->action_icon(
                    "#",
                    $digitalicon_attendance
                    );
            }
            
            $left = html_writer::nonempty_tag("div", paperattendance_convertdate($resources->smdate), array("align" => "left"));
            $left .= html_writer::nonempty_tag("div", get_string("description","local_paperattendance").": ".$summdescription, array("align" => "left"));
            $left .= html_writer::nonempty_tag("div", get_string("module","local_paperattendance").": ".$resources->hour, array("align" => "left"));
            if($isdigital == 0){
                $left .= html_writer::nonempty_tag("div", get_string("scan","local_paperattendance").": ".$scanaction_attendance, array("align" => "left"));
            }
            else{
                $left .= html_writer::nonempty_tag("div", $digitallist." ".$digitalaction_attendance, array("align" => "left"));
            }
            $left .= html_writer::nonempty_tag("div","<br>", array("align" => "left"));
            $left .= html_writer::nonempty_tag("div", '<button id="insertstudentmodalbtn" type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#insertstudentmodal">'.get_string("insertstudentmanually", "local_paperattendance").'</button>', array("align" => "left"));
            //$left .= html_writer::nonempty_tag("div", $OUTPUT->single_button($insertstudenturl, get_string('insertstudentmanually', 'local_paperattendance')), array("align" => "center"));
            $left .= html_writer::nonempty_tag("div","<br>", array("align" => "left"));
            $left .= html_writer::nonempty_tag("div",$insertstudentmodal);
            //displays button to add a student manually
            echo html_writer::nonempty_tag("div", $left);
            echo html_writer::div('', 'loader');
            
            //displays the table
            echo html_writer::table($attendancestable);
            //displays de pagination bar
            echo $OUTPUT->paging_bar($attendancescount, $page, $perpage,
                $CFG->wwwroot . '/local/paperattendance/history.php?action=' . $action . '&attendanceid=' . $attendanceid . '&courseid=' . $courseid . '&page=');
        }
        echo html_writer::nonempty_tag("div", $OUTPUT->single_button($viewbackbutton, get_string('back', 'local_paperattendance')), array("align" => "left"));
    }
    // Displays the form to edit a record
    if( $action == "edit" ){
        $editform->display();
    }
    //Displays the scan file
    if($action == "scan"){
        // Donwload and back buttons
        echo $OUTPUT->action_icon($url, new pix_icon('i/grades', get_string('download', 'local_paperattendance')), null, array("target" => "_blank"));
        echo html_writer::nonempty_tag("h7", get_string('downloadassistance', 'local_paperattendance'), array("align" => "left"));
        echo $viewbackbutton;
        echo $viewerpdf;
    }
    // Displays all the records and options
    if ($action == "view"){
        if (count($attendances) == 0){
            echo html_writer::nonempty_tag("h4", get_string('nonexistintingrecords', 'local_paperattendance'), array("align" => "left"));
        }
        else{
            echo html_writer::table($attendancestable);
        }
        echo html_writer::nonempty_tag("div", $OUTPUT->single_button($buttonurl, get_string('backtocourse', 'local_paperattendance')), array("align" => "left","style"=>"margin-top:20px"));
    }
    //Displays the insert student form
    if($action == "insertstudent"){
        $mform->display();
    }
}
//Ends Teacher's view

//Begins Student's view
else if ($isstudent) {
    //breadcrumb for navigation
    $PAGE->navbar->ignore_active();
    $PAGE->navbar->add(get_string('courses', 'local_paperattendance'), new moodle_url('/course/index.php'));
    $PAGE->navbar->add($course->shortname, new moodle_url('/course/view.php', array("id" => $courseid)));
    $PAGE->navbar->add(get_string('pluginname', 'local_paperattendance'));
    $PAGE->navbar->add(get_string('historytitle', 'local_paperattendance'), new moodle_url("/local/paperattendance/history.php", array("courseid" => $courseid)));
    // Lists all records in the database
    if ($action == "view")
    {
        //icons
        $urlicon = new moodle_url("#");
        $synchronizedicon = new pix_icon(" i/duration", get_string('pending', 'local_paperattendance'));
        $synchronizediconaction = $OUTPUT->action_icon(
            $urlicon,
            $synchronizedicon
            );
        $validicon = new pix_icon("i/valid", get_string('presentattendance', 'local_paperattendance'));
        $validiconaction = $OUTPUT->action_icon(
            $urlicon,
            $validicon
            );
        $invalidicon = new pix_icon("i/invalid", get_string('absentattendance', 'local_paperattendance'));
        $invalidiconaction = $OUTPUT->action_icon(
            $urlicon,
            $invalidicon
            );
        $getstudentattendances = "SELECT s.id AS sessionid,
				p.id AS presenceid,
				sm.date,
				CONCAT( m.initialtime, ' - ', m.endtime) AS hour,
				p.status,
				m.name,
				s.description AS description,
				s.lastmodified AS sessdate
				FROM {paperattendance_session} AS s
				INNER JOIN {paperattendance_sessmodule} AS sm ON (s.id = sm.sessionid AND s.courseid = ?)
				INNER JOIN {paperattendance_module} AS m ON (sm.moduleid = m.id)
				INNER JOIN {paperattendance_presence} AS p ON (s.id = p.sessionid)
				INNER JOIN {user} AS u ON (u.id = p.userid AND u.id = ?)
				ORDER BY sm.date DESC, m.name DESC";
        $attendances = $DB->get_records_sql($getstudentattendances, array($courseid, $USER->id));
        $attendancestable = new html_table();
        if (count($attendances) > 0){
            $attendancestable->head = array(
                get_string('hashtag', 'local_paperattendance'),
                get_string('date', 'local_paperattendance'),
                get_string('module', 'local_paperattendance'),
                get_string('time', 'local_paperattendance'),
                get_string('description', 'local_paperattendance'),
                get_string('attendance', 'local_paperattendance'),
                get_string('reviewattendance', 'local_paperattendance')
            );
            
            $attendancestable->size = array(
                '5%',
                '20%',
                '10%',
                '15%',
                '22%',
                '8%',
                '20%'
            );
            //A mere counter for the numbers of records
            $counter = 1;
            foreach ($attendances as $attendance){
                $urlattendance = new moodle_url("#");
                if ($attendance->status){
                    $statusicon = new pix_icon("i/valid", get_string('presentattendance', 'local_paperattendance'));
                }
                else{
                    $statusicon = new pix_icon("i/invalid", get_string('absentattendance', 'local_paperattendance'));
                }
                $statusiconaction = $OUTPUT->action_icon(
                    $urlattendance,
                    $statusicon
                    );
                $formbuttonurl = new moodle_url("/local/paperattendance/history.php", array("action"=>"requestattendance","presenceid" => $attendance->presenceid,"courseid" => $courseid));
                $discussion = $DB->get_record("paperattendance_discussion", array("presenceid" => $attendance->presenceid));
                //convert date from seconds (unix) to days
                $timelimit = $attendance->sessdate + $CFG->paperattendance_discusstimelimit*86400;
                if($attendance->description && is_numeric($attendance->description)){
                    $attdescription = paperattendance_returnattendancedescription(false, $attendance->description);
                }
                else{
                    $attdescription = get_string('class', 'local_paperattendance');
                }
                $attendancestable->data[] = array(
                    $counter,
                    paperattendance_convertdate($attendance->date),
                    $attendance->name,
                    $attendance->hour,
                    $attdescription,
                    $statusiconaction,
                    //result = 0 -> scheduled icon (Attendance request wasn't solved yet)
                    //result = 1 -> invalid icon (Attendance request wasn't accepted)
                    //result = 2 -> valid icon (Attendance request was accepted)
                    ((!$attendance->status && !$discussion && $timelimit>time()) ? html_writer::nonempty_tag("div", $OUTPUT->single_button($formbuttonurl, get_string('request', 'local_paperattendance')), array("style"=>"height:30px"))
                        : ((!$attendance->status && !$discussion && $timelimit<time()) ? html_writer::nonempty_tag("div", $OUTPUT->single_button($formbuttonurl, get_string('request', 'local_paperattendance'),'POST',array("disabled"=>"disabled")),array("style"=>"height:30px"))
                            : (($attendance->status && !$discussion) ? null
                                : (($discussion->result == 0) ? $synchronizediconaction
                                    : (($discussion->result == 1) ? $invalidiconaction
                                        : $validiconaction)))))
                );
                $counter++;
            }
        }
        $backbuttonurl = new moodle_url("/course/view.php", array("id" => $courseid));
    }
    if ($action == "requestattendance"){
        $requestform = new paperattendance_reviewattendance_form(null, array(
            "courseid" => $courseid,
            "presenceid" => $presenceid
        ));
        $goback = new moodle_url("/local/paperattendance/history.php", array("action"=>"view","courseid" => $courseid));
        $presence = $DB->get_record("paperattendance_presence", array("id"=>$presenceid));
        $sqlsession = "SELECT sm.date,
						m.name,
						m.initialtime,
						m.endtime,
						s.description
						FROM {paperattendance_module} m
						INNER JOIN {paperattendance_session} s ON (s.id =?)
						INNER JOIN {paperattendance_sessmodule} sm ON (sm.sessionid = s.id AND sm.moduleid = m.id)";
        $session = $DB->get_record_sql($sqlsession, array($presence->sessionid));
        $sessdate = paperattendance_convertdate($session->date);
        if($requestform->is_cancelled()){
            redirect($goback);
        }
        else if($data = $requestform->get_data()){
            $newdiscussion = new stdClass();
            $newdiscussion->presenceid = $presenceid;
            $newdiscussion->comment = $data->comment;
            $newdiscussion->result = 0; //Result equals to 0 means that the discussion is open
            $newdiscussion->timecreated = time();
            $newdiscussion->timemodified = time();
            $sqlteacher = "SELECT s.teacherid AS id
					FROM {paperattendance_session} s
					INNER JOIN {paperattendance_presence} p ON (p.sessionid = s.id AND p.id=?)";
            $teacher = $DB->get_record_sql($sqlteacher, array($presence->id));
            $insertdiscussion = $DB->insert_record("paperattendance_discussion", $newdiscussion, false);
            paperattendance_sendMail(null, $courseid, $USER->id, null, $sessdate, $course->fullname, "newdiscussionstudent",null);
            paperattendance_sendMail(null, $courseid, $teacher->id, null, $sessdate, $course->fullname, "newdiscussionteacher", null);
            redirect($goback);
        }
    }
    $PAGE->set_title(get_string('historytitle', 'local_paperattendance'));
    $PAGE->set_heading(get_string('historyheading', 'local_paperattendance'));
    echo $OUTPUT->header();
    // Displays all the records and options
    if ($action == "view"){
        if (count($attendances) == 0){
            echo html_writer::nonempty_tag("h4", get_string('nonexistintingrecords', 'local_paperattendance'), array("align" => "left"));
        }
        else{
            
            //student summary sql
            $sessions = $DB->count_records("paperattendance_session", array ("courseid" => $course->id));
            $present = "SELECT COUNT(*)
						FROM {paperattendance_presence} AS p
						INNER JOIN {paperattendance_session} AS s ON (s.id = p.sessionid AND p.status = 1 AND s.courseid = ? AND p.userid = ?)";
            $present = $DB->count_records_sql($present, array($course->id, $USER->id));
            $absent = $sessions - $present;
            $percentagestudent = round(($present/$sessions)*100);
            
            //summary
            echo html_writer::nonempty_tag("h4", $USER->firstname." ".$USER->lastname, array("align" => "left"));
            $resume = html_writer::nonempty_tag("div", get_string('totalattendances', 'local_paperattendance').": ".$sessions, array("align" => "right"));
            $resume .= html_writer::nonempty_tag("div", get_string('presentattendance', 'local_paperattendance').": ".$present, array("align" => "right"));
            $resume .= html_writer::nonempty_tag("div", get_string('absentattendance', 'local_paperattendance').": ".$absent, array("align" => "right"));
            $resume .= html_writer::nonempty_tag("div", get_string('percentagestudent', 'local_paperattendance')."*: ".$percentagestudent."%", array("align" => "right", "title" => get_string('tooltippercentage', 'local_paperattendance'), "data-placement"=>"left","data-toggle" =>"tooltip"));
            echo html_writer::nonempty_tag("div", $resume, array("style" => "width:20%"));
            
            //table
            echo html_writer::nonempty_tag("div", html_writer::table($attendancestable), array( "style" => " margin-top: 15px;"));
            
        }
        echo html_writer::nonempty_tag("div", $OUTPUT->single_button($backbuttonurl, get_string('backtocourse', 'local_paperattendance')), array("align" => "left"));
    }
    if ($action == "requestattendance"){
        echo html_writer::nonempty_tag("h4", $course->fullname." - ".$course->shortname, array("align" => "left"));
        $resume = html_writer::nonempty_tag("div", get_string('attdate', 'local_paperattendance').": ".$sessdate, array("align" => "left"));
        $resume .= html_writer::nonempty_tag("div", get_string('time', 'local_paperattendance').": ".$session->initialtime." - ".$session->endtime, array("align" => "left"));
        $resume .= html_writer::nonempty_tag("div", get_string('descriptionselect', 'local_paperattendance').": ".$session->description, array("align" => "left"));
        echo html_writer::nonempty_tag("div", $resume, array("style" => "width:30%; margin-bottom:30px"));
        $requestform->display();
    }
}
//Ends Student's view
else{
    print_error(get_string('error', 'local_paperattendance'));
}
echo $OUTPUT->footer();
?>
<script type="text/javascript">
	$( document ).on( "click", "#insertstudentmodalbtn", function() {
		jQuery('#insertstudentmodal').css('z-index', '');
	});
</script>

<script>
$( document ).ready(function() {
    /*$('.generaltable').find('th').hover(function() {
			$( this ).find('.changeall').toggle();
		}, function() {
			$( this ).find('.changeall').toggle();
		}
	);*/
	$('.presencehover').on( "click", function() {
		var div = $(this);
		var studentpresence = div.attr("setstudentpresence"); 
		var presenceid = div.attr("presenceid"); 
		var moodleurl = "<?php echo $CFG->wwwroot;?>";
		
		if(studentpresence == 0){
			var settext = "Presente";
			var setpresence = 1;
			var icon = 'icon fa fa-remove text-danger fa-fw';
		}
		else{
			var settext = "Ausente";
			var setpresence = 0;
			var icon = 'icon fa fa-check text-success fa-fw ';
		}
		$.ajax({
		    type: 'GET',
		    url: 'ajax/changestudentpresence.php',
		    data: {
			      'setstudentpresence' : studentpresence,
			      'presenceid' : presenceid
		    	},
		    success: function (response) {
                console.log(response);
				div.html(settext);
				div.attr("setstudentpresence", setpresence);
				div.parent().parent().find('.icon').first().attr({
					  'class': icon
				});
		    }
		});
	});
	// Change all presence function
	$('.changeall').on( "click", function() {
		//first we need the session id, course is and setpresence attribute located in the table head
		var studentspresenceinfo = [];
		var sessinfo = [];
		var sessid = <?php echo $attendanceid; ?>;
		var courseid = <?php echo $courseid; ?>;
		var setpresence = $('.generaltable').find('th').find('.changeall').attr("setpresence");
		//we add this info to de array sessinfo
		sessinfo.push({'courseid' : courseid, 'sessid' : sessid, 'setpresence' : setpresence});
		//now for each student we save his presence id and his email, presenceid for saving in database and email for saving in omega
		var trow = $('.generaltable').find('tr');
		$.each(trow, function(i, field){
			var div = $(this); 
			var email= div.find('.email').attr("email");
			var presenceid = div.find('.presencehover').attr("presenceid");
			//We agregate the info to the de studentspresenceinfo array
			studentspresenceinfo.push({'presenceid' : presenceid, 'email' : email});
			
		});
		console.log(JSON.stringify(studentspresenceinfo));
		console.log(JSON.stringify(sessinfo));
		$(".generaltable").hide();
		$(".loader").html("<img style='display: block; margin-left: auto; margin-right: auto;' src='img/loading.gif'>");
		$(".loader").show();
		//ajax to ajaxquerys with 2 arrays, one with the info session and the other with every studen presence id an email
		$.ajax({
		    type: 'POST',
		    url: 'ajax/changeallpresence.php',
		    data: {
			      'sessinfo' : JSON.stringify(sessinfo),
			      'studentspresenceinfo' : JSON.stringify(studentspresenceinfo)
		    	},
		    success: function (response) {
			    //now that we save the students attendance, we need to actualize the setpresence: 0 ->when was changed to present and 1->when changed to absent
		    	setpresence = response["setpresence"];
		    	var moodleurl = "<?php echo $CFG->wwwroot;?>";
				//we use font awesome icons
				if(setpresence == 1){
					var settext = "Presente";
					var settextchangeall = "Cambiar Todos";
					var setpresencestudent = 1;
					var icon = 'icon fa fa-remove text-danger fa-fw';
				}
				else{
					var settext = "Ausente";
					var settextchangeall = "Cambiar Todos";
					var setpresencestudent = 0;
					var icon = 'icon fa fa-check text-success fa-fw ';
				}
				//now we find every student in the table and change his frontend to present or absent
				var trow = $('.generaltable').find('tr').find('.presencehover');
		    	$.each(trow, function(i, field){
					var div = $(this); 
					div.html(settext);
					div.attr("setstudentpresence", setpresencestudent);
					div.parent().parent().find('.icon').first().attr({
						'class': icon
					});
					
				});
				//now finally we change the frontend for the "changeall" button
				var htmlchangeall = $('.generaltable').find('th').find('.changeall');
		    	htmlchangeall.attr("setpresence", setpresence);
		    	//htmlchangeall.html(settextchangeall);
				//var error3 = response["guardar"];
		    	$(".loader").hide();
		    	$(".generaltable").show();
				
		    }
		});
		
	});
	//Insert student modal function
	$( "#saveinsertstudent" ).on( "click", function() {
		var sessinfo = [];
		var sessid = <?php echo $attendanceid; ?>;
		var courseid = <?php echo $courseid; ?>;
		//we add this info to de array sessinfo
		sessinfo.push({'courseid' : courseid, 'sessid' : sessid});
		var contador = 0;
		var studentsattendance = [];
		//Validate if the checkbox is checked or not
		var checkbox = $('.usercheck');
		$.each(checkbox, function(i, field){
			var currentcheckbox = $(this);
			if(currentcheckbox.prop("checked") == true){
				var emailmodal= currentcheckbox.attr("emailmodal");
				//We agregate the info to the de studentsattendance array
				studentsattendance.push({"userid":currentcheckbox.val(), "email": emailmodal});
				contador = contador+1;
			}
		});	
		if (contador != 0){ //if exist at least one student to insert do this
			//ajax to ajaxquerys with 2 arrays, one with the info session and the other with every studentid an email
			$.ajax({
			    type: 'POST',
			    url: 'ajax/saveinsertstudent.php',
			    data: {
				      'sessinfo' : JSON.stringify(sessinfo),
				      'studentsattendance' : JSON.stringify(studentsattendance)
			    	},
			    success: function (response) {		    
	        		location.href="history.php?action=studentsattendance&attendanceid="+sessid+"&courseid="+courseid;
					
			    },
			    complete: function (index){
					console.log(index);
			    }
			});
		}
		//Shows students attendace and sessinfo in JSON format:
		/*alert(JSON.stringify(studentsattendance));
		console.log(JSON.stringify(studentsattendance));
		console.log(JSON.stringify(sessinfo));
		console.log(contador);
		*/
	});
});
</script>
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
define('AJAX_SCRIPT', true);
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');

global $CFG, $DB, $USER, $PAGE, $OUTPUT;

require_login();
if (isguestuser()) {
	die();
}

$action = required_param('action', PARAM_ALPHA);
$omegaid = optional_param('omegaid', null, PARAM_TEXT);
$diasemana = optional_param('diasemana', null, PARAM_TEXT);
$data = optional_param('result', null, PARAM_TEXT);
$paths = optional_param('path', null, PARAM_TEXT);
$courseid = optional_param("courseid", 1, PARAM_INT);
$begin = optional_param("begin", 1, PARAM_INT);
$category = optional_param('category', $CFG->paperattendance_categoryid, PARAM_INT);
$teacherid = optional_param("teacherid", 1, PARAM_INT);
$setstudentpresence = optional_param("setstudentpresence", 1, PARAM_INT);
$presenceid = optional_param("presenceid", 1, PARAM_INT);
$module = optional_param("module", null, PARAM_TEXT);
$date = optional_param("date", null, PARAM_TEXT);
//$sessinfo = optional_param_array('sessinfo', array("alo"), PARAM_INT);
//$studentsattendance = optional_param_array('studentsattendance', array("alo"), PARAM_INT);
//switch is used to execute an specific case depending of the page where ajaxquerys was called
switch ($action) {
	//This case response returns the omega modules of a class
	case 'curlgetmoduloshorario' :
		require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
		$token = $CFG->paperattendance_omegatoken;
		$url = $CFG->paperattendance_omegagetmoduloshorariosurl;
		if($courseid > 1){
			if($course = $DB->get_record("course", array("id" => $courseid)) ){
				if($course->idnumber != NULL){
					$context = context_coursecat::instance($course->category);
				}
			}
			else{
				$context = context_system::instance();
			}
		}else if($category > 1){
			$context = context_coursecat::instance($category);
		}else{
			$context = context_system::instance();
		}
		$isteacher = paperattendance_getteacherfromcourse($courseid, $USER->id);
		if(!has_capability("local/paperattendance:printsecre", $context) && !$isteacher && !is_siteadmin($USER) && !has_capability("local/paperattendance:print", $context)){
			print_error(get_string('notallowedprint', 'local_paperattendance'));
		}
		$curl = curl_init();

		$fields = array (
				"diaSemana" => $diasemana,
				"seccionId" => $omegaid,
				"token" => $token
		);

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
		curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
		$result = curl_exec ($curl);
		curl_close ($curl);

		echo  json_encode($result);
		break;
	//This case returns courses that match with the searched word on printsearch, it could be by teacher or course name
	case 'getcourses' :
		if($category > 1){
			$context = context_coursecat::instance($category);
		}else{
			$context = context_system::instance();
		}
	
		$contextsystem = context_system::instance();
		if (! has_capability('local/paperattendance:printsearch', $context) && ! has_capability('local/paperattendance:printsearch', $contextsystem)) {
			print_error(get_string('notallowedprint', 'local_paperattendance'));
		}
		/*parameters for the query:
	 	 *sqlin  -> enrol methods
		 *param2 -> contextlevel, role shortname
		 *filter -> input from user (course fullname or teacher name)
		 */
		$filter = array("%".$data."%", $data."%");
		$enrolincludes = explode("," ,$CFG->paperattendance_enrolmethod);
		list ( $sqlin, $param1 ) = $DB->get_in_or_equal ( $enrolincludes );
		$param2 = array(
				50,
				'%profesoreditor%',
		);
		$parametros1= array_merge($param1,$param2);
		//If is site admin he can see courses from all categories
		if(is_siteadmin()){
/*			#Query with date filter
 * 			$year = strtotime("1 January".(date('Y')));
			$filter = array($year, "%".$data."%", $data."%");
			list($sqlin, $parametros1) = $DB->get_in_or_equal(array(3,4));
			$sqlcourses = "SELECT c.id,
						c.fullname,
						cat.name,
						u.id as teacherid,
						CONCAT( u.firstname, ' ', u.lastname) as teacher
						FROM {role} AS r
						INNER JOIN {role_assignments} ra ON (ra.roleid = r.id AND r.id $sqlin)
						INNER JOIN {context} ct ON (ct.id = ra.contextid)
						INNER JOIN {course} c ON (c.id = ct.instanceid)
						INNER JOIN {user} u ON (u.id = ra.userid)
						INNER JOIN {course_categories} as cat ON (cat.id = c.category)
						WHERE (c.timecreated > ? AND c.idnumber > 0 ) AND (CONCAT( u.firstname, ' ', u.lastname) like ? OR c.fullname like ?)
						GROUP BY c.id
						ORDER BY c.fullname";
*/			
			//Query without date filter
			$sqlcourses = "SELECT c.id,
						c.fullname,
						cat.name,
						u.id as teacherid,
						CONCAT( u.firstname, ' ', u.lastname) as teacher
						FROM {user} u
						INNER JOIN {user_enrolments} ue ON (ue.userid = u.id)
						INNER JOIN {enrol} e ON (e.id = ue.enrolid)
						INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
						INNER JOIN {context} ct ON (ct.id = ra.contextid)
						INNER JOIN {course} c ON (c.id = ct.instanceid AND e.courseid = c.id)
						INNER JOIN {role} r ON (r.id = ra.roleid)
						INNER JOIN {course_categories} as cat ON (cat.id = c.category)
						WHERE e.enrol $sqlin AND c.idnumber > 0 AND ct.contextlevel = ? AND r.shortname like ? AND (CONCAT( u.firstname, ' ', u.lastname) like ? OR c.fullname like ?)
						GROUP BY c.id
						ORDER BY r.id ASC";
		}else{ 
			//If user is a secretary, he can see only courses from his categorie
			$paths = unserialize(base64_decode($paths));
			$pathscount = count($paths);
			$like = "";
			$counter = 1;
			foreach ($paths as $path){
				$searchquery = "cat.path like '%/".$path."/%' OR cat.path like '%/".$path."'";
				if($counter==$pathscount){
					$like.= $searchquery;
				}
				else{
						$like.= $searchquery." OR ";
				}
			$counter++;
			}
			$sqlcourses = "SELECT c.id,
						c.fullname,
						cat.name,
						u.id as teacherid,
						CONCAT( u.firstname, ' ', u.lastname) as teacher
						FROM {user} u
						INNER JOIN {user_enrolments} ue ON (ue.userid = u.id)
						INNER JOIN {enrol} e ON (e.id = ue.enrolid)
						INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
						INNER JOIN {context} ct ON (ct.id = ra.contextid)
						INNER JOIN {course} c ON (c.id = ct.instanceid AND e.courseid = c.id)
						INNER JOIN {role} r ON (r.id = ra.roleid)
						INNER JOIN {course_categories} as cat ON (cat.id = c.category)
						WHERE ($like AND c.idnumber > 0 ) AND e.enrol $sqlin AND ct.contextlevel = ? AND r.shortname like ? AND (CONCAT( u.firstname, ' ', u.lastname) like ? OR c.fullname like ?)
						GROUP BY c.id
						ORDER BY r.id ASC";
		}
		$parametros = array_merge($parametros1, $filter);
		$courses = $DB->get_records_sql($sqlcourses, $parametros);
		echo json_encode($courses);
		break;
	//This case returns the course data to add it to the cart list
	case 'cartlist':
		require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
		$return = array();
		$course = $DB->get_record("course", array("id" => $courseid)); //Get the course by id
		if($teacherid!=1){
			$return['courseid'] = $courseid;
			$return['course'] = $course->fullname;
			$return['descriptionid'] = 0;
			$return['description'] = paperattendance_returnattendancedescription(false, 0);

			$requestorinfo = $DB->get_record("user", array("id" => $teacherid));
			$return['requestor'] = $requestorinfo->firstname." ".$requestorinfo->lastname;
			$return['requestorid'] = $teacherid;
		}
		//This is to check modules from omega
		if (paperattendance_checktoken($CFG->paperattendance_omegatoken)){
			//CURL get modulos horario
			$curl = curl_init();

			$url = $CFG->paperattendance_omegagetmoduloshorariosurl;
			$token = $CFG->paperattendance_omegatoken;

			$fields = array (
					"diaSemana" => $diasemana,
					"seccionId" => $course -> idnumber,
					"token" => $token
			);

			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($curl, CURLOPT_POST, TRUE);
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
			curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
			$result = curl_exec ($curl);
			curl_close ($curl);

			$modules = array();
			$modules = json_decode($result);
			if(count($modules) == 0){
				$return['modules'] = false;
			}
			else{
				$return['modules'] = $modules;
			}

			echo json_encode($return);
		}
		break;
		case 'getliststudentspage':
			require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
			
			$return = array();
			$originaldate = $date;
			$date = explode("-",$date);
			if(checkdate($date[1],$date[0],$date[2])){
			
				if($moduledata = $DB->get_record("paperattendance_module", array("initialtime" => $module))){
					
					if($course = $DB->get_record("course", array("shortname" => $data))){
					
						$context = context_course::instance($course->id);
						$studentlist = paperattendance_get_printed_students_missingpages($moduledata->id, $course->id, strtotime($originaldate));
						
						if(count($studentlist) >= $begin){
							$arrayalumnos = array();
							$count = 1;
							$end = $begin + 25;
							foreach ($studentlist as $student){
								if($count>=$begin && $count<=$end){
									$studentobject = $DB->get_record("user", array("id" => $student->id));
									$line = array();
									$line["studentid"] = $student->id;
									$line["username"] = $studentobject->lastname.", ".$studentobject->firstname;
									
									$arrayalumnos[] = $line;
								}
								$count++;
							}
							$return["error"] = 0;
							$return["alumnos"] = $arrayalumnos;
							echo json_encode($return);
						}
						else{
							$return["error"] = get_string("incorrectlistinit","local_paperattendance");
							echo json_encode($return);
						}
					}
					else{
						$return["error"] = get_string("coursedoesntexist","local_paperattendance");
						echo json_encode($return);
					}
				}else{
					$return["error"] = get_string("incorrectmoduleinit","local_paperattendance");
					echo json_encode($return);
				}
			}else{
				$return["error"] = get_string("incorrectdate","local_paperattendance");
				echo json_encode($return);
			}
		break;
		//This case is to change an student attendance with ajax
		case 'changestudentpresence':
			require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
			
			if($attendance = $DB->get_record("paperattendance_presence", array("id" => $presenceid)) ){
				
				$record = new stdClass();
				$record->id = $presenceid;
				$record->lastmodified = time();
				$record->status = $setstudentpresence;
				$omegaid = $attendance -> omegaid;
				$DB->update_record("paperattendance_presence", $record);
				
				if(paperattendance_checktoken($CFG->paperattendance_omegatoken)){
				
					$modifieduserid = $attendance -> userid;
					$initialtime = time();
					$curl = curl_init();
					
					$url =  $CFG->paperattendance_omegaupdateattendanceurl;
					$token =  $CFG->paperattendance_omegatoken;
					
					if($setstudentpresence == 1){
						$status = "true";
					}
					else{
						$status = "false";
					}
					
					$fields = array (
							"token" => $token,
							"asistenciaId" => $omegaid,
							"asistencia" => $status
					);
					
					curl_setopt($curl, CURLOPT_URL, $url);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
					curl_setopt($curl, CURLOPT_POST, TRUE);
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
					curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
					$result = curl_exec ($curl);
					curl_close ($curl);
					$executiontime = time() - $initialtime;
					paperattendance_cronlog($url, $result, time(), $executiontime);
				}	
			}
			
			echo json_encode("presenceid:".$presenceid." omegaid:".$omegaid."status:".$setstudentpresence);
			break;
		case 'savestudentsattendance':
			$sessinfo = $_REQUEST['sessinfo']; 
			$sessinfo = json_decode ($sessinfo);
			$studentsattendance = $_REQUEST['studentsattendance'];
			$studentsattendance = json_decode ($studentsattendance);
					
			require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
			

			//$return["arregloinicialalumnos"] = print_r($studentsattendance, true);
	
			$sesspageid = $sessinfo[0] -> sesspageid;
			$shortname = $sessinfo[0] -> shortname;
			$date = $sessinfo[0] -> date;
			$module = $sessinfo[0] -> module;
			$begin = (int) $sessinfo[0] -> begin;
			
			$numberpage =  ($begin + 25)/26;
			
			$sesspageobject = $DB->get_record("paperattendance_sessionpages", array("id"=> $sesspageid));
			$courseobject = $DB->get_record("course", array("shortname"=> $shortname));
			$moduleobject = $DB->get_record("paperattendance_module", array("initialtime"=> $module));
			
			$sessdoesntexist = paperattendance_check_session_modules($moduleobject->id, $courseobject->id, strtotime($date));
			//mtrace("checking session: ".$sessdoesntexist);
			$stop = true;
			$return = array();
			//$return["sesiondos"] = "";
			$return["guardar"] = "";
			//$return["omegatoken"] = "";
			$return["omegatoken2"] = "";
			if( $sessdoesntexist == "perfect"){
				//mtrace("Session doesn't exists");
				//$return["sesion"] = "La sesión no existe";
				
				//Query to select teacher from a course
				$teachersquery = "SELECT u.id AS userid,
							c.id AS courseid,
							e.enrol,
							CONCAT(u.firstname, ' ', u.lastname) AS name
							FROM {user} u
							INNER JOIN {user_enrolments} ue ON (ue.userid = u.id)
							INNER JOIN {enrol} e ON (e.id = ue.enrolid)
							INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
							INNER JOIN {context} ct ON (ct.id = ra.contextid)
							INNER JOIN {course} c ON (c.id = ct.instanceid AND e.courseid = c.id)
							INNER JOIN {role} r ON (r.id = ra.roleid)
							WHERE r.id = $CFG->paperattendance_profesoreditorrole AND c.id = ? AND e.enrol = 'database'";
				
				$teachers = $DB->get_records_sql($teachersquery, array($courseobject->id));
				
				$enrolincludes = explode("," ,$CFG->paperattendance_enrolmethod);
				
				foreach ($teachers as $teacher){
					
					$enrolment = explode(",", $teacher->enrol);
					// Verifies that the teacher is enrolled through a valid enrolment and that we haven't added him yet.
					if (count(array_intersect($enrolment, $enrolincludes)) == 0 || isset($arrayteachers[$teacher->userid])) {
						continue;
					}
					$requestor = $teacher->userid;
				}
				$description = 0; //0 -> Indicates normal class
				$sessid = paperattendance_insert_session($courseobject->id, $requestor, $USER->id, $sesspageobject->pdfname, $description, 0);
				//mtrace("el id de la sesión es : ".$sessid);
				paperattendance_insert_session_module($moduleobject->id, $sessid, strtotime($date));
				
				$pagesession = new stdClass();
				$pagesession->id = $sesspageid;
				$pagesession->sessionid = $sessid;
				$pagesession->pagenum = $sesspageobject->pagenum;
				$pagesession->qrpage = $numberpage;
				$pagesession->pdfname = $sesspageobject->pdfname;
				$pagesession->processed = 1;
				$pagesession->uploaderid = $USER->id;
				$DB->update_record('paperattendance_sessionpages', $pagesession);
			
			}
			else{
				//mtrace("Session already exists");
				//$return["sesion"] = "la sesión ya existe, ";
				$sessid = $sessdoesntexist; //if session exist, then $sessdoesntexist contains the session id
				
				//Check if the page already was processed
				if( $DB->record_exists('paperattendance_sessionpages', array('sessionid'=>$sessid,'qrpage'=>$numberpage)) ){
					//mtrace("This session already exists and was already uploaded and processed / the entered course isn't the same than the existing session");
					$return["guardar"] = "Hoja procesada anteriormente.";
					
					$stop = false;
				}
				else{
					//To process a page that it session was already created but the page wasn't processed yet
					$pagesession = new stdClass();
					$pagesession->id = $sesspageid;
					$pagesession->sessionid = $sessid;
					$pagesession->pagenum = $sesspageobject->pagenum;
					$pagesession->qrpage = $numberpage;
					$pagesession->pdfname = $sesspageobject->pdfname;
					$pagesession->processed = 1;
					$pagesession->uploaderid = $USER->id;
					$DB->update_record('paperattendance_sessionpages', $pagesession);
					//mtrace("Session already exists but this page had not be uploaded nor processed");
				//	$return["sesiondos"] = "Hoja no procesada antes, ";
					$stop = true;
				}
			}
			if($stop){
				
				$arrayalumnos = array();
				$init = ($numberpage-1)*26+1; 
				$end = $numberpage*26;  
				$count = $init; //start at one because init starts at one

				foreach ($studentsattendance as $student){
					//$return["sesion"] = "entre al foreach";
					if($count>=$init && $count<=$end){
						//$return["sesion"] = "entre al foreach y deberia estar guardando a alguien S:";
						$line = array();
						$line['emailAlumno'] = paperattendance_getusername($student -> userid);
						$line['resultado'] = "true";
						$line['asistencia'] = "false";
						
						if($student -> presence == '1'){
							paperattendance_save_student_presence($sessid, $student -> userid, '1', NULL);
							$line['asistencia'] = "true";
						}
						else{
							paperattendance_save_student_presence($sessid, $student -> userid, '0', NULL);
						}
						
						$arrayalumnos[] = $line;
					}
					$count++;
				}
				$return["guardar"] = "Asistencia guardada por cada alumno. ";
				$omegasync = false;
				
				if(paperattendance_checktoken($CFG->paperattendance_omegatoken)){
					//$return["omegatoken"] = "Api aceptó token, ";
					//$return["arregloalumnos"] = print_r($arrayalumnos, true);
					//$return["idcurso"] = print_r($courseobject->id, true);
					//$return["idsesion"] = print_r($sessid,true);
					if(paperattendance_omegacreateattendance($courseobject->id, $arrayalumnos, $sessid)){
						$omegasync = true;
						$return["omegatoken2"] = "Se creó la asistencia en Omega. ";
					}else{
						$return["omegatoken2"] = "No se creó la asistencia en Omega. ";
					}
				}
				
				$update = new stdClass();
				$update->id = $sessid;
				if($omegasync){
					$update->status = 2;
				}
				else{
					$update->status = 1;
				}
				$DB->update_record("paperattendance_session", $update);
				
			}
			echo json_encode($return);
			break;
			
		case 'checkprocesspage':
			$sessinfo = $_REQUEST['sessinfo'];
			$sessinfo = json_decode ($sessinfo);
			
			require_once($CFG->dirroot . '/local/paperattendance/locallib.php');			
			
			$sesspageid = $sessinfo[0] -> sesspageid;
			$shortname = $sessinfo[0] -> shortname;
			$date = $sessinfo[0] -> date;
			$module = $sessinfo[0] -> module;
			$begin = (int) $sessinfo[0] -> begin;
			
			$numberpage =  ($begin + 25)/26;
			
			$sesspageobject = $DB->get_record("paperattendance_sessionpages", array("id"=> $sesspageid));
			$courseobject = $DB->get_record("course", array("shortname"=> $shortname));
			$moduleobject = $DB->get_record("paperattendance_module", array("initialtime"=> $module));
			
			$sessdoesntexist = paperattendance_check_session_modules($moduleobject->id, $courseobject->id, strtotime($date));
			//mtrace("checking session: ".$sessdoesntexist);
			$return = array();
			$return["process"] = "";
			if( $sessdoesntexist == "perfect"){
				//mtrace("Session doesn't exists");
				$return["process"] = 0;			
			}
			else{
				//mtrace("Session already exists");
				//$return["sesion"] = "la sesión ya existe, ";
				$sessid = $sessdoesntexist; //if session exist, then $sessdoesntexist contains the session id
				
				//Check if the page already was processed
				if( $DB->record_exists('paperattendance_sessionpages', array('sessionid'=>$sessid,'qrpage'=>$numberpage)) ){
					//mtrace("This session already exists and was already uploaded and processed / the entered course isn't the same than the existing session");
					$return["process"] = "Hoja procesada anteriormente.";

				}
				else{					
					//mtrace("Session already exists but this page had not be uploaded nor processed");
					$return["process"] = 0;
				}
			}
			echo json_encode($return);
			break;
			//This case is to change all student attendance with ajax
		case 'changeallpresence':
			require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
			$sessinfo = $_REQUEST['sessinfo'];
			$sessinfo = json_decode ($sessinfo);
			$studentspresenceinfo= $_REQUEST['studentspresenceinfo'];
			$studentspresenceinfo= json_decode ($studentspresenceinfo);
			
			$sessid = $sessinfo[0] -> sessid;
			$courseid = $sessinfo[0] -> courseid;
			$setpresence = $sessinfo[0] -> setpresence;
			
			//$return["arregloinicialalumnos"] = print_r($studentspresenceinfo, true);
			$arrayalumnos = array();
			
			//Here we are gonna make the array with every student to send to omegacreatattendance function located in localib for the attendance update
			//And we are gonna update every student attendace in database to all present or all absent depending on the value of setpresent
			foreach ($studentspresenceinfo as $student){
				$return["sesion"] = "entre al foreach";
				$presenceid = $student->presenceid;
				if($attendance = $DB->get_record("paperattendance_presence", array("id" => $presenceid))){
					$return["sesion"] = "entre al foreach y deberia estar guardando a alguien S:";
					$line = array();
					$line['emailAlumno'] = $student -> email;
					$line['resultado'] = "true";
					$line['asistencia'] = "false";
					
					$record = new stdClass();
					$record->id = $presenceid;
					$record->lastmodified = time();
					
					if($setpresence == '1'){
						$record->status = 1;
						$DB->update_record("paperattendance_presence", $record);
						
						$line['asistencia'] = "true";
					}
					else{
						$record->status = 0;
						$DB->update_record("paperattendance_presence", $record);
					}
					
					$arrayalumnos[] = $line;
				}
			}
			if($setpresence == '1'){
				$setpresence = 0;
			}
			else{
				$setpresence = 1;
			}
			$return["setpresence"] = $setpresence;
			$return["guardar"] = "Asistencia guardada por cada alumno. ";
			$omegasync = false;
			
			if(paperattendance_checktoken($CFG->paperattendance_omegatoken)){
				$return["omegatoken"] = "Api aceptó token, ";
				$return["arregloalumnos"] = print_r($arrayalumnos, true);
				$return["idcurso"] = print_r($courseid, true);
				$return["idsesion"] = print_r($sessid,true);
				if(paperattendance_omegacreateattendance($courseid, $arrayalumnos, $sessid)){
					$omegasync = true;
					$return["omegatoken2"] = "Se creó la asistencia en Omega. ";
				}else{
					$return["omegatoken2"] = "No se creó la asistencia en Omega. ";
				}
			}
			
			$update = new stdClass();
			$update->id = $sessid;
			if($omegasync){
				$update->status = 2;
			}
			else{
				$update->status = 1;
			}
			$DB->update_record("paperattendance_session", $update);
			
			echo json_encode($return);
			break;
			
		// this case is for insert students in the list
		case 'saveinsertstudent':
			require_once($CFG->dirroot . '/local/paperattendance/locallib.php');
			$sessinfo = $_REQUEST['sessinfo'];
			$sessinfo = json_decode ($sessinfo);
			$studentsattendance= $_REQUEST['studentsattendance'];
			$studentsattendance= json_decode ($studentsattendance);
			
			$sessid = $sessinfo[0] -> sessid;
			$courseid = $sessinfo[0] -> courseid;
			
			//$return["arregloinicialalumnos"] = print_r($studentspresenceinfo, true);
			$arrayalumnos = array();
			
			//Here we are gonna make the array with every student to send to omegacreatattendance function located in localib for the attendance creation
			foreach ($studentsattendance as $student){
				//$return["sesion"] = "entre al foreach y deberia estar guardando a alguien S:";
				$line = array();
				$line['emailAlumno'] = $student -> email;
				$line['resultado'] = "true";
				$line['asistencia'] = "true";
				paperattendance_save_student_presence($sessid, $student -> userid, '1', NULL);
				$arrayalumnos[] = $line;
			}
			
			$return["guardar"] = "Asistencia guardada por cada alumno. ";
			$omegasync = false;
			
			if(paperattendance_checktoken($CFG->paperattendance_omegatoken)){
				$return["omegatoken"] = "Api aceptó token, ";
				$return["arregloalumnos"] = print_r($arrayalumnos, true);
				$return["idcurso"] = print_r($courseid, true);
				$return["idsesion"] = print_r($sessid,true);
				if(paperattendance_omegacreateattendance($courseid, $arrayalumnos, $sessid)){
					$omegasync = true;
					$return["omegatoken2"] = "Se creó la asistencia en Omega. ";
				}else{
					$return["omegatoken2"] = "No se creó la asistencia en Omega. ";
				}
			}
			
			$update = new stdClass();
			$update->id = $sessid;
			if($omegasync){
				$update->status = 2;
			}
			else{
				$update->status = 1;
			}
			$DB->update_record("paperattendance_session", $update);
			
		echo json_encode($return);
		break;
}
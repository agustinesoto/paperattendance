<?php
/**
 *
 */

define('AJAX_SCRIPT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once "$CFG->dirroot/local/paperattendance/locallib.php";

require_login();
if (isguestuser()) {
    die();
}

global $DB, $USER;

$sessinfo = $_REQUEST['sessinfo'];
$sessinfo = json_decode($sessinfo);
$studentsattendance = $_REQUEST['studentsattendance'];
$studentsattendance = json_decode($studentsattendance);

$sesspageid = $sessinfo[0]->sesspageid;
$shortname = $sessinfo[0]->shortname;
$date = $sessinfo[0]->date;
$module = $sessinfo[0]->module;
$begin = (int) $sessinfo[0]->begin;

$numberpage = ($begin + 25) / 26;

$sesspageobject = $DB->get_record("paperattendance_sessionpages", array("id" => $sesspageid));
$courseobject = $DB->get_record("course", array("shortname" => $shortname));
$moduleobject = $DB->get_record("paperattendance_module", array("initialtime" => $module));

$sessdoesntexist = paperattendance_check_session_modules($moduleobject->id, $courseobject->id, strtotime($date));
//mtrace("checking session: ".$sessdoesntexist);
$stop = true;
$return = array();
//$return["sesiondos"] = "";
$return["guardar"] = "";
//$return["omegatoken"] = "";
$return["omegatoken2"] = "";
if ($sessdoesntexist == "perfect") {
    //mtrace("Session doesn't exists");
    //$return["sesion"] = "La sesión no existe";

    //Query to select teacher from a course
    $teachersquery =
        "SELECT u.id AS userid,
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
        WHERE r.id = 3 AND c.id = ? AND e.enrol = 'database'";

    $teachers = $DB->get_records_sql($teachersquery, array($courseobject->id));

    $enrolincludes = explode(",", $CFG->paperattendance_enrolmethod);

    foreach ($teachers as $teacher) {

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
} else {
    //mtrace("Session already exists");
    //$return["sesion"] = "la sesión ya existe, ";
    $sessid = $sessdoesntexist; //if session exist, then $sessdoesntexist contains the session id

    //Check if the page already was processed
    if ($DB->record_exists('paperattendance_sessionpages', array('sessionid' => $sessid, 'qrpage' => $numberpage))) {
        //mtrace("This session already exists and was already uploaded and processed / the entered course isn't the same than the existing session");
        $return["guardar"] = "Hoja procesada anteriormente.";

        $stop = false;
    } else {
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
        //    $return["sesiondos"] = "Hoja no procesada antes, ";
        $stop = true;
    }
}

if ($stop) {
    $arrayalumnos = array();
    $init = ($numberpage - 1) * 26 + 1;
    $end = $numberpage * 26;
    $count = $init; //start at one because init starts at one

    foreach ($studentsattendance as $student) {
        //$return["sesion"] = "entre al foreach";
        if ($count >= $init && $count <= $end) {
            //$return["sesion"] = "entre al foreach y deberia estar guardando a alguien S:";
            $line = array();
            $line['emailAlumno'] = paperattendance_getusername($student->userid);
            $line['resultado'] = "true";
            $line['asistencia'] = "false";

            if ($student->presence == '1') {
                paperattendance_save_student_presence($sessid, $student->userid, '1', null);
                $line['asistencia'] = "true";
            } else {
                paperattendance_save_student_presence($sessid, $student->userid, '0', null);
            }

            $arrayalumnos[] = $line;
        }
        $count++;
    }
    $return["guardar"] = "Asistencia guardada por cada alumno. ";
    $omegasync = false;

    if (paperattendance_omegacreateattendance($courseobject->id, $arrayalumnos, $sessid)) {
        $omegasync = true;
        $return["omegatoken2"] = "Se creó la asistencia en Omega. ";
    } else {
        $return["omegatoken2"] = "No se creó la asistencia en Omega. ";
    }

    $update = new stdClass();
    $update->id = $sessid;
    if ($omegasync) {
        $update->status = 2;
    } else {
        $update->status = 1;
    }
    $DB->update_record("paperattendance_session", $update);
}
echo json_encode($return);

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

global $DB;

$sessinfo = $_REQUEST['sessinfo'];
$sessinfo = json_decode($sessinfo);
$studentspresenceinfo = $_REQUEST['studentspresenceinfo'];
$studentspresenceinfo = json_decode($studentspresenceinfo);

$sessid = $sessinfo[0]->sessid;
$courseid = $sessinfo[0]->courseid;
$setpresence = $sessinfo[0]->setpresence;

$arrayalumnos = array();

//Here we are gonna make the array with every student to send to omegacreatattendance function located in localib for the attendance update
//And we are gonna update every student attendace in database to all present or all absent depending on the value of setpresent
foreach ($studentspresenceinfo as $student) {
    $return["sesion"] = "entre al foreach";
    $presenceid = $student->presenceid;
    if ($attendance = $DB->get_record("paperattendance_presence", array("id" => $presenceid))) {
        $return["sesion"] = "entre al foreach y deberia estar guardando a alguien S:";
        $line = array();
        $line['emailAlumno'] = $student->email;
        $line['resultado'] = "true";
        $line['asistencia'] = "false";

        $record = new stdClass();
        $record->id = $presenceid;
        $record->lastmodified = time();

        if ($setpresence == '1') {
            $record->status = 1;
            $DB->update_record("paperattendance_presence", $record);

            $line['asistencia'] = "true";
        } else {
            $record->status = 0;
            $DB->update_record("paperattendance_presence", $record);
        }

        $arrayalumnos[] = $line;
    }
}
if ($setpresence == '1') {
    $setpresence = 0;
} else {
    $setpresence = 1;
}
$return["setpresence"] = $setpresence;
$return["guardar"] = "Asistencia guardada por cada alumno. ";
$omegasync = false;

$return["arregloalumnos"] = print_r($arrayalumnos, true);
$return["idcurso"] = print_r($courseid, true);
$return["idsesion"] = print_r($sessid, true);
if (paperattendance_omegacreateattendance($courseid, $arrayalumnos, $sessid)) {
    $omegasync = true;
    $return["omegatoken"] = "Api aceptó token, ";
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

echo json_encode($return);

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

$sessinfo = $_REQUEST['sessinfo'];
$sessinfo = json_decode($sessinfo);
$studentsattendance = $_REQUEST['studentsattendance'];
$studentsattendance = json_decode($studentsattendance);

$sessid = $sessinfo[0]->sessid;
$courseid = $sessinfo[0]->courseid;

$arrayalumnos = array();

//Here we are gonna make the array with every student to send to omegacreatattendance function located in localib for the attendance creation
foreach ($studentsattendance as $student) {
    //$return["sesion"] = "entre al foreach y deberia estar guardando a alguien S:";
    $line = array();
    $line['emailAlumno'] = $student->email;
    $line['resultado'] = "true";
    $line['asistencia'] = "true";
    paperattendance_save_student_presence($sessid, $student->userid, '1', null);
    $arrayalumnos[] = $line;
}

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

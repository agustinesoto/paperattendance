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

$courseid = required_param("courseid", PARAM_INT);
$diasemana = required_param("diasemana", PARAM_TEXT);
$teacherid = required_param("teacherid", PARAM_INT);

$return = array();
$course = $DB->get_record("course", array("id" => $courseid));

if ($teacherid != 1) {
    $return['courseid'] = $courseid;
    $return['course'] = $course->fullname;
    $return['descriptionid'] = 0;
    $return['description'] = paperattendance_returnattendancedescription(false, 0);

    $requestorinfo = $DB->get_record("user", array("id" => $teacherid));
    $return['requestor'] = $requestorinfo->firstname . " " . $requestorinfo->lastname;
    $return['requestorid'] = $teacherid;
}

$url = $CFG->paperattendance_omegagetmoduloshorariosurl;

$fields = array(
    "diaSemana" => $diasemana,
    "seccionId" => $course->idnumber,
);

$result = paperattendance_curl($url, $fields, false);

$modules = array();
$modules = json_decode($result);
if (count($modules) == 0) {
    $return['modules'] = false;
} else {
    $return['modules'] = $modules;
}

echo json_encode($return);

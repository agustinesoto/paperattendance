<?php
/**
 * Change the presence of a single student
 * Since we use the presenceid we dont need the class or session id
 */

define('AJAX_SCRIPT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once "$CFG->dirroot/local/paperattendance/locallib.php";

require_login();
if (isguestuser()) {
    die();
}

global $DB;

$presenceid = required_param("presenceid", PARAM_INT);
$setstudentpresence = required_param("setstudentpresence", PARAM_INT);

if ($attendance = $DB->get_record("paperattendance_presence", array("id" => $presenceid))) {
    $record = new stdClass();
    $record->id = $presenceid;
    $record->lastmodified = time();
    $record->status = $setstudentpresence;
    $omegaid = $attendance->omegaid;
    $DB->update_record("paperattendance_presence", $record);

    //dont try to update if there is no omegaid
    if ($omegaid) {
        $url = $CFG->paperattendance_omegaupdateattendanceurl;

        if ($setstudentpresence == 1) {
            $status = "true";
        } else {
            $status = "false";
        }

        $fields = array(
            "asistenciaId" => $omegaid,
            "asistencia" => $status,
        );

        curl($url, $fields);
    }
}

echo json_encode("presenceid:" . $presenceid . " omegaid:" . $omegaid . "status:" . $setstudentpresence);

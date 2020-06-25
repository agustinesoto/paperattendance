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

$date = required_param("date", PARAM_TEXT);
$result = required_param("result", PARAM_TEXT);
$begin = required_param("begin", PARAM_INT);
$module = required_param("module", PARAM_TEXT);

$return = array();
$originaldate = $date;
$date = explode("-", $date);
if (checkdate($date[1], $date[0], $date[2])) {

    if ($moduledata = $DB->get_record("paperattendance_module", array("initialtime" => $module))) {

        if ($course = $DB->get_record("course", array("shortname" => $result))) {

            $context = context_course::instance($course->id);
            $studentlist = paperattendance_get_printed_students_missingpages($moduledata->id, $course->id, strtotime($originaldate));

            if (count($studentlist) >= $begin) {
                $arrayalumnos = array();
                $count = 1;
                $end = $begin + 25;
                foreach ($studentlist as $student) {
                    if ($count >= $begin && $count <= $end) {
                        $studentobject = $DB->get_record("user", array("id" => $student->id));
                        $line = array();
                        $line["studentid"] = $student->id;
                        $line["username"] = $studentobject->lastname . ", " . $studentobject->firstname;

                        $arrayalumnos[] = $line;
                    }
                    $count++;
                }
                $return["error"] = 0;
                $return["alumnos"] = $arrayalumnos;
                echo json_encode($return);
            } else {
                $return["error"] = get_string("incorrectlistinit", "local_paperattendance");
                echo json_encode($return);
            }
        } else {
            $return["error"] = get_string("coursedoesntexist", "local_paperattendance");
            echo json_encode($return);
        }
    } else {
        $return["error"] = get_string("incorrectmoduleinit", "local_paperattendance");
        echo json_encode($return);
    }
} else {
    $return["error"] = get_string("incorrectdate", "local_paperattendance");
    echo json_encode($return);
}

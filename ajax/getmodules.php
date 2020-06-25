<?php
/**
 * Reads modules from Omega for a course
 */

define('AJAX_SCRIPT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once "$CFG->dirroot/local/paperattendance/locallib.php";

require_login();
if (isguestuser()) {
    die();
}

global $DB, $USER;

$courseid = required_param("courseid", PARAM_INT);
$omegaid = required_param("omegaid", PARAM_TEXT);
$diasemana = required_param("diasemana", PARAM_TEXT);
$category = optional_param("category", $CFG->paperattenadance_categoryid, PARAM_INT);

if ($courseid > 1) {
    if ($course = $DB->get_record("course", array("id" => $courseid))) {
        if ($course->idnumber != null) {
            $context = context_coursecat::instance($course->category);
        }
    } else {
        $context = context_system::instance();
    }
} elseif ($category > 1) {
    $context = context_coursecat::instance($category);
} else {
    $context = context_system::instance();
}

$isteacher = paperattendance_getteacherfromcourse($courseid, $USER->id);

if (!has_capability("local/paperattendance:printsecre", $context) && !$isteacher && !is_siteadmin($USER) && !has_capability("local/paperattendance:print", $context)) {
    print_error(get_string('notallowedprint', 'local_paperattendance'));
}

$url = $CFG->paperattendance_omegagetmoduloshorariosurl;

$fields = array(
    "diaSemana" => $diasemana,
    "seccionId" => $omegaid,
);

echo paperattendance_curl($url, $fields, false);

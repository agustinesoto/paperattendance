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
$return = array();
$return["process"] = "";
if ($sessdoesntexist == "perfect") {
    //if the session doesnt exist
    $return["process"] = 0;
} else {
    //if the session already exists
    //if session exist, then $sessdoesntexist contains the session id
    $sessid = $sessdoesntexist;

    //Check if the page already was processed
    if ($DB->record_exists('paperattendance_sessionpages', array('sessionid' => $sessid, 'qrpage' => $numberpage))) {
        //mtrace("This session already exists and was already uploaded and processed / the entered course isn't the same than the existing session");
        $return["process"] = "Hoja procesada anteriormente.";
    } else {
        //mtrace("Session already exists but this page had not be uploaded nor processed");
        $return["process"] = 0;
    }
}
echo json_encode($return);

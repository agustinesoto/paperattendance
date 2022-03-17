<?php
/**
 *
 */

define('AJAX_SCRIPT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';

require_login();
if (isguestuser()) {
    die();
}

global $DB;

$category = optional_param("category", $CFG->paperattendance_categoryid, PARAM_INT);
$result = required_param("result", PARAM_TEXT);
$paths = required_param("path", PARAM_TEXT);

if ($category > 1) {
    $context = context_coursecat::instance($category);
} else {
    $context = context_system::instance();
}

$contextsystem = context_system::instance();
if (!has_capability('local/paperattendance:printsearch', $context) && !has_capability('local/paperattendance:printsearch', $contextsystem)) {
    print_error(get_string('notallowedprint', 'local_paperattendance'));
}

/**
 * parameters for the query:
 * sqlin  -> enrol methods
 * param2 -> contextlevel, role shortname
 * filter -> input from user (course fullname or teacher name)
 */

$filter = array("%" . $result . "%", $result . "%");
$enrolincludes = explode(",", $CFG->paperattendance_enrolmethod);
list($sqlin, $param1) = $DB->get_in_or_equal($enrolincludes);
$param2 = array(
    50,
    '%profesoreditor%',
);
$parametros1 = array_merge($param1, $param2);

//If is site admin he can see courses from all categories
if (is_siteadmin()) {
    //Query without date filter
    $sqlcourses =
        "SELECT c.id,
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
        ORDER BY r.id ASC"
    ;
} else {
    //If user is a secretary, he can see only courses from his categorie
    $paths = unserialize(base64_decode($paths));
    $pathscount = count($paths);
    $like = "";
    $counter = 1;
    foreach ($paths as $path) {
        $searchquery = "cat.path like '%/" . $path . "/%' OR cat.path like '%/" . $path . "'";
        if ($counter == $pathscount) {
            $like .= $searchquery;
        } else {
            $like .= $searchquery . " OR ";
        }
        $counter++;
    }
    $sqlcourses =
        "SELECT c.id,
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

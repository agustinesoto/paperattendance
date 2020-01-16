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
 * @package    local
 * @subpackage paperattendance
 * @copyright  2016 Matías Queirolo (mqueirolo@alumnos.uai.cl)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

//This doesnt actually remove the PDF's, only the images

define('CLI_SCRIPT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once $CFG->dirroot . '/local/paperattendance/locallib.php';
require_once $CFG->libdir . '/clilib.php';

global $CFG;

// Now get cli options
list($options, $unrecognized) = cli_get_params(
    array('help' => false),
    array('h' => 'help')
);
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}
// Text to the paperattendance console
if ($options['help']) {
    $help =
    // Todo: localize - to be translated later when everything is finished
    "Delete pdf located at folder print on moodle file system.
	Options:
	-h, --help            Print out this help
	Example:
	\$/usr/bin/php /local/paperattendance/cli/deletepdf.php";
    echo $help;
    die();
}
//heading
cli_heading('Paper Attendance pdf deleting'); // TODO: localize
echo "\nSearching for print pdfs\n";
echo "\nStarting at " . date("F j, Y, G:i:s") . "\n";

$initialtime = time();

$path = $CFG->dataroot . "/temp/local/paperattendance/print/";
$pathpng = $CFG->dataroot . "/temp/local/paperattendance/unread/";

//call de function to delete the files from the print folder in moodledata
if (file_exists($path)) {
    paperattendance_recursiveremovedirectory($path);
    echo "\nall files deleted from the print folder";

} else {
    echo "\nerror, files not deleted";

}
if (file_exists($pathpng)) {
    paperattendance_recursiveremove($pathpng, 'jpg');
    echo "\nall files deleted from the unread folder";

} else {
    echo "\nerror, files not deleted";
}

/*
//Delete files in unread, which are all the uploaded PDFs which are never cleaned
//We will delete all files except the ones in this array
$names = array();

//Get all unprocessed names
$table = "paperattendance_unprocessed";
$records = $DB->get_records($table);

foreach($records as $record)
{
    $names[] = $record->filename;
}

//Get all lost pages
$table = "paperattendance_sessionpages";
$conditions = array(
    "sessionid" => null
);
$records = $DB->get_records($table, $conditions);

foreach($records as $record)
{
    $names[] = $record->pdfname;
}

echo "\nUnlinking unused PDFs";

foreach (glob("{$pathpng}/*") as $file) {
    $filename = explode("/", $file);
    $filename = array_pop($filename);

    if(!in_array($filename, $names))
    {
        echo("Unlinking $filename");
        unlink($file);
    }
}*/

//finish
// Displays the time required to complete the process
$finaltime = time();
$executiontime = $finaltime - $initialtime;

echo "\nExecution time: " . $executiontime . " seconds. \n";

exit(0);

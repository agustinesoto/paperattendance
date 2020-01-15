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
 *
 * @package    local
 * @subpackage paperattendance
 * @copyright  2017 Jorge Cabané (jcabane@alumnos.uai.cl)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php';
require_once $CFG->dirroot . '/local/paperattendance/locallib.php';
require_once $CFG->dirroot . "/repository/lib.php";
require_once $CFG->libdir . '/pdflib.php';
require_once $CFG->libdir . '/clilib.php';
require_once $CFG->dirroot . '/mod/assign/feedback/editpdf/fpdi/fpdi.php';
require_once $CFG->dirroot . "/mod/assign/feedback/editpdf/fpdi/fpdi_bridge.php";

global $DB;

// Now get cli options
list($options, $unrecognized) = cli_get_params(array(
    'help' => false,
    'debug' => false,
), array(
    'h' => 'help',
    'd' => 'debug',
));
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}
// Text to the paperattendance console
if ($options['help']) {
    $help =
    // Todo: localize - to be translated later when everything is finished
    "Process pdf located at folder unread on moodle file system.
	Options:
	-h, --help            Print out this help
	Example:
	\$sudo /usr/bin/php /local/paperattendance/cli/processpdf.php";
    echo $help;
    die();
}
//heading
cli_heading('Paper Attendance pdf processing'); // TODO: localize
echo "\nSearching for unread pdfs\n";
echo "\nStarting at " . date("F j, Y, G:i:s") . "\n";

$initialtime = time();
$read = 0;
$found = 0;

$DB->execute('SET SESSION wait_timeout = 28800');
$DB->execute('SET SESSION interactive_timeout = 28800');

mtrace("Start process pdf csv: " . memory_get_usage() . "bytes \n");

// Sql that brings the unread pdfs names
$sqlunreadpdfs =
    "SELECT  id, filename AS name, uploaderid AS userid
	FROM {paperattendance_unprocessed}
	ORDER BY lastmodified ASC";

// Read the pdfs if there is any unread, with readpdf function
if ($resources = $DB->get_records_sql($sqlunreadpdfs, array())) {
    $path = "$CFG->dataroot/temp/local/paperattendance/unread";

    mtrace("Query find data correctly");

    foreach ($resources as $pdf) {
        $found++;
        mtrace("Found " . $found . " pdfs");

        $filename = $pdf->name;

        $uploaderobj = $DB->get_record("user", array("id" => $pdf->userid));

        $pagesWithErrors = array();

		//split pdf into multiple jpegs
		//this way we can process all pages independently and format the images beforehand
        $image = new Imagick();

        $image->setResolution(300, 300);
        $image->readImage("$path/$filename");
        $image->setImageFormat('jpeg');
        $image->setImageCompression(Imagick::COMPRESSION_JPEG);
        $image->setImageCompressionQuality(100);

        if ($image->getImageAlphaChannel()) {
            for ($i = 0; $i < $image->getNumberImages(); $i++) {
                //we actually start at the end of the array, so we need to move backwards to remove the alpha of each image
                $image->previousImage();
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $image->setImageBackgroundColor('white');
            }
        }

        if (!file_exists("$path/jpgs")) {
            mkdir("$path/jpgs", 0777, true);
		}
		
        //Remove initial jpgs in the directory
		paperattendance_recursiveremove("$path/jpgs", "jpg");

        $pdfname = explode(".", $filename);
        $pdfname = $pdfname[0];

        $image->writeImages("$path/jpgs/$pdfname.jpg", false);
        $image->destroy();
        unset($image);

        if (!file_exists("$path/jpgs/processing")) {
            mkdir("$path/jpgs/processing", 0777, true);
        }

        //Remove initial jpgs in the directory
        paperattendance_recursiveremove("$path/jpgs/processing", "jpg");

        //Remove initial csv in the directory
        paperattendance_recursiveremove("$path/jpgs/processing", "csv");

        //process jpgs one by one and then delete it
        $countprocessed = 0;
        foreach (glob("{$path}/jpgs/*.jpg") as $file) {
            //first move it to the processing folder
            $jpgname = basename($file);
            mtrace("el nombre del jpg recien sacado es: " . $jpgname);
            rename($file, $path . "/jpgs/processing/" . $jpgname);

            //now run the exec command
            $command = "";
            if ($CFG->paperattendance_categoryid == 406) //if production enable timeout
            {
                $command = "timeout 30 java -jar $CFG->paperattendance_formscannerjarlocation $CFG->paperattendance_formscannertemplatelocation $CFG->paperattendance_formscannerfolderlocation";

            } else {
                $command = "java -jar $CFG->paperattendance_formscannerjarlocation $CFG->paperattendance_formscannertemplatelocation $CFG->paperattendance_formscannerfolderlocation";
            }

            $lastline = exec($command, $output, $return_var);
            mtrace($command);
            print_r($output);
            mtrace($return_var);

            //return_var devuelve 0 si el proceso funciona correctamente
            if ($return_var == 0) {
                mtrace("Success running OMR");

                //revisar el csv que creó formscanner
                foreach (glob("{$path}/jpgs/processing/*.csv") as $filecsv) {
                    $arraypaperattendance_read_csv = array();
                    $arraypaperattendance_read_csv = paperattendance_read_csv($filecsv, $path, $filename, $uploaderobj);
                    $processed = $arraypaperattendance_read_csv[0];
                    if ($arraypaperattendance_read_csv[1] != null) {
                        $pagesWithErrors[$arraypaperattendance_read_csv[1]->pagenumber] = $arraypaperattendance_read_csv[1];
                    }
                    $countprocessed += $processed;
                }
            } else {
                //meaning that the timeout was reached, save that page with status unprocessed
                mtrace("Failure running OMR");
                $numpages = paperattendance_number_of_pages($path, $filename);

                if ($numpages == 1) {
                    $realpagenum = 0;
                } else {
                    $oldpdfpagenumber = explode("-", $jpgname);
                    $oldpdfpagenumber = $oldpdfpagenumber[1];
                    $realpagenum = explode(".", $oldpdfpagenumber);
                    $realpagenum = $oldpdfpagenumber[0];
                }

                $sessionpageid = paperattendance_save_current_pdf_page_to_session($realpagenum, null, null, $filename, 0, $uploaderobj->id, time());

                if ($CFG->paperattendance_sendmail == 1) {
                    $errorpage = new stdClass();
                    $errorpage->pageid = $sessionpageid;
                    $errorpage->pagenumber = $realpagenum + 1;
                    $pagesWithErrors[$errorpage->pagenumber] = $errorpage;
                }

                $countprocessed++;
            }

            //finally unlink the jpg file
            unlink("$path/jpgs/processing/$jpgname");
        }
        if (count($pagesWithErrors) > 0) {
            if (count($pagesWithErrors) > 1) {
                ksort($pagesWithErrors);
            }
            paperattendance_sendMail($pagesWithErrors, null, $uploaderobj->id, $uploaderobj->id, null, "NotNull", "nonprocesspdf", null);
            $admins = get_admins();
            foreach ($admins as $admin) {
                paperattendance_sendMail($pagesWithErrors, null, $admin->id, $admin->id, null, "NotNull", "nonprocesspdf", null);
            }
            mtrace("end pages with errors var dump");
        }

        if ($countprocessed >= 1) {
            mtrace("Pdf $found correctly processed");
            $read++;
            $DB->delete_records("paperattendance_unprocessed", array('id' => $pdf->id));
            mtrace("Pdf $found deleted from unprocessed table");

            //TODO: unlink al pdf grande y viejo y ya no utilizado
        } else {
            mtrace("problem reading the csv or with the pdf");
        }
    }

    echo $found . " PDF found. \n";
    echo $read . " PDF processed. \n";

    // Displays the time required to complete the process
    $finaltime = time();
    $executiontime = $finaltime - $initialtime;

    echo "Execution time: $executiontime seconds. \n";
} else {
    echo "$found pdfs found. \n";
}

mtrace("End CLI. Total memory used: " . memory_get_usage() . "bytes \n");

exit(0);

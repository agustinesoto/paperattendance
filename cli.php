<?php

/**
 * This unified CLI has 4 tasks:
 * -Processing PDFs using the OMR
 * -Deleting unused files
 * -Syncing unsynced attendances with Omega
 * -Something to do with presences? beware this one
 */

define('CLI_SCRIPT', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once "$CFG->dirroot/local/paperattendance/locallib.php";
require_once "$CFG->dirroot/repository/lib.php";
require_once "$CFG->dirroot/lib/pdflib.php";
require_once "$CFG->dirroot/mod/assign/feedback/editpdf/fpdi/fpdi.php";
require_once "$CFG->dirroot/mod/assign/feedback/editpdf/fpdi/fpdi_bridge.php";
require_once "$CFG->dirroot/lib/clilib.php";

cli_heading("Paper Attendance unified CLI");

//For timing purposes
$initialTime = time();

/**
 *
 * Processing the PDFs with the OMR
 *
 */
echo "\n== Processing PDFs ==\n";

$pdfTime = time();

$found = 0;
$read = 0;
$sqlunreadpdfs =
    "SELECT  id, filename AS name, uploaderid AS userid
	FROM {paperattendance_unprocessed}
	ORDER BY lastmodified ASC";

// Read the pdfs if there is any unread, with readpdf function
if ($resources = $DB->get_records_sql($sqlunreadpdfs, array())) {
    $path = "$CFG->dataroot/temp/local/paperattendance/unread";

    var_dump($resources);
    echo ("Found PDFs to process\n");

    foreach ($resources as $pdf) {
        $found++;

        $filename = $pdf->name;
        $uploaderobj = $DB->get_record("user", array("id" => $pdf->userid));
        $pagesWithErrors = array();

        echo ("Processing PDF $filename ($found)\n");

        /**
         * Create JPGs
         * We use a slightly roundabout method of splitting the PDF into single page ones
         * This is done to avoid the absurd memory usage of Imagick
         */
        //clean the directory
        paperattendance_recursiveremovedirectory("$path/jpgs");

        //count pages
        $pdf = new FPDI();
        $pagecount = $pdf->setSourceFile("$path/$filename");
        $pdf->close();
        unset($pdf);

        for ($i = 1; $i <= $pagecount; $i++) {
            echo "Exporting page $i\n";
            //Split a single page
            $new_pdf = new FPDI();
            $new_pdf->AddPage();
            $new_pdf->setSourceFile("$path/$filename");
            $new_pdf->useTemplate($new_pdf->importPage($i));
            $new_pdf->Output("$path/jpgs/temp.pdf", "F");
            $new_pdf->close();

            //Export the page as JPG
            $image = new Imagick();
            $image->setResolution(300, 300);
            $image->readImage("$path/jpgs/temp.pdf");
            $image->setImageFormat('jpeg');
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(100);

            if ($image->getImageAlphaChannel()) {
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $image->setImageBackgroundColor('white');
            }
            $image->writeImage("$path/jpgs/$i.jpg");
            $image->destroy();
        }

        unlink("$write/jpgs/temp.pdf");

        if (!file_exists("$path/jpgs")) {
            mkdir("$path/jpgs", 0777, true);
        }

        //process jpgs one by one and then delete it
        $countprocessed = 0;
        foreach (glob("{$path}/jpgs/*.jpg") as $file) {
            $jpgname = basename($file);
            echo "Running OMR on $jpgname\n";

            //now run the exec command
            //if production enable timeout
            $command = "";
            if ($CFG->paperattendance_categoryid == 406) {
                $command = "timeout 30 java -jar $CFG->paperattendance_formscannerjarlocation $CFG->paperattendance_formscannertemplatelocation $CFG->paperattendance_formscannerfolderlocation";

            } else {
                $command = "java -jar $CFG->paperattendance_formscannerjarlocation $CFG->paperattendance_formscannertemplatelocation $CFG->paperattendance_formscannerfolderlocation";
            }

            $lastline = exec($command, $output, $return_var);
            echo "$command\n";
            print_r($output);
            echo "$return_var\n";

            //return_var devuelve 0 si el proceso funciona correctamente
            if ($return_var == 0) {
                echo "Success running OMR\n";

                //revisar el csv que creó formscanner
                foreach (glob("{$path}/jpgs/*.csv") as $filecsv) {
                    $arraypaperattendance_read_csv = array();
                    $arraypaperattendance_read_csv = paperattendance_read_csv($filecsv, $path, $filename, $uploaderobj);
                    $processed = $arraypaperattendance_read_csv[0];
                    if ($arraypaperattendance_read_csv[1] != null) {
                        $pagesWithErrors[$arraypaperattendance_read_csv[1]->pagenumber] = $arraypaperattendance_read_csv[1];
                    }
                    $countprocessed += $processed;

                    /**
                     * TODO: Remake CSV reading for inline one (it isnt used anywhere else), the new one must be capable of sending pages with everyone absent to missing
                     *
                     * We need to:
                     * -Read the CSV
                     * -Identify the session
                     * -Check the presences
                     * -Send the page to missing if all absent
                     * -Store the presences
                     * -Attempt to sync with Omega
                     */
                }
            } else {
                //meaning that the timeout was reached, save that page with status unprocessed
                echo "Failure running OMR\n";
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

                $errorpage = new stdClass();
                $errorpage->pageid = $sessionpageid;
                $errorpage->pagenumber = $realpagenum + 1;
                $pagesWithErrors[$errorpage->pagenumber] = $errorpage;
            }

            //finally unlink the jpg file
            unlink("$path/jpgs/$jpgname");
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
            echo ("end pages with errors var dump\n");
        }

        if ($countprocessed >= 1) {
            echo "PDF $found correctly processed\n";
            $read++;
            $DB->delete_records("paperattendance_unprocessed", array('id' => $pdf->id));
            echo "PDF $found deleted from unprocessed table\n";
        } else {
            echo "problem reading the csv or with the pdf\n";
        }
    }

    echo "$found PDF found\n";
    echo "$read PDF processed\n";
} else {
    echo "No PDFs to process found\n";
}

// Displays the time required to complete the process
$executiontime = time() - $pdfTime;
echo "Processed PDFs in $executiontime seconds\n";

/**
 *
 * Deleting unused files
 *
 */
echo "\n== Cleaning unused files ==\n";

$deleteTime = time();

$path = "$CFG->dataroot/temp/local/paperattendance/print/";
$pathpng = "$CFG->dataroot/temp/local/paperattendance/unread/";

//call de function to delete the files from the print folder in moodledata
if (file_exists($path)) {
    paperattendance_recursiveremovedirectory($path);
    echo "All files deleted from the print folder\n";
} else {
    echo "Error, files not deleted\n";

}
if (file_exists($pathpng)) {
    paperattendance_recursiveremove($pathpng, 'jpg');
    echo "Deleted JPGs from unread folder\n";
} else {
    echo "Error, files not deleted\n";
}

//WARNING
//This code can delete potentially unused PDFs in the unread folder
//(this are the PDFs that teacher upload)
//The code makes sure to only delete PDFs that are unused (avoiding unprocessed and lost ones)
//Delete files in unread, which are all the uploaded PDFs which are never cleaned
//We will delete all files except the ones in this array
$names = array();

//Get all unprocessed names
$table = "paperattendance_unprocessed";
$records = $DB->get_records($table);

foreach ($records as $record) {
    $names[] = $record->filename;
}

//Get all lost pages
$table = "paperattendance_sessionpages";
$conditions = array(
    "sessionid" => null,
);
$records = $DB->get_records($table, $conditions);

foreach ($records as $record) {
    $names[] = $record->pdfname;
}

echo "Unlinking unused PDFs from jpg folder\n";

foreach (glob("{$pathpng}*") as $file) {
    $filename = explode("/", $file);
    $filename = array_pop($filename);

    if (!in_array($filename, $names) && !is_dir($file)) {
        //disabled for confirmation to know if I can actually do this
        //unlink($file);
    }
}

$finalTime = time() - $deleteTime;

echo "\nCleaned temporal folder in $finalTime seconds\n";

/**
 *
 * Syncing with Omega
 * Should redo this part as I dont really understand how it works
 *
 */

//disable omega sync
//return;

echo "\n== Omega Sync ==\n";

$omegaTime = time();

//FIRST PART
//Syncs processed sessions

echo "= Sync sessions =\n";

$foundfirst = 0;
$processedfirst = 0;

$sqlunsynced =
    "SELECT sess.id AS id, sess.courseid AS courseid
	FROM {paperattendance_session} AS sess
	WHERE sess.status = ?
	ORDER BY sess.lastmodified ASC";

// Parameters for the previous query
$params = array(PAPERATTENDANCE_STATUS_PROCESSED);

// sync students with synctask function
if ($resources = $DB->get_records_sql($sqlunsynced, $params)) {
    foreach ($resources as $session) {
        //found an other one
        $foundfirst++;

        // Sql that brings the unsynced students
        $sqlstudents =
            "SELECT p.id, p.userid AS userid, p.status AS status, s.username AS username
	 		FROM {paperattendance_presence} AS p
			INNER JOIN {user} AS s on ( p.userid = s.id AND p.sessionid = ? )";

        if ($resources = $DB->get_records_sql($sqlstudents, array($session->id))) {
            $arrayalumnos = array();

            foreach ($resources as $student) {

                $line = array();
                $line['emailAlumno'] = $student->username;
                $line['resultado'] = "true";

                if ($student->status == 1) {
                    $line['asistencia'] = "true";
                } else {
                    $line['asistencia'] = "false";
                }

                $arrayalumnos[] = $line;
            }

            if (paperattendance_omegacreateattendance($session->courseid, $arrayalumnos, $session->id)) {
                $processedfirst++;
                $session->status = PAPERATTENDANCE_STATUS_SYNC;
                $DB->update_record("paperattendance_session", $session);
                echo "Synced session: $session->id\n";
            } else {
                echo "Failed to sync session: $session->id\n";
            }
        } else {
            echo "ERROR: Session not found: $session->id  (in table paperattendance_presence), inconsistencies found in DB\n";
        }
    }
}

//SECOND PART
//Syncs unsynced presences (omegasync 0)

echo "\n= Sync presences =\n";

$foundsecond = 0;
$processedsecond = 0;

// Sql that brings the unsychronized attendances
$sqlunsicronizedpresences =
    "SELECT p.id,
	s.id AS sessionid,
	u.username,
	s.courseid,
	p.status
	FROM {paperattendance_session} s
	INNER JOIN {paperattendance_presence} p ON (p.sessionid = s.id)
	INNER JOIN {user} u ON (u.id = p.userid)
	WHERE p.omegasync = ?";

$unsynchronizedpresences = $DB->get_records_sql($sqlunsicronizedpresences, array(0));

foreach ($unsynchronizedpresences as $presence) {
    $foundsecond++;

    $arrayalumnos = array();
    $line = array();
    $line["emailAlumno"] = $presence->username;
    $line['resultado'] = "true";
    if ($presence->status) {
        $line['asistencia'] = "true";
    } else {
        $line['asistencia'] = "false";
    }

    $arrayalumnos[] = $line;
    if (paperattendance_omegacreateattendance($presence->courseid, $arrayalumnos, $presence->sessionid)) {
        $processedsecond++;
        echo "Synced presence: $presence->username\n";
    } else {
        echo "Failed to sync presence $presence->username\n";
    }
}

echo "\n$foundfirst Att found first part\n";
echo "$processedfirst Processed first part\n";
echo "$foundsecond Att found second part\n";
echo "$processedsecond Processed second part\n";

$finalTime = time() - $omegaTime;
echo "\nSynchronized with Omega in $finalTime seconds\n";

/**
 *
 * The most puzzling one, it searchs for lists and watches if there are students not on the lists
 * Then it adds them as not present.
 * What is the point? Added just for security
 *
 */
echo ("\n== Searching for insert students ==\n");

$insertTime = time();

//select the lastest verified id
$sqllastverified =
    "SELECT MAX(id) AS id, result
    FROM {paperattendance_cronlog}
    WHERE task = ?";

if ($resultverified = $DB->get_record_sql($sqllastverified, array("presence"))) {
    //if this task has already run at least once
    $lastsessionid = $resultverified->result;
} else {
    //just check all sessions
    $lastsessionid = 0;
}

$sqlsessions =
    "SELECT id,
    courseid
    FROM {paperattendance_session}
    WHERE id > ?";

//select all unverified sessions
if ($sessionstoverify = $DB->get_records_sql($sqlsessions, array($lastsessionid))) {
    //if there is at least one session, check if there is a student enrolled but not on the list
    foreach ($sessionstoverify as $session) {
        $sessionid = $session->id;
        $courseid = $session->courseid;

        $enrolincludes = explode(",", $CFG->paperattendance_enrolmethod);
        list($enrolmethod, $paramenrol) = $DB->get_in_or_equal($enrolincludes);
        $parameters = array_merge(array($courseid), $paramenrol, array($sessionid));

        $querystudentsnotinlist =
            "SELECT u.id
            FROM {user_enrolments} ue
            INNER JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = ?)
            INNER JOIN {context} c ON (c.contextlevel = 50 AND c.instanceid = e.courseid)
            INNER JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.roleid = 5 AND ra.userid = ue.userid)
            INNER JOIN {user} u ON (ue.userid = u.id)
            WHERE e.enrol $enrolmethod AND u.id NOT IN (SELECT userid FROM  {paperattendance_presence} WHERE sessionid = ?)
            GROUP BY u.id
            ORDER BY lastname ASC";

        //If we find students enrolled but not on the list we add him as not present
        if ($studentsnotinlist = $DB->get_records_sql($querystudentsnotinlist, $parameters)) {
            foreach ($studentsnotinlist as $student) {
                paperattendance_save_student_presence($sessionid, $student->id, '0');
            }
            paperattendance_cronlog("presence", $session->id, time());
        }
    }
}

$finalTime = time() - $insertTime;
echo "\nVerified sessions for insert students in $finalTime seconds\n";

//Print total time to run the CLI
$finalTime = time() - $initialTime;
echo ("\n== Final Time: $finalTime seconds ==\n");

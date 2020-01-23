<?php
namespace local_paperattendance\task;

class processpdf extends \core\task\adhoc_task
{
    public function execute()
    {
        global $CFG, $DB;
        require_once "$CFG->dirroot/local/paperattendance/locallib.php";
        require_once "$CFG->dirroot/lib/pdflib.php";
        require_once "$CFG->dirroot/mod/assign/feedback/editpdf/fpdi/fpdi.php";
        require_once "$CFG->dirroot/mod/assign/feedback/editpdf/fpdi/fpdi_bridge.php";

        echo "\n== Processing PDFs ==\n";
        $pdfTime = time();

        $found = 0;
        $sqlunreadpdfs =
            "SELECT  id, filename AS name, uploaderid AS userid
		    FROM {paperattendance_unprocessed}
		    ORDER BY lastmodified ASC";

        // Read the pdfs if there is any unread, with readpdf function
        if ($resources = $DB->get_records_sql($sqlunreadpdfs, array())) {
            $path = "$CFG->dataroot/temp/local/paperattendance/unread";

            $pdfnum = count($resources);
            echo ("Found $pdfnum PDFs to process\n");

            if (!file_exists("$path/jpgs")) {
                mkdir("$path/jpgs", 0777, true);
            }

            $pagesWithErrors = array();
            $countprocessed = 0;

            foreach ($resources as $pdf) {
                $found++;

                $filename = $pdf->name;
                $uploaderobj = $DB->get_record("user", array("id" => $pdf->userid));

                echo ("Processing PDF $filename ($found)\n");

                /**
                 * Create JPGs
                 * We use a slightly roundabout method of splitting the PDF into single page ones
                 * This is done to avoid the absurd memory usage of Imagick when reading large files
                 */
                //clean the directory
                \paperattendance_recursiveremovedirectory("$path/jpgs");

                //count pages
                $pages = new \FPDI();
                $pagecount = $pages->setSourceFile("$path/$filename");
                $pages->close();
                unset($pages);

                echo "$pagecount pages found\n";
                for ($i = 1; $i <= $pagecount; $i++) {
                    echo "\nProcessing page $i\n";
                    //Split a single page
                    $new_pdf = new \FPDI();
                    $new_pdf->AddPage();
                    $new_pdf->setSourceFile("$path/$filename");
                    $new_pdf->useTemplate($new_pdf->importPage($i));
                    $new_pdf->Output("$path/jpgs/temp.pdf", "F");
                    $new_pdf->close();

                    //Export the page as JPG
                    $image = new \Imagick();
                    $image->setResolution(300, 300);
                    $image->readImage("$path/jpgs/temp.pdf");
                    $image->setImageFormat('jpeg');
                    $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                    $image->setImageCompressionQuality(100);

                    if ($image->getImageAlphaChannel()) {
                        $image->setImageAlphaChannel(12);
                        $image->setImageBackgroundColor('white');
                    }
                    $image->writeImage("$path/jpgs/temp.jpg");
                    $image->destroy();

                    //now run the exec command
                    //if production enable timeout
                    //this will generate a csv with all the necesary data
                    $command = "timeout 30 java -jar $CFG->paperattendance_formscannerjarlocation $CFG->paperattendance_formscannertemplatelocation $CFG->paperattendance_formscannerfolderlocation";

                    $lastline = exec($command, $output, $return_var);
                    echo "$command\n";
                    print_r($output);
                    echo "$return_var\n";

                    //if formscanner ran successfully
                    if ($return_var == 0) {
                        echo "Success running OMR\n";
                        $arraypaperattendance_read_csv = array();
                        $arraypaperattendance_read_csv = \paperattendance_read_csv(glob("{$path}/jpgs/*.csv")[0], $path, $filename, $uploaderobj, $i);
                        $processed = $arraypaperattendance_read_csv[0];
                        if ($arraypaperattendance_read_csv[1] != null) {
                            $pagesWithErrors[$arraypaperattendance_read_csv[1]->pagenumber] = $arraypaperattendance_read_csv[1];
                        }
                        $countprocessed += $processed;
                    } else {
                        //meaning that the timeout was reached, save that page with status unprocessed
                        echo "Failure running OMR\n";
                        $sessionpageid = \paperattendance_save_current_pdf_page_to_session($i, null, null, $filename, 0, $uploaderobj->id, time());

                        $errorpage = new \stdClass();
                        $errorpage->pageid = $sessionpageid;
                        $errorpage->pagenumber = $i;
                        $pagesWithErrors[$errorpage->pagenumber] = $errorpage;
                    }

                }

                unlink("$path/jpgs/temp.pdf");
                unlink("$path/jpgs/temp.jpg");
            }

            //send messages if necessary
            if (count($pagesWithErrors) > 0) {
                if (count($pagesWithErrors) > 1) {
                    ksort($pagesWithErrors);
                }
                //send mail to uploader
                \paperattendance_sendMail($pagesWithErrors, null, $uploaderobj->id, $uploaderobj->id, null, "NotNull", "nonprocesspdf", null);

                //send mail to admins
                $admins = get_admins();
                foreach ($admins as $admin) {
                    \paperattendance_sendMail($pagesWithErrors, null, $admin->id, $admin->id, null, "NotNull", "nonprocesspdf", null);
                }
                echo ("end pages with errors var dump\n");
            }

            if ($countprocessed >= 1) {
                echo "PDF $found correctly processed\n";
                echo "PDF $found deleted from unprocessed table\n";
            } else {
                echo "problem reading the csv or with the pdf\n";
            }

            $DB->delete_records("paperattendance_unprocessed", array('id' => $pdf->id));

            echo "$found PDF found\n";
            echo "$countprocessed PDF processed\n";
        } else {
            echo "No PDFs to process found\n";
        }

        // Displays the time required to complete the process
        $executiontime = time() - $pdfTime;
        echo "Processed PDFs in $executiontime seconds\n";
    }
}

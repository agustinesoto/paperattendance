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

        $resources = $DB->get_records_sql($sqlunreadpdfs, array());
        if (!$resources) {
            echo "No PDFs to process found\n";
            return;
        }

        $path = "$CFG->dataroot/temp/local/paperattendance/unread";

        $pdfnum = count($resources);
        echo ("Found $pdfnum PDFs to process\n\n");

        if (!file_exists("$path/jpgs")) {
            mkdir("$path/jpgs", 0777, true);
        }

        $pagesWithErrors = array();
        $countprocessed = 0;

        foreach ($resources as $pdf) {
            $filename = $pdf->name;
            $uploaderobj = $DB->get_record("user", array("id" => $pdf->userid));

            echo ("Processing PDF $filename\n");

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
                $new_pdf->setPrintHeader(false);
                $new_pdf->setPrintFooter(false);
                $new_pdf->AddPage();
                $new_pdf->setSourceFile("$path/$filename");
                $new_pdf->useTemplate($new_pdf->importPage($i));
                $new_pdf->Output("$path/jpgs/temp.pdf", "F");
                $new_pdf->close();

                //Export the page as PNG
                $image = new \Imagick();
                $image->setResolution(300, 300);
                $image->readImage("$path/jpgs/temp.pdf");
                $image->setImageFormat('png');
                $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality(100);

                if ($image->getImageAlphaChannel()) {
                    $image->setImageAlphaChannel(12);
                    $image->setImageBackgroundColor('white');
                }
                $image->writeImage("$path/jpgs/$i.png");
                $image->destroy();

                //crop image
                $image = imagecreatefrompng("$path/jpgs/$i.png");
                $cropped = imagecropauto($image, IMG_CROP_DEFAULT);
                if ($cropped != false) {
                    imagedestroy($image);
                    $image = $cropped;
                    imagepng($image, "$path/jpgs/$i.png");
                }

                //process the PDF with an arbitrary number of templates
                //any new templates add here
                $templates = ["template-1.xtmpl", "template-2.xtmpl"];
                $formscanner_jar = "$CFG->dirroot/local/paperattendance/formscanner-1.1.4-bin/lib/formscanner-main-1.1.4.jar";
                $formscanner_path = "$path/jpgs/";

                $success = false;

                foreach ($templates as $template) {
                    //set the template
                    $formscanner_template = "$CFG->dirroot/local/paperattendance/formscanner-1.1.4-bin/$template";

                    $command = "timeout 30 java -jar $formscanner_jar $formscanner_template $formscanner_path";

                    $lastline = exec($command, $output, $return_var);
                    echo "$command\n";
                    print_r($output);
                    echo "$return_var\n";

                    if ($return_var == 0) {
                        $success = true;
                        echo "Success scanning with template: $template\n";
                        break;
                    } else {
                        echo "Failure with template: $template\n";
                    }
                }

                //if success read the CSV which in turn will write everything into the DB and sync with Omega
                if ($success) {
                    echo "Success running OMR\n";
                    $arraypaperattendance_read_csv = array();
                    $arraypaperattendance_read_csv = \paperattendance_read_csv(glob("{$path}/jpgs/*.csv")[0], $path, $filename, $uploaderobj);
                    $processed = $arraypaperattendance_read_csv[0];
                    if ($arraypaperattendance_read_csv[1] != null) {
                        $pagesWithErrors[$arraypaperattendance_read_csv[1]->pagenumber] = $arraypaperattendance_read_csv[1];
                    }
                    $countprocessed++;
                } else {
                    echo "Failure running OMR\n";
                    $sessionpageid = \paperattendance_save_current_pdf_page_to_session($i, null, null, $filename, 0, $uploaderobj->id, time());

                    $errorpage = new \stdClass();
                    $errorpage->pageid = $sessionpageid;
                    $errorpage->pagenumber = $i;
                    $pagesWithErrors[$errorpage->pagenumber] = $errorpage;
                }

                unlink("$path/jpgs/$i.png");
            }
            unlink("$path/jpgs/temp.pdf");

            $DB->delete_records("paperattendance_unprocessed", array('id' => $pdf->id));
        }

        //if errors happened while processing send mails
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

        echo "$pdfnum PDF found\n";
        echo "$countprocessed pages processed\n";

        // Displays the time required to complete the process
        $executiontime = time() - $pdfTime;
        echo "Processed PDFs in $executiontime seconds\n\n";
    }
}

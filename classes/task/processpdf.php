<?php

//The code in this CLI is duplicated in the cli/processpdf.pdf cron
//beware when modifying
//TODO: put the actual processing in a function and just call it from the CLI and task

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

		if(is_writable($CFG->paperattendance_processpdflogpath) == false)
		{
			mtrace("Abort PDF processing, no write access to log");
			return;
		}
		$log_file = fopen($CFG->paperattendance_processpdflogpath, "a");

		mtrace("==Processing PDFs==");
		$pdfTime = time();

		$found = 0;
		$sqlunreadpdfs =
			"SELECT  id, filename AS name, uploaderid AS userid
		    FROM {paperattendance_unprocessed}
            ORDER BY lastmodified ASC";

		$resources = $DB->get_records_sql($sqlunreadpdfs, array());
		if (!$resources) {
			mtrace("No PDFs to process found");
			return;
		}

		$path = "$CFG->dataroot/temp/local/paperattendance/unread";

		$pdfnum = count($resources);
		mtrace("Found $pdfnum PDFs to process");

		if (!file_exists("$path/jpgs")) {
			mkdir("$path/jpgs", 0777, true);
		}

		$pagesWithErrors = array();
		$countprocessed = 0;

		foreach ($resources as $pdf) {
			$filename = $pdf->name;
			$uploaderobj = $DB->get_record("user", array("id" => $pdf->userid));

			fwrite($log_file, "Processing PDF $filename\n");

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

			fwrite($log_file, "$pagecount pages found\n");
			for ($i = 1; $i <= $pagecount; $i++) {
				fwrite($log_file, "\nProcessing page $i\n");
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
				$image->setImageFormat('jpeg');
				$image->setImageCompression(\Imagick::COMPRESSION_JPEG);
				$image->setImageCompressionQuality(100);

				if ($image->getImageAlphaChannel()) {
					$image->setImageAlphaChannel(12);
					$image->setImageBackgroundColor('white');
				}
				$image->writeImage("$path/jpgs/$i.jpeg");
				$image->destroy();

				/*
                //crop image
                //this cropping barely works, dont recommend it
                //when it works successfully the template 2 will likely crash and drop to the template 1 which is worse
                $image = imagecreatefrompng("$path/jpgs/$i.png");
                $cropped = imagecropauto($image, IMG_CROP_DEFAULT);
                if ($cropped != false) {
                    imagedestroy($image);
                    $image = $cropped;
                    imagepng($image, "$path/jpgs/$i.png");
                }*/

				//process the PDF with an arbitrary number of templates
				//any new templates add here
				//the template 1 might crash less but its significantly less precise.
				$templates = ["template-2.xtmpl", "template-1.xtmpl"];
				$formscanner_jar = "$CFG->dirroot/local/paperattendance/formscanner-1.1.4-bin/lib/formscanner-main-1.1.4.jar";
				$formscanner_path = "$path/jpgs/";

				$success = false;

				foreach ($templates as $template) {
					//set the template
					$formscanner_template = "$CFG->dirroot/local/paperattendance/formscanner-1.1.4-bin/$template";

					$command = "timeout 30 java -jar $formscanner_jar $formscanner_template $formscanner_path 2>> $CFG->paperattendance_processpdflogpath";

					exec($command, $output, $return_var);

					fwrite($log_file, "$command\n");

					if ($return_var == 0) {
						$success = true;
						fwrite($log_file, "Success scanning with template: $template\n");
						break;
					} else {
						fwrite($log_file, "Failure with template: $template\n");
					}
				}

				//if success read the CSV which in turn will write everything into the DB and sync with Omega
				if ($success) {
					fwrite($log_file, "Success running OMR\n");

					$fila = 1;
					$return = 0;

					$file = glob("{$path}/jpgs/*.csv")[0];

					$errorpage = null;
					if (($handle = fopen($file, "r")) !== FALSE) {
						while (!feof($handle)) {
							$data = fgetcsv($handle, 1000, ";");

							//avoid complaints from count by checking if $data is an array
							//apparently this function does 3 passes, on the last pass there is no data
							$numero = false;
							if (is_array($data)) {
								$numero = count($data);

								fwrite($log_file, "data in row $fila: $numero\n");
								fwrite($log_file, var_export($data, true));
							}
							$stop = true;

							$pdfpage = explode(".", $data[0])[0];

							if ($fila > 1 && $numero > 26) {
								//$data[27] and $data[28] brings the info of the session
								$qrcodebottom = $data[27];
								$qrcodetop = $data[28];
								$qrcode = false;
								if (strpos($qrcodetop, '*') !== false) {
									$qrcode = $qrcodetop;
								} else {
									if (strpos($qrcodebottom, '*') !== false) {
										$qrcode = $qrcodebottom;
									}
								}

								//check if everyone is absent
								$presences = 0;
								foreach ($data as $presence) {
									if ($presence == "A") {
										$presences++;
									}
								}

								if ($qrcode) {
									//If stop is not false, it means that we could read one qr
									fwrite($log_file," qr found\n");
									$qrinfo = explode("*", $qrcode);
									//var_dump($qrinfo);
									if (count($qrinfo) == 7) {
										//Course id
										$course = $qrinfo[0];
										//Requestor id
										$requestorid = $qrinfo[1];
										//Module id
										$module = $qrinfo[2];
										//Date of the session in unix time
										$time = $qrinfo[3];
										//Number of page
										$page = $qrinfo[4];
										//Description of the session, example : regular
										$description = $qrinfo[5];
										//Print id
										$printid = $qrinfo[6];

										$objcourse = new \stdClass();
										$objcourse->id = $course;
										$studentlist = paperattendance_get_printed_students($printid);
										//var_dump($studentlist);

										$sessdoesntexist = paperattendance_check_session_modules($module, $course, $time);
										fwrite($log_file,"checkeo de la sesion: $sessdoesntexist\n");

										if ($sessdoesntexist == "perfect") {
											fwrite($log_file,"no existe\n");
											$sessid = paperattendance_insert_session($course, $requestorid, $uploaderobj->id, $filename, $description, 0);
											fwrite($log_file,"la session id es : $sessid\n");
											paperattendance_insert_session_module($module, $sessid, $time);
											paperattendance_save_current_pdf_page_to_session($pdfpage, $sessid, $page, $filename, 1, $uploaderobj->id, time());

											$coursename = $DB->get_record("course", array("id" => $course));
											$moduleobject = $DB->get_record("paperattendance_module", array("id" => $module));
											$sessdate = date("d-m-Y", $time) . ", " . $moduleobject->name . ": " . $moduleobject->initialtime . " - " . $moduleobject->endtime;
											paperattendance_sendMail($sessid, $course, $requestorid, $uploaderobj->id, $sessdate, $coursename->fullname, "processpdf", null);
										} else {
											$sessid = $sessdoesntexist; //if session exist, then $sessdoesntexist contains the session id
											//Check if the page already was processed
											if ($DB->record_exists('paperattendance_sessionpages', array('sessionid' => $sessid, 'qrpage' => $page))) {
												fwrite($log_file,"Session exists, list already uploaded\n");
												$return++;
												$stop = false;
											} else {
												paperattendance_save_current_pdf_page_to_session($pdfpage, $sessid, $page, $filename, 1, $uploaderobj->id, time());
												fwrite($log_file,"Session exists, but list hasn't been uploaded (attendance checked online?)\n");
												$stop = true;
											}
										}

										if ($stop) {
											$arrayalumnos = array();
											$init = ($page - 1) * 26 + 1;
											$end = $page * 26;
											$count = 1; //start at one because init starts at one
											$csvcol = 1;
											foreach ($studentlist as $student) {
												if ($count >= $init && $count <= $end) {
													$line = array();
													$line['emailAlumno'] = paperattendance_getusername($student->id);
													$line['resultado'] = "true";
													$line['asistencia'] = "false";

													if ($data[$csvcol] == 'A') {
														paperattendance_save_student_presence($sessid, $student->id, '1', NULL);
														$line['asistencia'] = "true";
													} else {
														paperattendance_save_student_presence($sessid, $student->id, '0', NULL);
													}

													$arrayalumnos[] = $line;
													$csvcol++;
												}
												$count++;
											}

											$omegasync = false;
											if (paperattendance_omegacreateattendance($course, $arrayalumnos, $sessid)) {
												$omegasync = true;
											}

											$update = new \stdClass();
											$update->id = $sessid;
											if ($omegasync) {
												$update->status = 2;
											} else {
												$update->status = 1;
											}
											$DB->update_record("paperattendance_session", $update);
										}
										$return++;
									} else {
										fwrite($log_file,"Error: can't process this page, no readable qr code\n");
										//$return = false;//send email or something to let know this page had problems
										$sessionpageid = paperattendance_save_current_pdf_page_to_session($pdfpage, null, null, $filename, 0, $uploaderobj->id, time());

										$errorpage = new \stdClass();
										$errorpage->pagenumber = $pdfpage;
										$errorpage->pageid = $sessionpageid;

										$return++;
									}
								} else {

									fwrite($log_file,"Error: can't process this page, no readable qr code\n");
									//$return = false;//send email or something to let know this page had problems
									$sessionpageid = paperattendance_save_current_pdf_page_to_session($pdfpage, null, null, $filename, 0, $uploaderobj->id, time());

									$errorpage = new \stdClass();
									$errorpage->pagenumber = $pdfpage;
									$errorpage->pageid = $sessionpageid;

									$return++;
								}
							}
							$fila++;
						}
						fclose($handle);
					}
					unlink($file);

					if ($errorpage != null) {
						$pagesWithErrors[$errorpage->pagenumber] = $errorpage;
					}
					$countprocessed++;

				} else {
					fwrite($log_file, "Failure running OMR\n");
					$sessionpageid = \paperattendance_save_current_pdf_page_to_session($i, null, null, $filename, 0, $uploaderobj->id, time());

					$errorpage = new \stdClass();
					$errorpage->pageid = $sessionpageid;
					$errorpage->pagenumber = $i;
					$pagesWithErrors[$errorpage->pagenumber] = $errorpage;
				}

				unlink("$path/jpgs/$i.jpeg");
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
			mtrace("end pages with errors var dump");
		}

		mtrace("$pdfnum PDFs found");
		mtrace("$countprocessed pages processed");

		// Displays the time required to complete the process
		$executiontime = time() - $pdfTime;
		mtrace("Processed PDFs in $executiontime seconds\n\n");
	}
}

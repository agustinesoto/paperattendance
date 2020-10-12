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
//Pertenece al plugin PaperAttendance
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once("$CFG->dirroot/local/paperattendance/locallib.php");
require_once("$CFG->dirroot/repository/lib.php");
require_once("$CFG->libdir/pdflib.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi_bridge.php");
require_once("$CFG->dirroot/local/paperattendance/lib/fpdi/fpdi.php");
global $CFG, $DB, $OUTPUT, $USER, $PAGE;
// User must be logged in.
require_login();
if (isguestuser()) {
	print_error(get_string('notallowedprint', 'local_paperattendance'));
	die();
}
// Action = { view, edit, delete }, all page options.
$action = optional_param('action', 'view', PARAM_TEXT);
$categoryid = optional_param('categoryid', $CFG->paperattendance_categoryid, PARAM_INT);
$sesspageid = optional_param('sesspageid', 0, PARAM_INT);
$pdfname = optional_param('pdfname', '-', PARAM_TEXT);
$sesskey = optional_param("sesskey", null, PARAM_ALPHANUM);
//Page
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

$contextsystem = context_system::instance();
if(is_siteadmin()  || has_capability('local/paperattendance:adminacademic', $contextsystem)){
	//if the user is an admin show everything
	$sqlmissing = "SELECT *
					FROM {paperattendance_sessionpages}
					WHERE processed = ?
					ORDER BY id DESC";
	$countmissing = count($DB->get_records_sql($sqlmissing, array(0)));
	$missing = $DB->get_records_sql($sqlmissing, array(0), $page*$perpage,$perpage);
}
else{
	/*
	 //if the user is a secretary show their own uploaded attendances
	  * 
	 $sqlcategory = "SELECT cc.*
	 FROM {course_categories} cc
	 INNER JOIN {role_assignments} ra ON (ra.userid = ?)
	 INNER JOIN {role} r ON (r.id = ra.roleid)
	 INNER JOIN {context} co ON (co.id = ra.contextid)
	 WHERE cc.id = co.instanceid AND r.shortname = ?";
	 $categoryparams = array($USER->id, "secrepaper");
	 $category = $DB->get_record_sql($sqlcategory, $categoryparams);
	 if($category){
		$categoryid = $category->id;
		}else{
		print_error(get_string('notallowedmissing', 'local_paperattendance'));
		}

		$sqlmissing = "SELECT *
		FROM {paperattendance_sessionpages}
		WHERE processed = ? AND uploaderid = ?
		ORDER BY id DESC";
		$params = array(0, $USER->id);
		*/
	$sqlcategory = "SELECT cc.*
					FROM {course_categories} cc
					INNER JOIN {role_assignments} ra ON (ra.userid = ?)
					INNER JOIN {role} r ON (r.id = ra.roleid AND r.shortname = ?)
					INNER JOIN {context} co ON (co.id = ra.contextid  AND  co.instanceid = cc.id  )";

	$categoryparams = array($USER->id, "secrepaper");

	$categorys = $DB->get_records_sql($sqlcategory, $categoryparams);
	$categoryscount = count($categorys);
	if($categorys){
		foreach($categorys as $category){
			$categoryids[] = $category->id;
		}
		$categoryid = $categoryids[0];
	}else{
		print_error(get_string('notallowedmissing', 'local_paperattendance'));
	}

	$sqlmissing = "SELECT *
					FROM {paperattendance_sessionpages}
					WHERE processed = ?
					ORDER BY id DESC";
	$params = array(0);
	$countmissing = count($DB->get_records_sql($sqlmissing, $params));
	$missing = $DB->get_records_sql($sqlmissing, $params, $page*$perpage,$perpage);
}
$context = context_coursecat::instance($categoryid);
$contextsystem = context_system::instance();
if (! has_capability('local/paperattendance:missingpages', $context) && ! has_capability('local/paperattendance:missingpages', $contextsystem)) {
	print_error(get_string('notallowedmissing', 'local_paperattendance'));
}
$url = new moodle_url('/local/paperattendance/missingpages.php');
$PAGE->navbar->add(get_string('pluginname', 'local_paperattendance'));
$PAGE->navbar->add(get_string('missingpages', 'local_paperattendance'), $url);
$PAGE->set_context($contextsystem);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin ( 'ui' );
$PAGE->requires->jquery_plugin ( 'ui-css' );
$PAGE->requires->css( new moodle_url('css/missingpages.css'));

if($countmissing==0){
	//print_error(get_string('nothingmissing', 'local_paperattendance'));
	$PAGE->set_title(get_string("viewmissing", "local_paperattendance"));
	$PAGE->set_heading(get_string("viewmissing", "local_paperattendance"));
	echo $OUTPUT->header();
	echo $OUTPUT->heading(get_string("viewmissingtitle", "local_paperattendance"));

	echo html_writer::nonempty_tag("h4", get_string('nothingmissing', 'local_paperattendance'), array("align" => "left"));
}
if ($action == "view") {
	$missingtable = new html_table();
	if ($countmissing > 0) {
		$missingtable->head = array(
				get_string("hashtag", "local_paperattendance"),
				get_string("scan", "local_paperattendance"),
				get_string("pagenum", "local_paperattendance"),
				get_string('date', 'local_paperattendance'),
				get_string("uploader", "local_paperattendance"),
				get_string("setting", "local_paperattendance"
						));
		 
		$missingtable->align = array(
				'left',
				'center',
				'center',
				'left',
				'center',
				'center'
		);
		 
		$counter = $page * $perpage + 1;
		foreach ($missing as $miss) {

			//delete action
			$deletemissingurl = new moodle_url("/local/paperattendance/missingpages.php",
					array(
							"action" => "delete",
							"sesspageid" => $miss->id,
							"sesskey" => sesskey()

					));
			$deletemissingicon= new pix_icon("t/delete", get_string("deletemissing", "local_paperattendance"
					));
			$deleteactionmissing = $OUTPUT->action_icon($deletemissingurl, $deletemissingicon,
					new confirm_action(get_string("doyouwantdeletemissing", "local_paperattendance")
							));

			//edit action
			$editurlmissing = new moodle_url("/local/paperattendance/missingpages.php",
					array(
							"action" => "edit",
							"sesspageid" => $miss->id,
							"sesskey" => sesskey()

					));
			$editiconmissing = new pix_icon("i/edit", get_string("editmissing", "local_paperattendance"
					));
			$editactionmissing = $OUTPUT->action_icon($editurlmissing, $editiconmissing
					);

			//view scan action
			$scanurl_attendance = new moodle_url("/local/paperattendance/missingpages.php", array(
					"action" => "scan",
					"pdfname" => $miss->pdfname,
					"page" => ($miss->pagenum)
			));
			$scanicon_attendance = new pix_icon("e/new_document", get_string('see', 'local_paperattendance'));
			$scanaction_attendance = $OUTPUT->action_icon(
					$scanurl_attendance,
					$scanicon_attendance
					);

			//get username
			$username = paperattendance_getusername($miss->uploaderid);
			//Convert the unix date to a local date
			$timecreated= $miss->timecreated;
			$dateconverted = paperattendance_convertdate($timecreated);

			//add data to table
			$missingtable->data [] = array(
					$counter,
					$scanaction_attendance,
					$miss->pagenum,
					$dateconverted,
					$username,
					$deleteactionmissing . $editactionmissing);

			$counter++;
		}
		$PAGE->set_title(get_string("viewmissing", "local_paperattendance"));
		$PAGE->set_heading(get_string("viewmissing", "local_paperattendance"));
		echo $OUTPUT->header();
		echo $OUTPUT->heading(get_string("viewmissingtitle", "local_paperattendance"));

		$totalmissing = get_string('totalmissing', 'local_paperattendance');
		echo("<p> $totalmissing: $countmissing </p>");

		echo html_writer::table($missingtable);
		//displays de pagination bar
		echo $OUTPUT->paging_bar($countmissing, $page, $perpage,
				$CFG->wwwroot . '/local/paperattendance/missingpages.php?action=' . $action . '&page=');
	}

}
if ($action == "edit") {
	/*
	Honestly, this is a clusterfuck.
	You get to editing by clicking "edit" on a pdf on the missing pages list
	Here you fill the main fields, then when you click "confirm" javascript hides everything and creates new fields
	it would be much nicer to just have an extra action
	*/

	//define the back button here to echo inside the if($seespageid == null) scope
	//we need to have it defined out here so it can be echoed into the javascript on the bottom of this file
 	$backurl = new moodle_url("/local/paperattendance/missingpages.php", array(
 			"action" => "view"
 	));
 	$viewbackbutton = html_writer::nonempty_tag(
 			"div",
 			$OUTPUT->single_button($backurl, get_string('back', 'local_paperattendance')),
			array("class"=>"form-group-input", "id"=>"backbutton")
		);


	if ($sesspageid == null) {
		print_error(get_string("sessdoesnotexist", "local_attendance"));
		$action = "view";
	}
	else {
		if ($session = $DB->get_record("paperattendance_sessionpages", array("id" => $sesspageid))){

			$timepdf = time();
			$path = $CFG -> dataroot. "/temp/local/paperattendance";
			$attendancepdffile = $path . "/print/paperattendance_".$sesspageid."_".$timepdf.".pdf";

			$pdf = new FPDI();
			$hashnamesql = "SELECT contenthash
							FROM {files}
							WHERE filename = ? AND component = ?";
			$hashname = $DB->get_record_sql($hashnamesql, array($session->pdfname, 'local_paperattendance' ));
			if($hashname){
				$newpdfname = $hashname->contenthash;
				$f1 = substr($newpdfname, 0 , 2);
				$f2 = substr($newpdfname, 2, 2);
				$filepath = $f1."/".$f2."/".$newpdfname;
				$pages = $session->pagenum;

				$originalpdf = $CFG -> dataroot. "/filedir/".$filepath;
					
				$pageCount = $pdf->setSourceFile($originalpdf);
				// import a page
				$templateId = $pdf->importPage($pages);
				// get the size of the imported page
				$size = $pdf->getTemplateSize($templateId);
				//Add page on portrait position
				$pdf->AddPage('P', array($size['w'], $size['h']));
				// use the imported page
				$pdf->useTemplate($templateId);
			}
			$pdf->Output($attendancepdffile, "F");
				
			$fs = get_file_storage();
			$file_record = array(
					'contextid' => $contextsystem->id,
					'component' => 'local_paperattendance',
					'filearea' => 'scan',
					'itemid' => 0,
					'filepath' => '/',
					'filename' => "paperattendance_".$sesspageid."_".$timepdf.".pdf"
			);
			// If the file already exists we delete it
			if ($fs->file_exists($contextsystem->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$sesspageid."_".$timepdf.".pdf")) {
				$previousfile = $fs->get_file($contextsystem->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$sesspageid."_".$timepdf.".pdf");
				$previousfile->delete();
			}
			// Info for the new file
			$fileinfo = $fs->create_file_from_pathname($file_record, $attendancepdffile);
			$url = moodle_url::make_pluginfile_url($contextsystem->id, 'local_paperattendance', 'scan', 0, '/', "paperattendance_".$sesspageid."_".$timepdf.".pdf");
			$viewerpdf = html_writer::nonempty_tag("embed", " ", array(
					"src" => $url,
					"style" => "height:50vh; width:90%; float:left; margin-top:3%; margin-left:5%;"
			));
			$viewerpdfdos = html_writer::nonempty_tag("embed", " ", array(
					"src" => $url,
					"style" => "height:116vh; width:40vw; float:left"
			));

			$viewerpdftres = html_writer::nonempty_tag("embed", " ", array(
				"src" => $url,
				"style" => "height:100%; width:100%;"
			));
				
				
			unlink($attendancepdffile);
				
			/*Inputs of the form to edit a missing page plus the modals help buttons*/
				
			//Input for the Shortname of the course like : 2113-V-ECO121-1-1-2017
			$inputs = html_writer::div('<label for="course">'.get_string("courseshortname", "local_paperattendance").'</label><input type="text" class="form-control" id="course" placeholder="2113-V-ECO121-1-1-2017"><button id="sn" type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#shortnamemodal">?</button>',"form-group-input");
			//Input for the Date of the list like: 01-08-2017
			$inputs .= html_writer::div('<label for="date">'.get_string("datemiss", "local_paperattendance").'</label><input type="text" class="form-control" id="date" placeholder="01-08-2017"><button id="d" type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#datemodal">?</button>',"form-group-input");
			//Input for the time of the module of the session like: 16:30
			$inputs .= html_writer::div('<label for="module">'.get_string("modulehourmiss", "local_paperattendance").'</label><input type="text" class="form-control" id="module" placeholder="16:30"><button id="m" type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#modulemodal">?</button>',"form-group-input");
			//Input for the list begin number like: 27
			$inputs .= html_writer::div('<label for="begin">'.get_string("listbeginmiss", "local_paperattendance").'</label><input type="text" class="form-control" id="begin" placeholder="27"><button id="b" type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#beginmodal">?</button>',"form-group-input");
			//Input fot the submit button of the form
			$inputs .= "<div id='buttons'>";
			$inputs .= html_writer::div('<button type="submit" id="confirm" class="btn btn-default">'.get_string("continue", "local_paperattendance").'</button>',"form-group-input");
			//add the back button
			$inputs .= $viewbackbutton;
			$inputs .= "</div>";
				
			//We now create de four help modals
			$shortnamemodal = '<div class="modal fade" id="shortnamemodal" role="dialog" style="width: 50vw; z-index: -10;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba el <strong>curso</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/hshortname.png">
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">'.get_string("close", "local_paperattendance").'</button>
							        </div>
							      </div>
							    </div>
							  </div>';
			$datemodal = '<div class="modal fade" id="datemodal" role="dialog" style="width: 50vw; z-index: -10;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba la <strong>fecha</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/helpdate.png">
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">'.get_string("close", "local_paperattendance").'</button>
							        </div>
							      </div>
							    </div>
							  </div>';
			$modulemodal = '<div class="modal fade" id="modulemodal" role="dialog" style="width: 50vw; z-index: -10;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba la <strong>hora del módulo</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/helpmodule.png">
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">'.get_string("close", "local_paperattendance").'</button>
							        </div>
							      </div>
							    </div>
							  </div>';
			$beginmodal = '<div class="modal fade" id="beginmodal" role="dialog" style="width: 50vw; z-index: -10;">
							    <div class="modal-dialog modal-sm">
							      <div class="modal-content">
							        <div class="modal-body">
									  <div class="alert alert-info">Escriba el <strong>nº de inicio</strong> perteneciente a su lista escaneada</div>
									  <img class="img-responsive" src="img/helpbegin.png">
							        </div>
							        <div class="modal-footer">
							          <button type="button" class="btn btn-default" data-dismiss="modal">'.get_string("close", "local_paperattendance").'</button>
							        </div>
							      </div>
							    </div>
							  </div>';
				
			$inputs .= html_writer::div($shortnamemodal, "form-group-input");
			$inputs .= html_writer::div($datemodal, "form-group-input");
			$inputs .= html_writer::div($modulemodal, "form-group-input");
			$inputs .= html_writer::div($beginmodal, "form-group-input");
		}
		else {
			print_error(get_string("missingpagesdoesnotexist", "local_paperattendance"));
			$action = "view";
			$url = new moodle_url('/local/paperattendance/missingpages.php');
			redirect($url);
		}
	}

	$PAGE->set_title(get_string("missingpages", "local_paperattendance"));
	$PAGE->set_heading(get_string("missingpages", "local_paperattendance"));
	echo $OUTPUT->header();
	echo $OUTPUT->heading(get_string("missingpagestitle", "local_paperattendance"));

  	$pdfarea = html_writer::div($viewerpdf,"col-md-12", array( "id"=>"pdfviewer"));
  	$inputarea = html_writer::div($inputs,"col-sm-12 row", array( "id"=>"inputs"));
 	echo html_writer::div($inputarea.$pdfarea, "form-group-input");
	
}
//Delete the selected missing page
if ($action == "delete") {
	if ($sesspageid == null) {
		print_error(get_string("missingdoesnotexist", "local_paperattendance"));
		$action = "view";
	}
	else {
		if ($session = $DB->get_record("paperattendance_sessionpages", array("id" => $sesspageid))) {
			if ($sesskey == $USER->sesskey) {
				$DB->delete_records("paperattendance_sessionpages", array("id" => $sesspageid));
				$action = "view";
			}
			else {
				print_error(get_string("usernotloggedin", "local_paperattendance"));
			}
		}
		else {
			print_error(get_string("missingdoesnotexist", "local_paperattendance"));
			$action = "view";
		}
	}
	$url = new moodle_url('/local/paperattendance/missingpages.php');
	redirect($url);
}
if($action == "scan"){

	$backurl = new moodle_url("/local/paperattendance/missingpages.php", array(
			"action" => "view"
	));

	$viewbackbutton = html_writer::nonempty_tag(
			"div",
			$OUTPUT->single_button($backurl, get_string('back', 'local_paperattendance')),
			array("align" => "left", "style"=>"padding-bottom: 1vh;")
		);

	$url = moodle_url::make_pluginfile_url($contextsystem->id, 'local_paperattendance', 'draft', 0, '/', $pdfname);

	$viewerpdf = html_writer::nonempty_tag("embed", " ", array(
			"src" => $url."#page=".$page,
			"style" => "height:100vh; width:60vw"
	));

	$PAGE->set_title(get_string("missingpages", "local_paperattendance"));
	$PAGE->set_heading(get_string("missingpages", "local_paperattendance"));
	echo $OUTPUT->header();
	echo $OUTPUT->heading(get_string("missingpagestitle", "local_paperattendance"));

	echo $viewbackbutton;
	echo $viewerpdf;

}
echo $OUTPUT->footer();
?>

</script>
<script type="text/javascript">
	$( document ).on( "click", "#sn", function() {
		jQuery('#shortnamemodal').css('z-index', '');
	});
	$(document).on("click", "#d", function() {
		jQuery("#datemodal").css('z-index', '');
	});
	$(document).on("click", "#m", function() {
		jQuery("#modulemodal").css('z-index', '');
	});
	$(document).on("click", "#b", function() {
		jQuery("#beginmodal").css('z-index', '');
	});
</script>

<script>
//check here to fix continue button

var sessinfo = [];
//When submit button in the form is clicked
$( "#confirm" ).on( "click", function() {
	var course = $('#course');
	var date = $('#date');
	var module = $('#module');
	var begin = $('#begin');
	var sesspageid = '<?php echo($sesspageid ?? ''); ?>';
	var pdfviewer = '<?php echo($viewerpdftres ?? ''); ?>';
	var backbutton = '<?php echo(preg_replace("/[\r\n|\n|\r]+/", " ", $viewbackbutton ?? '')); ?>';
	//Validate the four fields in the form
	if 
	(
		!course.val() || 
		!date.val() || 
		!module.val() || 
		!begin.val() || 
		(parseFloat(begin.val())-1+26)%26 != 0 
		|| date.val() === date.val().split('-')[0] 
		|| module.val() === module.val().split(':')[0]
	) 
	{
	    alert("Por favor, rellene todos los campos correctamente");
	}
	else 
	{
		//AJAX to get the students list
		$.ajax({
			    type: 'GET',
			    url: 'ajax/getliststudentspage.php',
			    data: {
				      'result' : course.val(),
				      'begin' : parseFloat(begin.val()),
				      'module' : module.val(),
				      'date' : date.val()
			    	},
			    success: function (response) {
			        var error = response["error"];
			        if (error != 0){
						alert(error);
						console.log(517);
			        }
			        else{
				        //Agregate the info of the session to the var sessinfo array
			        	sessinfo.push({"sesspageid":sesspageid, "shortname":course.val(), "date": date.val(), "module": module.val(), "begin": begin.val()});
						console.log(520);
						console.log(JSON.stringify(sessinfo));
						//AJAX to check if the page was processed
			        	$.ajax({
			        	    type: 'POST',
			        	    url: 'ajax/checkprocesspage.php',
			        	    data: {
			        		      'sessinfo' : JSON.stringify(sessinfo)
			        	    	},
			        	    success: function (responsetwo) {
			        	    	var error = responsetwo["process"];
			        	    	console.log(error);
			        	        if (error != 0){
			        	        	var deleteornot = confirm(error+'\n\n¿Desea eliminarla?');
						        	if (deleteornot){
						        		var sesskey = '<?php echo sesskey(); ?>';
						        		location.href="missingpages.php?action=delete&sesspageid="+sesspageid+"&sesskey="+sesskey;
							        }
			        	        }	
			        	        //now we create the table with the students		        	        
			        	        else{
			        	        	console.log(537);	
			        	        	$("#backbutton").empty();

                                    $(".form-group-input").addClass('row');

			    					$("#inputs").empty();
			    					$("#inputs").removeClass("row");
                                    $("#inputs").removeClass('col-sm-12');
                                    $("#inputs").addClass('col-md-6');
                                    $("#inputs").insertAfter("#pdfviewer");

			    					$("#pdfviewer").empty();
                                    $("#pdfviewer").removeClass('col-md-12');
                                    $("#pdfviewer").addClass('col-md-6');
			    					$("#pdfviewer").append(pdfviewer);

			    					//Create the table with all the students and checkboxs
			    				    var table = '<table class="table table-hover table-condensed table-responsive table-striped"><thead><tr><th>#</th><th><input type="checkbox" id="checkAll"></th><th>Seleccionar Todo</th></tr></thead><tbody id="appendtrs">';
			    				    $("#inputs").append(table);
			    				    
			    			        $.each(response["alumnos"], function(i, field){
			    				        var counter = i + parseFloat(begin.val());
			    				    	var appendcheckbox = '<tr class="usercheckbox"><td>'+counter+'</td><td><input type="checkbox" class="usercheck" value="'+field["studentid"]+'"></td><td>'+field["username"]+'</td></tr>';
			    			        	$("#appendtrs").append(appendcheckbox);
			    			        });

			    			        $("#inputs").append("</tbody></table>");
                                    $(".form-group-input").append('<div class="col-md-12" align="center" id="savebutton"><button class="btn btn-info savestudentsattendance" style="margin-bottom:5%; margin-top:5%;">Guardar Asistencia</button></div>');

			    		    		$("#backbutton").append(backbutton);


			    		    		$("#checkAll").change(function() {
			    		    	        if (this.checked) {
			    		    	            $(".usercheck").each(function() {
			    		    	                this.checked=true;
			    		    	            });
			    		    	        } else {
			    		    	            $(".usercheck").each(function() {
			    		    	                this.checked=false;
			    		    	            });
			    		    	        }
			    		    	    });

			    		    	    $(".usercheck").click(function () {
			    		    	        if ($(this).is(":checked")) {
			    		    	            var isAllChecked = 0;

			    		    	            $(".usercheck").each(function() {
			    		    	                if (!this.checked)
			    		    	                    isAllChecked = 1;
			    		    	            });

			    		    	            if (isAllChecked == 0) {
			    		    	                $("#checkAll").prop("checked", true);
			    		    	            }     
			    		    	        }
			    		    	        else {
			    		    	            $("#checkAll").prop("checked", false);
			    		    	        }
			    		    	    });

			    		    		/*
			    		    		jQuery(".usercheck").click(function () {
			    		    		    if (jQuery(this).is(":checked")) {
			    		    		        var isAllChecked = 0;
			    		    		
			    		    		        jQuery(".usercheck").each(function() {
			    		    		            if (!this.checked)
			    		    		                isAllChecked = 1;
			    		    		        });
			    		    		
			    		    		        if (isAllChecked == 0) {
			    		    		        	jQuery("#checkAll").prop("checked", true);
			    		    		        }     
			    		    		    }
			    		    		    else {
			    		    		    	jQuery("#checkAll").prop("checked", false);
			    		    		    }
			    		    		});*/
			    		    		
			    		    		
			    		    		RefreshSomeEventListener();
			    		        }
			        	    }
			        	});
			        }
			    }
		});
	}
});
//Function to save the students presence in checkbox to the database
function RefreshSomeEventListener() {
	$( ".savestudentsattendance" ).on( "click", function() {
		var studentsattendance = [];
		//Validate if the checkbox is checked or not, if checked presence = 1
		var checkbox = $('.usercheck');
		$.each(checkbox, function(i, field){
			var currentcheckbox = $(this);
			if(currentcheckbox.prop("checked") == true){
				var presence = 1;
			}
			else{
				var presence = 0;
			}
			//We agregate the info to the de studentsattendance aray
			studentsattendance.push({"userid":currentcheckbox.val(), "presence": presence});
		});	
		/*Shows students attendace and sessinfo in JSON format:
		alert(JSON.stringify(studentsattendance));
		console.log(JSON.stringify(studentsattendance));
		console.log(JSON.stringify(sessinfo));*/
		$("#inputs").empty();
		$("#pdfviewer").empty();
        $("#savebutton").remove();
        $(".savestudentsattendance").remove();
		$("#inputs").append("<div id='loader'><img src='img/loading.gif'></div>");
		//AJAX to save the student attendance in database
		$.ajax({
		    type: 'GET',
		    dataType:'JSON',
		    url: 'ajax/savestudentsattendance.php',
		    data: {
			      'sessinfo' : JSON.stringify(sessinfo),
			      'studentsattendance' : JSON.stringify(studentsattendance)
		    	},
		    success: function (response) {
				/**For the moment we only use the third error, the rest are for debugging**/
				/*var error = response["sesion"];
				var error2 = response["sesiondos"];*/
				var error3 = response["guardar"];
				//var error4 = response["omegatoken"];
				var error5 = response["omegatoken2"];
				/*var error6 = response["arregloalumnos"];
				var error7 = response["idcurso"];
				var error8 = response["idsesion"];
				var error9 = response["arregloinicialalumnos"];*/
				var moodleurl = "<?php echo $CFG->wwwroot;?>";
                $('#loader').hide();
                $("#alerthelp").hide();
                $("#savebutton").remove();
                $(".savestudentsattendance").remove();
                $("#inputs").removeClass('col-sm-12');
                $("#inputs").addClass('col-md-8');
                $("#inputs").html('<div class="alert alert-success" role="alert" style="margin-top:5%;">'+error3+error5+'</div>');
		    },
		    complete: function (index){
				console.log(index);
		    }
		});
	});
}
</script>
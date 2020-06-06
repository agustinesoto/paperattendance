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
* @copyright  2016 Jorge Cabané (jcabane@alumnos.uai.cl)
* @copyright  2016 Hans Jeria (hansjeria@gmail.com)
* @copyright  2016 Matías Queirolo (mqueirolo@alumnos.uai.cl)
* @copyright  2016 Cristobal Silva (cristobal.isilvap@gmail.com)
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/local/paperattendance/locallib.php');

global $DB, $OUTPUT, $USER, $PAGE;
// User must be logged in.
require_login();
if (isguestuser()) {
	print_error(get_string('notallowedprint', 'local_paperattendance'));
	die();
}

$categoryid = optional_param('categoryid', $CFG->paperattendance_categoryid, PARAM_INT);
$action = optional_param('action', 'viewform', PARAM_TEXT);
//Page
$page = optional_param('page', 0, PARAM_INT);
$perpage = 30;

$contextsystem = context_system::instance();
if(is_siteadmin() || has_capability('local/paperattendance:adminacademic', $contextsystem)){
/*	$sqlcourses = "SELECT c.id,
				c.fullname,
				cat.name,
				u.id as teacherid,
				CONCAT( u.firstname, ' ', u.lastname) as teacher
				FROM {user} AS u
				INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
				INNER JOIN {context} ct ON (ct.id = ra.contextid)
				INNER JOIN {course} c ON (c.id = ct.instanceid)
				INNER JOIN {role} r ON (r.id = ra.roleid AND r.id IN ( 3, 4))
				INNER JOIN {course_categories} as cat ON (cat.id = c.category)
				WHERE c.timecreated > ? AND c.idnumber > 0 
				GROUP BY c.id
				ORDER BY c.fullname";
	$year = strtotime("1 January".(date('Y'))); Poner aqui la fecha que se desea para elegir los cursos
	$ncourses = count($DB->get_records_sql($sqlcourses, array($year)));
	$courses = $DB->get_records_sql($sqlcourses, array($year), $page*$perpage,$perpage);
	$paths = 1;
*/	
	//Query to get all the courses for the admin
	$sqlcourses = "SELECT c.id,
				c.fullname,
				cat.name,
				u.id as teacherid,
				CONCAT( u.firstname, ' ', u.lastname) as teacher
				FROM {user} AS u
				INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
				INNER JOIN {context} ct ON (ct.id = ra.contextid)
				INNER JOIN {course} c ON (c.id = ct.instanceid)
				INNER JOIN {role} r ON (r.id = ra.roleid AND r.id IN ( $CFG->paperattendance_profesoreditorrole, $CFG->paperattendance_profesornoeditorrole))
				INNER JOIN {course_categories} as cat ON (cat.id = c.category)
				WHERE c.idnumber > 0
				GROUP BY c.id
				ORDER BY c.fullname";
	$ncourses = count($DB->get_records_sql($sqlcourses));
	$courses = $DB->get_records_sql($sqlcourses, null, $page*$perpage,$perpage);
	$paths = 1;
}
else{
	//Query to get the categorys of the secretary
	$sqlcategory = "SELECT cc.*
					FROM {course_categories} cc
					INNER JOIN {role_assignments} ra ON (ra.userid = ?)
					INNER JOIN {role} r ON (r.id = ra.roleid AND r.shortname = ?)
					INNER JOIN {context} co ON (co.id = ra.contextid  AND  co.instanceid = cc.id  )";
	
	$categoryparams = array($USER->id, "secrepaper");
	$categorys = $DB->get_records_sql($sqlcategory, $categoryparams);
	$categoryscount = count($categorys);
	$categoryids = array();
	$paths = array();
	$like = "";
	$counter = 1;
	if($categorys){
		foreach($categorys as $category){
			$categoryids[] = $category->id;
			$path = $category->id;
			$paths[] = $path;
			if($counter==$categoryscount)
				$like.= "cat.path like '%/".$path."/%' OR cat.path like '%/".$path."'";
				else
					$like.= "cat.path like '%/".$path."/%' OR cat.path like '%/".$path."' OR ";
					$counter++;
		}
		$categoryid = $categoryids[0];
	}else{
		print_error(get_string('notallowedprint', 'local_paperattendance'));
	}
	$sqlcoursesparam = array('50', $CFG->paperattendance_profesoreditorrole);
	$sqlcourses= "SELECT c.id,
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
	INNER JOIN {course_categories} as cat ON (cat.id = c.category)
	INNER JOIN {role} r ON (r.id = ra.roleid)
	WHERE ct.contextlevel = ? AND r.id = ?
	AND $like AND c.idnumber > 0
	GROUP BY c.id";

	$ncourses = count($DB->get_records_sql($sqlcourses,$sqlcoursesparam));
	$courses = $DB->get_records_sql($sqlcourses, $sqlcoursesparam, $page*$perpage,$perpage);
}

//modules
$modulesquery = "SELECT *
				FROM {paperattendance_module}
				ORDER BY initialtime ASC";
$modules = $DB->get_records_sql($modulesquery);
$modulesselect = "<select class='selectpicker' multiple><option value='no'>".get_string("selectmodules", "local_paperattendance")."</option>";
foreach ($modules as $module){
	$modulesselect .= "<option value='".$module->id."*".$module->initialtime."*".$module->endtime."'>".$module->initialtime."</option>";
}
$modulesselect .= "</select>";

$context = context_coursecat::instance($categoryid);
$contextsystem = context_system::instance();

if (! has_capability('local/paperattendance:printsearch', $context) && ! has_capability('local/paperattendance:printsearch', $contextsystem)) {
	print_error(get_string('notallowedprintaqui', 'local_paperattendance'));
}

// Creating tables and adding columns header.
$table = new html_table();
$table->head = array(get_string('hashtag', 'local_paperattendance'),
		get_string('course', 'local_paperattendance'),
		get_string('teacher', 'local_paperattendance'),
		get_string('category', 'local_paperattendance'),
		get_string('customprint', 'local_paperattendance'),
		get_string('addtocart', 'local_paperattendance'),
		get_string('quickprint', 'local_paperattendance')
);
$table->id = "fbody";

// This page url.
$url = new moodle_url('/local/paperattendance/printsearch.php', array(
		"categoryid" => $categoryid
));

$pagetitle = get_string('printtitle', 'local_paperattendance');
$PAGE->navbar->add(get_string('pluginname', 'local_paperattendance'));
$PAGE->navbar->add(get_string('printtitle', 'local_paperattendance'),$url);
if(is_siteadmin()){
	$PAGE->set_context($contextsystem);
}
else {
	$PAGE->set_context($context);
}
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($pagetitle);
// Require jquery for modal.
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin ( 'ui' );
$PAGE->requires->jquery_plugin ( 'ui-css' );
$PAGE->requires->js( new moodle_url('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js') );
$PAGE->requires->css( new moodle_url('https://netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.css') );

$coursecount = $page*$perpage+1;
foreach($courses as $course){
	$printurl = new moodle_url('/local/paperattendance/print.php', array(
			'courseid' => $course->id
	));
	$historyurl = new moodle_url('/local/paperattendance/history.php', array(
			'courseid' => $course->id
	));
	$table->data[] = array(
			$coursecount,
			html_writer::nonempty_tag("a", $course->fullname, array("href"=>$historyurl)),
			html_writer::nonempty_tag("span", $course->teacher, array("teacherid"=>$course->teacherid, "class"=>"teacher")),
			$course->name,
			html_writer::nonempty_tag("a", get_string("downloadprint", "local_paperattendance"), array("href"=>$printurl)),
			html_writer::nonempty_tag("i", ' ', array("class"=>"icon icon-plus listcart", "clicked"=>0, "courseid"=>$course->id)),
			html_writer::nonempty_tag("i", ' ', array("class"=>"icon icon-print quickprint", "clicked"=>0, "courseid"=>$course->id))
	);
	$coursecount++;
}
echo $OUTPUT->header();
echo html_writer::div(get_string("searchprinthelp","local_paperattendance"),"alert alert-info", array("role"=>"alert"));
$filterinput = html_writer::empty_tag("input", array( "id"=>"filter", "type"=>"text", "style"=>"float:left; width:25%"));
$cartbutton = html_writer::nonempty_tag("button", get_string("listscart","local_paperattendance"),  array( "id"=>"cartbutton", "style"=>"float:right; margin-right:6%"));
echo html_writer::div($filterinput.$cartbutton, "topbarmenu");

if ($ncourses>0){
	echo html_writer::table($table);
	echo html_writer::div('', 'loader');
	echo $OUTPUT->paging_bar($ncourses, $page, $perpage, $url);
}

$carttable = new html_table();
$carttable->head = array(
		get_string("session","local_paperattendance"),
		get_string("description","local_paperattendance"),
		get_string("date","local_paperattendance"),
		get_string("module","local_paperattendance"),
		get_string("requestor","local_paperattendance"),
		get_string("remove","local_paperattendance")
);
$carttable->id = "carttable";

//reminder for printer settings
$formmodal = 
'<div class="modal fade bs-example-modal-lg" id="formModal" tabindex="-1" role="dialog" aria-labelledby="formModalLabel" style="display: none; width:80%; margin-left:-45%">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="formModalLabel">Carrito de listas</h4>
			</div>
			<div class="modal-body" style="height:70vh">
				'.html_writer::table($carttable).'
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-info printbutton" data-dismiss="modal">Imprimir</button>
				<button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
			</div>
		</div>
	</div>
</div>'
;

$pdfmodal = "
<div class='modal fade bs-example-modal-lg' id='pdfModal' tabindex='-1' role='dialog' aria-labelledby='pdfModalLabel' style='display: none;'>
	<div class='modal-dialog modal-lg' role='document'>
		<div class='modal-content'>
			<div class='modal-header'>
				<button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button>
				<h4 class='modal-title' id='pdfModalLabel'>Listas pdf</h4>
			</div>
			<div class='modal-body'>
				<div class='pdflists' style='display:flex; flex-direction: column; height: 80vh;'></div>
			</div>
		</div>
	</div>
</div>
";


echo html_writer::div($formmodal, "modaldiv");
echo html_writer::div($pdfmodal, "modaldiv");

echo $OUTPUT->footer();

?>
<script>
jQuery('#formModal').modal({
	  keyboard: true,
	  show: false
})
$( document ).ready(function() {
    $('#filter').focus();
    $('#carttable tbody tr').remove();
});
</script>
<script type="text/javascript">
	var filter = $('#filter');
	var $table = $("#fbody").find("tbody");
	var $paging = $(".paging");
	var lists = [];
	//Today as default date (this is for the date inputs and to get defaultmodules)
    var today = new Date();
    var dd = today.getDate();
    var mm = today.getMonth()+1; //January is 0!
    var yyyy = today.getFullYear();
    var day = today.getDay();
    if(dd<10)
        dd='0'+dd;
    if(mm<10)
        mm='0'+mm;
    today = yyyy+'-'+mm+'-'+dd;
    //When secretary writes on the filter input
	filter.keyup(function(event){
		if(this.value.length >= 3 ){
			$table.find("tr").not(".ajaxtr").hide();
			$paging.hide();
		    var data = this.value;
		    var path = <?php echo json_encode(base64_encode(serialize($paths)));?>;
		    var print = <?php echo json_encode(get_string("downloadprint", "local_paperattendance"));?>;
			var categoryid = <?php echo $categoryid; ?>;
		    callAjax(data, path, print, categoryid);
		}
		else{
			$table.find("tr").not(".ajaxtr").show();
			$paging.show();
		}
		$(".ajaxtr").remove();
	});
	//When a plus icon is clicked, that course should be added to 'lists' array
	$( document ).on( "click", ".listcart", function() {
		var courseid = $(this).attr('courseid');
		var that = $(this);
		if(that.attr('clicked') == 0){
			$('.listcart[courseid='+courseid+']').removeClass('icon-plus').addClass('icon-ok');
		    //Get data to print
			$.ajax({
			    type: 'GET',
			    url: 'ajax/cartlist.php',
			    data: {
				      'courseid' : courseid,
				      'teacherid' : that.closest("tr").find(".teacher").attr("teacherid"),
				      'diasemana' : day
			    	},
			    success: function (response) {
				    var arr = response;
				    //Pre selected modules
				    var modulesselect = <?php echo json_encode($modulesselect);?>;	 
				    var selectedmodules = [];   
					jQuery('#carttable').append("<tr class='cart-tr' courseid="+courseid+"><td>"+arr['course']+"</td><td>"+arr['description']+"</td><td><input class='datepicker' type='date' size='10' value='"+today+"' courseid='"+courseid+"'></td><td>"+modulesselect+"</td><td>"+arr['requestor']+"</td><td><i class='icon icon-remove' courseid='"+courseid+"'></i></td></tr>");
					if(!arr["modules"]){
						jQuery('.cart-tr[courseid='+courseid+']').find('.selectpicker option[value="no"]').attr("selected", "selected");
					}
					else{
						jQuery('.cart-tr[courseid='+courseid+']').find('.selectpicker option').each(function (i){
							for(j=0;j<arr["modules"].length;j++)
								if(arr["modules"][j]["horaInicio"] == $(this).text()+":00"){
									$(this).attr("selected", "selected");
									var obj = {};
									obj[$(this).val()] = 1;
									selectedmodules.push(obj);
								}
						});
					}
					lists.push({"courseid":courseid, "requestorid": arr["requestorid"], "date": today, "modules": selectedmodules, "description": arr["description"]});
					$('.listcart[courseid='+courseid+']').attr('clicked', 1);
					enableprintbutton();
			    }
			});
		}
		else{
			var trmodal = $('#carttable').find("tr[courseid="+courseid+"]");
			trmodal.remove();
			jQuery(".listcart[courseid="+courseid+"]").removeClass('icon-ok').addClass('icon-plus');
			jQuery(".listcart[courseid="+courseid+"]").attr("clicked", 0);
			lists = jQuery.grep(lists, function(e){
				return e.courseid != courseid;
			});
			enableprintbutton();
		}
	});
	//When the quickprint icon is clicked
	$( document ).on( "click", ".quickprint", function() {
		var courseid = $(this).attr('courseid');
		$('.pdflists').html('<center><img src="img/loading.gif"></center>');
		$.ajax({
		    type: 'POST',
		    url: 'quickprint.php',
		    data: {
			      'courseid' : courseid
		    	},
		    success: function (response) {
				$('.pdflists').html(response);
				if(response == "There's nothing to print for today"){
					$('.printbutton').attr("disabled", true);
		    	}  	
				else{
					$('.printbutton').removeAttr("disabled");
				}
		    }
		});
		
		jQuery('#pdfModal').modal('show'); 
	
	});
	//When the background is clicked, the modal must hide
	$( document ).on( "click", ".modal-backdrop", function() {
		jQuery('#formModal').modal('hide');
	});
	//When this button is clicked, the modal must show the courses to print
	$( document ).on( "click", "#cartbutton", function() {
		jQuery('#formModal').modal('show');
		if(countlistselements(lists) != 0){
			enableprintbutton();
		}
	});
	//When a datepicker change, modules should change and lists array should be updated with de new data
	$( document ).on( "change", ".datepicker", function() {
		var cid = $(this).attr("courseid");
		var parts = $(this).val().split('-');
	    var year = parseInt(parts[0], 10);
	    var month = parseInt(parts[1], 10) - 1; // NB: month is zero-based!
	    var day = parseInt(parts[2], 10);
	    var date = new Date(year, month, day);
	    omegamodulescheck(date,cid);
	    updatelistsdate($(this).val(), cid);
	});
	//When a modules select change, lists array should be updated, if each list has at least one module, then the print button is enable
	$( document ).on( "change", ".selectpicker", function() {
		var cid = $(this).closest("tr").attr("courseid");
		var mods = new Array();
		$("option:selected", this).each(function(i){
			mods.push($(this).val());
		});
		updatelistsmodules(mods, cid);
		enableprintbutton();
	});
	//If some remove icon is clicked, it should be deleted that list from the lists array 
	$( document ).on( "click", ".icon-remove", function() {
		var tr = $(this).closest("tr");
		var cid = tr.attr("courseid");
		tr.remove();
		jQuery(".listcart[courseid="+cid+"]").removeClass('icon-ok').addClass('icon-plus');
		jQuery(".listcart[courseid="+cid+"]").attr("clicked", 0);
		lists = jQuery.grep(lists, function(e){
			return e.courseid != cid;
		});
		if(countlistselements(lists) != 0){
			enableprintbutton();
		}
	});
	//If print button is clicked, then the pdf with all lists is generated
	$( document ).on( "click", ".printbutton", function() {
		jQuery('#pdfModal').modal('show'); 
		$('.pdflists').html('<center><img src="img/loading.gif"></center>');
		$.ajax({
		    type: 'POST',
		    url: 'cartprint.php',
		    data: {
			      'lists' : lists
		    	},
		    success: function (response) {
				$('.pdflists').html(response);
		    }
		});
	});
	//This function is called to filter the table
	function callAjax(data, path, print, categoryid) {
		console.log(lists);
		var count = 1;
		//$("tbody").hide();
		$(".loader").html("<img style='display: block; margin-left: auto; margin-right: auto;' src='img/loading.gif'>");
		$(".loader").show();
		$.ajax({
		    type: 'GET',
		    url: 'ajax/getcourses.php',
		    data: {
			      'result' : data,
			      'path' : path,
			      'category' : categoryid
		    	},
		    success: function (response) {
		    	$(".ajaxtr").remove();
		        $.each(response, function(i, field){
			        var carticon = "<td><i class='icon icon-plus listcart' clicked='0' courseid='"+field['id']+"'></i></td>";
					$.each(lists, function(i, list){
						if(list.courseid == field['id']){
							carticon = "<td><i class='icon icon-ok listcart' clicked='1' courseid='"+field['id']+"'></i></td>";
						}
					});
		        	var num = "<td>"+count+"</td>";
			        var his = "<td><a href='history.php?courseid="+field['id']+"'>"+field['fullname']+"</a></td>"; 
			        var teacher = "<td><span class='teacher' teacherid='"+field['teacherid']+"'>"+field['teacher']+"</span></td>";
			        var category = "<td>"+field['name']+"</td>";
		        	var printicon = "<td><a href='print.php?courseid="+field['id']+"&categoryid="+path+"'>"+print+"</a></td>";
		        	var quickprinticon = "<td><i class='icon icon-print quickprint' clicked='0' courseid='"+field['id']+"'></i></td>";
		        	$table.append("<tr class='ajaxtr'>"+num+his+teacher+category+printicon+carticon+quickprinticon+"</tr>");
					count++;	
		        });
		        $(".loader").hide();
		        //$("tbody").show();
		    }
		});
	}
	//This function is to check modules from omega
	function omegamodulescheck(datetwo, courseid){
		dayofweek = datetwo.getDay();
		var modulesoptions = jQuery('.cart-tr[courseid='+courseid+']').find('.selectpicker option');
		modulesoptions.prop( "selected", false);
		$.ajax({
		    type: 'POST',
		    url: 'ajax/cartlist.php',
		    data: {
			      'courseid' : courseid,	
		    	  'diasemana': dayofweek
		    	},
		    success: function (response) {
		    	var arr = response;
		    	if(arr["modules"] == false){
		    		jQuery('.cart-tr[courseid='+courseid+']').find('.selectpicker option[value="no"]').prop("selected", true);
		    		updatelistsmodules(null, courseid);
		    		enableprintbutton();
			    }
		    	else{
			    	var mods = new Array();
			    	modulesoptions.each(function (i){
						for(j=0;j<arr["modules"].length;j++){
							if(arr["modules"][j]["horaInicio"] == $(this).text()+":00"){
								$(this).prop("selected", true);
								mods.push($(this).val());
							}
						}
					});
			    	enableprintbutton();
			    	updatelistsmodules(mods, courseid);
		    	}
		    }  	
		});
	}
    //This function is to update the lists array with some new selected module
	function updatelistsmodules(values, courseid){
		if(values == null){
			for(i=0; i<lists.length; i++){
				if(lists[i].courseid == courseid){
					lists[i].modules = new Array();
				}
			}
		}
		else{
			for(i=0; i<lists.length; i++){
				if(lists[i].courseid == courseid){
					lists[i].modules = new Array();
					for(j=0; j<values.length; j++){
						var mod = {};
						mod[values[j]] = 1;
						lists[i].modules.push(mod);
					}
				}
			}
		}
	}
    //This function is to update the lists array with some new selected date
	function updatelistsdate(value, courseid){
		for(i=0; i<lists.length; i++){
			if(lists[i].courseid == courseid){
				lists[i].date = value;
			}
		}
	}
	//This function is to enable the print button when every list has at least one module selected
	function enableprintbutton(){
		var count=0;
		$(".selectpicker option:selected").each(function(i){
			if($(this).val() == "no")
				count++;
		});
		if(count == 0)
			$(".printbutton").prop("disabled",false);
		else
			$(".printbutton").prop("disabled",true);
	}
	//function to count elements on the lists cart, if none, disable printbutton
	function countlistselements(lists){
		if(lists.length == 0)
			$('.printbutton').prop( "disabled", true );
		else
			$('.printbutton').prop( "disabled", false );

		return lists.length;
	}
		
</script>
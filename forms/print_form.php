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
* @copyright  2016 Hans Jeria (hansjeria@gmail.com) 					
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
defined('MOODLE_INTERNAL') || die();
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once($CFG->libdir . "/formslib.php");
class paperattendance_print_form extends moodleform {
	public function definition() {
		global $DB, $CFG;
		
		$mform = $this->_form;
		$instance = $this->_customdata;		
		$courseid = $instance["courseid"];
		
		$enrolincludes = explode("," ,$CFG->paperattendance_enrolmethod);
		list ( $sqlin, $param1 ) = $DB->get_in_or_equal ( $enrolincludes );
		$param2 = array(
				50,
				$courseid
		);
		$param = array_merge($param1,$param2);
		$teachersquery = 
			"SELECT u.id, 
			CONCAT(u.firstname, ' ', u.lastname) AS name
			FROM {user} u
			INNER JOIN {user_enrolments} ue ON (ue.userid = u.id)
			INNER JOIN {enrol} e ON (e.id = ue.enrolid)
			INNER JOIN {role_assignments} ra ON (ra.userid = u.id)
			INNER JOIN {context} ct ON (ct.id = ra.contextid)
			INNER JOIN {course} c ON (c.id = ct.instanceid AND e.courseid = c.id)
			INNER JOIN {role} r ON (r.id = ra.roleid)
			WHERE e.enrol $sqlin AND ct.contextlevel = ? AND c.id = ? AND r.id = $CFG->paperattendance_profesoreditorrole
			ORDER BY r.id ASC";
		
		$teachers = $DB->get_records_sql($teachersquery, $param);
		
		$arrayteachers = array();
		$arrayteachers["no"] = get_string('selectteacher', 'local_paperattendance');
		foreach ($teachers as $teacher){
			$arrayteachers[$teacher->id] = $teacher->name;
		}
		
		$descriptions = array(get_string('class', 'local_paperattendance'), 
							  get_string('assistantship', 'local_paperattendance'),
							  get_string('extraclass', 'local_paperattendance'), 
							  get_string('test', 'local_paperattendance'), 
							  get_string('quiz', 'local_paperattendance'), 
						      get_string('exam', 'local_paperattendance'), 
							  get_string('labs', 'local_paperattendance'));
		
		$countdescription = 0;
		$description = array();
		foreach ($descriptions as $arraydescriptions){
			$description[$countdescription] = $arraydescriptions;
			$countdescription++;
		}
		
		$mform->addElement("select", "requestor", get_string('requestor', 'local_paperattendance'), $arrayteachers);
		$mform->addElement("date_selector", "sessiondate", get_string('attdate', 'local_paperattendance'));
		$mform->addElement("select", "description", get_string('descriptionselect', 'local_paperattendance'), $description);
		$mform->addElement('html', '<div class="alert alert-info">'.get_string('modulesinfoomega','local_paperattendance').'</div>');
		
		$modulesquery = "SELECT * 
				FROM {paperattendance_module} 
				ORDER BY initialtime ASC";
		$modules = $DB->get_records_sql($modulesquery);
		$arraymodules = array();
		foreach ($modules as $module){
			$arraymodules[] = $mform->createElement('advcheckbox', $module->id."*".$module->initialtime."*".$module->endtime , '',$module->initialtime);	
		}
		$mform->addGroup($arraymodules, 'modules', get_string('modulescheckbox', 'local_paperattendance'));
		$mform->addElement("hidden", "courseid", $courseid);
		$mform->setType( "courseid", PARAM_INT);
		
		$this->add_action_buttons(true, get_string('downloadprint', 'local_paperattendance'));
		
	}
	
	public function validation($data, $files) {
		
		$errors = array();
		
		$requestor = $data["requestor"];
		$sessiondate = $data["sessiondate"];
		$modules = $data["modules"];
//		$description = $data["description"];
		
		
		if($requestor == "no"){
			$errors["requestor"] =  get_string('pleaseselectteacher', 'local_paperattendance');
		}
		
		$actualtime = strtotime(date("d-m-Y"));
		//echo strtotime(date("d-m-Y"))." select".$sessiondate;
		if($sessiondate < $actualtime){
			$errors["sessiondate"] = get_string('pleaseselectdate', 'local_paperattendance');
		}
		
		$count = 0;
		foreach ($modules as $module){
			if($module == 1){
				$count++;
			}
		}
		if($count == 0){
			$errors["modules"] = get_string('pleaseselectmodule', 'local_paperattendance');
		}
		
		return $errors;
	}
}

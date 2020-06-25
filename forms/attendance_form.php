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
* @copyright  2019 Matías Queirolo (mqueirolo@alumnos.uai.cl)					
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
defined('MOODLE_INTERNAL') || die();
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once($CFG->libdir . "/formslib.php");

class paperattendance_attendance_form extends moodleform {
	public function definition() {
		global $DB, $CFG;
		
		$mform = $this->_form;
		$instance = $this->_customdata;		
		$courseid = $instance["courseid"];
		$enrolledstudents = $instance["enrolledstudents"];
		
		//total sessions
		list($statusprocessed, $paramstatus) = $DB->get_in_or_equal(array(1,2));
		$params = array_merge(array($courseid), $paramstatus);
		$sqlsession = "SELECT s.id
		FROM {paperattendance_session} s
		WHERE s.courseid = ? AND s.status $statusprocessed";
		$sessions = count($DB->get_records_sql($sqlsession, $params));
		
		//check if exist some session or not
		if($sessions == 0 || $sessions == null){
			$existsession = false;
		}else {
			$existsession = true;
		}

		//localization, used below
		$student = get_string("student", "local_paperattendance");
		$currentAssistance = get_string("currentassistance", "local_paperattendance");
		
		//create de list of students with checkboxs
		$mform->addElement('header', 'nameforyourheaderelement', get_string('takeattendance', 'local_paperattendance'));
		$this->add_checkbox_controller(1);
		$counter = 0;
		
		$mform->addElement('html', '<table class="table table-striped table-condensed table-hover">');
		$mform->addElement('html', '<thead>');
		$mform->addElement('html', '<tr>');
		$mform->addElement('html', '<th>#');
		$mform->addElement('html', '</th>');
		$mform->addElement('html', "<th>$student");
		$mform->addElement('html', '</th>');
		$mform->addElement('html', "<th>$currentAssistance");
		$mform->addElement('html', '</th>');
		$mform->addElement('html', '</tr>');
		$mform->addElement('html', '</thead>');
		$mform->addElement('html', '<tbody>');
		foreach ($enrolledstudents as $student) {
			$counter++;
			$name = ($student->firstname.' '.$student->lastname);
			$email = $student->email;
			$userid = $student->userid;
			
			$mform->addElement('html', '<tr>');
			$mform->addElement('html', '<td>'.$counter);
			$mform->addElement('html', '</td>');
			$mform->addElement('html', '<td class="text-left" width="50%">');
			$mform->addElement('advcheckbox', 'key'.$userid, "", $name, array('group' => 1), array(0, 1));
			$mform->addElement('html', '</td>');
			$mform->addElement('html', '<td>');
			if ($existsession) {
				//student summary sql
				$present = "SELECT COUNT(*)
				FROM {paperattendance_presence} AS p
				INNER JOIN {paperattendance_session} AS s ON (s.id = p.sessionid AND p.status = 1  AND s.courseid = ? AND s.status $statusprocessed  AND p.userid = ?)";
				$paramspresent = array();
				$paramspresent = array_merge($params, array($student->userid));
				$present = $DB->count_records_sql($present, $paramspresent);
				$absent = $sessions - $present;
				$percentagestudentpresent = round(($present/$sessions)*100)."%";
				$percentagestudentabsent = round(($absent/$sessions)*100)."%";
				
				//progress bar
				//$progressbar = '<div class="progress progress-striped active" style="width: 50%;">
 				//		   	   		<div class="bar bar-success" style="width: '.$percentagestudentpresent.';">'.$percentagestudentpresent.'</div>
  				//			     	<div class="bar bar-danger" style="width: '.$percentagestudentabsent.';">'.$percentagestudentabsent.'</div>
				//	    		</div>';
				$progressbar = '<div class="progress progress-striped active" style="width: 60%;">';
				if ($percentagestudentpresent > 90){
					$progressbar .= '<div class="bar bar-success" style="width: '.$percentagestudentpresent.';">'.$percentagestudentpresent.'</div>
  							     	<div class="bar bar-danger" style="width: '.$percentagestudentabsent.';"></div>';
				}
				else if ($percentagestudentabsent > 90){
					$progressbar .= '<div class="bar bar-success" style="width: '.$percentagestudentpresent.';"></div>
  							     	<div class="bar bar-danger" style="width: '.$percentagestudentabsent.';">'.$percentagestudentabsent.'</div>';
				}
				else {
					$progressbar .= '<div class="bar bar-success" style="width: '.$percentagestudentpresent.';">'.$percentagestudentpresent.'</div>
  							     	<div class="bar bar-danger" style="width: '.$percentagestudentabsent.';">'.$percentagestudentabsent.'</div>';
				}
				$progressbar .= '</td>';
				$mform->addElement('html', $progressbar);
			}
			$mform->addElement('html', '</tr>');
			
		}
		$mform->addElement('html', '</tbody>');
		$mform->addElement('html', '</table>');
		//$this->add_checkbox_controller(2, get_string("checkallornone"), array('style' => 'font-weight: bold;'), 1);
		
		//Set the required parameter
		$mform->addElement("hidden", "courseid", $courseid);
		$mform->setType( "courseid", PARAM_INT);
		
		//$this->add_action_buttons(true, get_string('downloadprint', 'local_paperattendance'));
		$this->add_action_buttons(true);
		
	}
	
	public function validation($data, $files) {
		
		$errors = array();
		
		return $errors;
	}
}
?>

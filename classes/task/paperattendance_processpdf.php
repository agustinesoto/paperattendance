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
* @copyright  2016 Hans Jeria <hansjeria@gmail.com>
* @copyright  2016 Jorge Cabané (jcabane@alumnos.uai.cl) 					
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

namespace local_paperattendance\task;

class paperattendance_processpdf extends \core\task\scheduled_task {
	
	public function get_name() {
		return get_string('taskprocesspdf', 'local_paperattendance');
	}

	public function execute() {	
		global $CFG, $DB;
		require_once ($CFG->dirroot . '/local/paperattendance/locallib.php');
		
		// Sql that brings the unread pdfs names
		$sqlunreadpdfs = "SELECT sess.id AS id, 
				sess.pdf AS name, 
				sess.courseid AS courseid,
				sess.teacherid as teacherid,
				sess.uploaderid as uploaderid,
				c.fullname AS fullname,
				FROM_UNIXTIME(sess.lastmodified) AS date
 				FROM {paperattendance_session} AS sess
				INNER JOIN {course} AS c ON (c.id = sess.courseid AND sess.status = ?)
				ORDER BY sess.lastmodified ASC";
		// Parameters for the previous query
		$params = array(PAPERATTENDANCE_STATUS_UNREAD);
	
		// Read the pdfs if there is any unread, with readpdf function
		if($resources = $DB->get_records_sql($sqlunreadpdfs, $params)){
			$path = $CFG -> dataroot. "/temp/local/paperattendance/unread";
			foreach($resources as $pdf){
				$initialtime = time();
				
				$process = paperattendance_readpdf($path, $pdf-> name, $pdf->courseid);
				if($process["result"] == "true"){
					paperattendance_sendMail($pdf->id, $pdf->courseid, $pdf->teacherid, $pdf->uploaderid, $pdf->date, $pdf->fullname, "processpdf");
					if($process["synced"] == "true"){
						$pdf->status = 2;
					}
					else{
						$pdf->status = 1;
					}
					
					$DB->update_record("paperattendance_session", $pdf);
					
					$finaltime = time();
					$executiontime = $finaltime - $initialtime;
				
					$result = "pdf: ".$pdf-> name."-- result: ".$process["result"]."-- synced: ".$process["synced"];
						
					paperattendance_cronlog('processpdf', $result, time(), $executiontime);
				}

			}
		}
	}
}
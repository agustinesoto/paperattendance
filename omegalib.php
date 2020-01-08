<?php
/*
This library has all the functions needed to communicate with Omega in a single place
For now this is just for reference and is not actually used
TODO: rewire all communication to here and remove all other implementations spread out everywhere
*/

/*
this function pulls the modules of a single class, this are used to automatically fill the modules when creating a print
day is a number from 1 to 7, wednesday is 3
sectionId is the id of the section (beware, the id from Omega)
the function returns a string that represents an array with tuples inside that specify the start and end times of each module
Example:
"[
	{"horaInicio":"09:00:00","horaFin":"10:00:00","seccionId":0,"diaSemana":0},
	{"horaInicio":"10:10:00","horaFin":"11:10:00","seccionId":0,"diaSemana":0},
	{"horaInicio":"11:20:00","horaFin":"12:20:00","seccionId":0,"diaSemana":0},
	{"horaInicio":"12:30:00","horaFin":"13:30:00","seccionId":0,"diaSemana":0}
]"
Test API: http://webapitest.uai.cl/webcursos/GetModulosHorarios 
Production API: https://webapi.uai.cl/webcursos/GetModulosHorarios
*/
function pull_modules($day, $sectionId)
{
	global $CFG;
    $token = $CFG->paperattendance_omegatoken;
    $url = $CFG->paperattendance_omegagetmoduloshorariosurl;
    
	$fields = array (
		"diaSemana" => $day,
		"seccionId" => $sectionId,
		"token" => $token
	);

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($curl, CURLOPT_POST, TRUE);
	curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
	$result = curl_exec ($curl);
	curl_close ($curl);

	return $result;
}

//send/updates an entire session
//it sends the modules and assistances
//omega answers an id per each assistance which can be used for updating
//http://webapitest.uai.cl/webcursos/createattendance
//https://webapi.uai.cl/webcursos/createattendance
function push_assistances()
{

}

//using the omega id a single assistance is updated
//http://webapitest.uai.cl/webcursos/updateattendance
//https://webapi.uai.cl/webcursos/updateattendance
function update_assistance()
{
    
}
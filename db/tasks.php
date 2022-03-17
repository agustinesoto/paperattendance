<?php

$tasks = array(
    //the processpdf task is gone since its now an ad-hoc task that is queued only when needed (when uploading a pdf)
    array(
        'classname' => 'local_paperattendance\task\cleantemp',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '4',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ),
    array(
        'classname' => 'local_paperattendance\task\omegasync',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '4',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ),
    array(
        'classname' => 'local_paperattendance\task\presence',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '4',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ),

);

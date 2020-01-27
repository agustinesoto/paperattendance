<?php
namespace local_paperattendance\task;

class cleantemp extends \core\task\scheduled_task
{
    public function get_name()
    {
        return get_string('taskdelete', 'local_paperattendance');
    }

    public function execute()
    {
        global $CFG;
        require_once "$CFG->dirroot/local/paperattendance/locallib.php";
        echo "\n== Cleaning unused files ==\n";

        $deleteTime = time();

        $path = "$CFG->dataroot/temp/local/paperattendance/print/";

        //call the function to delete the files from the print folder in moodledata
        if (file_exists($path)) {
            \paperattendance_recursiveremovedirectory($path);
            echo "All files deleted from the print folder\n";
        } else {
            echo "Error, files not deleted\n";

        }

        $finalTime = time() - $deleteTime;

        echo "\nCleaned temporal folder in $finalTime seconds\n";
    }
}

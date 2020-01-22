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
        $pathpng = "$CFG->dataroot/temp/local/paperattendance/unread/";

        //call de function to delete the files from the print folder in moodledata
        if (file_exists($path)) {
            \paperattendance_recursiveremovedirectory($path);
            echo "All files deleted from the print folder\n";
        } else {
            echo "Error, files not deleted\n";

        }
        if (file_exists($pathpng)) {
            \paperattendance_recursiveremove($pathpng, 'jpg');
            echo "Deleted JPGs from unread folder\n";
        } else {
            echo "Error, files not deleted\n";
        }

        $finalTime = time() - $deleteTime;

        echo "\nCleaned temporal folder in $finalTime seconds\n";
    }
}

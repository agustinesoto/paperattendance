# paperattendance
Moodle plugin 

------------------------------------------

Pasos para resolver problema:
- 1 Revisar archivo .modules.php del plugin
- 2 Usando find a replace buscar palabra clave Initial Time
- 3 Reemplazar por texto requerido en parte del codigo correspondiente.

- 4 Todavía tengo problemas con la configuración de Moodle en ubuntu






Authors:
* Hans Jeria (hansjeria@gmail.com)
* Jorge Cabané (jcabane@alumnos.uai.cl) 
* Matías Queirolo (mqueirolo@alumnos.uai.cl)
* Cristobal Silva (cristobal.isilvap@gmail.com) 
* Juan Pablo Espinoza (juaespinoza@icloud.com)

Release notes
-------------

- 1.1: New version with multiple bugfixes (written and tested on Moodle 3.5)
- 1.0: First official deploy (written and tested on Moodle 2.6)

Introduction
------------

The paper attendance project began its development in July 2016 to give a definitive solution to the problem of registering attendance for teachers. Nowadays there is only one plugin for Moodle that allows the taking of assists, but its use requires a previous management of Webcourses by the teacher that wants to use it, besides the assistance must be taken from the platform.

Thus, the main idea of paper attendance is that teachers have an effective and simple method so that they can take the attendance in their classes, without having to have much knowledge of the applications of Webcourses or of what this allows them to do, so just mark in the paper course list the students present, deliver the paper to the secretaries who must digitize and upload to the platform, this way, will automatically take the paper assistance to an online registration in both Webcourses and Omega.

Installation
------------

In order to install PaperAttendance, the paperattendance directory in which this
README file is, should be copied to the /local/ directory in your Moodle
installation. Then visit your admin page to install the module.

Also this plugin uses the library included in Moodle that is in the directory mod/assign along with the following libraries that come in the paperattendance project:

- Phpdecoder
- Phpqrcode

However, the following library must be installed in php:

- Imagick

As for scanning and using the scanner, you must install the PaperPort program and use the black and white configuration with a resolution of 600 dpi and sensitivity 30.

Acnkowledgments, suggestions, complaints and bug reporting
----------------------------------------------------------

We'll be happy to get any useful feedback from you. Please feel free to
email us, our name and email address are in the top of this document. 

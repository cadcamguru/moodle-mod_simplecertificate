<?php
/**
 * Watermark and send files
 * 
 * @package mod
 * @subpackage simplecertificate
 * @copyright 2014 © Carlos Alexandre Soares da Fonseca
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once (dirname(dirname(dirname(__FILE__))) . '/config.php');

$id = required_param('id', PARAM_INTEGER); // Issed Code
$sk = required_param('sk', PARAM_RAW); // sesskey

if (confirm_sesskey($sk)) {
    if (!$issuedcert = $DB->get_record("simplecertificate_issues", array('id' => $id))) {
        print_error(get_string('issuedcertificatenotfound', 'simplecertificate'));
    }
    watermark_and_sent($issuedcert);
} else {
    print_error('invalidsesskey');
}

function watermark_and_sent(stdClass $issuedcert) {
    global $CFG, $USER, $COURSE, $DB, $PAGE;
    
    if ($issuedcert->haschange && $issuedcert->revoked == 0) {
        //This issue have a haschange flag, try to reissue
        if (empty($issuedcert->timedeleted)) {
            require_once ($CFG->dirroot . '/mod/simplecertificate/locallib.php');
            try {
                // Try to get cm
                $cm = get_coursemodule_from_instance('simplecertificate', $issuedcert->certificateid, 0, false, MUST_EXIST);
                $context = context_module::instance($cm->id);
                
                //Must set a page context to issue .... 
                $PAGE->set_context($context);
                $simplecertificate = new simplecertificate($context, null, null);
                $file = $simplecertificate->get_issue_file($issuedcert);
            
            } catch (moodle_exception $e) {
                // Only debug, no errors
                debugging($e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
            }
        } else {
            //Have haschange and timedeleted, somehting wrong, it will be impossible to reissue
            //add warning
            debugging("issued certificate [$issuedcert->id], have haschange and timedeleted");
        }
        $issuedcert->haschange = 0;
        $DB->update_record('simplecertificate_issues', $issuedcert);
    }
    
    if (empty($file)) {
        $fs = get_file_storage();
        if (!$fs->file_exists_by_hash($issuedcert->pathnamehash)) {
            print_error(get_string('filenotfound', 'simplecertificate', ''));
        }
        
        $file = $fs->get_file_by_hash($issuedcert->pathnamehash);
    }
    
    $canmanage = false;
    if (!empty($COURSE)) {
        $canmanage = has_capability('mod/simplecertificate:manage', context_course::instance($COURSE->id));
    }
    
    if ($issuedcert->revoked == 0 && ($canmanage || (!empty($USER) && $USER->id == $issuedcert->userid))) {
        send_stored_file($file, 0, 0, true);
    } else {
        require_once ($CFG->libdir . '/pdflib.php');
        require_once ($CFG->dirroot . '/mod/simplecertificate/lib/fpdi/fpdi.php');
        
        // copy to a tmp file
        if (!$issuedcert->revoked == 1) {
            //If not revoked put a copy watermark
            $tmpfile = simplecertificate::put_watermark($file,  get_string('certificatecopy', 'simplecertificate'));
        } else {
           send_stored_file($file, 0, 0, true);
        }  
        
        send_temp_file($tmpfile, $file->get_filename());
    }
}
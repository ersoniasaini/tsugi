<?php

use \Tsugi\UI\Lessons;
use \Tsugi\Util\U;
use \Tsugi\Util\CC;
use \Tsugi\Util\CC_LTI;
use \Tsugi\Util\CC_WebLink;

require_once "../config.php";

if ( ! isset($CFG->lessons) ) {
    die_with_error_log('Cannot find lessons.json ($CFG->lessons)');
}

// Load the Lesson
$l = new Lessons($CFG->lessons);

// Check if this is a remote import from Canvas
if ( isset($_POST['ext_content_return_url']) ) {
    $return_url = $_POST['ext_content_return_url'];
    $return_url .= strpos($return_url,'?') === false ? '?' : '&';
    $return_url .= "return_type=file&text=". urlencode($CFG->servicename) . "&url=";
    $return_url .= urlencode($CFG->wwwroot . '/cc/export');
    $return_url .= '&tsugi_lms=canvas';
    $OUTPUT->header();
    $OUTPUT->bodystart(false);
    echo("<p>Course: ".htmlentities($l->lessons->title)."</p>\n");
    echo("<p>".htmlentities($l->lessons->description)."</p>\n");
    echo("<p>Modules: ".count($l->lessons->modules)."</p>\n");
    $resource_count = 0;
    $assignment_count = 0;
    foreach($l->lessons->modules as $module) {
        $resources = Lessons::getUrlResources($module);
        if ( ! $resources ) continue;
        $resource_count = $resource_count + count($resources);
        if ( isset($module->lti) ) {
            $assignment_count = $assignment_count + count($module->lti);
        }
    }
    echo("<p>Resources: $resource_count </p>\n");
    echo("<p>Assignments: $assignment_count </p>\n");
    echo("<center>\n");
    echo('<a href="'.$return_url.'" role="button" class="btn btn-success">Import Course</a>');
    echo("</center>\n");
    $OUTPUT->footer();
    return;
}

// here we go...
$service = strtolower($CFG->servicename);
$filename = tempnam(sys_get_temp_dir(), $CFG->servicename);
if ( isCli() ) $filename = 'cc.zip';
$zip = new ZipArchive();
if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
    die("Cannot open $filename\n");
}

if ( ! isCli() ) {
    header( "Content-Type: application/x-zip" );
    header( "Content-Disposition: attachment; filename=\"".$service."_export.imscc\"" );
}

$tsugi_lms = false;
if ( isset($_GET['tsugi_lms']) ) $tsugi_lms = $_GET['tsugi_lms'];

$cc_dom = new CC();
$cc_dom->set_title($CFG->context_title.' import');
$top_module = false;
if ( $tsugi_lms == 'sakai' ) {
    $top_module = $cc_dom->add_module('Imported content');
}

// $cc_dom->set_description('Awesome MOOC to learn PHP, MySQL, and JavaScript.');

foreach($l->lessons->modules as $module) {
    if ( isCli() ) echo("title=$module->title\n");
    if ( $top_module ) {
        $sub_module = $cc_dom->add_sub_module($top_module,$module->title);
    } else {
        $sub_module = $cc_dom->add_module($module->title);
    }

    if ( isset($module->videos) ) {
        foreach($module->videos as $video ) {
            $title = 'Video: '.$video->title;
            $url = 'https://www.youtube.com/watch?v=' . $video->youtube;
            $cc_dom->zip_add_url_to_module($zip, $sub_module, $title, $url);
        }
    }

    if ( isset($module->slides) ) {
        $url = U::absolute_url($module->slides);
        $title = 'Slides: '.$module->title;
        $cc_dom->zip_add_url_to_module($zip, $sub_module, $title, $url);
    }

    if ( isset($module->assignment) ) {
        $url = U::absolute_url($module->assignment);
        $title = 'Assignment: '.$module->title;
        $cc_dom->zip_add_url_to_module($zip, $sub_module, $title, $url);
    }

    if ( isset($module->solution) ) {
        $url = U::absolute_url($module->solution);
        $title = 'Solution: '.$module->title;
        $cc_dom->zip_add_url_to_module($zip, $sub_module, $title, $url);
    }

    if ( isset($module->references) ) {
        foreach($module->references as $reference ) {
            $title = 'Reference: '.$reference->title;
            $url = U::absolute_url($reference->href);
            $cc_dom->zip_add_url_to_module($zip, $sub_module, $title, $url);
        }
    }

    if ( isset($module->lti) ) {
        foreach($module->lti as $lti ) {
            $title = isset($lti->title) ? $lti->title : $module->title;
            $title = 'Tool: '.$title;
            $custom_arr = array();
            if ( isset($lti->custom) ) {
                foreach($lti->custom as $custom) {
                    if ( isset($custom->value) ) {
                        $custom_arr[$custom->key] = $custom->value;
                    }
                    if ( isset($custom->json) ) {
                        $custom_arr[$custom->key] = json_encode($custom->json);
                    }
                }
            }
            $endpoint = U::absolute_url($lti->launch);
            // Sigh - some LMSs don't handle custom - sigh
            $endpoint = U::add_url_parm($endpoint, 'inherit', $lti->resource_link_id);
            $extensions = array('apphome' => $CFG->apphome);
            $cc_dom->zip_add_lti_to_module($zip, $sub_module, $title, $endpoint, $custom_arr, $extensions);
        }
    }
}

$zip->addFromString('imsmanifest.xml',$cc_dom->saveXML());

$zip->close();

if ( isCli() ) {
    echo("\nCLI run: Left zip file on cc.zip\n\n");
    return;
}

// Make sure to delete the file even if the download stops
// http://stackoverflow.com/questions/2641667/deleting-a-file-after-user-download-it

ignore_user_abort(true);
readfile($filename);
unlink($filename);
error_log("Downloaded $filename");




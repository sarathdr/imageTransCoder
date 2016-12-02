<?php
require_once('Settings.php');
require_once('Utils.php');
require_once('ImageTransCoder.php');
//
//// Write to access log if it exists 
Utils::log("Started:-------------" . Utils::getCurrentDate() . "-----------\n");
//
//  // Check to see if GD function exist 
Utils::checkGdLibraryExists();


// Start trans-coding
$transCoder = new ImageTransCoder($_SERVER, $_REQUEST);
$transCoder->startTransCoding();

?>

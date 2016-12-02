<?php

/** Make this {@code true} to switch on debugging  */
define ('DEBUG', false);

define ('ENABLE_ACCESS_LOG', false);

define ('ACCESS_LOG_FILE', "access.log");

/** Number of files to store before clearing cache */
define ('CACHE_SIZE', 5000);

define ('MEMORY', '128M');

/** maximum number of files to delete on each cache clear */
define ('CACHE_CLEAR', 5);

/** Version number (to force a cache refresh)  */
define ('VERSION', '2.0');

/** Cache directory  */
define ('DIRECTORY_CACHE', dirname(__FILE__) . '/cache');

/** Directory to store the original image temporarily  */
define ('DIRECTORY_TEMP', dirname(__FILE__) . '/temp');

/** Default image quality */
define('DEFAULT_QUALITY', 80);

if (DEBUG) {
    // Report simple running errors
    // Reporting E_NOTICE can be good too (to report uninitialized
    // variables or catch variable name misspellings ...)
    error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
} else {
    error_reporting(0);
}

?>
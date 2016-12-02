<?php

/**
 * Utils class for image transcoder
 * User: sarath.dr@gmail.com
 * Date: 20/06/16
 * Time: 16:39
 */
class Utils
{

    const REG_X_NUMBER = "/[^0-9]+/";
    const REG_X_MIME_TYPE = "/jpg|jpeg|gif|png/i";
    const REG_X_LINUX_OS = "/FreeBSD|FREEBSD|LINUX/";
    const DATE_STRING = "Y-m-d H:i:s";
    const UTF_8 = "utf-8";

    private static $_MIME_TYPES = array(
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif'
    );

    /** Method to sanitize all integer request values */
    public static function sanitizeInput($input)
    {
        return preg_replace(self::REG_X_NUMBER, '', $input);
    }

    /**
     * Encode html tags
     * @param $input the input values
     * @return string the sanitised values
     */
    public static function cleanInput($input)
    {
        $input = htmlspecialchars(trim($input));
        return $input;
    }

    /**
     * Checks GD library exists, otherwise display error and exit.
     */
    public static function checkGdLibraryExists()
    {
        // Check to see if GD function exist
        if (!function_exists('imagecreatetruecolor')) {
            self::displayError("GD Library Error: imagecreatetruecolor does not exist -
            please contact your web-host and ask them to install the GD library");
        }
    }

    /**
     * Check the image is a valid mime type
     *
     * @param $mimeType the mime type
     * @return bool {@code true} if it is a valid mime type
     */
    public static function isValidMimeType($mimeType)
    {
        return preg_match(self::REG_X_MIME_TYPE, $mimeType);
    }

    /**
     * Write to access logs
     * @param $logString string the log string
     */
    public static function log($logString)
    {
        if (ENABLE_ACCESS_LOG) {

            if (file_exists(ACCESS_LOG_FILE)) {
                $fp = fopen(ACCESS_LOG_FILE, 'a+');
                fwrite($fp, $logString."\n", strlen($logString));
                fclose($fp);
            }

        }
    }

    /**
     * Gets the current date string
     */
    public static function getCurrentDate()
    {
        return date(self::DATE_STRING);
    }

    /**
     * Display generic error message
     *
     * @param string $errorString the errorString
     */
    public static function displayError($errorString)
    {
        header('HTTP/1.1 400 Bad Request');
        echo '<pre>' . $errorString . '<br /> Image transcoder version : ' . VERSION . '</pre>';
        exit();
    }

    /**
     * Gets Mime type of a file
     * @param $file string the file path
     * @return mixed|string the mime type
     */

    public static function getMimeType($file)
    {

        if (stristr(PHP_OS, 'WIN')) {
            $os = 'WIN';
        } else {
            $os = PHP_OS;
        }

        $mimeType = '';

        if (function_exists('mime_content_type') && $os != 'WIN') {
            $mimeType = mime_content_type($file);
        }

        //Use PECL file info to determine mime type
        if (!self::isValidMimeType($mimeType)) {
            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME);
                if ($finfo != '') {
                    $mimeType = finfo_file($finfo, $file);
                    finfo_close($finfo);
                }
            }
        }

        // Try to determine mime type by using unix file command
        // This should not be executed on windows
        if (!self::isValidMimeType($mimeType) && $os != "WIN") {
            if (preg_match(self::REG_X_LINUX_OS, $os)) {
                $mimeType = trim(@shell_exec('file -bi ' . escapeshellarg($file)));
            }
        }

        // Use file's extension to determine mime type
        if (!self::isValidMimeType($mimeType)) {

            // Set defaults
            $mimeType = 'image/png';

            // file details
            $fileDetails = pathinfo($file);
            $extension = strtolower($fileDetails["extension"]);

            if (strlen($extension) && strlen(self::$_MIME_TYPES[$extension])) {
                $mimeType = self::$_MIME_TYPES[$extension];
            }

        }

        return $mimeType;

    }

}
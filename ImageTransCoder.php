<?php

/**
 * Image transcoder helps to resize the with specified width and height.
 * If the zoom crop is enabled the image will be cropped and resize proportionally
 *</p>
 * It downloads the image into a temporary folder before processing it.
 * Also caches the image.
 *<p/>
 *
 * User: sarath.dr@gmail.com
 *
 * Date: 21/06/16
 * Time: 13:32
 */
class ImageTransCoder
{

    const MINIMUM_SRC_LENGTH = 3;
    const USER_AGENT = "'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.7.5) Gecko/20041107 Firefox/1.0'";
    const HTTP_IF_MODIFIED_SINCE = "HTTP_IF_MODIFIED_SINCE";
    const CACHE_FILE_NAME_SEPARATOR = "_";

    /**
     * Request parameters names
     */
    const REQUEST_PARAM_SOURCE= "src";
    const REQUEST_PARAM_WIDTH = "w";
    const REQUEST_PARAM_HEIGHT = "h";
    const REQUEST_PARAM_ZOOM_CORP = "crop";
    const REQUEST_PARAM_QUALITY = "q";

    /** To hold request headers  */
    private $request;
    private $server;

    /** Image source URL */
    private $imageSourceUrl;

    /** Location of the saved file */
    private $imageFilePath;

    /** Image mime type  */
    private $mimeType;

    /** Location of the cache file */
    private $imageCacheFileName;

    private $newWidth;
    private $newHeight;
    private $quality;
    private $zoomCrop;

    public function __construct($server, $request)
    {
        $this->request = $request;
        $this->server = $server;
    }

    /**
     * Start encoding
     */
    public function startTransCoding()
    {

        $this->imageSourceUrl = $this->getRequestParam(self::REQUEST_PARAM_SOURCE, "");

        // Exit if source URL is not set
        if ($this->imageSourceUrl == '' || strlen($this->imageSourceUrl) <= self::MINIMUM_SRC_LENGTH) {
            exit("REQUIRED:SRC");
        }

        if(DEBUG) {
            Utils::log("\nSaving image locally " . $this->imageSourceUrl);
        }

        // Save image locally in a temporary folder
        $this->saveImageLocally($this->imageSourceUrl);

        $this->setParameters();
        $this->mimeType = Utils::getMimeType($this->imageFilePath);

        if (!Utils::isValidMimeType($this->mimeType)) {
            Utils::displayError('Invalid src mime type: ' . $this->mimeType);
        }

        // Checks and shows the cache file
        $this->showCacheFile();
        $this->drawImage();

    }

    /**
     * Resize and crops the image
     */
    protected function drawImage()
    {

        if (!file_exists($this->imageFilePath)) {
            Utils::displayError("Unable to find local image");
        }

        $this->setMemory();

        // Open the stored image from temp folder
        $image = $this->openImage();

        if ($image === false) {
            Utils::displayError('Unable to open image : ' . $this->imageFilePath);
        }

        // Get original width and height
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);

        // Map the width and height to adjust
        $adjustedWidth = $this->newWidth;
        $adjustedHeight = $this->newHeight;

        if ($adjustedWidth == 0 && $adjustedHeight == 0) {
            $adjustedWidth = $imageWidth;
            $adjustedHeight = $imageHeight;
        }

        // Do not resize if the width is higher than the actual width
        if ($adjustedWidth > $imageWidth) {
            $adjustedWidth = $imageWidth;
        }

        if ($adjustedHeight > $imageHeight) {
            $adjustedHeight = $imageHeight;
        }



        if ($this->zoomCrop) {

            // generate new w/h if not provided
            if ($adjustedWidth > 0 && $adjustedHeight <= 0) {

                $adjustedHeight = $imageHeight * ($adjustedWidth / $imageWidth);

            } elseif ($adjustedHeight > 0 && $adjustedWidth <= 0) {

                $adjustedWidth = $imageWidth * ($adjustedHeight / $imageHeight);
            }

        } else {

            $factorWidth = ($adjustedWidth > 0) ? $adjustedWidth / $imageWidth : 0;
            $factorHeight = ($adjustedHeight > 0) ? $adjustedHeight / $imageHeight : 0;

            if ($adjustedWidth > $adjustedHeight) {
                $adjustedHeight = $imageHeight * $factorWidth;
            } else {
                $adjustedWidth = $imageWidth * $factorHeight;
            }

        }

        // Create a new true color image
        $canvas = imagecreatetruecolor($adjustedWidth, $adjustedHeight);
        imagealphablending($canvas, false);

        // Create a new transparent color for image
        $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

        // Completely fill the background of the new image with allocated color.
        imagefill($canvas, 0, 0, $color);

        // Restore transparency blending
        imagesavealpha($canvas, true);

        if ($this->zoomCrop) {

            $sourceX = $sourceY = 0;

            $sourceWidth = $imageWidth;
            $sourceHeight = $imageHeight;

            $factorWidth = $imageWidth / $adjustedWidth;
            $factorHeight = $imageHeight / $adjustedHeight;

            // calculate x or y coordinate and width or height of source
            if ($factorWidth > $factorHeight) {

                $sourceWidth = round(($imageWidth / $factorWidth * $factorHeight));
                $sourceX = round(($imageWidth - ($imageWidth / $factorWidth * $factorHeight)) / 2);

            } elseif ($factorHeight > $factorWidth) {

                $sourceHeight = round(($imageHeight / $factorHeight * $factorWidth));
                $sourceY = round(($imageHeight - ($imageHeight / $factorHeight * $factorWidth)) / 2);

            }

            imagecopyresampled($canvas, $image, 0, 0, $sourceX, $sourceY, $adjustedWidth, $adjustedHeight, $sourceWidth, $sourceHeight);

        } else {

            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $adjustedWidth, $adjustedHeight, $imageWidth, $imageHeight);

        }

        // Output image to browser based on mime type
        $this->showImage($canvas);

        // remove image from memory
        imagedestroy($canvas);

    }

    /**
     * Open image based on the mime type
     */
    private function openImage()
    {

        $mimeType = strtolower($this->mimeType);

        if (stristr($mimeType, 'gif')) {

            $image = imagecreatefromgif($this->imageFilePath);

        } elseif (stristr($mimeType, 'jpeg')) {

            @ini_set('gd.jpeg_ignore_warning', 1);
            $image = imagecreatefromjpeg($this->imageFilePath);

        } elseif (stristr($mimeType, 'png')) {

            $image = imagecreatefrompng($this->imageFilePath);

        }

        return $image;

    }

    /**
     * Show the image from cache
     * @param $image resource the image
     */
    private function showImage($image)
    {
        $this->createCacheDirectoryIfItDoesNotExist();

        $cacheFileName = null;
        if (touch($this->imageCacheFileName)) {

            // Give 666 permissions so that the developer
            // can overwrite web server user
            chmod($this->imageCacheFileName, 0666);
            $cacheFileName = $this->imageCacheFileName;
        }

        Utils::log("Rendering image:-------------" . Utils::getCurrentDate() . "-----------\n");

        if ($cacheFileName == null) {
            header('Content-type: ' . $this->mimeType);
        }

        switch ($this->mimeType) {

            case 'image/jpeg':
                imagejpeg($image, $cacheFileName, $this->quality);
                break;
            case 'image/png':
                $quality = floor($this->quality * 0.09);
                imagepng($image, $cacheFileName, $quality);
                break;
            case 'image/gif':
                imagegif($image, $cacheFileName);
                break;

            default :
                $quality = floor($this->quality * 0.09);
                imagepng($image, $cacheFileName, $quality);

        }

        if ($cacheFileName != null) {
            $this->showCacheFile();
        }

        imagedestroy($image);
    }

    /**
     * Set all parameters
     */
    private function setParameters()
    {
        $this->newWidth = Utils::sanitizeInput($this->getRequestParam(self::REQUEST_PARAM_WIDTH, 0));
        $this->newHeight = Utils::sanitizeInput($this->getRequestParam(self::REQUEST_PARAM_HEIGHT, 0));    

        $crop = $this->getRequestParam(self::REQUEST_PARAM_ZOOM_CORP, "true");
        $this->zoomCrop = trim($crop) === 'true' ? true : false;

        $this->quality = Utils::sanitizeInput($this->getRequestParam(self::REQUEST_PARAM_QUALITY,
            DEFAULT_QUALITY));

    }

    private function getRequestParam($property, $default = 0)
    {
        if (isset($this->request[$property])) {
            return Utils::cleanInput($this->request[$property]);
        } else {
            return $default;
        }
    }

    /**
     * Tidy up the image source url
     *
     * @param string $source
     */
    private function saveImageLocally($source)
    {

        $host = str_replace('www.', '', $this->server['HTTP_HOST']);
        $regex = "/^((ht|f)tp(s|):\/\/)(www\.|)" . $host . "/i";

        $source = preg_replace($regex, '', $source);
        $source = strip_tags($source);
        $source = $this->saveFileLocallyAndReturnPath($source);

        // Remove slash from start of string
        if (strpos($source, '/') === 0) {
            $source = substr($source, -(strlen($source) - 1));
        }

        // Don't allow users the ability to use '../'
        // in order to gain access to files below document root
        $source = preg_replace("/\.\.+\//", "", $source);
        $source = '/' . $source;

        // Set image file path
        $this->imageFilePath = $source;
    }

    private function saveFileLocallyAndReturnPath($imageSource)
    {

        // Save to temp folder only if it is a remote file
        if (preg_match('/http:\/\//', $imageSource) == true) {

            $fileDetails = pathinfo($imageSource);
            $fileExtension = strtolower($fileDetails['extension']);

            // Save image locally to resize, if it exists, do not download
            $filename = md5($imageSource);
            $localFilePath = DIRECTORY_TEMP . '/' . $filename . '.' . $fileExtension;

            if (!file_exists($localFilePath)) {

                if (function_exists('curl_init')) {

                    $fileHandler = fopen($localFilePath, 'w');
                    $curlHandler = curl_init($imageSource);

                    curl_setopt($curlHandler, CURLOPT_URL, $imageSource);
                    curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curlHandler, CURLOPT_FOLLOWLOCATION, 1);
                    curl_setopt($curlHandler, CURLOPT_HEADER, 0);
                    curl_setopt($curlHandler, CURLOPT_USERAGENT, self::USER_AGENT);
                    curl_setopt($curlHandler, CURLOPT_FILE, $fileHandler);

                    if (curl_exec($curlHandler) === false) {
                        // Remove unfinished file
                        if (file_exists($localFilePath)) {
                            unlink($localFilePath);
                        }

                        Utils::displayError('Error reading file ' . $imageSource
                            . ' from remote host: ' . curl_error($curlHandler));
                    }

                    curl_close($curlHandler);
                    fclose($fileHandler);

                } else {

                    if (!$img = file_get_contents($imageSource)) {
                        Utils::displayError('Remote file for ' . $imageSource
                            . ' can not be accessed. It is likely that the file permissions are restricted');
                    }

                    if (file_put_contents($localFilePath, $img) == false) {
                        Utils::displayError('Error to write temporary file');
                    }

                }

                if (!file_exists($localFilePath)) {
                    Utils::displayError('Local temporary file for ' . $imageSource . ' can not be created');
                }
            }

            $imageSource = $localFilePath;
        }

        return $imageSource;
    }

    private static function createCacheDirectoryIfItDoesNotExist()
    {
        // Make sure cache dir exists
        if (!file_exists(DIRECTORY_CACHE)) {
            // Give 777 permissions so that developer can overwrite
            // files created by web server user
            mkdir(DIRECTORY_CACHE);
            chmod(DIRECTORY_CACHE, 0777);
        }
    }

    private function showCacheFile()
    {

        $this->imageCacheFileName = DIRECTORY_CACHE . '/' . $this->encodeFileNameFromImageUrl();

        // Show cache file only if its exist
        if (!file_exists($this->imageCacheFileName)) {
            return;
        }

        Utils::log("Ended:-------------" . Utils::getCurrentDate() . "-----------\n");
        $cacheFileTimeStamp = gmdate("D, d M Y H:i:s", filemtime($this->imageCacheFileName));

        if (!strstr($cacheFileTimeStamp, "GMT")) {
            $cacheFileTimeStamp .= " GMT";
        }

        if (isset($this->server[self::HTTP_IF_MODIFIED_SINCE])) {

            // Checks if there is any modification
            $ifModifiedSince = preg_replace("/;.*$/", "", $this->server[self::HTTP_IF_MODIFIED_SINCE]);
            if ($ifModifiedSince == $cacheFileTimeStamp) {

                // Send header and exit
                header("HTTP/1.1 304 Not Modified");
                exit();
            }
        }

        clearstatcache();
        $fileSize = filesize($this->imageCacheFileName);

        // send headers then display image
        header('Content-Type: ' . $this->mimeType);
        header('Accept-Ranges: bytes');
        header('Last-Modified: ' . $cacheFileTimeStamp);
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: max-age=9999, must-revalidate');
        header('Expires: ' . $cacheFileTimeStamp);

        readfile($this->imageCacheFileName);
        exit();
    }

    private function setMemory()
    {
        ini_set('memory_limit', MEMORY);
    }

    private function encodeFileNameFromImageUrl()
    {

        // Excludes the defaults from the url
        $excludes = array("https://", "http://", "www.");
        $imageName = str_replace($excludes, "", $this->imageSourceUrl);

        // Change "/" with "_"
        $imageName = str_replace("/", "_", $imageName);
        $crop = $this->zoomCrop ? "true" : "false";

        // Add image width, height and alignment
        $image_name = $this->newWidth
            . self::CACHE_FILE_NAME_SEPARATOR
            . $this->newHeight
            . self::CACHE_FILE_NAME_SEPARATOR
            . $crop
            . self::CACHE_FILE_NAME_SEPARATOR
            . $imageName;

        return $image_name;

    }

}
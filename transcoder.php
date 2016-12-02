<?php

$fp = fopen('access.log', 'a+');

$start_string = "Started:-------------".date('Y-m-d H:i:s')."-----------\n";
fwrite($fp, $start_string, strlen($start_string));

fclose($fp);

// check to see if GD function exist
if(!function_exists('imagecreatetruecolor')) {
    displayError('GD Library Error: imagecreatetruecolor does not exist - please contact your webhost and ask them to install the GD library');
}

define ('CACHE_SIZE', 5000);					// number of files to store before clearing cache
define ('CACHE_CLEAR', 5);					// maximum number of files to delete on each cache clear
define ('VERSION', '1.14');					// version number (to force a cache refresh)
define ('DIRECTORY_CACHE', dirname(__FILE__).'/cache');		// cache directory
define ('DIRECTORY_TEMP', dirname(__FILE__).'/temp');		// temp directory

//error_reporting(0);

if (function_exists('imagefilter') && defined('IMG_FILTER_NEGATE')) {
	$imageFilters = array(
		"1" => array(IMG_FILTER_NEGATE, 0),
		"2" => array(IMG_FILTER_GRAYSCALE, 0),
		"3" => array(IMG_FILTER_BRIGHTNESS, 1),
		"4" => array(IMG_FILTER_CONTRAST, 1),
		"5" => array(IMG_FILTER_COLORIZE, 4),
		"6" => array(IMG_FILTER_EDGEDETECT, 0),
		"7" => array(IMG_FILTER_EMBOSS, 0),
		"8" => array(IMG_FILTER_GAUSSIAN_BLUR, 0),
		"9" => array(IMG_FILTER_SELECTIVE_BLUR, 0),
		"10" => array(IMG_FILTER_MEAN_REMOVAL, 0),
		"11" => array(IMG_FILTER_SMOOTH, 0),
	);
}

// sort out image source
$src = get_request("src", "");
if($src == '' || strlen($src) <= 3) {
    exit('REQUIRED:SRC');
}



// clean params before use
$src = cleanSource($src);



// last modified time (for caching)
$lastModified = filemtime($src);

// get properties
$new_width         = preg_replace("/[^0-9]+/", '', get_request('w', 0));
$new_height     = preg_replace("/[^0-9]+/", '', get_request('h', 0));
$zoom_crop         = preg_replace("/[^0-9]+/", '', get_request('zc', 1));
$quality         = preg_replace("/[^0-9]+/", '', get_request('q', 80));
$filters        = get_request('f', '');
$sharpen        = get_request('s', 0);

if ($new_width == 0 && $new_height == 0) {
    $new_width = 100;
    $new_height = 100;
}

//echo $src;



// get mime type of src
$mime_type = mime_type($src);



// check to see if this image is in the cache already
check_cache ($mime_type);



ini_set('memory_limit', '50M');

// make sure that the src is gif/jpg/png
if(!valid_src_mime_type($mime_type)) {
    displayError('Invalid src mime type: ' . $mime_type);
}

if(strlen($src) && file_exists($src)) {

    // open the existing image
    $image = open_image($mime_type, $src);
    if($image === false) {
        displayError('Unable to open image : ' . $src);
    }

    // Get original width and height
    $width = imagesx($image);
    $height = imagesy($image);
    
    //donot resize if the width is heigher than the actual width
    if( $new_width > $width)
    {
      $new_width = $width;
    }

    // generate new w/h if not provided
    if( $new_width && !$new_height ) {

        $new_height = $height * ( $new_width / $width );

    } elseif($new_height && !$new_width) {

        $new_width = $width * ( $new_height / $height );

    } elseif(!$new_width && !$new_height) {

        $new_width = $width;
        $new_height = $height;

    }

    // create a new true color image
    $canvas = imagecreatetruecolor( $new_width, $new_height );
    imagealphablending($canvas, false);
    // Create a new transparent color for image
    $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    // Completely fill the background of the new image with allocated color.
    imagefill($canvas, 0, 0, $color);
    // Restore transparency blending
    imagesavealpha($canvas, true);

    if( $zoom_crop ) {

        $src_x = $src_y = 0;
        $src_w = $width;
        $src_h = $height;

        $cmp_x = $width  / $new_width;
        $cmp_y = $height / $new_height;

        // calculate x or y coordinate and width or height of source

        if ( $cmp_x > $cmp_y ) {

            $src_w = round( ( $width / $cmp_x * $cmp_y ) );
            $src_x = round( ( $width - ( $width / $cmp_x * $cmp_y ) ) / 2 );

        } elseif ( $cmp_y > $cmp_x ) {

            $src_h = round( ( $height / $cmp_y * $cmp_x ) );
            $src_y = round( ( $height - ( $height / $cmp_y * $cmp_x ) ) / 2 );

        }

        imagecopyresampled( $canvas, $image, 0, 0, $src_x, $src_y, $new_width, $new_height, $src_w, $src_h );

    } else {

        // copy and resize part of an image with resampling
        imagecopyresampled( $canvas, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );

    }

    if ($filters != '' && function_exists('imagefilter') && defined('IMG_FILTER_NEGATE')) {
        // apply filters to image
        $filterList = explode("|", $filters);
        foreach($filterList as $fl) {
            $filterSettings = explode(",", $fl);
            if(isset($imageFilters[$filterSettings[0]])) {

                for($i = 0; $i < 4; $i ++) {
                    if(!isset($filterSettings[$i])) {
                        $filterSettings[$i] = null;
                    }
                }

                switch($imageFilters[$filterSettings[0]][1]) {

                    case 1:

                        imagefilter($canvas, $imageFilters[$filterSettings[0]][0], $filterSettings[1]);
                        break;

                    case 2:

                        imagefilter($canvas, $imageFilters[$filterSettings[0]][0], $filterSettings[1], $filterSettings[2]);
                        break;

                    case 3:

                        imagefilter($canvas, $imageFilters[$filterSettings[0]][0], $filterSettings[1], $filterSettings[2], $filterSettings[3]);
                        break;

                    default:

                        imagefilter($canvas, $imageFilters[$filterSettings[0]][0]);
                        break;

                }
            }
        }
    }

	if ($sharpen > 0 && function_exists('imageconvolution')) {

		$sharpenMatrix = array(
			array(-1,-1,-1),
			array(-1,16,-1),
			array(-1,-1,-1),
		);
		$divisor = 8;
		$offset = 0;

		imageconvolution($canvas, $sharpenMatrix, $divisor, $offset);

	}

    // output image to browser based on mime type
    show_image($mime_type, $canvas);

    // remove image from memory
    imagedestroy($canvas);

} else {

    if (strlen($src)) {
        displayError ('image ' . $src . ' not found');
    } else {
        displayError ('no source specified');
    }

}
/**
if(!function_exists('imageconvolution')){
	function ImageConvolution($src, $filter, $filter_div, $offset){
	    if ($src==NULL) {
	        return 0;
	    }

	    $sx = imagesx($src);
	    $sy = imagesy($src);
	    $srcback = ImageCreateTrueColor ($sx, $sy);
	    ImageAlphaBlending($srcback, false);
	    ImageAlphaBlending($src, false);
	    ImageCopy($srcback, $src,0,0,0,0,$sx,$sy);

	    if($srcback==NULL){
	        return 0;
	    }

	    for ($y=0; $y<$sy; ++$y){
	        for($x=0; $x> 24);

	            for ($j=0; $j<3; ++$j) {
	                $yv = min(max($y - 1 + $j, 0), $sy - 1);
	                for ($i=0; $i> 16) & 0xFF) * $filter[$j][$i];
	                    $new_g += (($rgb >> 8) & 0xFF) * $filter[$j][$i];
	                    $new_b += ($rgb & 0xFF) * $filter[$j][$i];
	                    $new_a += ((0x7F000000 & $rgb) >> 24) * $filter[$j][$i];
	                }
	            }

	            $new_r = ($new_r/$filter_div)+$offset;
	            $new_g = ($new_g/$filter_div)+$offset;
	            $new_b = ($new_b/$filter_div)+$offset;
	            $new_a = ($new_a/$filter_div)+$offset;

	            $new_r = ($new_r > 255)? 255 : (($new_r  255)? 255 : (($new_g  255)? 255 : (($new_b  127)? 127 : (($new_a = 0) && ($y < $sy)) {
	                imagesetpixel($src, $x, $y, $new_pxl);
	            }
	        }
	    }
	    imagedestroy($srcback);
	    return 1;
	}
}
 *
 */
 
/**
 *
 */
 
 
 

 
 
function encode_url_in_img_name( $src, $width ) {
	
	//excludes the defaults from the url
	$excludes = array("https://", "http://", "www.");
	$image_name = str_replace($excludes, "", $src);
	
	//chnage "/" with "_" 
	$image_name = str_replace("/", "_", $image_name);
	
	//add image width
	$image_name = $width."-".$image_name;
	
	return $image_name; 

}




/**
 *
 */
function decode_url_from_img_name( $image_name ) {
	
	$explode = explode("-", $image_name);
	$width = $explode[0];
	$image_url = "http://".str_replace("_", "/", $explode[1]);
	
	return $image_url;

}

/**
 *
 */
function show_image($mime_type, $image_resized) {

    global $quality;
    
   

    // check to see if we can write to the cache directory
    $is_writable = 0;
    $cache_file_name = DIRECTORY_CACHE . '/' . get_cache_file(get_request('src',''), get_request('w',10));

    if (touch($cache_file_name)) {

        // give 666 permissions so that the developer
        // can overwrite web server user
        chmod ($cache_file_name, 0666);
        $is_writable = 1;

    } else {

        $cache_file_name = NULL;
        header ('Content-type: ' . $mime_type);

    }

    switch ($mime_type) {

        case 'image/jpeg':
            imagejpeg($image_resized, $cache_file_name, $quality);
            break;
		case 'image/png':
            imagepng($image_resized, $cache_file_name, $quality);
            break;
		case 'image/gif':
            imagegif($image_resized, $cache_file_name, $quality);
            break;

        default :
            $quality = floor ($quality * 0.09);
            imagepng($image_resized, $cache_file_name, $quality);

    }

    if ($is_writable) {
        show_cache_file ($mime_type);
    }

    imagedestroy ($image_resized);
    
  

    displayError ('error showing image');

}

/**
 *
 */
function get_request( $property, $default = 0 ) {

    if( isset($_REQUEST[$property]) ) {

        return $_REQUEST[$property];

    } else {

        return $default;

    }

}

/**
 *
 */
function open_image($mime_type, $src) {

	$mime_type = strtolower($mime_type);

    if (stristr ($mime_type, 'gif')) {

        $image = imagecreatefromgif($src);

    } elseif (stristr($mime_type, 'jpeg')) {

        @ini_set ('gd.jpeg_ignore_warning', 1);
        $image = imagecreatefromjpeg($src);

    } elseif (stristr ($mime_type, 'png')) {

        $image = imagecreatefrompng($src);

    }

    return $image;

}



/**
 * compare the file time of two files
 */
function filemtime_compare($a, $b) {

    return filemtime($a) - filemtime($b);

}


/**
 * determine the file mime type
 */
function mime_type($file) {

    if (stristr(PHP_OS, 'WIN')) {
        $os = 'WIN';
    } else {
        $os = PHP_OS;
    }

    $mime_type = '';

    if (function_exists('mime_content_type') && $os != 'WIN') {
        $mime_type = mime_content_type($file);
    }

	// use PECL fileinfo to determine mime type
	if (!valid_src_mime_type($mime_type)) {
		if (function_exists('finfo_open')) {
			$finfo = @finfo_open(FILEINFO_MIME);
			if ($finfo != '') {
				$mime_type = finfo_file($finfo, $file);
				finfo_close($finfo);
			}
		}
	}

    // try to determine mime type by using unix file command
    // this should not be executed on windows
    if (!valid_src_mime_type($mime_type) && $os != "WIN") {
        if (preg_match("/FreeBSD|FREEBSD|LINUX/", $os)) {
			$mime_type = trim(@shell_exec('file -bi ' . escapeshellarg($file)));
        }
    }

    // use file's extension to determine mime type
    if (!valid_src_mime_type($mime_type)) {

        // set defaults
        $mime_type = 'image/png';
        // file details
        $fileDetails = pathinfo($file);
        $ext = strtolower($fileDetails["extension"]);
        // mime types
        $types = array(
             'jpg'  => 'image/jpeg',
             'jpeg' => 'image/jpeg',
             'png'  => 'image/png',
             'gif'  => 'image/gif'
         );

        if (strlen($ext) && strlen($types[$ext])) {
            $mime_type = $types[$ext];
        }

    }

    return $mime_type;

}


/**
 *
 */
function valid_src_mime_type($mime_type) {

    if (preg_match("/jpg|jpeg|gif|png/i", $mime_type)) {
        return true;
    }

    return false;

}


/**
 *
 */
function check_cache ($mime_type) {

    // make sure cache dir exists
    if (!file_exists(DIRECTORY_CACHE)) {
        // give 777 permissions so that developer can overwrite
        // files created by web server user
        mkdir(DIRECTORY_CACHE);
        chmod(DIRECTORY_CACHE, 0777);
    }

    show_cache_file ($mime_type);

}


/**
 *
 */
function show_cache_file ($mime_type) {
  
  
     $fp = fopen('access.log', 'a+');
  
     $end_string = "Ended:-------------".date('Y-m-d H:i:s')."-----------\n";
     fwrite($fp, $end_string, strlen($end_string) );

     fclose($fp);
  
	
	global $src;
	global $new_width;

    $cache_file = DIRECTORY_CACHE . '/' . get_cache_file($src, $new_width);

    if (file_exists($cache_file)) {

        $gmdate_mod = gmdate("D, d M Y H:i:s", filemtime($cache_file));

        if(! strstr($gmdate_mod, "GMT")) {
            $gmdate_mod .= " GMT";
        }

        if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"])) {

            // check for updates
            $if_modified_since = preg_replace ("/;.*$/", "", $_SERVER["HTTP_IF_MODIFIED_SINCE"]);

            if ($if_modified_since == $gmdate_mod) {
                header("HTTP/1.1 304 Not Modified");
                die();
            }

        }

		clearstatcache();
        $fileSize = filesize ($cache_file);

        // send headers then display image
        header ('Content-Type: ' . $mime_type);
        header ('Accept-Ranges: bytes');
        header ('Last-Modified: ' . $gmdate_mod);
        header ('Content-Length: ' . $fileSize);
        header ('Cache-Control: max-age=9999, must-revalidate');
        header ('Expires: ' . $gmdate_mod);

        readfile ($cache_file);
		exit;

       

    }

}


/**
aaaaaaaaaaaa
 *
 */
function get_cache_file( $src, $width ) {

    global $lastModified;
    static $cache_file;

    if (!$cache_file) {
        $cachename = $_SERVER['QUERY_STRING'] . VERSION . $lastModified;
        //$cache_file = md5($cachename) . '.png';
		$cache_file = encode_url_in_img_name( $_GET['src'], $width );
    }

    return $cache_file;

}


/**
 * check to if the url is valid or not
 */
function valid_extension ($ext) {

    if (preg_match("/jpg|jpeg|png|gif/i", $ext)) {
        return TRUE;
    } else {
        return FALSE;
    }

}


/**
 *
 */
function checkExternal ($src) {

    $allowedSites = array(
        
		'rbt4all.net'
    );

    if (preg_match('/http:\/\//', $src) == true) {

        $url_info = parse_url ($src);

        $isAllowedSite = false;
        foreach ($allowedSites as $site) {
			$site = '/' . addslashes($site) . '/';
            if (preg_match($site, $url_info['host']) == true) {
                $isAllowedSite = true;
            }
		}

    $isAllowedSite = true;

		if ($isAllowedSite) {

			$fileDetails = pathinfo($src);
			$ext = strtolower($fileDetails['extension']);

			$filename = md5($src);
			$local_filepath = DIRECTORY_TEMP . '/' . $filename . '.' . $ext;

            if (!file_exists($local_filepath)) {

                if (function_exists('curl_init')) {

                    $fh = fopen($local_filepath, 'w');
                    $ch = curl_init($src);

                    curl_setopt($ch, CURLOPT_URL, $src);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
					curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.7.5) Gecko/20041107 Firefox/1.0');
                    curl_setopt($ch, CURLOPT_FILE, $fh);

					if (curl_exec($ch) === FALSE) {
						if (file_exists($local_filepath)) {
							unlink($local_filepath);
						}
						displayError('error reading file ' . $src . ' from remote host: ' . curl_error($ch));
					}

					curl_close($ch);
					fclose($fh);

                } else {

                    if (!$img = file_get_contents($src)) {
                        displayError('remote file for ' . $src . ' can not be accessed. It is likely that the file permissions are restricted');
                    }

                    if (file_put_contents($local_filepath, $img) == FALSE) {
                        displayError('error writing temporary file');
                    }

                }

                if (!file_exists($local_filepath)) {
					
                    displayError('local file for ' . $src . ' can not be created');
                }

            }

            $src = $local_filepath;

        } else {

            displayError('remote host "' . $url_info['host'] . '" not allowed');

        }

    }

    return $src;

}


/**
 * tidy up the image source url
 */
function cleanSource($src) {

	$host = str_replace('www.', '', $_SERVER['HTTP_HOST']);
	$regex = "/^((ht|f)tp(s|):\/\/)(www\.|)" . $host . "/i";

	$src = preg_replace ($regex, '', $src);
	$src = strip_tags ($src);
	
	
    $src = checkExternal ($src);
    

	

    // remove slash from start of string
    if (strpos($src, '/') === 0) {
        $src = substr ($src, -(strlen($src) - 1));
    }

    // don't allow users the ability to use '../'
    // in order to gain access to files below document root
    $src = preg_replace("/\.\.+\//", "", $src);

    // get path to image on file system
    $src = get_document_root($src) . '/' . $src;

    return $src;

}


/**
 *
 */
function get_document_root ($src) {

    return '';

}


/**
 * generic error message
 */
function displayError ($errorString = '') {

    //header('HTTP/1.1 400 Bad Request');
	//echo '<pre>' . $errorString . '<br />TimThumb version : ' . VERSION . '</pre>';
    die();

}
?>
<?php

/* IMAGE-WATCHER
 *
 * VERSIONS:
 *
 *  1.0		- Initial prototype
 *
 *
 */

// Load the composer auto-load deal so we have access to our third party libraries
require __DIR__ . '/vendor/autoload.php';

// Load libraries
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Intervention\Image\ImageManager;
use Seafile\Client\Http\Client as Client;
use Seafile\Client\Resource\Library as Library;
use Seafile\Client\Resource\File as File;
use Seafile\Client\Resource\Directory as Directory;

// Set up the timezone first
$php_timezone = 'UTC';		// Override with CAM_PHP_TIMEZONE

// Override configuration with env variables if they exit
if (array_key_exists('CAM_PHP_TIMEZONE', $_ENV)) {
	$php_timezone = $_ENV['CAM_PHP_TIMEZONE'];
}

// Set up the timezone
date_default_timezone_set($php_timezone);

// Create the logger 
$logger = new Logger('image-watcher');
$logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

//$log_filename = date("YmdHis") . ".log";
//$logger->pushHandler(new StreamHandler(__DIR__ . '/' . $log_filename, Logger::DEBUG));

// Mark the global start time
$global_start_time = microtime(true);

// Mark the interval start times
$clean_interval_start_time = microtime(true);
$encryption_interval_start_time = microtime(true);

log_info("Timezone set and logger created");

// Default configuration variables
$log_debug = false;		// Override with CAM_LOG_DEBUG
$max_width = 1280;		// Override with CAM_MAX_WIDTH
$output_quality = 80;	// Override with CAM_OUTPUT_QUALITY
$uploadAttemptLimit = 5;
$uploadAttempts = 0;

$interval_time_secs = 60;			// Override with CAM_INTERVAL_TIME_SECS
$clean_up_interval_mins = 60;		// Override with CAM_CLEAN_TIME_MINS
$retention_period_hours = 24;		// Override with CAM_RETENTION_TIME_HOURS
$encryption_timeout_mins = 60;		// Override with CAM_ENCRYPT_TIMEOUT_MINS

$font_file = "/usr/share/fonts/truetype/droid/DroidSans-Bold.ttf";		// Use this when running on Debian
//$font_file = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf";	// Use this when running on Ubuntu

// Get the camera URLs from environment varaibles
log_info("Reading environment configuration");
$camera_urls = getEnvironmentUrls();

// Override configuration with env variables if they exit
if (array_key_exists('CAM_LOG_DEBUG', $_ENV)) {
	if ($_ENV['CAM_LOG_DEBUG'] == "true" || $_ENV['CAM_LOG_DEBUG'] == 1) {
		$log_debug = true;
	}
}

if (array_key_exists('CAM_OUTPUT_QUALITY', $_ENV)) {
	$output_quality = $_ENV['CAM_OUTPUT_QUALITY'];
	if (!is_numeric($output_quality)) {
		log_error("Cannot accept CAM_OUTPUT_QUALITY of " . $output_quality);
		exit(1);
	}
}

if (array_key_exists('CAM_MAX_WIDTH', $_ENV)) {
	$max_width = $_ENV['CAM_MAX_WIDTH'];
	if (!is_numeric($max_width) || $max_width < 10) {
		log_error("Cannot accept CAM_MAX_WIDTH of " . $max_width);
		exit(1);
	}
	$max_width = round($max_width);
}

if (array_key_exists('CAM_INTERVAL_TIME_SECS', $_ENV)) {
	$interval_time_secs = $_ENV['CAM_INTERVAL_TIME_SECS'];
	if (!is_numeric($interval_time_secs)) {
		log_error("Cannot accept CAM_INTERVAL_TIME_SECS of " . $interval_time_secs);
		exit(1);
	}
}

if (array_key_exists('CAM_CLEAN_TIME_MINS', $_ENV)) {
	$clean_up_interval_mins = $_ENV['CAM_CLEAN_TIME_MINS'];
	if (!is_numeric($clean_up_interval_mins)) {
		log_error("Cannot accept CAM_CLEAN_TIME_MINS of " . $clean_up_interval_mins);
		exit(1);
	}
}

if (array_key_exists('CAM_RETENTION_TIME_HOURS', $_ENV)) {
	$retention_period_hours = $_ENV['CAM_RETENTION_TIME_HOURS'];
	if (!is_numeric($retention_period_hours)) {
		log_error("Cannot accept CAM_RETENTION_TIME_HOURS of " . $retention_period_hours);
		exit(1);
	}
}

if (array_key_exists('CAM_ENCRYPT_TIMEOUT_MINS', $_ENV)) {
	$encryption_timeout_mins = $_ENV['CAM_ENCRYPT_TIMEOUT_MINS'];
	if (!is_numeric($encryption_timeout_mins)) {
		log_error("Cannot accept CAM_ENCRYPT_TIMEOUT_MINS of " . $encryption_timeout_mins);
		exit(1);
	}
}

if (!array_key_exists('CAM_SEAFILE_URL', $_ENV)) {
	log_error("Cannot find CAM_SEAFILE_URL in the environment variables");
	exit(1);
}
else {
	$seafile_url = $_ENV['CAM_SEAFILE_URL'];
}

if (!array_key_exists('CAM_SEAFILE_APITOKEN', $_ENV)) {
	log_error("Cannot find CAM_SEAFILE_APITOKEN in the environment variables");
	exit(1);
}
else {
	$seafile_apitoken = $_ENV['CAM_SEAFILE_APITOKEN'];
}

if (!array_key_exists('CAM_SEAFILE_LIBRARY_ID', $_ENV)) {
	log_error("Cannot find CAM_SEAFILE_LIBRARY_ID in the environment variables");
	exit(1);
}
else {
	$seafile_library_id = $_ENV['CAM_SEAFILE_LIBRARY_ID'];
}

if (array_key_exists('CAM_SEAFILE_ENCRYPTION_KEY', $_ENV)) {
	$seafile_encryption_key = $_ENV['CAM_SEAFILE_ENCRYPTION_KEY'];
}
else {
	$seafile_encryption_key = null;
}

if (array_key_exists('CAM_SEAFILE_DIRECTORY', $_ENV)) {
	$seafile_directory = $_ENV['CAM_SEAFILE_DIRECTORY'];
}
else {
	$seafile_directory = "/";
}



// Now try initializing the Seafile client
try {
	log_info("Initializing Seafile client");
	$client = new Client(
		[
			'base_uri' => $seafile_url,
			'debug' => false,
			'headers' => [
				'Authorization' => 'Token ' . $seafile_apitoken
			]
		]
	);

	log_debug("Getting Seafile target library by ID: " . $seafile_library_id);
	$libraryResource = new Library($client);
	$target_library = $libraryResource->getById($seafile_library_id);

	// If we have an encryption key, the try to decrypt the resource
	decryptSeafileLibrary();

	log_debug("Successfully retrieved Seafile target library: " . $target_library->name);
	log_info("Successfully initialized Seafile client");
}
catch (Exception $e) {
	log_error("Error initializing Seafile and accessing library. Please check your configuration. Error message received: " . $e->getMessage());
	exit(1);
}

// Exit if we didn't find any URLs to monitor
if (count($camera_urls) == 0) {
	log_error("Exiting due to no CAM_IMAGE_URLx configurations detected in the environment variables");
	exit(1);
}

// Create an image manager instance with the imagick driver
log_info("Initializing ImageManager");
$manager = new ImageManager(array('driver' => 'imagick'));

// Infinite loop - TODO: Determine better way to manage execution
while(true) {

	$start_time = microtime(true);

	// Array to store the images downloaded
	$image_list = array();
	
	log_info("Retrieving images");

	foreach($camera_urls as $cam_url) {
	
		try {
			// Ask the manager to make an image from the URL
			$image = $manager->make($cam_url)->resize(640,480);
			array_push($image_list, $image);
			log_debug("Received image from " . $cam_url);
		}
		catch(Exception $e) {
			log_error("Error occurred trying to retrieve image via URL: " . $e->getMessage());
			array_push($image_list, null);
		}
	}
	
	log_debug("Assembling images");
	switch(count($camera_urls)) {
		case 1:
			$dest_image = $manager->canvas(640, 480,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			break;
		case 2:
			$dest_image = $manager->canvas(640, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"bottom-left"); }
			break;
		case 3:
			$dest_image = $manager->canvas(1280, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top-right"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"bottom-left"); }
			break;
		case 4:
			$dest_image = $manager->canvas(1280, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top-right"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"bottom-left"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"bottom-right"); }
			break;
		case 5:
			$dest_image = $manager->canvas(1920, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"bottom-left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"bottom"); }
			break;
		case 6:
			$dest_image = $manager->canvas(1920, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"bottom-left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"bottom"); }
			if ($image_list[5] != null) { $dest_image->insert($image_list[5],"bottom-right"); }
			break;
		case 7:
			$dest_image = $manager->canvas(1920, 1440,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"center"); }
			if ($image_list[5] != null) { $dest_image->insert($image_list[5],"right"); }
			if ($image_list[6] != null) { $dest_image->insert($image_list[6],"bottom-left"); }
			break;
		case 8:
			$dest_image = $manager->canvas(1920, 1440,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"center"); }
			if ($image_list[5] != null) { $dest_image->insert($image_list[5],"right"); }
			if ($image_list[6] != null) { $dest_image->insert($image_list[6],"bottom-left"); }
			if ($image_list[7] != null) { $dest_image->insert($image_list[7],"bottom"); }
			break;
		case 9:
			$dest_image = $manager->canvas(1920, 1440,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"center"); }
			if ($image_list[5] != null) { $dest_image->insert($image_list[5],"right"); }
			if ($image_list[6] != null) { $dest_image->insert($image_list[6],"bottom-left"); }
			if ($image_list[7] != null) { $dest_image->insert($image_list[7],"bottom"); }
			if ($image_list[8] != null) { $dest_image->insert($image_list[8],"bottom-right"); }
			break;
	}
	

	// Scale the image to the minimum of max_width or the actual image width
	
	if ($dest_image->width() > $max_width) {
		log_debug("Resizing composite image");
		$dest_image->resize($max_width, null, function ($constraint) {
			$constraint->aspectRatio();
		});
	}

	// Add the timestamp to the top
	$timestamp = date('r');
	$filename = date('Y-m-d-H-i-s') . ".jpg";

	log_debug("Adding text overlay");
	$dest_image->text($timestamp, $dest_image->width()/2, 0, function($font) use ($font_file) {
		$font->file($font_file);
		$font->align('center');
		$font->valign('top');
		$font->size(12);
		$font->color('#ff0000');
	});
	
	log_debug("Saving composite image to " . $filename . " with quality of " . $output_quality);
	$dest_image->save($filename, $output_quality);

	log_debug("Sending " . $filename . " to seafile");
	sendFileToSeafile($filename);
	log_info("Sent " . $filename . " to seafile");

	log_debug("Removing " . $filename);
	unlink($filename);

	// Remove old files from seafile
	if ( (microtime(true) - $clean_interval_start_time) > ($clean_up_interval_mins * 60) ) {
		log_debug("Removing old files from seafile");
		$clean_interval_start_time = microtime(true);
		removeOldFiles();	
	}

	// Remove old files from seafile
	if ( (microtime(true) - $encryption_interval_start_time) > ($encryption_timeout_mins * 60) ) {
		log_info("Re-requesting library decryption");
		$encryption_interval_start_time = microtime(true);
		decryptSeafileLibrary();	
	}

	$execution_time = microtime(true) - $start_time;
	log_debug("Completed regular cycle in " . round($execution_time,2) . " secs");

	// Calculate sleep time
	$sleep_time = max(1, $interval_time_secs-round($execution_time));
	log_debug("Sleeping for " . $sleep_time . " secs");
	
	// Sleep for remainder of time	
	sleep($sleep_time);

}

/* *************************************************************************************
 * UTILITY FUNCTIONS
 */

function sendFileToSeafile($filepath) {

	global $client, $target_library, $seafile_directory, $uploadAttempts, $uploadAttemptLimit;

/*
	log_debug("Client: " . print_r($client,true));
	log_debug("\\n\n");
	log_debug("Target LIbrary: " . print_r($target_library,true));
	log_debug(print_r($seafile_directory,true));
	log_debug(print_r($filepath,true));
*/	

	// Test uploading file
	log_debug("Attempting to upload file " . $filepath);
	
	// Create a file resource
	$fileResource = new File($client);

	// Try to upload the file
	try {
		$response = $fileResource->upload($target_library, $filepath, $seafile_directory);
		$uploadedFileId = json_decode((string)$response->getBody());
	}
	catch (Exception $e) {
		log_error("Exception found in trying to upload file to seafile. Try checking your configuration. This may occur if you are utilizing an encrypted library without an specified encryption key via CAM_SEAFILE_ENCRYPTION_KEY. Error message: " . $e->getMessage());
		$uploadAttempts++;

		if ($uploadAttempts > $uploadAttemptLimit) {
			log_error("Exceeded upload attempt limit. Throwing error to exit");
			throw $e;
		}
	}

	log_debug("Uploaded file " . $filepath);
}


function removeOldFiles() {

	global $client, $logger, $target_library, $seafile_directory, $retention_period_hours;

	log_debug("Attempting to remove files older than " . $retention_period_hours . " hours");

	try {
		// Mark the time right now
		$now = new DateTime();

		// Create the new resources needed to get the files
		$directoryResource = new Directory($client);
		$fileResource = new File($client);
	
		// Get all of the items in that directory
		$items = $directoryResource->getAll($target_library, $seafile_directory);

		// Iterate through all the items
		foreach ($items as $item) {
	
			if ($item->type == "file")  {

				// Calculate the time difference
				$timediff = $now->getTimeStamp() - $item->mtime->getTimeStamp();
	
				log_debug("Checking item " . $item->name . " - time difference " . $timediff);
			
				// If the difference between now and the file's timestamp is more than the retention period hours
				if ($timediff > ($retention_period_hours * 60 * 60)) {
		
					// Create the path
					$remove_path = $seafile_directory . "/" . $item->name;
				
					// Try to remove the file
					if ($fileResource->remove($target_library, $remove_path)) {
						log_info("Removed: " . $remove_path);
					}
					else {
						log_warning("Could not remove: " . $remove_path);
					}
				}
			}
		}
	}
	catch (Exception $e) {
		log_error("Could not remove old files. Error message: " . $e->getMessage());
	}
}

function getEnvironmentUrls() {

	global $logger;

	$camera_urls = array();

	// Dynamically check for up to 9 image urls in the environment vars
	for($i=1; $i<10; $i++) {

		// Form the variable
		$env_variable_name = "CAM_IMAGE_URL" . $i;
	
		// See if it exists in the environment
		if (array_key_exists($env_variable_name, $_ENV)) {
			array_push($camera_urls, $_ENV[$env_variable_name]);
			log_debug("Found camera url: " . $_ENV[$env_variable_name]);
		}
	}
	
	return $camera_urls;
}


function decryptSeafileLibrary() {

	global $seafile_encryption_key, $seafile_library_id, $libraryResource;

	// If we have an encryption key, the try to decrypt the resource
	if ($seafile_encryption_key != null) {
		log_info("Decrypting library resource: " . $seafile_library_id);
		$success = $libraryResource->decrypt($seafile_library_id, ['query' => ['password' => $seafile_encryption_key]]);
		if ($success) {
			log_info("Successfully decryped library resource: " . $seafile_library_id);
		}
		else {
			log_error("Was not able to decrypt library resource: " . $seafile_library_id);
			exit(1);
		}
	}
}

function log_debug($a) {
	global $logger, $log_debug;
	if ($log_debug) {
		$logger->addDebug($a);
	}
}

function log_info($a) {
	global $logger;
	$logger->addInfo($a);
}

function log_error($a) {
	global $logger;
	$logger->addError($a);
}

function log_warning($a) {
	global $logger;
	$logger->addWarning($a);
}


?>
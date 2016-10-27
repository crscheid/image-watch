<?php

/* IMAGE-WATCHER
 *
 * VERSIONS:
 *
 *  1.0		- Initial prototype
 *  1.1		- Rewrite method for environment variable handling and local file system support
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

// Set up the global configuration variable
$config = array();

// Set up global seafile reference
$seafile_client = null;
$seafile_library = null;
$seafile_library_resource = null;

// Set up the timezone and the logger
$logger = setupTimezoneAndLogger();
log_info("Initialized logger");

// Read the configuration
log_info("Reading configuration");
readConfiguration();

// Create an image manager instance with the imagick driver
log_info("Initializing ImageManager");
$image_manager = new ImageManager(array('driver' => 'imagick'));

// Attempt to initialize required storage repositories
log_info("Initializing storage repository connections");
initializeSeafile();

// Initialize the various timers
log_info("Initializing timers");
$clean_interval_start_time = microtime(true);
$encryption_interval_start_time = microtime(true);

// The following loop is the main loop that runs on every cycle. It calls the processImages()
// function and handles the timing of various other tasks like encryption renewal and calling
// for cleanup of old images.
while(true) {

	// Start time of the current cycle
	$cycle_start_time = microtime(true);

	// This is the main worker
	processImages();

	// Calculate the execution time
	$execution_time = microtime(true) - $cycle_start_time;
	log_debug("Completed regular cycle in " . round($execution_time,2) . " secs");

	// Calculate the remaining sleep time
	$sleep_time = max(1, $config['CAM_INTERVAL_TIME_SECS']-round($execution_time));
	log_debug("Sleeping for " . $sleep_time . " secs");
	sleep($sleep_time);

}



/* *************************************************************************************
 * UTILITY FUNCTIONS
 */


/*
 * This is the main routine that downloads and assembles images
 */
function processImages() {

	global $config, $image_manager, $clean_interval_start_time, $encryption_interval_start_time;

	// Retrieve all the images into an array
	$image_list = retrieveImages();
	
	// Composite the images together in a grid
	$dest_image = compositeImages($image_list);
	
	// Save the composite image 
	saveImage($dest_image);

	// Remove old files if needed
	if ( (microtime(true) - $clean_interval_start_time) > ($config['CAM_CLEAN_TIME_MINS'] * 60) ) {
		log_debug("Cleaning old files");
		$clean_interval_start_time = microtime(true);
		cleanUpImages();	
	}

	// If our storage method was seafile, then attempt to renew the decryption according to the configuration
	if ($config['CAM_STORAGE_METHOD'] == "seafile") {
		if ( (microtime(true) - $encryption_interval_start_time) > ($config['CAM_SEAFILE_ENCRYPT_TIMEOUT_MINS'] * 60) ) {
			log_info("Requesting library decryption");
			$encryption_interval_start_time = microtime(true);
			decryptSeafileLibrary();	
		}
	}
}

/*
 * This function downloads the images, resizes them, and returns an array of all of the 
 * images that were downloaded.
 */
function retrieveImages() {

	global $config, $image_manager;

	log_debug("Retrieving images");

	// Array to store the images downloaded
	$image_list = array();
	
	// Cycle through each configured URL
	foreach($config['CAM_URLS'] as $cam_url) {
	
		try {
			// Ask the manager to make an image from the URL and reside it to 640 x 480
			$image = $image_manager->make($cam_url)->resize(640,480);
			array_push($image_list, $image);
			log_debug("Received image from " . $cam_url);
		}
		catch(Exception $e) {
			log_error("Error occurred trying to retrieve image via URL: " . $e->getMessage());
			array_push($image_list, null);
		}
	}
	
	return $image_list;
}

/*
 * This function takes a list of images and composites them into an appropriately shaped
 * grid, returning the resultant image.
 */
function compositeImages($image_list) {

	global $config, $image_manager;

	log_debug("Compositing images to grid");
	switch(count($config['CAM_URLS'])) {
		case 1:
			$dest_image = $image_manager->canvas(640, 480,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			break;
		case 2:
			$dest_image = $image_manager->canvas(640, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"bottom-left"); }
			break;
		case 3:
			$dest_image = $image_manager->canvas(1280, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top-right"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"bottom-left"); }
			break;
		case 4:
			$dest_image = $image_manager->canvas(1280, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top-right"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"bottom-left"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"bottom-right"); }
			break;
		case 5:
			$dest_image = $image_manager->canvas(1920, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"bottom-left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"bottom"); }
			break;
		case 6:
			$dest_image = $image_manager->canvas(1920, 960,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"bottom-left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"bottom"); }
			if ($image_list[5] != null) { $dest_image->insert($image_list[5],"bottom-right"); }
			break;
		case 7:
			$dest_image = $image_manager->canvas(1920, 1440,'#000');
			if ($image_list[0] != null) { $dest_image->insert($image_list[0],"top-left"); }
			if ($image_list[1] != null) { $dest_image->insert($image_list[1],"top"); }
			if ($image_list[2] != null) { $dest_image->insert($image_list[2],"top-right"); }
			if ($image_list[3] != null) { $dest_image->insert($image_list[3],"left"); }
			if ($image_list[4] != null) { $dest_image->insert($image_list[4],"center"); }
			if ($image_list[5] != null) { $dest_image->insert($image_list[5],"right"); }
			if ($image_list[6] != null) { $dest_image->insert($image_list[6],"bottom-left"); }
			break;
		case 8:
			$dest_image = $image_manager->canvas(1920, 1440,'#000');
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
			$dest_image = $image_manager->canvas(1920, 1440,'#000');
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

	// Scale the composite image to the minimum of max_width or the actual image width
	if ($dest_image->width() > $config['CAM_MAX_WIDTH']) {
		log_debug("Resizing composite image");
		$dest_image->resize($config['CAM_MAX_WIDTH'], null, function ($constraint) {
			$constraint->aspectRatio();
		});
	}

	// Add the text overlay to the composite image
	log_debug("Adding text overlay");
	$timestamp = date('r');
	$dest_image->text($timestamp, $dest_image->width()/2, 0, function($font) use ($config) {
		$font->file($config['CAM_FONT']);
		$font->align('center');
		$font->valign('top');
		$font->size($config['CAM_FONT_SIZE']);
		$font->color($config['CAM_FONT_COLOR']);
	});
	
	return $dest_image;
}

/*
 * This function saves an image resource to the repository based on the configuration. It
 * handles sending the file to the appropriate location.
 */
function saveImage($image) {

	global $config;
	
	// Create a filename for the image
	$filename = date('Y-m-d-H-i-s') . ".jpg";

	// If the we are doing local files
	if ($config['CAM_STORAGE_METHOD'] == "local") {
		$filename = $config['CAM_STORAGE_DIRECTORY'] . "/" . $filename;
		log_debug("Saving composite image to " . $filename . " with quality of " . $config['CAM_OUTPUT_QUALITY']);
		$image->save($filename, $config['CAM_OUTPUT_QUALITY']);
		log_info("Saved " . $filename );
	}
	else if ($config['CAM_STORAGE_METHOD'] == "seafile") {
		log_debug("Saving composite image to " . $filename . " with quality of " . $config['CAM_OUTPUT_QUALITY']);
		$image->save($filename, $config['CAM_OUTPUT_QUALITY']);

		log_debug("Sending " . $filename . " to seafile");
		sendFileToSeafile($filename);
		log_debug("Removing local copy of " . $filename);
		unlink($filename);

		log_info("Saved " . $filename . " to seafile");
	}
}

/*
 * Transfers a file on the local disk to seafile
 */
function sendFileToSeafile($filepath) {

	global $config, $seafile_client, $seafile_library;

	// Test uploading file
	log_debug("Attempting to upload file " . $filepath);
	
	// Create a file resource
	$fileResource = new File($seafile_client);

	// Try to upload the file
	try {
		$response = $fileResource->upload($seafile_library, $filepath, $config['CAM_STORAGE_DIRECTORY']);
		$uploadedFileId = json_decode((string)$response->getBody());
		log_debug("Uploaded file " . $filepath);
	}
	catch (Exception $e) {
		log_error("Exception found in trying to upload file to seafile. Try checking your configuration. This may occur if you are utilizing an encrypted library without an specified encryption key via CAM_SEAFILE_ENCRYPTION_KEY. Error message: " . $e->getMessage());
	}
}

/*
 * Function to clean up the old images. Calls the appropriate function based on the type
 * of storage method being used.
 */
function cleanUpImages() {

	global $config;
	log_debug("Attempting to remove files older than " . $config['CAM_RETENTION_TIME_HOURS'] . " hours");

	if ($config['CAM_STORAGE_METHOD'] == "seafile") {
		cleanUpImagesSeafile();
	}
	else {
		cleanUpImagesLocal();
	}
}


/*
 * Clean up function specific to Seafile. Looks for old images and attempts to remove them
 */
function cleanUpImagesSeafile() {

	global $config, $seafile_client, $seafile_library;

	try {
		// Indicate the time right now
		$now = new DateTime();

		// Create the new resources needed to get the files
		$directoryResource = new Directory($seafile_client);
		$fileResource = new File($seafile_client);

		// Get all of the items in that directory
		$items = $directoryResource->getAll($seafile_library, $config['CAM_STORAGE_DIRECTORY']);

		// Iterate through all the items
		foreach ($items as $item) {

			// Make sure its a file
			if ($item->type == "file")  {

				// Calculate the time difference
				$timediff = $now->getTimeStamp() - $item->mtime->getTimeStamp();
				log_debug("Checking item " . $item->name . " - time difference " . $timediff);
		
				// If the difference between now and the file's timestamp is more than the retention period hours
				if ($timediff > ($config['CAM_RETENTION_TIME_HOURS'] * 60 * 60)) {
	
					// Create the full path for the file
					$remove_path = $config['CAM_STORAGE_DIRECTORY'] . "/" . $item->name;
			
					// Try to remove the file
					if ($fileResource->remove($seafile_library, $remove_path)) {
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
		log_error("Could not remove old files from Seafile. Error message: " . $e->getMessage());
	}
}


/*
 * Clean up function specific to Seafile. Looks for old images and attempts to remove them
 */
function cleanUpImagesLocal() {

	global $config;

	try {
		// Indicate the time right now
		$now = new DateTime();

		// Get all of the items in the local directory
		$items = scandir($config['CAM_STORAGE_DIRECTORY']);

		// Iterate through all the items
		foreach ($items as $item) {
		
			$filename = $config['CAM_STORAGE_DIRECTORY'] . "/" . $item;

			// Make sure its a jpg file
			if (substr($item,-4) == ".jpg")  {

				// Calculate the time difference
				$timediff = $now->getTimeStamp() - filemtime($filename);
				log_debug("Checking item " . $filename . " - time difference " . $timediff);
		
				// If the difference between now and the file's timestamp is more than the retention period hours
				if ($timediff > ($config['CAM_RETENTION_TIME_HOURS'] * 60 * 60)) {
					unlink($filename);
					log_info("Removed: " . $filename);
				}
			}
		}
	}
	catch (Exception $e) {
		log_error("Could not remove old file from local directory. Error message: " . $e->getMessage());
	}
}

/*
 * Initializes the timezone and passes back a logger instance
 */
function setupTimezoneAndLogger() {

	$config['CAM_PHP_TIMEZONE'] = 'UTC';

	// Override configuration with env variables if they exit
	if (array_key_exists('CAM_PHP_TIMEZONE', $_ENV)) {
		$config['CAM_PHP_TIMEZONE'] = $_ENV['CAM_PHP_TIMEZONE'];
	}

	// Set up the timezone
	date_default_timezone_set($config['CAM_PHP_TIMEZONE']);

	// Create the logger 
	$logger = new Logger('image-watcher');
	$logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());
	//$log_filename = date("YmdHis") . ".log";
	//$logger->pushHandler(new StreamHandler(__DIR__ . '/' . $log_filename, Logger::DEBUG));
	
	// Return the instance of the logger
	return $logger;

}

/*
 * Reads in all configuration information and loads it into the $config global variable.
 * Performs error / sanity checking on configuration information.
 */
function readConfiguration() {

	global $config;

	// Set the defaults
	log_info("Setting default configuration");
	$config['CAM_LOG_DEBUG'] = false;
	$config['CAM_STORAGE_METHOD'] = "local";
	$config['CAM_MAX_WIDTH'] = 1280;
	$config['CAM_OUTPUT_QUALITY'] = 80;
	$config['CAM_INTERVAL_TIME_SECS'] = 60;
	$config['CAM_CLEAN_TIME_MINS'] = 60;
	$config['CAM_RETENTION_TIME_HOURS'] = 24;
	$config['CAM_SEAFILE_ENCRYPT_TIMEOUT_MINS'] = 60;
	$config['CAM_FONT'] = "/usr/share/fonts/truetype/droid/DroidSans-Bold.ttf";
	$config['CAM_FONT_COLOR'] = "#FF0000";
	$config['CAM_FONT_SIZE'] = 14;
	//$config['CAM_FONT'] = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf";	// Use this when running on Ubuntu

	// Look through each of the environment variables that are prefaced by "CAM_" and place them in the the config array	
	log_info("Reading environment variables");
	foreach($_ENV as $env_var_key=>$env_var_value) {
		if (substr($env_var_key,0,4) == 'CAM_') {
			$config[$env_var_key] = $env_var_value;
		}
	}

	// Get the camera URLs from environment varaibles
	log_info("Processing image url configuration");
	$config['CAM_URLS'] = getCameraUrls();
	
	// Exit if we didn't find a CAM_STORAGE_DIRECTORY variable
	if (!array_key_exists('CAM_STORAGE_DIRECTORY', $config)) {
		log_error("Exiting due to CAM_STORAGE_DIRECTORY not being detected in the environment variables");
		exit(1);
	}	
	
	// Exit if we didn't find any URLs to monitor
	if (count($config['CAM_URLS']) == 0) {
		log_error("Exiting due to no CAM_IMAGE_URLx configurations detected in the environment variables");
		exit(1);
	}

	// Do some sanity checks of the configuration
	log_info("Error checking configuration");

	if (!is_numeric($config['CAM_OUTPUT_QUALITY'])) {
		log_error("Cannot accept CAM_OUTPUT_QUALITY of " . $config['CAM_OUTPUT_QUALITY']);
		exit(1);
	}

	if (!is_numeric($config['CAM_MAX_WIDTH']) || $config['CAM_MAX_WIDTH'] < 10) {
		log_error("Cannot accept CAM_MAX_WIDTH of " . $config['CAM_MAX_WIDTH']);
		exit(1);
	}

	if (!is_numeric($config['CAM_INTERVAL_TIME_SECS'])) {
		log_error("Cannot accept CAM_INTERVAL_TIME_SECS of " . $config['CAM_INTERVAL_TIME_SECS']);
		exit(1);
	}

	if (!is_numeric($config['CAM_CLEAN_TIME_MINS'])) {
		log_error("Cannot accept CAM_CLEAN_TIME_MINS of " . $config['CAM_CLEAN_TIME_MINS']);
		exit(1);
	}

	if (!is_numeric($config['CAM_RETENTION_TIME_HOURS'])) {
		log_error("Cannot accept CAM_RETENTION_TIME_HOURS of " . $config['CAM_RETENTION_TIME_HOURS']);
		exit(1);
	}
	
	if ($config['CAM_STORAGE_METHOD'] != "local" && $config['CAM_STORAGE_METHOD'] != "seafile") {
		log_error("Cannot accept CAM_STORAGE_METHOD of " . $config['CAM_STORAGE_METHOD'] . " - please use either local or seafile");
		exit(1);
	}

	// Check required vars for the seafile storage method
	if ($config['CAM_STORAGE_METHOD'] == "seafile") {
		$necessary_seafile_values = ['CAM_SEAFILE_URL', 'CAM_SEAFILE_APITOKEN', 'CAM_SEAFILE_LIBRARY_ID'];
		
		foreach($necessary_seafile_values as $var) {
			if (!array_key_exists($var,$config)) {
				log_error($var.  " must be set to use CAM_STORAGE_METHOD of seafile");
				exit(1);
			}
		}
	}
	
	// If we're using encryption for seafile, then make sure the timeout is numeric
	if (array_key_exists('CAM_SEAFILE_ENCRYPTION_KEY',$config)) {
		if (!is_numeric($config['CAM_SEAFILE_ENCRYPT_TIMEOUT_MINS'])) {
			log_error("Cannot accept CAM_SEAFILE_ENCRYPT_TIMEOUT_MINS of " . $config['CAM_SEAFILE_ENCRYPT_TIMEOUT_MINS']);
			exit(1);
		}
	}
	
	// Remove trailing slash from CAM_STORAGE_DIRECTORY if it exists
	if (substr($config['CAM_STORAGE_DIRECTORY'],-1) == "/") {
		$config['CAM_STORAGE_DIRECTORY'] = substr($config['CAM_STORAGE_DIRECTORY'], 0, strlen($config['CAM_STORAGE_DIRECTORY']) - 1);
	}
	
}


/*
 * Reads the camera URLs from the environment and places them into a flat array returned to the caller
 */
function getCameraUrls() {

	global $logger;

	$urls = array();

	// Dynamically check for up to 9 image urls in the environment vars
	for($i=1; $i<10; $i++) {

		// Form the variable
		$env_variable_name = "CAM_IMAGE_URL" . $i;
	
		// See if it exists in the environment
		if (array_key_exists($env_variable_name, $_ENV)) {
			array_push($urls, $_ENV[$env_variable_name]);
			log_debug("Found camera url: " . $_ENV[$env_variable_name]);
		}
	}
	return $urls;
}


/*
 * Attempts to initialize a connection to Seafile if the system is configured to do so
 */
function initializeSeafile() {

	global $config, $seafile_client, $seafile_library, $seafile_library_resource;

	if ($config['CAM_STORAGE_METHOD'] == "seafile") {

		// Now try initializing the Seafile client
		try {
			log_info("Initializing Seafile client");
			$seafile_client = new Client(
				[
					'base_uri' => $config['CAM_SEAFILE_URL'],
					'debug' => false,
					'headers' => [
						'Authorization' => 'Token ' . $config['CAM_SEAFILE_APITOKEN']
					]
				]
			);

			log_debug("Getting Seafile target library by ID: " . $config['CAM_SEAFILE_LIBRARY_ID']);
			$seafile_library_resource = new Library($seafile_client);
			$seafile_library = $seafile_library_resource->getById($config['CAM_SEAFILE_LIBRARY_ID']);

			// If we have an encryption key, the try to decrypt the resource
			decryptSeafileLibrary();

			log_debug("Successfully retrieved Seafile target library: " . $seafile_library->name);
			log_info("Successfully initialized Seafile client");
		}
		catch (Exception $e) {
			log_error("Error initializing Seafile. Please check your configuration. Error message received: " . $e->getMessage());
			exit(1);
		}
	}
}


/*
 * Sends a library decryption request to the Seafile server.
 */
function decryptSeafileLibrary() {

	global $config, $seafile_library_resource;

	// If we have an encryption key, the try to decrypt the resource
	if ($config['CAM_SEAFILE_ENCRYPTION_KEY'] != null) {
		log_info("Decrypting library resource: " . $config['CAM_SEAFILE_LIBRARY_ID']);
		
		try {
			$success = $seafile_library_resource->decrypt($config['CAM_SEAFILE_LIBRARY_ID'], ['query' => ['password' => $config['CAM_SEAFILE_ENCRYPTION_KEY']]]);

			if ($success) {
				log_info("Successfully decryped library resource: " . $config['CAM_SEAFILE_LIBRARY_ID']);
			}
			else {
				log_error("Was not able to decrypt library resource: " . $config['CAM_SEAFILE_LIBRARY_ID']);
				exit(1);
			}
			
		}
		catch (Exception $e) {
			log_error("Error communicating with Seafile. Error message received: " . $e->getMessage());
		}
	}
}


/*
 * Utility logging functions
 */
function log_debug($a) {
	global $logger, $config;
	if ($config['CAM_LOG_DEBUG']) {
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
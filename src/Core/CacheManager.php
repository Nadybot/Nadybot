<?php

namespace Budabot\Core;

use Exception;

/**
 * Read-through cache to URLs
 * @Instance
 */
class CacheManager {

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;

	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * The directory where to store the cache information
	 *
	 * @var string $cacheDir
	 */
	private $cacheDir;

	/**
	 * Initialize the cache on disk
	 *
	 * @return void
	 */
	public function init() {
		$this->cacheDir = $this->chatBot->vars["cachefolder"];

		//Making sure that the cache folder exists
		if (!dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0777);
		}
	}

	/**
	 * Lookup information in the cache or retrieve it when outdated
	 *
	 * @param string $url               The URL to load the data from if the cache is outdate
	 * @param string $groupName         The "name" of the cache, e.g. "guild_roster"
	 * @param string $filename          Filename to cache the information in when retrieved
	 * @param callable $isValidCallback Function to run on the body of the URL to check if the data is valid:
	 *                                  function($data) { return !json_decode($data) !== null }
	 * @param integer $maxCacheAge      Age of the cache entry in seconds after which the data will be considered outdated
	 * @param boolean $forceUpdate      Set to true to ignore the existing cache and always update
	 * @return \Budabot\Core\CacheResult
	 */
	public function lookup($url, $groupName, $filename, $isValidCallback, $maxCacheAge=86400, $forceUpdate=false) {
		if (empty($groupName)) {
			throw new Exception("Cache group name cannot be empty");
		}

		$cacheResult = new CacheResult();

		// Check if a xml file of the person exists and if it is uptodate
		if (!$forceUpdate && $this->cacheExists($groupName, $filename)) {
			$cacheAge = $this->getCacheAge($groupName, $filename);
			if ($cacheAge < $maxCacheAge) {
				$data = $this->retrieve($groupName, $filename);
				if (call_user_func($isValidCallback, $data)) {
					$cacheResult->data = $data;
					$cacheResult->cacheAge = $cacheAge;
					$cacheResult->usedCache = true;
					$cacheResult->oldCache = false;
					$cacheResult->success = true;
				} else {
					unset($data);
					$this->remove($groupName, $filename);
				}
			}
		}

		//If no old history file was found or it was invalid try to update it from url
		if ($cacheResult->success !== true) {
			$response = $this->http->get($url)->waitAndReturnResponse();
			$data = $response->body;
			if (call_user_func($isValidCallback, $data)) {
				$cacheResult->data = $data;
				$cacheResult->cacheAge = 0;
				$cacheResult->usedCache = false;
				$cacheResult->oldCache = false;
				$cacheResult->success = true;
			} else {
				unset($data);
			}
		}

		//If the site was not responding or the data was invalid and a xml file exists get that one
		if ($cacheResult->success !== true && $this->cacheExists($groupName, $filename)) {
			$data = $this->retrieve($groupName, $filename);
			if (call_user_func($isValidCallback, $data)) {
				$cacheResult->data = $data;
				$cacheResult->cacheAge = $this->getCacheAge($groupName, $filename);
				$cacheResult->usedCache = true;
				$cacheResult->oldCache = true;
				$cacheResult->success = true;
			} else {
				unset($data);
				$this->remove($groupName, $filename);
			}
		}

		// if a new file was downloaded, save it in the cache
		if ($cacheResult->usedCache === false && $cacheResult->success === true) {
			$this->store($groupName, $filename, $cacheResult->data);
		}

		return $cacheResult;
	}

	/**
	 * Store content in the cache
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 * @param string $filename  Filename of the cache
	 * @param string $contents  The string to store
	 * @return void
	 */
	public function store($groupName, $filename, $contents) {
		if (!dir($this->cacheDir . '/' . $groupName)) {
			mkdir($this->cacheDir . '/' . $groupName, 0777);
		}

		$cacheFile = "$this->cacheDir/$groupName/$filename";

		// at least in windows, modifcation timestamp will not change unless this is done
		// not sure why that is the case -tyrence
		@unlink($cacheFile);

		$fp = fopen($cacheFile, "w");
		fwrite($fp, $contents);
		fclose($fp);
	}

	/**
	 * Retrieve content from the cache
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 * @param string $filename  Filename of the cache
	 * @return string|null null on error or if the cache doresn't exist, otherwise the string that was cached
	 */
	public function retrieve($groupName, $filename) {
		$cacheFile = "{$this->cacheDir}/$groupName/$filename";

		if (file_exists($cacheFile)) {
			return file_get_contents($cacheFile);
		} else {
			return null;
		}
	}

	/**
	 * Check how old the information in a cache file is
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 * @param string $filename  Filename of the cache
	 * @return int|null null if the cache doresn't exist, otherwise the age of the cache in seconds
	 */
	public function getCacheAge($groupName, $filename) {
		$cacheFile = "$this->cacheDir/$groupName/$filename";

		if (file_exists($cacheFile)) {
			return time() - filemtime($cacheFile);
		} else {
			return null;
		}
	}

	/**
	 * Check if the cache already exists
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 * @param string $filename  Filename of the cache
	 * @return bool
	 */
	public function cacheExists($groupName, $filename) {
		$cacheFile = "$this->cacheDir/$groupName/$filename";

		return file_exists($cacheFile);
	}

	/**
	 * Delete a cache
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 * @param string $filename  Filename of the cache
	 * @return void
	 */
	public function remove($groupName, $filename) {
		$cacheFile = "$this->cacheDir/$groupName/$filename";

		@unlink($cacheFile);
	}

	/**
	 * Get a list of all files with cached information that belong to a group
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 * @return string[]
	 */
	public function getFilesInGroup($groupName) {
		$path = "$this->cacheDir/$groupName/";

		return $this->util->getFilesInDirectory($path);
	}

	/**
	 * Get a list of all existing cache groups
	 *
	 * @return string[]
	 */
	public function getGroups() {
		return $this->util->getDirectoriesInDirectory($this->cacheDir);
	}
}

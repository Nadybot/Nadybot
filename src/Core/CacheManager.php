<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;

/**
 * Read-through cache to URLs
 * @Instance
 */
class CacheManager {

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Util $util;
	
	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * The directory where to store the cache information
	 */
	private string $cacheDir;

	/**
	 * Initialize the cache on disk
	 * @Setup
	 */
	public function init(): void {
		$this->cacheDir = $this->chatBot->vars["cachefolder"];

		//Making sure that the cache folder exists
		if (!@is_dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0777);
		}
	}
		
	public function forceLookupFromCache(string $groupName, string $filename, callable $isValidCallback, int $maxCacheAge): ?CacheResult {
		// Check if a xml file of the person exists and if it is up to date
		if (!$this->cacheExists($groupName, $filename)) {
			return null;
		}
		$cacheAge = $this->getCacheAge($groupName, $filename);
		if ($cacheAge > $maxCacheAge) {
			return null;
		}
		$data = $this->retrieve($groupName, $filename);
		if (!$isValidCallback($data)) {
			$this->remove($groupName, $filename);
			return null;
		}
		$cacheResult = new CacheResult();
		$cacheResult->data = $data;
		$cacheResult->cacheAge = $cacheAge;
		$cacheResult->usedCache = true;
		$cacheResult->oldCache = false;
		$cacheResult->success = true;
		return $cacheResult;
	}

	/**
	 * Lookup information in the cache or retrieve it when outdated and call the callback
	 */
	public function asyncLookup(string $url, string $groupName, string $filename, callable $isValidCallback, int $maxCacheAge, bool $forceUpdate, callable $callback, ...$args): void {
		if (empty($groupName)) {
			$this->logger->log("ERROR", "Cache group name cannot be empty");
			return;
		}

		// Check if an xml file of the person exists and if it is up to date
		if (!$forceUpdate) {
			$cachedResult = $this->forceLookupFromCache($groupName, $filename, $isValidCallback, $maxCacheAge);
			if (isset($cachedResult)) {
				$callback($cachedResult, ...$args);
				return;
			}
		}
		//If no old history file was found or it was invalid try to update it from url
		$this->http->get($url)
			->withTimeout(10)
			->withCallback([$this, "handleCacheLookup"], $groupName, $filename, $isValidCallback, $callback, ...$args);
	}

	/**
	 * Handle HTTP replies to lookups for the cache
	 */
	public function handleCacheLookup(HttpResponse $response, string $groupName, string $filename, callable $isValidCallback, callable $callback, ...$args): void {
		if ($response->error) {
			$this->logger->log("WARN", $response->error);
		}
		if (!isset($response->body)) {
			$this->logger->log("WARN", "Empty reply received from " . $response->request->getURI());
		}
		if (empty($response->error)
			&& isset($response->body)
			&& $isValidCallback($response->body)
		) {
			// Lookup for the URL was successful, now update the cache and return data
			$cacheResult = new CacheResult();
			$cacheResult->data = $response->body;
			$cacheResult->cacheAge = 0;
			$cacheResult->usedCache = false;
			$cacheResult->oldCache = false;
			$cacheResult->success = true;
			$this->store($groupName, $filename, $cacheResult->data);
			$callback($cacheResult, ...$args);
			return;
		}
		//If the site was not responding or the data was invalid and we
		// also have no old cache, report that
		if (!$this->cacheExists($groupName, $filename)) {
			$callback(new CacheResult(), ...$args);
			return;
		}
		// If we have an old cache entry, use that one, it's better than nothing
		$data = $this->retrieve($groupName, $filename);
		if (!call_user_func($isValidCallback, $data)) {
			// Old cache data is invalid? Delete and report no data found
			$this->remove($groupName, $filename);
			$callback(new CacheResult(), ...$args);
			return;
		}

		$cacheResult = new CacheResult();
		$cacheResult->data = $data;
		$cacheResult->cacheAge = $this->getCacheAge($groupName, $filename);
		$cacheResult->usedCache = true;
		$cacheResult->oldCache = true;
		$cacheResult->success = true;
		$callback($cacheResult, ...$args);
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
	 * @throws Exception
	 */
	public function lookup(string $url, string $groupName, string $filename, callable $isValidCallback, int $maxCacheAge=86400, bool $forceUpdate=false): CacheResult {
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
	 */
	public function store(string $groupName, string $filename, string $contents): void {
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
	 */
	public function retrieve(string $groupName, string $filename): ?string {
		$cacheFile = "{$this->cacheDir}/$groupName/$filename";

		if (@file_exists($cacheFile)) {
			return file_get_contents($cacheFile);
		}
		return null;
	}

	/**
	 * Check how old the information in a cache file is
	 */
	public function getCacheAge(string $groupName, string $filename): ?int {
		$cacheFile = "$this->cacheDir/$groupName/$filename";

		if (@file_exists($cacheFile)) {
			return time() - filemtime($cacheFile);
		}
		return null;
	}

	/**
	 * Check if the cache already exists
	 */
	public function cacheExists(string $groupName, string $filename): bool {
		$cacheFile = "{$this->cacheDir}/$groupName/$filename";

		return @file_exists($cacheFile);
	}

	/**
	 * Delete a cache
	 */
	public function remove(string $groupName, string $filename): bool {
		$cacheFile = "$this->cacheDir/$groupName/$filename";

		return @unlink($cacheFile);
	}

	/**
	 * Get a list of all files with cached information that belong to a group
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 * @return string[]
	 */
	public function getFilesInGroup(string $groupName): array {
		$path = $this->cacheDir . DIRECTORY_SEPARATOR . $groupName . DIRECTORY_SEPARATOR;

		return $this->util->getFilesInDirectory($path);
	}

	/**
	 * Get a list of all existing cache groups
	 *
	 * @return string[]
	 */
	public function getGroups(): array {
		return $this->util->getDirectoriesInDirectory($this->cacheDir);
	}
}

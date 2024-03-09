<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\File\{Filesystem, FilesystemException};
use Amp\Http\Client\{HttpClientBuilder, Request};
use Exception;
use Nadybot\Core\Attributes as NCA;

use Nadybot\Core\Config\BotConfig;

/**
 * Read-through cache to URLs
 */
#[NCA\Instance]
class CacheManager {
	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	/** The directory where to store the cache information */
	private string $cacheDir;

	/** Initialize the cache on disk */
	#[NCA\Setup]
	public function init(): void {
		$this->cacheDir = $this->config->paths->cache;

		// Making sure that the cache folder exists
		if ($this->fs->isDirectory($this->cacheDir)) {
			return;
		}
		try {
			$this->fs->createDirectory($this->cacheDir, 0777);
		} catch (FilesystemException $e) {
			$this->logger->warning("Unable to create the cache directory {dir}: {error}", [
				"dir" => $this->cacheDir,
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	/** @psalm-param callable(?string): bool $isValidCallback */
	public function forceLookupFromCache(string $groupName, string $filename, callable $isValidCallback, int $maxCacheAge): ?CacheResult {
		// Check if a xml file of the person exists and if it is up to date
		if (!$this->cacheExists($groupName, $filename)) {
			return null;
		}
		$cacheAge = $this->getCacheAge($groupName, $filename);
		if (!isset($cacheAge) || $cacheAge > $maxCacheAge) {
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
	 * Lookup information in the cache or retrieve it when outdated
	 *
	 * @param string   $url             The URL to load the data from if the cache is outdate
	 * @param string   $groupName       The "name" of the cache, e.g. "guild_roster"
	 * @param string   $filename        Filename to cache the information in when retrieved
	 * @param callable $isValidCallback Function to run on the body of the URL to check if the data is valid:
	 *                                  function($data) { return !json_decode($data) !== null }
	 * @param int      $maxCacheAge     Age of the cache entry in seconds after which the data will be considered outdated
	 * @param bool     $forceUpdate     Set to true to ignore the existing cache and always update
	 *
	 * @throws Exception
	 *
	 * @deprecated
	 */
	public function lookup(string $url, string $groupName, string $filename, callable $isValidCallback, int $maxCacheAge=86400, bool $forceUpdate=false): CacheResult {
		if (empty($groupName)) {
			throw new Exception("Cache group name cannot be empty");
		}

		$cacheResult = new CacheResult();

		// Check if a xml file of the person exists and if it is up-to-date
		if (!$forceUpdate && $this->cacheExists($groupName, $filename)) {
			$cacheAge = $this->getCacheAge($groupName, $filename);
			if ($cacheAge < $maxCacheAge) {
				$data = $this->retrieve($groupName, $filename);
				if (call_user_func($isValidCallback, $data)) {
					$cacheResult->data = $data;
					$cacheResult->cacheAge = $cacheAge??0;
					$cacheResult->usedCache = true;
					$cacheResult->oldCache = false;
					$cacheResult->success = true;
				} else {
					unset($data);
					$this->remove($groupName, $filename);
				}
			}
		}

		// If no old history file was found or it was invalid try to update it from url
		if ($cacheResult->success !== true) {
			$http = $this->builder->build();
			$response = $http->request(new Request($url));
			$data = $response->getBody()->buffer();
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

		// If the site was not responding or the data was invalid and a xml file exists get that one
		if ($cacheResult->success !== true && $this->cacheExists($groupName, $filename)) {
			$data = $this->retrieve($groupName, $filename);
			if (call_user_func($isValidCallback, $data)) {
				$cacheResult->data = $data;
				$cacheResult->cacheAge = $this->getCacheAge($groupName, $filename) ?? 0;
				$cacheResult->usedCache = true;
				$cacheResult->oldCache = true;
				$cacheResult->success = true;
			} else {
				unset($data);
				$this->remove($groupName, $filename);
			}
		}

		// if a new file was downloaded, save it in the cache
		if ($cacheResult->usedCache === false && $cacheResult->success === true && isset($cacheResult->data)) {
			$this->store($groupName, $filename, $cacheResult->data);
		}

		return $cacheResult;
	}

	/** Store content in the cache */
	public function store(string $groupName, string $filename, string $contents): void {
		$cacheFile = "{$this->cacheDir}/{$groupName}/{$filename}";
		try {
			if (!$this->fs->isDirectory($this->cacheDir . '/' . $groupName)) {
				$this->fs->createDirectory($this->cacheDir . '/' . $groupName, 0777);
			}

			// at least in windows, modification timestamp will not change unless this is done
			// not sure why that is the case -tyrence
			$this->fs->deleteFile($cacheFile);

			$this->fs->write($cacheFile, $contents);
		} catch (FilesystemException $e) {
			$this->logger->warning("Unable to store cache {file}: {error}", [
				"file" => $cacheFile,
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	/** Retrieve content from the cache */
	public function retrieve(string $groupName, string $filename): ?string {
		$cacheFile = "{$this->cacheDir}/{$groupName}/{$filename}";

		if (!$this->fs->exists($cacheFile)) {
			return null;
		}
		try {
			return $this->fs->read($cacheFile);
		} catch (FilesystemException $e) {
			$this->logger->warning("Unable to read {file}: {error}", [
				"file" => $cacheFile,
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			return null;
		}
	}

	/** Check how old the information in a cache file is */
	public function getCacheAge(string $groupName, string $filename): ?int {
		$cacheFile = "{$this->cacheDir}/{$groupName}/{$filename}";

		if ($this->fs->exists($cacheFile)) {
			return time() - $this->fs->getModificationTime($cacheFile);
		}
		return null;
	}

	/** Check if the cache already exists */
	public function cacheExists(string $groupName, string $filename): bool {
		$cacheFile = "{$this->cacheDir}/{$groupName}/{$filename}";

		return $this->fs->exists($cacheFile);
	}

	/** Delete a cache */
	public function remove(string $groupName, string $filename): void {
		$cacheFile = "{$this->cacheDir}/{$groupName}/{$filename}";
		$this->fs->deleteFile($cacheFile);
	}

	/**
	 * Get a list of all files with cached information that belong to a group
	 *
	 * @param string $groupName The "name" of the cache, e.g. "guild_roster"
	 *
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

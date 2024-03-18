<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\File\FilesystemException;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Psr\Log\LoggerInterface;

/**
 * Read-through cache to URLs
 */
#[NCA\Instance]
class CacheManager {
	#[NCA\Logger]
	private LoggerInterface $logger;

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

<?php declare(strict_types=1);

namespace Nadybot\Core;

use Safe\Exceptions\FilesystemException;

/**
 * Reads entries from the text.mdb file
 *
 * @author: Tyrence (RK2)
 */
class MMDBParser {
	/** @var array<int,array<int,string>> */
	private array $mmdb = [];

	private LoggerWrapper $logger;

	public function __construct() {
		$this->logger = new LoggerWrapper('Core/MMDBParser');
		Registry::injectDependencies($this->logger);
	}

	public function getMessageString(int $categoryId, int $instanceId): ?string {
		// check for entry in cache
		if (isset($this->mmdb[$categoryId][$instanceId])) {
			return $this->mmdb[$categoryId][$instanceId];
		}

		$in = $this->openFile();
		if ($in === null) {
			return null;
		}

		// start at offset = 8 since that is where the categories start
		// find the category
		$category = $this->findEntry($in, $categoryId, 8);
		if ($category === null) {
			$this->logger->error("Could not find categoryID: '{$categoryId}'");
			\Safe\fclose($in);
			return null;
		}

		// find the instance
		$instance = $this->findEntry($in, $instanceId, $category['offset']);
		if ($instance === null) {
			$this->logger->error("Could not find instance id: '{$instanceId}' for categoryId: '{$categoryId}'");
			\Safe\fclose($in);
			return null;
		}

		fseek($in, $instance['offset']);
		$message = $this->readString($in);
		$this->mmdb[$categoryId][$instanceId] = $message;

		\Safe\fclose($in);

		return $message;
	}

	/**
	 * @return array<string,int>[]|null
	 */
	public function findAllInstancesInCategory(int $categoryId): ?array {
		$in = $this->openFile();
		if ($in === null) {
			return null;
		}

		// start at offset = 8 since that is where the categories start
		// find the category
		$category = $this->findEntry($in, $categoryId, 8);
		if ($category === null) {
			$this->logger->error("Could not find categoryID: '{$categoryId}'");
			\Safe\fclose($in);
			return null;
		}

		fseek($in, $category['offset']);

		// find all instances
		$instances = [];
		$instance = $this->readEntry($in);
		$previousInstance = null;
		while ($previousInstance == null || $instance['id'] > $previousInstance['id']) {
			$instances[] = $instance;
			$previousInstance = $instance;
			$instance = $this->readEntry($in);
		}

		\Safe\fclose($in);

		return $instances;
	}

	/**
	 * @return null|array<string,int>[]
	 */
	public function getCategories(): ?array {
		$in = $this->openFile();
		if ($in === null) {
			return null;
		}

		// start at offset = 8 since that is where the categories start
		fseek($in, 8);

		// find all categories
		$categories = [];
		$category = $this->readEntry($in);
		$previousCategory = null;
		while ($previousCategory == null || $category['id'] > $previousCategory['id']) {
			$categories[] = $category;
			$previousCategory = $category;
			$category = $this->readEntry($in);
		}

		\Safe\fclose($in);

		return $categories;
	}

	/**
	 * Open the MMDB file and return the resource of it
	 *
	 * @return null|resource
	 */
	private function openFile(string $filename="res/text.mdb") {
		try {
			$in = \Safe\fopen($filename, 'rb');
		} catch (FilesystemException $e) {
			$this->logger->error("Could not open file '{$filename}': " . $e->getMessage());
			return null;
		}

		// make sure first 4 chars are 'MMDB'
		$entry = $this->readEntry($in);
		if ($entry['id'] !== 1111772493) {
			$this->logger->error("Not an mmdb file: '{$filename}'");
			\Safe\fclose($in);
			return null;
		}

		return $in;
	}

	/**
	 * Find an entry in the MMDB
	 *
	 * @param resource $in The resource of the file
	 * @param int $id The category ID
	 * @param int $offset Offset where to read
	 * @return null|array<string,int>
	 */
	private function findEntry($in, int $id, int $offset): ?array {
		fseek($in, $offset);
		$entry = null;

		do {
			$previousEntry = $entry;
			$entry = $this->readEntry($in);

			if ($previousEntry != null && $entry['id'] < $previousEntry['id']) {
				return null;
			}
		} while ($id != $entry['id']);

		return $entry;
	}

	/**
	 * @param resource $in
	 * @return array<string,int>
	 */
	private function readEntry($in): array {
		return ['id' => $this->readLong($in), 'offset' => $this->readLong($in)];
	}

	/**
	 * @param resource $in
	 */
	private function readLong($in): int {
		$packed = \Safe\fread($in, 4);
		$unpacked = \Safe\unpack("L", $packed);
		return array_pop($unpacked);
	}

	/**
	 * @param resource $in
	 */
	private function readString($in): string {
		$message = '';
		$char = '';

		$char = \Safe\fread($in, 1);
		while ($char !== "\0" && !feof($in)) {
			$message .= $char;
			$char = \Safe\fread($in, 1);
		}

		return $message;
	}
}

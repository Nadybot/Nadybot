<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use function Safe\{inflate_add, inflate_init, json_decode};

use Amp\{CancelledException, TimeoutCancellation};
use Amp\Http\Client\{HttpClientBuilder, HttpException, Request, Response};
use Closure;
use Generator;
use JsonException;
use Nadybot\Core\{
	Attributes as NCA,
	CSV\Reader,
	CmdContext,
	DB,
	Filesystem,
	ModuleInstance,
	Safe,
	SettingManager,
	Types\AccessLevel,
	Types\Dimension,
	Types\SettingMode,
	Types\Status,
};
use Nadybot\Modules\ITEMS_MODULE\Exceptions\ItemImportException;
use Nadybot\Modules\ITEMS_MODULE\Import\{DBRowSink, ItemImportResult, ItemImporter};
use Nadybot\Modules\SKILLS_MODULE\WeaponAttribute;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the item tables up to date with the item data of the bot's dimension
 *
 * The data is checked once when the bot is ready and then periodically.
 * Nano programs are not part of the export, their buffs and types are
 * loaded from the CSV files of this module.
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'itemsupdate',
		accessLevel: AccessLevel::Admin,
		description: 'Check for new item data and import it',
	),
]
class ItemDataController extends ModuleInstance {
	/** Number of bytes passed to the importer at once */
	public const CHUNK_SIZE = 65_536;

	/** Maximum size of the downloaded export */
	public const MAX_SIZE = 256 * 1_024 * 1_024;

	/** Placeholder in the URL settings for the bot's dimension */
	public const DIMENSION_PLACEHOLDER = '{dimension}';

	/** Default of the item data URL, replaced by the URL for the bot's dimension in the setup */
	public const DEFAULT_ITEM_DATA_URL = 'https://items.aobots.org/rk{dimension}-items.json';

	/** Default of the URL for the items not in the game, the same for all dimensions */
	public const DEFAULT_NOT_IN_GAME_URL = 'https://items.aobots.org/rk{dimension}-not-in-game.json';

	/** Item data of Lost Eden, provided by the server itself */
	public const LOST_EDEN_ITEM_DATA_URL = 'https://rubi-ka.org/api/items/export/lost-eden-items.json';

	/** AOIDs not in the game on Lost Eden, provided by the server itself */
	public const LOST_EDEN_NOT_IN_GAME_URL = 'https://rubi-ka.org/api/items/export/not-in-game.json';

	/** URL of the item database */
	#[NCA\Setting\Text(accessLevel: AccessLevel::Admin)]
	public string $itemDataUrl = self::DEFAULT_ITEM_DATA_URL;

	/** URL of the list of items not in the game, "none" for no list */
	#[NCA\Setting\Text(accessLevel: AccessLevel::Admin)]
	public string $itemNotInGameUrl = self::DEFAULT_NOT_IN_GAME_URL;

	/** Version of the imported item data */
	#[NCA\Setting\Text(mode: SettingMode::NoEdit)]
	public string $itemDataVersion = '';

	/** URL the imported item data was loaded from */
	#[NCA\Setting\Text(mode: SettingMode::NoEdit)]
	public string $itemDataSource = '';

	/** Checksum of the applied list of items not in the game */
	#[NCA\Setting\Text(mode: SettingMode::NoEdit)]
	public string $itemNotInGameHash = '';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private ItemsController $itemsController;

	private bool $updateRunning = false;

	/** @var array<string,?array<string,string>> Cached SHA256SUMS contents per URL directory, null if not served */
	private array $checksums = [];

	/** Import the item data right away if there is none yet */
	#[NCA\Setup]
	public function setup(): void {
		$this->setDimensionUrls();
		if ($this->db->table(AODBEntry::getTable())->exists()) {
			return;
		}
		try {
			$message = $this->update(true, $this->logProgress(...));
			$this->logger->notice('{message}', ['message' => $message]);
		} catch (ItemImportException $e) {
			$this->logger->error('Unable to import the item data, the item database is empty: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/** Check for new item data */
	#[NCA\Timer(interval: '24h', defaultStatus: Status::Enabled)]
	public function checkForItemData(): void {
		try {
			$message = $this->update(false, $this->logProgress(...));
			$this->logger->notice('{message}', ['message' => $message]);
		} catch (ItemImportException $e) {
			$this->logger->warning('Unable to update the item data: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/** Check for new item data and import it, or re-import it with 'force' */
	#[NCA\HandlesCommand('itemsupdate')]
	#[NCA\Help\Example('<symbol>itemsupdate')]
	#[NCA\Help\Example('<symbol>itemsupdate force', 'Import the item data, even if it is up to date')]
	public function itemsUpdateCommand(
		CmdContext $context,
		#[NCA\Parameter\Str('force')] ?string $force,
	): void {
		try {
			$message = $this->update(isset($force), $context->reply(...));
		} catch (ItemImportException $e) {
			$message = 'Unable to update the item data: ' . $e->getMessage();
		}
		$context->reply($message);
	}

	/**
	 * Check for new item data and import it
	 *
	 * @param bool                   $force    Import the data, even if the version is unchanged
	 * @param ?Closure(string):mixed $progress Called with a message before the import starts
	 *
	 * @return string A message describing the outcome
	 *
	 * @throws ItemImportException if the data could not be updated, the old data is kept
	 */
	public function update(bool $force, ?Closure $progress=null): string {
		if ($this->updateRunning) {
			throw new ItemImportException('An update is already running.');
		}
		$this->updateRunning = true;
		try {
			return $this->runUpdate($force, $progress);
		} finally {
			$this->updateRunning = false;
		}
	}

	/** Resolve a URL setting, null if none is configured */
	public function resolveUrl(string $setting): ?string {
		$url = trim($setting);
		if ($url === '' || strtolower($url) === 'none') {
			return null;
		}
		return str_replace(self::DIMENSION_PLACEHOLDER, (string)$this->db->getDim(), $url);
	}

	/**
	 * Replace the generic default item data URL by the one for the bot's dimension
	 *
	 * Lost Eden provides its own export and its own list of items not in
	 * the game. A URL an admin has set is kept.
	 */
	private function setDimensionUrls(): void {
		$isLostEden = Dimension::tryFrom($this->db->getDim()) === Dimension::LostEden;
		if ($this->itemDataUrl === self::DEFAULT_ITEM_DATA_URL) {
			$url = $isLostEden ? self::LOST_EDEN_ITEM_DATA_URL : (string)$this->resolveUrl(self::DEFAULT_ITEM_DATA_URL);
			$this->itemDataUrl = $url;
			$this->settingManager->save('item_data_url', $url);
		}
		if ($this->itemNotInGameUrl === self::DEFAULT_NOT_IN_GAME_URL) {
			$url = $isLostEden ? self::LOST_EDEN_NOT_IN_GAME_URL : (string)$this->resolveUrl(self::DEFAULT_NOT_IN_GAME_URL);
			$this->itemNotInGameUrl = $url;
			$this->settingManager->save('item_not_in_game_url', $url);
		}
	}

	/** Write a progress message to the log */
	private function logProgress(string $message): void {
		$this->logger->notice('{message}', ['message' => $message]);
	}

	/** @param ?Closure(string):mixed $progress */
	private function runUpdate(bool $force, ?Closure $progress): string {
		$this->checksums = [];
		$url = $this->resolveUrl($this->itemDataUrl);
		if ($url === null) {
			throw new ItemImportException('No URL for the item data configured.');
		}
		$notInGame = $this->fetchNotInGame($this->resolveUrl($this->itemNotInGameUrl));
		$notInGameHash = $this->hashNotInGame($notInGame);

		$installed = $url === $this->itemDataSource ? $this->itemDataVersion : '';
		try {
			$version = $this->fetchVersion($url);
		} catch (ItemImportException $e) {
			if (!$force) {
				throw $e;
			}
			$version = null;
		}
		if (!$force && $installed !== '') {
			if ($version === $installed) {
				if ($notInGameHash === $this->itemNotInGameHash) {
					return "The item data is up to date (version {$version}).";
				}
				$this->applyNotInGame($notInGame);
				$this->settingManager->save('item_not_in_game_hash', $notInGameHash);
				return "The item data is up to date (version {$version}), ".
					'the list of items not in the game was updated (' . count($notInGame) . ' templates).';
			}
		}

		if (isset($progress)) {
			$progress(
				(($version !== null && $version === $installed) ? 'Re-importing' : 'Importing').
				' item data' . (isset($version) ? " {$version}" : '') . '...'
			);
		}
		$start = microtime(true);
		[$body, $encoding] = $this->download($url);
		$this->verifyDecodedChecksum($url, $body, $encoding);
		$downloadTime = microtime(true) - $start;
		$nanoBuffs = $this->readCSV(__DIR__ . '/nano_buffs.csv');
		$nanoTypes = $this->readCSV(__DIR__ . '/nano_types.csv');

		$start = microtime(true);
		$memoryBefore = memory_get_usage();
		$result = $this->import($body, $encoding, $notInGame, $nanoBuffs, $nanoTypes);
		$importTime = microtime(true) - $start;
		$memoryPeak = memory_get_peak_usage();

		$this->settingManager->save('item_data_version', $result->version);
		$this->settingManager->save('item_data_source', $url);
		$this->settingManager->save('item_not_in_game_hash', $notInGameHash);

		$message = "Imported item data {$result->version}: ".
			$result->items() . " items ({$result->familyItems} in {$result->groups} groups, ".
			"{$result->directItems} single), {$result->buffs} buffs, {$result->types} types, ".
			"{$result->weapons} weapons, {$result->notInGame} items not in the game. ".
			sprintf(
				'Downloaded %.1f MB in %.1fs, imported %.1f MB in %.1fs, memory %.1f MB (peak %.1f MB).',
				strlen($body) / 1_048_576,
				$downloadTime,
				$result->bytes / 1_048_576,
				$importTime,
				$memoryBefore / 1_048_576,
				$memoryPeak / 1_048_576,
			);
		if (count($result->skipped)) {
			$skipped = [];
			foreach ($result->skipped as $reason => $count) {
				$skipped []= "{$count}x {$reason}";
			}
			$message .= ' Skipped: ' . implode(', ', $skipped) . '.';
		}
		return $message;
	}

	/**
	 * Replace the item tables with the data of the export
	 *
	 * Everything runs in a single transaction without giving control back to
	 * the event loop, so the old data stays intact if anything fails.
	 * During the setup, the bot already has a transaction open that must not
	 * be rolled back. The tables are emptied again instead, which is how they
	 * were before, because the setup only imports into an empty database.
	 *
	 * @param array<int,true>             $notInGame
	 * @param list<array<string,?string>> $nanoBuffs
	 * @param list<array<string,?string>> $nanoTypes
	 */
	private function import(string $body, ?string $encoding, array $notInGame, array $nanoBuffs, array $nanoTypes): ItemImportResult {
		$chunks = $this->decode($body, $encoding);
		$ownTransaction = !$this->db->inTransaction();
		if ($ownTransaction) {
			$this->db->awaitBeginTransaction();
		}
		try {
			$this->clearTables();
			$sink = new DBRowSink($this->db);
			$importer = new ItemImporter($sink, $this->itemsController->getLongestCommonStringOfWords(...));
			$result = $importer->import($chunks, $notInGame);
			foreach ($nanoBuffs as $row) {
				$sink->add(ItemBuff::class, [
					'item_id' => (int)$row['item_id'],
					'attribute_id' => (int)$row['attribute_id'],
					'amount' => (int)$row['amount'],
				]);
			}
			foreach ($nanoTypes as $row) {
				$sink->add(ItemType::class, [
					'item_id' => (int)$row['item_id'],
					'item_type' => (string)$row['item_type'],
				]);
			}
			$sink->flush();
			if ($ownTransaction) {
				$this->db->commit();
			}
		} catch (Throwable $e) {
			if ($ownTransaction) {
				$this->db->rollback();
			} else {
				$this->clearTables();
			}
			if ($e instanceof ItemImportException) {
				throw $e;
			}
			throw new ItemImportException($e->getMessage(), 0, $e);
		}
		return $result;
	}

	/** Delete all rows of the tables the import fills */
	private function clearTables(): void {
		foreach ([AODBEntry::class, ItemGroup::class, ItemGroupName::class, ItemBuff::class, ItemType::class, WeaponAttribute::class] as $table) {
			$this->db->table($table::getTable())->delete();
		}
	}

	/**
	 * Decompress the body in chunks
	 *
	 * @return Generator<int,string>
	 */
	private function decode(string $body, ?string $encoding): Generator {
		$context = match ($encoding) {
			null, '', 'identity' => null,
			'gzip', 'x-gzip' => inflate_init(\ZLIB_ENCODING_GZIP),
			'deflate' => inflate_init(\ZLIB_ENCODING_DEFLATE),
			default => throw new ItemImportException("Unsupported content encoding '{$encoding}'."),
		};
		$length = strlen($body);
		for ($offset = 0; $offset < $length; $offset += self::CHUNK_SIZE) {
			$chunk = substr($body, $offset, self::CHUNK_SIZE);
			yield isset($context) ? inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH) : $chunk;
		}
		if (isset($context)) {
			yield inflate_add($context, '', \ZLIB_FINISH);
		}
	}

	/**
	 * Download the export without decompressing it
	 *
	 * @return array{string,?string} The body and its content encoding
	 *
	 * @throws ItemImportException on error
	 */
	private function download(string $url): array {
		$request = new Request($url);
		$request->setHeader('Accept-Encoding', extension_loaded('zlib') ? 'gzip, deflate' : 'identity');
		$request->setBodySizeLimit(self::MAX_SIZE);
		$request->setTransferTimeout(300);
		$request->setInactivityTimeout(30);
		$response = $this->request($request, $this->builder->skipAutomaticCompression());
		try {
			$body = $response->getBody()->buffer(new TimeoutCancellation(300));
		} catch (Throwable $e) {
			throw new ItemImportException("Unable to download {$url}: " . $e->getMessage(), 0, $e);
		}
		$encoding = $response->getHeader('content-encoding');
		return [$body, isset($encoding) ? strtolower(trim($encoding)) : null];
	}

	/** @throws ItemImportException on error */
	private function fetchVersion(string $url): string {
		$versionUrl = "{$url}.version";
		$raw = $this->fetchSmall($versionUrl);
		$this->verifyChecksum($versionUrl, $raw);
		$version = trim($raw);
		if ($version === '') {
			throw new ItemImportException("{$versionUrl} is empty.");
		}
		return $version;
	}

	/**
	 * Get the AOIDs of all templates that are not in the game
	 *
	 * @return array<int,true>
	 *
	 * @throws ItemImportException on error
	 */
	private function fetchNotInGame(?string $url): array {
		if ($url === null) {
			return [];
		}
		try {
			$body = $this->fetchSmall($url);
			$this->verifyChecksum($url, $body);
			$ids = json_decode($body, true, 2);
		} catch (JsonException $e) {
			throw new ItemImportException("{$url} is not valid JSON.", 0, $e);
		}
		if (!is_array($ids) || !array_is_list($ids)) {
			throw new ItemImportException("{$url} is not a list of item IDs.");
		}
		$result = [];
		foreach ($ids as $id) {
			if (!is_int($id)) {
				throw new ItemImportException("{$url} is not a list of item IDs.");
			}
			$result[$id] = true;
		}
		return $result;
	}

	/** @throws ItemImportException on error */
	private function fetchSmall(string $url): string {
		$request = new Request($url);
		$request->setTransferTimeout(30);
		$request->setBodySizeLimit(16 * 1_024 * 1_024);
		$response = $this->request($request, $this->builder);
		try {
			return $response->getBody()->buffer(new TimeoutCancellation(30));
		} catch (Throwable $e) {
			throw new ItemImportException("Unable to download {$url}: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Verify $body against the SHA256SUMS file next to $url
	 *
	 * The sums file is expected in the format of `sha256sum(1)`, with one
	 * entry per file, matched by file name. If the host doesn't serve
	 * `SHA256SUMS` or has no entry for this file, the data cannot be
	 * verified and a warning is logged instead.
	 *
	 * @throws ItemImportException on a checksum mismatch
	 */
	private function verifyChecksum(string $url, string $body): void {
		$this->compareChecksum($url, hash('sha256', $body));
	}

	/**
	 * Verify the decompressed data of a streamed download against the
	 * SHA256SUMS file next to $url
	 *
	 * The hashes in `SHA256SUMS` are checksums of the uncompressed files,
	 * while the export is transferred in whatever encoding the server
	 * picks, so the body has to be decoded for hashing. The importer
	 * iterates the decompressed data again, so it is calculated twice.
	 *
	 * @throws ItemImportException on a checksum mismatch
	 */
	private function verifyDecodedChecksum(string $url, string $body, ?string $encoding): void {
		$hashContext = hash_init('sha256');
		foreach ($this->decode($body, $encoding) as $chunk) {
			hash_update($hashContext, $chunk);
		}
		$this->compareChecksum($url, hash_final($hashContext));
	}

	/** @throws ItemImportException on a checksum mismatch */
	private function compareChecksum(string $url, string $hash): void {
		$sums = $this->fetchChecksums($url);
		if (!isset($sums)) {
			return;
		}
		$fileName = substr($url, (int)\strrpos($url, '/') + 1);
		$expected = $sums[$fileName] ?? null;
		if ($expected === null) {
			$this->logger->warning(
				'"{url}" has no entry for {file}, cannot verify the download.',
				['url' => substr($url, 0, (int)\strrpos($url, '/') + 1) . 'SHA256SUMS', 'file' => $fileName]
			);
			return;
		}
		if (!hash_equals($expected, $hash)) {
			throw new ItemImportException(
				"Checksum mismatch for {$url}: expected {$expected}, got {$hash}. ".
				'Please retry later and/or check the item data source.'
			);
		}
	}

	/**
	 * Read the SHA256SUMS file from the directory of $url
	 *
	 * @return null|array<string,string> hash per file name, null if not served
	 */
	private function fetchChecksums(string $url): ?array {
		$slashPos = \strrpos($url, '/');
		if ($slashPos === false) {
			$this->logger->warning(
				'Unable to verify {url}: the URL has no directory, so it cannot fetch a SHA256SUMS file.',
				['url' => $url]
			);
			return null;
		}
		$base = substr($url, 0, $slashPos + 1);
		if (\array_key_exists($base, $this->checksums)) {
			return $this->checksums[$base];
		}
		$sumsUrl = $base . 'SHA256SUMS';
		try {
			$sumsBody = $this->fetchSmall($sumsUrl);
		} catch (ItemImportException) {
			$this->logger->warning(
				'The host of {url} does not serve "{sumsUrl}", downloads cannot be verified.',
				['url' => $url, 'sumsUrl' => $sumsUrl]
			);
			return $this->checksums[$base] = null;
		}
		$sums = [];
		foreach (explode("\n", trim($sumsBody)) as $line) {
			$matches = Safe::pregMatch('/^([0-9a-f]{64})\s+\*?(.+)$/i', $line);
			if (!isset($matches[1], $matches[2])) {
				continue;
			}
			$sums[trim($matches[2])] = strtolower($matches[1]);
		}
		return $this->checksums[$base] = $sums === [] ? null : $sums;
	}

	/** @throws ItemImportException on error */
	private function request(Request $request, HttpClientBuilder $builder): Response {
		$url = (string)$request->getUri();
		try {
			$response = $builder->build()->request($request, new TimeoutCancellation(30));
		} catch (CancelledException $e) {
			throw new ItemImportException("Timeout downloading {$url}.", 0, $e);
		} catch (HttpException $e) {
			throw new ItemImportException("Unable to download {$url}: " . $e->getMessage(), 0, $e);
		}
		if ($response->getStatus() !== 200) {
			throw new ItemImportException("Unable to download {$url}: HTTP status " . $response->getStatus() . '.');
		}
		return $response;
	}

	/** @param array<int,true> $notInGame */
	private function applyNotInGame(array $notInGame): void {
		$ownTransaction = !$this->db->inTransaction();
		if ($ownTransaction) {
			$this->db->awaitBeginTransaction();
		}
		try {
			$this->db->table(AODBEntry::getTable())->update(['in_game' => 1]);
			foreach (array_chunk(array_keys($notInGame), 500) as $ids) {
				$this->db->table(AODBEntry::getTable())
					->whereIn('lowid', $ids)
					->orWhereIn('highid', $ids)
					->update(['in_game' => 0]);
			}
			if ($ownTransaction) {
				$this->db->commit();
			}
		} catch (Throwable $e) {
			if ($ownTransaction) {
				$this->db->rollback();
			}
			throw new ItemImportException($e->getMessage(), 0, $e);
		}
	}

	/** @param array<int,true> $notInGame */
	private function hashNotInGame(array $notInGame): string {
		$ids = array_keys($notInGame);
		sort($ids);
		return md5(implode(',', $ids));
	}

	/**
	 * Read a small CSV file of this module into memory
	 *
	 * This must happen before the import transaction starts, because reading
	 * the file gives control back to the event loop.
	 *
	 * @return list<array<string,?string>>
	 */
	private function readCSV(string $file): array {
		return iterator_to_array((new Reader($file, $this->fs))->items(), false);
	}
}

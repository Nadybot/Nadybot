<?php declare(strict_types=1);

namespace Nadybot\Modules\EXPORT_MODULE;

use function Safe\json_decode;

use Amp\File\FilesystemException;
use EventSauce\ObjectHydrator\UnableToHydrateObject;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	DB,
	Filesystem,
	Hydrator,
	ModuleInstance,
	Registry,
	Safe,
	Types\AccessLevel,
	Types\ImporterInterface,
};
use Nadybot\Modules\{
	COMMENT_MODULE\ExportCategory,
	PRIVATE_CHANNEL_MODULE\ExportMember,
	VOTE_MODULE\ExportPoll,
};
use Nadylib\Type;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;
use ValueError;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'import',
		accessLevel: AccessLevel::Superadmin,
		description: 'Import bot data and replace the current one',
	)
]
class ImportController extends ModuleInstance {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private BotConfig $config;

	/**
	 * @var array<string,string>
	 *
	 * @psalm-var array<string,class-string>
	 */
	private array $keyToClass = [];

	/** @var array<string,ImporterInterface> */
	private array $importers = [];

	#[NCA\Setup]
	public function setup(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			if (!($instance instanceof ImporterInterface)) {
				continue;
			}
			$reflection = new ReflectionClass($instance);
			$instanceAttrs = $reflection->getAttributes(NCA\Importer::class);
			if (!count($instanceAttrs)) {
				$this->logger->warning('{class} has ImporterInterface without Import attribute found', [
					'class' => $reflection->getName(),
				]);
				continue;
			}
			$instanceObj = $instanceAttrs[0]->newInstance();
			$this->logger->debug('{class} handles imports for {key}', [
				'class' => $reflection->getName(),
				'key' => $instanceObj->key,
			]);

			$this->importers[$instanceObj->key] = $instance;
			$this->keyToClass[$instanceObj->key] = $instanceObj->class;
		}
	}

	/** Import data from a file, mapping the exported access levels to your own ones */
	#[NCA\HandlesCommand('import')]
	#[NCA\Untestable]
	#[NCA\Help\Example('<symbol>import 2021-01-31 superadmin=admin admin=mod leader=member member=member')]
	#[NCA\Help\Prologue(
		"In order to import data from an old export, you should first think about\n".
		"how you want to map access levels between the bots.\n".
		'BeBot or Tyrbot use a totally different access level system than Nadybot.'
	)]
	#[NCA\Help\Epilogue(
		"<header2>Warning<end>\n\n".
		"Please note that importing a dump will delete most of the already existing\n".
		"data of your bot, so:\n".
		"<highlight>only do this after you created an export or database backup<end>!\n".
		"This cannot be stressed enough.\n\n".
		"<header2>In detail<end>\n\n".
		"Everything that is included in the dump, will be deleted before importing.\n".
		"So if your dump contains members of the bot, they will all be wiped first.\n".
		"If it does include an empty set of members, they will still be wiped.\n".
		"Only if the members were not exported at all, they won't be touched.\n\n".
		"There is no extra step in-between, so be careful not to delete any\n".
		"data you might want to keep.\n"
	)]
	public function importCommand(
		CmdContext $context,
		#[NCA\Parameter\FilenameStr] string $file,
		#[NCA\Parameter\Regexp("\w+=\w+", example: '&lt;exported al&gt;=&lt;new al&gt;')] ?string ...$mappings
	): void {
		$dataPath = $this->config->paths->data;
		$fileName = "{$dataPath}/export/" . basename($file);
		if ((pathinfo($fileName)['extension'] ?? '') !== 'json') {
			$fileName .= '.json';
		}
		if (!$this->fs->exists($fileName)) {
			$context->reply("No export file <highlight>{$fileName}<end> found.");
			return;
		}
		$import = $this->loadAndParseExportFile($fileName, $context);
		if (!isset($import)) {
			return;
		}
		$usedRanks = $this->getRanks($import);
		$validMappings = Safe::removeNull(array_values($mappings));
		$rankMapping = $this->parseRankMapping($validMappings);
		$rankMappingResult = [];
		foreach ($usedRanks as $rank) {
			if (!isset($rankMapping[$rank])) {
				$context->reply("Please define a mapping for <highlight>{$rank}<end> by appending '{$rank}=&lt;rank&gt;' to your command");
				return;
			}
			try {
				$rankMappingResult[$rank] = AccessLevel::fromName($rankMapping[$rank]);
			} catch (ValueError) {
				$context->reply("<highlight>{$rankMapping[$rank]}<end> is not a valid access level");
				return;
			}
		}
		$this->logger->notice('Starting import');
		$context->reply('Starting import...');
		foreach ($this->importers as $key => $importer) {
			$importer->import($this->db, $this->logger, $import[$key], $rankMappingResult);
		}
		$this->logger->notice('Import done');
		$context->reply('The import finished successfully.');
	}

	/**
	 * @param iterable<string> $mappings
	 *
	 * @return array<string,string>
	 */
	private function parseRankMapping(iterable $mappings): array {
		$mapping = [];
		foreach ($mappings as $part) {
			[$key, $value] = explode('=', $part);
			$mapping[$key] = $value;
		}
		return $mapping;
	}

	/**
	 * @param array<string,list<object>> $import
	 *
	 * @return list<string>
	 */
	private function getRanks(array $import): array {
		$ranks = [];
		foreach ($import['members']??[] as $member) {
			if (!($member instanceof ExportMember)) {
				continue;
			}
			if (isset($member->rank)) {
				$ranks[$member->rank] = true;
			}
		}
		foreach ($import['commentCategories']??[] as $category) {
			if (!($category instanceof ExportCategory)) {
				continue;
			}
			if (isset($category->minRankToRead)) {
				$ranks[$category->minRankToRead] = true;
			}
			if (isset($category->minRankToWrite)) {
				$ranks[$category->minRankToWrite] = true;
			}
		}
		foreach ($import['polls']??[] as $poll) {
			if (!($poll instanceof ExportPoll)) {
				continue;
			}
			if (isset($poll->minRankToVote)) {
				$ranks[$poll->minRankToVote] = true;
			}
		}

		/** @var list<string> */
		$result = array_values(array_filter(array_keys($ranks), is_string(...)));
		return $result;
	}

	/** @return ?array<string,list<object>> */
	private function loadAndParseExportFile(string $fileName, CmdContext $sendto): ?array {
		if (!$this->fs->exists($fileName)) {
			$sendto->reply("No export file <highlight>{$fileName}<end> found.");
			return null;
		}
		$this->logger->notice('Decoding the JSON data');
		try {
			$import = json_decode($this->fs->read($fileName), true);
			Type\dict(Type\string(), Type\mixed())->assert($import);
		} catch (FilesystemException $e) {
			$sendto->reply("Error reading <highlight>{$fileName}<end>: ".
				$e->getMessage() . '.');
			return null;
		} catch (Type\Exception\AssertException $e) {
			$sendto->reply("The file <highlight>{$fileName}<end> is not a valid export file.");
			return null;
		} catch (Throwable $e) {
			$sendto->reply("Error decoding <highlight>{$fileName}<end>.");
			return null;
		}

		$this->logger->notice('Loading schema data');
		$sendto->reply('Validating the import data. This could take a while.');

		/** @var array<string,list<object>> */
		$result = [];
		try {
			$validator = Type\vec(Type\dict(Type\string(), Type\mixed()));
			foreach ($import as $key => $importData) {
				if (!$validator->matches($importData)) {
					continue;
				}

				$objects = Hydrator::literalHydrateObjects(
					className: $this->keyToClass[$key],
					data: $importData,
				)->toArray();

				/** @var list<object> $objects */
				$result[$key] = $objects;
			}
		} catch (UnableToHydrateObject $e) {
			$sendto->reply('The import data is not valid: <highlight>' . $e->getMessage() . '<end>.');
			return null;
		}
		return $result;
	}
}

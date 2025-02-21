<?php declare(strict_types=1);

namespace Nadybot\Modules\EXPORT_MODULE;

use function Safe\json_encode;

use Amp\File\FilesystemException;
use EventSauce\ObjectHydrator\UnableToSerializeObject;
use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	DB,
	Filesystem,
	Hydrator,
	ModuleInstance,
	Registry,
	Types\ExporterInterface,
};
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Safe\Exceptions\JsonException;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'export',
		accessLevel: AccessLevel::Superadmin,
		description: 'Export the bot configuration and data',
	)
]
class ExportController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/** @var array<string,ExporterInterface> */
	private array $exporters = [];

	#[NCA\Setup]
	public function setup(): void {
		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			if (!($instance instanceof ExporterInterface)) {
				continue;
			}
			$reflection = new ReflectionClass($instance);
			$instanceAttrs = $reflection->getAttributes(NCA\Exporter::class);
			if (!count($instanceAttrs)) {
				$this->logger->warning('{class} has ExporterInterface without Export attribute found', [
					'class' => $reflection->getName(),
				]);
				continue;
			}
			$instanceObj = $instanceAttrs[0]->newInstance();
			$this->logger->debug('{class} handles exports for {key}', [
				'class' => $reflection->getName(),
				'key' => $instanceObj->key,
			]);

			$this->exporters[$instanceObj->key] = $instance;
		}
	}

	/** Export all of this bot's data into a portable JSON-file */
	#[NCA\HandlesCommand('export')]
	#[NCA\Help\Example(
		command: '<symbol>export 2021-01-31',
		description: "Export everything into 'data/export/2021-01-31.json'"
	)]
	#[NCA\Help\Prologue(
		"The purpose of this command is to create a portable file containing all the\n".
		"data, not settings, of your bot so it can later be imported into another\n".
		'bot.'
	)]
	public function exportCommand(CmdContext $context, string $file): void {
		$dataPath = $this->config->paths->data;
		$fileName = "{$dataPath}/export/" . basename($file);
		if ((pathinfo($fileName)['extension'] ?? '') !== 'json') {
			$fileName .= '.json';
		}
		if (!$this->fs->exists("{$dataPath}/export")) {
			$this->fs->createDirectory("{$dataPath}/export", 0o700);
		}
		$context->reply('Starting export...');
		$exports = [];
		foreach ($this->exporters as $key => $exporter) {
			$exports[$key] = $exporter->export($this->db, $this->logger);
		}
		try {
			$serialized = [];
			foreach ($exports as $key => $data) {
				$serialized[$key] = Hydrator::literalSerializeObjects($data)->toArray();
			}
			$cleaned = self::stripNull($serialized);
			$output = json_encode($cleaned, \JSON_PRETTY_PRINT|\JSON_UNESCAPED_SLASHES);
		} catch (JsonException | UnableToSerializeObject $e) {
			$context->reply('There was an error exporting the data: ' . $e->getMessage());
			return;
		}
		try {
			$this->fs->write($fileName, $output);
		} catch (FilesystemException $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply("The export was successfully saved in {$fileName}.");
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private static function stripNull(array $data): array {
		foreach ($data as $key => $value) {
			if (is_null($value)) {
				unset($data[$key]);
			} elseif (is_array($value)) {
				$data[$key] = self::stripNull($value);
			}
		}
		return $data;
	}
}

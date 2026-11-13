<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use function Safe\parse_url;

use Nadybot\Core\{Attributes as NCA, DB, DBSchema\Setting, ModuleInstance, Registry, SettingManager, Types\AccessLevel};
use Psr\Log\LoggerInterface;

/**
 * Knows all directory providers and decides which ones to ask
 * for a given feature and dimension.
 *
 * This is the only place that decides whether a feature is available
 * on a dimension, so no other code should check dimensions for that.
 *
 * Third-party providers must be module instances (extend ModuleInstance),
 * because the class loader only instantiates those inside module directories.
 */
#[NCA\Instance]
class DirectoryRegistry extends ModuleInstance {
	public const PORK_URL = 'http://people.anarchy-online.com';
	public const BORK_URL = 'https://bork.aobots.org';
	public const AOBOTS_HISTORY_URL = 'https://history.aobots.org/?server={dimension}&name={name}';
	public const JKBFF_HISTORY_URL = 'https://pork.jkbff.com/pork/history.php?server={dimension}&name={name}';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private SettingManager $settingManager;

	/** @var ?list<DirectoryProvider> */
	private ?array $providers = null;

	/**
	 * Providers created on the fly for setting values that are no known provider id
	 *
	 * @var array<string,PorkProvider>
	 */
	private array $adHocProviders = [];

	/** @param ?list<DirectoryProvider> $providers Use exactly these providers instead of discovering them */
	public function __construct(?array $providers=null) {
		$this->providers = $providers;
	}

	/**
	 * Get all known providers: the built-in ones and all registered instances
	 * that implement DirectoryProvider
	 *
	 * @return list<DirectoryProvider>
	 */
	public function getProviders(): array {
		if (isset($this->providers)) {
			return $this->providers;
		}
		$providers = $this->createBuiltinProviders();
		foreach (Registry::getAllInstances() as $instance) {
			if ($instance instanceof DirectoryProvider) {
				$providers []= $instance;
			}
		}
		return $this->providers = $providers;
	}

	/** Get a provider by its id */
	public function getProvider(string $id): ?DirectoryProvider {
		foreach ($this->getProviders() as $provider) {
			if ($provider->getId() === $id) {
				return $provider;
			}
		}
		return null;
	}

	/** Is there any provider for $feature on $dimension? */
	public function supports(DirectoryFeature $feature, int $dimension): bool {
		return count($this->providersFor($feature, $dimension)) > 0;
	}

	/**
	 * Get all providers that can deliver $feature for $dimension,
	 * the one with id $preferredId first, if it qualifies
	 *
	 * @return list<DirectoryProvider>
	 */
	public function providersFor(DirectoryFeature $feature, int $dimension, ?string $preferredId=null): array {
		$interface = $feature->interface();
		$providers = $this->getProviders();
		if (isset($preferredId) && $this->getProvider($preferredId) === null) {
			$adHoc = $this->getAdHocProvider($preferredId);
			if (isset($adHoc)) {
				$providers = [$adHoc, ...$providers];
			}
		}
		$result = array_values(array_filter(
			$providers,
			static function (DirectoryProvider $provider) use ($feature, $dimension, $interface): bool {
				return $provider instanceof $interface
					&& in_array($feature, $provider->getFeatures(), true)
					&& $provider->supportsDimension($dimension);
			}
		));
		if (!isset($preferredId)) {
			return $result;
		}
		usort(
			$result,
			static fn (DirectoryProvider $a, DirectoryProvider $b): int => ($b->getId() === $preferredId) <=> ($a->getId() === $preferredId)
		);
		return $result;
	}

	/** @return list<CharacterProvider> */
	public function characterProviders(int $dimension, ?string $preferredId=null): array {
		/** @var list<CharacterProvider> */
		$result = $this->providersFor(DirectoryFeature::Character, $dimension, $preferredId);
		return $result;
	}

	/** @return list<OrgRosterProvider> */
	public function orgRosterProviders(int $dimension, ?string $preferredId=null): array {
		/** @var list<OrgRosterProvider> */
		$result = $this->providersFor(DirectoryFeature::OrgRoster, $dimension, $preferredId);
		return $result;
	}

	/** @return list<OrgListProvider> */
	public function orgListProviders(int $dimension, ?string $preferredId=null): array {
		/** @var list<OrgListProvider> */
		$result = $this->providersFor(DirectoryFeature::OrgList, $dimension, $preferredId);
		return $result;
	}

	/** @return list<HistoryProvider> */
	public function historyProviders(int $dimension, ?string $preferredId=null): array {
		/** @var list<HistoryProvider> */
		$result = $this->providersFor(DirectoryFeature::History, $dimension, $preferredId);
		return $result;
	}

	/**
	 * Get the options for a setting choosing the preferred provider of
	 * $feature among those serving any of $dimensions
	 *
	 * @param list<int> $dimensions
	 *
	 * @return array<string,string> label => provider id
	 */
	public function getSettingOptions(DirectoryFeature $feature, array $dimensions): array {
		$options = [];
		foreach ($this->getProviders() as $provider) {
			if (!in_array($feature, $provider->getFeatures(), true)) {
				continue;
			}
			foreach ($dimensions as $dimension) {
				if ($provider->supportsDimension($dimension)) {
					$options[$provider->getName()] = $provider->getId();
					break;
				}
			}
		}
		return $options;
	}

	/**
	 * Replace the choices of the already registered setting $name with all providers
	 * offering $feature on any of $dimensions, keeping all other properties and the value
	 *
	 * @param list<int> $dimensions
	 */
	public function updateSettingOptions(string $name, DirectoryFeature $feature, array $dimensions): void {
		$setting = $this->db->table(Setting::getTable())
			->where('name', $name)
			->firstObj(Setting::class);
		if (!isset($setting, $setting->module, $setting->type)) {
			$this->logger->warning('Cannot update options of unknown setting {setting}', [
				'setting' => $name,
			]);
			return;
		}
		$this->settingManager->add(
			module: $setting->module,
			name: $setting->name,
			description: $setting->description ?? '',
			mode: $setting->mode,
			value: $this->settingManager->getValue($name) ?? $setting->value ?? '',
			type: $setting->type,
			options: $this->getSettingOptions($feature, $dimensions),
			accessLevel: $setting->access_level ?? AccessLevel::Mod,
			help: $setting->help,
			confidential: $setting->confidential,
		);
	}

	/** @return list<DirectoryProvider> */
	private function createBuiltinProviders(): array {
		$providers = [
			new PorkProvider(
				name: 'bork.aobots.org (Nadybot)',
				baseUrl: self::BORK_URL,
				characterTimeout: 1,
				orgTimeout: 10,
			),
			new PorkProvider(
				name: 'people.anarchy-online.com (Funcom)',
				baseUrl: self::PORK_URL,
			),
			new AoHistoryProvider(
				name: 'history.aobots.org',
				urlTemplate: self::AOBOTS_HISTORY_URL,
				notifyUrlTemplate: self::JKBFF_HISTORY_URL,
			),
			new AoHistoryProvider(
				name: 'pork.jkbff.com',
				urlTemplate: self::JKBFF_HISTORY_URL,
			),
			new LostEdenProvider(),
		];
		foreach ($providers as $provider) {
			Registry::injectDependencies($provider);
		}
		return $providers;
	}

	/**
	 * Settings used to take any base URL of a PORK-compatible service.
	 * Keep that working for values that are not the id of a known provider.
	 */
	private function getAdHocProvider(string $url): ?PorkProvider {
		if (isset($this->adHocProviders[$url])) {
			return $this->adHocProviders[$url];
		}
		if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
			return null;
		}
		$host = parse_url($url, \PHP_URL_HOST);
		$provider = new PorkProvider(
			name: is_string($host) ? $host : $url,
			baseUrl: rtrim($url, '/'),
		);
		Registry::injectDependencies($provider);
		return $this->adHocProviders[$url] = $provider;
	}
}

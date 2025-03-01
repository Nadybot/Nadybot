<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use AO\Utils;
use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastToType;
use InvalidArgumentException;
use Nadybot\Core\Attributes\Hydrator\{ConvertToBool, ForceList};
use Nadybot\Core\Types\Status;

/** General config settings */
class General {
	/**
	 * @param string      $orgName             Set to a non-empty string, if this is an org bot for
	 *                                         the given org
	 * @param string[]    $superAdmins         A list of character names that will get superadmin rank
	 * @param bool        $showAomlMarkup      Log the raw data that's send to Funcom, instead of
	 *                                         removing popups and simplifying links
	 * @param Status      $defaultModuleStatus Should all modules (and new ones) be enabled
	 *                                         or disabled by default?
	 * @param bool        $enableConsoleClient Set to enable managing the bot via the console.
	 *                                         Doesn't work under Windows
	 * @param bool        $enablePackageModule Enable the !package-command that allows installing
	 *                                         additional modules from within the bot
	 * @param bool        $enableHydratorCache Speed up parsing of network data at the expense
	 *                                         of needing some more RAM
	 * @param bool        $autoOrgName         Automatically pick up the org's name from the org channel name
	 * @param null|string $timezone            Timezone to use when displaying date and time in the bot
	 *
	 * @psalm-param list<string> $superAdmins
	 */
	public function __construct(
		public string $orgName,
		#[ForceList] #[MapFrom('super_admins')] public array $superAdmins,
		#[ConvertToBool] public bool $showAomlMarkup=false,
		#[CastToType('int')] public Status $defaultModuleStatus=Status::Enabled,
		#[ConvertToBool] public bool $enableConsoleClient=true,
		#[ConvertToBool] public bool $enablePackageModule=true,
		#[ConvertToBool] public bool $enableHydratorCache=true,
		#[ConvertToBool] #[MapFrom('auto_org_name')] public bool $autoOrgName=false,
		public ?string $timezone=null,
	) {
		$this->superAdmins = array_map(static function (string $char): string {
			$normalized = Utils::normalizeCharacter($char);
			if ((strlen($normalized) < 4) || (strlen($normalized) > 12)) {
				throw new InvalidArgumentException("\"{$normalized}\" is an invalid character name for a Superadmin.");
			}
			return $normalized;
		}, $this->superAdmins);
	}
}

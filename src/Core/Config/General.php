<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastToType;
use InvalidArgumentException;
use Nadybot\Core\Attributes\{ConvertToBool, ForceList};
use Nadybot\Core\Types\Status;

/** General config settings */
class General {
	/**
	 * @param string[] $superAdmins
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
			$normalized = ucfirst(strtolower($char));
			if ((strlen($normalized) < 4) || (strlen($normalized) > 12)) {
				throw new InvalidArgumentException("\"{$normalized}\" is an invalid character name for a Superadmin.");
			}
			return $normalized;
		}, $this->superAdmins);
	}
}

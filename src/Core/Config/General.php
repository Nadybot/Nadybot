<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use EventSauce\ObjectHydrator\MapFrom;
use EventSauce\ObjectHydrator\PropertyCasters\CastToType;
use Nadybot\Core\Attributes\ForceList;

class General {
	/** @param string[] $superAdmins */
	public function __construct(
		public string $orgName,
		#[ForceList] #[MapFrom('super_admins')] public array $superAdmins,
		#[CastToType('bool')] public bool $showAomlMarkup=false,
		#[CastToType('int')] public int $defaultModuleStatus=1,
		#[CastToType('bool')] public bool $enableConsoleClient=true,
		#[CastToType('bool')] public bool $enablePackageModule=true,
		#[CastToType('bool')] #[MapFrom('auto_org_name')] public bool $autoOrgName=false,
		public ?string $timezone=null,
	) {
		$this->superAdmins = array_map(static function (string $char): string {
			return ucfirst(strtolower($char));
		}, $this->superAdmins);
	}
}

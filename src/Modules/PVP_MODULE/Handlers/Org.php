<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Argument(
	names: ['org', 'guild', 'organization'],
	description: "Only keep sites of a specific org.\n".
		"Since all filters are only narrowing further down, it doesn't make\n".
		"an sense to filter by more than 1 org, since this will never\n".
		"match any site.\n".
		"If the name of the org contains spaces, put the overall org name into\n".
		"quotation marks, e.g. \"Team rainbow\". The match is case insensitive,\n".
		'and You can also use the * wildcard-operator.',
	type: 'string',
	examples: ['"Team Rainbow"', 'devil*'],
)]
class Org extends Base {
	public function matches(SiteUpdate $site): bool {
		if (!isset($site->org_name)) {
			return false;
		}
		return fnmatch($this->value, $site->org_name, \FNM_CASEFOLD);
	}

	protected function validateValue(): void {
	}
}

<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

/** The kinds of data a directory provider can deliver */
enum DirectoryFeature: string {
	/**
	 * The interface a provider must implement to deliver this feature
	 *
	 * @return class-string<DirectoryProvider>
	 */
	public function interface(): string {
		return match ($this) {
			self::Character => CharacterProvider::class,
			self::OrgRoster => OrgRosterProvider::class,
			self::OrgList => OrgListProvider::class,
			self::History => HistoryProvider::class,
		};
	}

	/** Look up a single character by name */
	case Character = 'character';

	/** Look up an organization and its members by id */
	case OrgRoster = 'org_roster';

	/** Download the list of all organizations */
	case OrgList = 'org_list';

	/** Look up the history of a character */
	case History = 'history';
}

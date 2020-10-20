<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class BasicSystemInformation {
	/** Name of the bot character in AO */
	public string $bot_name;

	/** Name of the character running the bot, null if not set */
	public ?string $superadmin;

	/** Name of the org this bot is in or null if not in an org */
	public ?string $org;

	/** ID of the org this bot is in or null if not in an org */
	public ?int $org_id;

	/** Which Nadybot version are we running? */
	public string $bot_version;

	/** Which PHP version are we running? */
	public string $php_version;

	/** Which operating system/kernel are we running? */
	public string $os;

	/** Which database type (mysql/sqlite) are we using? */
	public string $db_type;
}

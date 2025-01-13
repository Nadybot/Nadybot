<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

class BasicSystemInformation {
	/**
	 * @param string     $bot_name    Name of the bot character in AO
	 * @param string[]   $workers     Name(s) of the worker characters in AO
	 * @param string[]   $superadmins Name(s) of the character(s) running the bot, empty if not set
	 * @param ?string    $org         Name of the org this bot is in or null if not in an org
	 * @param ?int       $org_id      ID of the org this bot is in or null if not in an org
	 * @param string     $bot_version Which Nadybot version are we running?
	 * @param string     $php_version Which PHP version are we running?
	 * @param string     $event_loop  Which event loop driver are we running?
	 * @param string     $fs          Which file system driver are we running?
	 * @param string     $os          Which operating system/kernel are we running?
	 * @param string     $db_type     Which database type (mysql/sqlite) are we using?
	 * @param Endianness $endianness  Which endianness (little-endian, big-endian) are we running on?
	 *
	 * @psalm-param list<string> $superadmins
	 * @psalm-param list<string> $workers
	 */
	public function __construct(
		public string $bot_name,
		public array $workers,
		public array $superadmins,
		public ?string $org,
		public ?int $org_id,
		public string $bot_version,
		public string $php_version,
		public string $event_loop,
		public string $fs,
		public string $os,
		public string $db_type,
		public Endianness $endianness,
	) {
	}
}

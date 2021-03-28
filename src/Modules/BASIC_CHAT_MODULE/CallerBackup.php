<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use DateTime;

class CallerBackup {
	public DateTime $time;

	/**
	 * Names of all callers
	 * @var array<string,CallerList>
	 */
	public array $callers = [];

	public string $changer;

	public string $command;

	public function __construct(string $changer, string $command, array $callers) {
		$this->time = new DateTime();
		$this->changer = $changer;
		$this->command = $command;
		$this->callers = $callers;
	}
}

<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use DateTimeZone;
use Nadybot\Core\{
	CommandReply,
	Nadybot,
	Text,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'timezone',
 *		accessLevel = 'superadmin',
 *		description = "Set the timezone",
 *		help        = 'timezone.txt',
 *		alias		= 'timezones'
 *	)
 */
class TimezoneController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Nadybot $chatBot;
	
	/**
	 * @HandlesCommand("timezone")
	 * @Matches("/^timezone$/i")
	 */
	public function timezoneCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$timezoneAreas = $this->getTimezoneAreas();
		
		$blob = '';
		foreach ($timezoneAreas as $area => $code) {
			$blob .= $this->text->makeChatcmd($area, "/tell <myname> timezone $area") . "\n";
		}
		$msg = $this->text->makeBlob("Timezone Areas", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("timezone")
	 * @Matches("/^timezone set ([^ ]*)$/i")
	 */
	public function timezoneSetCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$timezone = $args[1];
		
		$result = date_default_timezone_set($timezone);
		
		if ($result === false) {
			$msg = "<highlight>$timezone<end> is not a valid timezone.";
			$sendto->reply($msg);
			return;
		}
		$msg = "Timezone has been set to <highlight>$timezone<end>.";
		$config = $this->chatBot->runner->getConfigFile();
		$config->load();
		$config->setVar('timezone', $timezone);
		$config->save();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("timezone")
	 * @Matches("/^timezone ([^ ]*)$/i")
	 */
	public function timezoneAreaCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$area = $args[1];
		
		$timezoneAreas = $this->getTimezoneAreas();
		$code = $timezoneAreas[$area];
		if (empty($code)) {
			$msg = "<highlight>$area<end> is not a valid area.";
			$sendto->reply($msg);
			return;
		}
		
		/** @var string[] */
		$timezones = DateTimeZone::listIdentifiers($code);
		$count = count($timezones);
		
		$blob = '';
		foreach ($timezones as $timezone) {
			$blob .= $this->text->makeChatcmd($timezone, "/tell <myname> timezone set $timezone") . "\n";
		}
		$msg = $this->text->makeBlob("Timezones for $area ($count)", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @return array<string,int>
	 */
	public function getTimezoneAreas(): array {
		return [
			'Africa'     => DateTimeZone::AFRICA,
			'America'    => DateTimeZone::AMERICA,
			'Antarctica' => DateTimeZone::ANTARCTICA,
			'Arctic'     => DateTimeZone::ARCTIC,
			'Asia'       => DateTimeZone::ASIA,
			'Atlantic'   => DateTimeZone::ATLANTIC,
			'Australia'  => DateTimeZone::AUSTRALIA,
			'Europe'     => DateTimeZone::EUROPE,
			'Indian'     => DateTimeZone::INDIAN,
			'Pacific'    => DateTimeZone::PACIFIC,
			'UTC'        => DateTimeZone::UTC,
		];
	}
}

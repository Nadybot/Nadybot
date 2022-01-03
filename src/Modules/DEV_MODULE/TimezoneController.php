<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use DateTimeZone;
use Nadybot\Core\{
	CmdContext,
	ConfigFile,
	Instance,
	Nadybot,
	Text,
};
use Nadybot\Core\ParamClass\PWord;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "timezone",
		accessLevel: "superadmin",
		description: "Set the timezone",
		help: "timezone.txt",
		alias: "timezones"
	)
]
class TimezoneController extends Instance {

		#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\HandlesCommand("timezone")]
	public function timezoneCommand(CmdContext $context): void {
		$timezoneAreas = $this->getTimezoneAreas();

		$blob = "<header2>Available timezones areas<end>\n";
		foreach ($timezoneAreas as $area => $code) {
			$blob .= "<tab>" . $this->text->makeChatcmd($area, "/tell <myname> timezone $area") . "\n";
		}
		$msg = $this->text->makeBlob("Timezone Areas", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("timezone")]
	public function timezoneSetCommand(CmdContext $context, #[NCA\Str("set")] string $action, PWord $timezone): void {
		$result = date_default_timezone_set($timezone());

		if ($result === false) {
			$msg = "<highlight>{$timezone}<end> is not a valid timezone.";
			$context->reply($msg);
			return;
		}
		$msg = "Timezone has been set to <highlight>{$timezone}<end>.";
		$this->config->timezone = $timezone();
		$this->config->save();
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("timezone")]
	public function timezoneAreaCommand(CmdContext $context, PWord $area): void {
		$area = $area();

		$timezoneAreas = $this->getTimezoneAreas();
		$code = $timezoneAreas[$area];
		if (empty($code)) {
			$msg = "<highlight>{$area}<end> is not a valid area.";
			$context->reply($msg);
			return;
		}

		/** @var string[] */
		$timezones = DateTimeZone::listIdentifiers($code);
		$count = count($timezones);

		$blob = "<header2>Timezones in {$area}<end>\n";
		foreach ($timezones as $timezone) {
			$blob .= "<tab>" . $this->text->makeChatcmd($timezone, "/tell <myname> timezone set $timezone") . "\n";
		}
		$msg = $this->text->makeBlob("Timezones for {$area} ({$count})", $blob);
		$context->reply($msg);
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

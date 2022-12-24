<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Nadybot\Core\Attributes\DefineSetting;
use Nadybot\Core\Modules\ALTS\NickController;
use Nadybot\Core\{AccessManager, Registry, SettingManager, Text};
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Template extends DefineSetting {
	/**
	 * @inheritDoc
	 *
	 * @param array<string|int,int|string>  $options       An optional list of values that the setting can be, semi-colon delimited.
	 *                                                     Alternatively, use an associative array [label => value], where label is optional.
	 * @param array<string,string|int|null> $exampleValues An optional list of example values to calculate the current dsplay value
	 */
	public function __construct(
		public string $type='template',
		public ?string $name=null,
		public null|int|float|string|bool $defaultValue=null,
		public string $mode='edit',
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
		public ?array $exampleValues=null,
	) {
		$this->type = 'template';
		if (isset($this->exampleValues)) {
			return;
		}
		$this->exampleValues = [
			"name" => "Nady",
			"c-name" => "<highlight>Nady<end>",
			"first-name" => "",
			"last-name" => "",
			"level" => 220,
			"c-level" => "<highlight>220<end>",
			"ai-level" => 30,
			"c-ai-level" => "<green>30<end>",
			"prof" => "Bureaucrat",
			"c-prof" => "<highlight>Bureaucrat<end>",
			"profession" => "Bureaucrat",
			"c-profession" => "<highlight>Bureaucrat<end>",
			"org" => "Team Rainbow",
			"c-org" => "<clan>Team Rainbow<end>",
			"org-rank" => "Advisor",
			"breed" => "Nano",
			"faction" => "Clan",
			"c-faction" => "<clan>Clan<end>",
			"gender" => "Female",
			"channel-name" => "the private channel",
			"whois" => "<highlight>\"Nady\"<end> (<highlight>220<end>/<green>30<end>, Female Nano <highlight>Bureaucrat<end>, <clan>Clan<end>, Veteran of <clan>Team Rainbow<end>)",
			"short-prof" => "Crat",
			"c-short-prof" => "<highlight>Crat<end>",
			"main" => "Nadyita",
			"c-main" => "<highlight>Nadyita<end>",
			"nick" => null,
			"c-nick" => null,
			"alt-of" => "Alt of <highlight>Nadyita<end>",
			"alt-list" => "<a href=skillid://1>Nadyita's Alts (18)</a>",
			"logon-msg" => "My logon-message",
			"logoff-msg" => "My logoff-message",
			"access-level" => "admin",
			"admin-level" => "Administrator",
			"c-admin-level" => "<red>Administrator<end>",
		];

		/** @var ?SettingManager */
		$settingManager = Registry::getInstance("settingmanager");
		if (isset($settingManager)) {
			if ($settingManager->getBool('guild_channel_status') === false) {
				$this->exampleValues["channel-name"] = "<myname>";
			}
		}

		/** @var ?NickController */
		$nickController = Registry::getInstance("nickcontroller");

		/** @var ?Text */
		$text = Registry::getInstance("text");
		if (isset($nickController, $text)) {
			$this->exampleValues["nick"] = "Nickname";
			$this->exampleValues["c-nick"] = $text->renderPlaceholders(
				$nickController->nickFormat,
				[
					"nick" => $this->exampleValues["nick"],
					"main" => $this->exampleValues["main"],
				]
			);
		}

		/** @var ?AccessManager */
		$accessManager = Registry::getInstance("accessmanager");
		if (isset($accessManager)) {
			$alName = ucfirst($accessManager->getDisplayName("admin"));
			$this->exampleValues["admin-level"] = $alName;
		}

		/** @var ?OnlineController */
		$onlineController = Registry::getInstance("onlinecontroller");
		if (isset($onlineController)) {
			$this->exampleValues["c-admin-level"] = $onlineController->rankColorAdmin.
				$this->exampleValues["admin-level"] . "<end>";
		}
	}
}

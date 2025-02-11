<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Setting;

use Attribute;
use Nadybot\Core\{
	AccessManager,
	Attributes\DefineSetting,
	Modules\ALTS\NickController,
	Registry,
	SettingManager,
	Text,
	Types\SettingMode
};
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Template extends DefineSetting {
	/**
	 * @inheritDoc
	 *
	 * @param null|int|float|string|bool|list<mixed> $defaultValue
	 * @param array<string|int,int|string>           $options       An optional list of values that the setting can be, semi-colon delimited.
	 *                                                              Alternatively, use an associative array [label => value], where label is optional.
	 * @param ?array<string,string|int|null>         $exampleValues An optional list of example values to calculate the current display value
	 */
	public function __construct(
		public string $type='template',
		public ?string $name=null,
		public null|int|float|string|bool|array $defaultValue=null,
		public SettingMode $mode=SettingMode::Edit,
		public array $options=[],
		public string $accessLevel='mod',
		public ?string $help=null,
		public ?array $exampleValues=null,
		public ?bool $confidential=false,
	) {
		$this->type = 'template';
		if (isset($this->exampleValues)) {
			return;
		}
		$this->exampleValues = [
			'name' => 'Nady',
			'c-name' => '<highlight>Nady<end>',
			'first-name' => '',
			'last-name' => '',
			'level' => 220,
			'c-level' => '<highlight>220<end>',
			'ai-level' => 30,
			'c-ai-level' => '<green>30<end>',
			'prof' => 'Bureaucrat',
			'c-prof' => '<highlight>Bureaucrat<end>',
			'profession' => 'Bureaucrat',
			'c-profession' => '<highlight>Bureaucrat<end>',
			'org' => 'Team Rainbow',
			'c-org' => '<clan>Team Rainbow<end>',
			'org-rank' => 'Advisor',
			'breed' => 'Nano',
			'faction' => 'Clan',
			'c-faction' => '<clan>Clan<end>',
			'gender' => 'Female',
			'channel-name' => 'the private channel',
			'whois' => '<highlight>"Nady"<end> (<highlight>220<end>/<green>30<end>, Female Nano <highlight>Bureaucrat<end>, <clan>Clan<end>, Veteran of <clan>Team Rainbow<end>)',
			'short-prof' => 'Crat',
			'c-short-prof' => '<highlight>Crat<end>',
			'main' => 'Nadyita',
			'c-main' => '<highlight>Nadyita<end>',
			'nick' => null,
			'c-nick' => null,
			'alt-of' => 'Alt of <highlight>Nadyita<end>',
			'alt-list' => "<a href=skillid://1>Nadyita's Alts (18)</a>",
			'logon-msg' => 'My logon-message',
			'logoff-msg' => 'My logoff-message',
			'access-level' => 'admin',
			'admin-level' => 'Administrator',
			'c-admin-level' => '<red>Administrator<end>',
		];

		$settingManager = Registry::getInstance(SettingManager::class);
		if ($settingManager->getBool('guild_channel_status') === false) {
			$this->exampleValues['channel-name'] = '<myname>';
		}

		$nickController = Registry::getInstance(NickController::class);

		$this->exampleValues['nick'] = 'Nickname';
		$this->exampleValues['c-nick'] = Text::renderPlaceholders(
			$nickController->nickFormat,
			[
				'nick' => $this->exampleValues['nick'],
				'main' => $this->exampleValues['main'],
			]
		);

		$accessManager = Registry::getInstance(AccessManager::class);
		$alName = ucfirst($accessManager->getDisplayName('admin'));
		$this->exampleValues['admin-level'] = $alName;

		$onlineController = Registry::getInstance(OnlineController::class);
		$this->exampleValues['c-admin-level'] = $onlineController->rankColorAdmin.
			$this->exampleValues['admin-level'] . '<end>';
	}
}

<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	DB,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Nadybot,
	ParamClass\PRemove,
	ParamClass\PWord,
	Text,
	Types\AccessLevelProvider,
	Types\CommandReply,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/RankMapping'),
	NCA\DefineCommand(
		command: 'ranks',
		accessLevel: 'guest',
		description: 'Show a list of all available org ranks',
	),
	NCA\DefineCommand(
		command: 'maprank',
		accessLevel: 'admin',
		description: 'Define how org ranks map to bot ranks',
	),
]
class GuildRankController extends ModuleInstance implements AccessLevelProvider {
	/** Map org ranks to bot ranks */
	#[NCA\Setting\Boolean]
	public bool $mapOrgRanksToBotRanks = false;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private GuildController $guildController;

	#[NCA\Inject]
	private GuildManager $guildManager;

	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);
	}

	public function getSingleAccessLevel(string $sender): ?string {
		if (!isset($this->chatBot->guildmembers[$sender])) {
			return null;
		}
		if (!$this->mapOrgRanksToBotRanks) {
			return 'guild';
		}
		return $this->getEffectiveAccessLevel($this->chatBot->guildmembers[$sender]);
	}

	/**
	 * Get a list of all defined rank mappings
	 *
	 * @return Collection<int,OrgRankMapping>
	 */
	public function getMappings(): Collection {
		return $this->db->table(OrgRankMapping::getTable())
			->orderBy('min_rank')
			->asObj(OrgRankMapping::class);
	}

	public function getEffectiveAccessLevel(int $rank): string {
		/** @var ?OrgRankMapping */
		$rank = $this->db->table(OrgRankMapping::getTable())
			->where('min_rank', '>=', $rank)
			->orderBy('min_rank')
			->limit(1)
			->asObj(OrgRankMapping::class)
			->first();
		return $rank ? $rank->access_level : 'guild';
	}

	/** Get a list of all your defined mappings of org rank to bot access level */
	#[NCA\HandlesCommand('maprank')]
	#[NCA\Help\Group('org-ranks')]
	public function maprankListCommand(CmdContext $context): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply('The bot must be in an org.');
			return;
		}
		$org = $this->guildManager->byId($this->config->orgId??0, null, false);
		$this->displayRankMappings($org, $context);
	}

	public function displayRankMappings(?Guild $guild, CmdContext $context): void {
		if (!isset($guild)) {
			$context->reply('Unable to find information about organization. Maybe PORK is down.');
			return;
		}
		$maps = $this->getMappings();

		/** @var array<int,true> */
		$mapKeys = $maps->reduce(
			static function (array $carry, OrgRankMapping $m): array {
				$carry[$m->min_rank] = true;
				return $carry;
			},
			[]
		);
		$ranks = $guild->governing_form->getOrgRanks();
		if ($maps->isEmpty()) {
			$context->reply('There are currently no org rank to bot rank mappings defined.');
			return;
		}
		$blob = "<header2>Mapped ranks<end>\n";
		foreach ($ranks as $rank => $rankName) {
			$accessLevel = $this->getEffectiveAccessLevel($rank);
			$blob .= '<tab>'.
				"{$rank} - {$rankName}: ".
				'<highlight>'.
				$this->accessManager->getDisplayName($accessLevel).
				'<end>';
			if (isset($mapKeys[$rank])) {
				$blob .= ' [' . Text::makeChatcmd('remove', "/tell <myname> maprank del {$rank}") . ']';
			}
			$blob .= "\n";
		}
		$msg = Text::makeBlob("Defined mappings ({$maps->count()})", $blob);
		$context->reply($msg);
	}

	/** Give &lt;access level&gt; rights to every org member rank &lt;rank id&gt; or higher */
	#[NCA\HandlesCommand('maprank')]
	#[NCA\Help\Group('org-ranks')]
	#[NCA\Help\Example(
		command: '<symbol>maprank 0 to admin',
		description: 'Give admin rights to org rank 0 (President, Monarch, Lorg, etc.)'
	)]
	#[NCA\Help\Example(
		command: '<symbol>maprank 1 to mod',
		description: 'Give mod rights to org rank 1 (Knight, Advisor, Board Member, etc.) or higher',
	)]
	#[NCA\Help\Epilogue(
		"Use <a href='chatcmd:///tell <myname> ranks'><symbol>ranks</a> to get the numeric rank IDs of your org"
	)]
	public function maprankCommand(CmdContext $context, int $rankId, #[NCA\Str('to')] ?string $to, PWord $accessLevel): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply('The bot must be in an org.');
			return;
		}
		$org = $this->guildManager->byId($this->config->orgId??0, null, false);
		$this->setRankMapping($org, $rankId, $accessLevel(), $context->char->name, $context);
	}

	public function setRankMapping(?Guild $guild, int $rank, string $accessLevel, string $sender, CommandReply $sendto): void {
		if (!isset($guild)) {
			$sendto->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $guild->governing_form->getOrgRanks();
		$accessLevels = $this->accessManager->getAccessLevels();
		try {
			$accessLevel = $this->accessManager->getAccessLevel($accessLevel);
		} catch (Exception) {
			// Catch system error about invalid access level
		}
		if (!isset($accessLevels[$accessLevel])) {
			$sendto->reply(
				"<highlight>{$accessLevel}<end> is not a valid access level. ".
				"Please use the short form like 'admin', 'mod' or 'rl'."
			);
			return;
		}
		$senderAL = $this->accessManager->getAccessLevelForCharacter($sender);
		$senderHasHigherAL = $this->accessManager->compareAccessLevels($senderAL, $accessLevel) > 0;
		if ($senderAL !== 'superadmin' && !$senderHasHigherAL) {
			$sendto->reply('You can only manage access levels below your own.');
			return;
		}
		if (!isset($ranks[$rank])) {
			$sendto->reply("{$guild->governing_form->value} doesn't have a rank #{$rank}.");
			return;
		}
		$currentEAL = $this->getEffectiveAccessLevel($rank);
		if ($this->accessManager->compareAccessLevels($accessLevel, $currentEAL) < 0) {
			$sendto->reply('You cannot assign declining access levels.');
			return;
		}
		$alName = $this->accessManager->getDisplayName($accessLevel);
		$rankName = $ranks[$rank];

		$rankMapping = new OrgRankMapping(
			access_level: $accessLevel,
			min_rank: $rank,
		);

		/** @var ?OrgRankMapping */
		$alEntry = $this->db->table(OrgRankMapping::getTable())
			->where('access_level', $rankMapping->access_level)
			->asObj(OrgRankMapping::class)
			->first();

		/** @var ?OrgRankMapping */
		$rankEntry = $this->db->table(OrgRankMapping::getTable())
			->where('min_rank', $rankMapping->min_rank)
			->asObj(OrgRankMapping::class)
			->first();
		if (isset($alEntry, $rankEntry)) {
			$sendto->reply("You have already assigned rank mapping for both {$alName} and {$rankName}.");
			return;
		}
		if (isset($alEntry)) {
			$this->db->update($rankMapping, 'access_level');
		} elseif (isset($rankEntry)) {
			$this->db->update($rankMapping, 'min_rank');
		} else {
			$this->db->insert($rankMapping);
		}
		$sendto->reply("Every <highlight>{$rankName}<end> or higher will now be mapped to <highlight>{$alName}<end>.");
	}

	/** Remove the special rights for an org rank */
	#[NCA\HandlesCommand('maprank')]
	#[NCA\Help\Group('org-ranks')]
	public function maprankDelCommand(CmdContext $context, PRemove $action, int $rankId): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply('The bot must be in an org.');
			return;
		}
		$org = $this->guildManager->byId($this->config->orgId??0, null, false);
		$this->delRankMapping($org, $rankId, $context->char->name, $context);
	}

	public function delRankMapping(?Guild $guild, int $rank, string $sender, CmdContext $context): void {
		if (!isset($guild)) {
			$context->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $guild->governing_form->getOrgRanks();
		if (!isset($ranks[$rank])) {
			$context->reply("{$guild->governing_form->value} doesn't have a rank #{$rank}.");
			return;
		}

		/** @var ?OrgRankMapping */
		$oldEntry = $this->db->table(OrgRankMapping::getTable())
			->where('min_rank', $rank)
			->asObj(OrgRankMapping::class)
			->first();
		if (!isset($oldEntry)) {
			$context->reply("You haven't defined any access level for <highlight>{$ranks[$rank]}<end>.");
			return;
		}
		$senderAL = $this->accessManager->getAccessLevelForCharacter($sender);
		$senderHasHigherAL = $this->accessManager->compareAccessLevels($senderAL, $oldEntry->access_level) > 0;
		if ($senderAL !== 'superadmin' && !$senderHasHigherAL) {
			$context->reply('You can only manage access levels below your own.');
			return;
		}
		$this->db->table(OrgRankMapping::getTable())
			->where('min_rank', $rank)
			->delete();
		$context->reply(
			"The access level mapping <highlight>{$ranks[$rank]}<end> to ".
			'<highlight>' . $this->accessManager->getDisplayName($oldEntry->access_level) . '<end> '.
			'was deleted successfully.'
		);
	}

	/** Get a list of all your org's ranks */
	#[NCA\HandlesCommand('ranks')]
	#[NCA\Help\Group('org-ranks')]
	public function ranksCommand(CmdContext $context): void {
		if (!$this->guildController->isGuildBot()) {
			$context->reply('The bot must be in an org.');
			return;
		}
		$org = $this->guildManager->byId($this->config->orgId??0, null, false);
		$this->displayGuildRanks($org, $context);
	}

	public function displayGuildRanks(?Guild $guild, CommandReply $sendto): void {
		if (!isset($guild)) {
			$sendto->reply("This org's governing form cannot be determined.");
			return;
		}
		$ranks = $guild->governing_form->getOrgRanks();
		$blob = "<header2>Org ranks of {$guild->governing_form->value}<end>\n";
		foreach ($ranks as $id => $name) {
			$blob .= "<tab>{$id}: <highlight>{$name}<end>\n";
		}
		$msg = Text::makeBlob(
			"Ranks of {$guild->governing_form->value} (" . count($ranks) . ')',
			$blob,
			$guild->governing_form->value,
		);
		$sendto->reply($msg);
	}
}

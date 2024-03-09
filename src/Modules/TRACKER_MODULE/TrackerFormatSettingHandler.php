<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\{Attributes as NCA, SettingHandler};

/**
 * Class to represent a setting with a tracker format value for NadyBot
 */
#[NCA\SettingHandler("tracker_format")]
class TrackerFormatSettingHandler extends SettingHandler {
	#[NCA\Inject]
	private TrackerController $trackerController;

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		$player = $this->getDummyPlayer();
		return $this->trackerController->getLogMessage($player, "Nady", $this->row->value ?? "");
	}

	/** Describe the valid values for this setting */
	public function getDescription(): string {
		$msg = "For this setting you can set any text using some pre-defined keywords\n".
			"that will be replaced with the actual data from the player:\n\n".
			"<tab><highlight>{faction}<end>: The faction in lower case: clan, neutral, omni\n".
			"<tab><highlight>{Faction}<end>: The faction, first char uppercase: Clan, Neutral, Omni\n".
			"<tab><highlight>{FACTION}<end>: The faction in upper case: CLAN, NEUTRAL, OMNI\n".
			"<tab><highlight>{name}<end>: The name of the character\n".
			"<tab><highlight>{profession}<end>: The profession of the character\n".
			"<tab><highlight>{prof}<end>: The short profession of the character\n".
			"<tab><highlight>{level}<end>: The level and alien level in color: <highlight>220<end>/<green>30<end>\n".
			"<tab><highlight>{org}<end>: Name of the organization, or &lt;no org&gt; if none\n".
			"<tab><highlight>{org_rank}<end>: The rank in the organization, or &lt;no rank&gt; if none\n".
			"<tab><highlight>{breed}<end>: The breed of the character\n".
			"<tab><highlight>{gender}<end>: The character's gender in lowercase\n".
			"<tab><highlight>{Gender}<end>: The character's gender, first letter uppercase\n".
			"<tab><highlight>{tl}<end>: The character's title level\n\n".
			"You can change it manually with the command\n\n".
			"/tell <myname> settings save {$this->row->name} &lt;new format&gt;\n\n".
			"Or you can choose from one of the predefined options\n\n";
		return $msg;
	}

	/** Get all options for this setting or null if no options are available */
	public function getOptions(): ?string {
		if (strlen($this->row->options??'')) {
			$options = explode(";", $this->row->options??"");
		}
		if (empty($options)) {
			return null;
		}
		$msg = "<header2>Predefined Options<end>\n";

		$player = $this->getDummyPlayer();
		foreach ($options as $example) {
			$selectLink = $this->text->makeChatcmd(
				"select",
				"/tell <myname> settings save {$this->row->name} {$example}",
			);
			$msg .= "<tab>".
				$this->trackerController->getLogMessage($player, "Nady", $example).
				" [{$selectLink}]\n".
				"<tab>Code: <highlight>" . htmlentities($example). "<end>\n\n";
		}
		return $msg;
	}

	private function getDummyPlayer(): Player {
		$player = new Player();
		$player->ai_level = 30;
		$player->level = 220;
		$player->name = "Nady";
		$player->guild = "Team Rainbow";
		$player->guild_id = 123;
		$player->gender = "Female";
		$player->faction = "Clan";
		$player->profession = "Bureaucrat";
		$player->breed = "Nanomage";
		return $player;
	}
}

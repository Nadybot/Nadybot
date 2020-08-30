<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class Activity extends JSONDataModel {
	public const ACTIVITY_GAME = 0;
	public const ACTIVITY_STREAMING = 1;
	public const ACTIVITY_LISTENING = 2;
	public const ACTIVITY_CUSTOM = 4;

	/** the activity's name */
	public string $name = "Anarchy Online";
	public int $type = self::ACTIVITY_GAME;
	/** stream url, is validated when type is ACTIVITY_STREAMING */
	public ?string $url;
	/** unix timestamp of when the activity was added to the user's session */
	public int $created_at;
	/** unix timestamps for start and/or end of the game */
	public ?object $timestamps;
	public ?string $application_id;
	/** what the player is currently doing */
	public ?string $details;
	/** the user's current party status */
	public ?string $state;
	/** the emoji used for a custom status */
	public ?Emoji $emoji;
	/** information for the current party of the player */
	public ?object $party;
	/** images for the presence and their hover texts */
	public ?object $assets;
	/** secrets for Rich Presence joining and spectating */
	public ?object $secrets;
	/** whether or not the activity is an instanced game session */
	public ?bool $instance;
	/** activity flags ORd together, describes what the payload includes */
	public ?int $flags;

	public function __construct() {
		$this->created_at = time();
	}
}

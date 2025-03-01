<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Nadybot\Core\Attributes\Hydrator\CastToStdClass;
use stdClass;
use Stringable;

class Activity implements Stringable {
	use ReducedStringableTrait;

	public const ACTIVITY_GAME = 0;
	public const ACTIVITY_STREAMING = 1;
	public const ACTIVITY_LISTENING = 2;
	public const ACTIVITY_WATCHING = 3;
	public const ACTIVITY_CUSTOM = 4;
	public const ACTIVITY_COMPETING = 5;

	/** unix timestamp of when the activity was added to the user's session */
	public int $created_at;

	/**
	 * @param ?string   $url            stream url, is validated when type is ACTIVITY_STREAMING
	 * @param ?stdClass $timestamps     unix timestamps for start and/or end of the game
	 * @param ?string   $application_id the application id
	 * @param ?string   $details        what the player is currently doing
	 * @param ?string   $state          the user's current party status
	 * @param ?Emoji    $emoji          the emoji used for a custom status
	 * @param ?stdClass $party          information for the current party of the player
	 * @param ?stdClass $assets         images for the presence and their hover texts
	 * @param ?stdClass $secrets        secrets for Rich Presence joining and spectating
	 * @param ?bool     $instance       whether or not the activity is an instanced game session
	 * @param ?int      $flags          activity flags ORd together, describes what the payload includes
	 * @param ?int      $created_at     unix timestamp of when the activity was added to the user's session
	 * @param string    $name           the activity's name
	 * @param int       $type           the activity's type
	 */
	public function __construct(
		public ?string $url=null,
		#[CastToStdClass] public ?stdClass $timestamps=null,
		public ?string $application_id=null,
		public ?string $details=null,
		public ?string $state=null,
		public ?Emoji $emoji=null,
		#[CastToStdClass] public ?stdClass $party=null,
		#[CastToStdClass] public ?stdClass $assets=null,
		#[CastToStdClass] public ?stdClass $secrets=null,
		public ?bool $instance=null,
		public ?int $flags=null,
		?int $created_at=null,
		public string $name='Anarchy Online',
		public int $type=self::ACTIVITY_GAME,
	) {
		$this->created_at = $created_at ?? time();
	}
}

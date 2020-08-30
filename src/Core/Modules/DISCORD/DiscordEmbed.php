<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use DateTime;
use Nadybot\Core\JSONDataModel;

class DiscordEmbed extends JSONDataModel {
	public ?string $title = null;
	public ?string $type = "rich";
	public ?string $description = null;
	public ?string $url = null;
	public ?DateTime $timestamp = null;
	public ?int $color = null;
	public ?object $footer = null;
	public ?object $image = null;
	public ?object $thumbnail = null;
	public ?object $video = null;
	public ?object $provider = null;
	public ?object $author = null;
	public ?array $fields = null;
}

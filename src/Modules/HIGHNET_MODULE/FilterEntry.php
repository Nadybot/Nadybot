<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use Nadybot\Core\DBRow;

class FilterEntry extends DBRow {
	public int $id;
	public string $creator;
	public ?string $sender_name=null;
	public ?int $sender_uid=null;
	public ?string $bot_name=null;
	public ?int $bot_uid=null;
	public ?string $channel=null;
	public ?int $dimension=null;
	public ?int $expires=null;

	public function matches(Message $message): bool {
		if (
			isset($this->sender_uid, $message->sender_uid)
			&& $message->sender_uid !== $this->sender_uid
		) {
			return false;
		}
		if (
			(!isset($this->sender_uid) || !isset($message->sender_uid))
			&& isset($this->sender_name)
			&& $message->sender_name !== $this->sender_name
		) {
			return false;
		}
		if (
			isset($this->bot_uid, $message->bot_uid)
			&& $message->bot_uid !== $this->bot_uid
		) {
			return false;
		}
		if (
			(!isset($this->bot_uid) || !isset($message->bot_uid))
			&& isset($this->bot_name, $message->bot_name)
			&& $message->bot_name !== $this->bot_name
		) {
			return false;
		}
		if (
			isset($this->dimension, $message->dimension)
			&& $message->dimension !== $this->dimension
		) {
			return false;
		}
		if (
			isset($this->channel, $message->channel)
			&& strtolower($message->channel) !== strtolower($this->channel)
		) {
			return false;
		}
		if (isset($this->expires) && time() > $this->expires) {
			return false;
		}
		return true;
	}
}

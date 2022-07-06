<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\ParamClass\{PCharacter, PQuantity, PRemove};
use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	DB,
	ModuleInstance,
	Nadybot,
	Text,
	UserStateEvent,
	Util,
};
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "wish",
		accessLevel: "guild",
		description: "Manage your wishlist",
	),
	NCA\DefineCommand(
		command: "wish deny",
		accessLevel: "all",
		description: "Deny someone's wish",
	),
]
class WishlistController extends ModuleInstance {
	public const DB_TABLE = "wishlist";
	public const DB_TABLE_FULFILMENT = "wishlist_fulfilment";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Event(
		name: "connect",
		description: "Put characters someone wished from to the buddylist"
	)]
	public function addPeopleOnWishlistToBuddylist(): Generator {
		$fromChars = $this->getActiveFroms();
		foreach ($fromChars as $name) {
			yield $this->buddylistManager->addAsync($name, "wishlist");
		}
	}

	#[NCA\Event(
		name: "logon",
		description: "Inform people that someone wishes an item from them"
	)]
	public function sendWishlistOnLogon(UserStateEvent $event): void {
		if (!$this->chatBot->isReady() || !is_string($event->sender)) {
			return;
		}
		$wishlistGrouped = $this->getOthersNeeds($event->sender);
		if ($wishlistGrouped->isEmpty()) {
			return;
		}
		[$numItems, $blob] = $this->renderCheckWishlist($wishlistGrouped, $event->sender);
		$msg = $this->text->makeBlob(
			"People are wishing items from you ({$numItems})",
			$blob
		);
		$this->chatBot->sendMassTell($msg, $event->sender);
	}

	/** Show your wishlist */
	#[NCA\HandlesCommand("wish")]
	public function showWishlistCommand(CmdContext $context): void {
		$mainChar = $this->altsController->getMainOf($context->char->name);
		$alts = $this->altsController->getAltsOf($mainChar);
		$sendersWishlist = $this->db->table(self::DB_TABLE)
			->where("created_by", $context->char->name)
			->orderBy("created_on")
			->asObj(Wish::class);
		$altsWishlist = $this->db->table(self::DB_TABLE)
			->whereIn("created_by", array_diff([$mainChar, ...$alts], [$context->char->name]))
			->orderBy("created_by")
			->orderBy("created_on")
			->asObj(Wish::class);
		$wishlist = $sendersWishlist->concat($altsWishlist);
		$wishlistGrouped = $this->addFulfilments($wishlist)
			->filter(function (Wish $w): bool {
				return $w->getRemaining() > 0;
			})->groupBy("created_by");
		if ($wishlistGrouped->isEmpty()) {
			$context->reply("Your wishlist is empty.");
			return;
		}
		$charGroups = [];
		$numItems = 0;
		foreach ($wishlistGrouped as $char => $wishlist) {
			/** @var Collection<Wish> $wishlist */
			$lines = [];
			$lines []= "<header2>{$char}<end>";
			foreach ($wishlist as $wish) {
				$numItems++;
				$line = "<tab>";
				if ($wish->amount > 1) {
					$remaining = $wish->getRemaining();
					if ($remaining !== $wish->amount) {
						$line .= "<highlight>{$remaining}x<end>/{$wish->amount}x ";
					} else {
						$line .= "<highlight>{$remaining}x<end> ";
					}
				}
				$line = "{$line}{$wish->item}";
				if (isset($wish->from)) {
					$line .= " (from {$wish->from})";
				}
				$delLink = $this->text->makeChatcmd("del", "/tell <myname> wish rem {$wish->id}");
				$line .= " [{$delLink}]";
				$lines []= $line;
				foreach ($wish->fulfilments as $fulfilment) {
					$delLink = $this->text->makeChatcmd("del", "/tell <myname> wish rem fulfilment {$fulfilment->id}");
					$lines []= "<tab><tab>{$fulfilment->amount} by {$fulfilment->fulfilled_by} on ".
						$this->util->date($fulfilment->fulfilled_on).
						" [{$delLink}]";
				}
			}
			$charGroups []= join("\n", $lines);
		}
		$blob = $this->text->makeBlob(
			"Your wishlist ({$numItems})",
			join("\n\n", $charGroups)
		);
		$context->reply($blob);
	}

	/**
	 * Get a list of items other people need that they requested from one
	 * of the given chars
	 *
	 * @return Collection<string,Collection<Wish>>
	 */
	public function getOthersNeeds(string ...$chars): Collection {
		$wishlist = $this->db->table(self::DB_TABLE)
			->whereIn("from", $chars)
			->orderBy("created_by")
			->orderBy("created_on")
			->asObj(Wish::class);

		/** @var Collection<string,Collection<Wish>> */
		$wishlistGrouped = $this->addFulfilments($wishlist)
			->filter(function (Wish $w): bool {
				return $w->getRemaining() > 0;
			})->groupBy("created_by");
		return $wishlistGrouped;
	}

	/** Check if anyone requested any items from you */
	#[NCA\HandlesCommand("wish")]
	public function checkOthersWishlistCommand(
		CmdContext $context,
		#[NCA\Str("check")] string $action,
	): void {
		$mainChar = $this->altsController->getMainOf($context->char->name);
		$alts = $this->altsController->getAltsOf($mainChar);
		$allChars = [$mainChar, ...$alts];
		$wishlistGrouped = $this->getOthersNeeds(...$allChars);
		if ($wishlistGrouped->isEmpty()) {
			$context->reply("No one is wishing anything from you.");
			return;
		}
		[$numItems, $blob] = $this->renderCheckWishlist($wishlistGrouped, ...$allChars);
		$msg = $this->text->makeBlob(
			"Others' wishlists ({$numItems})",
			$blob
		);
		$context->reply($msg);
	}

	/**
	 * Add fulfilments to wishes
	 *
	 * @param Collection<Wish> $wishes
	 *
	 * @return Collection<Wish>
	 */
	public function addFulfilments(Collection $wishes): Collection {
		$enriched = clone $wishes;
		$ids = $wishes->pluck("id");
		$this->db->table(self::DB_TABLE_FULFILMENT)
			->whereIn('wish_id', $ids->toArray())
			->orderBy("fulfilled_on")
			->asObj(WishFulfilment::class)
			->each(function (WishFulfilment $f) use ($enriched): void {
				/** @var Wish */
				$wish = $enriched->firstWhere("id", $f->wish_id);
				$wish->fulfilments->push($f);
			});
		return $enriched;
	}

	/** Add an item to your wishlist */
	#[NCA\HandlesCommand("wish")]
	public function addFromSomeoneToWishlistCommand(
		CmdContext $context,
		#[NCA\Str("from")] string $action,
		PCharacter $character,
		?PQuantity $amount,
		string $item,
	): Generator {
		$uid = yield $this->chatBot->getUid2($character());
		if ($uid === null) {
			$context->reply("The character <highlight>{$character}<end> does not exist.");
			return;
		}
		$fromChars = $this->getActiveFroms();
		$entry = new Wish();
		$entry->created_by = $context->char->name;
		$entry->from = $character();
		$entry->item = $item;
		if (isset($amount)) {
			$entry->amount = $amount();
		}
		$entry->id = $this->db->insert(self::DB_TABLE, $entry);
		$context->reply("Item added to your wishlist as #{$entry->id}.");
		if (!in_array($entry->from, $fromChars)) {
			yield $this->buddylistManager->addAsync($entry->from, "wishlist");
		}
	}

	/** Add an item to your wishlist */
	#[NCA\HandlesCommand("wish")]
	public function addToWishlistCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		?PQuantity $amount,
		string $item,
	): void {
		$entry = new Wish();
		$entry->created_by = $context->char->name;
		$entry->item = $item;
		if (isset($amount)) {
			$entry->amount = $amount();
		}
		$entry->id = $this->db->insert(self::DB_TABLE, $entry);
		$context->reply("Item added to your wishlist as #{$entry->id}.");
	}

	/** Clear your, and optionally all your alts', wishlist - including fulfilled wishes */
	#[NCA\HandlesCommand("wish")]
	public function clearAllWishlistCommand(
		CmdContext $context,
		#[NCA\Str("clear")] string $action,
		#[NCA\Str("all")] ?string $all,
	): Generator {
		$names = [$context->char->name];
		if (isset($all)) {
			$mainChar = $this->altsController->getMainOf($context->char->name);
			$alts = $this->altsController->getAltsOf($mainChar);
			$names = [$mainChar, ...$alts];
		}
		$ids = $this->db->table(self::DB_TABLE)
			->whereIn("created_by", $names)
			->pluckInts("id")
			->toArray();
		if (count($ids) === 0) {
			$context->reply("Your wishlist is empty.");
			return;
		}
		$oldFrom = $this->getActiveFroms();
		yield $this->db->awaitBeginTransaction();
		try {
			$this->db->table(self::DB_TABLE_FULFILMENT)
				->whereIn("wish_id", [$ids])
				->delete();
			$numDeleted = $this->db->table(self::DB_TABLE)
				->whereIn("id", [$ids])
				->delete();
		} catch (Throwable) {
			$this->db->rollback();
			$context->reply("An unknown error occurred when cleaning up your wishlist.");
			return;
		}
		$this->db->commit();
		$context->reply(
			"Removed <highlight>{$numDeleted} ".
			$this->text->pluralize("item", $numDeleted) . "<end> ".
			"from your wishlist."
		);
		$newFrom = $this->getActiveFroms();
		$toDelete = array_diff($oldFrom, $newFrom);
		foreach ($toDelete as $char) {
			$this->buddylistManager->remove($char, "wishlist");
		}
	}

	/** Remove an item from your or one of your alt's wishlist */
	#[NCA\HandlesCommand("wish")]
	public function removeFromWishlistCommand(
		CmdContext $context,
		PRemove $action,
		int $id,
	): Generator {
		$mainChar = $this->altsController->getMainOf($context->char->name);
		$alts = $this->altsController->getAltsOf($mainChar);

		/** @var ?Wish */
		$entry = $this->db->table(self::DB_TABLE)
			->whereIn("created_by", [$mainChar, ...$alts])
			->where("id", $id)
			->asObj(Wish::class)
			->first();
		if (!isset($entry)) {
			$context->reply("No item #{$id} on your wishlist.");
			return;
		}
		$oldFrom = $this->getActiveFroms();
		yield $this->db->awaitBeginTransaction();
		try {
			$this->db->table(self::DB_TABLE_FULFILMENT)
				->where("wish_id", $id)
				->delete();
			$this->db->table(self::DB_TABLE)->delete($id);
		} catch (Throwable) {
			$this->db->rollback();
			$context->reply("An unknown error occurred while removing the item from your wishlist.");
			return;
		}
		$this->db->commit();
		$context->reply(
			"Removed <highlight>{$entry->amount}x {$entry->item}<end> ".
			"from your wishlist."
		);
		$newFrom = $this->getActiveFroms();
		$toDelete = array_diff($oldFrom, $newFrom);
		foreach ($toDelete as $char) {
			$this->buddylistManager->remove($char, "wishlist");
		}
	}

	/** Remove a fulfilment that you did or from any of your alts' wishlist */
	#[NCA\HandlesCommand("wish")]
	public function removeFulfilmentCommand(
		CmdContext $context,
		PRemove $action,
		#[NCA\Str("fulfilment", "fulfillment", "fullfilment", "fullfillment")] string $subAction,
		int $fulfilmentId,
	): Generator {
		$mainChar = $this->altsController->getMainOf($context->char->name);
		$alts = $this->altsController->getAltsOf($mainChar);
		$allChars = [$mainChar, ...$alts];

		/** @var ?WishFulfilment */
		$fullfillment = $this->db->table(self::DB_TABLE_FULFILMENT)
			->where("id", $fulfilmentId)
			->asObj(WishFulfilment::class)
			->first();
		if (!isset($fullfillment)) {
			$context->reply("There is no fulfilment #{$fulfilmentId}.");
			return;
		}

		/** @var ?Wish */
		$entry = $this->db->table(self::DB_TABLE)
			->where("id", $fullfillment->wish_id)
			->asObj(Wish::class)
			->first();
		if (!isset($entry)) {
			$this->db->table(self::DB_TABLE_FULFILMENT)->delete($fulfilmentId);
			$context->reply("There is no fulfilment #{$fulfilmentId}.");
			return;
		}
		$canAccess = in_array($fullfillment->fulfilled_by, $allChars)
			|| in_array($entry->created_by, $allChars);
		if (!$canAccess) {
			$context->reply("You don't have the right to remove this fulfilment.");
			return;
		}
		$this->db->table(self::DB_TABLE_FULFILMENT)->delete($fulfilmentId);
		$context->reply(
			"Removed the fulfilment of <highlight>{$fullfillment->amount}x {$entry->item}<end> ".
			"from {$entry->created_by}'s wishlist."
		);
		if (isset($entry->from)) {
			yield $this->buddylistManager->addAsync($entry->from, "wishlist");
		}
	}

	/** Mark your or someone else's wish fulfilled */
	#[NCA\HandlesCommand("wish")]
	public function fulfillWishlistCommand(
		CmdContext $context,
		#[NCA\Str("fulfil", "fulfill", "fullfil", "fullfill")] string $action,
		?PQuantity $amount,
		int $id,
	): void {
		$mainChar = $this->altsController->getMainOf($context->char->name);
		$alts = $this->altsController->getAltsOf($mainChar);

		/** @var ?Wish */
		$entry = $this->db->table(self::DB_TABLE)
			->whereIn("created_by", [$mainChar, ...$alts])
			->where("id", $id)
			->asObj(Wish::class)
			->first()
			??
			$this->db->table(self::DB_TABLE)
			->whereIn("from", [$mainChar, ...$alts])
			->where("id", $id)
			->asObj(Wish::class)
			->first();
		if (!isset($entry)) {
			$context->reply("No item #{$id} on your wishlist or wished from you.");
			return;
		}
		$fulfilments = $this->db->table(self::DB_TABLE_FULFILMENT)
			->where("wish_id", $entry->id)
			->asObj(WishFulfilment::class);
		$numFulfilled = $fulfilments->sum(fn (WishFulfilment $f): int => $f->amount);
		if ($numFulfilled >= $entry->amount) {
			$context->reply("This wish has already been fulfilled.");
			return;
		}
		$fulfilment = new WishFulfilment();
		$fulfilment->amount = isset($amount) ? $amount() : ($entry->amount - $numFulfilled);
		$fulfilment->fulfilled_by = $context->char->name;
		$fulfilment->wish_id = $entry->id;
		$oldFrom = $this->getActiveFroms();
		$fulfilment->id = $this->db->insert(self::DB_TABLE_FULFILMENT, $fulfilment);
		$context->reply(
			"Marked <highlight>{$fulfilment->amount}x {$entry->item}<end> ".
			"as fulfilled on {$entry->created_by}'s wishlist."
		);
		$newFrom = $this->getActiveFroms();
		$toDelete = array_diff($oldFrom, $newFrom);
		foreach ($toDelete as $char) {
			$this->buddylistManager->remove($char, "wishlist");
		}
	}

	/** Deny someone's wish */
	#[NCA\HandlesCommand("wish deny")]
	public function denyWishCommand(
		CmdContext $context,
		#[NCA\Str("deny")] string $action,
		int $id,
	): Generator {
		$mainChar = $this->altsController->getMainOf($context->char->name);
		$alts = $this->altsController->getAltsOf($mainChar);

		/** @var ?Wish */
		$entry = $this->db->table(self::DB_TABLE)
			->whereIn("from", [$mainChar, ...$alts])
			->where("id", $id)
			->asObj(Wish::class)
			->first();
		if (!isset($entry)) {
			$context->reply("No item #{$id} wished from you.");
			return;
		}
		$oldFrom = $this->getActiveFroms();
		yield $this->db->awaitBeginTransaction();
		try {
			$this->db->table(self::DB_TABLE_FULFILMENT)
				->where("wish_id", $id)
				->delete();
			$this->db->table(self::DB_TABLE)->delete($id);
		} catch (Throwable) {
			$this->db->rollback();
			$context->reply("An unknown error occurred when denying the wish.");
			return;
		}
		$this->db->commit();
		$context->reply(
			"You denied {$entry->created_by}'s wish for {$entry->amount}x {$entry->item}."
		);
		$newFrom = $this->getActiveFroms();
		$toDelete = array_diff($oldFrom, $newFrom);
		foreach ($toDelete as $char) {
			$this->buddylistManager->remove($char, "wishlist");
		}
	}

	/** @return array{int,string} */
	private function renderCheckWishlist(Collection $wishlistGrouped, string ...$allChars): array {
		$charGroups = [];
		$numItems = 0;
		foreach ($wishlistGrouped as $char => $wishlist) {
			/** @var Collection<Wish> $wishlist */
			$lines = [];
			$lines []= "<header2>{$char}<end>";
			foreach ($wishlist as $wish) {
				$numItems++;
				$line = "<tab>";
				if ($wish->amount > 1) {
					$remaining = $wish->getRemaining();
					if ($remaining !== $wish->amount) {
						$line .= "<highlight>{$remaining}x<end>/{$wish->amount}x ";
					} else {
						$line .= "<highlight>{$remaining}x<end> ";
					}
				}
				$line = "{$line}{$wish->item}";
				$do1Link = $this->text->makeChatcmd("do 1", "/tell <myname> wish fulfil 1x {$wish->id}");
				$doAllLink = null;
				if ($wish->getRemaining() > 1) {
					$doAllLink = $this->text->makeChatcmd("do all", "/tell <myname> wish fulfil {$wish->id}");
				}
				$denyLink = $this->text->makeChatcmd("deny", "/tell <myname> wish deny {$wish->id}");
				$line .= " [{$do1Link}]";
				if (isset($doAllLink)) {
					$line .= " [{$doAllLink}]";
				}
				$line .= " [{$denyLink}]";
				$lines []= $line;
				foreach ($wish->fulfilments as $fulfilment) {
					$delLink = null;
					if (in_array($fulfilment->fulfilled_by, $allChars)) {
						$delLink = $this->text->makeChatcmd("del", "/tell <myname> wish rem fulfilment {$fulfilment->id}");
					}
					$line = "<tab><tab>{$fulfilment->amount} by {$fulfilment->fulfilled_by} on ".
						$this->util->date($fulfilment->fulfilled_on);
					if (isset($delLink)) {
						$line .= " [{$delLink}]";
					}
					$lines []= $line;
				}
			}
			$charGroups []= join("\n", $lines);
		}
		return [$numItems, join("\n\n", $charGroups)];
	}

	/** @return string[] */
	private function getActiveFroms(): array {
		$wishes = $this->db->table(self::DB_TABLE)
			->whereNotNull("from")
			->asObj(Wish::class);
		$this->db->table(self::DB_TABLE_FULFILMENT)
			->asObj(WishFulfilment::class)
			->each(function (WishFulfilment $f) use ($wishes): void {
				/** @var ?Wish */
				$wish = $wishes->firstWhere("id", $f->wish_id);
				if (isset($wish)) {
					$wish->fulfilments->push($f);
				}
			});
		$fromChars = $wishes->filter(function (Wish $w): bool {
			return $w->getRemaining() > 0;
		})->reduce(function (array $result, Wish $w): array {
			if (isset($w->from)) {
				$result[$w->from] = true;
			}
			return $result;
		}, []);

		/** @var string[] */
		$keys = array_keys($fromChars);
		return $keys;
	}
}

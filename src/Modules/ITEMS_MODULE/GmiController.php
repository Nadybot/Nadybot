<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\Attributes\HandlesCommand;
use Nadybot\Core\ParamClass\PItem;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Text,
	UserException,
	Util,
};
use Safe\Exceptions\JsonException;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "gmi",
		accessLevel: "guest",
		description: "Search GMI for an item",
	),
]
class GmiController extends ModuleInstance {
	public const EU_GMI_API = "https://gmi.eu.nadybot.org/v1.0";
	public const US_GMI_API = "https://gmi.us.nadybot.org/v1.0";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** GMI API to use */
	#[NCA\Setting\Text(options: [self::EU_GMI_API, self::US_GMI_API])]
	public string $gmiApi = self::EU_GMI_API;

	/**
	 * Contact the GMI API and return the parsed results
	 *
	 * @throws UserException on any  error
	 */
	public function getPricesFromGmi(AODBEntry $item): GmiResult {
		try {
			$httpClient = $this->builder->build();

			$response = $httpClient->request(
				new Request(rtrim($this->gmiApi, '/') . "/aoid/{$item->lowid}")
			);
			if ($response->getStatus() === 404) {
				throw new UserException("{$item->name} is not tradeable on GMI.");
			}
			if ($response->getStatus() !== 200) {
				throw new UserException(
					"The GMI API is encountered a temporary error. ".
					"Please try again later."
				);
			}
			$body = $response->getBody()->buffer();
			$mapper = new ObjectMapperUsingReflection();
			$json = json_decode($body, true);

			/** @var GmiResult */
			$gmiResult = $mapper->hydrateObject(GmiResult::class, $json);
		} catch (UserException $e) {
			throw $e;
		} catch (JsonException $e) {
			throw new UserException("The GMI API returned invalid data.");
		} catch (UnableToHydrateObject $e) {
			throw new UserException("The GMI API returned invalid data.");
		} catch (Throwable) {
			throw new UserException("Unknown error occurred contacting the GMI API.");
		}
		return $gmiResult;
	}

	/** Check prices on GMI for an item */
	#[HandlesCommand("gmi")]
	public function gmiIdCommand(CmdContext $context, int $itemId): void {
		$entry = $this->itemsController->findById($itemId);
		$this->gmiCommand($context, $entry);
	}

	/** Check prices on GMI for an item */
	#[HandlesCommand("gmi")]
	public function gmiItemCommand(CmdContext $context, PItem $item): void {
		$entry = $this->itemsController->findById($item->lowID);
		$this->gmiCommand($context, $entry, $item->ql);
	}

	/** Check prices on GMI for an item */
	#[HandlesCommand("gmi")]
	public function gmiSearchCommand(CmdContext $context, string $search): void {
		$matches = $this->itemsController->findItemsFromLocal($search, null);
		$perfectMatches = array_filter(
			$matches,
			function (ItemSearchResult $item) use ($search): bool {
				return strcasecmp($item->name, $search) === 0;
			}
		);
		if (count($perfectMatches) === 1) {
			$matches = [array_shift($perfectMatches)];
		} else {
			$usedIds = [];
			$matches = array_values(
				array_filter(
					$matches,
					function (ItemSearchResult $item) use (&$usedIds): bool {
						if ($item->flags & ItemFlag::NO_DROP) {
							return false;
						}
						if (isset($usedIds[$item->lowid])) {
							return false;
						}
						$usedIds[$item->lowid] = true;
						return true;
					}
				)
			);
		}
		if (count($matches) === 1) {
			$entry = $this->itemsController->findById($matches[0]->lowid);
			$this->gmiCommand($context, $entry);
			return;
		}
		$blob = "<header2>Items matching {$search}<end>\n";
		$numMatches = 0;
		foreach ($matches as $item) {
			$numMatches++;
			$useQL = $item->ql;
			if ($item->highql !== $item->lowql) {
				$useQL .= "-{$item->highql}";
			}
			$itemLink = $this->text->makeItem($item->lowid, $item->highid, $item->ql, $item->name);
			$gmiLink = $this->text->makeChatcmd("GMI", "/tell <myname> gmi {$item->lowid}");
			$blob .= "<tab>[{$gmiLink}] {$itemLink} (QL {$useQL})\n";
		}
		if ($numMatches === 0) {
			$context->reply("No yesdrop-items matched your search criteria.");
			return;
		}
		$msg = $this->text->makeBlob("Items matching your search ({$numMatches})", $blob);
		$context->reply($msg);
	}

	protected function gmiCommand(CmdContext $context, ?AODBEntry $item, ?int $ql=null): Generator {
		if (!isset($item)) {
			$context->reply("This item does not exist.");
			return;
		}
		if ($item->flags & ItemFlag::NO_DROP) {
			$context->reply("NODROP items cannot be traded via GMI.");
			return;
		}

		/** @var GmiResult */
		$gmiResult = yield $this->getPricesFromGmi($item);
		$message = $this->renderGmiResult($gmiResult, $item, $ql);
		$context->reply($message);
	}

	/** @return string[] */
	protected function renderGmiResult(GmiResult $gmi, AODBEntry $item, ?int $ql=null): array {
		if (!count($gmi->buyOrders) && !count($gmi->sellOrders)) {
			return ["There are no orders on GMI."];
		}
		$numBuy = count($gmi->buyOrders);
		$numSell = count($gmi->sellOrders);
		$buyCutString = "";
		if (count($gmi->buyOrders) > 10) {
			$buyCutString = " (top 10 only)";
			$gmi->buyOrders = array_slice($gmi->buyOrders, 0, 10);
		}
		$sellCutString = "";
		if (count($gmi->sellOrders) > 10) {
			$sellCutString = " (top 10 only)";
			$gmi->sellOrders = array_slice($gmi->sellOrders, 0, 10);
		}
		$orders = new Collection([...$gmi->buyOrders, ...$gmi->sellOrders]);
		$highestAmount = $orders->max("count");
		$highestPrice = $orders->max("price");
		$buyers = "<header2>Buy orders{$buyCutString}<end>";
		if (count($gmi->buyOrders)) {
			foreach ($gmi->buyOrders as $buyOrder) {
				$buyers .= "\n<tab>" . $this->renderBuyOrder($buyOrder, $item, $ql, $highestAmount, $highestPrice);
			}
		} else {
			$buyers .= "\n<tab>- none -";
		}
		$sellers = "<header2>Sell orders{$sellCutString}<end>";
		if (count($gmi->sellOrders)) {
			foreach ($gmi->sellOrders as $sellOrder) {
				$sellers .= "\n<tab>" . $this->renderSellOrder($sellOrder, $item, $highestAmount, $highestPrice);
			}
		} else {
			$sellers .= "\n<tab>- none -";
		}
		return (array)$this->text->makeBlob(
			sprintf(
				"GMI orders for %s (%d buy, %d sell)",
				$item->name,
				$numBuy,
				$numSell
			),
			$item->getLink($ql, $this->text->makeImage($item->icon)) . "\n\n" . $buyers . "\n\n" . $sellers
		);
	}

	protected function renderSellOrder(GmiSellOrder $order, AODBEntry $item, int $highestAmount, int $highestPrice): string {
		if ($item->lowql !== $item->highql) {
			return sprintf(
				"%sx QL %s for %s from %s  (ends in %s)",
				$this->text->alignNumber($order->count, strlen((string)$highestAmount)),
				$this->text->alignNumber($order->ql, 3),
				$this->text->alignNumber($order->price, strlen((string)$highestPrice), "highlight", true),
				$order->seller,
				$this->util->unixtimeToReadable($order->expiration),
			);
		}
		return sprintf(
			"%sx for %s from %s  (ends in %s)",
			$this->text->alignNumber($order->count, strlen((string)$highestAmount)),
			$this->text->alignNumber($order->price, strlen((string)$highestPrice), "highlight", true),
			$order->seller,
			$this->util->unixtimeToReadable($order->expiration),
		);
	}

	protected function renderBuyOrder(GmiBuyOrder $order, AODBEntry $item, ?int $ql, int $highestAmount, int $highestPrice): string {
		if ($item->lowql !== $item->highql) {
			$highlight = null;
			if (isset($ql) && ($order->minQl <= $ql) && ($order->maxQl >= $ql)) {
				$highlight = "green";
			}
			$ql = $this->text->alignNumber($order->minQl, 3, $highlight) . "-".
				$this->text->alignNumber($order->maxQl, 3, $highlight);
			if ($order->minQl === $order->maxQl) {
				$ql = "<black>000-<end>" . $this->text->alignNumber($order->maxQl, 3, $highlight);
			}
			return sprintf(
				"%sx QL %s for %s from %s  (ends in %s)",
				$this->text->alignNumber($order->count, strlen((string)$highestAmount)),
				$ql,
				$this->text->alignNumber($order->price, strlen((string)$highestPrice), "highlight", true),
				$order->buyer,
				$this->util->unixtimeToReadable($order->expiration),
			);
		}
		return sprintf(
			"%sx for %s from %s  (ends in %s)",
			$this->text->alignNumber($order->count, strlen((string)$highestAmount)),
			$this->text->alignNumber($order->price, strlen((string)$highestPrice), "highlight", true),
			$order->buyer,
			$this->util->unixtimeToReadable($order->expiration),
		);
	}
}

<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use function Amp\call;
use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use Amp\Promise;
use EventSauce\ObjectHydrator\ObjectMapperUsingReflection;

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

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "gmi",
		accessLevel: "guest",
		description: "Search GMI for an item",
	),
]
class GmiController extends ModuleInstance {
	public const GMI_API = "https://gmi.nadybot.org";

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
	#[NCA\Setting\Text(options: [self::GMI_API])]
	public string $gmiApi = self::GMI_API;

	/**
	 * Contact the GMI API and return the parsed results
	 *
	 * @return Promise<GmiResult>
	 */
	public function getPricesFromGmi(AODBEntry $item): Promise {
		return call(function () use ($item): Generator {
			$httpClient = $this->builder->buildDefault();

			/** @var Response */
			$response = yield $httpClient->request(
				new Request($this->gmiApi . "/aoid/{$item->lowid}")
			);
			if ($response->getStatus() === 404) {
				throw new UserException("{$item->name} is not tradeable on GMI.");
			}
			$body = yield $response->getBody()->buffer();
			$mapper = new ObjectMapperUsingReflection();
			$json = json_decode($body, true);

			/** @var GmiResult */
			$gmiResult = $mapper->hydrateObject(GmiResult::class, $json);
			return $gmiResult;
		});
	}

	/** Check prices on GMI for an item */
	#[HandlesCommand("gmi")]
	public function gmiIdCommand(CmdContext $context, int $itemId): Generator {
		$entry = $this->itemsController->findById($itemId);
		yield from $this->gmiCommand($context, $entry);
	}

	/** Check prices on GMI for an item */
	#[HandlesCommand("gmi")]
	public function gmiItemCommand(CmdContext $context, PItem $item): Generator {
		$entry = $this->itemsController->findById($item->lowID);
		yield from $this->gmiCommand($context, $entry);
	}

	/** Check prices on GMI for an item */
	#[HandlesCommand("gmi")]
	public function gmiSearchCommand(CmdContext $context, string $search): Generator {
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
			yield from $this->gmiCommand($context, $entry);
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

	protected function gmiCommand(CmdContext $context, ?AODBEntry $item): Generator {
		if (!isset($item)) {
			$context->reply("This item does not exist.");
			return;
		}
		if ($item->flags & ItemFlag::NO_DROP) {
			$context->reply("NODROP items cannot be traded via GMI.");
			return;
		}

		try {
			/** @var GmiResult */
			$gmiResult = yield $this->getPricesFromGmi($item);
		} catch (JsonException $e) {
			$context->reply("Invalid data received from the GMI API.");
			return;
		} catch (UserException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$context->reply("An unexpected error occurred while contacting the GMI API.");
			return;
		}
		$message = $this->renderGmiResult($gmiResult, $item);
		$context->reply($message);
	}

	/** @return string[] */
	protected function renderGmiResult(GmiResult $gmi, AODBEntry $item): array {
		if (!count($gmi->buyOrders) && !count($gmi->sellOrders)) {
			return ["There are no offers on GMI."];
		}
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
				$buyers .= "\n<tab>" . $this->renderBuyOrder($buyOrder, $item, $highestAmount, $highestPrice);
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
				"GMI orders for %s (%d)",
				$item->name,
				$orders->count(),
			),
			$item->getLink(null, $this->text->makeImage($item->icon)) . "\n\n" . $buyers . "\n\n" . $sellers
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

	protected function renderBuyOrder(GmiBuyOrder $order, AODBEntry $item, int $highestAmount, int $highestPrice): string {
		if ($item->lowql !== $item->highql) {
			$ql = $this->text->alignNumber($order->minQl, 3) . "-".
				$this->text->alignNumber($order->maxQl, 3);
			if ($order->minQl === $order->maxQl) {
				$ql = "<black>000-<end>" . $this->text->alignNumber($order->maxQl, 3);
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

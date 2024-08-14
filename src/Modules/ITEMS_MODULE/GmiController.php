<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use EventSauce\ObjectHydrator\{ObjectMapperUsingReflection, UnableToHydrateObject};
use Nadybot\Core\Types\ItemFlag;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Exceptions\UserException,
	ModuleInstance,
	ParamClass\PItem,
	Text,
	Types\AOIcon,
	Types\AOItemSpec,
	Util,
};
use Safe\Exceptions\JsonException;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'gmi',
		accessLevel: 'guest',
		description: 'Search GMI for an item',
	),
]
class GmiController extends ModuleInstance {
	public const EU_GMI_API = 'https://gmi.eu.nadybot.org/v1.0';
	public const US_GMI_API = 'https://gmi.us.nadybot.org/v1.0';

	/** GMI API to use */
	#[NCA\Setting\Text(options: [self::EU_GMI_API, self::US_GMI_API])]
	public string $gmiApi = self::EU_GMI_API;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private ItemsController $itemsController;

	/**
	 * Contact the GMI API and return the parsed results
	 *
	 * @throws UserException on any  error
	 */
	public function getPricesFromGmi(AOItemSpec $item): GmiResult {
		try {
			$httpClient = $this->builder->build();

			$response = $httpClient->request(
				new Request(rtrim($this->gmiApi, '/') . "/aoid/{$item->getLowID()}")
			);
			if ($response->getStatus() === 404) {
				throw new UserException("{$item->getName()} is not tradeable on GMI.");
			}
			if ($response->getStatus() !== 200) {
				throw new UserException(
					'The GMI API is encountered a temporary error. '.
					'Please try again later.'
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
			throw new UserException('The GMI API returned invalid data.', 0, $e);
		} catch (UnableToHydrateObject $e) {
			throw new UserException('The GMI API returned invalid data.', 0, $e);
		} catch (Throwable $e) {
			throw new UserException('Unknown error occurred contacting the GMI API.', 0, $e);
		}
		return $gmiResult;
	}

	/** Check prices on GMI for an item */
	#[NCA\HandlesCommand('gmi')]
	public function gmiIdCommand(CmdContext $context, int $itemId): void {
		$entry = $this->itemsController->findById($itemId);
		$this->gmiCommand($context, $entry);
	}

	/** Check prices on GMI for an item */
	#[NCA\HandlesCommand('gmi')]
	public function gmiItemCommand(CmdContext $context, PItem $item): void {
		$entry = $this->itemsController->findById($item->lowID);
		$this->gmiCommand($context, $entry, $item->ql);
	}

	/** Check prices on GMI for an item */
	#[NCA\HandlesCommand('gmi')]
	public function gmiSearchCommand(CmdContext $context, string $search): void {
		$matches = $this->itemsController->findItemsFromLocal($search, null);
		$perfectMatches = array_filter(
			$matches,
			static function (ItemSearchResult $item) use ($search): bool {
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
					static function (ItemSearchResult $item) use (&$usedIds): bool {
						if ($item->flags->has(ItemFlag::NO_DROP)) {
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
			$itemLink = $item->getLink();
			$gmiLink = Text::makeChatcmd('GMI', "/tell <myname> gmi {$item->lowid}");
			$blob .= "<tab>[{$gmiLink}] {$itemLink} (QL {$useQL})\n";
		}
		if ($numMatches === 0) {
			$context->reply('No yesdrop-items matched your search criteria.');
			return;
		}
		$msg = $this->text->makeBlob("Items matching your search ({$numMatches})", $blob);
		$context->reply($msg);
	}

	protected function gmiCommand(CmdContext $context, ?AODBEntry $item, ?int $ql=null): void {
		if (!isset($item)) {
			$context->reply('This item does not exist.');
			return;
		}
		if ($item->flags->has(ItemFlag::NO_DROP)) {
			$context->reply('NODROP items cannot be traded via GMI.');
			return;
		}

		$gmiResult = $this->getPricesFromGmi($item);
		$message = $this->renderGmiResult($gmiResult, $item, $ql);
		$context->reply($message);
	}

	/** @return list<string> */
	protected function renderGmiResult(GmiResult $gmi, AOItemSpec&AOIcon $item, ?int $ql=null): array {
		if (!count($gmi->buyOrders) && !count($gmi->sellOrders)) {
			return ['There are no orders on GMI.'];
		}
		$numBuy = count($gmi->buyOrders);
		$numSell = count($gmi->sellOrders);
		$buyCutString = '';
		if (count($gmi->buyOrders) > 10) {
			$buyCutString = ' (top 10 only)';
			$gmi->buyOrders = array_slice($gmi->buyOrders, 0, 10);
		}
		$sellCutString = '';
		if (count($gmi->sellOrders) > 10) {
			$sellCutString = ' (top 10 only)';
			$gmi->sellOrders = array_slice($gmi->sellOrders, 0, 10);
		}
		$orders = collect([...$gmi->buyOrders, ...$gmi->sellOrders]);
		$highestAmount = $orders->max(static fn (GmiBuyOrder|GmiSellOrder $item): int => $item->count);
		$highestPrice = $orders->max('price');
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
				'GMI orders for %s (%d buy, %d sell)',
				$item->getName(),
				$numBuy,
				$numSell
			),
			$item->getLink($ql, $item->getIcon()) . "\n\n" . $buyers . "\n\n" . $sellers
		);
	}

	protected function renderSellOrder(GmiSellOrder $order, AOItemSpec $item, int $highestAmount, int $highestPrice): string {
		if ($item->getLowQL() !== $item->getHighQL()) {
			return sprintf(
				'%sx QL %s for %s from %s  (ends in %s)',
				Text::alignNumber($order->count, strlen((string)$highestAmount)),
				Text::alignNumber($order->ql, 3),
				Text::alignNumber($order->price, strlen((string)$highestPrice), 'highlight', true),
				$order->seller,
				Util::unixtimeToReadable($order->expiration),
			);
		}
		return sprintf(
			'%sx for %s from %s  (ends in %s)',
			Text::alignNumber($order->count, strlen((string)$highestAmount)),
			Text::alignNumber($order->price, strlen((string)$highestPrice), 'highlight', true),
			$order->seller,
			Util::unixtimeToReadable($order->expiration),
		);
	}

	protected function renderBuyOrder(GmiBuyOrder $order, AOItemSpec $item, ?int $ql, int $highestAmount, int $highestPrice): string {
		if ($item->getLowQL() !== $item->getHighQL()) {
			$highlight = null;
			if (isset($ql) && ($order->minQl <= $ql) && ($order->maxQl >= $ql)) {
				$highlight = 'green';
			}
			$ql = Text::alignNumber($order->minQl, 3, $highlight) . '-'.
				Text::alignNumber($order->maxQl, 3, $highlight);
			if ($order->minQl === $order->maxQl) {
				$ql = '<black>000-<end>' . Text::alignNumber($order->maxQl, 3, $highlight);
			}
			return sprintf(
				'%sx QL %s for %s from %s  (ends in %s)',
				Text::alignNumber($order->count, strlen((string)$highestAmount)),
				$ql,
				Text::alignNumber($order->price, strlen((string)$highestPrice), 'highlight', true),
				$order->buyer,
				Util::unixtimeToReadable($order->expiration),
			);
		}
		return sprintf(
			'%sx for %s from %s  (ends in %s)',
			Text::alignNumber($order->count, strlen((string)$highestAmount)),
			Text::alignNumber($order->price, strlen((string)$highestPrice), 'highlight', true),
			$order->buyer,
			Util::unixtimeToReadable($order->expiration),
		);
	}
}

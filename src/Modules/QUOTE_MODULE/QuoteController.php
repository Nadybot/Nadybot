<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE;

use function Safe\preg_split;

use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	DB,
	ModuleInstance,
	Nadybot,
	ParamClass\PRemove,
	Text,
	Util,
};

/**
 * @author Lucier (RK1)
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'quote',
		accessLevel: 'guest',
		description: 'Add/Remove/View Quotes',
	)
]
class QuoteController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	/** Add a quote */
	#[NCA\HandlesCommand('quote')]
	public function quoteAddCommand(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		string $quote
	): void {
		$quoteMsg = trim($quote);
		$row = $this->db->table(Quote::getTable())
			->whereIlike('msg', $quoteMsg)
			->asObj(Quote::class)->first();
		if (isset($row)) {
			$msg = "This quote has already been added as quote <highlight>{$row->id}<end>.";
			$context->reply($msg);
			return;
		}
		if (strlen($quoteMsg) > 1_000) {
			$msg = 'This quote is too long.';
			$context->reply($msg);
			return;
		}
		$poster = $context->char->name;

		$this->db->insert($quote = new Quote(
			poster: $poster,
			dt: time(),
			msg: $quoteMsg,
		));
		$msg = "Quote <highlight>{$quote->id}<end> has been added.";
		$context->reply($msg);
	}

	/** Remove a quote */
	#[NCA\HandlesCommand('quote')]
	public function quoteRemoveCommand(
		CmdContext $context,
		PRemove $action,
		PUuid $id
	): void {
		$id = $id();

		/** @var ?Quote */
		$row = $this->db->table(Quote::getTable())
			->where('id', $id)
			->asObj(Quote::class)->first();

		if ($row === null) {
			$msg = 'Could not find this quote. Already deleted?';
			$context->reply($msg);
			return;
		}
		$poster = $row->poster;

		// only author or admin can delete.
		if (($poster === $context->char->name)
			|| $this->accessManager->checkAccess($context->char->name, 'moderator')
		) {
			$this->db->table(Quote::getTable())->delete($id);
			$msg = 'This quote has been deleted.';
		} else {
			$msg = "Only a moderator or {$poster} can delete this quote.";
		}
		$context->reply($msg);
	}

	/** Search for a quote of a specific author/victim/text */
	#[NCA\HandlesCommand('quote')]
	public function quoteSearchCommand(
		CmdContext $context,
		#[NCA\Str('search')] string $action,
		string $search
	): void {
		$searchParam = "%{$search}%";
		$msg = '';

		// Search for poster:
		$idList = $this->db->table(Quote::getTable())
			->whereIlike('poster', $searchParam)
			->asObj(Quote::class)
			->map(static function (Quote $quote): string {
				assert(isset($quote->id));
				return Text::makeChatcmd(
					(string)$quote->id,
					"/tell <myname> quote {$quote->id}"
				);
			})->toArray();
		if (count($idList)) {
			$msg  = "<header2>Quotes posted by \"{$search}\"<end>\n";
			$msg .= '<tab>' . implode(', ', $idList) . "\n\n";
		}

		// Search inside quotes:
		$idList = $this->db->table(Quote::getTable())
			->whereIlike('msg', $searchParam)
			->asObj(Quote::class)
			->map(static function (Quote $quote): string {
				assert(isset($quote->id));
				return Text::makeChatcmd(
					(string)$quote->id,
					"/tell <myname> quote {$quote->id}"
				);
			})->toArray();
		if (count($idList)) {
			$msg .= "<header2>Quotes that contain \"{$search}\"<end>\n";
			$msg .= '<tab>' . implode(', ', $idList);
		}

		if ($msg) {
			$msg = $this->text->makeBlob("Results for: '{$search}'", $msg);
		} else {
			$msg = 'Could not find any matches for this search.';
		}
		$context->reply($msg);
	}

	/** Show a given quote, optionally to the org or private channel */
	#[NCA\HandlesCommand('quote')]
	public function quoteShowCommand(
		CmdContext $context,
		#[NCA\StrChoice('org', 'priv')] ?string $channel,
		PUuid $id
	): void {
		$id = $id();
		$result = $this->getQuoteInfo($id);

		if ($result === null) {
			$msg = "No quote found with ID <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		$msg = $result;
		if (!isset($channel)) {
			$context->reply($msg);
			return;
		}
		if ($channel === 'priv') {
			$this->chatBot->sendPrivate($msg, true);
		} else {
			$this->chatBot->sendGuild($msg, true);
		}
	}

	/** Show a random quote */
	#[NCA\HandlesCommand('quote')]
	public function quoteShowRandomCommand(CmdContext $context): void {
		// choose a random quote to show
		$result = $this->getQuoteInfo(null);

		if ($result === null) {
			$msg = 'There are no quotes to show.';
		} else {
			$msg = $result;
		}
		$context->reply($msg);
	}

	/** @return ?list<string> */
	public function getQuoteInfo(null|string|\Stringable $id=null): ?array {
		$count = $this->db->table(Quote::getTable())->count();

		if ($count === 0) {
			return null;
		}

		if ($id === null) {
			$row = $this->db->table(Quote::getTable())
				->inRandomOrder()
				->limit(1)
				->asObj(Quote::class)->first();
		} else {
			$row = $this->db->table(Quote::getTable())
				->where('id', $id)
				->asObj(Quote::class)->first();
		}

		/** @var ?Quote $row */
		if (!isset($row)) {
			return null;
		}

		$poster = $row->poster;
		$quoteMsg = $row->msg;

		$msg = "ID: <highlight>{$row->id}<end> of {$count}\n";
		$msg .= "Poster: <highlight>{$poster}<end>\n";
		$msg .= 'Date: <highlight>' . Util::date($row->dt) . "<end>\n";
		$msg .= "Quote: <highlight>{$quoteMsg}<end>\n";
		$msg .= 'Action:';
		if ($this->config->general->orgName !== '') {
			$msg .= ' ['.
				Text::makeChatcmd('To orgchat', "/tell <myname> quote org {$row->id}").
			']';
		}
		$msg .= ' ['.
			Text::makeChatcmd('To Privchat', "/tell <myname> quote priv {$row->id}").
		"]\n\n";

		$msg .= "<header2>Quotes posted by \"{$poster}\"<end>\n";
		$idList = $this->db->table(Quote::getTable())
			->where('poster', $poster)
			->asObj(Quote::class)
			->map(static function (Quote $row): string {
				return Text::makeChatcmd(
					(string)$row->id,
					"/tell <myname> quote {$row->id}"
				);
			});
		$msg .= '<tab>' . $idList->join(', ');

		return Text::blobWrap(
			'',
			$this->text->makeBlob('Quote', $msg),
			": \"{$quoteMsg}\""
		);
	}

	#[
		NCA\NewsTile(
			name: 'quote',
			description: 'Displays a random quote from your quote database',
			example: "» [Team] This is a random quote from Player 1\n".
				'» [Team] And a witty response from Player 2'
		)
	]
	public function quoteTile(string $sender): ?string {
		/** @var ?Quote */
		$row = $this->db->table(Quote::getTable())
			->inRandomOrder()
			->limit(1)
			->asObj(Quote::class)->first();
		if (!isset($row)) {
			return null;
		}
		$result = [];
		$lines = preg_split("/ (?=(?:\(\d{2}:\d{2}\) )?\[[a-zA-Z 0-9-]+\])/", $row->msg);
		foreach ($lines as $line) {
			$result = [...$result, ...explode("\n", $line)];
		}
		$quote = implode("\n» ", $result);
		$msg = "» {$quote}";
		return $msg;
	}
}

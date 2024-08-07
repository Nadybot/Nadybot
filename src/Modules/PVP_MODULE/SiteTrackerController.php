<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

// pf, site

use Nadybot\Core\Modules\MESSAGES\MessageHubController;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	DB,
	Exceptions\UserException,
	MessageHub,
	ModuleInstance,
	ParamClass\PRemove,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Text,
	Util
};
use Nadybot\Modules\PVP_MODULE\{
	Attributes\Argument,
	FeedMessage\SiteUpdate,
	Handlers\Base,
};
use ReflectionClass;

use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'nw track',
		description: 'Track tower sites',
		accessLevel: 'member'
	),
]
class SiteTrackerController extends ModuleInstance {
	public const EVENTS = [
		'gas-change',
		'site-hot',
		'site-cold',
		'site-planted',
		'site-destroyed',
		'tower-attack',
		'tower-outcome',
		'tower-planted',
		'tower-destroyed',
	];

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private NotumWarsController $nwCtrl;

	#[NCA\Inject]
	private MessageHub $msgHub;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private MessageHubController $msgHubCtrl;

	/** @var array<string,TrackerEntry> */
	private array $trackers = [];

	/**
	 * @var array<string,string>
	 *
	 * @psalm-var array<string,class-string>
	 */
	private array $handlers = [];

	/**
	 * Register an argument handler
	 *
	 * @psalm-param class-string $className
	 */
	public function registerHandler(string $className, string ...$names): void {
		foreach ($names as $name) {
			$this->handlers[$name] = $className;
		}
	}

	/** Check if a given site is currently tracked */
	public function isTracked(SiteUpdate $site, string $event): bool {
		foreach ($this->trackers as $tracker) {
			if ($tracker->matches($site, $event)) {
				return true;
			}
		}
		return false;
	}

	/** Fire the $event for all matching trackers */
	public function fireEvent(RoutableMessage $msg, SiteUpdate $site, string $event): void {
		foreach ($this->trackers as $tracker) {
			if (!$tracker->matches($site, $event)) {
				continue;
			}
			$ignoreEvent = collect($tracker->events)->filter(
				static fn (string $eventPattern): bool => fnmatch($eventPattern, $event, \FNM_CASEFOLD)
			)->isEmpty();
			if ($ignoreEvent) {
				continue;
			}
			$msg->prependPath(Source::fromChannel($tracker->getChannelName()));
			$this->msgHub->handle($msg);
		}
	}

	#[NCA\Setup]
	public function setup(): void {
		foreach (get_declared_classes() as $class) {
			if (!is_a($class, Base::class, true)) {
				continue;
			}
			$refClass = new ReflectionClass($class);
			foreach ($refClass->getAttributes(Argument::class) as $attr) {
				$handler = $attr->newInstance();
				$this->registerHandler($class, ...$handler->names);
			}
		}

		$this->trackers = $this->db->table(TrackerEntry::getTable())
			->asObj(TrackerEntry::class)
			->reduce(
				function (array $result, TrackerEntry $entry): array {
					try {
						$parsed = $this->parseExpression($entry->expression);
					} catch (Throwable) {
						return $result;
					}
					$entry->handlers = $parsed->handlers;
					$result[$entry->id->toString()] = $entry;
					$this->msgHub->registerMessageEmitter($entry);
					return $result;
				},
				[]
			);
	}

	/**
	 * Track sites based on one or more criteria
	 * &lt;expression&gt; is a combination of 1 or more patterns and 0 or more events.
	 * Check the <a href='chatcmd:///tell <myname> <symbol>nw track patterns'>list of patterns</a> and the <a href='chatcmd:///tell <myname> <symbol>nw track events'>list of events</a> for details.
	 *
	 * Note: All given patterns have to match, so using the same pattern twice
	 * <tab>will most likely give an empty result.
	 */
	#[NCA\HandlesCommand('nw track')]
	#[NCA\Help\Example(
		command: '<symbol>nw track add faction=omni max_towers=1',
		description: 'Track all sites owned by omni-orgs with only a CT'
	)]
	#[NCA\Help\Example(
		command: '<symbol>nw track add pf=MORT faction=neutral ql=32-52',
		description: 'Track all sites in Mort owned by neutral orgs, where '.
			'the CT has a QL between 32 and 52'
	)]
	public function addTowerTracker(
		CmdContext $context,
		#[NCA\Str('track', 'tracker')] string $action,
		#[NCA\Str('add')] string $subAction,
		string $expression
	): void {
		$entry = $this->parseExpression($expression);
		$entry->created_by = $context->char->name;
		$this->db->insert($entry);
		$this->msgHub->registerMessageEmitter($entry);
		$this->trackers[$entry->id->toString()] = $entry;
		$numMatches = $this->countMatches($entry);
		$channel = $entry->getChannelName();
		$details = '';
		if (!$this->msgHub->hasRouteFor($channel)) {
			$privCmd = "<symbol>route add {$channel} -> aopriv";
			$orgCmd = "<symbol>route add {$channel} -> aoorg";
			$privLink = Text::makeChatcmd('do it', "/tell <myname> {$privCmd}");
			$orgLink = Text::makeChatcmd('do it', "/tell <myname> {$orgCmd}");

			$blob = "To be able to see the events that your tracker generates,\n".
				"you need to create a route from <highlight>{$channel}<end> to where you'd\n".
				"like to see the notifications:\n\n".
				"<tab><highlight>{$privCmd}<end> [{$privLink}]\n".
				"<tab><i>To display them in the bot's private channel</i>\n\n";
			if (isset($this->config->orgId)) {
				$blob .=
					"<tab><highlight>{$orgCmd}<end> [{$orgLink}]\n".
					"<tab><i>To display them in the guild channel</i>\n\n";
			}
			$blob .=
				"<tab><highlight><symbol>route add {$channel} -> discordpriv(foo)<end>\n".
				"<tab><i>To display them in the Discord-channel 'foo'.";
			$details = ' You need to add a route in order to see the events '.
				'this tracker generates [' . ((array)$this->text->makeBlob(
					'see how',
					$blob,
					'How to configure routing for a tower tracker'
				))[0] . ']';
		}
		$context->reply("Tracker #{$entry->id} installed successfully, matching {$numMatches} sites.{$details}");
	}

	/** Delete a site trackers */
	#[NCA\HandlesCommand('nw track')]
	public function delTowerTracker(
		CmdContext $context,
		#[NCA\Str('track', 'tracker')] string $action,
		PRemove $subAction,
		PUuid $id,
	): void {
		$id = $id();
		$tracker = $this->trackers[$id] ?? null;
		if (!isset($tracker)) {
			$context->reply("No tracker <highlight>{$id}<end> found.");
			return;
		}
		$this->db->table(TrackerEntry::getTable())->delete($id);
		$this->msgHub->unregisterMessageEmitter($tracker->getChannelName());
		$routes = $this->msgHub->getRoutes();
		foreach ($routes as $route) {
			if ($route->getSource() === $tracker->getChannelName()) {
				$this->msgHubCtrl->routeDel($context, $subAction, new PUuid($route->getID()->toString()));
			}
		}
		unset($this->trackers[$id]);
		$context->reply("Tracker <highlight>{$id}<end> successfully removed.");
	}

	/** Show all currently setup site trackers */
	#[NCA\HandlesCommand('nw track')]
	public function listTowerTracker(
		CmdContext $context,
		#[NCA\Str('track', 'tracker')] string $action,
		#[NCA\Str('list')] ?string $subAction,
	): void {
		if (!count($this->trackers)) {
			$context->reply('No registered trackers.');
			return;
		}
		$blocks = [];
		foreach ($this->trackers as $tracker) {
			$blocks []= $this->renderTracker($tracker);
		}
		$msg = $this->text->makeBlob(
			'Registered trackers (' . count($this->trackers) . ')',
			implode("\n\n", $blocks)
		);
		$context->reply($msg);
	}

	/** Show all sites matched by a site tracker */
	#[NCA\HandlesCommand('nw track')]
	public function showTowerTrackerMatches(
		CmdContext $context,
		#[NCA\Str('track', 'tracker')] string $action,
		#[NCA\Str('show', 'view')] string $subAction,
		PUuid $id,
	): void {
		$id = $id();
		$tracker = $this->trackers[$id] ?? null;
		if (!isset($tracker)) {
			$context->reply("No tracker <highlight>{$id}<end> found.");
			return;
		}

		$sites = $this->nwCtrl->getEnabledSites()
			->filter(static fn (SiteUpdate $site): bool => $tracker->matches($site))
			->sortBy('site_id')
			->sortBy('playfield_id');
		$blob = $this->nwCtrl->renderHotSites(null, ...$sites->toArray());
		$expression = Safe::pregReplace('/\s+'.implode('\s+', array_map('preg_quote', $tracker->events)).'$/', '', $tracker->expression);
		$msg = $this->text->makeBlob(
			"Sites matching tracker '{$expression}' (" . $sites->count() . ')',
			$blob
		);
		$context->reply($msg);
	}

	/** Show all sites matched by a site tracker */
	#[NCA\HandlesCommand('nw track')]
	public function showTowerTrackerPatterns(
		CmdContext $context,
		#[NCA\Str('track', 'tracker')] string $action,
		#[NCA\Str('pattern', 'patterns')] string $subAction,
	): void {
		/** @psalm-var list<class-string> */
		$classes = array_unique(array_values($this->handlers));
		$blocks = [];
		foreach ($classes as $class) {
			$refClass = new ReflectionClass($class);
			foreach ($refClass->getAttributes(Argument::class) as $attr) {
				$spec = $attr->newInstance();
				$block = "<header2>{$spec->names[0]}<end>\n";
				if (count($spec->names) > 1) {
					$block .= '<tab>Aliases: <highlight>'.
						implode('<end>, <highlight>', array_values(array_slice($spec->names, 1))).
						"<end>\n";
				}
				if (count($spec->examples)) {
					$block .= "<tab>Examples: <highlight>{$spec->names[0]}=".
						implode("<end>, <highlight>{$spec->names[0]}=", $spec->examples).
						"<end>\n";
				}
				$block .= "<tab>Type: <highlight>{$spec->type}<end>\n";
				$block .= "\n<tab><i>" . implode("</i>\n<tab><i>", explode("\n", $spec->description)).
					'</i>';
				$blocks []= $block;
			}
		}
		$blob = "The following is a list of patterns you can use to limit the\n".
			"scope of your site trackers. Your trackers can use any number\n".
			"of patterns, separated by space.\n".
			"Unless you specify the events you want to receive, you\n".
			"will receive <i>all</i> events for the matching tower sites.\n".
			'See ' . Text::makeChatcmd(
				'<symbol>nw track events',
				'/tell <myname> <symbol>nw track events'
			) . " for a list of events to use in '<highlight><symbol>nw track add<end>'.\n\n".
			implode("\n\n", $blocks);
		$msg = $this->text->makeBlob(
			'Available patterns (' . count($blocks) . ')',
			$blob
		);
		$context->reply($msg);
	}

	/** Show all available site tracker events */
	#[NCA\HandlesCommand('nw track')]
	public function showTowerTrackerEvents(
		CmdContext $context,
		#[NCA\Str('track', 'tracker')] string $action,
		#[NCA\Str('event', 'events')] string $subAction,
	): void {
		$blocks = [];
		foreach (self::EVENTS as $event) {
			$blocks []= "<tab>{$event}";
		}
		$blob = "If you're setting up a site tracker, you can limit the\n".
			"type of events you want to receive for the matching sites.\n".
			"For example, you might be interested in all events for your\n".
			"own org's sites, but only in attacks for the sites of other\n".
			"orgs in your alliance.\n".
			"Events can always use wildcard-operators, so '<highlight>site-*<end>'\n".
			"will match site-planted, site-destroyed, site-hot, and site-cold.\n".
			"You can also give multiple events, just as you need it.\n\n".
			"<header2>Available site tracker events<end>\n".
			implode("\n", $blocks);
		$msg = $this->text->makeBlob(
			'Available events (' . count($blocks) . ')',
			$blob
		);
		$context->reply($msg);
	}

	private function renderTracker(TrackerEntry $tracker): string {
		$expression = Safe::pregReplace('/\s+'.implode('\s+', array_map('preg_quote', $tracker->events)).'$/', '', $tracker->expression);
		$showSitesLink = Text::makeChatcmd(
			'show',
			"/tell <myname> <symbol>nw track show {$tracker->id}"
		);
		$deleteLink = Text::makeChatcmd(
			'delete',
			"/tell <myname> <symbol>nw track rm {$tracker->id}"
		);
		$block = "<header2>{$expression}<end>\n".
			"<tab>ID: <highlight>{$tracker->id}<end> [{$deleteLink}]\n".
			'<tab>Created: <highlight>' . Util::date($tracker->created_on) . '<end> '.
			"by <highlight>{$tracker->created_by}<end>\n".
			'<tab>Events: <highlight>' . implode('<end>, <highlight>', $tracker->events) . "<end>\n".
			'<tab>Matches: <highlight>' . $this->countMatches($tracker) . '<end> sites ['.
			$showSitesLink . ']';
		if (!$this->msgHub->hasRouteFor($tracker->getChannelName())) {
			$block .= "\n<tab><red>No message route for this tracker<end>";
		} else {
			$receivers = $this->msgHub->getReceiversFor($tracker->getChannelName());
			if (count($receivers) > 0) {
				$block .= "\n<tab>Routed to: " . Text::enumerate(
					...Text::arraySprintf('<highlight>%s<end>', ...$receivers)
				);
			}
		}
		return $block;
	}

	private function countMatches(TrackerEntry $entry): int {
		return $this->nwCtrl->getEnabledSites()
			->filter(static fn (SiteUpdate $site): bool => $entry->matches($site))
			->count();
	}

	private function parseExpression(string $expression): TrackerEntry {
		$parser = new TrackerArgumentParser();
		$config = $parser->parse($expression);
		if (!count($config->events)) {
			$config->events = ['*'];
		}
		foreach ($config->events as $eventPattern) {
			$unknownEvent = collect(self::EVENTS)->filter(
				static fn (string $event): bool => fnmatch($eventPattern, $event, \FNM_CASEFOLD)
			)->isEmpty();
			if ($unknownEvent) {
				throw new UserException("There is no event '<highlight>{$eventPattern}<end>'.");
			}
		}
		$handlers = [];
		foreach ($config->arguments as $argument) {
			$argument->name = strtolower($argument->name);
			$className = $this->handlers[$argument->name] ?? null;
			if (!isset($className)) {
				throw new UserException("There is no filter for '<highlight>{$argument->name}<end>'.");
			}
			if (is_subclass_of($className, Base::class)) {
				try {
					/** @psalm-suppress UnsafeInstantiation */
					$handlers []= new $className($argument->value);
				} catch (UserException $e) {
					throw $e;
				} catch (Throwable $e) {
					throw new UserException("'<highlight>{$argument->value}<end>' is not a valid value for {$argument->name}.", 0, $e);
				}
			}
		}
		$entry = new TrackerEntry(
			expression: $expression,
			events: $config->events,
			handlers: $handlers,
			created_by: $this->config->main->character,
		);
		return $entry;
	}
}

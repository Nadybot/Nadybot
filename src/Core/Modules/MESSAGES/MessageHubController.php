<?php

declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use function Safe\{json_encode, preg_match};
use Exception;
use Illuminate\Support\Collection;
use Monolog\Logger;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	Attributes as NCA,
	Channels\DiscordChannel,
	CmdContext,
	Config\BotConfig,
	DB,
	DBSchema\Route,
	DBSchema\RouteHopColor,
	DBSchema\RouteHopFormat,
	DBSchema\RouteModifier,
	DBSchema\RouteModifierArgument,
	MessageHub,
	MessageRoute,
	ModuleInstance,
	ParamClass\PColor,
	ParamClass\PRemove,
	Routing\Source,
	Safe,
	SettingHandlers\ColorSettingHandler,
	Text,
	Types\MessageEmitter,
	Util,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionException;
use Safe\Exceptions\JsonException;

use Throwable;

/**
 * @author Nadyita (RK5)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'route',
		accessLevel: 'mod',
		description: 'Set which message are routed from where to where',
		defaultStatus: 1
	)
]
class MessageHubController extends ModuleInstance {
	/** How to render the sender's name of routed messages */
	#[NCA\Setting\Template(
		options: [
			'Char' => '{char}',
			'Char (Nick)/Char (Main)/Char' => '{char}{?nick: ({nick})}{!nick:{?main: ({main})}}',
			'Char (Nick)/Char' => '{char}{?nick: ({nick})}',
			'Nick/Char' => '{?nick:{nick}}{!nick:{char}}',
			'Nick (Char)/Main (Char)/Char' => '{?nick:{nick} ({char})}{!nick:{?main:{main} ({char})}{!main:{char}}}',
			'Nick (Char)/Char' => '{?nick:{nick} ({char})}{!nick:{char}}',
		],
		exampleValues: ['char' => 'Char', 'nick' => 'Nickname', 'main' => 'Main'],
		help: 'routed_sender_format.txt',
	)]
	public string $routedSenderFormat = '{char}';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BotConfig $config;

	/** Load defined routes from the database and activate them */
	public function loadRouting(): void {
		/** @var Collection<array-key,Collection<int,RouteModifierArgument>> */
		$arguments = $this->db->table(RouteModifierArgument::getTable())
			->orderBy('id')
			->asObj(RouteModifierArgument::class)
			->groupBy('route_modifier_id');

		/** @var Collection<array-key,Collection<int,RouteModifier>> */
		$modifiers = $this->db->table(RouteModifier::getTable())
			->orderBy('id')
			->asObj(RouteModifier::class)
			->each(static function (RouteModifier $mod) use ($arguments): void {
				/** @var list<RouteModifierArgument> */
				$modArguments = $arguments->get($mod->id->toString(), new Collection())->toList();
				$mod->arguments = $modArguments;
			})
			->groupBy('route_id');
		$this->db->table(Route::getTable())
			->orderBy('id')
			->asObj(Route::class)
			->each(function (Route $route) use ($modifiers): void {
				/** @var list<RouteModifier> */
				$routeModifiers = $modifiers->get($route->id->toString(), new Collection())->toList();
				$route->modifiers = $routeModifiers;
				try {
					$msgRoute = $this->messageHub->createMessageRoute($route);
					$this->messageHub->addRoute($msgRoute);
				} catch (Exception $e) {
					$this->logger->error('{error}', [
						'error' => $e->getMessage(),
						'exception' => $e,
					]);
				}
			});
		$this->messageHub->routingLoaded = true;
	}

	/** Mute an existing route for a given period of time */
	#[NCA\HandlesCommand('route')]
	#[NCA\Help\Example(
		'<symbol>route mute 17 1h',
		'Disable route #17 for 1 hour'
	)]
	public function routeMuteIdCommand(
		CmdContext $context,
		#[NCA\Str('mute', 'disable')] string $action,
		PUuid $id,
		#[NCA\PDuration] #[NCA\Str('off')] string $duration
	): void {
		$id = $id();
		$route = $this->getMsgRoute($id);
		if (!isset($route)) {
			$context->reply("No route <highlight>{$id}<end> found.");
			return;
		}
		$from = $route->getSource();
		$to = $route->getDest();
		$direction = $route->getTwoWay() ? '&lt;-&gt;' : '-&gt;';
		if (strtolower($duration) === 'off') {
			$route->unmute();
			$context->reply(
				"Route {$from} {$direction} {$to} <on>enabled<end> again"
			);
			$this->db->table(Route::getTable())
				->where('id', $route->getID())
				->update(['disabled_until' => null]);
			return;
		}
		$durationSecs = Util::parseTime($duration);
		if ($durationSecs === 0) {
			$context->reply("<highlight>{$duration}<end> is not a valid duration.");
			return;
		}
		$route->disable($durationSecs);
		$this->db->table(Route::getTable())
			->where('id', $route->getID())
			->update(['disabled_until' => time() + $durationSecs]);
		$context->reply(
			"Route {$from} {$direction} {$to} <off>muted<end> ".
			"for <highlight>{$duration}<end>."
		);
	}

	/** Mute an existing route by choosing how long */
	#[NCA\HandlesCommand('route')]
	public function routeMuteCommand(
		CmdContext $context,
		#[NCA\Str('mute', 'disable')] string $action,
		PUuid $id,
	): void {
		$id = $id();
		$route = $this->getMsgRoute($id);
		if (!isset($route)) {
			$context->reply("No route <highlight>{$id}<end> found.");
			return;
		}
		$durations = [60, 300, 600, 1_800, 3_600, 6*3_600, 24*3_600];
		$blob = '<header2>Choose mute duration<end>';
		foreach ($durations as $durationInSecs) {
			$timeString = Util::unixtimeToReadable($durationInSecs);
			$command = Text::makeChatcmd(
				"{$timeString}",
				"/tell <myname> route mute {$id} {$durationInSecs}s"
			);
			$blob .= "\n<tab>[{$command}]";
		}
		$from = $route->getSource();
		$to = $route->getDest();
		$direction = $route->getTwoWay() ? '&lt;-&gt;' : '-&gt;';
		$context->reply(
			$this->text->makeBlob(
				"Choose how long to mute {$from} {$direction} {$to}",
				$blob
			)
		);
	}

	/** Create a new route from &lt;from&gt; to &lt;to&gt; with optional modifiers */
	#[NCA\HandlesCommand('route')]
	#[NCA\Help\Example(
		'<symbol>route add system(*) -&gt; web',
		'Route all system messages to the web interface'
	)]
	#[NCA\Help\Example(
		'<symbol>route add aopriv &lt;-&gt; aoorg if-has-prefix(prefix="-")',
		'Relay between org and private channel by using a dash prefix'
	)]
	#[NCA\Help\Example(
		'<symbol>route add tradebot -&gt; aotell(Nady) if-matches(text=machi case-sensitive=false)',
		'Send all messages from Darknet containing "machi" to Nady'
	)]
	#[NCA\Help\Prologue(
		"Routes define from which source to which destination\n".
		"messages will be forwarded. They consist of a source,\n".
		"a destination and any number of modifiers, where modifiers\n".
		"can either modify or completely drop a message.\n"
	)]
	#[NCA\Help\Epilogue(
		"For more information, see the <a href='chatcmd:///start https://github.com/Nadybot/Nadybot/wiki/Routing'>Nadybot WIKI</a>"
	)]
	public function routeAddCommand(
		CmdContext $context,
		#[NCA\Str('add', 'addforce')] string $action,
		#[NCA\Str('from')] ?string $fromConst,
		PSource $from,
		PDirection $direction,
		PSource $to,
		?string $modifiers
	): void {
		$force = (strtolower($action) === 'addforce');
		$to = $this->fixDiscordChannelName($to());
		$from = $this->fixDiscordChannelName($from());
		if ($to === Source::PRIV) {
			$to = Source::PRIV . "({$this->config->main->character})";
		}
		if ($from === Source::PRIV) {
			$from = Source::PRIV . "({$this->config->main->character})";
		}
		$receiver = $this->messageHub->getReceiver($to);
		if (!$force && !isset($receiver)) {
			$context->reply("Unknown target <highlight>{$to}<end>.");
			return;
		}

		$senders = collect($this->messageHub->getEmitters());
		$hasSender = $senders->first(static function (MessageEmitter $e) use ($from): bool {
			return fnmatch($e->getChannelName(), $from, \FNM_CASEFOLD)
				|| fnmatch($from, $e->getChannelName(), \FNM_CASEFOLD);
		});
		if (!$force && !isset($hasSender)) {
			$context->reply("No message source for <highlight>{$from}<end> found.");
			return;
		}
		$route = new Route(
			source: $from,
			destination: $to,
		);
		if ($direction->isTwoWay()) {
			$route->two_way = true;
			$receiver = $this->messageHub->getReceiver($from);
			if (!$force && !isset($receiver)) {
				$context->reply("Unable to route to <highlight>{$from}<end>.");
				return;
			}
		}
		if (isset($modifiers)) {
			$parser = new ModifierExpressionParser();
			try {
				$modifiers = $parser->parse($route, $modifiers);
			} catch (ModifierParserException $e) {
				$context->reply($e->getMessage());
				return;
			}
		}

		$inTransaction = $this->db->inTransaction();

		/** @var ?list<RouteModifier> $modifiers */
		if (!$inTransaction) {
			$this->db->awaitBeginTransaction();
		}
		try {
			$this->db->insert($route);
			foreach ($modifiers??[] as $modifier) {
				$this->db->insert($modifier);
				foreach ($modifier->arguments as $argument) {
					$this->db->insert($argument);
				}
				$route->modifiers []= $modifier;
			}
		} catch (Throwable $e) {
			if (!$inTransaction) {
				$this->db->rollback();
			}
			$context->reply('Error saving the route: ' . $e->getMessage());
			return;
		}
		$modifiers = [];
		foreach ($route->modifiers as $modifier) {
			$modifiers []= htmlspecialchars($modifier->toString());
		}
		try {
			$msgRoute = $this->messageHub->createMessageRoute($route);
		} catch (Exception $e) {
			if (!$inTransaction) {
				$this->db->rollback();
			}
			$context->reply($e->getMessage());
			return;
		}
		if (!$inTransaction) {
			$this->db->commit();
		}
		$this->messageHub->addRoute($msgRoute);
		$context->reply(
			"Route added from <highlight>{$from}<end> ".
			"to <highlight>{$to}<end>".
			(count($modifiers) ? ' using ' : '').
			implode(' ', $modifiers)  . '.'
		);
	}

	/** List all route sources */
	#[NCA\HandlesCommand('route')]
	public function routeListFromCommand(
		CmdContext $context,
		#[NCA\Str('list')] string $action,
		#[NCA\Regexp('from|sources?|src', example: 'src')] string $subAction
	): void {
		$emitters = $this->messageHub->getEmitters();
		$count = count($emitters);
		ksort($emitters);
		$emitters = collect($emitters);
		$blob = $emitters->groupBy($this->getEmitterType(...))
			->map($this->renderEmitterGroup(...))
			->join("\n\n");
		$msg = $this->text->makeBlob("Message sources ({$count})", $blob);
		$context->reply($msg);
	}

	/** List all route destinations */
	#[NCA\HandlesCommand('route')]
	public function routeListToCommand(
		CmdContext $context,
		#[NCA\Str('list')] string $action,
		#[NCA\Regexp('to|dsts?|dests?|destinations?', example: 'dst')] string $subAction
	): void {
		$receivers = $this->messageHub->getReceivers();
		$count = count($receivers);
		ksort($receivers);
		$receivers = collect($receivers);
		$blob = $receivers->groupBy($this->getEmitterType(...))
			->map($this->renderEmitterGroup(...))
			->join("\n\n");
		$msg = $this->text->makeBlob("Message targets ({$count})", $blob);
		$context->reply($msg);
	}

	/** List all route modifiers */
	#[NCA\HandlesCommand('route')]
	public function routeListModifiersCommand(
		CmdContext $context,
		#[NCA\Str('list')] string $action,
		#[NCA\Regexp('mods?|modifiers?', example: 'mods')] string $subAction
	): void {
		$mods = $this->messageHub->modifiers;
		$count = count($mods);
		if (!$count) {
			$context->reply('No message modifiers available.');
			return;
		}
		$blobs = [];
		foreach ($mods as $mod) {
			$description = $mod->description ?? 'Someone forgot to add a description';
			$entry = "<header2>{$mod->name}<end>\n".
				'<tab><i>'.
				implode("\n<tab>", explode("\n", trim($description))).
				"</i>\n".
				'<tab>[' . Text::makeChatcmd('details', "/tell <myname> route list mod {$mod->name}") . ']';
			$blobs []= $entry;
		}
		$blob = implode("\n\n", $blobs);
		$msg = $this->text->makeBlob("Message modifiers ({$count})", $blob);
		$context->reply($msg);
	}

	/** Get detailed information about a route modifier */
	#[NCA\HandlesCommand('route')]
	public function routeListModifierCommand(
		CmdContext $context,
		#[NCA\Str('list')] string $action,
		#[NCA\Regexp('mods?|modifiers?', example: 'mod')] string $subAction,
		string $modifier
	): void {
		$mod = $this->messageHub->modifiers[$modifier]??null;
		if (!isset($mod)) {
			$context->reply("No message modifier <highlight>{$modifier}<end> found.");
			return;
		}
		$refClass = new ReflectionClass($mod->class);
		try {
			$refConstr = $refClass->getMethod('__construct');
			$refParams = $refConstr->getParameters();
		} catch (ReflectionException $e) {
			$refParams = [];
		}
		$description = $mod->description ?? 'Someone forgot to add a description';
		$blob = "<header2>Description<end>\n".
			'<tab>' . implode("\n<tab>", explode("\n", trim($description))).
			"\n";
		if (count($mod->params)) {
			$blob .= "\n<header2>Parameters<end>\n";
			$parNum = 0;
			foreach ($mod->params as $param) {
				$type = ($param->type === $param::TYPE_SECRET) ? $param::TYPE_STRING : $param->type;
				$blob .= "<tab><green>{$type}<end> <highlight>{$param->name}<end>";
				if (!$param->required) {
					if (isset($refParams[$parNum]) && $refParams[$parNum]->isDefaultValueAvailable()) {
						try {
							$blob .= ' (optional, default='.
								json_encode(
									$refParams[$parNum]->getDefaultValue(),
									\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR|\JSON_INVALID_UTF8_SUBSTITUTE
								) . ')';
						} catch (JsonException $e) {
							$blob .= ' (optional)';
						}
					} else {
						$blob .= ' (optional)';
					}
				}
				$parNum++;
				$blob .= "\n<tab><i>".
					implode("</i>\n<tab><i>", explode("\n", $param->description ?? 'No description')).
					"</i>\n\n";
			}
		}
		$msg = $this->text->makeBlob("{$mod->name}", $blob);
		$context->reply($msg);
	}

	/** Delete a route by its ID */
	#[NCA\HandlesCommand('route')]
	public function routeDel(CmdContext $context, PRemove $action, PUuid $id): void {
		$id = $id();
		$route = $this->getRoute($id);
		if (!isset($route)) {
			$context->reply("No route <highlight>{$id}<end> found.");
			return;
		}

		/** @var list<int> List of modifier-ids for the route */
		$modifiers = array_column($route->modifiers, 'id');
		$this->db->awaitBeginTransaction();
		try {
			if (count($modifiers)) {
				$this->db->table(RouteModifierArgument::getTable())
					->whereIn('route_modifier_id', $modifiers)
					->delete();
				$this->db->table(RouteModifier::getTable())
					->where('route_id', $id)
					->delete();
			}
			$this->db->table(Route::getTable())
				->delete($id);
		} catch (Throwable $e) {
			$this->db->rollback();
			$context->reply('Error deleting the route: ' . $e->getMessage());
			return;
		}
		$this->db->commit();
		$deleted = $this->messageHub->deleteRouteID($id);
		if (isset($deleted)) {
			$context->reply(
				"Route #{$id} (" . trim($this->renderRoute($deleted)) . ') deleted.'
			);
		} else {
			$context->reply("Route <highlight>{$id}<end> deleted.");
		}
	}

	/** Get a list view of all defined routes */
	#[NCA\HandlesCommand('route')]
	public function routeList(CmdContext $context, #[NCA\Str('list')] string $action): void {
		$routes = $this->messageHub->getRoutes();
		if (!count($routes)) {
			$context->reply('There are no routes defined.');
			return;
		}
		$list = [];
		usort($routes, static function (MessageRoute $route1, MessageRoute $route2): int {
			return strcmp($route1->getSource(), $route2->getSource());
		});
		foreach ($routes as $route) {
			$delLink = Text::makeChatcmd('delete', '/tell <myname> route del ' . $route->getID());
			$disabledUntil = $route->getDisabled();
			$isDisabled = isset($disabledUntil) && $disabledUntil > time();
			$disableLink = Text::makeChatcmd('mute', '/tell <myname> route mute ' . $route->getID());
			if ($isDisabled) {
				$disableLink = Text::makeChatcmd('unmute', '/tell <myname> route mute ' . $route->getID() . ' off');
			}
			$list []="[{$delLink}] [{$disableLink}] " . $this->renderRoute($route);
		}
		$blob = "<header2>Active routes<end>\n<tab>";
		$blob .= implode("\n<tab>", $list);
		$msg = $this->text->makeBlob('Message Routes (' . count($routes) . ')', $blob);
		$context->reply($msg);
	}

	/** Get a tree view of all defined routes. If 'all' is given, it will include system routes as well */
	#[NCA\HandlesCommand('route')]
	public function routeTree(CmdContext $context, #[NCA\Str('tree')] ?string $tree, #[NCA\Str('all')] ?string $all): void {
		$routes = $this->messageHub->getRoutes();
		if (!count($routes)) {
			$context->reply('There are no routes defined.');
			return;
		}

		/** @var array<string,list<MessageRoute>> $grouped */
		$grouped = [];
		$numTotal = count($routes);
		$numShown = 0;
		foreach ($routes as $route) {
			$isSystemRoute = preg_match('/^system/', $route->getDest())
				|| preg_match('/^system/', $route->getSource());
			if (!isset($all) && $isSystemRoute) {
				continue;
			}
			$dests = [strtolower($route->getDest())];
			if ($route->getTwoWay()) {
				$dests []= $route->getSource();
			}
			foreach ($dests as $dest) {
				if ($this->messageHub->getReceiver($dest) === null) {
					continue;
				}
				$grouped[strtolower($dest)] ??= [];
				$grouped[strtolower($dest)] []= $route;
			}
			$numShown++;
		}

		$result = [];
		foreach ($grouped as $receiver => $recRoutes) {
			$result[$receiver] = [];
			foreach ($recRoutes as $route) {
				$delLink = Text::makeChatcmd('delete', '/tell <myname> route del ' . $route->getID());
				$arrow = '&lt;-';
				if ($route->getTwoWay() && $this->messageHub->getReceiver($route->getSource()) !== null) {
					$arrow .= '&gt;';
				} else {
					$arrow .= '<black>><end>';
				}
				$routeName = $route->getSource();
				if ($route->getTwoWay() && (strcasecmp($route->getSource(), $receiver) === 0)) {
					$routeName = $route->getDest();
				}
				$disabledUntil = $route->getDisabled();
				$isDisabled = isset($disabledUntil) && $disabledUntil > time();
				$disableLink = Text::makeChatcmd('mute', '/tell <myname> route mute ' . $route->getID());
				if ($isDisabled) {
					$disableLink = Text::makeChatcmd('unmute', '/tell <myname> route mute ' . $route->getID() . ' off');
				}
				$result[$receiver][$routeName] ??= [];
				$result[$receiver][$routeName] []= "<tab>{$arrow} [{$delLink}] [{$disableLink}] <highlight>{$routeName}<end> ".
					implode(' ', $route->renderModifiers(true)).
					(
						$isDisabled
						? ' (<red>muted for ' . Util::unixtimeToReadable($disabledUntil - time()) . '<end>)'
						: ''
					);
			}
		}
		$blobs = [];
		ksort($result);
		foreach ($result as $receiver => $senders) {
			ksort($senders);
			$blob = "<header2>{$receiver}<end>\n";
			foreach ($senders as $sender => $lines) {
				$blob .= implode("\n", $lines) . "\n";
			}
			$blobs []= $blob;
		}
		$blob = implode("\n", $blobs);
		if (!isset($all)) {
			$blob .= "\n\n".
				"<i>This view does not include system messages.\n".
				'Use ' . Text::makeChatcmd('<symbol>route all', '/tell <myname> route all').
				' or ' . Text::makeChatcmd('<symbol>route list', '/tell <myname> route list').
				' to see them.</i>';
		}
		$msg = 'Message routes ';
		if ($numShown < $numTotal) {
			$msg .= "({$numShown} / {$numTotal})";
		} else {
			$msg .= "({$numTotal})";
		}
		$msg = $this->text->makeBlob($msg, $blob);
		$context->reply($msg);
	}

	/** Show the current colors for all sources and routes */
	#[NCA\HandlesCommand('route')]
	public function routeListColorConfigCommand(CmdContext $context, #[NCA\Str('color')] string $action): void {
		$colors = $this->messageHub::$colors;
		if ($colors->isEmpty()) {
			$context->reply('No colors have been defined yet.');
			return;
		}
		$blob = "<header2>Color definitions<end>\n";
		$blobs = [];
		foreach ($colors as $color) {
			$id = $title = $color->hop;
			if (isset($color->where)) {
				$title .= "<end> -&gt; <highlight>{$color->where}";
				$id .= " -> {$color->where}";
			}
			if (isset($color->via)) {
				$title .= "<end> via <highlight>{$color->via}";
				$id .= " via {$color->via}";
			}
			$remCmd = Text::makeChatcmd(
				'clear',
				"/tell <myname> route color tag rem {$id}"
			);
			$pickCmd = Text::makeChatcmd(
				'pick',
				"/tell <myname> route color tag pick {$id}"
			);
			$part = "<tab><highlight>{$title}<end>\n".
				'<tab><tab>Tag: ';
			if (isset($color->tag_color)) {
				$part .= "<font color=#{$color->tag_color}>#{$color->tag_color}</font>".
					" [{$pickCmd}] [{$remCmd}]\n";
			} else {
				$part .= "&lt;unset&gt; [{$pickCmd}]\n";
			}

			$remCmd = Text::makeChatcmd(
				'clear',
				"/tell <myname> route color text rem {$id}"
			);
			$pickCmd = Text::makeChatcmd(
				'pick',
				"/tell <myname> route color text pick {$id}"
			);
			$part .= '<tab><tab>Text: ';
			if (isset($color->text_color)) {
				$part .= "<font color=#{$color->text_color}>#{$color->text_color}</font>".
					" [{$pickCmd}] [{$remCmd}]\n";
			} else {
				$part .= "&lt;unset&gt; [{$pickCmd}]\n";
			}

			$blobs []= $part;
		}
		$blob .= implode("\n", $blobs);
		$blob .= "\n\n".
			'You can specify colors for any of the types and names listed '.
			"at <highlight><symbol>route list src<end>.\n".
			"Types are 'system', 'aopriv', etc. and names are specific sub-".
			"parts of these, like 'gsp' in 'system(gsp)'.\n\n".
			"Set the colors with\n".
			"<tab><highlight><symbol>route color tag pick type(name)<end>,\n".
			"<tab><highlight><symbol>route color text pick type(name)<end>,\n".
			"<tab><highlight><symbol>route color text pick type(name) -&gt; type(name)<end> or \n".
			'<tab><highlight><symbol>route color text pick type(name) -&gt; type(name) via type(name)<end>';
		$msg = $this->text->makeBlob(
			'Routing colors (' . count($colors) . ')',
			$blob
		);
		$context->reply($msg);
	}

	/** Remove the tag or text color definition for a source or route */
	#[NCA\HandlesCommand('route')]
	public function routeTagColorRemCommand(
		CmdContext $context,
		#[NCA\Str('color')] string $action,
		#[NCA\StrChoice('tag', 'text')] string $type,
		PRemove $subAction,
		PSource $tag,
		?PWhere $where,
		?PVia $via
	): void {
		$tag = $this->fixDiscordChannelName($tag());
		if (isset($where)) {
			$where = $this->fixDiscordChannelName($where());
		}

		/** @var ?string $where */
		if (isset($via)) {
			$via = $this->fixDiscordChannelName($via());
		}

		/** @var ?string $via */
		$color = $this->getHopColor($tag, $where??null, $via??null);
		$name = $tag;
		if (isset($where)) {
			$name .= "<end> -&gt; <highlight>{$where}";
		}
		if (isset($via)) {
			$name .= "<end> via <highlight>{$via}";
		}
		$attr = "{$type}_color";
		$otherAttr = 'text_color';
		if ($type === 'text') {
			$otherAttr = 'tag_color';
		}
		if (!isset($color) || !isset($color->{$attr})) {
			$context->reply("No {$type} color for <highlight>{$name}<end> defined.");
			return;
		}
		if (isset($color->{$otherAttr})) {
			$color->{$attr} = null;
			$this->db->update($color);
			$context->reply(
				ucfirst($type) . ' color definition for '.
				"<highlight>{$name}<end> deleted."
			);
			return;
		}
		$this->db->table(RouteHopColor::getTable())
			->delete($color->id);
		$this->messageHub->loadTagColor();
		$context->reply("Color definition for <highlight>{$name}<end> deleted.");
	}

	/** Remove all color definitions for tags and texts */
	#[NCA\HandlesCommand('route')]
	public function routeTagColorRemAllCommand(CmdContext $context, #[NCA\Str('color')] string $action, #[NCA\Str('remall')] string $subAction): void {
		$this->db->table(RouteHopColor::getTable())
			->truncate();
		$this->messageHub->loadTagColor();
		$context->reply('All route color definitions deleted.');
	}

	/** Set the tag or text color for a source or route */
	#[NCA\HandlesCommand('route')]
	public function routeSetColorCommand(
		CmdContext $context,
		#[NCA\Str('color')] string $action,
		#[NCA\StrChoice('tag', 'text')] string $type,
		#[NCA\Str('set')] string $subAction,
		PSource $tag,
		?PWhere $where,
		?PVia $via,
		PColor $color
	): void {
		$tag = $this->fixDiscordChannelName($tag());
		$name = $tag = strtolower($tag);
		$where = isset($where)
			? strtolower($this->fixDiscordChannelName($where()))
			: null;
		$via = isset($via)
			? strtolower($this->fixDiscordChannelName($via()))
			: null;
		if (isset($where)) {
			$name .= "<end> -&gt; <highlight>{$where}";
		}
		if (isset($via)) {
			$name .= "<end> via <highlight>{$via}";
		}
		$type = strtolower($type);
		$color = $color->getCode();
		if (strlen($tag) > 50) {
			$context->reply('Your tag is longer than the supported 50 characters.');
			return;
		}
		if (strlen($where??'') > 50) {
			$context->reply('Your destination is longer than the supported 50 characters.');
			return;
		}
		if (strlen($via??'') > 50) {
			$context->reply('Your via hop is longer than the supported 50 characters.');
			return;
		}
		$colorDef = $this->getHopColor($tag, $where, $via);
		if (!isset($colorDef)) {
			$colorDef = new RouteHopColor(
				hop: $tag,
				where: $where,
				via: $via,
			);
		}
		if ($type === 'text') {
			$colorDef->text_color = $color;
		} else {
			$colorDef->tag_color = $color;
		}
		if (isset($colorDef->id)) {
			$this->db->update($colorDef);
		} else {
			$this->db->insert($colorDef);
			$this->messageHub->loadTagColor();
		}
		$context->reply(
			ucfirst($type) . ' color for '.
			"<highlight>{$name}<end> set to ".
			"<font color='#{$color}'>#{$color}</font>."
		);
	}

	/** Pick the tag or text color for a source or route */
	#[NCA\HandlesCommand('route')]
	public function routePickColorCommand(
		CmdContext $context,
		#[NCA\Str('color')] string $action,
		#[NCA\StrChoice('tag', 'text')] string $type,
		#[NCA\Str('pick')] string $subAction,
		PSource $tag,
		?PWhere $where,
		?PVia $via
	): void {
		$tag = $this->fixDiscordChannelName($tag());
		$id = $name = $tag = strtolower($tag);
		$where = isset($where)
			? strtolower($this->fixDiscordChannelName($where()))
			: null;
		$via = isset($via)
			? strtolower($this->fixDiscordChannelName($via()))
			: null;
		if (isset($where)) {
			$name .= " -&gt; {$where}";
			$id .= " -> {$where}";
		}
		if (isset($via)) {
			$name .= " <i>via {$via}</i>";
			$id .= " via {$via}";
		}
		$type = strtolower($type);
		if (strlen($tag) > 50) {
			$context->reply('Your tag name is too long.');
			return;
		}
		if (strlen($where??'') > 50) {
			$context->reply('Your destination is too long.');
			return;
		}
		if (strlen($via??'') > 50) {
			$context->reply('Your via hop name is too long.');
			return;
		}
		$colorList = ColorSettingHandler::getExampleColors();
		$blob = "<header2>Pick a {$type} color for {$name}<end>\n";
		foreach ($colorList as $color => $colorName) {
			$link = Text::makeChatcmd(
				'Pick this one',
				"/tell <myname> route color {$type} set {$id} {$color}"
			);
			$blob .= "<tab>[{$link}] <font color='{$color}'>Example Text</font> ({$colorName})\n";
		}
		$msg = $this->text->makeBlob(
			'Choose from colors (' . count($colorList) . ')',
			$blob
		);
		$context->reply($msg);
	}

	/** Show how the hops of a route are rendered */
	#[NCA\HandlesCommand('route')]
	public function routeListFormatCommand(CmdContext $context, #[NCA\Str('format')] string $action): void {
		$formats = Source::$format;
		if ($formats->isEmpty()) {
			$context->reply('No formats have been defined yet.');
			return;
		}
		$blob = "<header2>Format definitions<end>\n";
		$blobs = [];
		foreach ($formats as $format) {
			$remCmd = Text::makeChatcmd(
				'clear',
				"/tell <myname> route format rem {$format->hop}"
			);
			if ($format->render) {
				$switchCmd = Text::makeChatcmd(
					'disable',
					"/tell <myname> route format render {$format->hop} false"
				);
			} else {
				$switchCmd = Text::makeChatcmd(
					'enable',
					"/tell <myname> route format render {$format->hop} true"
				);
			}
			$part = "<tab><highlight>{$format->hop}<end> [{$remCmd}]\n".
				'<tab><tab>Render: <highlight>'.
				($format->render ? 'true' : 'false') . "<end> [{$switchCmd}]\n".
				"<tab><tab>Display: <highlight>{$format->format}<end>\n";

			$blobs []= $part;
		}
		$blob .= implode("\n", $blobs);
		$blob .= "\n\n".
			'You can specify the format for any of the types and names listed '.
			"at with <highlight><symbol>route list src<end>.\n".
			"Types are 'system', 'aopriv', etc. and names are specific sub-".
			"parts of these, like 'gsp' in 'system(gsp)'.\n\n".
			"Set the format with\n".
			"<tab><highlight><symbol>route format render type(name) false<end> or\n".
			'<tab><highlight><symbol>route format display type(name) gsp:%s<end>.';
		$msg = $this->text->makeBlob(
			'Routing formats (' . count($formats) . ')',
			$blob
		);
		$context->reply($msg);
	}

	/** Reset the rendering of a hop to the default */
	#[NCA\HandlesCommand('route')]
	public function routeFormatClearCommand(
		CmdContext $context,
		#[NCA\Str('format')] string $action,
		PRemove $subAction,
		PSource $hop
	): void {
		$hop = $this->fixDiscordChannelName($hop());
		if (!$this->clearHopFormat($hop)) {
			$context->reply("No format defined for <highlight>{$hop}<end>.");
			return;
		}
		$context->reply("Format cleared for <highlight>{$hop}<end>.");
	}

	/** Reset the rendering of all hops to their default */
	#[NCA\HandlesCommand('route')]
	public function routeFormatRemAllCommand(CmdContext $context, #[NCA\Str('format')] string $action, #[NCA\Str('remall')] string $subAction): void {
		$this->db->table(RouteHopFormat::getTable())->truncate();
		$this->messageHub->loadTagFormat();
		$context->reply('All route format definitions deleted.');
	}

	/** Switch the displaying of a hop's name on or off */
	#[NCA\HandlesCommand('route')]
	public function routeFormatChangeRenderCommand(
		CmdContext $context,
		#[NCA\Str('format')] string $action,
		#[NCA\Str('render')] string $subAction,
		PSource $hop,
		bool $render
	): void {
		$hop = $this->fixDiscordChannelName($hop());
		if (strlen($hop) > 50) {
			$context->reply("Your tag '<highlight>{$hop}<end>' is longer than the supported 50 characters.");
			return;
		}
		$this->setHopRender($hop, $render);
		$context->reply('Format saved.');
	}

	/** Change the display text format of a hop's name */
	#[NCA\HandlesCommand('route')]
	public function routeFormatChangeDisplayCommand(
		CmdContext $context,
		#[NCA\Str('format')] string $action,
		#[NCA\Str('display')] string $subAction,
		PSource $hop,
		string $format
	): void {
		$hop = $this->fixDiscordChannelName($hop());
		if (strlen($hop) > 50) {
			$context->reply("Your tag '<highlight>{$hop}<end>' is longer than the supported 50 characters.");
			return;
		}
		if (strlen($format) > 50) {
			$context->reply("Your display format '<highlight>{$format}<end>' is longer than the supported 50 characters.");
			return;
		}
		try {
			$this->setHopDisplay($hop, $format);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply('Display format saved.');
	}

	/** Remove all routes. Do not use unless you know what you are doing */
	#[NCA\HandlesCommand('route')]
	public function routeRemAllCommand(
		CmdContext $context,
		#[NCA\Str('remall')] string $action
	): void {
		try {
			$numDeleted = $this->messageHub->deleteAllRoutes();
		} catch (Exception $e) {
			$context->reply('Unknown error clearing the routing table: ' . $e->getMessage());
			return;
		}
		$context->reply("<highlight>{$numDeleted}<end> routes deleted.");
	}

	/** Turn on/off rendering of a specific hop */
	public function setHopRender(string $hop, bool $state): void {
		/** @var ?RouteHopFormat */
		$format = Source::$format->first(static fn (RouteHopFormat $x) => $x->hop === $hop);
		if (!isset($format)) {
			$format = new RouteHopFormat(
				hop: $hop,
				format: '%s',
			);
		}
		$format->render = $state;
		$this->db->upsert($format);
		$this->messageHub->loadTagFormat();
	}

	/** Define how to render a specific hop */
	public function setHopDisplay(string $hop, string $format): void {
		if (preg_match('/%[^%]/', $format)) {
			$_ignore = sprintf($format, 'text');
		}
		$spec = Source::$format->first(static fn (RouteHopFormat $x) => $x->hop === $hop);

		if (!isset($spec)) {
			$spec = new RouteHopFormat(hop: $hop);
		}
		$spec->format = $format;
		$this->db->upsert($spec);
		$this->messageHub->loadTagFormat();
	}

	public function clearHopFormat(string $hop): bool {
		/** @var ?RouteHopFormat */
		$format = Source::$format->first(static fn (RouteHopFormat $x) => $x->hop === $hop);
		if (!isset($format)) {
			return false;
		}
		if ($this->db->table(RouteHopFormat::getTable())->delete($format->id) === 0) {
			return false;
		}
		$this->messageHub->loadTagFormat();
		return true;
	}

	public function getHopColor(string $hop, ?string $where=null, ?string $via=null): ?RouteHopColor {
		return $this->messageHub::$colors
			->first(static function (RouteHopColor $x) use ($hop, $where, $via): bool {
				if (isset($where) !== isset($x->where)) {
					return false;
				}
				if (isset($where) && strcasecmp($x->where??'', $where) !== 0) {
					return false;
				}
				if (isset($via) !== isset($x->via)) {
					return false;
				}
				if (isset($via) && strcasecmp($x->via??'', $via) !== 0) {
					return false;
				}
				return strcasecmp($x->hop, $hop) === 0;
			});
	}

	public function getRoute(\Stringable|string $id): ?Route {
		/** @var Route|null */
		$route = $this->db->table(Route::getTable())
			->where('id', (string)$id)
			->limit(1)
			->asObj(Route::class)
			->first();
		if (!isset($route)) {
			return null;
		}
		$route->modifiers = $this->db->table(RouteModifier::getTable())
		->where('route_id', (string)$id)
		->orderBy('id')
		->asObjArr(RouteModifier::class);
		foreach ($route->modifiers as $modifier) {
			$modifier->arguments = $this->db->table(RouteModifierArgument::getTable())
			->where('route_modifier_id', $modifier->id)
			->orderBy('id')
			->asObjArr(RouteModifierArgument::class);
		}
		return $route;
	}

	public function getMsgRoute(UuidInterface|string $id): ?MessageRoute {
		$routes = $this->messageHub->getRoutes();
		foreach ($routes as $route) {
			if ((string)$route->getID() === (string)$id) {
				return $route;
			}
		}
		return null;
	}

	/** Render the route $route into a single line of text */
	public function renderRoute(MessageRoute $route): string {
		$from = $route->getSource();
		$to = $route->getDest();
		$direction = $route->getTwoWay() ? '&lt;-&gt;' : '-&gt;';

		$routeString = "<highlight>{$from}<end> {$direction} <highlight>{$to}<end> ".
			implode(' ', $route->renderModifiers(true));
		$disabledUntil = $route->getDisabled();
		if (isset($disabledUntil) && $disabledUntil > time()) {
			$routeString .= '(<red>muted for ' . Util::unixtimeToReadable($disabledUntil - time()) .'<end>)';
		}
		return $routeString;
	}

	/** Get the type (the part before the bracket) of an emitter */
	public function getEmitterType(MessageEmitter $emitter): string {
		$type = $emitter->getChannelName();
		$bracket = strpos($type, '(');
		if ($bracket === false) {
			return $type;
		}
		return substr($type, 0, $bracket);
	}

	/**
	 * Render a blob for an emitter group
	 *
	 * @param Collection<array-key,MessageEmitter> $values
	 */
	public function renderEmitterGroup(Collection $values, string $group): string {
		if ($group === Source::LOG) {
			// Log group is sorted by severity, descending
			$values = $values->sort(static function (MessageEmitter $e1, MessageEmitter $e2): int {
				$l1 = 0;
				if (count($matches = Safe::pregMatch("/\((.+)\)$/", $e1->getChannelName()))) {
					try {
						/**
						 * @psalm-suppress ArgumentTypeCoercion
						 *
						 * @phpstan-ignore-next-line
						 */
						$l1 = Logger::toMonologLevel($matches[1]);
					} catch (Exception) {
					}
				}
				$l2 = 0;
				if (count($matches = Safe::pregMatch("/\((.+)\)$/", $e2->getChannelName()))) {
					try {
						/**
						 * @psalm-suppress ArgumentTypeCoercion
						 *
						 * @phpstan-ignore-next-line
						 */
						$l2 = Logger::toMonologLevel($matches[1]);
					} catch (Exception) {
					}
				}
				return $l2 <=> $l1;
			});
		}
		return "<header2>{$group}<end>\n<tab>".
			$values->map(static function (MessageEmitter $emitter): string {
				$name = htmlentities($emitter->getChannelName());
				if ($emitter instanceof DiscordChannel) {
					if (!preg_match('/^[[:graph:]]+$/s', $name)) {
						$name .= ' or discordpriv(' . $emitter->getChannelID() . ')';
					}
				}
				return $name;
			})
			->join("\n<tab>");
	}

	protected function fixDiscordChannelName(string $name): string {
		if (!count($matches = Safe::pregMatch("/^discordpriv\((\d+?)\)$/", $name))) {
			return str_replace(['&lt;', '&gt;'], ['<', '>'], $name);
		}
		$emitters = $this->messageHub->getEmitters();
		foreach ($emitters as $emitter) {
			if ($emitter instanceof DiscordChannel
				&& ($emitter->getChannelID() === $matches[1])) {
				return $emitter->getChannelName();
			}
		}
		return $name;
	}
}

<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\MESSAGES;

use Exception;
use JsonException;
use ReflectionClass;
use Throwable;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	ColorSettingHandler,
	CommandReply,
	DB,
	DBSchema\Route,
	DBSchema\RouteHopColor,
	DBSchema\RouteHopFormat,
	DBSchema\RouteModifier,
	DBSchema\RouteModifierArgument,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	MessageRoute,
	Nadybot,
	SettingManager,
	Text,
	Util,
	Routing\Source,
};
use ReflectionException;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command       = 'route',
 *		accessLevel   = 'mod',
 *		description   = 'Set which message are routed from where to where',
 *		help          = 'route.txt',
 *		defaultStatus = '1'
 *	)
 */
class MessageHubController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Logger */
	public LoggerWrapper $logger;

	/** Load defined routes from the database and activate them */
	public function loadRouting() {
		$arguments = $this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT)
			->orderBy("id")
			->asObj(RouteModifierArgument::class)
			->groupBy("route_modifier_id");
		$modifiers = $this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER)
			->orderBy("id")
			->asObj(RouteModifier::class)
			->each(function (RouteModifier $mod) use ($arguments): void {
				$mod->arguments = $arguments->get($mod->id, new Collection())->toArray();
			})
			->groupBy("route_id");
		$this->db->table($this->messageHub::DB_TABLE_ROUTES)
			->orderBy("id")
			->asObj(Route::class)
			->each(function(Route $route) use ($modifiers): void {
				$route->modifiers = $modifiers->get($route->id, new Collection())->toArray();
				try {
					$msgRoute = $this->messageHub->createMessageRoute($route);
					$this->messageHub->addRoute($msgRoute);
				} catch (Exception $e) {
					$this->logger->log('ERROR', $e->getMessage(), $e);
				}
			});
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route add (?:from )?(?<from>.+?) (?<direction>to|->|-&gt;|<->|&lt;-&gt;) (?<to>[^ ]+) (?<modifiers>.+)$/i")
	 * @Matches("/^route add (?:from )?(?<from>.+?) (?<direction>to|->|-&gt;|<->|&lt;-&gt;) (?<to>[^ ]+\(.*?\)) (?<modifiers>.+)$/i")
	 * @Matches("/^route add (?:from )?(?<from>.+?) (?<direction>to|->|-&gt;|<->|&lt;-&gt;) (?<to>.+)$/i")
	 */
	public function routeAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($args["to"] === Source::PRIV) {
			$args["to"] = Source::PRIV . "({$this->chatBot->char->name})";
		}
		if ($args["from"] === Source::PRIV) {
			$args["from"] = Source::PRIV . "({$this->chatBot->char->name})";
		}
		$receiver = $this->messageHub->getReceiver($args["to"]);
		if (!isset($receiver)) {
			$sendto->reply("Unknown target <highlight>{$args["to"]}<end>.");
			return;
		}
		/** @Collection<MessageEmitter> */
		$senders = new Collection($this->messageHub->getEmitters());
		$hasSender = $senders->first(function(MessageEmitter $e) use ($args) {
			return fnmatch($e->getChannelName(), $args["from"], FNM_CASEFOLD)
				|| fnmatch($args["from"], $e->getChannelName(), FNM_CASEFOLD);
		});
		if (!isset($hasSender)) {
			$sendto->reply("No message source for <highlight>{$args['from']}<end> found.");
			return;
		}
		$route = new Route();
		$route->source = $args["from"];
		$route->destination = $args["to"];
		if ($args["direction"] === "<->" || $args["direction"] === "&lt;-&gt;") {
			$route->two_way = true;
			$receiver = $this->messageHub->getReceiver($args["from"]);
			if (!isset($receiver)) {
				$sendto->reply("Unable to route to <highlight>{$args["from"]}<end>.");
				return;
			}
		}
		if (isset($args["modifiers"])) {
			$parser = new ModifierExpressionParser();
			try {
				$modifiers = $parser->parse($args["modifiers"]);
			} catch (ModifierParserException $e) {
				$sendto->reply($e->getMessage());
				return;
			}
		}
		$this->db->beginTransaction();
		try {
			$route->id = $this->db->insert($this->messageHub::DB_TABLE_ROUTES, $route);
			foreach ($modifiers as $modifier) {
				$modifier->route_id = $route->id;
				$modifier->id = $this->db->insert(
					$this->messageHub::DB_TABLE_ROUTE_MODIFIER,
					$modifier
				);
				foreach ($modifier->arguments as $argument) {
					$argument->route_modifier_id = $modifier->id;
					$argument->id = $this->db->insert(
						$this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT,
						$argument
					);
				}
				$route->modifiers []= $modifier;
			}
		} catch (Throwable $e) {
			$this->db->rollback();
			$sendto->reply("Error saving the route: " . $e->getMessage());
			return;
		}
		$modifiers = [];
		foreach ($route->modifiers as $modifier) {
			$modifiers []= $modifier->toString();
		}
		try {
			$msgRoute = $this->messageHub->createMessageRoute($route);
		} catch (Exception $e) {
			$this->db->rollback();
			$sendto->reply($e->getMessage());
			return;
		}
		$this->db->commit();
		$this->messageHub->addRoute($msgRoute);
		$sendto->reply(
			"Route added from <highlight>{$args["from"]}<end> ".
			"to <highlight>{$args["to"]}<end>".
			(count($modifiers) ? " using " : "").
			join(" ", $modifiers)  . "."
		);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list (?:from|sources?|src)$/i")
	 */
	public function routeListFromCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$emitters = $this->messageHub->getEmitters();
		$count = count($emitters);
		ksort($emitters);
		$emitters = new Collection($emitters);
		$blob = $emitters->groupBy([$this, "getEmitterType"])
			->map([$this, "renderEmitterGroup"])
			->join("\n\n");
		$msg = $this->text->makeBlob("Message sources ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list (?:to|dsts?|dests?|destinations?)$/i")
	 */
	public function routeListToCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$receivers = $this->messageHub->getReceivers();
		$count = count($receivers);
		ksort($receivers);
		$receivers = new Collection($receivers);
		$blob = $receivers->groupBy([$this, "getEmitterType"])
			->map([$this, "renderEmitterGroup"])
			->join("\n\n");
		$msg = $this->text->makeBlob("Message targets ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list (?:mod|mods|modifiers?)$/i")
	 */
	public function routeListModifiersCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$mods = $this->messageHub->modifiers;
		$count = count($mods);
		if (!$count) {
			$sendto->reply("No message modifiers available.");
			return;
		}
		$blobs = [];
		foreach ($mods as $mod) {
			$description = $mod->description ?? "Someone forgot to add a description";
			$entry = "<header2>{$mod->name}<end>\n".
				"<tab><i>".
				join("\n<tab>", explode("\n", trim($description))).
				"</i>\n".
				"<tab>[" . $this->text->makeChatcmd("details", "/tell <myname> route list mod {$mod->name}") . "]";
			$blobs []= $entry;
		}
		$blob = join("\n\n", $blobs);
		$msg = $this->text->makeBlob("Message modifiers ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list (?:mod|modifier) (.+)$/i")
	 */
	public function routeListModifierCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$mod = $this->messageHub->modifiers[$args[1]];
		if (!isset($mod)) {
			$sendto->reply("No message modifier <highlight>{$args[1]}<end> found.");
			return;
		}
		try {
			$refClass = new ReflectionClass($mod->class);
		} catch (ReflectionException $e) {
			$sendto->reply("The modifier <highlight>{$args[1]}<end> cannot be initialized.");
			return;
		}
		try {
			$refConstr = $refClass->getMethod("__construct");
			$refParams = $refConstr->getParameters();
		} catch (ReflectionException $e) {
			$refParams = [];
		}
		$description = $mod->description ?? "Someone forgot to add a description";
		$blob = "<header2>Description<end>\n".
			"<tab>" . join("\n<tab>", explode("\n", trim($description))).
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
							$blob .= " (optional, default=".
								json_encode(
									$refParams[$parNum]->getDefaultValue(),
									JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE
								) . ")";
						} catch (JsonException $e) {
							$blob .= " (optional)";
						}
					} else {
						$blob .= " (optional)";
					}
				}
				$parNum++;
				$blob .= "\n<tab><i>".
					join("</i>\n<tab><i>", explode("\n", $param->description ?? "No description")).
					"</i>\n\n";
			}
		}
		$msg = $this->text->makeBlob("{$mod->name}", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route (?:del|rem) (\d+)$/i")
	 */
	public function routeDel(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		$route = $this->getRoute($id);
		if (!isset($route)) {
			$sendto->reply("No route <highlight>#{$id}<end> found.");
			return;
		}
		/** @var int[] List of modifier-ids for the route */
		$modifiers = array_column($route->modifiers, "id");
		$this->db->beginTransaction();
		try {
			if (count($modifiers)) {
				$this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT)
					->whereIn("route_modifier_id", $modifiers)
					->delete();
				$this->db->table($this->messageHub::DB_TABLE_ROUTE_MODIFIER)
					->where("route_id", $id)
					->delete();
			}
			$this->db->table($this->messageHub::DB_TABLE_ROUTES)
				->delete($id);
		} catch (Throwable $e) {
			$this->db->rollback();
			$sendto->reply("Error deleting the route: " . $e->getMessage());
			return;
		}
		$this->db->commit();
		$deleted = $this->messageHub->deleteRouteID($id);
		if (isset($deleted)) {
			$sendto->reply(
				"Route #{$id} (" . $this->renderRoute($deleted) . ") deleted."
			);
		} else {
			$sendto->reply("Route <highlight>#${id}<end> deleted.");
		}
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route list$/i")
	 */
	public function routeList(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$routes = $this->messageHub->getRoutes();
		if (empty($routes)) {
			$sendto->reply("There are no routes defined.");
			return;
		}
		$list = [];
		usort($routes, function(MessageRoute $route1, MessageRoute $route2): int {
			return strcmp($route1->getSource(), $route2->getSource());
		});
		foreach ($routes as $route) {
			$delLink = $this->text->makeChatcmd("delete", "/tell <myname> route del " . $route->getID());
			$list []="[{$delLink}] " . $this->renderRoute($route);
		}
		$blob = "<header2>Active routes<end>\n<tab>";
		$blob .= join("\n<tab>", $list);
		$msg = $this->text->makeBlob("Message Routes (" . count($routes) . ")", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route$/i")
	 * @Matches("/^route tree$/i")
	 */
	public function routeList2(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$routes = $this->messageHub->getRoutes();
		if (empty($routes)) {
			$sendto->reply("There are no routes defined.");
			return;
		}
		$grouped = [];
		foreach ($routes as $route) {
			$dests = [strtolower($route->getDest())];
			if ($route->getTwoWay()) {
				$dests []= $route->getSource();
			}
			foreach ($dests as $dest) {
				if (!isset($dest) || $this->messageHub->getReceiver($dest) === null) {
					continue;
				}
				$grouped[strtolower($dest)] ??= [];
				$grouped[strtolower($dest)] []= $route;
			}
		}
		/** @var array<string,MessageRoute[]> $grouped */
		$result = [];
		foreach ($grouped as $receiver => $recRoutes) {
			$result[$receiver] = [];
			foreach ($recRoutes as $route) {
				$delLink = $this->text->makeChatcmd("delete", "/tell <myname> route del " . $route->getID());
				$arrow = "&lt;-";
				if ($route->getTwoWay() && $this->messageHub->getReceiver($route->getSource()) !== null) {
					$arrow .= "&gt;";
				} else {
					$arrow .= "<black>><end>";
				}
				$routeName = $route->getSource();
				if ($route->getTwoWay() && ($route->getSource() === $receiver)) {
					$routeName = $route->getDest();
				}
				$result[$receiver][$routeName] ??= [];
				$result[$receiver][$routeName] []= "<tab>{$arrow} [{$delLink}] <highlight>{$routeName}<end> ".
					join(" ", $route->renderModifiers());
			}
		}
		$blobs = [];
		ksort($result);
		foreach ($result as $receiver => $senders) {
			ksort($senders);
			$blob = "<header2>{$receiver}<end>\n";
			foreach ($senders as $sender => $lines) {
				$blob .= join("\n", $lines) . "\n";
			}
			$blobs []= $blob;
		}
		$blob = join("\n", $blobs);
		$msg = $this->text->makeBlob("Message Routes (" . count($routes) . ")", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route color$/i")
	 */
	public function routeListColorConfigCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$colors = $this->messageHub::$colors;
		if ($colors->isEmpty()) {
			$sendto->reply("No colors have been defined yet.");
			return;
		}
		$blob = "<header2>Color definitions<end>\n";
		$blobs = [];
		foreach ($colors as $color) {
			$remCmd = $this->text->makeChatcmd(
				"clear",
				"/tell <myname> route color tag rem {$color->hop}"
			);
			$pickCmd = $this->text->makeChatcmd(
				"pick",
				"/tell <myname> route color tag pick {$color->hop}"
			);
			$part = "<tab><highlight>{$color->hop}<end>\n".
				"<tab><tab>Tag: ";
			if (isset($color->tag_color)) {
				$part .= "<font color=#{$color->tag_color}>#{$color->tag_color}</font>".
					" [{$pickCmd}] [{$remCmd}]\n";
			} else {
				$part .= "&lt;unset&gt; [{$pickCmd}]\n";
			}

			$remCmd = $this->text->makeChatcmd(
				"clear",
				"/tell <myname> route color text rem {$color->hop}"
			);
			$pickCmd = $this->text->makeChatcmd(
				"pick",
				"/tell <myname> route color text pick {$color->hop}"
			);
			$part .= "<tab><tab>Text: ";
			if (isset($color->text_color)) {
				$part .= "<font color=#{$color->text_color}>#{$color->text_color}</font>".
					" [{$pickCmd}] [{$remCmd}]\n";
			} else {
				$part .= "&lt;unset&gt; [{$pickCmd}]\n";
			}

			$blobs []= $part;
		}
		$blob .= join("\n", $blobs);
		$blob .= "\n\n".
			"You can specify colors for any of the types and names listed ".
			"at with <highlight><symbol>route list src<end>.\n".
			"Types are 'system', 'aopriv', etc. and names are specific sub-".
			"parts of these, like 'gsp' in 'system(gsp)'.\n\n".
			"Set the colors with\n".
			"<tab><highlight><symbol>route color tag pick type(name)<end> or\n".
			"<tab><highlight><symbol>route color text pick type(name)<end>.";
		$msg = $this->text->makeBlob(
			"Routing colors (" . count($colors) . ")",
			$blob
		);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route color tag (?:rem|del|remove|delete|rm) (?<tag>.+)$/i")
	 */
	public function routeTagColorRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$color = $this->getHopColor($args['tag']);
		if (!isset($color) || !isset($color->tag_color)) {
			$sendto->reply("No tag color for <highlight>[{$args['tag']}]<end> defined.");
			return;
		}
		if (isset($color->text_color)) {
			$color->tag_color = null;
			$this->db->update($this->messageHub::DB_TABLE_COLORS, "id", $color);
			$sendto->reply("Tag color definition for <highlight>[{$args['tag']}] deleted.");
			return;
		}
		$this->db->table($this->messageHub::DB_TABLE_COLORS)
			->delete($color->id);
		$this->messageHub->loadTagColor();
		$sendto->reply("Color definition for <highlight>[{$args['tag']}] deleted.");
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route color text (?:rem|del|remove|delete|rm) (?<tag>.+)$/i")
	 */
	public function routeTextColorRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$color = $this->getHopColor($args['tag']);
		if (!isset($color) || !isset($color->text_color)) {
			$sendto->reply("No text color for <highlight>[{$args['tag']}]<end> defined.");
			return;
		}
		if (isset($color->tag_color)) {
			$color->text_color = null;
			$this->db->update($this->messageHub::DB_TABLE_COLORS, "id", $color);
			$sendto->reply("Text color definition for <highlight>[{$args['tag']}] deleted.");
			return;
		}
		$this->db->table($this->messageHub::DB_TABLE_COLORS)
			->delete($color->id);
		$this->messageHub->loadTagColor();
		$sendto->reply("Color definition for <highlight>[{$args['tag']}] deleted.");
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route color (?<type>tag|text) set (?<tag>[^ ]+) #?(?<color>[0-9a-f]{6})$/i")
	 */
	public function routeSetColorCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$tag = strtolower($args['tag']);
		$type = strtolower($args['type']);
		$color = strtoupper($args['color']);
		if (strlen($tag) > 25) {
			$sendto->reply("Your tag is longer than the supported 25 characters.");
			return;
		}
		$colorDef = $this->getHopColor($tag);
		if (!isset($colorDef)) {
			$colorDef = new RouteHopColor();
			$colorDef->hop = $tag;
		}
		if ($type === "text") {
			$colorDef->text_color = $color;
		} else {
			$colorDef->tag_color = $color;
		}
		$table = $this->messageHub::DB_TABLE_COLORS;
		if (isset($colorDef->id)) {
			$this->db->update($table, "id", $colorDef);
		} else {
			$colorDef->id = $this->db->insert($table, $colorDef);
			$this->messageHub->loadTagColor();
		}
		$sendto->reply(
			ucfirst(strtolower($type)) . " color for ".
			"<highlight>[{$tag}]<end> set to ".
			"<font color='#{$color}'>#{$color}</font>."
		);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route color (?<type>text|tag) pick (?<tag>.+)$/i")
	 */
	public function routeColorPickCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$tag = strtolower($args['tag']);
		$type = strtolower($args['type']);
		if (strlen($tag) > 25) {
			$sendto->reply("Your tag name is too long.");
			return;
		}
		$colorList = ColorSettingHandler::getExampleColors();
		$blob = "<header2>Pick a {$type} color for [{$tag}]<end>\n";
		foreach ($colorList as $color => $name) {
			$blob .= "<tab>[<a href='chatcmd:///tell <myname> route color {$type} set {$tag} {$color}'>Pick this one</a>] <font color='{$color}'>Example Text</font> ({$name})\n";
		}
		$msg = $this->text->makeBlob(
			"Choose from colors (" . count($colorList) . ")",
			$blob
		);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route format$/i")
	 */
	public function routeListFormatCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$formats = Source::$format;
		if ($formats->isEmpty()) {
			$sendto->reply("No formats have been defined yet.");
			return;
		}
		$blob = "<header2>Format definitions<end>\n";
		$blobs = [];
		foreach ($formats as $format) {
			$remCmd = $this->text->makeChatcmd(
				"clear",
				"/tell <myname> route format rem {$format->hop}"
			);
			if ($format->render) {
				$switchCmd = $this->text->makeChatcmd(
					"disable",
					"/tell <myname> route format render {$format->hop} false"
				);
			} else {
				$switchCmd = $this->text->makeChatcmd(
					"enable",
					"/tell <myname> route format render {$format->hop} true"
				);
			}
			$part = "<tab><highlight>{$format->hop}<end> [{$remCmd}]\n".
				"<tab><tab>Render: <highlight>".
				($format->render ? "true" : "false") . "<end> [{$switchCmd}]\n".
				"<tab><tab>Display: <highlight>{$format->format}<end>\n";

			$blobs []= $part;
		}
		$blob .= join("\n", $blobs);
		$blob .= "\n\n".
			"You can specify the format for any of the types and names listed ".
			"at with <highlight><symbol>route list src<end>.\n".
			"Types are 'system', 'aopriv', etc. and names are specific sub-".
			"parts of these, like 'gsp' in 'system(gsp)'.\n\n".
			"Set the format with\n".
			"<tab><highlight><symbol>route format render type(name) false<end> or\n".
			"<tab><highlight><symbol>route format display type(name) gsp:%s<end>.";
		$msg = $this->text->makeBlob(
			"Routing formats (" . count($formats) . ")",
			$blob
		);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route format (clear|del|rem|rm|reset) (?<hop>.+)$/i")
	 */
	public function routeFormatClearCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->clearHopFormat($args['hop'])) {
			$sendto->reply("No format defined for <highlight>{$args['hop']}<end>.");
			return;
		}
		$sendto->reply("Format cleared for <highlight>{$args['hop']}<end>.");
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route format render (?<hop>.+) (?<render>true|false)$/i")
	 */
	public function routeFormatChangeRenderCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->setHopRender($args['hop'], $args['render'] === 'true');
		$sendto->reply("Format saved.");
	}

	/**
	 * @HandlesCommand("route")
	 * @Matches("/^route format display (?<hop>[^ ]+) (?<format>.+)$/i")
	 * @Matches("/^route format display (?<hop>[^ ]+\(.*?\)) (?<format>.+)$/i")
	 */
	public function routeFormatChangeDisplayCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		try {
			$this->setHopDisplay($args['hop'], $args['format']);
		} catch (Exception $e) {
			$sendto->reply($e->getMessage());
			return;
		}
		$sendto->reply("Display format saved.");
	}

	/** Turn on/off rendering of a specific hop */
	public function setHopRender(string $hop, bool $state): void {
		/** @var ?RouteHopFormat */
		$format = Source::$format->first(fn($x) => $x->hop === $hop);
		$update = true;
		if (!isset($format)) {
			$format = new RouteHopFormat();
			$format->hop = $hop;
			$format->format = '%s';
			$update = false;
		}
		$format->render = $state;
		if ($update) {
			$this->db->update(Source::DB_TABLE, "id", $format);
		} else {
			$format->id = $this->db->insert(Source::DB_TABLE, $format);
			$this->messageHub->loadTagFormat();
		}
	}

	/** Define how to render a specific hop */
	public function setHopDisplay(string $hop, string $format): void {
		if (preg_match("/%[^%]/", $format) && @sprintf($format, "text") === false) {
			throw new Exception("Invalid format string given.");
		}
		$spec = Source::$format->first(fn($x) => $x->hop === $hop);
		/** @var RouteHopFormat $format */
		$update = true;
		if (!isset($spec)) {
			$spec = new RouteHopFormat();
			$spec->hop = $hop;
			$update = false;
		}
		$spec->format = $format;
		if ($update) {
			$this->db->update(Source::DB_TABLE, "id", $spec);
		} else {
			$spec->id = $this->db->insert(Source::DB_TABLE, $spec);
			$this->messageHub->loadTagFormat();
		}
	}

	public function clearHopFormat(string $hop): bool {
		/** @var ?RouteHopFormat */
		$format = Source::$format->first(fn($x) => $x->hop === $hop);
		if (!isset($format)) {
			return false;
		}
		if ($this->db->table(Source::DB_TABLE)->delete($format->id) === 0) {
			return false;
		}
		$this->messageHub->loadTagFormat();
		return true;
	}

	public function getHopColor(string $hop): ?RouteHopColor {
		return $this->messageHub::$colors
			->first(fn(RouteHopColor $x) => $x->hop === $hop);
	}

	public function getRoute(int $id): ?Route {
		/** @var Route|null */
		$route = $this->db->table($this->messageHub::DB_TABLE_ROUTES)
			->where("id", $id)
			->limit(1)
			->asObj(Route::class)
			->first();
		if (!isset($route)) {
			return null;
		}
		$route->modifiers = $this->db->table(
			$this->messageHub::DB_TABLE_ROUTE_MODIFIER
		)
		->where("route_id", $id)
		->orderBy("id")
		->asObj(RouteModifier::class)
		->toArray();
		foreach ($route->modifiers as $modifier) {
			$modifier->arguments = $this->db->table(
				$this->messageHub::DB_TABLE_ROUTE_MODIFIER_ARGUMENT
			)
			->where("route_modifier_id", $modifier->id)
			->orderBy("id")
			->asObj(RouteModifierArgument::class)
			->toArray();
		}
		return $route;
	}

	/** Render the route $route into a single line of text */
	public function renderRoute(MessageRoute $route): string {
		$from = $route->getSource();
		$to = $route->getDest();
		$direction = $route->getTwoWay() ? "&lt;-&gt;" : "-&gt;";
		return "<highlight>{$from}<end> {$direction} <highlight>{$to}<end> ".
			join(" ", $route->renderModifiers());
	}

	/** Get the type (the part before the bracket) of an emitter */
	public function getEmitterType(MessageEmitter $emitter): string {
		$type = $emitter->getChannelName();
		$bracket = strpos($type, "(");
		if ($bracket === false) {
			return $type;
		}
		return substr($type, 0, $bracket);
	}

	/** Render a blob for an emitter group */
	public function renderEmitterGroup(Collection $values, string $group): string {
		return "<header2>{$group}<end>\n<tab>".
			$values->map(fn(MessageEmitter $emitter) => $emitter->getChannelName())
				->join("\n<tab>");
	}
}

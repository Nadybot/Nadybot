<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance
 * @package Nadybot\Core
 */
class StdinHandler {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public Nadybot $chatBot;

	public SocketNotifier $notifier;
	public $socket;

	public function getCacheFile(): string {
		if (isset($_SERVER["XDG_CACHE_HOME"])) {
			return explode(":", $_SERVER["XDG_CACHE_HOME"])[0] . "/Nadybot/readline.history";
		}
		if (isset($_SERVER["HOME"])) {
			return $_SERVER["HOME"] . "/.cache/Nadybot/readline.history";
		}
		return sys_get_temp_dir() . "/Nadybot/readline.history";
	}

	public function loadHistory(): bool {
		$file = $this->getCacheFile();
		if (!@file_exists($file)) {
			return false;
		}
		return readline_read_history($file);
	}

	public function saveHistory(): bool {
		$file = $this->getCacheFile();
		if (!@file_exists($file)) {
			if (!@mkdir(dirname($file), 0700, true)) {
				return false;
			}
		}
		return readline_write_history($file);
	}

	/**
	 * @Setup
	 */
	public function setup(): void {
		if (!$this->chatBot->vars["enable_console_client"]) {
			return;
		}
		$this->loadHistory();
		$this->socket = STDIN;
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			function() {
				readline_callback_read_char();
			}
		);
		$this->socketManager->addSocketNotifier($this->notifier);
		readline_callback_handler_install('> ', [$this, 'processStdin']);
	}

	public function processStdin(?string $line): void {
		if ($line === null || trim($line) === '') {
			return;
		}
		readline_add_history($line);
		$this->saveHistory();
		$handler = new StdinCommandReply($this->chatBot);
		$this->commandManager->process("msg", $line, $this->chatBot->vars["SuperAdmin"], $handler);
	}
}
<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\CmdCfg;

class ModuleSubommand {
	public const TYPE_COMMAND = "cmd";
	public const TYPE_SUBCOMMAND = "subcmd";

	/** The string or regexp that has to match this command */
	public string $command;

	/** Either "cmd" or "subcmd" */
	public string $type;

	/**
	 * The access level you need to have
	 * in order to be allowed to use this command
	 */
	public string $access_level = "all";

	/** Is this command enabled? */
	public bool $enabled = false;

	/** Can this command be enabled in org channel? */
	public bool $org_avail = false;

	/** Is this command enabled in org channel? */
	public bool $org_enabled = false;

	/** Can this command be enabled in priv channel? */
	public bool $priv_avail = false;

	/** Is this command enabled in priv channel? */
	public bool $priv_enabled = false;

	/** Can this command be enabled in direct messages? */
	public bool $msg_avail = false;

	/** Is this command enabled in direct messages? */
	public bool $msg_enabled = false;

	public function __construct(CmdCfg $cfg) {
		$this->command = $cfg->cmd;
		$this->type = $cfg->cmdevent;
		$this->access_level = $cfg->admin;
		$this->enabled = (bool)$cfg->status;
		$this->org_avail = (bool)($cfg->guild_avail??false);
		$this->priv_avail = (bool)($cfg->priv_avail??false);
		$this->msg_avail = (bool)($cfg->msg_avail??false);
		$this->org_enabled = (bool)($cfg->guild_status??false);
		$this->priv_enabled = (bool)($cfg->priv_status??false);
		$this->msg_enabled = (bool)($cfg->msg_status??false);
	}
}

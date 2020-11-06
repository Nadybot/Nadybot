<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\CmdCfg;

class ModuleSubcommand {
	public const TYPE_COMMAND = "cmd";
	public const TYPE_SUBCOMMAND = "subcmd";

	/** The string or regexp that has to match this command */
	public string $command;

	/** Either "cmd" or "subcmd" */
	public string $type;

	/** A short description of the command */
	public string $description;

	/** Settings for tells */
	public ?ModuleSubcommandChannel $msg;

	/** Settings for private channel */
	public ?ModuleSubcommandChannel $priv;

	/** Settings for org channel */
	public ?ModuleSubcommandChannel $org;

	public function __construct(CmdCfg $cfg) {
		$this->command = $cfg->cmd;
		$this->type = $cfg->cmdevent;
		$this->description = $cfg->description;
		if ($cfg->guild_avail??false) {
			$this->org = new ModuleSubcommandChannel();
			$this->org->access_level = $cfg->guild_al??"none";
			$this->org->enabled = (bool)($cfg->guild_status??0);
		}
		if ($cfg->priv_avail??false) {
			$this->priv = new ModuleSubcommandChannel();
			$this->priv->access_level = $cfg->priv_al??"none";
			$this->priv->enabled = (bool)($cfg->priv_status??0);
		}
		if ($cfg->msg_avail??false) {
			$this->msg = new ModuleSubcommandChannel();
			$this->msg->access_level = $cfg->msg_al??"none";
			$this->msg->enabled = (bool)($cfg->msg_status??0);
		}
	}
}

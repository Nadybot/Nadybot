<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
	Util,
};
use ParseError;

/**
 * @author Tyrence (RK2)
 * @author Neksus (RK2)
 * @author Mdkdoc420 (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'dyna',
 *		accessLevel = 'all',
 *		description = 'Search for RK Dynabosses',
 *		help        = 'dyna.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'oe',
 *		accessLevel = 'all',
 *		description = 'Over-equipped calculation',
 *		help        = 'oe.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'calc',
 *		accessLevel = 'all',
 *		description = 'Calculator',
 *		help        = 'calculator.txt'
 *	)
 */
class HelpbotController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'dyna');
	}
	
	/**
	 * @HandlesCommand("dyna")
	 * @Matches("/^dyna ([0-9]+)$/i")
	 */
	public function dynaLevelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = $args[1];
		$range1 = $search - 25;
		$range2 = $search + 25;
		/** @var DynaDB[] */
		$data = $this->db->fetchAll(
			DynaDB::class,
			"SELECT * FROM dynadb d ".
			"JOIN playfields p ON d.playfield_id = p.id ".
			"WHERE minQl > ? AND minQl < ? ORDER BY `minQl`",
			$range1,
			$range2
		);
		$count = count($data);

		$blob = "Results of Dynacamp Search for '$search'\n\n";

		$blob .= $this->formatResults($data);
		
		$blob .= "Dyna camp information taken from CSP help files: http://creativestudent.com/ao/files-helpfiles.html";

		$msg = $this->text->makeBlob("Dynacamps ($count)", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("dyna")
	 * @Matches("/^dyna (.+)$/i")
	 */
	public function dynaNameCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = str_replace(" ", "%", $args[1]);
		$data = $this->db->query(
			"SELECT * ".
			"FROM dynadb d ".
			"JOIN playfields p ON d.playfield_id = p.id ".
			"WHERE long_name LIKE ? OR short_name LIKE ? OR mob LIKE ? ".
			"ORDER BY `minQl`",
			"%{$search}%",
			"%{$search}%",
			"%{$search}%"
		);
		$count = count($data);

		$blob = "Results of Dynacamp Search for '$search'\n\n";

		$blob .= $this->formatResults($data);
		
		$blob .= "Dyna camp information taken from CSP help files: http://creativestudent.com/ao/files-helpfiles.html";

		$msg = $this->text->makeBlob("Dynacamps ($count)", $blob);
		$sendto->reply($msg);
	}
	
	private function formatResults(array $data): string {
		$blob = '';
		foreach ($data as $row) {
			$coordLink = $this->text->makeChatcmd("{$row->long_name} {$row->cX}x{$row->cY}", "/waypoint $row->cX $row->cY $row->playfield_id");
			$blob .="<pagebreak>$coordLink\n";
			$blob .="$row->mob - Level <highlight>{$row->minQl}-{$row->maxQl}<end>\n\n";
		}
		return $blob;
	}
	
	/**
	 * @HandlesCommand("oe")
	 * @Matches("/^oe ([0-9]+)$/i")
	 */
	public function oeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$oe = (int)$args[1];
		$oe100 = (int)floor($oe / 0.8);
		$lowOE100 = (int)floor($oe * 0.8);
		$oe75 = (int)floor($oe / 0.6);
		$lowOE75 = (int)floor($oe * 0.6);
		$oe50 = (int)floor($oe / 0.4);
		$lowOE50 = (int)floor($oe * 0.4);
		$oe25 = (int)floor($oe / 0.2);
		$lowOE25 = (int)floor($oe * 0.2);

		$blob = "With a skill requirement of <highlight>$oe<end>, you will be\n".
			"Out of OE: <highlight>$lowOE100<end> or higher\n".
			"75%: <highlight>$lowOE75<end> - <highlight>" .($lowOE100 - 1). "<end>\n".
			"50%: <highlight>" .($lowOE50 + 1). "<end> - <highlight>" .($lowOE75 - 1). "<end>\n".
			"25%: <highlight>" .($lowOE25 + 1). "<end> - <highlight>$lowOE50<end>\n".
			"<black>0<end>0%: <highlight>$lowOE25<end> or lower\n\n".
			"With a personal skill of <highlight>$oe<end>, you can use up to and be\n".
			"Out of OE: <highlight>$oe100<end> or lower\n".
			"75%: <highlight>" .($oe100 + 1). "<end> - <highlight>$oe75<end>\n".
			"50%: <highlight>" .($oe75 + 1). "<end> - <highlight>" .($oe50 - 1). "<end>\n".
			"25%: <highlight>$oe50<end> - <highlight>" .($oe25 - 1). "<end>\n".
			"<black>0<end>0%: <highlight>$oe25<end> or higher\n\n".
			"WARNING: May be plus/minus 1 point!";

		$msg = "<highlight>{$lowOE100}<end> - {$oe} - <highlight>{$oe100}<end> ".
			$this->text->makeBlob('More info', $blob, 'Over-equipped Calculation');

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("calc")
	 * @Matches("/^calc (.+)$/i")
	 */
	public function calcCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$calc = strtolower($args[1]);

		// check if the calc string includes not allowed chars
		$numValidChars = strspn($calc, "0123456789.+-*%()/\\ ");

		if ($numValidChars !== strlen($calc)) {
			$sendto->reply("Cannot compute.");
			return;
		}
		try {
			$result = 0;
			$calc = "\$result = ".$calc.";";
			eval($calc);
			
			$result = round($result, 4);
		} catch (ParseError $e) {
			$sendto->reply("Cannot compute.");
			return;
		}
		$msg = $args[1]." = <highlight>".$result."<end>";
		$sendto->reply($msg);
	}
}

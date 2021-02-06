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
		$range1 = (int)floor($search - $search / 10);
		$range2 = (int)ceil($search + $search / 10);
		/** @var DynaDB[] */
		$data = $this->db->fetchAll(
			DynaDB::class,
			"SELECT * FROM dynadb d ".
			"JOIN playfields p ON d.playfield_id = p.id ".
			"WHERE maxQl >= ? AND minQl <= ? ORDER BY p.`long_name` ASC, `minQl` ASC",
			$range1,
			$range2
		);
		$count = count($data);
		if (!$count) {
			$sendto->reply(
				"No dynacamps found between level <highlight>{$range1}<end> ".
				"and <highlight>{$range2}<end>."
			);
			return;
		}

		$blob = "Results of Dynacams level <highlight>{$range1}<end>-<highlight>{$range2}<end>\n\n";

		$blob .= $this->formatResults($data);

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
			"FROM `dynadb` d ".
			"JOIN `playfields` p ON d.`playfield_id` = p.`id` ".
			"WHERE `long_name` LIKE ? OR `short_name` LIKE ? OR `mob` LIKE ? ".
			"ORDER BY p.`long_name` ASC, `minQl` ASC",
			"%{$search}%",
			"%{$search}%",
			"%{$search}%"
		);
		$count = count($data);

		if (!$count) {
			$sendto->reply("No dyna names or locations matched <highlight>{$args[1]}<end>.");
			return;
		}
		$blob = "Results of Dynacamp Search for <highlight>{$args[1]}<end>\n\n";

		$blob .= $this->formatResults($data);

		$msg = $this->text->makeBlob("Dynacamps ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Format the dynacamp results as a blob for a popup
	 */
	private function formatResults(array $data): string {
		$blob = '';
		$lastPF = '';
		foreach ($data as $row) {
			if ($lastPF !== $row->long_name) {
				if ($lastPF !== '') {
					$blob .= "\n";
				}
				$blob .= "<pagebreak><header2>{$row->long_name}<end>\n";
				$lastPF = $row->long_name;
			}
			$coordLink = $this->text->makeChatcmd("{$row->cX}x{$row->cY}", "/waypoint $row->cX $row->cY $row->playfield_id");
			$range = "{$row->minQl}-{$row->maxQl}";
			if (strlen($range) < 7) {
				$range = "<black>" . str_repeat("_", 7 - strlen($range)) . "<end>{$range}";
			}
			$blob .= "<tab>{$range}: <highlight>{$row->mob}<end> at $coordLink\n";
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
		$numValidChars = strspn($calc, "0123456789.+^-*%()/\\ ");

		if ($numValidChars !== strlen($calc)) {
			$sendto->reply("Cannot compute.");
			return;
		}
		$calc = str_replace("^", "**", $calc);
		try {
			$result = 0;
			$calc = "\$result = ".$calc.";";
			eval($calc);

			$result = preg_replace("/\.?0+$/", "", number_format(round($result, 4), 4));
			$result = str_replace(",", "<end>,<highlight>", $result);
		} catch (ParseError $e) {
			$sendto->reply("Cannot compute.");
			return;
		}
		preg_match_all("{(\d*\.?\d+|[+%()/-^]|\*+)}", $args[1], $matches);
		$expression = join(" ", $matches[1]);
		$expression = str_replace(["* *", "( ", " )", "*"], ["^", "(", ")", "×"], $expression);
		$expression = preg_replace("/(\d+)/", "<cyan>$1<end>", $expression);

		$msg ="{$expression} = <highlight>{$result}<end>";
		$sendto->reply($msg);
	}
}

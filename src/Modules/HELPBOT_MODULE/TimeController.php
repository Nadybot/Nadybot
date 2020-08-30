<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use stdClass;
use DateTime;
use Nadybot\Core\CommandReply;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'time',
 *		accessLevel = 'all',
 *		description = 'Show the time in the different timezones',
 *		help        = 'time.txt'
 *	)
 */
class TimeController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/**
	 * @HandlesCommand("time")
	 * @Matches("/^time$/i")
	 */
	public function timeListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$link  = "<header2>Australia<end>\n";
		$link .= "<tab><highlight>Western Australia<end>\n";
		$link .= "<tab><tab>Standard Time (AWST = GMT +8:00): " . $this->getTimezone("AWST")->time . "\n";
		$link .= "<tab><highlight>Northern Territory/South Australia<end>\n";
		$link .= "<tab><tab>Standard Time (ACST = UTC+9:30): " . $this->getTimezone("ACST")->time . "\n";
		$link .= "<tab><tab>Summer Time (ACDT = UTC+10:30): " . $this->getTimezone("ACDT")->time . "\n";
		$link .= "<tab><highlight>Queensland/Victoria/Tasmania<end>\n";
		$link .= "<tab><tab>Standard Time (AEST = UTC+10): " . $this->getTimezone("AEST")->time . "\n";
		$link .= "<tab><tab>Summer Time (AEDT = UTC+11): " . $this->getTimezone("AEDT")->time . "\n\n";

		$link .= "<header2>Asia<end>\n";
		$link .= "<tab><highlight>Thailand/Vietnam/Kambodscha<end>\n";
		$link .= "<tab><tab>Standard Time (ICT = UTC+7): " . $this->getTimezone("ICT")->time . "\n";
		$link .= "<tab><highlight>China/Malaysia/Singapur/Indonesien<end>\n";
		$link .= "<tab><tab>Standard Time (CST = UTC+8): " . $this->getTimezone("CCST")->time . "\n";
		$link .= "<tab><highlight>Japan/Korea<end>\n";
		$link .= "<tab><tab>Standard Time (JST = UTC+9): " . $this->getTimezone("JST")->time . "\n\n";

		$link .= "<header2>Europe<end>\n";
		$link .= "<tab><highlight>England,Spain,Portugal<end>\n";
		$link .= "<tab><tab>Standard Time (GMT = UTC): " . $this->getTimezone("GMT")->time . "\n";
		$link .= "<tab><tab>Summer Time (BST = UTC+1): " . $this->getTimezone("BST")->time . "\n";
		$link .= "<tab><highlight>Germany/France/Netherlands/Italy/Norway<end>\n";
		$link .= "<tab><tab>Standard Time (CET = UTC+1): " . $this->getTimezone("CET")->time . "\n";
		$link .= "<tab><tab>Summer Time (CEST = UTC+2): " . $this->getTimezone("CEST")->time . "\n";
		$link .= "<tab><highlight>Egypt/Bulgary/Finland/Greece<end>\n";
		$link .= "<tab><tab>Standard Time (EET = UTC+2): " . $this->getTimezone("EET")->time . "\n";
		$link .= "<tab><tab>Summer Time (EEST/EEDT = UTC+3): " . $this->getTimezone("EEST")->time . "\n";
		$link .= "<tab><highlight>Bahrain/Iraq/Russia/Saudi Arabia<end>\n";
		$link .= "<tab><tab>Standard Time (MSK = UTC+3): " . $this->getTimezone("MSK")->time . "\n";
		$link .= "<tab><tab>Summer Time (MSD = UTC+4): " . $this->getTimezone("MSD")->time . "\n\n";

		$link .= "<header2>West Asia<end>\n";
		$link .= "<tab><highlight>India (UTC+5:30)<end>: " . $this->getTimezone("IST")->time . "\n";
		$link .= "<tab><highlight>Iran (UTC+3:30)<end>: " . $this->getTimezone("IRT")->time . "\n\n";

		$link .= "<header2>North America<end>\n";
		$link .= "<tab><highlight>Newfoundland<end>\n";
		$link .= "<tab><tab>Standard Time (NST = GMT-3:30): " . $this->getTimezone("NST")->time . "\n";
		$link .= "<tab><tab>Summer Time (NDT = GMT-2:30): " . $this->getTimezone("NDT")->time . "\n";
		$link .= "<tab><highlight>Toronto<end>\n";
		$link .= "<tab><tab>Standard Time (EDT = GMT-4): " . $this->getTimezone("EDT")->time . "\n";
		$link .= "<tab><tab>Summer Time (AST = GMT-3): " . $this->getTimezone("AST")->time . "\n";
		$link .= "<tab><highlight>Florida/Indiana/New York/Maine/New Jersey/Washington D.C./Winnipeg<end>\n";
		$link .= "<tab><tab>Standard Time (EST = GMT-5): " . $this->getTimezone("EST")->time . "\n";
		$link .= "<tab><tab>Summer Time (EDT = GMT-4): " . $this->getTimezone("EDT")->time . "\n";
		$link .= "<tab><highlight>Alaska<end>\n";
		$link .= "<tab><tab>Standard Time (AKST = GMT-9): " . $this->getTimezone("AKST")->time . "\n";
		$link .= "<tab><tab>Summer Time (AKDT = GMT-8): " . $this->getTimezone("AKDT")->time . "\n";
		$link .= "<tab><highlight>California/Nevada/Washington<end>\n";
		$link .= "<tab><tab>Standard Time (PST = GMT-8): " . $this->getTimezone("PST")->time . "\n";
		$link .= "<tab><tab>Summer Time (PDT = GMT-7): " . $this->getTimezone("PDT")->time . "\n";
		$link .= "<tab><highlight>Colorado/Montana/New Mexico/Utah/Vancouver<end>\n";
		$link .= "<tab><tab>Standard Time (MST = GMT-7): " . $this->getTimezone("MST")->time . "\n";
		$link .= "<tab><tab>Summer Time (MDT = GMT-6): " . $this->getTimezone("MDT")->time . "\n";
		$link .= "<tab><highlight>Alabama/Illinois/Iowa/Michigan/Minnesota/Oklahoma/Edmonton<end>\n";
		$link .= "<tab><tab>Standard Time (CST = GMT-6): " . $this->getTimezone("CST")->time . "\n";
		$link .= "<tab><tab>Summer Time (CDT = GMT-5): " . $this->getTimezone("CDT")->time . "\n\n";
		
		$link .= "<header2>Unix time<end>\n";
		$link .= "<tab><tab>" . time() . "\n";

		$msg = "<highlight>".$this->util->date(time())."<end>";
		$msg .= " " . $this->text->makeBlob("All Timezones", $link);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("time")
	 * @Matches("/^time (.+)$/i")
	 */
	public function timeShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$zone = strtoupper($args[1]);
		$timezone = $this->getTimezone($zone);
		if ($timezone !== null) {
			$msg = $timezone->name." is <highlight>".$timezone->time."<end>";
		} else {
			$msg = "Unknown timezone.";
		}

		$sendto->reply($msg);
	}
	
	public function getTimezone($tz) {
		$date = new DateTime();
		$time = time() - $date->getOffset();
		$time_format = "dS M, H:i";

		switch ($tz) {
			case "CST":
				$name = "Central Standard Time (GMT-6)";
				$offset = -(3600*6);
				break;
			case "CDT":
				$name = "Central Daylight Time (GMT-5)";
				$offset = -(3600*5);
				break;
			case "MST":
				$name = "Mountain Standard Time (GMT-7)";
				$offset = -(3600*7);
				break;
			case "MDT":
				$name = "Mountain Daylight Time (GMT-6)";
				$offset = -(3600*6);
				break;
			case "PST":
				$name = "Pacific Standard Time (GMT-8)";
				$offset = -(3600*8);
				break;
			case "PDT":
				$name = "Pacific Daylight Time (GMT-7)";
				$offset = -(3600*7);
				break;
			case "AKST":
				$name = "Alaska Standard Time (GMT-9)";
				$offset = -(3600*9);
				break;
			case "AKDT":
				$name = "Alaska Daylight Time (GMT-8)";
				$offset = -(3600*8);
				break;
			case "AST":
				$name = "Atlantic Standard Time (GMT-6)";
				$offset = -(3600*6);
				break;
			case "EST":
				$name = "Eastern Standard Time (GMT-5)";
				$offset = -(3600*5);
				break;
			case "EDT":
				$name = "Eastern Daylight Time (GMT-4)";
				$offset = -(3600*4);
				break;
			case "NST":
				$name = "Newfoundland Standard Time (GMT-3:30)";
				$offset = -(3600*3.5);
				break;
			case "NDT":
				$name = "Newfoundland Daylight Time (GMT-2:30)";
				$offset = -(3600*2.5);
				break;
			case "UTC":
			case "GMT":
				$name = "Greenwich Mean Time (GMT / AO)";
				$offset = 0;
				break;
			case "BST":
				$name = "British Summer Time (UTC+1)";
				$offset = 3600;
				break;
			case "CET":
				$name = "Central European Time (UTC+1)";
				$offset = 3600;
				break;
			case "CEST":
				$name = "Central European Summer Time (UTC+2)";
				$offset = 3600*2;
				break;
			case "EET":
				$name = "Eastern European Time (UTC+2)";
				$offset = 3600*2;
				break;
			case "EEST":
				$name = "Eastern European Summer Time (UTC+3)";
				$offset = 3600*3;
				break;
			case "EEDT":
				$name = "Eastern European Daylight Time (UTC+3)";
				$offset = 3600*3;
				break;
			case "MSK":
				$name = "Moscow Time (UTC+3)";
				$offset = 3600*3;
				break;
			case "MSD":
				$name = "Moscow Daylight Time (UTC+4)";
				$offset = 3600*4;
				break;
			case "IRT":
				$name = "Iran Time (UTC+3:30)";
				$offset = 3600*3.5;
				break;
			case "IST":
				$name = "Indian Standard Time (UTC+5:30)";
				$offset = 3600*5.5;
				break;
			case "ICT":
				$name = "Indochina Time (UTC+7)";
				$offset = 3600*7;
				break;
			case "CCST":
				$name = "China Standard Time (UTC+8)";
				$offset = 3600*8;
				break;
			case "JST":
				$name = "Japan Standard Time (UTC+9)";
				$offset = 3600*9;
				break;
			case "AWST":
				$name = "Australian Western Standard Time (UTC+8)";
				$offset = 3600*8;
				break;
			case "ACST":
				$name = "Australian Central Standard Time (UTC+9:30)";
				$offset = 3600*9.5;
				break;
			case "ACDT":
				$name = "Australian Central Daylight Time (UTC+10:30)";
				$offset = 3600*10.5;
				break;
			case "AEST":
				$name = "Australian Eastern Standard Time (UTC+10)";
				$offset = 3600*10;
				break;
			case "AEDT":
				$name = "Australian Eastern Daylight Time (UTC+11)";
				$offset = 3600*11;
				break;
			default:
				return null;
		}

		$obj = new stdClass;
		$obj->name = $name;
		$obj->offset = $offset;
		$obj->time = date($time_format, (int)($time + $offset));
		return $obj;
	}
}

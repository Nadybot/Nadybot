<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Util,
};

/**
 * @author Neksus (RK2)
 * @author Mdkdoc420 (RK2)
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "ding",
		accessLevel: "all",
		description: "Shows a random ding gratz message",
	)
]
class DingController extends ModuleInstance {
	#[NCA\Inject]
	public Util $util;

	/** Show a random ding gratz message */
	#[NCA\HandlesCommand("ding")]
	public function ding1Command(CmdContext $context): void {
		$dingText = [
			"Yeah yeah gratz, I would give you a better response but you didn't say what level you dinged.",
			"Hmmm, I really want to know what level you dinged, but gratz anyways nub.",
			"When are you people going to start using me right! Gratz for your level though.",
			"Gratz! But what are we looking at? I need a level next time."
		];

		$context->reply($this->util->randomArrayValue($dingText));
	}

	/** Show a cheesy ding reply */
	#[NCA\HandlesCommand("ding")]
	public function dingDongCommand(CmdContext $context, #[NCA\Str("dong")] string $action): void {
		$msg =	"Ditch, Bitch!";
		$context->reply($msg);
	}

	/** Show a ding gratz message for dinging &lt;level&gt; */
	#[NCA\HandlesCommand("ding")]
	public function ding3Command(CmdContext $context, int $level, ?string $ignoredText): void {
		if ($level <= 0) {
			$lvl = (int)round(220 - $level);
			$dingText = [
				"Reclaim sure is doing a number on you if you're going backwards...",
				"That sounds like a problem... so how are your skills looking?",
				"Wtb negative exp kite teams!",
				"That leaves you with... $lvl more levels until 220, I don't see the problem?",
				"How the hell did you get to $level?"
			];
		} elseif ($level == 1) {
			$dingText = [
				"You didn't even start yet...",
				"Did you somehow start from level 0?",
				"Dinged from 1 to 1? Congratz"
			];
		} elseif ($level == 100) {
			$dingText = [
				"Congratz! <red>Level 100<end> - {$context->char->name} you rock!\n",
				"Congratulations! Time to twink up for T.I.M!",
				"Gratz, you're half way to 200. More missions, MORE!",
				"Woot! Congrats, don't forget to put on your 1k token board."
			];
		} elseif ($level == 150) {
			$dingText = [
				"S10 time!!!",
				"Time to ungimp yourself! Horray! Congrats =)",
				"What starts with A, and ends with Z? <green>ALIUMZ!<end>",
				"Wow, is it that time already? TL 5 really? You sure are moving along! Gratz"
			];
		} elseif ($level == 180) {
			$dingText = [
				"Congratz! Now go kill some <green>aliumz<end> at S13/28/35!!",
				"Only 20 more froob levels to go! HOORAH!",
				"Yay, only 10 more levels until TL 6! Way to go!"
			];
		} elseif ($level == 190) {
			$dingText = [
				"Wow holy shiznits! You're TL 6 already? Congrats!",
				"Just a few more steps and you're there buddy, keep it up!",
				"Almost party time! just a bit more to go {$context->char->name}. We'll be sure to bring you a cookie!"];
		} elseif ($level == 200) {
			$dingText = [
				"Congratz! The big Two Zero Zero!!! Party at {$context->char->name}'s place",
				"Best of the best in froob terms, congratulations!",
				"What a day indeed. Finally done with froob levels. Way to go!"
			];
		} elseif ($level > 200 && $level < 220) {
			$dingText = [
				"Congratz! Just a few more levels to go!",
				"Enough with the dingin you are making the fr00bs feel bad!",
				"Come on save some dings for the rest!"
			];
		} elseif ($level == 220) {
			$dingText = [
				"Congratz! You have reached the end of the line! No more fun for you :P",
				"Holy shit, you finally made it! What an accomplishment... Congratulations {$context->char->name}, for reaching a level reserved for the greatest!",
				"I'm going to miss you a great deal, because after this, ".
					"we no longer can be together {$context->char->name}. ".
					"We must part so you can continue getting your research ".
					"and AI levels done! Farewell!",
				"How was the inferno grind? I'm glad to see you made it through, and congratulations for finally getting the level you well deserved!",
				"Our congratulations, to our newest level 220 member, {$context->char->name}, for his dedication. We present him with his new honorary rank, Chuck Norris!"
			];
		} elseif ($level > 220) {
			$dingText = [
				"Umm...no.",
				"You must be high, because that number is too high...",
				"Ha, ha... ha, yeah... no...",
				"You must be a GM or one hell of an exploiter, that number it too high!",
				"Yeah, and I'm Chuck Norris...",
				"Not now, not later, not ever... find a more reasonable level!"
			];
		} else {
			$lvl = (int)round(220 - $level);
			$dingText = [
				"Ding ding ding... now ding some more!",
				"Keep em coming!",
				"Don't stop now, you're getting there!",
				"Come on, COME ON! Only $lvl more levels to go until 220!"
			];
		}

		$context->reply($this->util->randomArrayValue($dingText));
	}
}

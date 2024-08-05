<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use function Safe\{json_decode, json_encode};
use Nadybot\Core\Events\TimerEvent;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	EventManager,
	MessageHub,
	ModuleInstance,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Text,
	Types\MessageEmitter,
	Util,
};

/**
 * @author Nadyita (RK5)
 * @author Lucier (RK1)
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'vote',
		accessLevel: 'member',
		description: 'Vote in polls',
	),
	NCA\DefineCommand(
		command: 'poll',
		accessLevel: 'member',
		description: 'Create, view or delete polls',
		alias: 'polls'
	),
	NCA\ProvidesEvent(PollStartEvent::class),
	NCA\ProvidesEvent(PollEndEvent::class),
	NCA\ProvidesEvent(PollDelEvent::class),
	NCA\ProvidesEvent(VoteCastEvent::class),
	NCA\ProvidesEvent(VoteDelEvent::class),
	NCA\ProvidesEvent(VoteChangeEvent::class)
]
class VoteController extends ModuleInstance implements MessageEmitter {
	public const DELIMITER = '|';

	// status indicates the last alert that happened (not the next alert that will happen)
	public const STATUS_CREATED = 0;
	public const STATUS_STARTED = 1;
	public const STATUS_60_MINUTES_LEFT = 2;
	public const STATUS_15_MINUTES_LEFT = 3;
	public const STATUS_60_SECONDS_LEFT = 4;
	public const STATUS_ENDED = 9;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	/** @var array<string,Poll> */
	private $polls = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->cacheVotes();
		$this->messageHub->registerMessageEmitter($this);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . '(votes)';
	}

	public function cacheVotes(): void {
		$this->db->table(Poll::getTable())
			->where('status', '!=', self::STATUS_ENDED)
			->asObj(Poll::class)
			->each(function (Poll $topic): void {
				$topic->answers = json_decode($topic->possible_answers, false);
				$this->polls[$topic->id->toString()] = $topic;
			});
	}

	public function getPoll(\Stringable|string $id, ?string $creator=null): ?Poll {
		$query = $this->db->table(Poll::getTable())->where('id', (string)$id);
		if ($creator !== null) {
			$query->where('owner', $creator);
		}

		/** @var ?Poll */
		$topic = $query->asObj(Poll::class)->first();
		if ($topic === null) {
			return null;
		}
		$topic->answers = json_decode($topic->possible_answers);
		return $topic;
	}

	/** This event handler checks for polls ending. */
	#[NCA\Event(
		name: 'timer(2sec)',
		description: 'Checks polls and periodically updates chat with time left'
	)]
	public function checkVote(TimerEvent $eventObj): void {
		if (count($this->polls) === 0) {
			return;
		}

		$msg = [];
		foreach ($this->polls as $id => $poll) {
			$timeleft = $poll->getTimeLeft();

			if ($timeleft <= 0) {
				$title = "Finished Vote: {$poll->question}";
				$this->db->table(Poll::getTable())
					->where('id', $poll->id)
					->update(['status' => self::STATUS_ENDED]);
				$ePoll = clone $poll;
				$event = new PollEndEvent(
					poll: $ePoll,
					votes: $this->db->table(Vote::getTable())
						->where('poll_id', $poll->id)
						->asObjArr(Vote::class),
				);
				$this->eventManager->fireEvent($event);
				unset($this->polls[$id]);
			} elseif ($poll->status === self::STATUS_CREATED) {
				$title = "Vote: {$poll->question}";

				if ($timeleft > 3_600) {
					$mstatus = self::STATUS_STARTED;
				} elseif ($timeleft > 900) {
					$mstatus = self::STATUS_60_MINUTES_LEFT;
				} elseif ($timeleft > 60) {
					$mstatus = self::STATUS_15_MINUTES_LEFT;
				} else {
					$mstatus = self::STATUS_60_SECONDS_LEFT;
				}
				$this->polls[$id]->status = $mstatus;
				// @phpstan-ignore-next-line
			} elseif ($timeleft <= 60 && $timeleft > 0 && $poll->status !== self::STATUS_60_SECONDS_LEFT) {
				$title = "60 seconds left: {$poll->question}";
				$this->polls[$id]->status = self::STATUS_60_SECONDS_LEFT;
			} elseif ($timeleft <= 900 && $timeleft > 60 && $poll->status !== self::STATUS_15_MINUTES_LEFT) {
				$title = "15 minutes left: {$poll->question}";
				$this->polls[$id]->status = self::STATUS_15_MINUTES_LEFT;
			} elseif ($timeleft <= 3_600 && $timeleft > 900 && $poll->status !== self::STATUS_60_MINUTES_LEFT) {
				$title = "60 minutes left: {$poll->question}";
				$this->polls[$id]->status = self::STATUS_60_MINUTES_LEFT;
			} else {
				$title = '';
			}

			if ($title === '') {
				continue;
			}
			$blob = $this->getPollBlob($poll);

			$pages = (array)$this->text->makeBlob($title, $blob);
			foreach ($pages as $page) {
				$msg []= $page;
			}
		}
		if (count($msg)) {
			$rMsg = new RoutableMessage(implode("\n", $msg));
			$rMsg->appendPath(new Source(Source::SYSTEM, 'votes'));
			$this->messageHub->handle($rMsg);
		}
	}

	/** Show all the polls */
	#[NCA\HandlesCommand('poll')]
	#[NCA\Help\Group('voting')]
	public function pollCommand(CmdContext $context): void {
		$topics = $this->db->table(Poll::getTable())
			->orderBy('started')
			->asObjArr(Poll::class);
		$running = '';
		$over = '';
		$blob = '';
		if (count($topics) === 0) {
			$msg = 'There are currently no polls.';
			$context->reply($msg);
			return;
		}
		foreach ($topics as $topic) {
			$line = '<tab>' . Text::makeChatcmd(
				$topic->question,
				"/tell <myname> poll show {$topic->id}"
			);

			$timeleft = $topic->getTimeLeft();
			if ($timeleft > 0) {
				$running .= $line . ' (' . Util::unixtimeToReadable($timeleft) . " left)\n";
			} else {
				$over .= $line . "\n";
			}
		}
		if ($running) {
			$blob .= "<on>Running polls:<end>\n{$running}\n";
		}
		if ($over) {
			$blob .= "<off>Finished polls:<end>\n{$over}";
		}

		$msg = $this->text->makeBlob('All polls', $blob);
		$context->reply($msg);
	}

	/** Delete a poll */
	#[NCA\HandlesCommand('poll')]
	#[NCA\Help\Group('voting')]
	public function pollKillCommand(
		CmdContext $context,
		PRemove $action,
		PUuid $pollId
	): void {
		$pollId = $pollId();
		$owner = null;
		if (!$this->accessManager->checkAccess($context->char->name, 'moderator')) {
			$owner = $context->char->name;
		}
		$topic = $this->getPoll($pollId, $owner);

		if ($topic === null || !isset($topic->id)) {
			$msg = 'Either this poll does not exist, or you did not create it.';
			$context->reply($msg);
			return;
		}
		$this->db->table(Vote::getTable())->where('poll_id', $topic->id)->delete();
		$this->db->table(Poll::getTable())->delete($topic->id);
		$ePoll = clone $topic;
		unset($this->polls[$topic->id->toString()]);
		$msg = "The poll <highlight>{$topic->question}<end> has been removed.";
		$context->reply($msg);
		$event = new PollDelEvent(poll: $ePoll, votes: []);
		$this->eventManager->fireEvent($event);
	}

	/** Remove your vote from a running poll */
	#[NCA\HandlesCommand('vote')]
	#[NCA\Help\Group('voting')]
	public function voteRemoveCommand(
		CmdContext $context,
		PRemove $action,
		PUuid $pollId
	): void {
		$pollId = $pollId();
		if (!isset($this->polls[$pollId])) {
			$msg = "There is no active poll <highlight>{$pollId}<end>.";
			$context->reply($msg);
			return;
		}
		$topic = $this->polls[$pollId];
		$deleted = $this->db->table(Vote::getTable())
			->where('poll_id', $pollId)
			->where('author', $context->char->name)
			->delete();
		if ($deleted > 0) {
			$msg = "Your vote for <highlight>{$topic->question}<end> has been removed.";
			$ePoll = clone $topic;
			$event = new VoteDelEvent(
				poll: $ePoll,
				player: $context->char->name,
			);
			$this->eventManager->fireEvent($event);
		} else {
			$msg = "You have not voted on <highlight>{$topic->question}<end>.";
		}
		$context->reply($msg);
	}

	/** End a poll (voting will end in 60 seconds) */
	#[NCA\HandlesCommand('poll')]
	#[NCA\Help\Group('voting')]
	public function pollEndCommand(
		CmdContext $context,
		#[NCA\Str('end')] string $action,
		PUuid $pollId
	): void {
		$pollId = $pollId();
		$topic = $this->getPoll($pollId);

		if ($topic === null) {
			$msg = 'Either this poll does not exist, or you did not create it.';
			$context->reply($msg);
			return;
		}
		$timeleft = $topic->getTimeLeft();

		if ($timeleft > 60) {
			$topic->duration = (time() - $topic->started) + 61;
			$this->db->table(Poll::getTable())
				->where('id', $topic->id)
				->update(['duration' => $topic->duration]);
			$this->polls[$pollId]->duration = $topic->duration;
			$msg = 'Vote duration reduced to 60 seconds.';
		} elseif ($timeleft <= 0) {
			$msg = 'This poll has already finished.';
		} else {
			$msg = "There is only <highlight>{$timeleft}<end> seconds left.";
		}
		$context->reply($msg);
	}

	/** View a specific poll */
	#[NCA\HandlesCommand('poll')]
	#[NCA\Help\Group('voting')]
	public function voteShowCommand(
		CmdContext $context,
		#[NCA\Str('show', 'view')] ?string $action,
		PUuid $id
	): void {
		$id = $id();
		$topic = $this->getPoll($id);
		if ($topic === null) {
			$context->reply("There is no poll <highlight>{$id}<end>.");
			return;
		}

		$blob = $this->getPollBlob($topic, $context->char->name);

		/** @var ?Vote */
		$vote = $this->db->table(Vote::getTable())
			->where('poll_id', $topic->id)
			->where('author', $context->char->name)
			->asObj(Vote::class)
			->first();
		$timeleft = $topic->getTimeLeft();
		if (isset($vote) && isset($vote->answer) && $timeleft > 0) {
			$privmsg = "You voted: <highlight>{$vote->answer}<end>.";
		} elseif ($timeleft > 0) {
			$privmsg = 'You have not voted on this yet.';
		}

		$msg = $this->text->makeBlob("Poll Nr. {$topic->id}", $blob);
		if (isset($privmsg)) {
			$context->reply($privmsg);
		}

		$context->reply($msg);
	}

	/** Vote for a poll */
	#[NCA\HandlesCommand('vote')]
	#[NCA\Help\Group('voting')]
	public function voteCommand(CmdContext $context, PUuid $pollId, string $answer): void {
		$pollId = $pollId();
		$topic = $this->getPoll($pollId);
		if ($topic === null) {
			$msg = "The poll <highlight>{$pollId}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$timeleft = $topic->getTimeLeft();

		if ($timeleft <= 0) {
			$msg = 'No longer accepting votes for this poll.';
			$context->reply($msg);
			return;
		}

		if (!$topic->allow_other_answers && !in_array($answer, $topic->answers, true)) {
			$msg = 'You have to pick one of the options '.
				Text::enumerate(...Text::arraySprintf("'%s'", ...$topic->answers)) . '. '.
				'Custom answers are not allowed for this poll.';
			$context->reply($msg);
			return;
		}

		/** @var ?Vote */
		$oldVote = $this->db->table(Vote::getTable())
			->where('poll_id', $topic->id)
			->where('author', $context->char->name)
			->asObj(Vote::class)
			->first();
		$ePoll = clone $topic;
		if (isset($oldVote)) {
			$this->db->table(Vote::getTable())
				->where('author', $context->char->name)
				->where('poll_id', $topic->id)
				->update([
					'answer' => $answer,
					'time' => time(),
				]);
			$msg = 'You have changed your vote to '.
				"<highlight>{$answer}<end> for \"{$topic->question}\".";
			$event = new VoteChangeEvent(
				poll: $ePoll,
				player: $context->char->name,
				vote: $answer,
				oldVote: $oldVote->answer ?? 'unknown',
			);
		} else {
			$this->db->insert(new Vote(
				author: $context->char->name,
				answer: $answer,
				time: time(),
				poll_id: $topic->id,
			));
			$msg = "You have voted <highlight>{$answer}<end> for \"{$topic->question}\".";
			$event = new VoteCastEvent(
				poll: $ePoll,
				player: $context->char->name,
				vote: $answer,
			);
		}
		$context->reply($msg);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Create a new poll
	 * The poll will be active for &lt;duration&gt;
	 * The format of &lt;definition&gt; is &lt;topic&gt;|&lt;option1&gt;|&lt;option2&gt;...
	 * If you finish the options with two pipes ||, then custom answers won't be allowed.
	 */
	#[NCA\HandlesCommand('poll')]
	#[NCA\Help\Group('voting')]
	#[NCA\Help\Example('<symbol>poll create 4d3h2m1s WHAT... Is your favorite color?!?|Blue|Yellow')]
	public function pollCreateCommand(
		CmdContext $context,
		#[NCA\Str('add', 'create', 'new')] string $action,
		PDuration $duration,
		string $definition
	): void {
		$answers = Safe::pregSplit("/\s*\Q" . self::DELIMITER . "\E\s*/", $definition);
		$question = array_shift($answers);
		$duration = $duration->toSecs();

		if ($duration === 0) {
			$msg = 'Invalid duration entered. Time format should be: 1d2h3m4s';
			$context->reply($msg);
			return;
		}
		if (count($answers) < 2) {
			$msg = 'You must have at least two options for this poll.';
			$context->reply($msg);
			return;
		}
		$allowCustomAnswers = array_slice($answers, -2) !== ['', ''];
		if ($allowCustomAnswers === false) {
			$answers = array_slice($answers, 0, count($answers)-2);
		}
		$topic = new Poll(
			question: $question,
			author: $context->char->name,
			allow_other_answers: $allowCustomAnswers,
			started: time(),
			duration: $duration,
			answers: $answers,
			possible_answers: json_encode($answers),
			status: self::STATUS_CREATED,
		);

		$this->db->insert($topic);
		$this->polls[$topic->id->toString()] = $topic;
		$msg = "Poll <highlight>{$topic->id}<end> has been created.";

		$context->reply($msg);
		$ePoll = clone $topic;
		$event = new PollStartEvent(poll: $ePoll);
		$this->eventManager->fireEvent($event);
	}

	public function getPollBlob(Poll $topic, ?string $sender=null): string {
		$pollVotes = $this->db->table(Vote::getTable())
			->where('poll_id', $topic->id)
			->asObjArr(Vote::class);

		$results = [];
		foreach ($topic->answers as $answer) {
			$results[$answer] = 0;
		}
		$totalresults = 0;
		foreach ($pollVotes as $pollVote) {
			$answer = $pollVote->answer;
			if (isset($answer)) {
				$results[$answer]++;
				$totalresults++;
			}
		}

		$timeleft = $topic->getTimeLeft();
		$blob = "<header2>{$topic->author}'s Poll<end>\n".
			"<tab><highlight>{$topic->question}<end>\n\n";
		if ($timeleft > 0) {
			$blob .= Util::unixtimeToReadable($timeleft)." till this poll closes.\n\n";
		} else {
			$blob .= '<off>This poll has ended ' . Util::unixtimeToReadable($timeleft * -1, true) . " ago.<end>\n\n";
		}

		$blob .= "<header2>Answers<end>\n";
		foreach ($results as $answer => $votes) {
			if ($totalresults === 0) {
				$val = 0;
			} else {
				$val = (int)round(100 * ($votes / $totalresults), 0);
			}
			$blob .= '<tab>' . Text::alignNumber($val, 3) . '% ';

			if ($timeleft > 0) {
				$blob .= Text::makeChatcmd($answer, "/tell <myname> vote {$topic->id} {$answer}") . " (Votes: {$votes})\n";
			} else {
				$blob .= "<highlight>{$answer}<end> (Votes: {$votes})\n";
			}
		}

		if ($timeleft > 0) {
			$blob .= "\n" .	Text::makeChatcmd(
				'Remove yourself from this poll',
				"/tell <myname> vote remove {$topic->id}"
			) . "\n";

			if ($topic->allow_other_answers) {
				$blob .="\nDon't like these choices? Add your own:\n".
					"<tab>/tell <myname> vote {$topic->id} <highlight>your choice<end>\n";
			}
		}

		if ($sender === null) {
			$blob .="\nIf you started this poll, you can:\n";
		} elseif ($sender === $topic->author) {
			$blob .="\nAs the creator of this poll, you can:\n";
		}
		if ($sender === null || $sender === $topic->author) {
			$blob .='<tab>' . Text::makeChatcmd('Delete the poll completely', "/tell <myname> poll delete {$topic->id}") . "\n";
			if ($timeleft > 0) {
				$blob .='<tab>' . Text::makeChatcmd('End the poll early', "/tell <myname> poll end {$topic->id}");
			}
		}

		return $blob;
	}

	#[
		NCA\NewsTile(
			name: 'polls',
			description: 'Shows currently running polls - if any',
			example: "<header2>Polls<end>\n".
				"<tab>Shall we use startpage instead of news? [<u>show</u>]\n".
				'<tab>New logo for Discord [<u>show</u>]'
		)
	]
	public function pollsNewsTile(string $sender): ?string {
		$topics = $this->db->table(Poll::getTable())
			->orderBy('started')
			->asObjArr(Poll::class);
		if (count($topics) === 0) {
			return null;
		}
		$lines = [];
		foreach ($topics as $topic) {
			$lines []= "<tab>{$topic->question} [" . Text::makeChatcmd(
				'show',
				"/tell <myname> poll show {$topic->id}"
			) . ']';
		}
		$blob = "<header2>Polls<end>\n".
			implode("\n", $lines);
		return $blob;
	}
}

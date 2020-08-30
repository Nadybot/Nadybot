--CREATE TABLE IF NOT EXISTS vote_<myname> (
--	`question` TEXT(500),
--	`author` TEXT (80),
--	`started` INT (10),
--	`duration` INT (10),
--	`answer` TEXT(500),
--	`status` INT (1),
--	`id` INT
--);

CREATE TABLE IF NOT EXISTS polls_<myname> (
	`id` INT PRIMARY KEY AUTOINCREMENT NOT NULL,
	`author` VARCHAR(20) NOT NULL,
	`question` TEXT NOT NULL,
	`possible_answers` TEXT NOT NULL DEFAULT '[]',
	`started` INT NOT NULL,
	`duration` INT NOT NULL,
	`status` INT NOT NULL
);

CREATE TABLE IF NOT EXISTS votes_<myname> (
	`poll_id` INT NOT NULL,
	`author` VARCHAR(20) NOT NULL,
	`answer` TEXT,
	`time` INT,
	UNIQUE(poll_id, author)
);
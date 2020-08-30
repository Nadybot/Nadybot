CREATE TABLE IF NOT EXISTS `news` (
	`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
	`time` INT NOT NULL,
	`name` VARCHAR(30),
	`news` TEXT,
	`sticky` TINYINT NOT NULL,
	`deleted` TINYINT NOT NULL
);

CREATE TABLE IF NOT EXISTS `news_confirmed` (
	`id` INTEGER NOT NULL,
	`player` VARCHAR(20) NOT NULL,
	`time` INT NOT NULL,
	UNIQUE(`id`, `player`)
);

CREATE INDEX IF NOT EXISTS news_confirmed_id ON news_confirmed(id);
CREATE INDEX IF NOT EXISTS news_confirmed_player ON news_confirmed(player);
CREATE TABLE IF NOT EXISTS `raid_reward_<myname>` (
	`id`      INT NOT NULL PRIMARY KEY,
	`name`    VARCHAR(20) NOT NULL,
	`points`  INT NOT NULL,
	`reason`  VARCHAR(100) NOT NULL
);

CREATE INDEX IF NOT EXISTS `raid_reward_<myname>_name_idx` ON `raid_reward_<myname>`(`name`);
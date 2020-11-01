CREATE TABLE IF NOT EXISTS `raid_block_<myname>` (
	`player`        VARCHAR(15) NOT NULL,
	`blocked_from`  VARCHAR(20) NOT NULL,
	`blocked_by`    VARCHAR(15) NOT NULL,
	`reason`        TEXT NOT NULL,
	`time`          INT NOT NULL,
	`expiration`    INT
);

CREATE INDEX IF NOT EXISTS `raid_block_<myname>_player_idx` ON `raid_block_<myname>`(`player`);
CREATE INDEX IF NOT EXISTS `raid_block_<myname>_expiration_idx` ON `raid_block_<myname>`(`expiration`);

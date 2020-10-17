CREATE TABLE IF NOT EXISTS `raid_log_<myname>` (
	`raid_id`           INT NOT NULL,
	`description`       VARCHAR(255) DEFAULT NULL,
	`seconds_per_point` INT NOT NULL,
	`announce_interval` INT NOT NULL,
	`locked`            BOOLEAN NOT NULL,
	`time`              INT NOT NULL
);

CREATE INDEX IF NOT EXISTS `raid_log_<myname>_raid_id_idx` ON `raid_log_<myname>`(`raid_id`);
CREATE INDEX IF NOT EXISTS `raid_log_<myname>_time_idx` ON `raid_log_<myname>`(`time`);
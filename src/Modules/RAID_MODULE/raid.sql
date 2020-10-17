CREATE TABLE IF NOT EXISTS `raid_<myname>` (
	`raid_id`           INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`description`       VARCHAR(255) DEFAULT NULL,
	`seconds_per_point` INT NOT NULL,
	`announce_interval` INT NOT NULL,
	`locked`            BOOLEAN NOT NULL DEFAULT FALSE,
	`started`           INT NOT NULL,
	`started_by`        VARCHAR(20) NOT NULL,
	`stopped`           INT,
	`stopped_by`        VARCHAR(20)
);

CREATE INDEX IF NOT EXISTS `raid_<myname>_started_idx` ON `raid_<myname>`(`started`);
CREATE INDEX IF NOT EXISTS `raid_<myname>_stopped_idx` ON `raid_<myname>`(`stopped`);
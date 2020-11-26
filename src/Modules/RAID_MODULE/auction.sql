CREATE TABLE IF NOT EXISTS `auction_<myname>` (
	`id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`raid_id` INT,
	`item` VARCHAR(255) NOT NULL,
	`auctioneer` VARCHAR(20) NOT NULL,
	`cost` INT,
	`winner` VARCHAR(20),
	`end` INT NOT NULL,
	`reimbursed` BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS `auction_<myname>_raid_id_idx` ON `auction_<myname>`(`raid_id`);
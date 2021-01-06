CREATE TABLE IF NOT EXISTS `org_rank_mapping_<myname>` (
	`access_level` VARCHAR(15) NOT NULL PRIMARY KEY,
	`min_rank` INT NOT NULL UNIQUE
);
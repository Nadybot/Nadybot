CREATE TABLE IF NOT EXISTS alts (
	`alt` VARCHAR(25) NOT NULL PRIMARY KEY,
	`main` VARCHAR(25),
	`validated_by_main` BOOLEAN DEFAULT FALSE,
	`validated_by_alt` BOOLEAN DEFAULT FALSE,
	`added_via` VARCHAR(15)
);
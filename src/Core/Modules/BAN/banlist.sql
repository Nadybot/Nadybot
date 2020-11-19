CREATE TABLE IF NOT EXISTS banlist_<myname> (
	charid BIGINT NOT NULL PRIMARY KEY,
	admin VARCHAR(25),
	time INT,
	reason TEXT,
	banend INT
);

CREATE INDEX IF NOT EXISTS `banlist_<myname>_banend_idx` ON `banlist_<myname>`(`banend`);
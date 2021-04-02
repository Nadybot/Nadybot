DROP TABLE IF EXISTS `arulsaba`;
CREATE TABLE `arulsaba` (
	`name` VARCHAR(20) NOT NULL PRIMARY KEY,
	`lesser_prefix` VARCHAR(10) NOT NULL,
	`regular_prefix` VARCHAR(10) NOT NULL,
	`buffs` VARCHAR(20) NOT NULL
);

DROP TABLE IF EXISTS `arulsaba_buffs`;
CREATE TABLE `arulsaba_buffs` (
	`name` VARCHAR(20) NOT NULL,
	`min_level` INT NOT NULL,
	`left_aoid` INT NOT NULL,
	`right_aoid` INT NOT NULL,
	UNIQUE(`name`, `min_level`)
);

CREATE INDEX `arulsaba_buffs_name_idx` ON `arulsaba_buffs`(`name`);

INSERT INTO `arulsaba` VALUES
('Plasma','Fiery','Burning','Poison Damage'),
('Sky','Scarlet','Rainbow-hued','Radiation Damage'),
('Tundra','Icy','Frozen','Cold Damage'),
('Desert','Empty','Searing','Fire Damage'),
('Killer','Insidious','Silent','Poison Damage'),
('Landscape','Craggy','Jagged','Projectile Damage'),
('Glory','Decayed','Corroded','Chemical Damage'),
('Brawler','Novice','Bruised','Melee Damage'),
('Juggernaut','Frail','Eternal','Max Health'),
('Moebius','Broken','Infinite','Max Nano and Health');

INSERT INTO `arulsaba_buffs` VALUES
('Plasma',  60, 230099, 230114),
('Plasma', 100, 168519, 168534),
('Plasma', 150, 168524, 168539),
('Plasma', 185, 168528, 168543),
('Plasma', 202, 168531, 168546),
('Plasma', 215, 168533, 168548),

('Sky',  60, 230262, 230277),
('Sky', 100, 168763, 168778),
('Sky', 150, 168768, 168783),
('Sky', 185, 168772, 168787),
('Sky', 202, 168775, 168790),
('Sky', 215, 168777, 168792),

('Tundra',  60, 230182, 230197),
('Tundra', 100, 168626, 168641),
('Tundra', 150, 168631, 168646),
('Tundra', 185, 168635, 168650),
('Tundra', 202, 168638, 168653),
('Tundra', 215, 168640, 168655),

('Desert',  60, 230142, 230142),
('Desert', 100, 168559, 168574),
('Desert', 150, 168564, 168579),
('Desert', 185, 168568, 168583),
('Desert', 202, 168571, 168586),
('Desert', 215, 168573, 168588),

('Killer',  60, 230342, 230357),
('Killer', 100, 168849, 168864),
('Killer', 150, 168854, 168869),
('Killer', 185, 168858, 168873),
('Killer', 202, 168861, 168876),
('Killer', 215, 168863, 168878),

('Landscape',  60, 230222, 230237),
('Landscape', 100, 168723, 168738),
('Landscape', 150, 168728, 168743),
('Landscape', 185, 168732, 168747),
('Landscape', 202, 168735, 168750),
('Landscape', 215, 168737, 168752),

('Glory',  60, 230302, 230317),
('Glory', 100, 168803, 168818),
('Glory', 150, 168808, 168823),
('Glory', 185, 168812, 168827),
('Glory', 202, 168815, 168830),
('Glory', 215, 168817, 168832),

('Brawler',  60, 230057, 230072),
('Brawler', 100, 168479, 168494),
('Brawler', 150, 168484, 168499),
('Brawler', 185, 168488, 168503),
('Brawler', 202, 168491, 168506),
('Brawler', 215, 168493, 168508),

('Juggernaut',  60, 229990, 229973),
('Juggernaut', 100, 165395, 165410),
('Juggernaut', 150, 165400, 165415),
('Juggernaut', 185, 165404, 165419),
('Juggernaut', 202, 165407, 165422),
('Juggernaut', 215, 165409, 165424),

('Moebius',  60, 230011, 230026),
('Moebius', 100, 168438, 168453),
('Moebius', 150, 168443, 168458),
('Moebius', 185, 168447, 168462),
('Moebius', 202, 168450, 168465),
('Moebius', 215, 168452, 168467);
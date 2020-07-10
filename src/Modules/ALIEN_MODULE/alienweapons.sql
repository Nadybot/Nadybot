DROP TABLE IF EXISTS `alienweaponspecials`;
CREATE TABLE `alienweaponspecials` (
	`type` INT NOT NULL,
	`specials` varchar(255) NOT NULL
);
INSERT INTO `alienweaponspecials` (`type`, `specials`) VALUES
(1, 'Fling shot'),
(2, 'Aimed Shot'),
(4, 'Burst'),
(3, 'Fling Shot and Aimed Shot'),
(5, 'Fling Shot and Burst'),
(12, 'Burst and Full Auto'),
(13, 'Burst, Fling Shot and Full Auto'),
(48, 'Brawl and Dimach'),
(76, 'Brawl and Fast Attack'),
(112, 'Brawl, Dimach and Fast Attack'),
(240, 'Brawl, Dimach, Fast Attack and Sneak Attack'),
(880, 'Dimach, Fast Attack, Parry and Riposte'),
(992, 'Dimach, Fast Attack, Sneak Attack, Parry and Riposte');

DROP TABLE IF EXISTS `alienweapons`;
CREATE TABLE `alienweapons` (
	`type` INT NOT NULL,
	`name` VARCHAR(255) NOT NULL
);
INSERT INTO `alienweapons` (`type`, `name`) VALUES
(1, 'Kyr''Ozch Grenade Gun - Type 1'),
(1, 'Kyr''Ozch Pistol - Type 1'),
(1, 'Kyr''Ozch Shotgun - Type 1'),
(2, 'Kyr''Ozch Crossbow - Type 2'),
(2, 'Kyr''Ozch Rifle - Type 2'),
(3, 'Kyr''Ozch Crossbow - Type 3'),
(3, 'Kyr''Ozch Energy Carbine - Type 3'),
(3, 'Kyr''Ozch Rifle - Type 3'),
(4, 'Kyr''Ozch Machine Pistol - Type 4'),
(4, 'Kyr''Ozch Pistol - Type 4'),
(4, 'Kyr''Ozch Submachine Gun - Type 4'),
(5, 'Kyr''Ozch Carbine - Type 5'),
(5, 'Kyr''Ozch Energy Carbine - Type 5'),
(5, 'Kyr''Ozch Energy Pistol - Type 5'),
(5, 'Kyr''Ozch Machine Pistol - Type 5'),
(5, 'Kyr''Ozch Submachine Gun - Type 5'),
(12, 'Kyr''Ozch Carbine - Type 12'),
(12, 'Kyr''Ozch Submachine Gun - Type 12'),
(13, 'Kyr''Ozch Carbine - Type 13'),
(48, 'Kyr''Ozch Nunchacko - Type 48'),
(76, 'Kyr''Ozch Energy Sword - Type 76'),
(76, 'Kyr''Ozch Sledgehammer - Type 76'),
(112, 'Kyr''Ozch Energy Hammer - Type 112'),
(112, 'Kyr''Ozch Hammer - Type 112'),
(112, 'Kyr''Ozch Spear - Type 112'),
(112, 'Kyr''Ozch Sword - Type 112'),
(240, 'Kyr''Ozch Axe - Type 240'),
(880, 'Kyr''Ozch Sword - Type 880'),
(992, 'Kyr''Ozch Energy Rapier - Type 992');
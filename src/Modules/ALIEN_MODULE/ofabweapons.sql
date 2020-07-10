DROP TABLE IF EXISTS `ofabweapons`;
CREATE TABLE `ofabweapons` (
	`type` int(11) NOT NULL default '0',
	`name` varchar(255) NOT NULL default ''
);
INSERT INTO `ofabweapons` (`type`, `name`) VALUES
(18, 'Mongoose'),
(18, 'Viper'),
(18, 'Wolf'),
(34, 'Bear'),
(34, 'Panther'),
(687, 'Cobra'),
(687, 'Shark'),
(687, 'Silverback'),
(812, 'Hawk'),
(812, 'Peregrine'),
(812, 'Tiger');

DROP TABLE IF EXISTS `ofabweaponscost`;
CREATE TABLE `ofabweaponscost` (
	`ql` int(11) NOT NULL,
	`vp` int(11) NOT NULL
);
INSERT INTO `ofabweaponscost` (`ql`, `vp`) VALUES
(25, 117),
(50, 488),
(75, 1110),
(100, 1988),
(125, 2365),
(150, 3497),
(175, 5384),
(200, 7987),
(225, 8617),
(250, 10509),
(275, 13665),
(300, 18000);
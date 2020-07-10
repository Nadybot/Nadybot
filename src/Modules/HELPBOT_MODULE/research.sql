DROP TABLE IF EXISTS research;
CREATE TABLE research (
	`level` INT,
	`sk` INT,
	`levelcap` INT
);
INSERT INTO research (`level`, `sk`, `levelcap`) VALUES
(0,0,0),
(1,50,1),
(2,450,50),
(3,1600,75),
(4,4700,100),
(5,12750,125),
(6,32000,150),
(7,54000,175),
(8,64000,190),
(9,740000,190),
(10,900000,200);
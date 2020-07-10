DROP TABLE IF EXISTS ImplantType;
CREATE TABLE ImplantType (
	ImplantTypeID INT NOT NULL PRIMARY KEY,
	Name VARCHAR(20) NOT NULL,
	ShortName VARCHAR(10) NOT NULL
);
INSERT INTO ImplantType (ImplantTypeID, Name, ShortName) VALUES
(1,'Eye','eye'),
(2,'Head','head'),
(3,'Ear','ear'),
(4,'Chest','chest'),
(5,'Waist','waist'),
(6,'Leg','legs'),
(7,'Feet','feet'),
(8,'Left Arm','larm'),
(9,'Left Wrist','lwrist'),
(10,'Left Hand','lhand'),
(11,'Right Arm','rarm'),
(12,'Right Wrist','rwrist'),
(13,'Right Hand','rhand');

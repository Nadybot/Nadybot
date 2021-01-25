CREATE TABLE IF NOT EXISTS `package_files_<myname>`(
	`module` VARCHAR(25) NOT NULL,
	`version` VARCHAR(50) NOT NULL,
	`file` TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS `package_files_<myname>_module_idx` on `package_files_<myname>`(`module`);
CREATE INDEX IF NOT EXISTS `package_files_<myname>_version_idx` on `package_files_<myname>`(`version`);

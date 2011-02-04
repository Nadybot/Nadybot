<?php
	$MODULE_NAME = "NANO_MODULE";

	//Search for Database Updates
	DB::loadSQLFile($MODULE_NAME, "nanos");
	DB::loadSQLFile($MODULE_NAME, "nanolines");

    //nano Search
	bot::command("", "$MODULE_NAME/nano.php", "nano", "all", "Searches for a nano and tells you were to get it.");
	
	//nanolines
	bot::command("", "$MODULE_NAME/nanolines.php", "nanolines", "all", "Shows a list of professions to choose from");
	bot::command("", "$MODULE_NAME/nlprof.php", "nlprof", "all", "Shows a list of nanolines given a profession");
	bot::command("", "$MODULE_NAME/nlline.php", "nlline", "all", "Shows a list of nanos given a nanoline");

	//Settings
    Setting::add($MODULE_NAME, 'maxnano', 'Number of Nanos shown on the list', 'edit', '40', '30;40;50;60', "0", "mod", "$MODULE_NAME/maxnano_help.txt");
	Setting::add($MODULE_NAME, "shownanolineicons", "Show icons for the nanolines", "edit", "0", "true;false", "1;0");

	//Helpfiles
    Help::register($MODULE_NAME, "nano", "nano.txt", "guild", "How to search for a nano.");
	Help::register($MODULE_NAME, "nanolines", "nanolines.txt", "all", "How to use nanolines");
?>
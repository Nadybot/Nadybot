<?php declare(strict_types=1);


/* Configuration file for Nadybot. */


$vars = [];
// Account information.
$vars['login']      = "";
$vars['password']   = "";
$vars['name']       = "";
$vars['my_guild']   = "";

// Automatically detect org name changes. Requires an initial my_guild
$vars['auto_guild_name']  = 0;

// 6 for Live (new), 5 for Live (old), 4 for Test.
$vars['dimension']  = 5;

// Character name of the Super Administrator.
$vars['SuperAdmin'] = "";

// Database information.
$vars['DB Type'] = "sqlite";		// What type of database should be used? ('sqlite' or 'mysql')
$vars['DB Name'] = "nadybot.db";	// Database name
$vars['DB Host'] = "./data/";		// Hostname or file location
$vars['DB username'] = "";			// MySQL username
$vars['DB password'] = "";			// MySQL password

// Show AOML markup in logs/console? 1 for enabled, 0 for disabled.
$vars['show_aoml_markup'] = 0;

// Cache folder for storing organization XML files.
$vars['cachefolder'] = "./cache/";

// Folder for storing HTML files of the webserver
$vars['htmlfolder'] = "./html/";

// Folder for storing data files
$vars['datafolder'] = "./data/";

// Folder for storing log files
$vars['logsfolder'] = "./logs/";

// Default status for new modules? 1 for enabled, 0 for disabled.
$vars['default_module_status'] = 0;

// Enable the readline-based console interface to the bot?
$vars['enable_console_client'] = 1;

// Enable the module to install other modules from within the bot
$vars['enable_package_module'] = 1;

// Try to automatically unfreeze frozen accounts
$vars['auto_unfreeze'] = true;
$vars['auto_unfreeze_login'] = null;
$vars['auto_unfreeze_password'] = null;
$vars['auto_unfreeze_use_nadyproxy'] = true;

// Use AO Chat Proxy? 1 for enabled, 0 for disabled.
$vars['use_proxy']    = 0;
$vars['proxy_server'] = "127.0.0.1";
$vars['proxy_port']   = 9993;

// Define additional paths from where Nadybot should load modules at startup
$vars['module_load_paths'] = [
	'./src/Modules',
	'./extras',
];

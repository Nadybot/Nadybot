<?php

/* ********************************************	*/
/* Configuration file for Nadybot.              */
/* ********************************************	*/

// Account information.
$vars['login']      = "";
$vars['password']   = "";
$vars['name']       = "";
$vars['my_guild']   = "";

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

// Default status for new modules? 1 for enabled, 0 for disabled.
$vars['default_module_status'] = 0;

// Use AO Chat Proxy? 1 for enabled, 0 for disabled.
$vars['use_proxy']    = 0;
$vars['proxy_server'] = "127.0.0.1";
$vars['proxy_port']   = 9993;

// Using an AMQP server like RabbitMQ?
$vars['amqp_server']   = "127.0.0.1";
$vars['amqp_port']     = 5672;
$vars['amqp_user']     = "";
$vars['amqp_password'] = "";
$vars['amqp_vhost']    = "/";

// Define additional paths from where Nadybot should load modules at startup
$vars['module_load_paths'] = [
	'./src/Modules',
	'./extras'
];

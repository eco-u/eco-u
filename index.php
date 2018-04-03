<?php
// Version
define('VERSION', '2.3.0.2');
$cur_url = explode('?',$_SERVER['REQUEST_URI']);
if(in_array($cur_url[0], Array('/', '/index.php')))
{
	$_REQUEST['_route_'] = 'eda/';
	$_GET['_route_'] = 'eda/';
}

// Push test11
// Configuration
if (is_file('config.php')) {
	require_once('config.php');
}

// Install
if (!defined('DIR_APPLICATION')) {
	header('Location: install/index.php');
	exit;
}

// Startup
require_once(DIR_SYSTEM . 'startup.php');

start('catalog');


<?php namespace ProcessWire;

$info = array(
	'title' => 'File Renamer',
	'summary' => 'Safely rename ProcessWire uploaded asset basenames from the admin.',
	'version' => 131,
	'author' => 'Daniel Zilli',
	'icon' => 'file-image-o',
	'singular' => true,
	'autoload' => false,
	'permission' => 'file-renamer',
	'page' => array(
		'name' => 'file-renamer',
		'parent' => 'setup',
		'title' => 'File Renamer'
	),
	'requires' => array('ProcessWire>=3.0.262', 'PHP>=7.4.0')
);

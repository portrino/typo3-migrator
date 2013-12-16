<?php
/***************************************************************
 * Extension Manager/Repository config file for ext "migrator".
 ***************************************************************/
$EM_CONF['migrator'] = array(
	'title' => 'DB Migrator',
	'description' => 'TYPO3 DB Migrator',
	'category' => 'backend',
	'state' => 'beta',
	'author' => 'Sebastian Michaelsen',
	'author_email' => 'sebastian@app-zap.de',
	'author_company' => 'app zap',
	'version' => '0.0.0',
	'constraints' => array(
		'depends' => array(
			'php' => '5.3.0-0.0.0',
			'typo3' => '6.1.0-0.0.0',
			'extbase' => '0.0.0-0.0.0',
		),
	),
);
?>
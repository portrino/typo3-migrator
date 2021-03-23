<?php
/***************************************************************
 * Extension Manager/Repository config file for ext "migrator".
 ***************************************************************/
$EM_CONF['migrator'] = array(
	'title' => 'DB Migrator',
	'description' => 'TYPO3 DB Migrator',
	'category' => 'be',
	'state' => 'beta',
	'author' => 'Sebastian Michaelsen, portrino GmbH',
	'author_email' => 'sebastian@app-zap.de, info@portrino.de',
	'author_company' => 'app zap',
	'version' => '2.0.0',
	'constraints' => array(
		'depends' => array(
			'typo3' => '10.4.0-10.4.99',
		),
	),
);

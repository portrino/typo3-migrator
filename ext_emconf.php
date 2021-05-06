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
	'author_email' => 'sebastian@app-zap.de, dev@portrino.de',
	'author_company' => 'app zap, portrino GmbH',
	'version' => '1.3.0',
	'constraints' => array(
		'depends' => array(
			'typo3' => '7.6.0-9.5.99',
		),
	),
);

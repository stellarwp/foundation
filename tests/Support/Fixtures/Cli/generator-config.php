<?php declare(strict_types=1);

return [
	'generators' => [
		'wpcli-command'      => ['namespace' => 'Acme\\Plugin\\Commands'],
		'database-provider'  => ['namespace' => 'Acme\\Plugin\\Persistence'],
		'database-table'     => ['namespace' => 'Acme\\Plugin\\Persistence\\Tables'],
		'database-migration' => ['namespace' => 'Acme\\Plugin\\Persistence\\Migrations'],
	],
];

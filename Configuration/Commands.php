<?php
return [
    'migration:migrateall' => [
        'class' => \AppZap\Migrator\Command\MigrateAllCommand::class,
    ],
    'migration:migrateshellfile' => [
        'class' => \AppZap\Migrator\Command\MigrateShellFileCommand::class,
    ],
];

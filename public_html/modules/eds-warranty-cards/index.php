<?php
return [
    'slug' => 'eds-warranty-cards',
    'name' => 'Warranty Cards',
    'description' => 'Prepare and issue customer warranty cards for work orders.',
    'version' => '0.12.1',
    'min_motherboard_version' => '26.9.18.1',
    'min_php_version' => '8.4',
    'default_enabled' => false,
    'settings' => true,
    'author' => 'EDS',
    'boot' => function (array $definition): void {
        require $definition['path'] . '/boot.php';
    },
];

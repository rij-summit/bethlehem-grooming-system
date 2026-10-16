<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
$server = new PDO("mysql:host={$connection['host']};port={$connection['port']}", $connection['username'], $connection['password']);
$database = 'bethlehem_capacity_'.bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $process = new Process([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', '--no-progress', '--filter',
        'test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql'], dirname(__DIR__),
        ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'DB_HOST' => $connection['host'],
            'DB_PORT' => $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password']]);
    $status = $process->run(fn ($type, $buffer) => print($buffer));
} finally {
    $server->exec("DROP DATABASE `{$database}`");
}
exit($status);

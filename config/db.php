<?php

$mongoHost = getenv('MONGO_HOST') ?: 'localhost';
$mongoPort = getenv('MONGO_PORT') ?: '27017';

return [
    'class' => 'yii\db\Connection',
    'dsn' => "mongodb://{$mongoHost}:{$mongoPort}/stock",
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8',
];

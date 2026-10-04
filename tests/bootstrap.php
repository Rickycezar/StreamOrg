<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load();
Lang::setLocale('en');

$_SESSION = [];

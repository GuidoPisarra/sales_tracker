<?php

namespace App\Service;

use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;

class AppLogs
{
    private static $project_dir;
    private static $logger;

    public function __construct(string $project_dir)
    {
        self::$project_dir = $project_dir;
    }

    public static function get_log(): Logger
    {
        $storage = self::$project_dir . $_ENV['LOGS'];
        if (!file_exists($storage)) {
            mkdir($storage, 0777, true);
        }

        if (self::$logger !== null) {
            return self::$logger;
        }

        self::$logger = new Logger('app_logger');

        self::$logger->pushHandler(new StreamHandler($storage . 'app.log', LogLevel::DEBUG));
        self::$logger->pushHandler(new FirePHPHandler());

        return self::$logger;
    }
}
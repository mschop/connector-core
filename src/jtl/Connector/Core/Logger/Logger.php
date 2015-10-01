<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Core
 */

namespace jtl\Connector\Core\Logger;

use \Monolog\Logger as Monolog;
use \Monolog\Handler\StreamHandler;

class Logger extends Monolog
{
    /**
     * Adds a log record at an arbitrary level.
     *
     * @param mixed $message The log message
     * @param mixed $level The log level
     * @param string $channel The log channel
     * @return Boolean Whether the record has been processed
     */
    public static function write($message, $level = self::ERROR, $channel = 'general')
    {
        $forceWriting = false;
        if (function_exists('Application') && Application()->getConfig() !== null) {
            try {
                $forceWriting = Application()->getConfig()->read('developer_logging');
            } catch (\Exception $e) { }
        }

        if (!$forceWriting && $level == Monolog::DEBUG && getenv('APPLICATION_ENV') != 'development') {
            return null;
        }

        if ($level == Monolog::DEBUG && getenv('APPLICATION_ENV') != 'development') {
            return null;
        }

        $path = array(
            CONNECTOR_DIR,
            'logs',
            "{$channel}.log"
        );

        $log = LoggerFactory::get($channel);
        if (!$log->isHandling($level)) {
            $log->pushHandler(new StreamHandler(implode(DIRECTORY_SEPARATOR, $path), $level));
        }

        return $log->log($level, $message);
    }

    /**
     * Gets the current logger
     *
     * @param string $channel The log channel
     * @return \Monolog\Logger
     */
    public static function getLogger($channel)
    {
        return LoggerFactory::get($channel);
    }
}

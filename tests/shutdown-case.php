<?php

/**
 * Logs once and exits WITHOUT calling flush(), so the parent test can check
 * that the shutdown hook drained the queue on its own. Run as a subprocess.
 *
 * argv: <autoload> <log directory> <api url>
 */

declare(strict_types=1);

require $argv[1];

Tracy\Debugger::$logDirectory = $argv[2];

$logger = new Residit\NetteLogger\NetteLogger();
$logger->register();
$logger->setUrl($argv[3]);
$logger->setProxy('');
$logger->setToken('shutdown-token');
$logger->setTimeouts(2000, 5000);

$logger->log('logged without an explicit flush', Tracy\ILogger::INFO);

echo "exiting\n";

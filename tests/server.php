<?php

/**
 * Router for PHP's built-in server, used by the test suite as a stand-in API.
 *
 * Records every request it receives so the test can assert what was delivered,
 * and can be asked to stall via ?delay=<ms> so the suite can tell a dispatch
 * that waits from one that does not.
 */

declare(strict_types=1);

$dir = getenv('NETTE_LOGGER_CAPTURE_DIR');

if (isset($_GET['delay'])) {
  usleep(((int) $_GET['delay']) * 1000);
}

if ($dir) {
  $record = [
    'post' => $_POST,
    'token' => isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : null,
    'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
  ];
  file_put_contents(
    $dir . '/' . uniqid('req-', true) . '.json',
    json_encode($record),
    LOCK_EX
  );
}

echo 'ok';

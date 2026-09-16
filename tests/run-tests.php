<?php

/**
 * Dependency-free test runner.
 *
 * Deliberately avoids PHPUnit: the supported range spans PHP 7.2 to 8.4 and no
 * single PHPUnit major covers it, so the matrix would test the test framework's
 * resolution rather than this library. Everything here is plain PHP 7.2.
 */

declare(strict_types=1);

use Nette\DI\Compiler;
use Nette\DI\ContainerLoader;
use Nette\Security\User;
use Residit\NetteLogger\DI\NetteLoggerExtension;
use Residit\NetteLogger\NetteLogger;
use Tracy\Debugger;
use Tracy\ILogger;

require __DIR__ . '/../vendor/autoload.php';

$failures = [];
$passed = 0;

function test(string $name, callable $fn)
{
  global $failures, $passed;
  try {
    $fn();
    $passed++;
    echo "  ok   $name\n";
  } catch (Throwable $e) {
    $failures[] = $name . ': ' . $e->getMessage();
    echo "  FAIL $name\n       " . $e->getMessage() . "\n";
  }
}

function assertTrue($cond, string $msg)
{
  if (!$cond) {
    throw new RuntimeException($msg);
  }
}

function assertSame($expected, $actual, string $msg)
{
  if ($expected !== $actual) {
    throw new RuntimeException($msg . ' (expected ' . var_export($expected, true)
      . ', got ' . var_export($actual, true) . ')');
  }
}

function tempDir(string $suffix): string
{
  $dir = sys_get_temp_dir() . '/nette-logger-tests-' . getmypid() . '-' . $suffix;
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  return $dir;
}

/**
 * Captures the payload instead of sending it, so the assembled data can be
 * asserted without standing up an HTTP server.
 */
class CapturingLogger extends NetteLogger
{
  public $sent = [];

  protected function send(array $logData): void
  {
    $this->sent[] = $logData;
  }
}

/**
 * Nette\Security\User needs a storage to be constructible, and the interface
 * it expects was renamed between nette/security 3.0 and 3.1. Only the branch
 * matching the installed version is ever declared.
 */
if (interface_exists('Nette\Security\UserStorage')) {
  class TestUserStorage implements Nette\Security\UserStorage
  {
    public function saveAuthentication(Nette\Security\IIdentity $identity): void {}
    public function clearAuthentication(bool $clearIdentity): void {}
    public function getState(): array { return [false, null, null]; }
    public function setExpiration(?string $expire, bool $clearIdentity): void {}
  }
} else {
  class TestUserStorage implements Nette\Security\IUserStorage
  {
    public function setAuthenticated(bool $state) { return $this; }
    public function isAuthenticated(): bool { return false; }
    public function setIdentity(?Nette\Security\IIdentity $identity) { return $this; }
    public function getIdentity(): ?Nette\Security\IIdentity { return null; }
    public function setExpiration(?string $expire, int $flags = 0) { return $this; }
    public function getLogoutReason(): ?int { return null; }
  }
}

function buildContainer(array $config, string $key)
{
  $loader = new ContainerLoader(tempDir('di-' . $key), true);
  $class = $loader->load(function (Compiler $compiler) use ($config) {
    $compiler->addExtension('netteLogger', new NetteLoggerExtension());
    $compiler->addConfig($config);
  }, $key);

  return new $class;
}

$logDir = tempDir('log');
Debugger::$logDirectory = $logDir;

echo "PHP " . PHP_VERSION . ", Tracy " . Debugger::VERSION . "\n\n";

echo "DI extension\n";

test('registers the logger and hands it to Tracy', function () use ($logDir) {
  $container = buildContainer([
    'netteLogger' => ['url' => 'http://127.0.0.1:9/x', 'token' => 'secret'],
  ], 'enabled');
  $container->initialize();

  $service = $container->getService('netteLogger.logger');
  assertTrue($service instanceof NetteLogger, 'service is not a NetteLogger');
  assertTrue(Debugger::getLogger() instanceof NetteLogger, 'Tracy was not given the logger');
});

test('stays off when url and token are missing', function () {
  $container = buildContainer(['netteLogger' => []], 'disabled');
  assertTrue(!$container->hasService('netteLogger.logger'), 'service registered despite empty config');
});

test('stays off when only the url is set', function () {
  $container = buildContainer([
    'netteLogger' => ['url' => 'http://127.0.0.1:9/x'],
  ], 'url-only');
  assertTrue(!$container->hasService('netteLogger.logger'), 'service registered without a token');
});

test('rejects unknown configuration keys', function () {
  $thrown = false;
  try {
    buildContainer([
      'netteLogger' => ['url' => 'u', 'token' => 't', 'typo' => 'x'],
    ], 'typo');
  } catch (Throwable $e) {
    $thrown = true;
  }
  assertTrue($thrown, 'an unknown option was silently accepted');
});

test('wires security.user when the application defines it', function () {
  $compiler = new Compiler();
  $extension = new NetteLoggerExtension();
  $compiler->addExtension('netteLogger', $extension);
  $compiler->addConfig([
    'netteLogger' => ['url' => 'http://127.0.0.1:9/x', 'token' => 't'],
    'services' => [
      'security.userStorage' => TestUserStorage::class,
      'security.user' => User::class,
    ],
  ]);
  $compiler->compile();

  $definition = $compiler->getContainerBuilder()->getDefinition('netteLogger.logger');
  $names = [];
  foreach ($definition->getSetup() as $setup) {
    // Before resolution the entity is the bare method name; afterwards it is
    // a [target, method] pair.
    $entity = $setup->getEntity();
    $names[] = is_array($entity) ? (string) end($entity) : (string) $entity;
  }
  assertTrue(in_array('setIdentity', $names, true), 'setIdentity was not wired, got: ' . implode(', ', $names));
});

echo "\nPayload\n";

/**
 * Starts PHP's built-in server on a free port with tests/server.php as the
 * router, and returns [$baseUrl, $captureDir, $stop].
 *
 * @return array{0: string, 1: string, 2: callable}
 */
function startApiStub(string $key): array
{
  $captureDir = tempDir('api-' . $key);
  foreach ((array) glob($captureDir . '/*.json') as $stale) {
    unlink($stale);
  }

  $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
  if (!$probe) {
    throw new RuntimeException("could not reserve a port: $errstr");
  }
  $name = stream_socket_get_name($probe, false);
  $port = (int) substr($name, strrpos($name, ':') + 1);
  fclose($probe);

  $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
  $process = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg(__DIR__ . '/server.php'),
    $descriptors,
    $pipes,
    __DIR__,
    [
      'NETTE_LOGGER_CAPTURE_DIR' => $captureDir,
      'PATH' => getenv('PATH'),
      // The built-in server is single-threaded unless this is set, which would
      // serialise concurrent transfers and hide what the test is measuring.
      'PHP_CLI_SERVER_WORKERS' => '4',
    ]
  );

  if (!is_resource($process)) {
    throw new RuntimeException('could not start the stub api');
  }

  $stop = function () use ($process, $pipes) {
    foreach ($pipes as $pipe) {
      if (is_resource($pipe)) {
        fclose($pipe);
      }
    }
    proc_terminate($process);
    proc_close($process);
  };

  $deadline = microtime(true) + 10;
  while (microtime(true) < $deadline) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn) {
      fclose($conn);
      return ['http://127.0.0.1:' . $port . '/', $captureDir, $stop];
    }
    usleep(50000);
  }

  $stop();
  throw new RuntimeException('the stub api never came up on port ' . $port);
}

/**
 * @return array<int, array<string, mixed>>
 */
function capturedRequests(string $captureDir): array
{
  $out = [];
  foreach ((array) glob($captureDir . '/*.json') as $file) {
    $decoded = json_decode((string) file_get_contents($file), true);
    if (is_array($decoded)) {
      $out[] = $decoded;
    }
  }
  return $out;
}

function makeLogger(): CapturingLogger
{
  $logger = new CapturingLogger();
  $logger->register();
  $logger->setUrl('http://127.0.0.1:9/x');
  $logger->setProxy('');
  $logger->setToken('t');
  return $logger;
}

test('exception fills in title, file, line, hash and html', function () {
  $logger = makeLogger();
  $exception = new RuntimeException('something broke');
  $file = $logger->log($exception, ILogger::EXCEPTION);

  assertTrue(is_string($file) && $file !== '', 'parent::log() returned no bluescreen file');
  assertSame(1, count($logger->sent), 'expected exactly one payload');

  $data = $logger->sent[0];
  assertSame('something broke', $data['title'], 'wrong title');
  assertSame($exception->getFile(), $data['file'], 'wrong file');
  assertSame($exception->getLine(), $data['line'], 'wrong line');
  assertSame(ILogger::EXCEPTION, $data['type'], 'wrong type');
  assertTrue(is_string($data['hash']) && $data['hash'] !== '', 'hash was not extracted from ' . $file);
  assertTrue(is_string($data['html']) && $data['html'] !== '', 'bluescreen html was not attached');
});

test('plain string is logged as the title', function () {
  $logger = makeLogger();
  $logger->log('just a message', ILogger::INFO);

  $data = $logger->sent[0];
  assertSame('just a message', $data['title'], 'wrong title');
  assertSame(null, $data['html'], 'html should stay empty for a string');
});

// Dumper::THEME only exists from Tracy 2.8 on; before the dependency
// constraints were fixed this branch fataled with an undefined constant.
test('array is dumped to html', function () {
  $logger = makeLogger();
  $logger->log(['a' => 1, 'password' => 'hunter2'], ILogger::INFO);

  $data = $logger->sent[0];
  assertSame('Array log', $data['title'], 'wrong title');
  assertTrue(is_string($data['html']) && $data['html'] !== '', 'no html produced');
  assertTrue(strpos($data['html'], 'hunter2') === false, 'password leaked into the dump');
});

test('object is dumped to html with its class name', function () {
  $logger = makeLogger();
  $logger->log(new stdClass(), ILogger::INFO);

  $data = $logger->sent[0];
  assertSame('Object log - stdClass', $data['title'], 'wrong title');
  assertTrue(is_string($data['html']) && $data['html'] !== '', 'no html produced');
});

// setUrl() is never called when the extension is disabled, so an unconfigured
// logger must not try to reach the API at all.
test('nothing is dispatched when no url is configured', function () {
  $logger = new CapturingLogger();
  $logger->register();
  $logger->log('message', ILogger::INFO);
  assertSame(0, count($logger->sent), 'a payload was dispatched without a url');
});

echo "\nRequest url\n";

test('https is detected', function () {
  $logger = makeLogger();
  $_SERVER['HTTP_HOST'] = 'example.com';
  $_SERVER['REQUEST_URI'] = '/page';
  $_SERVER['HTTPS'] = 'on';
  $logger->log('m', ILogger::INFO);
  assertSame('https://example.com/page', $logger->sent[0]['url'], 'wrong url');
  unset($_SERVER['HTTPS']);
});

// isset($_SERVER['HTTPS']) alone is true for the literal value "off", which
// Apache sets on plain http.
test('HTTPS=off is treated as plain http', function () {
  $logger = makeLogger();
  $_SERVER['HTTP_HOST'] = 'example.com';
  $_SERVER['REQUEST_URI'] = '/page';
  $_SERVER['HTTPS'] = 'off';
  $logger->log('m', ILogger::INFO);
  assertSame('http://example.com/page', $logger->sent[0]['url'], 'wrong url');
  unset($_SERVER['HTTPS']);
});

test('url stays null outside an http request', function () {
  $logger = makeLogger();
  unset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['HTTPS']);
  $logger->log('m', ILogger::INFO);
  assertSame(null, $logger->sent[0]['url'], 'url should be null on the command line');
});

echo "\nDispatch\n";

test('payloads reach the api and carry the auth token', function () {
  list($url, $captureDir, $stop) = startApiStub('delivery');

  try {
    $logger = new NetteLogger();
    $logger->register();
    $logger->setUrl($url);
    $logger->setProxy('');
    $logger->setToken('secret-token');
    $logger->setFinishRequest(false);

    $logger->log('first', ILogger::INFO);
    $logger->log('second', ILogger::WARNING);
    $logger->flush();

    $requests = capturedRequests($captureDir);
    assertSame(2, count($requests), 'wrong number of requests delivered');

    $titles = [];
    foreach ($requests as $request) {
      assertSame('secret-token', $request['token'], 'auth token missing or wrong');
      assertSame('POST', $request['method'], 'wrong http method');
      $titles[] = $request['post']['title'];
    }
    sort($titles);
    assertSame(['first', 'second'], $titles, 'wrong payloads delivered');
  } finally {
    $stop();
  }
});

// The point of the whole exercise: log() hands the transfer to cURL and returns,
// and the waiting happens in flush(), after the response is already gone.
test('log() returns without waiting for a slow api', function () {
  list($url, $captureDir, $stop) = startApiStub('slow');

  try {
    $logger = new NetteLogger();
    $logger->register();
    $logger->setUrl($url . '?delay=1000');
    $logger->setProxy('');
    $logger->setToken('t');
    $logger->setTimeouts(2000, 5000);
    $logger->setFinishRequest(false);

    $started = microtime(true);
    $logger->log('slow one', ILogger::INFO);
    $logCost = microtime(true) - $started;

    $started = microtime(true);
    $logger->flush();
    $flushCost = microtime(true) - $started;

    assertTrue($logCost < 0.3, sprintf('log() blocked for %.0f ms', $logCost * 1000));
    assertTrue($flushCost > 0.5, sprintf('flush() did not wait (%.0f ms), so nothing was deferred', $flushCost * 1000));
    assertSame(1, count(capturedRequests($captureDir)), 'the deferred request never arrived');
  } finally {
    $stop();
  }
});

test('several logs are dispatched concurrently, not one after another', function () {
  list($url, $captureDir, $stop) = startApiStub('parallel');

  try {
    $logger = new NetteLogger();
    $logger->register();
    $logger->setUrl($url . '?delay=600');
    $logger->setProxy('');
    $logger->setToken('t');
    $logger->setTimeouts(2000, 5000);
    $logger->setFinishRequest(false);

    for ($i = 0; $i < 3; $i++) {
      $logger->log('entry ' . $i, ILogger::INFO);
    }

    $started = microtime(true);
    $logger->flush();
    $elapsed = microtime(true) - $started;

    assertSame(3, count(capturedRequests($captureDir)), 'not every request arrived');

    // The timing half of this only means anything where the stub can answer
    // more than one request at a time. PHP_CLI_SERVER_WORKERS arrived in 7.4,
    // so below that the built-in server serialises the replies itself and
    // would report a slowdown that says nothing about the dispatcher.
    if (PHP_VERSION_ID >= 70400 && DIRECTORY_SEPARATOR === '/') {
      // Serially this would be ~1.8s; in parallel it stays near the cost of one.
      assertTrue($elapsed < 1.5, sprintf('transfers appear to be serialised (%.0f ms)', $elapsed * 1000));
    }
  } finally {
    $stop();
  }
});

test('the queue is drained at shutdown without an explicit flush', function () use ($logDir) {
  list($url, $captureDir, $stop) = startApiStub('shutdown');

  try {
    $command = escapeshellarg(PHP_BINARY)
      . ' ' . escapeshellarg(__DIR__ . '/shutdown-case.php')
      . ' ' . escapeshellarg(__DIR__ . '/../vendor/autoload.php')
      . ' ' . escapeshellarg($logDir)
      . ' ' . escapeshellarg($url);

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    assertSame(0, $exitCode, 'the subprocess failed: ' . implode("\n", $output));
    assertSame(1, count(capturedRequests($captureDir)), 'the shutdown hook never sent the payload');
  } finally {
    $stop();
  }
});

test('flush() is safe with nothing queued and safe to repeat', function () {
  $logger = new NetteLogger();
  $logger->register();
  $logger->flush();
  $logger->flush();
  assertTrue(true, 'unreachable');
});

echo "\n";

if ($failures) {
  echo count($failures) . " failed, $passed passed\n";
  exit(1);
}

echo "all $passed passed\n";
exit(0);

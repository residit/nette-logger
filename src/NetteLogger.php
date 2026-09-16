<?php

declare(strict_types=1);

namespace Residit\NetteLogger;

use Nette\Security\IIdentity;
use Nette\Security\User;
use Tracy\BlueScreen;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\ILogger;
use Tracy\Logger;

class NetteLogger extends Logger
{
  /**
   * @var User|null $user  Lazily resolved at log() time to avoid starting the
   * session / caching the auth state during DI container initialization.
   */
  private $user = null;

  /**
   * @var string $url
   */
  private $url = '';

  /**
   * @var string $proxy
   */
  private $proxy = '';

  /**
   * @var string|null $token
   */
  private $token = null;

  /**
   * Tracy\Logger::__construct() declares $directory without a default value.
   * Since nette/di 3.1 the container no longer autowires such built-in typed
   * parameters as null, so the service could not be instantiated at all.
   * Defaults are provided here; register() fills in the real values.
   *
   * @param string|string[]|null $email
   */
  public function __construct(?string $directory = null, $email = null, ?BlueScreen $blueScreen = null)
  {
    parent::__construct($directory, $email, $blueScreen);
  }

  public function setIdentity(User $user)
  {
    // Store the User reference only. Do NOT resolve the identity here: this
    // setter runs during DI container initialize() (Tracy::setLogger), i.e.
    // before presenters set their session namespace. Calling getIdentity()
    // here would start the session and cache the auth state under the default
    // namespace, breaking apps that use Nette\Security\User storage namespaces
    // (e.g. $user->getStorage()->setNamespace('app')).
    $this->user = $user;
  }

  public function setUrl(string $url)
  {
    $this->url = $url;
  }

  public function setProxy(string $proxy) {
    $this->proxy = $proxy;
  }

  public function setToken(string $token)
  {
    $this->token = $token;
  }

  /**
   * Init logging directory
   */
  public function register()
  {
    $this->directory = Debugger::$logDirectory;
    $this->email = & Debugger::$email;
  }

  /**
   * Process the error and log it
   *
   * Signature is intentionally left untyped so that it stays compatible with
   * both the old (`log($value, $priority)`) and the current Tracy
   * (`log(mixed $value, string $level)`) declaration.
   *
   * @param mixed $value
   * @param string $priority
   * @return string|null
   */
  public function log($value, $priority = ILogger::INFO)
  {
    $response = parent::log($value, $priority);
    $userId = null;
    $url = null;

    // Resolve identity lazily, at log time, when the correct session namespace
    // is already in effect.
    $identity = $this->user ? $this->user->getIdentity() : null;
    if ($identity instanceof IIdentity) {
      $userId = $identity->getId();
    }

    if (isset($_SERVER['REQUEST_URI']) && isset($_SERVER['HTTP_HOST'])) {
      $https = isset($_SERVER['HTTPS']) && strcasecmp((string) $_SERVER['HTTPS'], 'off') !== 0;
      $url = 'http' . ($https ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    }

    $logData = [
      'title' => null,
      'type' => $priority,
      'url' => $url,
      'file' => null,
      'line' => null,
      'hash' => null,
      'userId' => $userId,
      'html' => null,
    ];

    if ($value instanceof \Throwable) {
      $logData['title'] = $value->getMessage();
      $logData['file'] = $value->getFile();
      $logData['line'] = $value->getLine();

      // parent::log() returns the bluescreen filename, but only when a log
      // directory is configured; guard against null to avoid a TypeError.
      if (is_string($response) && $response !== '') {
        $logData['hash'] = $this->extractHash($response);
        $html = @file_get_contents($response);
        $logData['html'] = $html === false ? null : $html;
      }
    } elseif (is_array($value) || is_object($value)) {
      $options = [
        Dumper::THEME => "dark",
        Dumper::COLLAPSE => false,
        Dumper::KEYS_TO_HIDE => ['password', 'secret', 'token', 'container', 'connection', 'database', 'db', 'linkGenerator']
      ];

      if(is_array($value)) {
        $logData['title'] = "Array log";
      } else {
        $logData['title'] = "Object log - " . get_class($value);
      }
      $logData['html'] = Dumper::toHtml($value, $options);
    } else {
      $logData['title'] = $value;
    }

    if ($this->url !== '') {
      $this->send($logData);
    }

    return $response;
  }

  /**
   * Send the prepared payload to the API. Only called once an API url is
   * configured. Override to plug in a different transport.
   *
   * @param array<string, mixed> $logData
   */
  protected function send(array $logData): void
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->url);
    if ($this->proxy !== '') {
      curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
    }
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $logData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Auth-Token: ' . (string) $this->token]);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 300);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 400);

    curl_exec($ch);
    curl_close($ch);
  }

  /**
   * extract hash
   *
   * @param string $string
   * @return string|null
   */
  protected function extractHash(string $string): ?string
  {
    $pattern = '/--\d{4}-\d{2}-\d{2}--\d{2}-\d{2}--([a-zA-Z0-9]+)\./';

    if (preg_match($pattern, $string, $matches)) {
        return $matches[1];
    }

    return null;
  }
}

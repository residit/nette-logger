<?php

declare(strict_types=1);

namespace Residit\NetteLogger;

use Nette\Security\Identity;
use Nette\Security\User;
use Nette\Utils\Json;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\ILogger;
use Tracy\Logger;

class NetteLogger extends Logger
{
  /**
   * @var Identity $identity
   */
  private $identity;

  /**
   * @var string $url
   */
  private $url;

  /**
   * @var string $proxy
   */
  private $proxy;

  /**
   * @var null $token
   */
  private $token = null;

  public function setIdentity(User $user)
  {
    $this->identity = $user->getIdentity();
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
   * @param mixed $value
   * @param string $priority
   * @return bool|string|null
   * @throws \Nette\Utils\JsonException
   */
  public function log($value, $priority = ILogger::INFO)
  {
    $response = parent::log($value, $priority);
    $userId = null;
    $url = null;

    if ($this->identity) {
      $userId = $this->identity->getId();
    }

    if (isset($_SERVER['REQUEST_URI']) && isset($_SERVER['HTTP_HOST'])) {
      $url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
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
      $hash = $this->extractHash($response);
      $logData['title'] = $value->getMessage();
      $logData['file'] = $value->getFile();
      $logData['line'] = $value->getLine();
      $logData['hash'] = $hash;
      $logData['html'] = file_get_contents($response);
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
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->url);
    curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $logData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Auth-Token: ' . $this->token]);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 300);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 400);

    curl_exec($ch);
    curl_close($ch);

    return $response;
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

<?php

declare(strict_types=1);

namespace Residit\NetteLogger;

use Nette\Http\Session;
use Nette\Security\Identity;
use Nette\Security\User;
use Nette\Utils\Json;
use Tracy\Debugger;
use Tracy\ILogger;
use Tracy\Logger;

class NetteLogger extends Logger
{
  /**
   * @var Identity $identity
   */
  private $identity;

  /**
   * @var Session $session
   */
  private $session;

  /**
   * @var string $url
   */
  private $url;

  /**
   * @var null $token
   */
  private $token = null;

  /**
   * @var array $userData
   */
  private $userData = [];

  public function setIdentity(User $user)
  {
    $this->identity = $user->getIdentity();
  }

  public function setSession(Session $session)
  {
    $this->session = $session;
  }

  public function setUrl(string $url)
  {
    $this->url = $url;
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
    $sessionData = null;

    if ($this->identity) {
      $userId = $this->identity->getId();
    }

    if ($this->session) {
      foreach ($this->session->getIterator() as $section) {
        foreach ($this->session->getSection($section)->getIterator() as $key => $val) {
          $sessionData[$section][$key] = $val;
        }
      }
    }

    $htmlWithoutScriptTags = preg_replace('#<script(.*?)>(.*?)</script>#is', '', file_get_contents($response));
    $url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";

    $logData = array(
      'title' => $value->getMessage(),
      'type' => $priority,
      'url' => $url,
      'file' => $value->getFile(),
      'trace' => Json::encode($value->getTrace()),
      'line' => $value->getLine(),
      'session' => Json::encode($sessionData),
      'userId' => $userId,
      'html' => $htmlWithoutScriptTags,
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $logData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Auth-Token: ' . $this->token]);

    $response = curl_exec($ch);
    $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $this->checkResponse($responseHttpCode, $response);

    return $response;
  }

  /**
   * Handle response states
   *
   * @param $httpCode
   * @param $response
   * @throws \Nette\Utils\JsonException
   */
  public function checkResponse($httpCode, $response)
  {
    switch ($httpCode):
      case 401:
      case 201:
        $response = Json::decode($response)->status;
        $this->logStatusToConsole($response);
        break;
      default:
        $this->logStatusToConsole('Sending to API error, check API endpoint or url in config file!');
    endswitch;
  }

  public function logStatusToConsole($text)
  {
    echo("<script>console.log('%cNette Logger:', 'background: #d50000; color: #fff;', ' " . $text . "')</script>");
  }
}

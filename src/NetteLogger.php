<?php

declare(strict_types=1);

namespace Residit\NetteLogger;

use Nette\Http\Session;
use Nette\Security\IIdentity;
use Nette\Security\User;
use Nette\Utils\Json;
use Tracy\Debugger;
use Tracy\ILogger;
use Tracy\Logger;

class NetteLogger extends Logger
{
    /** @var IIdentity */
    private $identity;

    /** @var Session */
    private $session;

    /** @var array */
    private $userData = [];

    /** @var string */
    private $token = null;

    public function register()
    {
        $this->directory = Debugger::$logDirectory;
    }

    public function setUser(User $user)
    {
        $this->identity = $user->getIdentity();
    }

    public function setSession(Session $session)
    {
      $this->session = $session;
    }

    public function setUserData(array $userData)
    {
        $this->userData = $userData;
    }

    public function setToken(string $token)
    {
        $this->token = $token;
    }

    public function log($value, $priority = ILogger::INFO)
    {
        $response = parent::log($value, $priority);
        $userData = null;
        $sessionData = null;

        if ($this->identity) {
            $userData = [
                'id' => $this->identity->getId(),
            ];

            foreach ($this->userData as $name) {
                $userData[$name] = $this->identity->{$name} ?? null;
            }
        }

        if ($this->session) {
            foreach ($this->session->getIterator() as $section) {
                foreach ($this->session->getSection($section)->getIterator() as $key => $val) {
                    $sessionData[$section][$key] = $val;
                }
            }
        }

        $htmlWithoutScriptTags = preg_replace('#<script(.*?)>(.*?)</script>#is', '', file_get_contents($response));

        $json = array(
          'title' => $value->getMessage(),
          'type' => $priority,
          'url' => $value->getFile(),
          'trace' => Json::encode($value->getTrace()),
          'line' => $value->getLine(),
          'session' => Json::encode($sessionData),
          'user' => Json::encode($userData),
          'html' => $htmlWithoutScriptTags,
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,"http://log.residit.loc/api/v1/log");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Auth-Token: ' . $this->token]);

        $response = curl_exec ($ch);

        curl_close ($ch);

        bdump($response);

        return $response;
    }
}

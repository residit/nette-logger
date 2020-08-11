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
    private $userFields = [];

    /** @var array */
    private $priorityMapping = [];

    public function register(string $dsn, string $environment)
    {
        // $dns, $environment

        $this->email = & Debugger::$email;
        $this->directory = Debugger::$logDirectory;
    }

    public function setUser(User $user)
    {
        $this->identity = $user->getIdentity();
    }

    public function setUserFields(array $userFields)
    {
        $this->userFields = $userFields;
    }

    public function setPriorityMapping(array $priorityMapping)
    {
        $this->priorityMapping = $priorityMapping;
    }

    public function setSession(Session $session)
    {
        $this->session = $session;
    }

    public function log($value, $priority = ILogger::INFO)
    {
        $response = parent::log($value, $priority);
        $severity = $this->priorityToSeverity($priority);

        $userFields = null;
        $data = null;

        // Configurable error mapping
        if (!$severity) {
            $mappedSeverity = $this->priorityMapping[$priority] ?? null;
            if ($mappedSeverity) {
                $severity = (string) $mappedSeverity;
            }
        }

        // We do not have severity - do not log anything
        if (!$severity) {
            return $response;
        }

        if ($this->identity) {
            $userFields = [
                'id' => $this->identity->getId(),
            ];
            foreach ($this->userFields as $name) {
                $userFields[$name] = $this->identity->{$name} ?? null;
            }
        }
        if ($this->session) {
            $data = [];
            foreach ($this->session->getIterator() as $section) {
                foreach ($this->session->getSection($section)->getIterator() as $key => $val) {
                    $data[$section][$key] = $val;
                }
            }
        }

        /*
        if ($value instanceof \Throwable) {
            captureException($value);
        } else {
            captureMessage($value);
        }
        */

        $json = array(
          'title' => $value->getMessage(),
          'type' => $severity,
          'url' => $value->getFile(),
          'trace' => Json::encode($value->getTrace()),
          'line' => $value->getLine(),
          'session' => Json::encode($data),
          'user' => Json::encode($userFields),
          'html' => file_get_contents($response),
        );

        bdump($json, 'JSON pro API');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,"http://log.residit.loc/api/v1/log");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $headers = [
          'X-Auth-Token: 4b43b0aee35624cd95b910189b3dc231'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $server_output = curl_exec ($ch);

        curl_close ($ch);

        bdump(Json::decode($server_output));

        return $response;
    }

    private function priorityToSeverity(string $priority)
    {
        switch ($priority) {
            case ILogger::DEBUG:
                return 'debug';
            case ILogger::INFO:
                return 'info';
            case ILogger::WARNING:
                return 'warning';
            case ILogger::ERROR:
            case ILogger::EXCEPTION:
                return 'error';
            case ILogger::CRITICAL:
                'fatal';
            default:
                return null;
        }
    }
}

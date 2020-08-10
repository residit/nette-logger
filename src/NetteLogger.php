<?php

declare(strict_types=1);

namespace Residit\NetteLogger;

use Cassandra\Date;
use Nette\Http\Session;
use Nette\Security\IIdentity;
use Nette\Security\User;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Nette\Utils\Random;
use Sentry\ClientBuilder;
use Sentry\Integration\RequestIntegration;
use Sentry\Severity;
use Sentry\State\Hub;
use Tracy\Debugger;
use Tracy\ILogger;
use Tracy\Logger;
use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;

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
        $options = new \Sentry\Options([
            'dsn' => $dsn,
            'environment' => $environment,
            'default_integrations' => false,
        ]);

        $options->setIntegrations([
            new RequestIntegration($options),
        ]);

        $builder = new ClientBuilder($options);
        $client = $builder->getClient();
        Hub::setCurrent(new Hub($client));

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

        $date = new DateTime();

        $json = array(
          'token' => md5(Random::generate(25)),
          'file' => $value->getFile(),
          'severity' => $severity,
          'line' => $value->getLine(),
          'message' => $value->getMessage(),
          'trace' => Json::encode($value->getTrace()),
          'user' => Json::encode($userFields),
          'session' => Json::encode($data),
          'html' => file_get_contents($response),
          'datetime' => $date->format('Y-m-d H:i:s')
        );

        bdump($json, 'JSON pro API');

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

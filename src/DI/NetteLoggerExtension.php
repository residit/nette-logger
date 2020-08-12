<?php

declare(strict_types=1);

namespace Residit\NetteLogger\DI;

use Nette\DI\CompilerExtension;
use Tracy\Debugger;
use Tracy\ILogger;

class NetteLoggerExtension extends CompilerExtension
{
    private const PARAM_TOKEN = 'token';
    private const PARAM_USER_DATA = 'user_data';

    private $defaults = [
        self::PARAM_TOKEN => null,
        self::PARAM_USER_DATA => [],
    ];

    private $enabled = false;

    public function loadConfiguration()
    {
        $this->validateConfig($this->defaults);

        if (!$this->config[self::PARAM_TOKEN]) {
            Debugger::log('Unable to initialize NetteLogger, token config option is missing', ILogger::WARNING);
            return;
        }

        $this->enabled = true;

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('netteLogger'))
            ->setFactory(\Residit\NetteLogger\NetteLogger::class)
            ->addSetup(
                'register',
                [
                ]
            )->addSetup(
                'setUserData',
                [
                    $this->config[self::PARAM_USER_DATA],
                ]
            )->addSetup(
                'setToken',
                [
                    $this->config[self::PARAM_TOKEN],
                ]
            );
    }

    public function beforeCompile()
    {
        if (!$this->enabled) return;

        $builder = $this->getContainerBuilder();

        if ($builder->hasDefinition('tracy.logger')) {
            $builder->getDefinition('tracy.logger')->setAutowired(false);
        }

        if ($builder->hasDefinition('security.user')) {
            $builder->getDefinition($this->prefix('netteLogger'))
                ->addSetup('setUser', [$builder->getDefinition('security.user')]);
        }

        if ($builder->hasDefinition('session.session')) {
            $builder->getDefinition($this->prefix('netteLogger'))
                ->addSetup('setSession', [$builder->getDefinition('session.session')]);
        }
    }

    public function afterCompile(\Nette\PhpGenerator\ClassType $class)
    {
        if (!$this->enabled) return;

        $class->getMethod('initialize')
            ->addBody('Tracy\Debugger::setLogger($this->getService(?));', [ $this->prefix('netteLogger') ]);
    }
}

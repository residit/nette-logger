<?php

declare(strict_types=1);

namespace Residit\NetteLogger\DI;

use Nette\DI\CompilerExtension;
use Nette\PhpGenerator\ClassType;
use Residit\NetteLogger\NetteLogger;

class NetteLoggerExtension extends CompilerExtension
{
  /**
   * @var string $extensionPrefix
   */
  private $extensionPrefix = 'logger';

  /**
   * Load configuration of extension
   */
  public function loadConfiguration()
  {
    $builder = $this->getContainerBuilder();

    $builder->addDefinition($this->prefix($this->extensionPrefix))
      ->setFactory(NetteLogger::class)
      ->addSetup(
        'register', []
      )->addSetup(
        'setUrl',
        [
          $this->config['url']
        ]
      )->addSetup(
        'setToken',
        [
          $this->config['token']
        ]
      );
  }

  /**
   * Pass data to extension before compiling to PHP class
   */
  public function beforeCompile()
  {
    $builder = $this->getContainerBuilder();

    if ($builder->hasDefinition('tracy.logger')) {
      $builder->getDefinition('tracy.logger')->setAutowired(false);
    }

    if ($builder->hasDefinition('security.user')) {
      $builder->getDefinition($this->prefix($this->extensionPrefix))
        ->addSetup('setIdentity', [$builder->getDefinition('security.user')]);
    }

    if ($builder->hasDefinition('session.session')) {
      $builder->getDefinition($this->prefix($this->extensionPrefix))
        ->addSetup('setSession', [$builder->getDefinition('session.session')]);
    }
  }

  /**
   * Initializing logger class after compiling into PHP class
   * @param ClassType $class
   */
  public function afterCompile(ClassType $class)
  {
    $class->getMethod('initialize')
      ->addBody('Tracy\Debugger::setLogger($this->getService(?));', [$this->prefix($this->extensionPrefix)]);
  }
}

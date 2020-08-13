<?php

declare(strict_types=1);

namespace Residit\NetteLogger\DI;

use Nette;
use Nette\Schema\Expect;
use Nette\DI\CompilerExtension;
use Nette\PhpGenerator\ClassType;
use Residit\NetteLogger\NetteLogger;

class NetteLoggerExtension extends CompilerExtension
{
  /**
   * @var string $prefix
   */
  private $prefix;

  /**
   * Check configuration of extension
   * @return Nette\Schema\Schema
   */
  public function getConfigSchema(): Nette\Schema\Schema
  {
    return Expect::structure([
      'url' => Expect::string(),
      'token' => Expect::string(),
      'userData' => Expect::mixed()->nullable()
    ]);
  }

  /**
   * Load configuration of extension
   */
  public function loadConfiguration()
  {
    $this->prefix = 'logger';
    $builder = $this->getContainerBuilder();

    $builder->addDefinition($this->prefix($this->prefix))
      ->setFactory(NetteLogger::class)
      ->addSetup(
        'register', []
      )->addSetup(
        'setUrl', [$this->config->url]
      )->addSetup(
        'setToken', [$this->config->token]
      )->addSetup(
        'setUserData', [$this->config->userData]
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
      $builder->getDefinition($this->prefix($this->prefix))
        ->addSetup('setIdentity', [$builder->getDefinition('security.user')]);
    }

    if ($builder->hasDefinition('session.session')) {
      $builder->getDefinition($this->prefix($this->prefix))
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
      ->addBody('Tracy\Debugger::setLogger($this->getService(?));', [$this->prefix($this->prefix)]);
  }
}

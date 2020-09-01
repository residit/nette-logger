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
   * @var bool $enabled
   */
  private $enabled = false;

  private const PARAM_URL = 'url';
  private const PARAM_TOKEN = 'token';

  private $defaults = [
    self::PARAM_URL => '',
    self::PARAM_TOKEN => ''
  ];

  /**
   * Load configuration of extension
   */
  public function loadConfiguration()
  {
    $this->validateConfig($this->defaults);

    if (!($this->config[self::PARAM_URL] && $this->config[self::PARAM_TOKEN])) {
      return;
    }

    $this->enabled = true;
    $builder = $this->getContainerBuilder();

    $builder->addDefinition($this->prefix($this->extensionPrefix))
      ->setFactory(NetteLogger::class)
      ->addSetup(
        'register', []
      )->addSetup(
        'setUrl',
        [
          $this->config[self::PARAM_URL] ?? ''
        ]
      )->addSetup(
        'setToken',
        [
          $this->config[self::PARAM_TOKEN] ?? ''
        ]
      );
  }

  /**
   * Pass data to extension before compiling to PHP class
   */
  public function beforeCompile()
  {
    if (!$this->enabled) return;

    $builder = $this->getContainerBuilder();

    if ($builder->hasDefinition('tracy.logger')) {
      $builder->getDefinition('tracy.logger')->setAutowired(false);
    }

    if ($builder->hasDefinition('security.user')) {
      $builder->getDefinition($this->prefix($this->extensionPrefix))
        ->addSetup('setIdentity', [$builder->getDefinition('security.user')]);
    }
  }

  /**
   * Initializing logger class after compiling into PHP class
   * @param ClassType $class
   */
  public function afterCompile(ClassType $class)
  {
    if (!$this->enabled) return;

    $class->getMethod('initialize')
      ->addBody('Tracy\Debugger::setLogger($this->getService(?));', [$this->prefix($this->extensionPrefix)]);
  }
}

<?php

declare(strict_types=1);

namespace Residit\NetteLogger\DI;

use Nette\DI\CompilerExtension;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
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
  private const PARAM_PROXY = 'proxy';
  private const PARAM_TOKEN = 'token';

  /**
   * Config schema. Replaces the deprecated validateConfig() call, which is
   * scheduled for removal in nette/di 4.0.
   */
  public function getConfigSchema(): Schema
  {
    return Expect::structure([
      self::PARAM_URL => Expect::string('')->dynamic(),
      self::PARAM_PROXY => Expect::string('')->dynamic(),
      self::PARAM_TOKEN => Expect::string('')->dynamic(),
    ]);
  }

  /**
   * Load configuration of extension
   */
  public function loadConfiguration()
  {
    $config = $this->config;

    if (!($config->{self::PARAM_URL} && $config->{self::PARAM_TOKEN})) {
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
          $config->{self::PARAM_URL}
        ]
      )->addSetup(
        'setProxy',
        [
          $config->{self::PARAM_PROXY}
        ]
      )->addSetup(
        'setToken',
        [
          $config->{self::PARAM_TOKEN}
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

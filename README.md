# Nette Logger

Tracy logger extension capable of logging messages and errors to API.

*Note*: If you have debug mode enabled in your application, logger will only send `\Tracy\Debugger::log()` messages to API.

You can disable debug mode by inserting the lines below in file *app/bootstrap.php*

```php
$configurator->setDebugMode(false);
```

## Requirements

- PHP 7.2+ (tested on PHP 8.4)
- Tracy 2.8+, Nette DI / Security / HTTP 3.x

## Installation

Install package via Composer:

```
composer require residit/nette-logger
```

## Configuration

Enable and configure the extension in Nette config file:

```neon
extensions:
	# ...
	netteLogger: Residit\NetteLogger\DI\NetteLoggerExtension

netteLogger:
    url: https://api-url.com/api/v1
    proxy: 192.168.0.100:1234 (optional)
    token: ae27a4b4821b13cad2a17a75d219853e
```

## Tests

```
composer install
php tests/run-tests.php
```

The suite is dependency-free on purpose: the supported range spans PHP 7.2 to
8.4 and no single PHPUnit major covers it. CI runs it on every supported PHP
version, and additionally against the lowest allowed dependency versions.

## Usage

Once enabled as extension, you can continue to throw exceptions without any change. If you do not fill configuration, plugin will stay off.

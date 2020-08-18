# Nette Logger

Tracy logger extension capable of logging messages and errors to API.

*Note*: If you have debug mode enabled in your application, logger will only send `\Tracy\Debugger::log()` messages to API.

You can disable debug mode by inserting the lines below in file *app/bootstrap.php*

```php
$configurator->setDebugMode(false);
```

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
    token: ae27a4b4821b13cad2a17a75d219853e
```

## Usage

Once enabled as extension, you can continue to throw exceptions without any change. If you do not fill configuration, plugin will stay off.

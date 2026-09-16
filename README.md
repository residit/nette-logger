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

    # optional, milliseconds
    connectTimeout: 1000
    timeout: 3000

    # optional, see below
    finishRequest: true
```

## How sending works

Logging does not wait for the API. `log()` hands the payload to cURL and
returns immediately, so the transfer travels while the application carries on.
Anything still outstanding is collected once, at the end of the request, and
several entries from the same request go out in parallel rather than one after
another.

Under PHP-FPM (and LiteSpeed) the response is released to the client before
that wait happens, so the visitor never pays for it. That is what
`finishRequest` controls. Set it to `false` if something in your application
still needs to write output from a shutdown function registered after the first
log call -- otherwise leave it on.

Because the wait moved out of the request, the timeouts no longer have to be
tuned to stay invisible; the defaults are far more generous than the 300/400 ms
the logger used to run with, which means fewer entries are dropped. They still
occupy a worker for up to `timeout` after the response, so lower them if you
log heavily.

One trade-off worth knowing: if the process dies without running its shutdown
functions (a segfault or the memory limit, say), entries queued in that request
are lost. Tracy's own file log is written before any of this and is unaffected.

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

# pyritephp-core

PyritePHP Core

This is intended as the core dependency for [PyritePHP](https://github.com/vphantom/pyrite-php) based projects.

## Usage

```sh
$ composer install pyritephp-core
```

Note that this is done automatically when setting up a PyritePHP project with its `make init` (including installing Composer itself locally).

```php
<?php

// Load dependencies provided by Composer
require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Pyrite
Pyrite::bootstrap(__DIR__);

// Route request
Pyrite::run();

// Shut down
Pyrite::shutdown();
```
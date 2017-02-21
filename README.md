# PyritePHP

[![license](https://img.shields.io/github/license/vphantom/pyritephp.svg?style=plastic)]() [![GitHub release](https://img.shields.io/github/release/vphantom/pyritephp.svg?style=plastic)]()

PHP 5 and Bootstrap 3 framework to kick-start multilingual web application development

Simple event-driven framework for creating PHP 5 applications backed by a PDO database and with a Twitter Bootstrap user interface.  Emphasis has been given on security:

* SQL queries use placeholders, of course, and a whitelist for column names;
* User passwords are saved in cryptographically secure hash form in the database;
* Twig templating has escaping enabled globally by default;
* Sessions are tied to the browser's IP address and fingerprint to reduce the risk of hijacking;
* Form displays are tied to the current session to elimiate duplicate submissions and further reduce the risks associated with session hijacking and scripted attacks;
* New users require e-mail confirmation to become active;
* E-mail and password changes require password re-entry and trigger e-mail notifications;
* Registration and password reset processes don't leak whether an e-mail is already signed up;
* Covering 98% of users, forms are validated client-side to improve responsiveness.

Just use this repo as your starting point, modify this file and the contents of `modules/` and `templates/` to suit your needs.  The rest of the developer documentation can be found in [Developers](DEVELOPERS.md).

### Why the name "Pyrite"?

This framework was actually built as the starting point for a commercial project named "PyriteView" as a bilingual play on the words "Peer Review".  The framework was then forked on its own as it is highly generic.  The name "PyritePHP" then made sense, considering its origin.

### Version 0.9.57-prerelease

All the planned functionality is included, however some API design is still slightly in flux (i.e. some event vs class choices).  If you're looking to fork, this is probably a good enough starting point, however if you'd like future bug fixes and updates, you might want to wait for version 1.0.

## Usage

### Manually from scratch

```sh
$ composer require vphantom/pyritephp
```

Then in your main PHP file:

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

The above program will only load up and fire a single event if one is
specified from the CLI.  Our example application's installation is launched
with, for example:

```sh
$ php ./index.php --trigger install
```

### Automatically from our example application

You probably want to use our [example basic application](https://github.com/vphantom/pyritephp-example) as a full-featured starting point instead, for example if you want to create `foo/`:

```sh
$ composer create-project vphantom/pyritephp-example foo
```

### Requirements

* PHP 5.5 or later
* PHP extension modules: mbstring, mcrypt, pdo_sqlite, readline
* SQLite 3
* Typical Linux command line tools: make, wget, gzip
* A web server of course

### Web Server Configuration

In order to produce clean, technology-agnostic URLs such as `http://www.yourdomain.com/articles/127`, you need to tell your web server to internally redirect requests for non-existent files to `/index.php`, which will look in `PATH_INFO` for details.  We also want to prevent access to private files.

Here are sample configurations for major server software:

#### Apache

```
RewriteEngine on

RewriteRule ^(bin|lib|modules|node_modules|templates|var|vendor) - [F,L,NC]

RewriteRule ^$ /index.php [L]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.+) /index.php/$1 [L]
```

#### Nginx

```
location ~ /(bin|lib|modules|node_modules|templates|var|vendor) {
    deny all;
    return 404;
}

location ~ \.php$ {
	# Usual FastCGI configuration
}

location / {
    index index.html index.htm index.php;
    try_files $uri $uri/ $uri/index.php /index.php;
}
```

#### Lighttpd

```
# TODO: Deny private directories
url.rewrite-if-not-file (
    "^(.*)$" => "/index.php/$1"
    "^/(.*)$" => "/index.php/$1"
)
```

#### Configuration

If you used our example application, edit `config.ini` to change any defaults as needed and run `make init`.  This will automatically download and set up the latest version of PHP's Composer package manager in the current directory, then use it to download runtime dependencies locally.  Finally, it will create the database tables and the administrative user so you can log into your new installation.  You will be prompted on the command line for an e-mail address and password to use for that unrestricted account.  (**NOTE:** This prompt requires PHP's `readline`, so *it will not work on Windows*.)

You will also need to make sure that your web server or PHP process has read-write access to the `var/` directory where the database and various other files are stored.  This is not done by the `Makefile` because it requires root access and knowledge of which group your web server is a part of (often `www-data`, but not always):

```sh
mkdir var
chgrp www-data var
chmod 6770 var
cd var
mkdir sessions twig_cache
chmod 6770 sessions twig_cache
```


## Updating

While the example skeleton isn't upgradeable since it consisted of a simple starting point for your own application, everything else is one `composer update` away, conveniently available in `make update` if you used the example application.


## Acknowledgements

This application would not have been possible within a reasonable time frame without the help of the following:

### Server-side

* The PHP language
* The SQLite database engine
* The Sphido Events library to facilitate event-driven design
* The Twig PHP templating library
* The 100% PHP Gettext implementation

### Client-side

#### Frameworks

* jQuery 2
* Twitter Bootstrap 3, including its gracious Glyphicon license

#### Utilities

* ParsleyJS to validate forms client-side
* Selectize to create rich, interactive form inputs
* Timeago to display human-readable timestamp descriptions

### Build Tools

* Browserify
* Clean-CSS
* Uglify-JS


## MIT License

Copyright (c) 2016 Stephane Lavergne <https://github.com/vphantom>

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

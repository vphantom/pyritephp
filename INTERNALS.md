# PyritePHP Internals

Here's a brief run-through of PyritePHP itself:

## Assets

Everything needed to build an application is in `assets/`.  They are automatically used by our example application's build process.

### CSS

Resources which pertain to components provided by PyritePHP itself have stylesheets here.

### JavaScript

UI components we built or configure in addition to Twitter Bootstrap are bundled in a single file `pyriteview.js` here.

## PHP Source

Everything related to PHP is in `src/`.

### globals.php

Everything the framework does in the global namespace (initialization and event API global functions) and run-time start-up.  Global `$PPHP` is initialized here and anything not required to be global is wrapped in class `Pyrite`.

While higher-level start-up is performed by `Pyrite::bootstrap()`, some definitions need to happen as early as possible and thus run directly when `globals.php` is included.  This is mostly event handler definitions, including invoking the `bootstrap()` method of every internal module which has one.  Those only contain further event handler definitions.

### Pyrite/ACL.php

Our Access Control List implementation.  It ties users to roles, and both users and roles to specific permissions, and provides a simple API for navigating and modifying those concepts.

### Pyrite/AuditTrail.php

Transaction log.  Anything significant is noted using its `log` event and a simple API allows fetching relevant event histories.

### Pyrite/Router.php

Low-level processing of the web request happens here.  This includes maintaining the request object which is available with `grab('request')` and triggering `route/...` events based on the parsed URL and which route event handlers have been registered.

### Pyrite/Sendmail.php

Our wrapper around the generic `Pyrite/Core/Email.php`.  Sends an e-mail to someone by rendering a template.

### Pyrite/Session.php

One step higher than `Router.php`, the session handler maintains `$_SESSION['user']`, the concepts of logging in and out, and the validation of forms using nonce values.

### Pyrite/Templating.php

Our wrapper around Twig which sets our include paths and defines our custom global functions (including Gettext).

### Pyrite/Users.php

The concept of users, including searching.  Because users may need custom fields but are also part of PyritePHP for login purposes, it discovers additional SQL column definitions in `config.ini`.

### Pyrite/Core/Email.php

Generic e-mail sending module with text, HTML, multipart and file attachment support.  It uses `popen()` to invoke the `sendmail` command for sending mail instead of relying on PHP's built-in implementation.

### Pyrite/Core/PDB.php

Abstraction layer on top of PHP's `PDO` class.  It adds partial query building and manipulation, a clean exec/insert/update API and several select utility functions to format the result in useful ways, such as: single value, list, first/second column key/value pairs and 2D associative arrays.

NOTE: While PDB itself, like PDO, is compatible with various engines such as MySQL/MariaDB and SQLite3, PyritePHP currently uses some SQLite3-specific syntax.  MySQL/MariaDB support is planned for the future.

### Pyrite/Core/Watchdog.php

Uses PHP's `set_error_handler()` to dump environment information and stack traces when errors or uncaught exceptions occur.  In production, this can be used to e-mail that information instead of displaying it on the web page.

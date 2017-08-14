# Developers

PyritePHP is completely modular  and thus easily modified or augmented.  Plugins can be added to `modules/` and handle any existing event (see below) as well as provide additional classes and functions.

The build process compiles `modules/*.css` into a single file, so add-on modules just need to supply their own separate CSS file at no cost.

Similarly, the build compiles the framework's JavaScript, `modules/main.js` and their dependencies into a single script, so add-on modules can add content to `modules/main.js` and possibly add their own NPM module (either in the same directory or the application's `node_modules/`).

To summarize, a typical plugin involved with all aspects of PyritePHP would consist of two new files added to `modules/`.  For example:

* `modules/example.php`
* `modules/example.css`

If your CSS refers to additional resources, they should be nested, which will be handled by the build process.  For example:

* `modules/example/image.png`
* `modules/example/font.ttf`

**CAUTION:** This was not tested thoroughly with `cleancss` yet.

### Requirements

In addition to the run-time requirements, for building PyritePHP you will also need:

* NodeJS for its package manager "NPM"

Run `make dev-init` to initially download the requirements for the build process.  Similarly, `make dev-update` will keep your dependencies up to date.

Then, running `make distrib` any time will rebuild `client.css[.gz]` and `client.js[.gz]`.


## Configuration

Global associative array `$PPHP['config']` contains the parsed contents of `config.ini`.  Feel free to add your own custom sections to this file.


## Client-side components beyond Bootstrap

### form.form-horizontal

Given class `form-horizontal`, each of a form's inputs and buttons will become its own group, per Bootstrap 3 example layouts.  Input attribute `data-label` will fill its left-hand label.

### .hidewaway-focus

Not yet documented!

### form.form-leftright

Not yet documented!

### form.form-tight

Not yet documented!

### form.form-compact

Not yet documented!

### .fileupload-single

Not yet documented! (goes together with Twig macro)

### .fileupload-multiple

Not yet documented! (goes together with Twig macro)

### select.advanced

Not yet documented!

### select.keywords

Not yet documented!

### select.users

Not yet documented!

### select.users-create

Not yet documented! (interaction with `#myModal`)

### .timeago

Not yet documented!

### ParsleyJS

Not yet documented!


## Global Functions

### dejoin(*$delim*, *$str*)

Wrapper around PHP's `explode()` which takes care of returning an empty array when `$str` is empty or null.


## Templating

Tempates use the [Twig template engine](http://twig.sensiolabs.org/).

All templates have variable `session` equivalent to `$_SESSION`, `config` which is `$PPHP['config']` as well as `grab()`, `pass()` and `filter()` from the PHP side and the special variable `req` with details about the current request.

Twig's `dump()` is always available and it is forcibly muted when `config.ini`'s `global.debug` is false.  It is therefore safe to leave some of those laying around.  For convenience, `debug()` prints all its arguments when debugging is true and nothing at all when it is false.

PyritePHP adds an extra tag `{% exit %}` to ease in stopping templates partly through, for example in a condition.  This makes templates easier to read and manage than having large blocks of content indented (or worse: not indented, but still part of a big conditional section).

### Common variables

#### config.global.name

Whatever you call your product, per `config.ini`.  Avoids having to change it in multiple places in templates.

#### session.user

Associative array of the user's information, if one is logged in.

#### session.identified

Boolean describing whether a user is currently logged in.

#### req.lang

Current 2-letter lowercase language code.

#### req.default_lang

2-letter lowercase language code for the language configured in `index.php` to be the default for URLs not including a language code.

#### req.base

Either '' or '/xx' where `xx` is the current lowercase language code.  Therefore, all your templates' URLs for dynamic content should use the form:

```html
<a href="{{ req.base }}/foo">foo</a>
```

#### req.path

Path to the current page without leading nor trailing slashes.  This is the entire `PATH_INFO`, excluding the language prefix if applicable.

#### req.path_args

Array of strings making the individual `req.path` sections _excluding_ what was captured by the router.

#### req.binary

If any part of the path was `/=bin/`, it was removed from the path and caused this boolean to be `true`.  This is used internally to keep the templating engine quiet when binary output is desired.

#### req.query

Current query string, including leading '?'.  Since forms should normally use the POST method, GET queries should be considered like ordinary dynamic content.  Thus the full link to the current page should normally be:

```html
<a href="{{ req.base }}/{{ req.path }}{{ req.query }}">...</a>
```

#### req.get / req.post

Equivalents to PHP's `$_GET[]` and `$_POST[]`.

##### Special CGI variable `__arrays[]`

If present in a form, all values of this variable are created if missing in the form data, as an empty `array()`.  This is useful as a companion to `<SELECT MULTIPLE>` for example, to have an empty array instead of no CGI variable at all when no items are selected.  For example:

```html
<input name="__arrays[]" value="keywords">
<input name="__arrays[]" value="countries">
```

The above would guarantee that if CGI variables `keywords` or `countries` are missing, they will be created as empty arrays.

#### req.files

Almost equivalent to PHP's `$_FILES[]` filtered by `is_uploaded_file()` and with the `type` key (MIME Type) replaced with a slightly safer, server-side guess based on PHP's `finfo_file()`.  Also, the four keys from `pathinfo(name)` are also added for convenience in parsing the browser-supplied suggested file name: `dirname`, `basename`, `extension` and `filename`.

#### req.remote_addr

The browser's IP address, as gathered from various PHP and HTTP header information, including common proxy headers.

#### req.host

The server's host name (and possibly port number) as found in the request's `Host` header.

#### req.protocol

Either `http` or `https` based on the status reported in `req.ssl` (below).

#### req.ssl

True if current request appears to have been served using HTTPS.  (This uses various HTTP headers in addition to PHP's `$_SERVER['HTTPS']` which can not always be relied upon.)

#### req.status

An integer between 100 and 599.

#### req.redirect

Either false or a URL to refresh to (typically via META tags in `layout.html`).

### Template Files

Templates are located in `templates/xx/` where `xx` is a lowercase language code such as `en` for English.  Note that if a template doesn't exist in the current language as discovered by the router (see *Router* below), its version per `index.php`'s `PV_DEFAULT_LANG` will be used.  For convenience, if neither exist, it will be looked for directly in `templates/`, which keeps the structure of single-language applications simpler.

Utility template `templates/lib` contains macros used throughout other templates.

Most templates except `lib` and `layout.html` can call `title()` to prepend a section to the page's title, which will be used in `layout.html`.

A built-in router at URL `/page/` maps to templates in `templates/pages/` (with sanitized file names).  This gives you flexibility to create any page you want, while benefitting from Twig templating.  Also, in a multilingual setup, you are free to give pages different file names across languages, yielding user-friendly URLs.

A single template file is mandatory: `layout.html` which is divided into two blocks:

### head

Displayed and flushed to the browser as early as possible.  Should reference resources and *not* include a TITLE tag yet.

Variables available: `session`, `req`

### body

If processing the request was successful, during event `shutdown` this is displayed for the rest of the document, including TITLE, closing HEAD, etc.  As `body` is partial HTML, it should be filtered with `|raw` in the template to avoid escaping.

Variables available: `session`, `req`, `title`, `body`, `stdout`

If `req.status` isn't 200, you may want to display a helpful error message.  Typical codes:

**403** When the user needs to be logged in, but isn't.  (Not 401 to avoid native browser password prompt.)

**404** When no module declared support for the base of the requested URL.

**440** Recommended when an operation fails because the session expired.

**500** When a module handled the requested URL, but returned `false`.

For debugging purposes, `body` also has special variable `stdout` available which contains the output captured from all code that ran between `startup` and `shutdown` events.  Output by any code not using the `render` event to display templates safely ends up here.

Our example code also provides many other templates:

### register.html

Displayed when not logged in for any reason.

### user_edit.html

Tied to `/user/prefs` to edit the current user's details.


## Context

You can set global `$PPHP['contextType']` and `$PPHP['contextId']` to describe your current context in the same sense you would for logging (see "Logging Events" below).  This is useful ahead of invoking components which themselves might trigger logging (for example, sending e-mails).


## Database

Global variable `$PPHP['db']` is available with an instance of the `PDB` wrapper to `PDO`.  See [PDB documentation](lib/PDB.md).


## User

Note that the first three columns defined in our example `modules/user.php` should remain intact: we need a unique ID and we expect to identify users by e-mail address (case-insensitive) and password, which itself is stored as a one-way hash in the database.


## Router

A simple `PATH_INFO` based router dispatches the main processing of a URL to an event named "route/_basename_" or "route/_basename_+_sectionname_".  Processing of this event is expected to return a non-false value, or else the router will issue an HTTP 500 error.

For example, if "/index.php/foo/bar/baz" is requested, the base route is deemed to be `foo` and, if previously declared using the static method below, would trigger the event `route/foo` with the rest of the path as an array argument.  Further, if `foo+bar` was also declared, the event will instead be `route/foo+bar` with the shorter rest of that path as argument.

For example:

```php
class MyClass { ... }

// Handle '/mystuff'
on('route/mystuff', 'MyClass::myStaticMethod');

// Handle '/mystuff/edit'
on('route/mystuff+edit', 'MyClass::myEditMethod');

// Handle /mystuff/delete
on('route/mystuff+delete', 'MyClass::myDeleteMethod');
```

When an empty or root URL is processed, the route will resolve to `main`, therefore to event `route/main`.

Any URL not resolvable to an event handler will yield an HTTP 404 error.

**Note:** A root-level route 2 characters long is removed from the path and used as a language code.  Therefore, if you specify a handler for a 2-character path, it will match on the *second* level of any URL: `/aa/bb/cc` would set the language to `aa` (for templating) and trigger `route/bb+cc`.


## Events

With only the above exceptions, PyritePHP is event-driven to keep its structure completely modular.  It uses the simple and elegant [Sphido Events library](https://github.com/sphido/events) with a couple of handy helper functions thrown in:

#### on($event, $callable[, $priority = 10])

Declare a handler for event named `$event`, which will be called with possible arguments.  (See details in `trigger()`.)  The optional priority allows us to guarantee an order in which handlers for a same event are called.  In PyritePHP, those are between 1 and 99.  Examples:

```php
on('foo', 'MyClass::myStaticMethod');

on('bar', function ($arg1, $arg2) { return $arg2; }, 1);
```

#### trigger(*$event*[, ...])

Calls all handlers registered for `$event`, with any additional arguments optionally passed in.  Returns an array of all the handlers' return values, in the order in which they were called.

#### grab(*$event*[, ...])

Wrapper around `trigger()` which returns the _last_ return value of the handler chain.  This helps cases where events are used for the purpose of returning information.

#### pass(*$event*[, ...])

Wrapper around `trigger()` which tests for the non-falsehood of its _last_ return value.  In other words, if any of the triggered event handlers returned false, which also stops propagation, then `pass()` will return false instead of `trigger()`'s array of all results.  This is great for validation purposes where all handlers should agree.

#### filter(*$event*, $value)

Sphido Events includes a WordPress-inspired filtering mechanism, where chains of handlers can modify input supplied to `filter()`.  This can be useful for formatting add-ons, currency conversion and such.  Note that `add_filter()` is just an alias for `on()`  Quoting the example straight from Sphido's documentation:

```php
add_filter('price', function($price) {
  return (int)$price . ' USD';
});

add_filter('price', function($price) {
  return 'The price is: ' . $price ;
});

echo filter('price', 100); // print The price is: 100 USD
```

Below is a partial list of events present in PyritePHP and its demo/framework application, which may be of interest to developers:

### Global Events

#### install

Invoked when `index.php` is executed from the command line.  Use this to confirm the existence of any database tables your module may need, for example.

#### daily

If configured as recommended, this event is fired from the command line exactly once per day.

#### hourly

If configured as recommended, this event is fired from the command line every hour.

#### startup

Invoked at the very start of the request process.  Used for initialization, for example session handling or loading any database data you are guaranteed to need regardless of request specifics.

#### shutdown

Invoked at the very end of the request process.  Used for proper clean-up.

#### newuser

Triggered when the current user's identity or credentials have changed.  Useful for cache and data invalidation.


### Emailing Events

#### sendmail (*$to*, *$template*[, *$args[]*])

Uses `render_blocks` (below) to load `templates/email/$template` with optional `$args[]`, expecting block `subject` as well as `text` and/or `html`.  A MIME message is then built from those and sent to `$to`.  Should be triggered with `pass()` as it returns its success status.


### Templating Events

#### http_status (*$code*)

The router triggers this to notify the template when we have a 404 or 500 situation.  You may trigger it for other situations as necessary, although after the `startup` phase is complete, headers are already sent to the browser and this will no longer affect the HTTP status, only which template gets displayed for the body of the page.  (See "Templating / Files".)

#### http_redirect (*$url$)

Will be directly relayed to subsequent blocks of `layout.html` being rendered by the templating module.  The URL can be relative.

#### title (*$prepend*[, *$sep*])

Prepends `$prepend` to the current title to be displayed.  If the title wasn't empty, uses `$sep` as separator (default: " - ").

#### section (*$name*)

Set/replace the name of the currently active "section", logical concept used in `layout.html` to highlight/expand` the "current" section.

#### render (*$template*[, *$args[]*])

Render the named `$template` with optional supplied associative `$args[]`.  See *Templating* above for the location of template files.

#### render_blocks (*$template*[, *$args[]*])

Load `$template` with optional supplied associative `$args[]`, then renders each block (if any) found in an associative array (keyed by block name) which is returned, *not displayed*.  Thus it should be triggered with `grab()`.


### Form Events

**YOU SHOULD USE THESE ON ABSOLUTELY EVERY FORM!**

These events require the `$form_name` argument be supplied, which should be unique to the entire application, possibly matching the form's HTML `ID`.  Combined, they guarantee that each form displayed will be submitted at most one single time and that they will not be submitted outside of a valid session.

#### form_begin (*$form_name*)

Generate unique form ID to guarantee one-time validity which expires with the current session.  Should be triggered with `grab()` within templates at the beginning of HTML `FORM` blocks, with `|raw` filtering as this outputs a hidden `INPUT`.

#### form_validate (*$form_name*)

Trigger in your form handling code *before* processing form data.  This allows the cleanup of control values and should be triggered with `pass()` so that processing stops and the form doesn't get acted upon if any validation handler fails.


### Filtering Events

#### body (*$html*)

After the main content area of the current page is buffered, it is passed through this filter.  This allows, for example, automated table of contents generation, link substitutions, etc.

#### clean_email (*$address*)

Sanitizes whatever characters shouldn't be in an e-mail address and reduces to lowercase.

#### clean_name (*$name*)

Sanitizes what shouldn't comprise a typical user input field, such as `<>|\"'` and the backtick and low-ASCII.


### Session Events

#### login (*$email*, *$password*)

Attempts logging in and saves the credentials in the current session if successful.  This should be triggered with `pass()` when processing a login or ID confirmation form.

#### logout

Trigger this event to wipe the current session clean.

#### can (*$verb*[, *$objectType*[, *$objectId*]])

Returns true if the current user is allowed to perform the action named `$verb`, either by itself or acting upon an object of type `$objectType`, possibly a specific instance `$objectId`.  This should be triggered with `pass()` to obtain a clean boolean result.

#### can_sql (*$columnName*, *$verb*, *$objectType*)

Returns an SQL boolean expression (expected to be used as part of a `WHERE` statement, for example) in the form of a `PDBquery` object.  Use with `grab()`.  The resulting query will be true (`1=1`) if the right is granted for all, false (`1=2`) if none is allowed, or a proper condition if one or several are allowed.

For example, if you're looking for the 10 latest articles which the current user can view:

```php
$db = $PPHP['db'];
$query = $db->query("SELECT * FROM articles WHERE");
$partialQuery = grab('can_sql', 'articleId', 'view', 'article');
$query->append($partialQuery)->order_by('articleId DESC')->limit(10);

// Query is something like:
// SELECT * FROM articles WHERE articleId IN (21, 41, 63) ORDER BY articleId DESC LIMIT 10
```

#### has_role (*$role*)

Returns true if the current user is a member of the role named `$role`.

### Logging Events

The optional logging module is used to create an audit trail in the database.

#### log (*$args[]*)

This should be triggered *after* a noteworthy action has taken place successfully.  Possible arguments in the associative array (depending on transaction type):

##### action

Typically one of: `create`, `update`, `delete` or your custom verbs.

##### [objectType]

Class of object being acted upon (i.e. 'article').

##### [objectId]

Specific ID of the object being acted upon.

##### [fieldName]

Name of object's field being affected.

##### [oldValue]

Previous value, if any, of the field being modified.  Requires `fieldName`.

##### [newValue]

New value of the field being modified.  Requires `fieldName`.


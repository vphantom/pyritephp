COMPOSER := `which composer`
PHP      := `which php`
PHPUNIT  := vendor/bin/phpunit -v --colors=auto
JSLINT   := node_modules/.bin/eslint --fix

PHP_TESTABLE := $(wildcard test/*.php)
PHP_COVERAGE := clover.xml

JS_SRC := $(wildcard assets/*.js)

help:
	echo "This is help."

clean:
	rm -fr build

test:	$(PHP_COVERAGE)

lint:
	$(JSLINT) $(JS_SRC)

clover.xml:	$(PHP_TESTABLE)
	$(PHPUNIT) --whitelist src --test-suffix=.php --coverage-html build/coverage --coverage-clover build/logs/clover.xml test

.SILENT:	help

.PHONY:	clean help test lint

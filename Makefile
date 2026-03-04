PORT ?= 8000

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
	
install:
	composer install

validate:
	composer validate

autoload:
	composer dump-autoload

require:
	composer require
	
lint:
	composer exec --verbose phpcs -- --standard=PSR12 src public

lint-fix:
	composer lint-fix
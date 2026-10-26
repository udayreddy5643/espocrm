param(
    [string]$Path = "tests/unit/Espo/Custom/Tools/SmartReminder"
)

docker run --rm -v "${PWD}:/app" --volumes-from espocrm-php -w /app php85-nginx-mariadb-espocrm-php php /var/www/html/phpunit.phar --bootstrap tests/bootstrap-unit.php $Path

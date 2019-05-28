wget https://getcomposer.org/installer



composer require nikic/php-parser
composer require greenlion/php-sql-parser

// TODO, check md5
cp patches/SQLProcessor.php vendor/greenlion/php-sql-parser/src/PHPSQLParser/processors/SQLProcessor.php 
cp patches/FromProcessor.php vendor/greenlion/php-sql-parser/src/PHPSQLParser/processors/FromProcessor.php 

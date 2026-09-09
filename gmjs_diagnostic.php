<?php
require_once __DIR__.'/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
echo "GMJS v4.2\n";
echo "PHP: ".PHP_VERSION."\n";
echo "bootstrap: ".realpath(__DIR__.'/bootstrap.php')."\n";
echo "summary: ".realpath(__DIR__.'/summary_print.php')."\n";
echo "setting(): ".(function_exists('setting')?'YES':'NO')."\n";
echo "db(): ".(function_exists('db')?'YES':'NO')."\n";

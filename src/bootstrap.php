<?php

declare(strict_types=1);

$crowndRoot = dirname(__DIR__);

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Crownd benötigt PHP 8.3 oder neuer. Installiert ist PHP ' . PHP_VERSION . '.';
    exit;
}

if (!is_file($crowndRoot . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Crownd: Der Ordner vendor/ fehlt. Bitte das Release-ZIP verwenden "
        . "oder im Projektordner 'composer install --no-dev' ausführen.";
    exit;
}

require $crowndRoot . '/vendor/autoload.php';

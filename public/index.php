<?php

declare(strict_types=1);

/**
 * Front controller. Point your web server's document root at this directory.
 *
 * For a quick local run:  php -S localhost:8000 -t public
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\App;
use App\Core\Request;

App::handle(Request::capture())->send();

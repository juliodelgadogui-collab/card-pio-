<?php

declare(strict_types=1);

use EventMenu\Core\Database;
use EventMenu\Support\JcLancheDemoSeeder;

require dirname(__DIR__) . '/app/bootstrap.php';

JcLancheDemoSeeder::run(Database::connection());

echo "JC Lanche configurada: Super ADM, administrador, Carlos, 28 itens e 20 mesas.\n";

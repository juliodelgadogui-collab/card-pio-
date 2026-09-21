<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (PHP_SAPI !== 'cli') throw new RuntimeException('Este atualizador deve ser executado via CLI.');
if (!is_file($root . '/.env')) throw new RuntimeException('.env ausente. Não execute update antes da instalação.');
if (!is_file($root . '/storage/installed.lock')) throw new RuntimeException('installed.lock ausente. Use install.sh em uma instalação nova.');
require $root . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;

$pdo = Database::connection();
Migrator::run($pdo);
echo "Migrações EventMenu 1.0.0 aplicadas com sucesso.\n";

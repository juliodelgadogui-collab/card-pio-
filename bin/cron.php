<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\MaintenanceService;

try{$result=(new MaintenanceService())->releaseExpiredReservations();echo '['.date(DATE_ATOM).'] maintenance '.json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;exit(0);}catch(Throwable $e){fwrite(STDERR,'['.date(DATE_ATOM).'] '.$e->getMessage().PHP_EOL);exit(1);}

<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[
  $root.'/database/migrations/080_event_schedule.sql'=>'event_schedule_items',
  $root.'/database/sqlite/migrations/080_event_schedule.sql'=>'event_schedule_items',
  $root.'/app/routes/event-admin.php'=>"section==='schedule'",
  $root.'/public/evento.php'=>'id="programacao"',
];
foreach($checks as$file=>$needle){
    $source=(string)@file_get_contents($file);
    if($source===''||!str_contains($source,$needle)){
        fwrite(STDERR,"Missing event schedule contract: {$file} -> {$needle}\n");
        exit(1);
    }
}
echo "event schedule contract ok\n";

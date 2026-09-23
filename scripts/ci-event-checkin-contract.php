<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$api=(string)@file_get_contents($root.'/public/api.php');
$ticket=(string)@file_get_contents($root.'/src/Services/TicketService.php');
$guest=(string)@file_get_contents($root.'/src/Services/GuestService.php');
$eventRepository=(string)@file_get_contents($root.'/mobile/eventmenu-go/app/src/main/java/br/com/eventmenu/go/data/EventOperationsRepository.kt');
$mainViewModel=(string)@file_get_contents($root.'/mobile/eventmenu-go/app/src/main/java/br/com/eventmenu/go/MainViewModel.kt');

$checks=[
    [$api,"(new TicketService())->checkIn((string)(\$body['token']??''),\$eventId)",'Legacy ticket-checkin must pass event_id.'],
    [$api,"(new GuestService())->checkIn((string)(\$body['code']??''),\$eventId)",'Legacy guest-checkin must pass event_id.'],
    [$api,"JOIN events e ON e.id=t.event_id AND e.tenant_id=t.tenant_id",'Ticket QR resolution must keep event in the same tenant.'],
    [$api,"JOIN events e ON e.id=g.event_id AND e.tenant_id=g.tenant_id",'Guest QR resolution must keep event in the same tenant.'],
    [$ticket,"if(!\$expectedEventId||\$expectedEventId<1)",'Ticket service must require an expected event.'],
    [$guest,"if(!\$expectedEventId||\$expectedEventId<1)",'Guest service must require an expected event.'],
    [$eventRepository,'JSONObject().put("event_id", eventId).put("token", value.trim())','EventMenu GO ticket check-in must send the selected event.'],
    [$eventRepository,'JSONObject().put("event_id", eventId).put("code", value.trim())','EventMenu GO guest check-in must send the selected event.'],
    [$mainViewModel,'eventRepo.ticketCheckIn(eventId,qr.raw)','EventMenu GO scanner must use the event-scoped ticket endpoint.'],
    [$mainViewModel,'eventRepo.guestCheckIn(eventId,qr.raw)','EventMenu GO scanner must use the event-scoped guest endpoint.'],
    [$mainViewModel,'selectedEventId?:throw IllegalStateException("Selecione o evento antes de validar a entrada.")','EventMenu GO scanner must require the currently selected event.'],
];

foreach($checks as[$source,$needle,$message]){
    if($source===''||!str_contains($source,$needle)){
        fwrite(STDERR,"EVENT CHECK-IN CONTRACT FAIL: {$message}\n");
        exit(1);
    }
}

echo "event check-in contract ok\n";

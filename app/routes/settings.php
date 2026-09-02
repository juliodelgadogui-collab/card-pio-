<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('settings.manage');
$tenantId=em_require_tenant();

function settings_cents(string $value):int{
    $value=trim(str_replace(['R$',' '],'',$value));
    if($value==='')return 0;
    if(str_contains($value,','))$value=str_replace(['.',','],['','.'],$value);
    if(!is_numeric($value))throw new RuntimeException('Valor monetário inválido.');
    return max(0,(int)round((float)$value*100));
}
function settings_time(string $value,string $fallback):string{
    $value=trim($value);return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value)?$value:$fallback;
}

$s=$pdo->prepare('SELECT * FROM tenants WHERE id=?');$s->execute([$tenantId]);$tenant=$s->fetch();
$settings=json_decode((string)($tenant['settings']??'{}'),true);if(!is_array($settings))$settings=[];

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    try{
        $name=trim((string)($_POST['name']??''));if($name==='')throw new RuntimeException('Nome inválido.');
        $timezone=trim((string)($_POST['timezone']??'America/Sao_Paulo'));
        if(!in_array($timezone,\DateTimeZone::listIdentifiers(),true))throw new RuntimeException('Fuso horário inválido.');
        $etaMin=max(5,min(1440,(int)($_POST['default_eta_min']??30)));
        $etaMax=max(5,min(1440,(int)($_POST['default_eta_max']??60)));
        if($etaMax<$etaMin)throw new RuntimeException('Prazo máximo não pode ser menor que o mínimo.');
        $slot=(int)($_POST['scheduling_slot_minutes']??30);
        if(!in_array($slot,[10,15,20,30,45,60],true))throw new RuntimeException('Intervalo de agendamento inválido.');
        $lead=max(0,min(1440,(int)($_POST['scheduling_lead_minutes']??30)));
        $days=max(1,min(60,(int)($_POST['scheduling_days_ahead']??7)));
        $capacity=max(0,min(500,(int)($_POST['max_orders_per_slot']??0)));
        $kitchenLead=max(0,min(1440,(int)($_POST['scheduled_kds_lead_minutes']??45)));

        $hours=[];
        for($day=1;$day<=7;$day++){
            $hours[(string)$day]=[
                'enabled'=>isset($_POST['hours_enabled'][$day]),
                'open'=>settings_time((string)($_POST['hours_open'][$day]??'00:00'),'00:00'),
                'close'=>settings_time((string)($_POST['hours_close'][$day]??'23:59'),'23:59'),
            ];
        }

        $settings=array_merge($settings,[
            'delivery_fee_cents'=>settings_cents((string)($_POST['delivery_fee']??'0')),
            'min_delivery_order_cents'=>settings_cents((string)($_POST['min_delivery_order']??'0')),
            'default_delivery_eta_min'=>$etaMin,
            'default_delivery_eta_max'=>$etaMax,
            'points_enabled'=>isset($_POST['points_enabled']),
            'menu_message'=>mb_substr(trim((string)($_POST['menu_message']??'')),0,300),
            'whatsapp'=>mb_substr(trim((string)($_POST['whatsapp']??'')),0,30),
            'timezone'=>$timezone,
            'delivery_paused'=>isset($_POST['delivery_paused']),
            'delivery_pause_reason'=>mb_substr(trim((string)($_POST['delivery_pause_reason']??'')),0,180),
            'pickup_enabled'=>isset($_POST['pickup_enabled']),
            'pickup_paused'=>isset($_POST['pickup_paused']),
            'pickup_pause_reason'=>mb_substr(trim((string)($_POST['pickup_pause_reason']??'')),0,180),
            'scheduling_enabled'=>isset($_POST['scheduling_enabled']),
            'scheduling_slot_minutes'=>$slot,
            'scheduling_lead_minutes'=>$lead,
            'scheduling_days_ahead'=>$days,
            'max_orders_per_slot'=>$capacity,
            'scheduled_kds_lead_minutes'=>$kitchenLead,
            'business_hours'=>$hours,
        ]);
        $up=$pdo->prepare('UPDATE tenants SET name=?,settings=? WHERE id=?');
        $up->execute([$name,json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$tenantId]);
        Auth::audit('tenant.settings','tenant',(string)$tenantId,[
            'delivery_paused'=>(bool)$settings['delivery_paused'],
            'pickup_enabled'=>(bool)$settings['pickup_enabled'],
            'scheduling_enabled'=>(bool)$settings['scheduling_enabled'],
            'timezone'=>$timezone,
        ]);
        em_flash('ok','Configurações salvas.');em_go('settings');
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('settings');}
}

$days=[1=>'Segunda-feira',2=>'Terça-feira',3=>'Quarta-feira',4=>'Quinta-feira',5=>'Sexta-feira',6=>'Sábado',7=>'Domingo'];
$hours=$settings['business_hours']??[];
em_header('Configurações','settings');
?><section class="card" style="max-width:920px"><h2>Empresa e operação</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><label class="span-2">Nome da empresa<input name="name" required value="<?= Security::e($tenant['name']) ?>"></label><label>Taxa padrão de entrega<input name="delivery_fee" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,',','.')) ?>"></label><label>Pedido mínimo padrão<input name="min_delivery_order" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,',','.')) ?>"></label><label>Prazo padrão mín. (min)<input type="number" name="default_eta_min" min="5" max="1440" value="<?= (int)($settings['default_delivery_eta_min']??30) ?>"></label><label>Prazo padrão máx. (min)<input type="number" name="default_eta_max" min="5" max="1440" value="<?= (int)($settings['default_delivery_eta_max']??60) ?>"></label><p class="muted span-2">Esses valores são usados quando não há zona de delivery configurada. Zonas específicas substituem taxa, mínimo e prazo.</p><label class="span-2">WhatsApp<input name="whatsapp" value="<?= Security::e($settings['whatsapp']??'') ?>"></label><label class="span-2">Mensagem no cardápio<textarea name="menu_message"><?= Security::e($settings['menu_message']??'') ?></textarea></label><label class="span-2">Fuso horário<select name="timezone"><?php foreach(['America/Sao_Paulo','America/Manaus','America/Cuiaba','America/Recife','America/Fortaleza','America/Belem','America/Rio_Branco'] as$tz):?><option value="<?= Security::e($tz) ?>"<?= em_selected($settings['timezone']??'America/Sao_Paulo',$tz) ?>><?= Security::e($tz) ?></option><?php endforeach;?></select></label><label class="checkbox span-2"><input type="checkbox" name="points_enabled"<?= em_checked($settings['points_enabled']??true) ?>> Programa de pontos ativo</label><hr class="span-2" style="border-color:var(--line);width:100%"><div class="span-2"><h3>Pedidos online</h3></div><label class="checkbox span-2"><input type="checkbox" name="pickup_enabled"<?= em_checked($settings['pickup_enabled']??true) ?>> Permitir retirada no local</label><label class="checkbox span-2"><input type="checkbox" name="scheduling_enabled"<?= em_checked($settings['scheduling_enabled']??false) ?>> Permitir agendamento de delivery e retirada</label><label>Intervalo dos horários<select name="scheduling_slot_minutes"><?php foreach([10,15,20,30,45,60] as$minutes):?><option value="<?= $minutes ?>"<?= em_selected($settings['scheduling_slot_minutes']??30,$minutes) ?>><?= $minutes ?> min</option><?php endforeach;?></select></label><label>Antecedência mínima (min)<input type="number" min="0" max="1440" name="scheduling_lead_minutes" value="<?= (int)($settings['scheduling_lead_minutes']??30) ?>"></label><label>Dias disponíveis para agendar<input type="number" min="1" max="60" name="scheduling_days_ahead" value="<?= (int)($settings['scheduling_days_ahead']??7) ?>"></label><label>Máx. pedidos por horário<input type="number" min="0" max="500" name="max_orders_per_slot" value="<?= (int)($settings['max_orders_per_slot']??0) ?>"><small class="muted">0 = sem limite</small></label><label class="span-2">Mostrar pedido agendado no KDS com quantos minutos de antecedência?<input type="number" min="0" max="1440" name="scheduled_kds_lead_minutes" value="<?= (int)($settings['scheduled_kds_lead_minutes']??45) ?>"></label><hr class="span-2" style="border-color:var(--line);width:100%"><div class="span-2"><h3>Delivery</h3></div><label class="checkbox span-2"><input type="checkbox" name="delivery_paused"<?= em_checked($settings['delivery_paused']??false) ?>> Pausar novos pedidos delivery</label><label class="span-2">Motivo da pausa<input name="delivery_pause_reason" maxlength="180" value="<?= Security::e($settings['delivery_pause_reason']??'') ?>" placeholder="Ex.: Cozinha lotada; voltamos em breve"></label><div class="span-2"><h3>Retirada</h3></div><label class="checkbox span-2"><input type="checkbox" name="pickup_paused"<?= em_checked($settings['pickup_paused']??false) ?>> Pausar novos pedidos para retirada</label><label class="span-2">Motivo da pausa<input name="pickup_pause_reason" maxlength="180" value="<?= Security::e($settings['pickup_pause_reason']??'') ?>" placeholder="Ex.: Retirada temporariamente indisponível"></label><hr class="span-2" style="border-color:var(--line);width:100%"><div class="span-2"><h3>Horário de funcionamento</h3></div><div class="span-2 table-wrap"><table class="table"><thead><tr><th>Dia</th><th>Ativo</th><th>Abre</th><th>Fecha</th></tr></thead><tbody><?php foreach($days as$day=>$label):$rule=$hours[(string)$day]??$hours[$day]??['enabled'=>true,'open'=>'00:00','close'=>'23:59'];?><tr><td><strong><?= Security::e($label) ?></strong></td><td><input type="checkbox" name="hours_enabled[<?= $day ?>]" value="1"<?= em_checked($rule['enabled']??true) ?>></td><td><input type="time" name="hours_open[<?= $day ?>]" value="<?= Security::e($rule['open']??'00:00') ?>"></td><td><input type="time" name="hours_close[<?= $day ?>]" value="<?= Security::e($rule['close']??'23:59') ?>"></td></tr><?php endforeach;?></tbody></table></div><p class="muted span-2">Horários que atravessam a meia-noite são aceitos, por exemplo 18:00 → 02:00.</p><button class="primary span-2">Salvar configurações</button></form><hr style="border-color:var(--line);margin:24px 0"><h3>Identificação técnica</h3><p>Slug: <code><?= Security::e($tenant['slug']) ?></code><br>Plano: <span class="badge"><?= Security::e($tenant['plan']) ?></span><br>Status: <span class="badge"><?= Security::e($tenant['status']) ?></span></p><p class="muted">O APP_KEY deve permanecer secreto. Alterá-lo depois de cadastrar gateways impedirá descriptografar credenciais existentes.</p></section><?php em_footer();
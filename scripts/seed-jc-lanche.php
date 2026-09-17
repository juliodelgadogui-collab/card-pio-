<?php

declare(strict_types=1);

use EventMenu\Core\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Database::connection();
$passwordHash = password_hash('1', PASSWORD_DEFAULT);

Database::transaction(function (PDO $pdo) use ($passwordHash): void {
    $findTenant = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? OR name = ? LIMIT 1');
    $findTenant->execute(['jc-lanche', 'JC Lanche']);
    $tenantId = (int)($findTenant->fetchColumn() ?: 0);
    if ($tenantId <= 0) {
        $stmt = $pdo->prepare("INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,'premium','active')");
        $stmt->execute(['JC Lanche', 'jc-lanche']);
        $tenantId = (int)$pdo->lastInsertId();
    }

    $upsertUser = static function (PDO $pdo, ?int $tenantId, string $name, string $email, string $role, string $hash): int {
        $q = $pdo->prepare('SELECT id FROM users WHERE lower(email)=lower(?) LIMIT 1');
        $q->execute([$email]);
        $id = (int)($q->fetchColumn() ?: 0);
        if ($id > 0) {
            $u = $pdo->prepare('UPDATE users SET tenant_id=?,name=?,password_hash=?,role=?,status=\'active\',updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $u->execute([$tenantId,$name,$hash,$role,$id]);
            return $id;
        }
        $u = $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,?,\'active\')');
        $u->execute([$tenantId,$name,$email,$hash,$role]);
        return (int)$pdo->lastInsertId();
    };

    $upsertUser($pdo, null, 'Julio Delgado', 'juliodelgadogui@gmail.com', 'super_admin', $passwordHash);
    $upsertUser($pdo, $tenantId, 'Administrador JC Lanche', 'A@1.com', 'admin', $passwordHash);
    $upsertUser($pdo, $tenantId, 'Carlos', '1@1.com', 'delivery', $passwordHash);

    $categories = [
        'Hambúrgueres'=>10,'Combos'=>20,'Porções'=>30,'Bebidas'=>40,'Adicionais'=>50,
    ];
    $categoryIds = [];
    foreach ($categories as $name=>$sort) {
        $q=$pdo->prepare('SELECT id FROM categories WHERE tenant_id=? AND name=? LIMIT 1'); $q->execute([$tenantId,$name]);
        $id=(int)($q->fetchColumn() ?: 0);
        if(!$id){$i=$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,?,1)');$i->execute([$tenantId,$name,$sort]);$id=(int)$pdo->lastInsertId();}
        $categoryIds[$name]=$id;
    }

    $products = [
      ['Hambúrgueres','X-Burguer','Pão, hambúrguer artesanal e queijo',1890,'JC-XB'],
      ['Hambúrgueres','X-Salada','Hambúrguer, queijo, alface, tomate e molho da casa',2190,'JC-XS'],
      ['Hambúrgueres','X-Bacon','Hambúrguer, queijo, bacon crocante e molho da casa',2490,'JC-XBAC'],
      ['Hambúrgueres','X-Egg','Hambúrguer, queijo, ovo e molho especial',2390,'JC-XEGG'],
      ['Hambúrgueres','X-Tudo','Hambúrguer, queijo, bacon, ovo, presunto e salada',2990,'JC-XT'],
      ['Hambúrgueres','Duplo Bacon','Dois hambúrgueres, queijo duplo e bacon',3490,'JC-DB'],
      ['Hambúrgueres','JC Especial','Hambúrguer artesanal, cheddar, bacon, cebola caramelizada e molho JC',3690,'JC-ESP'],
      ['Combos','Combo X-Burguer','X-Burguer + batata P + refrigerante lata',2990,'JC-CXB'],
      ['Combos','Combo X-Bacon','X-Bacon + batata P + refrigerante lata',3590,'JC-CBAC'],
      ['Combos','Combo X-Tudo','X-Tudo + batata M + refrigerante lata',4190,'JC-CXT'],
      ['Combos','Combo Família','4 X-Burguer + batata G + refrigerante 2L',9990,'JC-CFAM'],
      ['Porções','Batata Frita P','Batata frita crocante 200g',1290,'JC-BP'],
      ['Porções','Batata Frita M','Batata frita crocante 350g',1890,'JC-BM'],
      ['Porções','Batata Frita G','Batata frita crocante 500g',2490,'JC-BG'],
      ['Porções','Batata Cheddar e Bacon','Batata 500g com cheddar e bacon',3290,'JC-BCB'],
      ['Porções','Calabresa Acebolada','Calabresa fatiada com cebola',2990,'JC-CAL'],
      ['Porções','Nuggets 10 unidades','10 nuggets crocantes',1990,'JC-NUG'],
      ['Bebidas','Coca-Cola Lata 350ml','Gelada',700,'JC-COCA350'],
      ['Bebidas','Guaraná Lata 350ml','Gelado',650,'JC-GUA350'],
      ['Bebidas','Fanta Laranja Lata 350ml','Gelada',650,'JC-FAN350'],
      ['Bebidas','Coca-Cola 2L','Gelada',1400,'JC-COCA2'],
      ['Bebidas','Água Mineral 500ml','Sem gás',400,'JC-AGUA'],
      ['Bebidas','Suco de Laranja 500ml','Suco gelado',1000,'JC-SUCO'],
      ['Adicionais','Hambúrguer extra','Adicional de hambúrguer artesanal',800,'JC-ADD-H'],
      ['Adicionais','Bacon extra','Porção adicional de bacon',500,'JC-ADD-B'],
      ['Adicionais','Queijo extra','Fatia adicional de queijo',400,'JC-ADD-Q'],
      ['Adicionais','Cheddar extra','Porção adicional de cheddar',400,'JC-ADD-C'],
      ['Adicionais','Ovo extra','Ovo adicional',300,'JC-ADD-O'],
    ];
    foreach($products as [$cat,$name,$desc,$price,$sku]){
        $q=$pdo->prepare('SELECT id FROM products WHERE tenant_id=? AND sku=? LIMIT 1');$q->execute([$tenantId,$sku]);
        if(!$q->fetchColumn()){$i=$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,description,sku,price_cents,stock_qty,track_stock,active) VALUES (?,?,?,?,?,?,NULL,0,1)');$i->execute([$tenantId,$categoryIds[$cat],$name,$desc,$sku,$price]);}
    }

    for($n=1;$n<=20;$n++){
        $name=sprintf('Mesa %02d',$n);
        $q=$pdo->prepare('SELECT id FROM restaurant_tables WHERE tenant_id=? AND name=? LIMIT 1');$q->execute([$tenantId,$name]);
        if(!$q->fetchColumn()){$i=$pdo->prepare('INSERT INTO restaurant_tables (tenant_id,name,seats,status,qr_token) VALUES (?,?,4,\'available\',?)');$i->execute([$tenantId,$name,'jc-mesa-'.str_pad((string)$n,2,'0',STR_PAD_LEFT).'-'.bin2hex(random_bytes(6))]);}
    }
});

echo "JC Lanche configurada: Super ADM, administrador, Carlos, cardápio e 20 mesas.\n";

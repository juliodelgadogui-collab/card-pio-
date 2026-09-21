<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use RuntimeException;

final class Migrator
{
    private const SUPERSEDED_DELIVERY_MIGRATIONS = [
        '099_delivery_live_tracking.sql',
        '100_delivery_tracking_tokens.sql',
        '101_delivery_tracking_telemetry.sql',
    ];

    public static function run(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (Database::isSqlite($pdo)) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            self::repairSqliteLegacyDrift($pdo);
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        $dir = Database::migrationDirectory($pdo);
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (self::migrationApplied($pdo, $name)) continue;

            // As migrations 099-101 pertencem à primeira geração do GPS. A geração 053/054
            // substituiu essa estrutura. Em uma instalação nova, executar 099 depois de 053
            // recriaria delivery_live_locations e quebraria o instalador.
            if (in_array($name, self::SUPERSEDED_DELIVERY_MIGRATIONS, true)
                && self::migrationApplied($pdo, '053_delivery_location_tracking.sql')) {
                self::markMigrationApplied($pdo, $name);
                $applied[] = $name . ' (superseded)';
                continue;
            }

            if (Database::isSqlite($pdo) && $name === '052_production_desktop_print_claim.sql') {
                self::prepareSqliteProductionPrintUpgrade($pdo);
            }
            if (Database::isSqlite($pdo) && $name === '053_delivery_location_tracking.sql') {
                self::prepareSqliteDeliveryTrackingUpgrade($pdo);
            }

            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException('Não foi possível ler a migração ' . $name);

            $transactional = Database::isSqlite($pdo) && !$pdo->inTransaction();
            if ($transactional) $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                self::markMigrationApplied($pdo, $name);
                if ($transactional) $pdo->commit();
                $applied[] = $name;
            } catch (\Throwable $e) {
                if ($transactional && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        return $applied;
    }

    private static function repairSqliteLegacyDrift(PDO $pdo): void
    {
        if (self::migrationApplied($pdo, '035_operational_hardening.sql')) {
            self::ensureProductionUnitColumns($pdo);
            if (self::migrationApplied($pdo, '052_production_desktop_print_claim.sql')) {
                self::ensureProductionPrintClaimColumns($pdo);
                self::finalizeProductionPrintUpgrade($pdo);
            }
        }

        if (self::migrationApplied($pdo, '053_delivery_location_tracking.sql')) {
            self::prepareSqliteDeliveryTrackingUpgrade($pdo);
        }
    }

    private static function prepareSqliteProductionPrintUpgrade(PDO $pdo): void
    {
        self::ensureProductionUnitColumns($pdo);
        self::ensureProductionPrintClaimColumns($pdo);
    }

    private static function prepareSqliteDeliveryTrackingUpgrade(PDO $pdo): void
    {
        $hadOldHistory = self::tableExists($pdo, 'delivery_location_history');
        $liveWasPresent = self::tableExists($pdo, 'delivery_live_locations');
        $liveIsModern = $liveWasPresent && self::deliveryLiveSchemaIsModern($pdo);
        $legacyLiveTable = null;

        if ($liveWasPresent && !$liveIsModern) {
            $legacyLiveTable = self::nextLegacyTableName($pdo, 'delivery_live_locations_legacy_099');
            $pdo->exec('ALTER TABLE ' . self::quoteSqliteIdentifier('delivery_live_locations') . ' RENAME TO ' . self::quoteSqliteIdentifier($legacyLiveTable));
        } elseif (self::tableExists($pdo, 'delivery_live_locations_legacy_099')) {
            $legacyLiveTable = 'delivery_live_locations_legacy_099';
        }

        $eventsExisted = self::tableExists($pdo, 'delivery_location_events');
        self::createCanonicalDeliveryTrackingTables($pdo);

        // Migra a telemetria histórica antiga apenas quando a tabela canônica ainda não tinha dados.
        if ($hadOldHistory && (!$eventsExisted || self::tableCount($pdo, 'delivery_location_events') === 0)) {
            self::migrateLegacyDeliveryHistory($pdo);
        }

        // A posição "live" antiga só volta para a tabela canônica se o pedido ainda estiver em rota.
        // Pedidos concluídos/cancelados continuam preservados na tabela legacy e no histórico.
        if ($legacyLiveTable !== null && self::tableCount($pdo, 'delivery_live_locations') === 0) {
            self::migrateLegacyLiveLocations($pdo, $legacyLiveTable);
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_location_route ON delivery_location_events(tenant_id,order_id,recorded_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_location_user ON delivery_location_events(tenant_id,unit_id,delivery_user_id,recorded_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_live_unit ON delivery_live_locations(tenant_id,unit_id,received_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_live_order ON delivery_live_locations(tenant_id,order_id,received_at)');
    }

    private static function createCanonicalDeliveryTrackingTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS delivery_location_events (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          tenant_id INTEGER NOT NULL,
          delivery_user_id INTEGER NOT NULL,
          shift_id INTEGER NOT NULL,
          unit_id INTEGER NULL,
          order_id INTEGER NOT NULL,
          latitude REAL NOT NULL,
          longitude REAL NOT NULL,
          accuracy_m REAL NULL,
          speed_mps REAL NULL,
          heading_degrees REAL NULL,
          provider TEXT NULL,
          device_id_hash TEXT NOT NULL,
          recorded_at TEXT NOT NULL,
          received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
          FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
          FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
          FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS delivery_live_locations (
          tenant_id INTEGER NOT NULL,
          delivery_user_id INTEGER NOT NULL,
          shift_id INTEGER NOT NULL,
          unit_id INTEGER NULL,
          order_id INTEGER NOT NULL,
          latitude REAL NOT NULL,
          longitude REAL NOT NULL,
          accuracy_m REAL NULL,
          speed_mps REAL NULL,
          heading_degrees REAL NULL,
          recorded_at TEXT NOT NULL,
          received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          device_id_hash TEXT NOT NULL,
          PRIMARY KEY (tenant_id,delivery_user_id),
          FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
          FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
          FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
          FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )');
    }

    private static function migrateLegacyDeliveryHistory(PDO $pdo): void
    {
        $columns = self::tableColumns($pdo, 'delivery_location_history');
        $required = ['tenant_id','order_id','delivery_user_id','latitude','longitude'];
        foreach ($required as $column) if (!isset($columns[$column])) return;

        $rows = $pdo->query('SELECT * FROM delivery_location_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $insert = $pdo->prepare('INSERT INTO delivery_location_events (tenant_id,delivery_user_id,shift_id,unit_id,order_id,latitude,longitude,accuracy_m,speed_mps,heading_degrees,provider,device_id_hash,recorded_at,received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $row) {
            $tenantId=(int)$row['tenant_id'];$userId=(int)$row['delivery_user_id'];$orderId=(int)$row['order_id'];
            $recorded=(string)($row['captured_at']??$row['recorded_at']??$row['created_at']??gmdate('Y-m-d H:i:s'));
            $shift=self::findDeliveryShift($pdo,$tenantId,$userId,$recorded);if(!$shift)continue;
            $unit=self::resolveTrackingUnit($pdo,$tenantId,$orderId,(int)$shift['id'],isset($row['unit_id'])?(int)$row['unit_id']:0);
            $insert->execute([$tenantId,$userId,(int)$shift['id'],$unit,$orderId,(float)$row['latitude'],(float)$row['longitude'],self::nullableFloat($row['accuracy_m']??null),self::nullableFloat($row['speed_mps']??null),self::nullableFloat($row['heading_degrees']??$row['bearing_deg']??null),isset($row['provider'])?(string)$row['provider']:null,hash('sha256','legacy:'.$tenantId.':'.$userId),$recorded,(string)($row['received_at']??$row['updated_at']??$recorded)]);
        }
    }

    private static function migrateLegacyLiveLocations(PDO $pdo,string $legacyTable): void
    {
        $columns=self::tableColumns($pdo,$legacyTable);foreach(['tenant_id','order_id','delivery_user_id','latitude','longitude']as$column)if(!isset($columns[$column]))return;
        $rows=$pdo->query('SELECT l.* FROM '.self::quoteSqliteIdentifier($legacyTable).' l JOIN orders o ON o.id=l.order_id AND o.tenant_id=l.tenant_id WHERE o.status="out_for_delivery" ORDER BY l.order_id')->fetchAll(PDO::FETCH_ASSOC);
        $insert=$pdo->prepare('INSERT INTO delivery_live_locations (tenant_id,delivery_user_id,shift_id,unit_id,order_id,latitude,longitude,accuracy_m,speed_mps,heading_degrees,recorded_at,received_at,device_id_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(tenant_id,delivery_user_id) DO UPDATE SET shift_id=excluded.shift_id,unit_id=excluded.unit_id,order_id=excluded.order_id,latitude=excluded.latitude,longitude=excluded.longitude,accuracy_m=excluded.accuracy_m,speed_mps=excluded.speed_mps,heading_degrees=excluded.heading_degrees,recorded_at=excluded.recorded_at,received_at=excluded.received_at,device_id_hash=excluded.device_id_hash');
        foreach($rows as$row){$tenantId=(int)$row['tenant_id'];$userId=(int)$row['delivery_user_id'];$orderId=(int)$row['order_id'];$recorded=(string)($row['captured_at']??$row['recorded_at']??$row['updated_at']??gmdate('Y-m-d H:i:s'));$shift=self::findDeliveryShift($pdo,$tenantId,$userId,$recorded);if(!$shift)continue;$unit=self::resolveTrackingUnit($pdo,$tenantId,$orderId,(int)$shift['id'],isset($row['unit_id'])?(int)$row['unit_id']:0);$insert->execute([$tenantId,$userId,(int)$shift['id'],$unit,$orderId,(float)$row['latitude'],(float)$row['longitude'],self::nullableFloat($row['accuracy_m']??null),self::nullableFloat($row['speed_mps']??null),self::nullableFloat($row['heading_degrees']??$row['bearing_deg']??null),$recorded,(string)($row['received_at']??$row['updated_at']??$recorded),hash('sha256','legacy:'.$tenantId.':'.$userId)]);}
    }

    private static function findDeliveryShift(PDO $pdo,int $tenantId,int $userId,string $recorded): ?array
    {
        if (!self::tableExists($pdo,'work_shifts')) return null;
        $q=$pdo->prepare('SELECT id,unit_id FROM work_shifts WHERE tenant_id=? AND user_id=? AND mode="delivery" AND started_at<=? AND (ended_at IS NULL OR ended_at>=?) ORDER BY id DESC LIMIT 1');$q->execute([$tenantId,$userId,$recorded,$recorded]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row)return$row;
        $q=$pdo->prepare('SELECT id,unit_id FROM work_shifts WHERE tenant_id=? AND user_id=? AND mode="delivery" ORDER BY id DESC LIMIT 1');$q->execute([$tenantId,$userId]);$row=$q->fetch(PDO::FETCH_ASSOC);return$row?:null;
    }

    private static function resolveTrackingUnit(PDO $pdo,int $tenantId,int $orderId,int $shiftId,int $legacyUnit): ?int
    {
        if($legacyUnit>0)return$legacyUnit;
        if(self::columnExists($pdo,'orders','unit_id')){$q=$pdo->prepare('SELECT unit_id FROM orders WHERE id=? AND tenant_id=?');$q->execute([$orderId,$tenantId]);$v=(int)$q->fetchColumn();if($v>0)return$v;}
        if(self::columnExists($pdo,'work_shifts','unit_id')){$q=$pdo->prepare('SELECT unit_id FROM work_shifts WHERE id=? AND tenant_id=?');$q->execute([$shiftId,$tenantId]);$v=(int)$q->fetchColumn();if($v>0)return$v;}
        if(self::tableExists($pdo,'operating_units')){$q=$pdo->prepare('SELECT id FROM operating_units WHERE tenant_id=? ORDER BY is_primary DESC,id LIMIT 1');try{$q->execute([$tenantId]);}catch(\Throwable){$q=$pdo->prepare('SELECT id FROM operating_units WHERE tenant_id=? ORDER BY id LIMIT 1');$q->execute([$tenantId]);}$v=(int)$q->fetchColumn();if($v>0)return$v;}
        return null;
    }

    private static function deliveryLiveSchemaIsModern(PDO $pdo): bool
    {
        foreach(['tenant_id','delivery_user_id','shift_id','unit_id','order_id','latitude','longitude','heading_degrees','recorded_at','received_at','device_id_hash']as$column)if(!self::columnExists($pdo,'delivery_live_locations',$column))return false;
        $pk=[];foreach($pdo->query('PRAGMA table_info("delivery_live_locations")')->fetchAll(PDO::FETCH_ASSOC)as$row)if((int)($row['pk']??0)>0)$pk[(int)$row['pk']]=(string)$row['name'];ksort($pk);return array_values($pk)===['tenant_id','delivery_user_id'];
    }

    private static function ensureProductionUnitColumns(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'operating_units')) throw new RuntimeException('Banco SQLite legado inconsistente: a tabela operating_units não existe. A atualização 021 precisa ser reparada antes de continuar.');
        if (self::tableExists($pdo, 'production_stations')) {self::ensureColumn($pdo, 'production_stations', 'unit_id', 'INTEGER NULL');$pdo->exec('UPDATE production_stations SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_stations.tenant_id) WHERE unit_id IS NULL');$pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_station_unit ON production_stations(tenant_id,unit_id,active,sort_order,name)');}
        if (self::tableExists($pdo, 'production_prints')) {self::ensureColumn($pdo, 'production_prints', 'unit_id', 'INTEGER NULL');if (self::tableExists($pdo, 'orders') && self::columnExists($pdo, 'orders', 'unit_id')) {$pdo->exec('UPDATE production_prints SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_prints.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_prints.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_prints.tenant_id)) WHERE unit_id IS NULL');} else {$pdo->exec('UPDATE production_prints SET unit_id=COALESCE((SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_prints.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_prints.tenant_id)) WHERE unit_id IS NULL');}$pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_print_unit ON production_prints(tenant_id,unit_id,created_at)');}
        if (self::tableExists($pdo, 'production_print_queue')) {self::ensureColumn($pdo, 'production_print_queue', 'unit_id', 'INTEGER NULL');if (self::tableExists($pdo, 'orders') && self::columnExists($pdo, 'orders', 'unit_id')) {$pdo->exec('UPDATE production_print_queue SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_print_queue.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_print_queue.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_print_queue.tenant_id)) WHERE unit_id IS NULL');} else {$pdo->exec('UPDATE production_print_queue SET unit_id=COALESCE((SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_print_queue.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_print_queue.tenant_id)) WHERE unit_id IS NULL');}$pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_queue_unit ON production_print_queue(tenant_id,unit_id,status,created_at)');}
    }

    private static function ensureProductionPrintClaimColumns(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'production_stations') || !self::tableExists($pdo, 'production_print_queue')) throw new RuntimeException('Banco SQLite legado inconsistente: estrutura de impressão da produção ausente.');
        self::ensureColumn($pdo, 'production_stations', 'desktop_device_id', 'TEXT NULL');self::ensureColumn($pdo, 'production_print_queue', 'attempts', 'INTEGER NOT NULL DEFAULT 0');self::ensureColumn($pdo, 'production_print_queue', 'claimed_at', 'TEXT NULL');self::ensureColumn($pdo, 'production_print_queue', 'claimed_device_id', 'TEXT NULL');self::ensureColumn($pdo, 'production_print_queue', 'last_error', 'TEXT NULL');self::ensureColumn($pdo, 'production_print_queue', 'next_attempt_at', 'TEXT NULL');self::ensureColumn($pdo, 'production_print_queue', 'updated_at', 'TEXT NULL');
    }

    private static function finalizeProductionPrintUpgrade(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'production_print_queue')) return;$pdo->exec('UPDATE production_print_queue SET updated_at=COALESCE(updated_at,created_at,CURRENT_TIMESTAMP) WHERE updated_at IS NULL');$pdo->exec('CREATE TRIGGER IF NOT EXISTS trg_prod_print_queue_updated_at_insert AFTER INSERT ON production_print_queue FOR EACH ROW WHEN NEW.updated_at IS NULL BEGIN UPDATE production_print_queue SET updated_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END');$pdo->exec('CREATE INDEX IF NOT EXISTS idx_prod_print_claim ON production_print_queue(tenant_id,unit_id,status,next_attempt_at,created_at)');$pdo->exec('CREATE INDEX IF NOT EXISTS idx_prod_station_desktop ON production_stations(tenant_id,unit_id,desktop_device_id,active)');
    }

    private static function ensureColumn(PDO $pdo,string $table,string $column,string $definition): void{if(self::columnExists($pdo,$table,$column))return;$pdo->exec('ALTER TABLE '.self::quoteSqliteIdentifier($table).' ADD COLUMN '.self::quoteSqliteIdentifier($column).' '.$definition);}
    private static function tableExists(PDO $pdo,string $table): bool{$s=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");$s->execute([$table]);return(bool)$s->fetchColumn();}
    private static function tableCount(PDO $pdo,string $table): int{return self::tableExists($pdo,$table)?(int)$pdo->query('SELECT COUNT(*) FROM '.self::quoteSqliteIdentifier($table))->fetchColumn():0;}
    private static function tableColumns(PDO $pdo,string $table): array{$out=[];if(!self::tableExists($pdo,$table))return$out;foreach($pdo->query('PRAGMA table_info('.self::quoteSqliteIdentifier($table).')')->fetchAll(PDO::FETCH_ASSOC)as$row)$out[(string)$row['name']]=true;return$out;}
    private static function columnExists(PDO $pdo,string $table,string $column): bool{return isset(self::tableColumns($pdo,$table)[$column]);}
    private static function migrationApplied(PDO $pdo,string $name): bool{$s=$pdo->prepare('SELECT 1 FROM migrations WHERE migration=? LIMIT 1');$s->execute([$name]);return(bool)$s->fetchColumn();}
    private static function markMigrationApplied(PDO $pdo,string $name): void{$s=$pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');$s->execute([$name]);}
    private static function quoteSqliteIdentifier(string$name): string{return '"'.str_replace('"','""',$name).'"';}
    private static function nextLegacyTableName(PDO$pdo,string$base): string{if(!self::tableExists($pdo,$base))return$base;$i=2;while(self::tableExists($pdo,$base.'_'.$i))$i++;return$base.'_'.$i;}
    private static function nullableFloat(mixed$value): ?float{return$value===null||$value===''?null:(float)$value;}
}

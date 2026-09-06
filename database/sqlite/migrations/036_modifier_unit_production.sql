PRAGMA foreign_keys = ON;

CREATE TABLE modifier_option_unit_production_profiles (
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  modifier_option_id INTEGER NOT NULL,
  station_id INTEGER NULL,
  production_enabled INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,unit_id,modifier_option_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (modifier_option_id) REFERENCES modifier_options(id) ON DELETE CASCADE,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL
);
CREATE INDEX idx_modifier_unit_production ON modifier_option_unit_production_profiles(tenant_id,unit_id,production_enabled,station_id);

INSERT OR IGNORE INTO modifier_option_unit_production_profiles (tenant_id,unit_id,modifier_option_id,station_id,production_enabled)
SELECT mo.tenant_id,
       (SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=mo.tenant_id),
       mo.id,mo.station_id,mo.production_enabled
FROM modifier_options mo
WHERE (SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=mo.tenant_id) IS NOT NULL;

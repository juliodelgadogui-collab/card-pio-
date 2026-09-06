CREATE TABLE modifier_option_unit_production_profiles (
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  modifier_option_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  production_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,unit_id,modifier_option_id),
  CONSTRAINT fk_moupp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_moupp_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  CONSTRAINT fk_moupp_option FOREIGN KEY (modifier_option_id) REFERENCES modifier_options(id) ON DELETE CASCADE,
  CONSTRAINT fk_moupp_station FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL,
  INDEX idx_modifier_unit_production (tenant_id,unit_id,production_enabled,station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO modifier_option_unit_production_profiles (tenant_id,unit_id,modifier_option_id,station_id,production_enabled)
SELECT mo.tenant_id,(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=mo.tenant_id),mo.id,mo.station_id,mo.production_enabled
FROM modifier_options mo
WHERE (SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=mo.tenant_id) IS NOT NULL;

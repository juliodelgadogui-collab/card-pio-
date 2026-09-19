UPDATE production_print_queue SET updated_at=COALESCE(updated_at,created_at,CURRENT_TIMESTAMP) WHERE updated_at IS NULL;
CREATE TRIGGER IF NOT EXISTS trg_prod_print_queue_updated_at_insert
AFTER INSERT ON production_print_queue
FOR EACH ROW
WHEN NEW.updated_at IS NULL
BEGIN
  UPDATE production_print_queue SET updated_at=CURRENT_TIMESTAMP WHERE id=NEW.id;
END;
CREATE INDEX IF NOT EXISTS idx_prod_print_claim ON production_print_queue(tenant_id,unit_id,status,next_attempt_at,created_at);
CREATE INDEX IF NOT EXISTS idx_prod_station_desktop ON production_stations(tenant_id,unit_id,desktop_device_id,active);

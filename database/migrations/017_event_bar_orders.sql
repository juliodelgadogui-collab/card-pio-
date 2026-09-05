ALTER TABLE orders
  MODIFY channel ENUM('counter','table','delivery','pickup','event','bar') NOT NULL DEFAULT 'counter';

ALTER TABLE orders
  ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER promoter_id,
  ADD INDEX idx_orders_event_bar (tenant_id,event_id,channel,status),
  ADD CONSTRAINT fk_orders_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL;

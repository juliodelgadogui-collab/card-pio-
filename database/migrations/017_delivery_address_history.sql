ALTER TABLE delivery_events
  MODIFY COLUMN event_type ENUM('created','assigned','unassigned','out_for_delivery','delivered','cancelled','failed','address_updated') NOT NULL;

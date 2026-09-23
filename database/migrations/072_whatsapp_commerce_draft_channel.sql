ALTER TABLE orders
  MODIFY channel ENUM('pending','counter','table','delivery','pickup','event','bar','event_bar') NOT NULL DEFAULT 'counter';

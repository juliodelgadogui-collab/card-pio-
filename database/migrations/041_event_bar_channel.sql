ALTER TABLE orders
  MODIFY channel ENUM('counter','table','delivery','pickup','event','bar','event_bar') NOT NULL DEFAULT 'counter';

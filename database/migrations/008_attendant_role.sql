ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','admin','manager','cashier','attendant','waiter','kitchen','delivery','promoter') NOT NULL DEFAULT 'admin';

ALTER TABLE users MODIFY role ENUM('super_admin','admin','manager','cashier','counter','waiter','kitchen','delivery','promoter') NOT NULL DEFAULT 'admin';

ALTER TABLE work_shifts ADD COLUMN delivery_commission_bps INTEGER NOT NULL DEFAULT 0;
ALTER TABLE work_shifts ADD COLUMN delivery_commission_fixed_cents INTEGER NOT NULL DEFAULT 0;

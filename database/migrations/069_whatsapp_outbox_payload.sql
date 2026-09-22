ALTER TABLE whatsapp_outbox ADD COLUMN payload_json JSON NULL AFTER message_text;

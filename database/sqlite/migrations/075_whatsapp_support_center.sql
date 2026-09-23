ALTER TABLE whatsapp_conversations ADD COLUMN resume_state TEXT NULL;
ALTER TABLE whatsapp_conversations ADD COLUMN resume_context_json TEXT NULL;
ALTER TABLE whatsapp_conversations ADD COLUMN assigned_at TEXT NULL;
ALTER TABLE whatsapp_conversations ADD COLUMN last_read_at TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_wa_conversation_activity ON whatsapp_conversations(tenant_id,last_activity_at);

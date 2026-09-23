ALTER TABLE whatsapp_conversations
  ADD COLUMN resume_state VARCHAR(40) NULL AFTER state,
  ADD COLUMN resume_context_json JSON NULL AFTER context_json,
  ADD COLUMN assigned_at DATETIME NULL AFTER assigned_user_id,
  ADD COLUMN last_read_at DATETIME NULL AFTER last_outbound_at;

CREATE INDEX idx_wa_conversation_activity ON whatsapp_conversations(tenant_id,last_activity_at);

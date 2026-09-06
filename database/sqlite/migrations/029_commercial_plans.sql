CREATE TABLE IF NOT EXISTS saas_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  description TEXT NULL,
  monthly_cents INTEGER NOT NULL DEFAULT 0,
  yearly_cents INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  features_json TEXT NULL,
  limits_json TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL UNIQUE,
  plan_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  billing_cycle TEXT NOT NULL DEFAULT 'monthly',
  custom_price_cents INTEGER NULL,
  starts_at TEXT DEFAULT CURRENT_TIMESTAMP,
  ends_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (plan_id) REFERENCES saas_plans(id)
);

INSERT OR IGNORE INTO saas_plans (code,name,description,monthly_cents,yearly_cents,active,sort_order,features_json,limits_json) VALUES
('premium','Premium','Para operações que estão começando com cardápio digital e atendimento.',14900,149000,1,10,'["Cardápio digital","Pedidos","Mesas e comandas","Clientes e pontos"]','{"users":8,"units":1,"products":250}'),
('pro','Pro','Operação completa com delivery, gestão e automações.',29900,299000,1,20,'["Tudo do Premium","Delivery","Estoque","Relatórios","Pagamentos e NFC"]','{"users":25,"units":3,"products":1000}'),
('enterprise','Enterprise','Para redes, eventos e operações com múltiplas unidades.',59900,599000,1,30,'["Tudo do Pro","Eventos e ingressos","Múltiplas unidades","Auditoria avançada","Personalização premium"]','{"users":100,"units":20,"products":5000}');

-- Empresas já existentes entram automaticamente no plano correspondente,
-- sem perder dados e sem exigir recadastro comercial.
INSERT OR IGNORE INTO tenant_subscriptions (tenant_id,plan_id,status,billing_cycle)
SELECT t.id,p.id,'active','monthly'
FROM tenants t
JOIN saas_plans p ON p.code=t.plan;

CREATE TABLE IF NOT EXISTS saas_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  monthly_cents INT UNSIGNED NOT NULL DEFAULT 0,
  yearly_cents INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  features_json TEXT NULL,
  limits_json TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('trial','active','past_due','cancelled') NOT NULL DEFAULT 'active',
  billing_cycle ENUM('monthly','yearly','custom') NOT NULL DEFAULT 'monthly',
  custom_price_cents INT UNSIGNED NULL,
  starts_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  ends_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_subscription_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_id) REFERENCES saas_plans(id),
  INDEX idx_subscription_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO saas_plans (code,name,description,monthly_cents,yearly_cents,active,sort_order,features_json,limits_json) VALUES
('premium','Premium','Para operações que estão começando com cardápio digital e atendimento.',14900,149000,1,10,'["Cardápio digital","Pedidos","Mesas e comandas","Clientes e pontos"]','{"users":8,"units":1,"products":250}'),
('pro','Pro','Operação completa com delivery, gestão e automações.',29900,299000,1,20,'["Tudo do Premium","Delivery","Estoque","Relatórios","Pagamentos e NFC"]','{"users":25,"units":3,"products":1000}'),
('enterprise','Enterprise','Para redes, eventos e operações com múltiplas unidades.',59900,599000,1,30,'["Tudo do Pro","Eventos e ingressos","Múltiplas unidades","Auditoria avançada","Personalização premium"]','{"users":100,"units":20,"products":5000}');

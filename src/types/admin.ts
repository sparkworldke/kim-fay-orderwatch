export interface AiProviderStatus {
  id: number | null;
  provider: "openai" | "anthropic";
  source: "environment" | "database";
  masked_preview: string | null;
  last_used_at: string | null;
  health_status: "healthy" | "rate_limited" | "error" | "unchecked";
}

export interface AcumaticaConfig {
  id: number;
  base_url: string;
  endpoint: string;
  version: string;
  tenant: string;
  username: string;
  token_url: string;
  client_id_preview: string | null;
  client_secret_preview: string | null;
  password_preview: string | null;
  endpoint_version: string | null;
  last_validated_at: string | null;
  health_status: "connected" | "error" | "unchecked";
}

export interface AcumaticaSyncLog {
  id: number;
  sync_type: string;
  started_at: string;
  ended_at: string | null;
  record_count: number;
  status: string;
  error_message: string | null;
}

export interface AcumaticaResponse {
  config: AcumaticaConfig;
  sync_logs: AcumaticaSyncLog[];
}

export interface Permission {
  id: number;
  name: string;
  description: string | null;
}

export interface Role {
  id: number;
  name: string;
  description: string | null;
  is_system: boolean;
  users_count: number;
  permissions: Permission[];
}

export interface NotificationRule {
  id: number;
  rule_key: string;
  label: string;
  channels: string[];
  is_enabled: boolean;
  last_evaluated_at: string | null;
  last_triggered_at: string | null;
}

export interface AuditLogEntry {
  id: string;
  timestamp: string;
  actor_user_id: number | null;
  actor_ip: string | null;
  action_type: string;
  resource_type: string;
  resource_id: string | null;
  changes: Record<string, unknown> | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface AdminHealth {
  outlook_oauth: ServiceHealth;
  openai: ServiceHealth;
  anthropic: ServiceHealth;
  acumatica: ServiceHealth;
}

export interface ServiceHealth {
  status: string;
  last_checked_at: string | null;
}

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
  success_count: number;
  failed_count: number;
  status: "running" | "completed" | "failed";
  error_message: string | null;
  filters: Record<string, unknown> | null;
  trigger_type: "manual" | "background" | "scheduled";
  triggered_by_user_id: number | null;
}

export interface AcumaticaResponse {
  config: AcumaticaConfig;
  sync_logs: AcumaticaSyncLog[];
}

export interface AcumaticaCustomerSummary {
  id: number;
  acumatica_id: string;
  name: string;
  customer_class: string | null;
  email: string | null;
  status: string | null;
}

export interface AcumaticaCustomer extends AcumaticaCustomerSummary {
  phone: string | null;
  payment_terms: string | null;
  tax_zone: string | null;
  billing_address: Record<string, string> | null;
  shipping_address: Record<string, string> | null;
  synced_at: string | null;
}

export interface AcumaticaSalesOrderLine {
  id: number;
  line_nbr: number;
  inventory_id: string | null;
  description: string | null;
  order_qty: string;
  unit_price: string;
  ext_cost: string;
  discount_amount: string;
  discount_code: string | null;
}

export interface AcumaticaSalesOrder {
  id: number;
  acumatica_order_nbr: string;
  order_type: string;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  customer_order: string | null;
  status: string | null;
  match_status: "pending" | "matched" | "unmatched" | "duplicate" | "escalated" | "missing";
  flag_source: "acumatica" | "email" | null;
  rejection_reason: string | null;
  on_hold_reason: string | null;
  email_subject: string | null;
  email_received_at: string | null;
  order_date: string | null;
  last_modified_at: string | null;
  ship_date: string | null;
  requested_on: string | null;
  approved_at: string | null;
  shipped_at: string | null;
  completed_at: string | null;
  order_total: string;
  currency_id: string | null;
  lines_count?: number;
  lines?: AcumaticaSalesOrderLine[];
  synced_at: string | null;
}

export interface ReconciliationResult {
  id: number;
  sync_run_id: number;
  resource_type: string;
  resource_id: string;
  field_name: string;
  local_value: string | null;
  acumatica_value: string | null;
  severity: "info" | "warning" | "error";
  remediation_status: "open" | "resolved" | "ignored";
  created_at: string;
}

export interface DeadLetter {
  id: number;
  sync_run_id: number;
  resource_type: string;
  resource_id: string | null;
  attempt_count: number;
  last_error: string;
  remediation_notes: string | null;
  created_at: string;
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

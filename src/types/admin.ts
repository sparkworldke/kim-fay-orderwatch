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
  heartbeat_at: string | null;
  stop_requested_at: string | null;
  record_count: number;
  success_count: number;
  failed_count: number;
  status: "running" | "completed" | "failed" | "stopped";
  error_message: string | null;
  filters: Record<string, unknown> | null;
  trigger_type: "manual" | "background" | "scheduled";
  triggered_by_user_id: number | null;
}

export interface AcumaticaResponse {
  config: AcumaticaConfig;
  sync_logs: AcumaticaSyncLog[];
}

export type AcumaticaLookupType =
  | "inventory_id"
  | "customer_id"
  | "rep_code"
  | "consultant_id"
  | "salesperson_id"
  | "zone_id"
  | "route_code"
  | "route_name";

export interface AcumaticaLookupResult {
  lookup_type: AcumaticaLookupType;
  lookup_label: string;
  lookup_id: string;
  entity: string;
  field: string;
  top_level_keys: string[];
  raw: Record<string, unknown>;
}

export interface AcumaticaCustomerSummary {
  id: number;
  acumatica_id: string;
  name: string;
  customer_class: string | null;
  email: string | null;
  status: string | null;
  is_main_account: boolean;
  parent_acumatica_id: string | null;
}

export interface AcumaticaShippingZone {
  acumatica_id: string;
  description: string | null;
  name: string | null;
  region: string | null;
  synced_at?: string | null;
  customer_count?: number;
}

export interface DeliverySlaConfigRule {
  region_key: string;
  label: string;
  sla_hours: number;
  warning_hours: number | null;
  breach_hours: number;
  is_metro: boolean;
  is_active?: boolean;
  alert_min_orders: number;
  alert_delayed_pct: number;
  clock_start: "order_date" | "approved_at" | "ship_date";
}

export interface AcumaticaCustomer extends AcumaticaCustomerSummary {
  phone: string | null;
  payment_terms: string | null;
  tax_zone: string | null;
  shipping_zone_id: string | null;
  shipping_zone?: AcumaticaShippingZone | null;
  billing_address: Record<string, string> | null;
  shipping_address: Record<string, string> | null;
  synced_at: string | null;
  branches?: AcumaticaCustomer[];
  branch_count?: number;
}

export interface AcumaticaSalesOrderLine {
  id: number;
  line_nbr: number;
  inventory_id: string | null;
  description: string | null;
  brand?: string | null;
  posting_class?: string | null;
  sub_trading_group?: string | null;
  supplier?: string | null;
  order_qty: string;
  shipped_qty?: string | null;
  qty_on_shipments?: string | null;
  open_qty?: string | null;
  cancelled_qty?: string | null;
  qty_at_approval?: string | null;
  backorder_qty?: string | null;
  fill_rate_pct?: string | number | null;
  unfilled_reason_code?: string | null;
  line_type?: string | null;
  completed?: boolean | null;
  fulfillment_status?: string | null;
  warehouse_id?: string | null;
  uom?: string | null;
  unit_price: string;
  ext_cost: string;
  discount_amount: string;
  discount_code: string | null;
}

export interface OrderMatchConflict {
  field: string;
  email_value: string;
  acumatica_value: string;
  reason: string;
  email_value_inc_vat?: string;
  vat_rate?: string;
  amount_delta?: string;
}

export interface AcumaticaSalesOrder {
  id: number;
  acumatica_order_nbr: string;
  order_type: string;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  customer?: AcumaticaCustomerSummary | null;
  customer_order: string | null;
  status: string | null;
  match_status: "pending" | "matched" | "matched_discrepancies" | "needs_review" | "unmatched" | "duplicate" | "escalated" | "missing";
  flag_source: "acumatica" | "email" | null;
  rejection_reason: string | null;
  rejection_reason_code: string | null;
  on_hold_reason: string | null;
  workflow_parent_reason: string | null;
  workflow_sub_reason_code: string | null;
  workflow_reason_label: string | null;
  email_subject: string | null;
  email_received_at: string | null;
  matched_po_number?: string | null;
  extracted_po_number?: string | null;
  sanitized_po_number?: string | null;
  match_conflicts?: OrderMatchConflict[] | null;
  order_date: string | null;
  last_modified_at: string | null;
  ship_date: string | null;
  requested_on: string | null;
  approved_at: string | null;
  approved_by_id: string | null;
  shipped_at: string | null;
  completed_at: string | null;
  order_total: string;
  currency_id: string | null;
  sales_consultant_rep_code: string | null;
  sales_consultant_name: string | null;
  description: string | null;
  lines_count?: number;
  lines_avg_fill_rate_pct?: string | number | null;
  lines_sum_backorder_qty?: string | number | null;
  revenue_lost?: string | number | null;
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

export interface Department {
  id: number;
  slug: string;
  name: string;
  is_customer_facing: boolean;
  brands?: string[];
}

export type OrgLevel = "executive" | "c_suite" | "hod" | "sales" | "brandsops" | "operations" | "gap";
export type DataScopeMode = "org_wide" | "scoped" | "deny_all";
export type ProductTypeScope = "manufactured" | "trading" | "both";
export type SectorScope = "GT" | "MT" | "KP" | "ALL";

export interface TeamMemberReportsTo {
  id: number;
  name: string;
  email: string;
}

export interface TeamMember {
  id: number;
  name: string;
  email: string;
  role: string;
  phone_number: string | null;
  rep_code: string | null;
  employee_number: string | null;
  department_id: number | null;
  department_role: string | null;
  org_level: OrgLevel | null;
  reports_to_user_id: number | null;
  reports_to?: TeamMemberReportsTo | null;
  product_type_scope: ProductTypeScope | null;
  data_scope_mode: DataScopeMode | null;
  is_shared_mailbox: boolean;
  is_consultant: boolean;
  department?: Department | null;
  departments?: Department[];
  department_ids?: number[];
  sector_scopes?: SectorScope[];
  brand_assignments?: string[];
  roles?: Array<{ id: number; name: string }>;
  role_ids?: number[];
  customer_assignment_count?: number;
  is_active: boolean;
  is_account_manager: boolean;
  is_super_admin: boolean;
  created_at: string;
}

export interface CreateTeamMemberInput {
  name: string;
  email: string;
  role: string;
  role_ids?: number[];
  phone_number?: string;
  rep_code?: string;
  employee_number?: string;
  department_id?: number | null;
  department_ids?: number[];
  department_role?: string;
  org_level?: OrgLevel;
  reports_to_user_id?: number | null;
  product_type_scope?: ProductTypeScope;
  data_scope_mode?: DataScopeMode;
  sector_scopes?: SectorScope[];
  is_consultant?: boolean;
  is_shared_mailbox?: boolean;
  is_account_manager?: boolean;
  is_active?: boolean;
}

export interface UpdateTeamMemberInput {
  name?: string;
  email?: string;
  role?: string;
  role_ids?: number[];
  phone_number?: string | null;
  rep_code?: string | null;
  employee_number?: string | null;
  department_id?: number | null;
  department_ids?: number[];
  department_role?: string;
  org_level?: OrgLevel;
  reports_to_user_id?: number | null;
  product_type_scope?: ProductTypeScope;
  data_scope_mode?: DataScopeMode;
  sector_scopes?: SectorScope[];
  is_consultant?: boolean;
  is_shared_mailbox?: boolean;
  is_account_manager?: boolean;
  is_active?: boolean;
  change_reason?: string | null;
}

export interface StaffImportGap {
  id: number;
  email: string | null;
  employee_number: string | null;
  display_name: string | null;
  gap_reason: string;
  match_score: number | null;
  resolution_status: string;
  created_at: string;
}

export interface StaffImportResult {
  message: string;
  stats: {
    created: number;
    updated: number;
    skipped: number;
    gaps: number;
    errors: string[];
  };
}

export interface CustomerBackfillResult {
  message: string;
  result: { added: number; total: number; customer_ids: string[] };
}

export interface UserSessionEntry {
  id: number;
  login_at: string;
  logout_at: string | null;
  logout_reason: string | null;
  duration_seconds: number | null;
  ip_address: string | null;
  login_mode: string;
}

export interface RepCodeHistoryEntry {
  id: number;
  rep_code: string | null;
  changed_by_name: string | null;
  changed_by: number | null;
  change_reason: string | null;
  changed_at: string;
  created_at: string;
}

export interface NotificationRule {
  id: number;
  rule_key: string;
  label: string;
  channels: string[];
  is_enabled: boolean;
  last_evaluated_at: string | null;
  last_triggered_at: string | null;
  recipient_emails: string[];
  recipient_roles: string[];
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
  mail_delivery: ServiceHealth & { mailer: "smtp" | "resend" | string };
}

export interface ServiceHealth {
  status: string;
  last_checked_at: string | null;
}

export interface MailSettings {
  mailer: "smtp" | "resend";
  smtp_host: string | null;
  smtp_port: number;
  smtp_scheme: "tls" | "ssl" | string;
  smtp_username: string | null;
  smtp_password_configured: boolean;
  smtp_password_preview: string | null;
  smtp_configured: boolean;
  resend_configured: boolean;
  from_address: string;
  from_name: string | null;
  updated_at: string | null;
}

export interface MailSettingsInput {
  mailer?: "smtp" | "resend";
  smtp_host?: string;
  smtp_port?: number;
  smtp_scheme?: "tls" | "ssl";
  smtp_username?: string;
  smtp_password?: string;
  from_address?: string;
  from_name?: string;
  resend_api_key?: string;
}

export interface CronRunLog {
  id: number;
  cron_job_id: number;
  status: "running" | "success" | "partial" | "failed" | "skipped";
  trigger_source: "scheduler" | "manual" | "queue";
  started_at: string;
  ended_at: string | null;
  duration_ms: number | null;
  emails_checked: number;
  emails_processed: number;
  sales_orders_checked: number;
  sales_orders_processed: number;
  matches_created: number;
  matched_with_discrepancies_count: number;
  needs_review_count: number;
  unmatched_count: number;
  skipped_count: number;
  error_count: number;
  step_status: Record<string, { status: string; duration_ms: number; metrics: Record<string, number>; errors?: string[] }> | null;
  error_summary: string | null;
  metadata: Record<string, unknown> | null;
}

export interface AiPromptLog {
  id: number;
  user_id: number | null;
  user_role: string | null;
  user: { id: number; name: string; email: string } | null;
  prompt: string;
  intent: string | null;
  domains: string[] | null;
  formulas_used: Record<string, string> | null;
  ai_message: string | null;
  cards_returned: unknown[] | null;
  sources: string[] | null;
  provider: string | null;
  response_time_ms: number | null;
  status: "success" | "failed";
  error_message: string | null;
  created_at: string;
}

export interface AiPromptLogStats {
  total: number;
  success: number;
  failed: number;
  avg_response_ms: number;
  by_intent: Record<string, number>;
  by_provider: Record<string, number>;
}

export interface DailyReportRun {
  id: number;
  report_date: string | null;
  started_at: string | null;
  completed_at: string | null;
  sent_at: string | null;
  status: string;
  ai_status: string | null;
  delivery_status: string | null;
  recipient_count: number;
  duration_ms: number | null;
  error_summary: string | null;
  has_payload: boolean;
}

export interface DailyReportConfig {
  id: number;
  name: string;
  is_enabled: boolean;
  send_time: string;
  timezone: string;
  send_to?: string[];
  cc?: string[];
  recipients: string[];
  reply_to: string[];
  subject_template: string;
  include_ai_insights: boolean;
  include_comparison: boolean;
  include_mtd: boolean;
  include_customer_highlights: boolean;
  last_sent_at: string | null;
  last_sent_status: string | null;
  last_delivery_status: string | null;
  last_run: DailyReportRun | null;
  command_reference: string;
  scheduler_reference: string;
}

export interface CronJob {
  id: number;
  job_key: string;
  name: string;
  description: string | null;
  is_enabled: boolean;
  frequency_label: string;
  cron_expression: string;
  trigger_type: string;
  command: string;
  last_run_at: string | null;
  last_success_at: string | null;
  last_failure_at: string | null;
  last_run_status: string | null;
  last_duration_ms: number | null;
  next_run_at: string | null;
  settings: {
    // auto-match specific
    email_sync_enabled?: boolean;
    acumatica_sync_enabled?: boolean;
    matching_enabled?: boolean;
    sales_order_lookback_days?: number;
    deterministic_auto_link?: boolean;
    ai_auto_link?: boolean;
    // other job settings (inventory filters, lookback windows, etc.)
    [key: string]: unknown;
  };
  notes: string | null;
  command_reference: string;
  scheduler_reference: string;
  runs: CronRunLog[];
}

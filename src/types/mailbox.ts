export interface MailboxAccount {
  id: number;
  email: string;
  display_name: string | null;
  status: "connected" | "error" | "disconnected";
  last_synced_at: string | null;
  sync_from_date: string | null;
  created_at: string;
}

export interface EmailMessage {
  id: number;
  mailbox_account_id: number;
  message_id: string;
  subject: string | null;
  from_email: string | null;
  from_name: string | null;
  to_recipients: { name: string; address: string }[] | null;
  body_preview: string | null;
  is_read: boolean;
  received_at: string | null;
  folder: string;
  ingestion_classification: "po_processing" | "needs_review" | "stored_non_order" | null;
  ingestion_reason_codes: string[] | null;
  ingestion_decision_sources: string[] | null;
  extracted_po_number?: string | null;
  canonical_po?: string | null;
  mailbox_folder?: {
    display_name: string;
    customer?: { id: number; acumatica_id: string; name: string } | null;
    rules: FolderRuleMapping[];
  } | null;
  created_at?: string;
}

export interface InboxEmailStats {
  total: number;
  with_po: number;
  po_processing: number;
  needs_review: number;
  stored_non_order: number;
  unread: number;
}

export interface InboxCustomerGroup {
  customer_id: number | null;
  customer_name: string;
  acumatica_id: string | null;
  email_count: number;
  with_po_count: number;
  po_processing_count: number;
  needs_review_count: number;
  stored_non_order_count: number;
  unread_count: number;
  emails: EmailMessage[];
}

export interface InboxEmailGroupsResponse {
  stats: InboxEmailStats;
  groups: InboxCustomerGroup[];
  truncated: boolean;
  date_from: string | null;
  date_to: string | null;
}

export interface FolderRuleMapping {
  id: number;
  existing_rule_name: string;
  customer_id: number | null;
  is_enabled: boolean;
  is_trusted: boolean;
  notes: string | null;
}

export interface MailboxFolder {
  id: number;
  mailbox_account_id: number;
  external_folder_id: string;
  display_name: string;
  parent_display_name: string | null;
  total_item_count: number;
  unread_item_count: number;
  is_sync_enabled: boolean;
  is_order_folder: boolean;
  customer_id: number | null;
  trust_level: "untrusted" | "standard" | "trusted_order";
  sync_priority: number;
  is_active: boolean;
  last_discovered_at: string | null;
  last_synced_at: string | null;
  last_sync_error: string | null;
  emails_synced_all_time: number;
  last_manual_sync_at: string | null;
  last_manual_sync_count: number;
  suggested_order_folder: boolean;
  rules: FolderRuleMapping[];
  customer: { id: number; acumatica_id: string; name: string } | null;
}

export type EmailFilterType =
  | "sender_email"
  | "sender_domain"
  | "subject_keyword"
  | "received_date"
  | "date_range";

export interface EmailFilterCondition {
  type: EmailFilterType;
  value: string;
}

export interface EmailFilter {
  id: number;
  name: string;
  // New server: conditions array. Old server: type + value at top level.
  conditions?: EmailFilterCondition[];
  type?: EmailFilterType;
  value?: string;
  is_active: boolean;
  match_count: number;
  created_at: string;
  updated_at: string;
}

export interface CreateEmailFilterPayload {
  name: string;
  conditions: EmailFilterCondition[];
  type?: EmailFilterType;
  value?: string;
  is_active?: boolean;
}

export interface UpdateEmailFilterPayload {
  name?: string;
  conditions?: EmailFilterCondition[];
  type?: EmailFilterType;
  value?: string;
  is_active?: boolean;
}

export interface PaginatedEmails {
  data: EmailMessage[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface SyncLog {
  id: number;
  mailbox_account_id: number;
  started_at: string;
  ended_at: string | null;
  emails_fetched: number;
  emails_created: number;
  emails_updated: number;
  emails_skipped: number;
  emails_deleted: number;
  emails_failed: number;
  status: "running" | "completed" | "failed" | "stopped";
  error_message: string | null;
  sync_scope: {
    type: "all" | "rule";
    filter_id: number | null;
    filter_name: string | null;
  };
  reason_counts: Array<{
    code: string;
    label: string;
    count: number;
  }>;
  decision_counts: Array<{
    classification: string;
    reason_code: string;
    label: string;
    folder_name: string | null;
    count: number;
  }>;
}

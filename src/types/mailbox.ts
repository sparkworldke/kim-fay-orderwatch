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
  created_at: string;
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
  status: "running" | "completed" | "failed";
  error_message: string | null;
}

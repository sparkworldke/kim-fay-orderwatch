import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { API_BASE_URL, apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export type FolStatus =
  | "draft"
  | "submitted"
  | "in_approval"
  | "rejected"
  | "ready_for_invoicing"
  | "so_linked"
  | "invoiced"
  | "fulfilled";

export interface FolLine {
  id?: number;
  line_no?: number;
  inventory_id: string;
  product_description?: string | null;
  qty_requested: number;
  qty_previously_issued?: number;
  date_last_issue?: string | null;
}

export interface FolAttachment {
  id: number;
  original_name: string;
  mime: string | null;
  size: number;
  created_at: string;
}

export interface FolEvent {
  id: number;
  event_type: string;
  comment: string | null;
  payload_json: Record<string, unknown> | null;
  created_at: string;
}

export interface FolRequest {
  id: number;
  public_ref: string;
  customer_acumatica_id: string;
  customer_name: string;
  sales_consultant_email: string | null;
  request_origin: string;
  requestor_first_name: string;
  requestor_last_name: string;
  requestor_phone: string;
  requestor_email: string;
  issue_types: string[];
  reason_text: string;
  installation_required: boolean;
  installation_location: string | null;
  assigned_technician_user_id: number | null;
  technician_assigned_by: number | null;
  technician_assigned_at: string | null;
  assigned_technician?: FolTechnician | null;
  customer_has_submitted_po: boolean;
  consumables_last_purchase_date: string | null;
  consumables_sales_6m_kes: string | number;
  consumables_volume_6m: string | number;
  consumables_metrics_source: "system_so" | "manual_override";
  consumables_override_reason: string | null;
  debt_explanation: string;
  status: FolStatus;
  current_stage_key: string | null;
  linked_so_order_nbrs: string[] | null;
  linked_so_status_summary: string | null;
  submitted_at: string | null;
  decided_at: string | null;
  created_at: string;
  lines: FolLine[];
  attachments: FolAttachment[];
  events?: FolEvent[];
  so_links?: Array<{ id: number; acumatica_order_nbr: string; po_number?: string | null; link_type: string }>;
}

export interface FolTechnician {
  id: number;
  name: string;
  email: string | null;
  role: string | null;
}

export interface FolCustomer {
  acumatica_id: string;
  name: string;
  customer_class: string | null;
  status: string | null;
  email: string | null;
  phone: string | null;
  payment_terms: string | null;
}

export interface FolInventoryItem {
  inventory_id: string;
  description: string | null;
  fol_category: string | null;
  default_uom: string | null;
  qty_on_hand: string | number | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface FolInput {
  customer_acumatica_id: string;
  request_origin: string;
  request_origin_other?: string | null;
  requestor_first_name: string;
  requestor_last_name: string;
  requestor_phone: string;
  requestor_email: string;
  issue_types: string[];
  reason_text: string;
  installation_required: boolean;
  installation_location?: string | null;
  customer_has_submitted_po: boolean;
  consumables_last_purchase_date?: string | null;
  consumables_sales_6m_kes?: number;
  consumables_volume_6m?: number;
  consumables_metrics_source?: "system_so" | "manual_override";
  consumables_override_reason?: string | null;
  debt_explanation: string;
  lines: FolLine[];
}

export function useFolList(params: { view?: string; q?: string; page?: number }) {
  const qs = new URLSearchParams();
  if (params.view) qs.set("view", params.view);
  if (params.q) qs.set("q", params.q);
  if (params.page) qs.set("page", String(params.page));

  return useQuery({
    queryKey: ["kp-fol", params],
    queryFn: () => apiFetch<Paginated<FolRequest>>(`kp/fol?${qs}`),
  });
}

export function useFolRequest(id: string | number | undefined) {
  return useQuery({
    queryKey: ["kp-fol", id],
    queryFn: () => apiFetch<FolRequest>(`kp/fol/${id}`),
    enabled: id !== undefined && id !== "",
  });
}

export function useFolCustomers(q: string) {
  return useQuery({
    queryKey: ["kp-fol-customers", q],
    queryFn: () => apiFetch<FolCustomer[]>(`kp/fol/customers/search?q=${encodeURIComponent(q)}`),
    enabled: q.length >= 2,
  });
}

export function useFolInventory(q: string) {
  return useQuery({
    queryKey: ["kp-fol-inventory", q],
    queryFn: () => apiFetch<FolInventoryItem[]>(`kp/fol/inventory/search?q=${encodeURIComponent(q)}`),
    enabled: q.length >= 1,
  });
}

export function useFolMetrics(customerId: string, inventoryIds: string[]) {
  const qs = new URLSearchParams();
  if (customerId) qs.set("customer_acumatica_id", customerId);
  for (const id of inventoryIds) qs.append("inventory_id[]", id);

  return useQuery({
    queryKey: ["kp-fol-metrics", customerId, inventoryIds],
    queryFn: () => apiFetch<{
      metrics: { last_purchase_date: string | null; sales_6m_kes: number; volume_6m: number };
      prior_issued: Record<string, { qty: number; date: string | null }>;
    }>(`kp/fol/metrics?${qs}`),
    enabled: !!customerId,
  });
}

export function useFolTechnicians(enabled: boolean) {
  return useQuery({
    queryKey: ["kp-fol-technicians"],
    queryFn: () => apiFetch<FolTechnician[]>("kp/fol/technicians"),
    enabled,
  });
}

export function useCreateFol() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: FolInput) => apiFetch<FolRequest>("kp/fol", { method: "POST", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.setQueryData(["kp-fol", data.id], data);
    },
  });
}

export function useSubmitFol(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<FolRequest>(`kp/fol/${id}/submit`, { method: "POST" }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.setQueryData(["kp-fol", id], data);
    },
  });
}

export function submitFolRequest(id: number | string) {
  return apiFetch<FolRequest>(`kp/fol/${id}/submit`, { method: "POST" });
}

export function useFolDecision(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { decision: "approved" | "rejected"; comment: string }) =>
      apiFetch<FolRequest>(`kp/fol/${id}/decision`, { method: "POST", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.setQueryData(["kp-fol", id], data);
    },
  });
}

export function useFolSoLink(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { acumatica_order_nbr: string }) =>
      apiFetch<FolRequest>(`kp/fol/${id}/so-links`, { method: "POST", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.setQueryData(["kp-fol", id], data);
    },
  });
}

export function useFolPoLink(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { po_number: string }) =>
      apiFetch<FolRequest>(`kp/fol/${id}/po-links`, { method: "POST", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.setQueryData(["kp-fol", id], data);
    },
  });
}

export function useAssignFolTechnician(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { technician_user_id: number }) =>
      apiFetch<FolRequest>(`kp/fol/${id}/technician`, { method: "POST", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.invalidateQueries({ queryKey: ["kp-fol-technician-calendar"] });
      qc.setQueryData(["kp-fol", id], data);
    },
  });
}

export type FolCalendarItem = {
  id: number;
  public_ref: string;
  customer_acumatica_id: string;
  customer_name: string;
  status: FolStatus;
  resolve_state: "open" | "resolved" | "closed";
  installation_required: boolean;
  installation_location: string | null;
  issue_types: string[] | null;
  lines_count: number;
  linked_so_order_nbrs: string[] | null;
  technician_assigned_at: string | null;
  calendar_date: string | null;
  sales_consultant_email: string | null;
};

export type FolTechnicianCalendar = {
  month: string;
  technician: { id: number; name: string; email: string | null } | null;
  summary: {
    allocated_open: number;
    resolved: number;
    total_assigned: number;
    distinct_accounts: number;
    resolved_this_month: number;
    open_this_month: number;
  };
  days: Array<{
    date: string;
    open: number;
    resolved: number;
    items: FolCalendarItem[];
  }>;
  accounts: Array<{
    customer_acumatica_id: string;
    customer_name: string;
    open: number;
    resolved: number;
    total: number;
  }>;
  items: FolCalendarItem[];
};

export function useFolTechnicianCalendar(params: {
  month: string;
  technicianUserId?: number | null;
  enabled?: boolean;
}) {
  const qs = new URLSearchParams();
  qs.set("month", params.month);
  if (params.technicianUserId) qs.set("technician_user_id", String(params.technicianUserId));

  return useQuery({
    queryKey: ["kp-fol-technician-calendar", params.month, params.technicianUserId ?? null],
    queryFn: () => apiFetch<FolTechnicianCalendar>(`kp/fol/technician/calendar?${qs}`),
    enabled: params.enabled !== false,
  });
}

export function useResolveFolTechnician(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body?: { comment?: string }) =>
      apiFetch<FolRequest>(`kp/fol/${id}/technician/resolve`, {
        method: "POST",
        body: body ?? {},
      }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.invalidateQueries({ queryKey: ["kp-fol-technician-calendar"] });
      qc.setQueryData(["kp-fol", id], data);
    },
  });
}

export async function uploadFolAttachments(id: number | string, files: File[]) {
  const form = new FormData();
  files.forEach((file) => form.append("files[]", file));
  const token = getToken();
  const res = await fetch(`${API_BASE_URL}/kp/fol/${id}/attachments`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: form,
  });
  const data = await res.json();
  if (!res.ok) {
    throw new Error(data?.message ?? "Attachment upload failed.");
  }
  return data as FolRequest;
}

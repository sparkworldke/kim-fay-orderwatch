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
  line_type?: "fol_item";
  qty_requested: number;
  qty_previously_issued?: number;
  date_last_issue?: string | null;
  /** Unit sales price (ex-VAT) captured when added to cart. */
  unit_price?: number | null;
}

export interface FolAttachment {
  id: number;
  original_name: string;
  mime: string | null;
  size: number;
  created_at: string;
  kind?: "image" | "pdf" | "table" | "text" | "binary" | string;
  download_url?: string;
  view_url?: string;
  preview_url?: string;
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
  requestor_contact_id?: number | null;
  issue_types: string[];
  reason_text: string;
  installation_required: boolean;
  installation_location: string | null;
  assigned_technician_user_id: number | null;
  technician_assigned_by: number | null;
  technician_assigned_at: string | null;
  assigned_technician?: FolTechnician | null;
  customer_has_submitted_po: boolean;
  consumable_inventory_ids: string[];
  consumables_last_purchase_date: string | null;
  consumables_sales_3m_kes: string | number;
  consumables_volume_3m: string | number;
  consumables_sales_6m_kes: string | number;
  consumables_volume_6m: string | number;
  consumables_evidence_json?: Record<string, FolConsumableEvidence> | null;
  consumables_metrics_as_of?: string | null;
  consumables_metrics_source: "system_so" | "manual_override";
  consumables_override_reason: string | null;
  debt_explanation: string;
  status: FolStatus;
  current_stage_key: string | null;
  /** Primary Acumatica SO number attached after CCO approve / manual link. */
  so_number?: string | null;
  acumatica_so_number?: string | null;
  so_numbers?: string[] | null;
  so_status?: string | null;
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
  sales_price?: string | number | null;
  /** inventory = master sales_price; last_so = latest SO line unit price */
  price_source?: "inventory" | "last_so" | null;
}

/** Kenya VAT rate applied on FOL cart totals. */
export const FOL_VAT_RATE = 0.16;

export function folLinePricing(line: Pick<FolLine, "qty_requested" | "unit_price">) {
  const qty = Math.max(0, Number(line.qty_requested) || 0);
  const unit = Math.max(0, Number(line.unit_price) || 0);
  const subtotal = Math.round(qty * unit * 100) / 100;
  const vat = Math.round(subtotal * FOL_VAT_RATE * 100) / 100;
  const total = Math.round((subtotal + vat) * 100) / 100;
  return { qty, unit_price: unit, subtotal, vat, total, vat_rate: FOL_VAT_RATE };
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
  /** Saved CRM contact on the account (optional). */
  requestor_contact_id?: number | null;
  /** When true, create/update a contact on the customer from requestor fields. */
  save_requestor_as_contact?: boolean;
  requestor_designation_key?: string | null;
  requestor_designation_label?: string | null;
  requestor_is_primary?: boolean;
  issue_types: string[];
  reason_text: string;
  installation_required: boolean;
  installation_location?: string | null;
  customer_has_submitted_po: boolean;
  consumable_inventory_ids?: string[];
  consumables_last_purchase_date?: string | null;
  consumables_sales_3m_kes?: number;
  consumables_volume_3m?: number;
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

/** Search FOL-eligible inventory. Empty q returns the first eligible SKUs (browse mode). */
export function useFolInventory(q: string, enabled = true, purpose?: "fol_item" | "consumable") {
  return useQuery({
    queryKey: ["kp-fol-inventory", q, purpose],
    queryFn: () => apiFetch<FolInventoryItem[]>(`kp/fol/inventory/search?q=${encodeURIComponent(q)}${purpose ? `&purpose=${purpose}` : ""}`),
    enabled,
    staleTime: 30_000,
  });
}

export type FolRecentOrder = {
  order_nbr: string;
  order_date: string | null;
  order_total: number;
  status: string | null;
  order_type: string | null;
};

export type FolCustomer6mSummary = {
  months: number;
  revenue_total: number;
  order_count: number;
  aov: number;
  orders_per_month: number;
  avg_days_between_orders: number | null;
  frequency_label: string;
  first_order_date: string | null;
  last_order_date: string | null;
};

export type FolPriorSku = {
  inventory_id: string;
  product_description?: string | null;
  qty: number;
  date: string | null;
  public_ref?: string | null;
};

export type FolPriorRecent = {
  id: number;
  public_ref: string;
  status: string;
  decided_at: string | null;
  submitted_at: string | null;
  total_qty: number;
  line_count: number;
  lines: Array<{ inventory_id: string; product_description: string | null; qty: number }>;
};

export type FolPriorSummary = {
  request_count: number;
  total_qty_issued: number;
  last_issue_date: string | null;
  last_public_ref: string | null;
  last_status: string | null;
  recent: FolPriorRecent[];
  by_sku: Record<string, FolPriorSku>;
};

export type FolMetrics = {
  last_purchase_date: string | null;
  last_purchase_order_nbr: string | null;
  sales_3m_kes: number;
  volume_3m: number;
  sales_6m_kes: number;
  volume_6m: number;
  lookback_months: number;
  scope: string;
  line_last_purchases: Record<
    string,
    FolConsumableEvidence
  >;
  metrics_as_of?: string;
  /** Last SOs for the customer — not FOL-SKU filtered. */
  recent_orders?: FolRecentOrder[];
  /** Full SO book 6M summary — not FOL-SKU filtered. */
  customer_6m?: FolCustomer6mSummary;
  /** Previous Free-On-Loan issues from OrderWatch FOL (not SO lines). */
  prior_fol?: FolPriorSummary;
};

export type FolConsumableEvidence = {
  date: string | null;
  order_nbr: string | null;
  qty: number;
  value: number;
  qty_3m: number;
  value_3m: number;
};

export function useFolMetrics(customerId: string, inventoryIds: string[]) {
  const qs = new URLSearchParams();
  if (customerId) qs.set("customer_acumatica_id", customerId);
  for (const id of inventoryIds) qs.append("inventory_id[]", id);

  return useQuery({
    queryKey: ["kp-fol-metrics", customerId, inventoryIds],
    queryFn: () => apiFetch<{
      metrics: FolMetrics;
      prior_issued: Record<string, { qty: number; date: string | null; public_ref?: string | null; product_description?: string | null }>;
      prior_fol?: FolPriorSummary | null;
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

export function useUpdateFol(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: FolInput) => apiFetch<FolRequest>(`kp/fol/${id}`, { method: "PUT", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["kp-fol"] });
      qc.setQueryData(["kp-fol", data.id], data);
    },
  });
}

export function updateFolDraft(id: number | string, body: FolInput) {
  return apiFetch<FolRequest>(`kp/fol/${id}`, { method: "PUT", body });
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

export async function deleteFolAttachment(attachmentId: number | string) {
  return apiFetch<FolRequest>(`kp/fol/attachments/${attachmentId}`, { method: "DELETE" });
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

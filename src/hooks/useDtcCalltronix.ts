import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { toast } from "sonner";

export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number };

export type DtcStats = {
  quotes: { count: number; total_amount: number };
  pos_orders: { count: number; total_amount: number };
  qt_count?: number;
  so_count?: number;
  prices: { count: number; matched: number };
  date_from?: string | null;
  date_to?: string | null;
};

export type DtcPriceFilters = {
  brands: string[];
  taxations: string[];
  product_types: string[];
  warehouses: string[];
};

export type DtcMeta = {
  price_class: string;
  currency: string;
  pos_order_customer_id: string;
  is_admin: boolean;
  can_create: boolean;
  can_convert: boolean;
  can_import_quotes: boolean;
  can_import_pos?: boolean;
  can_import_prices?: boolean;
  /** Acumatica product/price sync (admin + managers). */
  can_sync_prices?: boolean;
  last_product_sync?: { status: string; started_at: string; records_processed: number } | null;
  last_price_sync?: { status: string; started_at: string; records_processed: number } | null;
  last_excel_import?: { status: string; started_at: string; records_processed: number } | null;
  last_quote_import?: { status: string; started_at: string; records_processed: number } | null;
  last_pos_import?: { status: string; started_at: string; records_processed: number } | null;
  price_filters?: DtcPriceFilters;
  stats?: DtcStats;
};

export type DtcPrice = {
  id: number;
  inventory_id: string;
  description: string | null;
  brand?: string | null;
  product_type?: string | null;
  uom: string;
  /** Sell price (incl. 16% VAT when taxable) */
  price: string;
  price_ex_vat?: string | null;
  vat_rate?: number;
  vat_amount?: string | null;
  is_taxable?: boolean;
  dtc_price?: string | null;
  default_price?: string | null;
  break_qty?: string | null;
  currency_id: string;
  price_type?: string | null;
  taxation?: string | null;
  tax?: string | null;
  effective_date: string | null;
  expiration_date: string | null;
  synced_at: string;
  product_synced_at?: string | null;
  price_synced_at?: string | null;
  qty_available: string | null;
  qty_on_hand: string | null;
  default_warehouse_id: string | null;
  item_status: string | null;
  in_catalog?: boolean;
  source?: string | null;
};

export type DtcPriceListQuery = {
  q?: string;
  page?: number;
  per_page?: number;
  brand?: string;
  taxation?: string;
  product_type?: string;
  /** Single warehouse (legacy) */
  warehouse?: string;
  /** Comma-separated multi-select, e.g. "DTC,FGS" */
  warehouses?: string;
  stock?: string;
  priced?: string;
};

export type DtcPriceImportJob = {
  id: number;
  status: string;
  original_filename: string | null;
  records_processed: number;
  progress?: { rows_read?: number; updated?: number; created?: number; skipped?: number; unmatched?: number; errors?: string[] } | null;
  error_message?: string | null;
  started_at: string;
  finished_at?: string | null;
  triggered_by?: { id: number; name: string } | null;
};

export type DtcCustomer = {
  customer_key: string;
  acumatica_id: string;
  accounting_customer_id: string;
  name: string;
  status: string | null;
  email: string | null;
  phone: string | null;
  payment_terms: string | null;
  billing_address: { address_line1?: string | null; address_line2?: string | null };
  quote_count: number;
  so_count: number;
  last_order_date: string | null;
  quotes: Array<{ id: number; number: string; total: string; status: string }>;
  pos_orders: Array<{
    quote_id: number;
    order_nbr: string;
    total: string;
    converted_at: string;
    lines: DtcPosLine[];
  }>;
};

export type DtcPosLine = {
  id?: string | null;
  inventory_id: string;
  description?: string | null;
  quantity: number;
  uom?: string | null;
  unit_price: number;
  line_total: number;
};

export type DtcLine = {
  id: number;
  inventory_id: string;
  description: string | null;
  uom: string;
  warehouse_id: string | null;
  quantity: string;
  unit_price: string;
  line_total: string;
};

export type DtcConversion = {
  status: string;
  acumatica_order_nbr: string | null;
  order_total: string | null;
  price_variance: string | null;
  attempt_count: number;
  last_error: string | null;
  converted_at: string | null;
  customer_details_snapshot?: {
    name?: string;
    email?: string;
    phone?: string;
    address_line1?: string;
    address_line2?: string;
  } | null;
  pos_lines_snapshot?: DtcPosLine[] | null;
};

export type DtcQuote = {
  id: number;
  public_ref: string;
  acumatica_quote_nbr: string | null;
  customer_acumatica_id: string;
  customer_name: string;
  status: string;
  description: string | null;
  quoted_total: string;
  currency_id: string;
  created_at: string;
  submitted_at: string | null;
  /** Acumatica QT Date (YYYY-MM-DD) */
  acumatica_date?: string | null;
  creator?: { id: number; name: string };
  lines: DtcLine[];
  conversion?: DtcConversion | null;
};

export type DtcSalesOrder = {
  id: number;
  acumatica_order_nbr: string;
  customer_acumatica_id: string;
  customer_name: string;
  status: string;
  order_date: string;
  /** Acumatica SO Date (YYYY-MM-DD) — same as order_date from ERP */
  acumatica_date?: string | null;
  order_total: string;
  lines?: unknown[];
  dtc_conversion?: {
    quote?: { public_ref: string; acumatica_quote_nbr: string | null };
    converted_at: string;
  } | null;
};

const qs = (p: Record<string, string | number | undefined>) => {
  const x = new URLSearchParams();
  Object.entries(p).forEach(([k, v]) => {
    if (v !== undefined && v !== "") x.set(k, String(v));
  });
  return x;
};

export const useDtcMeta = (dateFrom?: string, dateTo?: string) =>
  useQuery({
    queryKey: ["dtc-meta", dateFrom ?? "", dateTo ?? ""],
    queryFn: () =>
      apiFetch<DtcMeta>(`kp/dtc-calltronix/meta?${qs({ date_from: dateFrom, date_to: dateTo })}`),
  });

export const useDtcStats = (dateFrom?: string, dateTo?: string) =>
  useQuery({
    queryKey: ["dtc-stats", dateFrom ?? "", dateTo ?? ""],
    queryFn: () =>
      apiFetch<DtcStats>(`kp/dtc-calltronix/stats?${qs({ date_from: dateFrom, date_to: dateTo })}`),
  });

export const useDtcPrices = (filters: DtcPriceListQuery = {}) =>
  useQuery({
    queryKey: ["dtc-prices", filters],
    queryFn: () =>
      apiFetch<Page<DtcPrice>>(
        `kp/dtc-calltronix/prices?${qs({
          q: filters.q,
          page: filters.page ?? 1,
          per_page: filters.per_page ?? 100,
          brand: filters.brand,
          taxation: filters.taxation,
          product_type: filters.product_type,
          warehouse: filters.warehouse,
          warehouses: filters.warehouses,
          stock: filters.stock,
          priced: filters.priced,
        })}`,
      ),
  });

export const useDtcPriceImportJobs = () =>
  useQuery({
    queryKey: ["dtc-price-import-jobs"],
    queryFn: () => apiFetch<Page<DtcPriceImportJob>>("kp/dtc-calltronix/prices/import-jobs"),
    refetchInterval: (query) =>
      query.state.data?.data?.some((job) => ["queued", "running"].includes(job.status)) ? 3000 : false,
  });

export const useDtcCustomers = (q = "") =>
  useQuery({
    queryKey: ["dtc-customers", q],
    queryFn: () => apiFetch<Page<DtcCustomer>>(`kp/dtc-calltronix/customers?${qs({ q })}`),
  });

export const useDtcQuotes = (q = "", status = "") =>
  useQuery({
    queryKey: ["dtc-quotes", q, status],
    queryFn: () => apiFetch<Page<DtcQuote>>(`kp/dtc-calltronix/quotes?${qs({ q, status })}`),
  });

export const useDtcSalesOrders = (q = "") =>
  useQuery({
    queryKey: ["dtc-sales-orders", q],
    queryFn: () => apiFetch<Page<DtcSalesOrder>>(`kp/dtc-calltronix/sales-orders?${qs({ q })}`),
  });

export function useDtcActions() {
  const qc = useQueryClient();
  const refresh = () => {
    qc.invalidateQueries({ queryKey: ["dtc-quotes"] });
    qc.invalidateQueries({ queryKey: ["dtc-sales-orders"] });
    qc.invalidateQueries({ queryKey: ["dtc-meta"] });
    qc.invalidateQueries({ queryKey: ["dtc-stats"] });
    qc.invalidateQueries({ queryKey: ["dtc-prices"] });
    qc.invalidateQueries({ queryKey: ["dtc-customers"] });
  };

  return {
    create: useMutation({
      mutationFn: (body: {
        customer_acumatica_id: string;
        description?: string | null;
        lines: Array<{ inventory_id: string; quantity: number }>;
      }) => apiFetch<DtcQuote>("kp/dtc-calltronix/quotes", { method: "POST", body }),
      onSuccess: () => {
        toast.success("Draft quote created");
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    submit: useMutation({
      mutationFn: (id: number) =>
        apiFetch<DtcQuote>(`kp/dtc-calltronix/quotes/${id}/submit`, { method: "POST" }),
      onSuccess: () => {
        toast.success("QT created in Acumatica");
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    convert: useMutation({
      mutationFn: (id: number) =>
        apiFetch<DtcQuote>(`kp/dtc-calltronix/quotes/${id}/convert`, { method: "POST" }),
      onSuccess: () => {
        toast.success("Quote converted to sales order");
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    updateConvertedCustomer: useMutation({
      mutationFn: ({
        id,
        ...body
      }: {
        id: number;
        name: string;
        email?: string;
        phone?: string;
        address_line1?: string;
        address_line2?: string;
      }) =>
        apiFetch<DtcQuote>(`kp/dtc-calltronix/quotes/${id}/converted-customer`, {
          method: "PUT",
          body,
        }),
      onSuccess: () => {
        toast.success("POS customer details updated");
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    syncProducts: useMutation({
      mutationFn: () =>
        apiFetch<{ ok: boolean; products: number }>("kp/dtc-calltronix/prices/sync-products", {
          method: "POST",
          timeoutMs: 180_000,
        }),
      onSuccess: (x) => {
        toast.success(`Synced ${x.products} products into DTC price list`);
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    sync: useMutation({
      mutationFn: () =>
        apiFetch<{
          processed: number;
          matched: number;
          unmatched: number;
          skipped?: number;
          fetched?: number;
          price_source?: string;
          products?: number;
        }>("kp/dtc-calltronix/prices/refresh", { method: "POST", timeoutMs: 600_000 }),
      onSuccess: (x) => {
        const source = x.price_source ? ` · ${x.price_source}` : "";
        const fetched = x.fetched != null ? `${x.fetched} from Acumatica · ` : "";
        toast.success(
          `DTCACCOUNT: ${fetched}${x.processed} saved · ${x.matched} matched catalog · ${x.unmatched ?? 0} price-only${source}`,
        );
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    syncAll: useMutation({
      mutationFn: () =>
        apiFetch<{
          products: number;
          processed: number;
          matched: number;
          unmatched: number;
          fetched?: number;
          price_source?: string;
        }>("kp/dtc-calltronix/prices/refresh?full=1", { method: "POST", timeoutMs: 600_000 }),
      onSuccess: (x) => {
        toast.success(
          `Products ${x.products} · DTCACCOUNT ${x.fetched != null ? x.fetched + " fetched · " : ""}${x.processed} saved · ${x.matched} matched · ${x.price_source ?? "inquiry"}`,
        );
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    importExcel: useMutation({
      mutationFn: async (file: File) => {
        const { API_BASE_URL } = await import("@/lib/api");
        const token =
          typeof window !== "undefined" ? window.localStorage.getItem("kf_token") : null;
        if (!token) {
          throw new Error("You are signed out. Refresh the page and sign in again.");
        }
        const form = new FormData();
        form.append("file", file);
        const base = API_BASE_URL.replace(/\/+$/, "");
        const url = base.endsWith("/api")
          ? `${base}/kp/dtc-calltronix/prices/import-excel`
          : `${base}/api/kp/dtc-calltronix/prices/import-excel`;
        const controller = new AbortController();
        const t = window.setTimeout(() => controller.abort(), 300_000);
        try {
          const res = await fetch(url, {
            method: "POST",
            headers: {
              Accept: "application/json",
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
              // Do NOT set Content-Type — browser must set multipart boundary for FormData.
            },
            body: form,
            signal: controller.signal,
          });
          const text = await res.text();
          let data: Record<string, unknown> = {};
          try {
            data = text ? (JSON.parse(text) as Record<string, unknown>) : {};
          } catch {
            throw new Error(
              res.ok
                ? "Import returned a non-JSON response."
                : `Import failed (${res.status}). ${text.slice(0, 180) || "Server error"}`,
            );
          }
          if (!res.ok) {
            const errors = data.errors as Record<string, string[]> | undefined;
            const firstFieldError =
              errors && typeof errors === "object"
                ? Object.values(errors).flat().find(Boolean)
                : undefined;
            throw new Error(
              (data.message as string) ||
                firstFieldError ||
                (data.error as string) ||
                `Import failed (${res.status})`,
            );
          }
          return data as {
            job_id: number;
            status: string;
            rows_read?: number;
            updated?: number;
            created?: number;
            skipped?: number;
            unmatched?: number;
            errors?: string[];
            message?: string;
          };
        } catch (e) {
          if (e instanceof DOMException && e.name === "AbortError") {
            throw new Error("Upload timed out. Try a smaller file or check your connection.");
          }
          throw e;
        } finally {
          window.clearTimeout(t);
        }
      },
      onSuccess: (x) => {
        qc.invalidateQueries({ queryKey: ["dtc-price-import-jobs"] });
        const updated = Number(x.updated ?? 0);
        const created = Number(x.created ?? 0);
        const unmatched = Number(x.unmatched ?? 0);
        const rows = Number(x.rows_read ?? updated + created);
        if (x.status === "failed") {
          toast.error(x.message || "Price import failed");
        } else {
          toast.success(
            `Price import complete: ${rows} rows · ${updated} updated · ${created} new` +
              (unmatched > 0 ? ` · ${unmatched} not in inventory` : ""),
          );
        }
        refresh();
      },
      onError: (e: Error) => toast.error(e.message || "Price file upload failed"),
    }),
    importQuotes: useMutation({
      mutationFn: (body: {
        scope: "range" | "date" | "all";
        date?: string;
        date_from?: string;
        date_to?: string;
      }) =>
        apiFetch<{
          fetched: number;
          created: number;
          updated: number;
          total_amount: number;
        }>("kp/dtc-calltronix/quotes/import", { method: "POST", body, timeoutMs: 300_000 }),
      onSuccess: (x) => {
        toast.success(
          `Imported ${x.fetched} QT (${x.created} new, ${x.updated} updated) · KES ${Number(x.total_amount ?? 0).toLocaleString()}`,
        );
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
    importPos: useMutation({
      mutationFn: (body: { date_from: string; date_to: string }) =>
        apiFetch<{
          fetched: number;
          processed: number;
          failed: number;
          total_amount: number;
          status: string;
        }>("kp/dtc-calltronix/sales-orders/import", {
          method: "POST",
          body,
          timeoutMs: 300_000,
        }),
      onSuccess: (x) => {
        toast.success(
          `POS import: ${x.processed} saved of ${x.fetched} · KES ${Number(x.total_amount ?? 0).toLocaleString()}`,
        );
        refresh();
      },
      onError: (e: Error) => toast.error(e.message),
    }),
  };
}

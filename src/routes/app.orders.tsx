import { createFileRoute, useSearch } from "@tanstack/react-router";
import {
  AlertTriangle,
  ChevronDown,
  ChevronRight,
  Download,
  GitMerge,
  RefreshCw,
} from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { useOrderStats, useOrders, useUpdateOrder } from "@/hooks/useOrders";
import { useMatchOrders } from "@/hooks/admin/useAdminSettings";
import type { AcumaticaSalesOrder } from "@/types/admin";

export const Route = createFileRoute("/app/orders")({
  head: () => ({ meta: [{ title: "Orders — Kim-Fay OrderWatch" }] }),
  validateSearch: (s: Record<string, string>) => ({
    status:    typeof s.status    === "string" ? s.status    : undefined,
    date_from: typeof s.date_from === "string" ? s.date_from : undefined,
    date_to:   typeof s.date_to   === "string" ? s.date_to   : undefined,
  }),
  component: OrdersPage,
});

function today() { return new Date().toISOString().slice(0, 10); }
function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

const ORDER_STATUSES = ["Open", "Completed", "Cancelled", "Back Order", "Credit Hold", "On Hold", "Rejected", "Shipping", "Pending Approval"];

const MATCH_STATUSES = [
  { value: "matched",   label: "Matched" },
  { value: "matched_discrepancies", label: "Matched with Discrepancies" },
  { value: "needs_review", label: "Needs Review" },
  { value: "unmatched", label: "Unmatched" },
  { value: "duplicate", label: "Duplicate" },
  { value: "escalated", label: "Escalated" },
  { value: "missing",   label: "Missing" },
];

function OrdersPage() {
  const search = useSearch({ from: "/app/orders" });
  const [q, setQ]                   = useState("");
  const [status, setStatus]         = useState(search.status ?? "all");
  const [matchStatus, setMatchStatus] = useState("all");
  const [flagFilter, setFlagFilter] = useState("all");
  const [sort, setSort]             = useState<"latest" | "oldest">("latest");
  const [dateFrom, setDateFrom]     = useState(() => search.date_from ?? startOfMonth());
  const [dateTo, setDateTo]         = useState(() => search.date_to   ?? today());
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());
  const [page, setPage]       = useState(1);
  const [perPage, setPerPage] = useState(50);

  const { data, isLoading, isError, refetch } = useOrders({
    q:         q || undefined,
    status:    status !== "all" ? status : undefined,
    sort,
    date_from: dateFrom || undefined,
    date_to:   dateTo   || undefined,
    page,
    per_page:  perPage,
  });

  // Reset to page 1 when filters change
  const resetPage = () => setPage(1);

  const updateOrder = useUpdateOrder();
  const matchOrders = useMatchOrders();
  const orderStats  = useOrderStats({ q: q || undefined, date_from: dateFrom || undefined, date_to: dateTo || undefined });

  function toggleExpand(id: number) {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function exportCsv() {
    if (!data?.data) return;
    const headers = ["Order #", "Type", "Customer", "Customer ID", "PO Number", "Status", "Match Status", "Flag", "Lines", "Order Date", "Email In Date", "Email Subject", "Total", "Currency"];
    const rows = data.data.map((o) => [
      o.acumatica_order_nbr, o.order_type,
      `"${o.customer_name ?? ""}"`, o.customer_acumatica_id ?? "",
      o.customer_order ?? "", o.status ?? "",
      o.match_status, o.flag_source ?? "",
      o.lines_count ?? "", o.order_date ?? "",
      o.email_received_at ?? "", `"${o.email_subject ?? ""}"`,
      o.order_total, o.currency_id ?? "KES",
    ]);
    const csv = [headers.join(","), ...rows.map((r) => r.join(","))].join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `kim-fay-orders-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success(`Exported ${data.data.length} orders`);
  }

  // Client-side filter for match_status and flag_source (server doesn't filter these yet)
  const orders = (data?.data ?? []).filter((o) => {
    if (matchStatus !== "all" && o.match_status !== matchStatus) return false;
    if (flagFilter === "acumatica" && o.flag_source !== "acumatica") return false;
    if (flagFilter === "email"     && o.flag_source !== "email") return false;
    if (flagFilter === "none"      && o.flag_source !== null) return false;
    return true;
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Orders</h1>
        <p className="text-sm text-muted-foreground">Acumatica sales orders synced to OrderWatch.</p>
      </div>

      {/* Stat cards */}
      <OrderStatCards stats={orderStats.data} loading={orderStats.isLoading} activeStatus={status} onFilter={setStatus} />

      {/* Filters */}
      <div className="flex flex-wrap items-end gap-2">
        <div className="grid gap-1.5">
          <Label className="text-xs">Search</Label>
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Order #, customer, PO…" className="h-9 w-48 text-sm" />
        </div>
        <div className="grid gap-1.5">
          <Label className="text-xs">From</Label>
          <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-9 w-36 text-sm" />
        </div>
        <div className="grid gap-1.5">
          <Label className="text-xs">To</Label>
          <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-9 w-36 text-sm" />
        </div>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="h-9 w-[150px] text-sm"><SelectValue placeholder="Status" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {ORDER_STATUSES.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={matchStatus} onValueChange={setMatchStatus}>
          <SelectTrigger className="h-9 w-[140px] text-sm"><SelectValue placeholder="Match" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All matches</SelectItem>
            {MATCH_STATUSES.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={flagFilter} onValueChange={setFlagFilter}>
          <SelectTrigger className="h-9 w-[140px] text-sm"><SelectValue placeholder="Flag" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All flags</SelectItem>
            <SelectItem value="acumatica">⚠ Acumatica</SelectItem>
            <SelectItem value="email">⚠ Email</SelectItem>
            <SelectItem value="none">No flag</SelectItem>
          </SelectContent>
        </Select>
        <Select value={sort} onValueChange={(v) => { setSort(v as "latest" | "oldest"); resetPage(); }}>
          <SelectTrigger className="h-9 w-[130px] text-sm"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="latest">⬇ Latest first</SelectItem>
            <SelectItem value="oldest">⬆ Oldest first</SelectItem>
          </SelectContent>
        </Select>
        <Button variant="outline" size="sm" className="h-9" onClick={() => refetch()}>
          <RefreshCw className="mr-1 h-3.5 w-3.5" /> Refresh
        </Button>
        <Button variant="outline" size="sm" className="h-9" onClick={exportCsv} disabled={!orders.length}>
          <Download className="mr-1 h-3.5 w-3.5" /> Export
        </Button>
        <Button
          size="sm"
          className="h-9 bg-violet-600 hover:bg-violet-700 text-white"
          onClick={() => matchOrders.mutate()}
          disabled={matchOrders.isPending}
        >
          <GitMerge className="mr-1 h-3.5 w-3.5" />
          {matchOrders.isPending ? "Matching…" : "Match Orders"}
        </Button>
      </div>

      {isLoading && (
        <div className="space-y-2">
          {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
        </div>
      )}

      {isError && (
        <div className="rounded-lg border bg-card p-6 text-center text-sm text-muted-foreground">
          Failed to load orders.{" "}
          <button type="button" className="underline" onClick={() => refetch()}>Retry</button>
        </div>
      )}

      {!isLoading && !isError && orders.length === 0 && (
        <div className="rounded-lg border bg-card p-10 text-center">
          <p className="text-sm text-muted-foreground">No orders found.</p>
          <p className="mt-1 text-xs text-muted-foreground">
            Run a sync from <strong>Administration → Sync Operations</strong> to import orders.
          </p>
        </div>
      )}

      {!isLoading && !isError && orders.length > 0 && (
        <>
          <div className="overflow-x-auto rounded-lg border bg-card shadow-[var(--shadow-panel)]">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-[11px] uppercase tracking-wide text-muted-foreground">
                <tr>
                  <th className="w-8 px-2 py-3" />
                  <th className="px-3 py-3 text-left font-semibold">Match Status</th>
                  <th className="px-3 py-3 text-left font-semibold">Flag</th>
                  <th className="px-3 py-3 text-left font-semibold">PO Number</th>
                  <th className="px-3 py-3 text-left font-semibold">Customer</th>
                  <th className="px-3 py-3 text-left font-semibold">Email Subject</th>
                  <th className="px-3 py-3 text-left font-semibold">Email In Date</th>
                  <th className="px-3 py-3 text-left font-semibold">Order #</th>
                  <th className="px-3 py-3 text-left font-semibold">Status</th>
                  <th className="px-3 py-3 text-center font-semibold">Lines</th>
                  <th className="px-3 py-3 text-right font-semibold">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {orders.map((order) => {
                  const expanded = expandedIds.has(order.id);
                  return (
                    <>
                      <tr
                        key={order.id}
                        className={`cursor-pointer transition-colors hover:bg-muted/20 ${expanded ? "bg-muted/10" : ""}`}
                        onClick={() => toggleExpand(order.id)}
                      >
                        {/* Expand */}
                        <td className="px-2 py-3 text-muted-foreground">
                          {expanded
                            ? <ChevronDown className="h-3.5 w-3.5" />
                            : <ChevronRight className="h-3.5 w-3.5" />}
                        </td>

                        {/* Match Status */}
                        <td className="px-3 py-3"><MatchBadge status={order.match_status} /></td>

                        {/* Flag Source */}
                        <td className="px-3 py-3"><FlagBadge source={order.flag_source} /></td>

                        {/* PO Number */}
                        <td className="px-3 py-3 font-mono text-xs font-medium">
                          {order.customer_order ?? <span className="text-muted-foreground">—</span>}
                        </td>

                        {/* Customer */}
                        <td className="px-3 py-3">
                          <div className="font-medium leading-tight">{order.customer_name ?? "—"}</div>
                          <div className="font-mono text-[10px] text-muted-foreground">{order.customer_acumatica_id}</div>
                        </td>

                        {/* Email Subject (truncated) */}
                        <td className="px-3 py-3 max-w-[220px]">
                          {order.email_subject
                            ? <span className="block truncate text-xs text-muted-foreground" title={order.email_subject}>{order.email_subject}</span>
                            : <span className="text-xs text-muted-foreground/40 italic">—</span>}
                        </td>

                        {/* Email In Date */}
                        <td className="px-3 py-3 whitespace-nowrap text-xs text-muted-foreground">
                          {order.email_received_at ? fmtCompact(order.email_received_at) : <span className="italic">—</span>}
                        </td>

                        {/* Order # */}
                        <td className="px-3 py-3">
                          <span className="font-mono text-xs font-semibold">{order.acumatica_order_nbr}</span>
                          <span className="ml-1 font-mono text-[10px] text-muted-foreground">{order.order_type}</span>
                        </td>

                        {/* Status */}
                        <td className="px-3 py-3"><StatusChip status={order.status ?? ""} /></td>

                        {/* Lines */}
                        <td className="px-3 py-3 text-center">
                          {order.lines_count != null
                            ? <span className="inline-flex h-5 min-w-5 items-center justify-center rounded bg-muted px-1.5 font-mono text-[10px] font-medium">{order.lines_count}</span>
                            : <span className="text-muted-foreground">—</span>}
                        </td>

                        {/* Total */}
                        <td className="px-3 py-3 text-right font-mono text-xs tabular-nums">
                          {order.currency_id ?? "KES"} {Number(order.order_total).toLocaleString("en-KE", { minimumFractionDigits: 2 })}
                        </td>
                      </tr>

                      {/* Expanded detail row */}
                      {expanded && (
                        <tr key={`${order.id}-expand`} className="bg-muted/5 border-b">
                          <td />
                          <td colSpan={10} className="px-3 pb-4 pt-2">
                            <div className="grid gap-3 sm:grid-cols-3">
                              <DetailCard
                                label="Email Subject"
                                value={order.email_subject}
                                empty="No email linked to this order"
                              />
                              <DetailCard
                                label="Reason for Rejection"
                                value={order.rejection_reason}
                                empty="No rejection reason recorded"
                                tone="red"
                              />
                              <DetailCard
                                label="Reason for On Hold"
                                value={order.on_hold_reason}
                                empty="No on-hold reason recorded"
                                tone="amber"
                              />
                            </div>
                            <div className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-3 lg:grid-cols-6">
                              <DateSlot label="Email In"      value={order.email_received_at} accent="violet" />
                              <DateSlot label="Order Created" value={order.order_date}        accent="blue" />
                              <DateSlot label="Date Approved" value={order.approved_at}       accent="amber" />
                              <DateSlot label="Ship Date"     value={order.ship_date}         accent="purple" />
                              <DateSlot label="Date Completed" value={order.completed_at}     accent="green" />
                              <DateSlot label="Due"           value={order.requested_on}      accent="default" />
                            </div>
                            <div className="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
                              <span>Last modified: <strong>{fmtDatetime(order.last_modified_at) || "—"}</strong></span>
                              <OrderCycleDuration order={order} />
                            </div>
                          </td>
                        </tr>
                      )}
                    </>
                  );
                })}
              </tbody>
            </table>
          </div>
          <PaginationControls
            currentPage={data!.current_page}
            lastPage={data!.last_page}
            total={data!.total}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={(s) => { setPerPage(s); setPage(1); }}
          />
        </>
      )}
    </div>
  );
}

// -------------------------------------------------------------------------
// Order stat cards (reused on Orders page and linked from Dashboard)
// -------------------------------------------------------------------------

import type { OrderStats } from "@/hooks/useOrders";

const ORDER_STAT_CARDS: {
  key: keyof OrderStats;
  label: string;
  statusFilter: string;
  color: string; bg: string; border: string;
}[] = [
  { key: "total",            label: "Total",           statusFilter: "all",              color: "text-blue-700 dark:text-blue-300",   bg: "bg-blue-50 dark:bg-blue-950/40",   border: "border-blue-200 dark:border-blue-800" },
  { key: "completed",        label: "Completed",       statusFilter: "Completed",        color: "text-green-700 dark:text-green-300", bg: "bg-green-50 dark:bg-green-950/40", border: "border-green-200 dark:border-green-800" },
  { key: "pending_approval", label: "Pending Approval",statusFilter: "Pending Approval", color: "text-amber-700 dark:text-amber-300", bg: "bg-amber-50 dark:bg-amber-950/40", border: "border-amber-200 dark:border-amber-800" },
  { key: "shipping",         label: "Shipping",        statusFilter: "Shipping",         color: "text-purple-700 dark:text-purple-300", bg: "bg-purple-50 dark:bg-purple-950/40", border: "border-purple-200 dark:border-purple-800" },
  { key: "rejected",         label: "Rejected",        statusFilter: "Rejected",         color: "text-red-700 dark:text-red-300",   bg: "bg-red-50 dark:bg-red-950/40",   border: "border-red-200 dark:border-red-800" },
  { key: "on_hold",          label: "On Hold",         statusFilter: "On Hold",          color: "text-orange-700 dark:text-orange-300", bg: "bg-orange-50 dark:bg-orange-950/40", border: "border-orange-200 dark:border-orange-800" },
];

function OrderStatCards({
  stats, loading, activeStatus, onFilter,
}: {
  stats: OrderStats | undefined;
  loading: boolean;
  activeStatus: string;
  onFilter: (status: string) => void;
}) {
  if (loading) {
    return (
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
        {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-16" />)}
      </div>
    );
  }

  return (
    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
      {ORDER_STAT_CARDS.map((card) => {
        const isActive = activeStatus === card.statusFilter;
        return (
          <button
            key={card.key}
            type="button"
            onClick={() => onFilter(isActive ? "all" : card.statusFilter)}
            className={`rounded-lg border p-3 text-left transition-all hover:shadow-sm active:scale-[0.97] ${card.bg} ${card.border} ${isActive ? "ring-2 ring-offset-1 ring-current" : ""}`}
          >
            <div className={`text-[10px] font-semibold uppercase tracking-wide ${card.color} opacity-75 truncate`}>
              {card.label}
            </div>
            <div className={`mt-1 text-xl font-bold tabular-nums ${card.color}`}>
              {stats ? (stats[card.key] ?? 0).toLocaleString() : "—"}
            </div>
          </button>
        );
      })}
    </div>
  );
}

// -------------------------------------------------------------------------
// Badges & chips
// -------------------------------------------------------------------------

const MATCH_MAP: Record<AcumaticaSalesOrder["match_status"], { label: string; cls: string }> = {
  matched:   { label: "Matched",   cls: "border-green-300 bg-green-50 text-green-700 dark:border-green-700 dark:bg-green-950/40 dark:text-green-300" },
  matched_discrepancies: { label: "Matched with Discrepancies", cls: "border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300" },
  needs_review: { label: "Needs Review", cls: "border-violet-300 bg-violet-50 text-violet-700 dark:border-violet-700 dark:bg-violet-950/40 dark:text-violet-300" },
  unmatched: { label: "Unmatched", cls: "border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-950/40 dark:text-red-300" },
  duplicate: { label: "Duplicate", cls: "border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950/40 dark:text-blue-300" },
  escalated: { label: "Escalated", cls: "border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-950/40 dark:text-red-300" },
  missing:   { label: "Missing",   cls: "border-red-400 bg-red-100 text-red-800 dark:border-red-600 dark:bg-red-950/60 dark:text-red-200" },
  pending:   { label: "Pending",   cls: "border-muted bg-muted/30 text-muted-foreground" },
};

function MatchBadge({ status }: { status: AcumaticaSalesOrder["match_status"] }) {
  const { label, cls } = MATCH_MAP[status] ?? MATCH_MAP.pending;
  return <Badge variant="outline" className={`text-[10px] font-medium ${cls}`}>{label}</Badge>;
}

function FlagBadge({ source }: { source: AcumaticaSalesOrder["flag_source"] }) {
  if (!source) return <span className="text-xs text-muted-foreground">—</span>;
  const isAcumatica = source === "acumatica";
  return (
    <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-medium ${
      isAcumatica
        ? "border-orange-300 bg-orange-50 text-orange-700 dark:border-orange-700 dark:bg-orange-950/40 dark:text-orange-300"
        : "border-purple-300 bg-purple-50 text-purple-700 dark:border-purple-700 dark:bg-purple-950/40 dark:text-purple-300"
    }`}>
      <AlertTriangle className="h-2.5 w-2.5" />
      {isAcumatica ? "Acumatica" : "Email"}
    </span>
  );
}

function StatusChip({ status }: { status: string }) {
  const s = status.toLowerCase();
  const tone =
    s === "open"      ? "border-blue-400/40 bg-blue-400/10 text-blue-600"
    : s === "completed" ? "border-success/40 bg-success/10 text-success"
    : s.includes("cancel") || s === "rejected"
        ? "border-destructive/40 bg-destructive/10 text-destructive"
    : s.includes("hold") || s === "pending approval"
        ? "border-amber-400/40 bg-amber-400/10 text-amber-600"
    : "border-muted bg-muted/30 text-muted-foreground";
  return (
    <span className={`inline-block rounded border px-1.5 py-0.5 text-[10px] font-medium ${tone}`}>
      {status || "—"}
    </span>
  );
}

// -------------------------------------------------------------------------
// Detail card (expanded row)
// -------------------------------------------------------------------------

function DetailCard({ label, value, empty, tone }: { label: string; value: string | null; empty: string; tone?: "red" | "amber" }) {
  const toneClass = tone === "red"
    ? "border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/30"
    : tone === "amber"
      ? "border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30"
      : "border-border bg-muted/30";
  return (
    <div className={`rounded-md border p-3 ${toneClass}`}>
      <div className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</div>
      {value
        ? <p className="text-xs leading-relaxed">{value}</p>
        : <p className="text-xs italic text-muted-foreground/60">{empty}</p>}
    </div>
  );
}

// -------------------------------------------------------------------------
// Date slot card (compact labeled date in the expanded row)
// -------------------------------------------------------------------------

type DateAccent = "violet" | "blue" | "amber" | "purple" | "green" | "default";

const DATE_ACCENT: Record<DateAccent, { dot: string; value: string }> = {
  violet:  { dot: "bg-violet-400",  value: "text-violet-700 dark:text-violet-300" },
  blue:    { dot: "bg-blue-400",    value: "text-blue-700 dark:text-blue-300" },
  amber:   { dot: "bg-amber-400",   value: "text-amber-700 dark:text-amber-300" },
  purple:  { dot: "bg-purple-400",  value: "text-purple-700 dark:text-purple-300" },
  green:   { dot: "bg-green-400",   value: "text-green-700 dark:text-green-300" },
  default: { dot: "bg-muted-foreground/40", value: "text-foreground" },
};

function DateSlot({
  label, value, accent = "default",
}: {
  label: string;
  value: string | null | undefined;
  accent?: DateAccent;
}) {
  const { dot, value: valueCls } = DATE_ACCENT[accent];
  const formatted = value ? fmtDatetime(value) : null;

  return (
    <div className="min-w-0">
      <div className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
        <span className={`inline-block h-1.5 w-1.5 rounded-full ${dot}`} />
        {label}
      </div>
      {formatted ? (
        <div className={`mt-0.5 text-xs font-medium ${valueCls}`}>{formatted}</div>
      ) : (
        <div className="mt-0.5 text-xs italic text-muted-foreground/40">Not recorded</div>
      )}
    </div>
  );
}

// -------------------------------------------------------------------------
// Order cycle duration
// -------------------------------------------------------------------------

/**
 * Shows a lifecycle stage badge for the order:
 *   Not Started  (grey)  — status is Open / Pending Approval
 *   In Progress  (blue)  — order active, not yet completed
 *   Completed    (green) — status is Completed / has completed_at
 *
 * When a duration can be computed it is appended: e.g. "Completed · 2d 4h"
 */
function OrderCycleDuration({ order }: { order: AcumaticaSalesOrder }) {
  const status      = (order.status ?? "").toLowerCase();
  const isCompleted = status === "completed" || !!order.completed_at;
  const isNotStarted = ["open", "pending approval"].includes(status) && !order.approved_at;

  // Stage config
  const stage = isCompleted
    ? {
        label: "Completed",
        cls:   "border-green-300 bg-green-50 text-green-700 dark:border-green-700 dark:bg-green-950/40 dark:text-green-300",
        dot:   "bg-green-500",
      }
    : isNotStarted
    ? {
        label: "Not Started",
        cls:   "border-muted bg-muted/30 text-muted-foreground",
        dot:   "bg-muted-foreground/40",
      }
    : {
        label: "In Progress",
        cls:   "border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950/40 dark:text-blue-300",
        dot:   "bg-blue-500",
      };

  // Duration calculation
  const startMs = order.email_received_at
    ? new Date(order.email_received_at).getTime()
    : order.order_date
    ? new Date(order.order_date).getTime()
    : NaN;

  const endMs = isCompleted
    ? (order.completed_at ? new Date(order.completed_at).getTime() : new Date(order.last_modified_at ?? "").getTime())
    : isNotStarted
    ? NaN
    : Date.now(); // In progress — measure up to now

  let duration = "";
  if (!isNaN(startMs) && !isNaN(endMs) && endMs > startMs) {
    const diffMs    = endMs - startMs;
    const totalMins = Math.floor(diffMs / 60_000);
    const days      = Math.floor(totalMins / 1440);
    const hours     = Math.floor((totalMins % 1440) / 60);
    const mins      = totalMins % 60;
    if (days > 0)       duration = `${days}d ${hours}h`;
    else if (hours > 0) duration = `${hours}h ${mins}m`;
    else                duration = `${mins}m`;
  }

  return (
    <span className={`inline-flex items-center gap-1.5 rounded border px-2 py-0.5 text-[10px] font-semibold ${stage.cls}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${stage.dot} ${!isCompleted && !isNotStarted ? "animate-pulse" : ""}`} />
      {stage.label}
      {duration && <span className="opacity-70">· {duration}</span>}
    </span>
  );
}

// -------------------------------------------------------------------------
// Date helpers
// -------------------------------------------------------------------------

/** Parse a date/datetime string robustly — handles both "2026-06-20" and ISO "2026-06-20T00:00:00.000000Z" */
function parseDate(value: string): Date {
  // Already has time component — parse directly
  if (value.includes("T") || value.includes(" ")) return new Date(value);
  // Date-only string — avoid UTC midnight shift by appending local midnight
  return new Date(value + "T00:00:00");
}

function fmtDate(value: string | null | undefined): string {
  if (!value) return "";
  const d = parseDate(value);
  return isNaN(d.getTime()) ? "" : d.toLocaleDateString("en-KE", { year: "numeric", month: "short", day: "numeric" });
}

function fmtDatetime(value: string | null | undefined): string {
  if (!value) return "";
  const d = new Date(value);
  if (isNaN(d.getTime())) return "";
  const tz = { timeZone: "Africa/Nairobi" } as const;
  const hasTime = value.includes("T") || value.includes(" ");
  if (!hasTime) {
    return d.toLocaleDateString("en-KE", { ...tz, weekday: "short", day: "numeric", month: "short", year: "numeric" });
  }
  const weekday = d.toLocaleDateString("en-KE", { ...tz, weekday: "short" });
  const date    = d.toLocaleDateString("en-KE", { ...tz, day: "numeric", month: "short", year: "numeric" });
  const time    = d.toLocaleTimeString("en-KE", { ...tz, hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false });
  return `${weekday}, ${date} ${time}`;
}

/** Compact "Mon 18 Jun, 13:38" format for table cells */
function fmtCompact(value: string | null | undefined): string {
  if (!value) return "—";
  const d = new Date(value);
  if (isNaN(d.getTime())) return "—";
  const tz = { timeZone: "Africa/Nairobi" } as const;
  const weekday = d.toLocaleDateString("en-KE", { ...tz, weekday: "short" });
  const date    = d.toLocaleDateString("en-KE", { ...tz, day: "numeric", month: "short" });
  const time    = d.toLocaleTimeString("en-KE", { ...tz, hour: "2-digit", minute: "2-digit", hour12: false });
  return `${weekday} ${date}, ${time}`;
}

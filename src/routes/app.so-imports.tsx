import { createFileRoute } from "@tanstack/react-router";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import {
  CheckCircle2,
  GitBranch,
  Mail,
  MailOpen,
  PackageCheck,
  RefreshCw,
  Search,
  Trash2,
  TrendingUp,
  Users,
  XCircle,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";

export const Route = createFileRoute("/app/so-imports")({
  head: () => ({ meta: [{ title: "Sales Order Imports — Kim-Fay OrderWatch" }] }),
  component: SoImportsPage,
});

// -------------------------------------------------------------------------
// Types
// -------------------------------------------------------------------------

type ImportStats = { total: number; successful: number; failed: number };
type StatusFilter = "all" | "successful" | "failed";

type SuccessfulOrder = {
  id: number;
  acumatica_order_nbr: string;
  order_type: string;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  status: string | null;
  order_date: string | null;
  order_total: string;
  currency_id: string | null;
  synced_at: string | null;
};

type SuccessfulCustomer = {
  id: number;
  acumatica_id: string;
  name: string | null;
  status: string | null;
  email: string | null;
  phone: string | null;
  customer_class: string | null;
  synced_at: string | null;
};

type FailedRecord = {
  id: number;
  resource_id: string | null;
  attempt_count: number;
  last_error: string;
  created_at: string;
};

type EmailRecord = {
  id: number;
  subject: string | null;
  from_email: string | null;
  from_name: string | null;
  to_recipients: string[] | null;
  body_preview: string | null;
  is_read: boolean;
  folder: string;
  received_at: string | null;
};

type EmailStats = { total: number; read: number; unread: number };
type EmailStatusFilter = "all" | "read" | "unread";
type EmailImportsResponse = { stats: EmailStats; items: Paginated<EmailRecord> };

type Paginated<T> = { data: T[]; total: number; current_page: number; last_page: number };
type ImportsResponse<T> = { stats: ImportStats; items: Paginated<T | FailedRecord>; mode: "successful" | "failed" };

// -------------------------------------------------------------------------
// Hooks
// -------------------------------------------------------------------------

type PagingParams = { page: number; perPage: number };

function buildQs(base: Record<string, string>, paging: PagingParams) {
  return new URLSearchParams({ ...base, page: String(paging.page), per_page: String(paging.perPage) }).toString();
}

function useOrderImports(params: { dateFrom: string; dateTo: string; status: StatusFilter } & PagingParams) {
  const qs = buildQs({ date_from: params.dateFrom, date_to: params.dateTo, status: params.status }, params);
  return useQuery({
    queryKey: ["so-imports", "orders", params.dateFrom, params.dateTo, params.status, params.page, params.perPage],
    queryFn: () => apiFetch<ImportsResponse<SuccessfulOrder>>(`so-imports?${qs}`),
  });
}

function useCustomerImports(params: { dateFrom: string; dateTo: string; status: StatusFilter } & PagingParams) {
  const qs = buildQs({ date_from: params.dateFrom, date_to: params.dateTo, status: params.status }, params);
  return useQuery({
    queryKey: ["so-imports", "customers", params.dateFrom, params.dateTo, params.status, params.page, params.perPage],
    queryFn: () => apiFetch<ImportsResponse<SuccessfulCustomer>>(`so-imports/customers?${qs}`),
  });
}

function useWorkflow(params: { dateFrom: string; dateTo: string; q: string } & PagingParams) {
  const qs = buildQs({ date_from: params.dateFrom, date_to: params.dateTo, q: params.q }, params);
  return useQuery({
    queryKey: ["so-imports", "workflow", params.dateFrom, params.dateTo, params.q, params.page, params.perPage],
    queryFn: () => apiFetch<Paginated<WorkflowOrder>>(`so-imports/workflow?${qs}`),
  });
}

function useEmailImports(params: { dateFrom: string; dateTo: string; status: EmailStatusFilter } & PagingParams) {
  const qs = buildQs({ date_from: params.dateFrom, date_to: params.dateTo, status: params.status }, params);
  return useQuery({
    queryKey: ["so-imports", "emails", params.dateFrom, params.dateTo, params.status, params.page, params.perPage],
    queryFn: () => apiFetch<EmailImportsResponse>(`so-imports/emails?${qs}`),
  });
}

function useTruncate(resource: "orders" | "customers" | "emails") {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<{ message: string }>(`so-imports/truncate/${resource}`, { method: "POST" }),
    onSuccess: (res) => {
      toast.success(res.message);
      qc.invalidateQueries({ queryKey: ["so-imports"] });
    },
    onError: () => toast.error("Failed to clear data"),
  });
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

const today = () => new Date().toISOString().slice(0, 10);

function fmt(value: string | null | undefined) {
  if (!value) return "—";
  const d = new Date(value);
  return isNaN(d.getTime()) ? value : d.toLocaleString();
}

function fmtDate(value: string | null | undefined) {
  if (!value) return "—";
  const d = new Date(value + "T00:00:00");
  return isNaN(d.getTime()) ? value : d.toLocaleDateString();
}

function fmtCurrency(amount: string | number | null, currency: string | null) {
  if (amount == null) return "—";
  const num = typeof amount === "string" ? parseFloat(amount) : amount;
  return `${currency ?? "KES"} ${num.toLocaleString("en-KE", { minimumFractionDigits: 2 })}`;
}

// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

type WorkflowOrder = {
  id: number;
  acumatica_order_nbr: string;
  order_type: string;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  status: string | null;
  order_date: string | null;
  approved_at: string | null;
  shipped_at: string | null;
  completed_at: string | null;
};

type PageTab = "orders" | "customers" | "emails" | "workflow";

const TAB_META: Record<PageTab, { label: string; icon: React.ComponentType<{ className?: string }> }> = {
  orders:    { label: "Sales Orders",          icon: PackageCheck },
  customers: { label: "Customer Import",       icon: Users },
  emails:    { label: "Email Imports",         icon: Mail },
  workflow:  { label: "Workflow Optimization", icon: GitBranch },
};

function SoImportsPage() {
  const { session } = useAuth();
  const isAdmin = session?.role === "Administrator";
  const [activeTab, setActiveTab] = useState<PageTab>("orders");

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Sales Order Imports</h1>
        <p className="mt-0.5 text-sm text-muted-foreground">
          Acumatica sync results — imported orders, customers and emails.
        </p>
      </div>

      {/* Page-level tabs */}
      <div className="flex border-b">
        {(Object.keys(TAB_META) as PageTab[]).map((t) => {
          const { label, icon: Icon } = TAB_META[t];
          return (
            <button
              key={t}
              type="button"
              onClick={() => setActiveTab(t)}
              className={`flex items-center gap-2 border-b-2 px-5 py-2.5 text-sm font-medium transition-colors ${
                activeTab === t
                  ? "border-primary text-foreground"
                  : "border-transparent text-muted-foreground hover:text-foreground"
              }`}
            >
              <Icon className="h-4 w-4" />
              {label}
            </button>
          );
        })}
      </div>

      {activeTab === "orders"    && <OrdersTab    isAdmin={isAdmin} />}
      {activeTab === "customers" && <CustomersTab isAdmin={isAdmin} />}
      {activeTab === "emails"    && <EmailsTab    isAdmin={isAdmin} />}
      {activeTab === "workflow"  && <WorkflowTab />}
    </div>
  );
}

// -------------------------------------------------------------------------
// Orders tab
// -------------------------------------------------------------------------

function OrdersTab({ isAdmin }: { isAdmin: boolean }) {
  const [dateFrom, setDateFrom] = useState(today);
  const [dateTo, setDateTo]     = useState(today);
  const [status, setStatus]     = useState<StatusFilter>("all");
  const [page, setPage]         = useState(1);
  const [perPage, setPerPage]   = useState(50);
  const truncate                = useTruncate("orders");

  const { data, isLoading, isError, refetch, isFetching } = useOrderImports({ dateFrom, dateTo, status, page, perPage });
  const stats = data?.stats ?? { total: 0, successful: 0, failed: 0 };

  function handleClear() {
    if (!confirm("This will permanently delete ALL imported sales orders and line items. Continue?")) return;
    truncate.mutate();
  }

  return (
    <TabContent
      stats={stats}
      statsLoading={isLoading}
      dateFrom={dateFrom} setDateFrom={setDateFrom}
      dateTo={dateTo}     setDateTo={setDateTo}
      status={status}     setStatus={setStatus}
      isFetching={isFetching}
      onRefresh={() => refetch()}
      isAdmin={isAdmin}
      onClear={handleClear}
      clearPending={truncate.isPending}
      clearLabel="Clear All SO Data"
    >
      {isLoading && <TableSkeleton />}
      {isError && <ErrorState onRetry={() => refetch()} />}
      {data && (
        <>
          {data.mode === "failed"
            ? <FailedTable items={data.items as Paginated<FailedRecord>} />
            : <OrdersTable items={data.items as Paginated<SuccessfulOrder>} status={status} />}
          <PaginationControls currentPage={data.items.current_page} lastPage={data.items.last_page} total={data.items.total} perPage={perPage} onPageChange={setPage} onPerPageChange={(s) => { setPerPage(s); setPage(1); }} />
        </>
      )}
    </TabContent>
  );
}

// -------------------------------------------------------------------------
// Customers tab
// -------------------------------------------------------------------------

function CustomersTab({ isAdmin }: { isAdmin: boolean }) {
  const [dateFrom, setDateFrom] = useState(today);
  const [dateTo, setDateTo]     = useState(today);
  const [status, setStatus]     = useState<StatusFilter>("all");
  const [page, setPage]         = useState(1);
  const [perPage, setPerPage]   = useState(50);
  const truncate                = useTruncate("customers");

  const { data, isLoading, isError, refetch, isFetching } = useCustomerImports({ dateFrom, dateTo, status, page, perPage });
  const stats = data?.stats ?? { total: 0, successful: 0, failed: 0 };

  function handleClear() {
    if (!confirm("This will permanently delete ALL imported customer records. Continue?")) return;
    truncate.mutate();
  }

  return (
    <TabContent
      stats={stats}
      statsLoading={isLoading}
      dateFrom={dateFrom} setDateFrom={setDateFrom}
      dateTo={dateTo}     setDateTo={setDateTo}
      status={status}     setStatus={setStatus}
      isFetching={isFetching}
      onRefresh={() => refetch()}
      isAdmin={isAdmin}
      onClear={handleClear}
      clearPending={truncate.isPending}
      clearLabel="Clear All Customer Data"
    >
      {isLoading && <TableSkeleton />}
      {isError && <ErrorState onRetry={() => refetch()} />}
      {data && (
        <>
          {data.mode === "failed"
            ? <FailedTable items={data.items as Paginated<FailedRecord>} />
            : <CustomersTable items={data.items as Paginated<SuccessfulCustomer>} status={status} />}
          <PaginationControls currentPage={data.items.current_page} lastPage={data.items.last_page} total={data.items.total} perPage={perPage} onPageChange={setPage} onPerPageChange={(s) => { setPerPage(s); setPage(1); }} />
        </>
      )}
    </TabContent>
  );
}

// -------------------------------------------------------------------------
// Emails tab
// -------------------------------------------------------------------------

function EmailsTab({ isAdmin }: { isAdmin: boolean }) {
  const [dateFrom, setDateFrom]   = useState(today);
  const [dateTo, setDateTo]       = useState(today);
  const [status, setStatus]       = useState<EmailStatusFilter>("all");
  const [page, setPage]           = useState(1);
  const [perPage, setPerPage]     = useState(50);
  const truncate                  = useTruncate("emails");

  const { data, isLoading, isError, refetch, isFetching } = useEmailImports({ dateFrom, dateTo, status, page, perPage });
  const stats = data?.stats ?? { total: 0, read: 0, unread: 0 };

  function handleClear() {
    if (!confirm("This will permanently delete ALL imported email records. Continue?")) return;
    truncate.mutate();
  }

  return (
    <div className="space-y-5">
      {/* Stat cards */}
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Total Emails" value={stats.total}  icon={Mail}      color="blue"   loading={isLoading} />
        <StatCard label="Read"         value={stats.read}   icon={MailOpen}  color="green"  loading={isLoading} />
        <StatCard label="Unread"       value={stats.unread} icon={Mail}      color="amber"  loading={isLoading} />
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-4">
        <div className="grid gap-1.5">
          <Label>From</Label>
          <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-40" />
        </div>
        <div className="grid gap-1.5">
          <Label>To</Label>
          <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-40" />
        </div>
        <div className="grid gap-1.5">
          <Label>Status</Label>
          <div className="flex rounded-lg border bg-muted/40 p-1 gap-1">
            {(["all", "read", "unread"] as EmailStatusFilter[]).map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => setStatus(s)}
                className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize transition-all ${
                  status === s ? "bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {s}
              </button>
            ))}
          </div>
        </div>
        <div className="ml-auto flex items-end gap-2">
          <Button variant="outline" size="sm" onClick={() => refetch()} disabled={isFetching}>
            <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${isFetching ? "animate-spin" : ""}`} />
            Refresh
          </Button>
          {isAdmin && (
            <Button variant="destructive" size="sm" onClick={handleClear} disabled={truncate.isPending}>
              <Trash2 className="mr-1.5 h-3.5 w-3.5" />
              {truncate.isPending ? "Clearing…" : "Clear All Email Data"}
            </Button>
          )}
        </div>
      </div>

      {isLoading && <TableSkeleton />}
      {isError   && <ErrorState onRetry={() => refetch()} />}
      {data && (
        <>
          <EmailsTable items={data.items} status={status} />
          <PaginationControls currentPage={data.items.current_page} lastPage={data.items.last_page} total={data.items.total} perPage={perPage} onPageChange={setPage} onPerPageChange={(s) => { setPerPage(s); setPage(1); }} />
        </>
      )}
    </div>
  );
}

function EmailsTable({ items, status }: { items: Paginated<EmailRecord>; status: EmailStatusFilter }) {
  if (items.data.length === 0) {
    return <EmptyState icon={Mail} title={status === "all" ? "No emails for this date range" : `No ${status} emails`} />;
  }
  return (
    <div className="overflow-x-auto rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
          <tr>
            {["", "Subject", "From", "To", "Folder", "Received At"].map((h) => (
              <th key={h} className="px-4 py-2.5 text-left font-medium">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {items.data.map((row) => (
            <tr key={row.id} className={`hover:bg-muted/20 transition-colors ${!row.is_read ? "font-medium" : ""}`}>
              <td className="px-4 py-2.5">
                {row.is_read
                  ? <MailOpen className="h-3.5 w-3.5 text-muted-foreground" />
                  : <Mail className="h-3.5 w-3.5 text-primary" />
                }
              </td>
              <td className="px-4 py-2.5 max-w-xs">
                <span className="block truncate" title={row.subject ?? undefined}>
                  {row.subject ?? <span className="text-muted-foreground italic">(no subject)</span>}
                </span>
                {row.body_preview && (
                  <span className="block truncate text-xs font-normal text-muted-foreground" title={row.body_preview}>
                    {row.body_preview}
                  </span>
                )}
              </td>
              <td className="px-4 py-2.5 text-xs">
                <div>{row.from_name ?? row.from_email ?? "—"}</div>
                {row.from_name && <div className="text-muted-foreground">{row.from_email}</div>}
              </td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">
                {Array.isArray(row.to_recipients) && row.to_recipients.length > 0
                  ? row.to_recipients.slice(0, 2).join(", ") + (row.to_recipients.length > 2 ? ` +${row.to_recipients.length - 2}` : "")
                  : "—"}
              </td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{row.folder}</td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{fmt(row.received_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// -------------------------------------------------------------------------
// Workflow Optimization tab
// -------------------------------------------------------------------------

function WorkflowTab() {
  const [dateFrom, setDateFrom]     = useState(today);
  const [dateTo, setDateTo]         = useState(today);
  const [q, setQ]                   = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [page, setPage]             = useState(1);
  const [perPage, setPerPage]       = useState(50);

  const handleQ = (v: string) => {
    setQ(v);
    clearTimeout((handleQ as { _t?: ReturnType<typeof setTimeout> })._t);
    (handleQ as { _t?: ReturnType<typeof setTimeout> })._t = setTimeout(() => { setDebouncedQ(v); setPage(1); }, 400);
  };

  const { data, isLoading, isError, refetch, isFetching } = useWorkflow({ dateFrom, dateTo, q: debouncedQ, page, perPage });

  return (
    <div className="space-y-5">
      {/* Filters */}
      <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-4">
        <div className="grid gap-1.5">
          <Label>Order Date From</Label>
          <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-40" />
        </div>
        <div className="grid gap-1.5">
          <Label>Order Date To</Label>
          <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-40" />
        </div>
        <div className="grid gap-1.5 flex-1 min-w-48">
          <Label>Search</Label>
          <div className="relative">
            <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={q}
              onChange={(e) => handleQ(e.target.value)}
              placeholder="Order #, customer name or ID…"
              className="pl-9"
            />
          </div>
        </div>
        <Button variant="outline" size="sm" onClick={() => refetch()} disabled={isFetching}>
          <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${isFetching ? "animate-spin" : ""}`} />
          Refresh
        </Button>
      </div>

      {isLoading && <TableSkeleton />}
      {isError   && <ErrorState onRetry={() => refetch()} />}
      {data && (
        <>
          <WorkflowTable items={data} />
          <PaginationControls currentPage={data.current_page} lastPage={data.last_page} total={data.total} perPage={perPage} onPageChange={setPage} onPerPageChange={(s) => { setPerPage(s); setPage(1); }} />
        </>
      )}
    </div>
  );
}

function WorkflowTable({ items }: { items: Paginated<WorkflowOrder> }) {
  if (items.data.length === 0) {
    return <EmptyState icon={GitBranch} title="No orders for this date range" subtitle="Adjust the date range or run a sales order sync." />;
  }

  return (
    <div className="overflow-x-auto rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
          <tr>
            <th className="px-4 py-3 text-left font-medium">Order #</th>
            <th className="px-4 py-3 text-left font-medium">Type</th>
            <th className="px-4 py-3 text-left font-medium">Customer ID</th>
            <th className="px-4 py-3 text-left font-medium">Customer Name</th>
            <th className="px-4 py-3 text-left font-medium">
              <div className="flex items-center gap-1.5">
                <span className="inline-block h-2 w-2 rounded-full bg-blue-400" />
                Created
              </div>
            </th>
            <th className="px-4 py-3 text-left font-medium">
              <div className="flex items-center gap-1.5">
                <span className="inline-block h-2 w-2 rounded-full bg-amber-400" />
                Approved
              </div>
            </th>
            <th className="px-4 py-3 text-left font-medium">
              <div className="flex items-center gap-1.5">
                <span className="inline-block h-2 w-2 rounded-full bg-purple-400" />
                Shipping
              </div>
            </th>
            <th className="px-4 py-3 text-left font-medium">
              <div className="flex items-center gap-1.5">
                <span className="inline-block h-2 w-2 rounded-full bg-green-400" />
                Completed
              </div>
            </th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {items.data.map((row) => (
            <tr key={row.id} className="hover:bg-muted/20 transition-colors">
              <td className="px-4 py-3 font-mono text-xs font-semibold">{row.acumatica_order_nbr}</td>
              <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{row.order_type}</td>
              <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{row.customer_acumatica_id ?? "—"}</td>
              <td className="px-4 py-3 font-medium">{row.customer_name ?? <span className="text-muted-foreground">—</span>}</td>
              <td className="px-4 py-3"><WorkflowCell value={row.order_date} color="blue" isDate /></td>
              <td className="px-4 py-3"><WorkflowCell value={row.approved_at} color="amber" /></td>
              <td className="px-4 py-3"><WorkflowCell value={row.shipped_at} color="purple" /></td>
              <td className="px-4 py-3"><WorkflowCell value={row.completed_at} color="green" /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

type WfColor = "blue" | "amber" | "purple" | "green";
const wfColors: Record<WfColor, string> = {
  blue:   "text-blue-700 dark:text-blue-300",
  amber:  "text-amber-700 dark:text-amber-300",
  purple: "text-purple-700 dark:text-purple-300",
  green:  "text-green-700 dark:text-green-300",
};

function WorkflowCell({ value, color, isDate }: { value: string | null; color: WfColor; isDate?: boolean }) {
  if (!value) {
    return <span className="text-xs text-muted-foreground/50 italic">Pending</span>;
  }
  const d = new Date(isDate ? value + "T00:00:00" : value);
  if (isNaN(d.getTime())) return <span className="text-xs text-muted-foreground">—</span>;
  return (
    <span className={`text-xs font-medium ${wfColors[color]}`}>
      {isDate ? d.toLocaleDateString() : d.toLocaleString()}
    </span>
  );
}

// -------------------------------------------------------------------------
// Shared tab shell
// -------------------------------------------------------------------------

function TabContent({
  stats, statsLoading,
  dateFrom, setDateFrom, dateTo, setDateTo,
  status, setStatus,
  isFetching, onRefresh,
  isAdmin, onClear, clearPending, clearLabel,
  children,
}: {
  stats: ImportStats; statsLoading: boolean;
  dateFrom: string; setDateFrom: (v: string) => void;
  dateTo: string;   setDateTo:   (v: string) => void;
  status: StatusFilter; setStatus: (v: StatusFilter) => void;
  isFetching: boolean; onRefresh: () => void;
  isAdmin: boolean; onClear: () => void; clearPending: boolean; clearLabel: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-5">
      {/* Stat cards */}
      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Total Imported" value={stats.total}      icon={TrendingUp}   color="blue"  loading={statsLoading} />
        <StatCard label="Successful"     value={stats.successful} icon={CheckCircle2} color="green" loading={statsLoading} />
        <StatCard label="Failed"         value={stats.failed}     icon={XCircle}      color="red"   loading={statsLoading} />
      </div>

      {/* Filters row */}
      <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-4">
        <div className="grid gap-1.5">
          <Label>From</Label>
          <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-40" />
        </div>
        <div className="grid gap-1.5">
          <Label>To</Label>
          <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-40" />
        </div>
        <div className="grid gap-1.5">
          <Label>Status</Label>
          <div className="flex rounded-lg border bg-muted/40 p-1 gap-1">
            {(["all", "successful", "failed"] as StatusFilter[]).map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => setStatus(s)}
                className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize transition-all ${
                  status === s ? "bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {s}
              </button>
            ))}
          </div>
        </div>

        <div className="ml-auto flex items-end gap-2">
          <Button variant="outline" size="sm" onClick={onRefresh} disabled={isFetching}>
            <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${isFetching ? "animate-spin" : ""}`} />
            Refresh
          </Button>
          {isAdmin && (
            <Button
              variant="destructive"
              size="sm"
              onClick={onClear}
              disabled={clearPending}
            >
              <Trash2 className="mr-1.5 h-3.5 w-3.5" />
              {clearPending ? "Clearing…" : clearLabel}
            </Button>
          )}
        </div>
      </div>

      {children}
    </div>
  );
}

// -------------------------------------------------------------------------
// Tables
// -------------------------------------------------------------------------

function OrdersTable({ items, status }: { items: Paginated<SuccessfulOrder>; status: StatusFilter }) {
  if (items.data.length === 0) {
    return <EmptyState icon={PackageCheck} title={status === "all" ? "No imports for this date range" : "No successful imports"} />;
  }
  return (
    <div className="overflow-x-auto rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
          <tr>
            {["Order #", "Type", "Customer", "Customer ID", "Status", "Order Date", "Total", "Synced At"].map((h) => (
              <th key={h} className="px-4 py-2.5 text-left font-medium">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {(items.data as SuccessfulOrder[]).map((row) => (
            <tr key={row.id} className="hover:bg-muted/20 transition-colors">
              <td className="px-4 py-2.5 font-mono text-xs font-medium">{row.acumatica_order_nbr}</td>
              <td className="px-4 py-2.5 font-mono text-xs text-muted-foreground">{row.order_type}</td>
              <td className="px-4 py-2.5 font-medium">{row.customer_name ?? <span className="text-muted-foreground">—</span>}</td>
              <td className="px-4 py-2.5 font-mono text-xs text-muted-foreground">{row.customer_acumatica_id ?? "—"}</td>
              <td className="px-4 py-2.5"><StatusBadge status={row.status} /></td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{fmtDate(row.order_date)}</td>
              <td className="px-4 py-2.5 text-xs tabular-nums">{fmtCurrency(row.order_total, row.currency_id)}</td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{fmt(row.synced_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CustomersTable({ items, status }: { items: Paginated<SuccessfulCustomer>; status: StatusFilter }) {
  if (items.data.length === 0) {
    return <EmptyState icon={Users} title={status === "all" ? "No customers for this date range" : "No successful customer imports"} />;
  }
  return (
    <div className="overflow-x-auto rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
          <tr>
            {["Customer ID", "Name", "Class", "Status", "Email", "Phone", "Synced At"].map((h) => (
              <th key={h} className="px-4 py-2.5 text-left font-medium">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {(items.data as SuccessfulCustomer[]).map((row) => (
            <tr key={row.id} className="hover:bg-muted/20 transition-colors">
              <td className="px-4 py-2.5 font-mono text-xs font-medium">{row.acumatica_id}</td>
              <td className="px-4 py-2.5 font-medium">{row.name ?? <span className="text-muted-foreground">—</span>}</td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{row.customer_class ?? "—"}</td>
              <td className="px-4 py-2.5"><StatusBadge status={row.status} /></td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{row.email ?? "—"}</td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{row.phone ?? "—"}</td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{fmt(row.synced_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function FailedTable({ items }: { items: Paginated<FailedRecord> }) {
  if (items.data.length === 0) {
    return <EmptyState icon={CheckCircle2} title="No failed imports" subtitle="All records in this date range were imported successfully." />;
  }
  return (
    <div className="overflow-x-auto rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
          <tr>
            {["Record ID", "Attempts", "Error", "Failed At"].map((h) => (
              <th key={h} className="px-4 py-2.5 text-left font-medium">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {(items.data as FailedRecord[]).map((row) => (
            <tr key={row.id} className="hover:bg-muted/20 transition-colors">
              <td className="px-4 py-2.5 font-mono text-xs font-medium">{row.resource_id ?? "—"}</td>
              <td className="px-4 py-2.5 text-xs">{row.attempt_count}</td>
              <td className="px-4 py-2.5 max-w-md">
                <span className="block truncate text-xs text-destructive" title={row.last_error}>{row.last_error}</span>
              </td>
              <td className="px-4 py-2.5 text-xs text-muted-foreground">{fmt(row.created_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// -------------------------------------------------------------------------
// Primitives
// -------------------------------------------------------------------------

type StatColor = "blue" | "green" | "red" | "amber";
const colorMap: Record<StatColor, { card: string; icon: string; value: string }> = {
  blue:  { card: "border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/40",       icon: "text-blue-500",   value: "text-blue-700 dark:text-blue-300"    },
  green: { card: "border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950/40",   icon: "text-green-500",  value: "text-green-700 dark:text-green-300"  },
  red:   { card: "border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/40",           icon: "text-red-500",    value: "text-red-700 dark:text-red-300"      },
  amber: { card: "border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40",   icon: "text-amber-500",  value: "text-amber-700 dark:text-amber-300"  },
};

function StatCard({ label, value, icon: Icon, color, loading }: { label: string; value: number; icon: React.ComponentType<{ className?: string }>; color: StatColor; loading: boolean }) {
  const c = colorMap[color];
  return (
    <div className={`rounded-lg border p-5 ${c.card}`}>
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-muted-foreground">{label}</span>
        <Icon className={`h-5 w-5 ${c.icon}`} />
      </div>
      {loading ? <Skeleton className="mt-3 h-8 w-20" /> : (
        <p className={`mt-3 text-3xl font-bold tabular-nums ${c.value}`}>{value.toLocaleString()}</p>
      )}
    </div>
  );
}

function StatusBadge({ status }: { status: string | null }) {
  if (!status) return <span className="text-muted-foreground text-xs">—</span>;
  const s = status.toLowerCase();
  const tone =
    s === "completed" || s === "active" ? "border-green-300 bg-green-50 text-green-700 dark:border-green-700 dark:bg-green-950/40 dark:text-green-300"
    : s === "open"    ? "border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950/40 dark:text-blue-300"
    : s === "cancelled" || s === "rejected" || s === "inactive" ? "border-red-300 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-950/40 dark:text-red-300"
    : "border-muted bg-muted/30 text-muted-foreground";
  return <Badge variant="outline" className={`text-[10px] ${tone}`}>{status}</Badge>;
}

function EmptyState({ icon: Icon, title, subtitle }: { icon: React.ComponentType<{ className?: string }>; title: string; subtitle?: string }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-lg border bg-card py-16 text-center">
      <Icon className="mb-3 h-10 w-10 text-muted-foreground/40" />
      <p className="font-medium">{title}</p>
      {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
    </div>
  );
}

function TableSkeleton() {
  return (
    <div className="space-y-2 rounded-lg border bg-card p-4">
      {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
    </div>
  );
}

function ErrorState({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="rounded-lg border bg-card p-6 text-center text-sm text-muted-foreground">
      Failed to load data.{" "}
      <button className="font-medium text-primary hover:underline" onClick={onRetry}>Retry</button>
    </div>
  );
}


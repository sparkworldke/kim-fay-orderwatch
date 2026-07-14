import { Link, createFileRoute, useParams } from "@tanstack/react-router";
import { CustomerLink, DateWithActions } from "@/components/entity-links";
import {
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  ArrowUpDown,
  BriefcaseBusiness,
  Calendar,
  DollarSign,
  Search,
  UserCheck,
  Users,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  useConsultantCustomers,
  useConsultantDetail,
  type SalesConsultantProfile,
  type ConsultantCustomerFilters,
} from "@/hooks/useSalesConsultants";
import { useCapabilities } from "@/hooks/useCapabilities";
import { useSalesManagementPrompts } from "@/hooks/useSalesManagement";
import { ApiError } from "@/lib/api";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  promptSeverityClass,
  SALES_PROMPT_TYPE_LABEL,
  shortDate,
} from "@/lib/sales-management";

export const Route = createFileRoute("/app/sales-consultants/$id")({
  head: () => ({ meta: [{ title: "Sales Consultant — Kim-Fay OrderWatch" }] }),
  component: SalesConsultantDetailPage,
});

function today() {
  return new Date().toISOString().slice(0, 10);
}

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function SalesConsultantDetailPage() {
  const { id } = useParams({ from: "/app/sales-consultants/$id" });
  const consultantIdentifier = id.trim();
  const [dateFrom, setDateFrom] = useState(startOfMonth);
  const [dateTo, setDateTo] = useState(today);
  // Customer table state — search (debounced), sorting (server-side), pagination
  const [searchInput, setSearchInput] = useState("");
  const [searchDebounced, setSearchDebounced] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [sort, setSort] = useState("total_order_value");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");

  // Debounce the search input (350ms) to avoid excessive API calls
  useEffect(() => {
    const handle = setTimeout(() => {
      setSearchDebounced(searchInput.trim());
      setPage(1);
    }, 350);
    return () => clearTimeout(handle);
  }, [searchInput]);

  const dateFilters = useMemo(
    () => ({
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }),
    [dateFrom, dateTo],
  );

  const customerFilters = useMemo<ConsultantCustomerFilters>(
    () => ({
      ...dateFilters,
      q: searchDebounced || undefined,
      page,
      per_page: perPage,
      sort,
      sort_dir: sortDir,
    }),
    [dateFilters, searchDebounced, page, perPage, sort, sortDir],
  );

  const detail = useConsultantDetail(consultantIdentifier, dateFilters);
  const customers = useConsultantCustomers(consultantIdentifier, customerFilters);
  const capabilities = useCapabilities();
  const canViewSalesPrompts = capabilities.permissions.includes("sales.management.view");
  const consultant = detail.data?.consultant;
  const summary = detail.data?.summary ?? customers.data?.summary;
  const rows = customers.data?.customers ?? [];
  const pagination = customers.data?.pagination;

  // Column definitions are used for header click-to-sort (server-side).
  // The actual sort is driven by `sort` / `sortDir` state, not client-side table sorting.
  const sortableColumns: { key: string; label: string; align?: "right" }[] = [
    { key: "customer_name", label: "Customer" },
    { key: "order_count", label: "Orders", align: "right" },
    { key: "orders_per_month", label: "Frequency", align: "right" },
    { key: "active_orders", label: "Active", align: "right" },
    { key: "completed_orders", label: "Completed", align: "right" },
    { key: "total_order_value", label: "Sales", align: "right" },
    { key: "fill_rate_pct", label: "Fill Rate", align: "right" },
    { key: "revenue_lost", label: "Rev. Lost", align: "right" },
    { key: "last_order_date", label: "Last Order", align: "right" },
  ];

  function handleSort(columnKey: string) {
    if (sort === columnKey) {
      setSortDir(sortDir === "asc" ? "desc" : "asc");
    } else {
      setSort(columnKey);
      setSortDir("desc");
    }
    setPage(1);
  }

  if (!consultantIdentifier) {
    return (
      <div className="flex flex-col gap-4 p-6">
        <BackLink />
        <ErrorBlock message="Invalid consultant identifier." />
      </div>
    );
  }

  if (detail.isError) {
    const message =
      detail.error instanceof ApiError
        ? detail.error.status === 404
          ? "Sales consultant not found."
          : detail.error.status === 403
            ? "You do not have permission to view this consultant."
            : detail.error.message
        : "Unable to load sales consultant.";

    return (
      <div className="flex flex-col gap-4 p-6">
        <BackLink />
        <ErrorBlock message={message} onRetry={() => detail.refetch()} />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <BackLink />
          <h1 className="text-2xl font-semibold tracking-tight">
            {detail.isLoading ? "Sales Consultant" : consultant?.name ?? "Sales Consultant"}
          </h1>
          <p className="text-sm text-muted-foreground">
            {consultant?.email ?? "Assigned customer sales from Sales Order data."}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {consultant?.rep_code && (
            <Badge variant="secondary" className="font-mono">
              Rep Code {consultant.rep_code}
            </Badge>
          )}
          {consultant && (
            <Badge variant={consultant.is_active ? "default" : "secondary"}>
              {consultant.is_active ? "Active" : "Inactive"}
            </Badge>
          )}
        </div>
      </div>

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Date range</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div className="grid gap-1.5">
            <Label htmlFor="sc-from">From</Label>
            <Input
              id="sc-from"
              type="date"
              value={dateFrom}
              onChange={(event) => setDateFrom(event.target.value)}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="sc-to">To</Label>
            <Input
              id="sc-to"
              type="date"
              value={dateTo}
              onChange={(event) => setDateTo(event.target.value)}
            />
          </div>
          <div className="flex items-end sm:col-span-2 lg:col-span-2">
            <p className="text-xs text-muted-foreground">
              KPI cards and the customer table reflect orders in this range.
              {dateFrom && dateTo ? ` Showing ${formatDate(dateFrom)} – ${formatDate(dateTo)}.` : ""}
            </p>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <MetricCard
          label="Total sales"
          value={formatMoney(summary?.total_order_value)}
          loading={detail.isLoading}
          icon={DollarSign}
          text
        />
        <MetricCard
          label="Customers"
          value={summary?.customer_count ?? 0}
          loading={detail.isLoading}
          icon={Users}
        />
        <MetricCard
          label="Orders"
          value={summary?.total_orders ?? 0}
          loading={detail.isLoading}
          icon={BriefcaseBusiness}
        />
        <MetricCard
          label="Active orders"
          value={summary?.active_orders ?? 0}
          loading={detail.isLoading}
          icon={UserCheck}
        />
        <MetricCard
          label="Last order"
          value={formatDate(summary?.last_order_date)}
          loading={detail.isLoading}
          icon={Calendar}
          text
        />
      </div>

      {consultant && canViewSalesPrompts && <ConsultantPromptPanel consultant={consultant} />}

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
          <CardTitle className="text-base">Customers & order activity</CardTitle>
          <div className="relative w-full max-w-xs">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search customers…"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              className="pl-9"
            />
          </div>
        </CardHeader>
        <CardContent>
          {customers.isLoading ? (
            <SkeletonRows />
          ) : customers.isError ? (
            <ErrorBlock
              message={customers.error instanceof Error ? customers.error.message : "Unable to load customers."}
              onRetry={() => customers.refetch()}
            />
          ) : rows.length === 0 ? (
            <div className="rounded border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
              {searchDebounced
                ? "No customers match your search."
                : "No customers found for this consultant in the selected date range."}
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    {sortableColumns.map((col) => (
                      <TableHead
                        key={col.key}
                        className={col.align === "right" ? "text-right" : undefined}
                      >
                        <button
                          type="button"
                          className={`inline-flex items-center gap-1 ${col.align === "right" ? "ml-auto" : ""}`}
                          onClick={() => handleSort(col.key)}
                        >
                          {col.label}
                          {sort === col.key && sortDir === "asc" && <ArrowUp className="h-3 w-3" />}
                          {sort === col.key && sortDir === "desc" && <ArrowDown className="h-3 w-3" />}
                          {sort !== col.key && <ArrowUpDown className="h-3 w-3 opacity-40" />}
                        </button>
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.customer_id}>
                      <TableCell>
                        <div>
                          <CustomerLink
                            customerId={row.customer_id}
                            className="font-medium"
                          >
                            {row.customer_name ?? "-"}
                          </CustomerLink>
                          <div className="text-xs text-muted-foreground">
                            {row.customer_id}
                            {row.customer_class ? ` · ${row.customer_class}` : ""}
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {row.order_count.toLocaleString("en-KE")}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatFrequency(row.orders_per_month, row.order_count)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {row.active_orders.toLocaleString("en-KE")}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {row.completed_orders.toLocaleString("en-KE")}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatMoney(row.total_order_value)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {row.fill_rate_pct != null ? formatPercent(row.fill_rate_pct) : "-"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {row.revenue_lost != null && row.revenue_lost > 0
                          ? formatMoney(row.revenue_lost)
                          : "-"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        <DateWithActions value={row.last_order_date} emptyText="-" />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {pagination && (
                <div className="mt-4">
                  <PaginationControls
                    currentPage={pagination.current_page}
                    lastPage={pagination.last_page}
                    total={pagination.total}
                    perPage={pagination.per_page}
                    onPageChange={setPage}
                    onPerPageChange={(size) => {
                      setPerPage(size);
                      setPage(1);
                    }}
                  />
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function BackLink() {
  return (
    <Button asChild variant="ghost" size="sm" className="-ml-2 mb-2">
      <Link to="/app/sales-consultants">
        <ArrowLeft className="mr-2 h-4 w-4" />
        Sales Consultants
      </Link>
    </Button>
  );
}

function ConsultantPromptPanel({ consultant }: { consultant: SalesConsultantProfile }) {
  const prompts = useSalesManagementPrompts({
    consultant_user_id: consultant.id,
    view: "due",
    per_page: 5,
  });
  const rows = prompts.data?.data ?? [];
  const total = prompts.data?.total ?? 0;
  const overdue = rows.filter((prompt) => prompt.severity === "overdue").length;

  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
        <div>
          <CardTitle className="text-base">Sales management prompts</CardTitle>
          <p className="text-xs text-muted-foreground">
            {total.toLocaleString("en-KE")} due prompt{total === 1 ? "" : "s"}
            {overdue > 0 ? `, ${overdue.toLocaleString("en-KE")} overdue in preview` : ""}
          </p>
        </div>
        <Button asChild size="sm" variant="outline">
          <Link to="/app/sales-management">View all</Link>
        </Button>
      </CardHeader>
      <CardContent>
        {prompts.isLoading ? (
          <SkeletonRows />
        ) : prompts.isError ? (
          <ErrorBlock
            message={prompts.error instanceof Error ? prompts.error.message : "Unable to load prompts."}
            onRetry={() => prompts.refetch()}
          />
        ) : rows.length === 0 ? (
          <div className="rounded border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
            No due sales management prompts for this consultant.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Prompt</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead className="text-right">Due</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((prompt) => (
                  <TableRow key={prompt.id}>
                    <TableCell>
                      <Badge variant="outline" className={promptSeverityClass(prompt.severity)}>
                        {prompt.severity}
                      </Badge>
                      <div className="mt-1 font-medium">{SALES_PROMPT_TYPE_LABEL[prompt.prompt_type]}</div>
                      <div className="max-w-xl text-xs text-muted-foreground">{prompt.reason}</div>
                    </TableCell>
                    <TableCell>
                      <div className="font-medium">{prompt.customer_name ?? prompt.customer_acumatica_id}</div>
                      <div className="font-mono text-[11px] text-muted-foreground">{prompt.customer_acumatica_id}</div>
                    </TableCell>
                    <TableCell className="text-right text-xs">{shortDate(prompt.due_date)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function MetricCard({
  label,
  value,
  loading,
  icon: Icon,
  text = false,
}: {
  label: string;
  value: number | string;
  loading: boolean;
  icon?: typeof Users;
  text?: boolean;
}) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardContent className="flex items-center justify-between p-4">
        <div>
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums">
            {loading ? "..." : text ? value : Number(value).toLocaleString("en-KE")}
          </p>
        </div>
        {Icon && <Icon className="h-5 w-5 text-muted-foreground" />}
      </CardContent>
    </Card>
  );
}

function SkeletonRows() {
  return (
    <div className="space-y-2">
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-full" />
    </div>
  );
}

function ErrorBlock({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="rounded border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
      <p>{message}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-3" onClick={onRetry}>
          Retry
        </Button>
      )}
    </div>
  );
}

function formatMoney(value: number | string | null | undefined) {
  const amount = Number(value ?? 0);
  return `KES ${(Number.isFinite(amount) ? amount : 0).toLocaleString("en-KE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function formatDate(value: string | null | undefined) {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString("en-KE", { timeZone: "Africa/Nairobi" });
}

function formatPercent(value: number | null | undefined) {
  if (value == null || !Number.isFinite(value)) return "-";
  return `${value.toFixed(1)}%`;
}

function formatFrequency(ordersPerMonth: number | null, orderCount: number) {
  if (ordersPerMonth != null) {
    return `${ordersPerMonth.toLocaleString("en-KE", { maximumFractionDigits: 1 })}/mo`;
  }
  if (orderCount > 0) {
    return `${orderCount.toLocaleString("en-KE")} total`;
  }
  return "-";
}

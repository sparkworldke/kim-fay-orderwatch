import { Link, createFileRoute, useParams } from "@tanstack/react-router";
import { CustomerLink } from "@/components/entity-links";
import {
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  ArrowUpDown,
  BriefcaseBusiness,
  Calendar,
  DollarSign,
  UserCheck,
  Users,
} from "lucide-react";
import { useMemo, useState } from "react";
import {
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
  type ColumnDef,
  type SortingState,
} from "@tanstack/react-table";
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
  type ConsultantCustomerRow,
} from "@/hooks/useSalesConsultants";
import { ApiError } from "@/lib/api";

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
  const consultantId = Number(id);
  const [dateFrom, setDateFrom] = useState(startOfMonth);
  const [dateTo, setDateTo] = useState(today);
  const [sorting, setSorting] = useState<SortingState>([{ id: "total_order_value", desc: true }]);

  const filters = useMemo(
    () => ({
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }),
    [dateFrom, dateTo],
  );

  const detail = useConsultantDetail(consultantId, filters);
  const customers = useConsultantCustomers(consultantId, filters);
  const consultant = detail.data?.consultant;
  const summary = detail.data?.summary ?? customers.data?.summary;
  const rows = customers.data?.customers ?? [];

  const columns = useMemo<ColumnDef<ConsultantCustomerRow>[]>(
    () => [
      {
        id: "customer_name",
        accessorFn: (row) => row.customer_name ?? row.customer_id,
        header: "Customer",
        cell: ({ row }) => (
          <div>
            <CustomerLink
              customerId={row.original.customer_id}
              className="font-medium"
            >
              {row.original.customer_name ?? "-"}
            </CustomerLink>
            <div className="text-xs text-muted-foreground">
              {row.original.customer_id}
              {row.original.customer_class ? ` · ${row.original.customer_class}` : ""}
            </div>
          </div>
        ),
      },
      {
        accessorKey: "order_count",
        header: "Orders",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{Number(getValue()).toLocaleString("en-KE")}</span>
        ),
      },
      {
        accessorKey: "orders_per_month",
        header: "Frequency",
        cell: ({ row }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatFrequency(row.original.orders_per_month, row.original.order_count)}
          </span>
        ),
        sortingFn: (a, b) =>
          (a.original.orders_per_month ?? a.original.order_count) -
          (b.original.orders_per_month ?? b.original.order_count),
      },
      {
        accessorKey: "active_orders",
        header: "Active",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{Number(getValue()).toLocaleString("en-KE")}</span>
        ),
      },
      {
        accessorKey: "completed_orders",
        header: "Completed",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{Number(getValue()).toLocaleString("en-KE")}</span>
        ),
      },
      {
        accessorKey: "total_order_value",
        header: "Sales",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatMoney(Number(getValue()))}</span>
        ),
      },
      {
        accessorKey: "last_order_date",
        header: "Last Order",
        cell: ({ getValue }) => formatDate(getValue() as string | null),
        sortingFn: (a, b) =>
          dateSortValue(a.original.last_order_date) - dateSortValue(b.original.last_order_date),
      },
    ],
    [],
  );

  const table = useReactTable({
    data: rows,
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
  });

  if (!Number.isFinite(consultantId) || consultantId <= 0) {
    return (
      <div className="flex flex-col gap-4 p-6">
        <BackLink />
        <ErrorBlock message="Invalid consultant id." />
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

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Customers &amp; order activity</CardTitle>
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
              No customers found for this consultant in the selected date range.
            </div>
          ) : (
            <Table>
              <TableHeader>
                {table.getHeaderGroups().map((headerGroup) => (
                  <TableRow key={headerGroup.id}>
                    {headerGroup.headers.map((header) => (
                      <TableHead
                        key={header.id}
                        className={header.column.id !== "customer_name" ? "text-right" : undefined}
                      >
                        {header.isPlaceholder ? null : (
                          <button
                            type="button"
                            className={`inline-flex items-center gap-1 ${header.column.id !== "customer_name" ? "ml-auto" : ""}`}
                            onClick={header.column.getToggleSortingHandler()}
                          >
                            {flexRender(header.column.columnDef.header, header.getContext())}
                            {header.column.getIsSorted() === "asc" && <ArrowUp className="h-3 w-3" />}
                            {header.column.getIsSorted() === "desc" && <ArrowDown className="h-3 w-3" />}
                            {!header.column.getIsSorted() && <ArrowUpDown className="h-3 w-3 opacity-40" />}
                          </button>
                        )}
                      </TableHead>
                    ))}
                  </TableRow>
                ))}
              </TableHeader>
              <TableBody>
                {table.getRowModel().rows.map((row) => (
                  <TableRow key={row.original.customer_id}>
                    {row.getVisibleCells().map((cell) => (
                      <TableCell
                        key={cell.id}
                        className={cell.column.id !== "customer_name" ? "text-right" : undefined}
                      >
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
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

function dateSortValue(value: string | null) {
  if (!value) return 0;
  const time = new Date(value).getTime();
  return Number.isNaN(time) ? 0 : time;
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
import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, Download, ExternalLink, Filter } from "lucide-react";
import { useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { CustomerLink, DateWithActions, OrderLink } from "@/components/entity-links";
import { useOrders } from "@/hooks/useOrders";
import { MaskedKES, useMaskedKESFormatter } from "@/components/MaskedCurrency";
import { StatusBadge } from "@/components/status-badge";
import type { AcumaticaSalesOrder } from "@/types/admin";

export const Route = createFileRoute("/app/orders-by-date/$date")({
  head: () => ({ meta: [{ title: "Orders by Date — Kim-Fay OrderWatch" }] }),
  component: OrdersByDatePage,
});

function OrdersByDatePage() {
  const kes = useMaskedKESFormatter();
  const { date } = Route.useParams();
  const [statusFilter, setStatusFilter] = useState<string>("all");
  const [search, setSearch] = useState("");
  const [sortBy, setSortBy] = useState<"latest" | "oldest" | "amount">("latest");

  const nextDay = useMemo(() => {
    const d = new Date(date + "T00:00:00");
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
  }, [date]);

  const ordersQuery = useOrders({
    date_from: date,
    date_to: nextDay,
    per_page: 500,
  });

  const allOrders: AcumaticaSalesOrder[] = ordersQuery.data?.data ?? [];

  const filtered = useMemo(() => {
    let result = allOrders;
    if (statusFilter !== "all") {
      result = result.filter(
        (o) =>
          (o.status ?? "").toLowerCase() === statusFilter ||
          o.match_status === statusFilter,
      );
    }
    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter(
        (o) =>
          o.acumatica_order_nbr?.toLowerCase().includes(q) ||
          o.customer_name?.toLowerCase().includes(q) ||
          o.customer_acumatica_id?.toLowerCase().includes(q) ||
          o.customer_order?.toLowerCase().includes(q),
      );
    }
    result = [...result].sort((a, b) => {
      if (sortBy === "amount") {
        return parseFloat(b.order_total) - parseFloat(a.order_total);
      }
      const dateA = new Date(a.order_date ?? 0).getTime();
      const dateB = new Date(b.order_date ?? 0).getTime();
      return sortBy === "latest" ? dateB - dateA : dateA - dateB;
    });
    return result;
  }, [allOrders, statusFilter, search, sortBy]);

  const totalAmount = useMemo(
    () => filtered.reduce((sum, o) => sum + parseFloat(o.order_total || "0"), 0),
    [filtered],
  );

  const totalOrders = filtered.length;

  function exportCsv() {
    const headers = [
      "Order Nbr",
      "Order Type",
      "Customer ID",
      "Customer Name",
      "Customer PO",
      "Status",
      "Match Status",
      "Order Date",
      "Amount",
    ];
    const rows = filtered.map((o) => [
      o.acumatica_order_nbr,
      o.order_type,
      o.customer_acumatica_id ?? "",
      o.customer_name ?? "",
      o.customer_order ?? "",
      o.status ?? "",
      o.match_status,
      o.order_date ?? "",
      o.order_total,
    ]);
    const csv = [headers, ...rows]
      .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(","))
      .join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `orders-${date}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  const formattedDate = useMemo(() => {
    const d = new Date(date + "T00:00:00");
    return d.toLocaleDateString("en-KE", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }, [date]);

  return (
    <div className="flex flex-col gap-6 p-6">
      {/* Back link */}
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2 mb-2">
          <Link to="/app/orders" search={{ status: undefined, order_type: undefined, date_from: undefined, date_to: undefined }}>
            <ArrowLeft className="mr-1 h-4 w-4" />
            Back to Orders
          </Link>
        </Button>
        <h1 className="text-xl font-semibold">Orders for {formattedDate}</h1>
        <p className="text-sm text-muted-foreground">
          All sales orders created on this date
        </p>
      </div>

      {/* Summary metrics */}
      <div className="grid gap-4 sm:grid-cols-3">
        <Card className="rounded-lg shadow-sm">
          <CardContent className="p-4">
            <div className="text-sm text-muted-foreground">Total Orders</div>
            <div className="mt-1 text-2xl font-bold">
              {ordersQuery.isLoading ? (
                <Skeleton className="h-8 w-16" />
              ) : (
                totalOrders
              )}
            </div>
          </CardContent>
        </Card>
        <Card className="rounded-lg shadow-sm">
          <CardContent className="p-4">
            <div className="text-sm text-muted-foreground">Total Value</div>
            <div className="mt-1 text-2xl font-bold">
              {ordersQuery.isLoading ? (
                <Skeleton className="h-8 w-24" />
              ) : (
                kes(totalAmount)
              )}
            </div>
          </CardContent>
        </Card>
        <Card className="rounded-lg shadow-sm">
          <CardContent className="p-4">
            <div className="text-sm text-muted-foreground">Average Order</div>
            <div className="mt-1 text-2xl font-bold">
              {ordersQuery.isLoading ? (
                <Skeleton className="h-8 w-24" />
              ) : totalOrders > 0 ? (
                kes(totalAmount / totalOrders)
              ) : (
                "—"
              )}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Filters */}
      <Card className="rounded-lg shadow-sm">
        <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Filter className="h-4 w-4" />
            Filter & Export
          </CardTitle>
          <Button variant="outline" size="sm" onClick={exportCsv} disabled={filtered.length === 0}>
            <Download className="mr-1 h-4 w-4" />
            Export CSV
          </Button>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-3">
          <div className="grid gap-1.5">
            <label className="text-xs font-medium text-muted-foreground">Search</label>
            <Input
              placeholder="Order #, customer, PO..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <div className="grid gap-1.5">
            <label className="text-xs font-medium text-muted-foreground">Status</label>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger>
                <SelectValue placeholder="All statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All statuses</SelectItem>
                <SelectItem value="open">Open</SelectItem>
                <SelectItem value="completed">Completed</SelectItem>
                <SelectItem value="pending">Pending</SelectItem>
                <SelectItem value="matched">Matched</SelectItem>
                <SelectItem value="unmatched">Unmatched</SelectItem>
                <SelectItem value="needs_review">Needs Review</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-1.5">
            <label className="text-xs font-medium text-muted-foreground">Sort By</label>
            <Select value={sortBy} onValueChange={(v) => setSortBy(v as "latest" | "oldest" | "amount")}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="latest">Latest First</SelectItem>
                <SelectItem value="oldest">Oldest First</SelectItem>
                <SelectItem value="amount">Highest Amount</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Orders table */}
      <Card className="rounded-lg shadow-sm">
        <CardContent className="p-0">
          {ordersQuery.isLoading ? (
            <div className="space-y-2 p-4">
              {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          ) : ordersQuery.isError ? (
            <div className="p-6 text-center text-sm text-destructive">
              Failed to load orders. Please try again.
            </div>
          ) : filtered.length === 0 ? (
            <div className="p-8 text-center">
              <p className="text-sm text-muted-foreground">
                No sales orders found for {formattedDate}.
              </p>
              <p className="mt-1 text-xs text-muted-foreground">
                This date may have no orders, or they may not have been synced yet.
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/40">
                    <TableHead className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      Order #
                    </TableHead>
                    <TableHead className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      Customer
                    </TableHead>
                    <TableHead className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      Customer PO
                    </TableHead>
                    <TableHead className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      Status
                    </TableHead>
                    <TableHead className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      Order Date
                    </TableHead>
                    <TableHead className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      Amount
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filtered.map((order) => (
                    <TableRow key={order.id} className="hover:bg-muted/20">
                      <TableCell className="px-4 py-3">
                        <OrderLink
                          customerId={order.customer_acumatica_id}
                          orderId={order.acumatica_order_nbr}
                        />
                        <span className="ml-1 font-mono text-[10px] text-muted-foreground">
                          {order.order_type}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <CustomerLink
                          customerId={order.customer_acumatica_id}
                          customerName={order.customer_name}
                          showId
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 font-mono text-xs text-muted-foreground">
                        {order.customer_order ?? "—"}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        {order.status ? (
                          <StatusBadge status={order.status} />
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-xs text-muted-foreground">
                        <DateWithActions value={order.order_date} format="datetime" emptyText="—" />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right font-medium tabular-nums">
                        {kes(parseFloat(order.order_total || "0"))}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Navigation to full orders page with this date */}
      <div className="flex justify-end">
        <Button asChild variant="outline" size="sm">
          <Link
            to="/app/orders"
            search={{ date_from: date, date_to: nextDay } as never}
          >
            <ExternalLink className="mr-1 h-4 w-4" />
            View in full Orders page
          </Link>
        </Button>
      </div>
    </div>
  );
}

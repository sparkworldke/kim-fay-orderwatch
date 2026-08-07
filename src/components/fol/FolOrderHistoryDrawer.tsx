import { useEffect, useMemo, useState } from "react";
import { Filter, Search, X } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from "@/components/ui/sheet";
import { useOrders } from "@/hooks/useOrders";

const PER_PAGE = 10;
const LOOKBACK_MONTHS = 3;

function threeMonthsAgoIso(): string {
  const d = new Date();
  d.setMonth(d.getMonth() - LOOKBACK_MONTHS);
  return d.toISOString().slice(0, 10);
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

export function FolOrderHistoryDrawer({
  customerId,
  open,
  onOpenChange,
}: {
  customerId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const [searchInput, setSearchInput] = useState("");
  const [q, setQ] = useState("");
  const [status, setStatus] = useState<string>("");
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [page, setPage] = useState(1);

  // Debounce search input into the query param.
  useEffect(() => {
    const t = setTimeout(() => setQ(searchInput.trim()), 300);
    return () => clearTimeout(t);
  }, [searchInput]);

  useEffect(() => {
    setPage(1);
  }, [q, status]);

  const dateFrom = useMemo(() => threeMonthsAgoIso(), []);
  const dateTo = useMemo(() => todayIso(), []);

  const orders = useOrders({
    customer_id: customerId,
    date_from: dateFrom,
    date_to: dateTo,
    q: q || undefined,
    status: status || undefined,
    page,
    per_page: PER_PAGE,
    sort: "latest",
  });

  const rows = orders.data?.data ?? [];

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex w-full flex-col gap-4 sm:max-w-xl">
        <SheetHeader>
          <SheetTitle>Order History</SheetTitle>
          <SheetDescription>Last {LOOKBACK_MONTHS} months</SheetDescription>
        </SheetHeader>

        <div className="flex items-center gap-2">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search orders…"
              className="pl-8"
            />
          </div>
          <Button
            type="button"
            variant={filtersOpen || status ? "secondary" : "outline"}
            size="sm"
            onClick={() => setFiltersOpen((v) => !v)}
          >
            <Filter className="mr-1.5 h-3.5 w-3.5" /> Filters
          </Button>
        </div>

        {filtersOpen && (
          <div className="flex items-center gap-2 rounded-md border bg-muted/30 p-2">
            <Select value={status || "__any__"} onValueChange={(v) => setStatus(v === "__any__" ? "" : v)}>
              <SelectTrigger className="h-8 w-full">
                <SelectValue placeholder="Any status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__any__">Any status</SelectItem>
                <SelectItem value="Open">Open</SelectItem>
                <SelectItem value="Shipped">Shipped</SelectItem>
                <SelectItem value="Completed">Completed</SelectItem>
                <SelectItem value="Cancelled">Cancelled</SelectItem>
              </SelectContent>
            </Select>
            {status && (
              <Button type="button" variant="ghost" size="icon" className="h-8 w-8 shrink-0" onClick={() => setStatus("")} aria-label="Clear status filter">
                <X className="h-4 w-4" />
              </Button>
            )}
          </div>
        )}

        <div className="flex-1 overflow-auto rounded-md border">
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-muted/60 backdrop-blur">
              <tr>
                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">Order No.</th>
                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">Date</th>
                <th className="px-3 py-2 text-right text-[11px] font-semibold uppercase text-muted-foreground">Amount</th>
                <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {orders.isLoading && (
                <tr>
                  <td colSpan={4} className="px-3 py-8 text-center text-xs text-muted-foreground">
                    Loading order history…
                  </td>
                </tr>
              )}
              {!orders.isLoading && rows.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-3 py-8 text-center text-xs text-muted-foreground">
                    No orders found for this customer in the last {LOOKBACK_MONTHS} months.
                  </td>
                </tr>
              )}
              {rows.map((order) => (
                <tr key={order.id} className="hover:bg-muted/20">
                  <td className="px-3 py-2 font-mono text-xs font-semibold">{order.acumatica_order_nbr}</td>
                  <td className="px-3 py-2 text-xs text-muted-foreground">{order.order_date ?? "—"}</td>
                  <td className="px-3 py-2 text-right text-xs tabular-nums font-medium">
                    KES {Number(order.order_total ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}
                  </td>
                  <td className="px-3 py-2">
                    <Badge variant="outline" className="text-[10px] capitalize">
                      {order.status || "—"}
                    </Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {orders.data && orders.data.total > 0 && (
          <PaginationControls
            currentPage={orders.data.current_page}
            lastPage={orders.data.last_page}
            total={orders.data.total}
            perPage={orders.data.per_page}
            onPageChange={setPage}
            onPerPageChange={() => {}}
            pageSizes={[PER_PAGE]}
          />
        )}
      </SheetContent>
    </Sheet>
  );
}

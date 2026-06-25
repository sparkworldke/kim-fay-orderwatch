import { createFileRoute } from "@tanstack/react-router";
import { FileText, RefreshCw } from "lucide-react";
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
import { useOrders } from "@/hooks/useOrders";
import { useSyncCreditNotesAndMore } from "@/hooks/useOperations";

export const Route = createFileRoute("/app/credit-notes-more")({
  head: () => ({ meta: [{ title: "Credit Notes & More — Kim-Fay OrderWatch" }] }),
  component: CreditNotesMorePage,
});

const ORDER_TYPES = [
  { value: "QT", label: "Quote (QT)" },
  { value: "RC", label: "Return / Credit Note (RC)" },
  { value: "CM", label: "Credit Memo (CM)" },
  { value: "PL", label: "Pick List (PL)" },
];

const ORDER_STATUSES = ["Open", "Completed", "Cancelled", "Back Order", "Credit Hold", "On Hold", "Rejected", "Shipping", "Pending Approval"];

function today() { return new Date().toISOString().slice(0, 10); }
function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function typeBadgeVariant(type: string): "default" | "secondary" | "outline" | "destructive" {
  switch (type) {
    case "RC":
    case "CM":
      return "destructive";
    case "QT":
      return "secondary";
    default:
      return "outline";
  }
}

function CreditNotesMorePage() {
  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [status, setStatus] = useState("all");
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);

  const handleQ = (v: string) => {
    setQ(v);
    clearTimeout((handleQ as { _t?: ReturnType<typeof setTimeout> })._t);
    (handleQ as { _t?: ReturnType<typeof setTimeout> })._t = setTimeout(() => {
      setDebouncedQ(v);
      setPage(1);
    }, 400);
  };

  const { data, isLoading, refetch } = useOrders({
    q: debouncedQ || undefined,
    status: status !== "all" ? status : undefined,
    order_type: "CREDIT_NOTES_MORE",
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    page,
    per_page: perPage,
  });

  const sync = useSyncCreditNotesAndMore();

  function handleSync() {
    sync.mutate(
      { date_from: dateFrom, date_to: dateTo },
      {
        onSuccess: (res) => {
          toast.success(`Sync ${res.sync_run.status}: ${res.sync_run.success_count} documents imported`);
          refetch();
        },
        onError: (e: Error) => toast.error(e.message),
      },
    );
  }

  const items = data?.data ?? [];

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
            <FileText className="h-6 w-6 text-muted-foreground" />
            Credit Notes &amp; More
          </h1>
          <p className="text-sm text-muted-foreground">
            Non-sales-order documents from Acumatica — QT, RC, CM, and PL. Dashboard and Orders show SO only.
          </p>
        </div>
        <Button onClick={handleSync} disabled={sync.isPending}>
          <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
          Sync from Acumatica
        </Button>
      </div>

      <div className="flex flex-wrap gap-2">
        {ORDER_TYPES.map((t) => (
          <Badge key={t.value} variant="outline">{t.label}</Badge>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="grid gap-1.5">
          <Label className="text-xs">Search</Label>
          <Input
            value={q}
            onChange={(e) => handleQ(e.target.value)}
            placeholder="Order #, customer…"
            className="h-9 w-48 text-sm"
          />
        </div>
        <div className="grid gap-1.5">
          <Label className="text-xs">From</Label>
          <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-9 w-36 text-sm" />
        </div>
        <div className="grid gap-1.5">
          <Label className="text-xs">To</Label>
          <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-9 w-36 text-sm" />
        </div>
        <Select value={status} onValueChange={(v) => { setStatus(v); setPage(1); }}>
          <SelectTrigger className="h-9 w-[150px] text-sm"><SelectValue placeholder="Status" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {ORDER_STATUSES.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
          </SelectContent>
        </Select>
      </div>

      {isLoading && <Skeleton className="h-64" />}

      {!isLoading && items.length === 0 && (
        <p className="text-sm text-muted-foreground">No documents found. Run a sync for the selected date range.</p>
      )}

      {!isLoading && items.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                <th className="px-3 py-2 font-medium">Order #</th>
                <th className="px-3 py-2 font-medium">Type</th>
                <th className="px-3 py-2 font-medium">Customer</th>
                <th className="px-3 py-2 font-medium">Status</th>
                <th className="px-3 py-2 font-medium">Date</th>
                <th className="px-3 py-2 font-medium text-right">Total</th>
                <th className="px-3 py-2 font-medium text-right">Lines</th>
              </tr>
            </thead>
            <tbody>
              {items.map((order) => (
                <tr key={order.id} className="border-b last:border-0">
                  <td className="px-3 py-2 font-mono text-xs">{order.acumatica_order_nbr}</td>
                  <td className="px-3 py-2">
                    <Badge variant={typeBadgeVariant(order.order_type)}>{order.order_type}</Badge>
                  </td>
                  <td className="px-3 py-2">{order.customer_name ?? order.customer_acumatica_id ?? "—"}</td>
                  <td className="px-3 py-2">{order.status ?? "—"}</td>
                  <td className="px-3 py-2 text-muted-foreground">
                    {order.order_date ? new Date(order.order_date).toLocaleDateString("en-KE") : "—"}
                  </td>
                  <td className="px-3 py-2 text-right font-mono text-xs">
                    {order.order_total != null ? `${order.currency_id ?? "KES"} ${order.order_total}` : "—"}
                  </td>
                  <td className="px-3 py-2 text-right">{order.lines_count ?? 0}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && (
        <PaginationControls
          page={page}
          perPage={perPage}
          total={data.total}
          lastPage={data.last_page}
          onPageChange={setPage}
          onPerPageChange={(v) => { setPerPage(v); setPage(1); }}
        />
      )}
    </div>
  );
}
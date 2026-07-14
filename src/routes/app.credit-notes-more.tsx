import { createFileRoute } from "@tanstack/react-router";
import { CustomerLink, OrderLink } from "@/components/entity-links";
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
import { useOrderStats, useOrders } from "@/hooks/useOrders";
import { useSyncCreditNotesAndMore } from "@/hooks/useOperations";
import { DATE_PRESETS, type DatePresetId, resolveDatePreset } from "@/lib/date-presets";

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

function DocTypeCard({
  label,
  value,
  active,
  loading,
  onClick,
}: {
  label: string;
  value: number;
  active: boolean;
  loading: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`rounded-md border p-3 text-left transition-colors ${
        active ? "border-primary bg-primary/5" : "bg-card hover:bg-muted/40"
      }`}
    >
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-1 text-2xl font-semibold tabular-nums">
        {loading ? "..." : value.toLocaleString()}
      </div>
    </button>
  );
}

function CreditNotesMorePage() {
  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [documentType, setDocumentType] = useState("all");
  const [status, setStatus] = useState("all");
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [datePreset, setDatePreset] = useState<DatePresetId>("this_month");
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
    document_type: documentType !== "all" ? documentType : undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    page,
    per_page: perPage,
  });
  const stats = useOrderStats({
    q: debouncedQ || undefined,
    status: status !== "all" ? status : undefined,
    order_type: "CREDIT_NOTES_MORE",
    document_type: documentType !== "all" ? documentType : undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  });
  const typeStats = useOrderStats({
    q: debouncedQ || undefined,
    status: status !== "all" ? status : undefined,
    order_type: "CREDIT_NOTES_MORE",
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
  });

  const sync = useSyncCreditNotesAndMore();

  function handleSync() {
    if (!dateFrom || !dateTo) {
      toast.error("Set a date range before syncing.");
      return;
    }
    if (dateFrom > dateTo) {
      toast.error("Start date must be before end date.");
      return;
    }

    sync.mutate(
      {
        date_from: dateFrom,
        date_to: dateTo,
        ...(documentType !== "all" ? { document_type: documentType } : {}),
      },
      {
        onSuccess: (res) => {
          toast.success(`Sync ${res.sync_run.status}: ${res.sync_run.success_count} documents imported`);
          refetch();
          stats.refetch();
          typeStats.refetch();
        },
        onError: (e: Error) => toast.error(e.message),
      },
    );
  }

  function applyDatePreset(preset: DatePresetId) {
    setDatePreset(preset);
    if (preset !== "custom") {
      const range = resolveDatePreset(preset);
      setDateFrom(range.from);
      setDateTo(range.to);
      setPage(1);
    }
  }

  const items = data?.data ?? [];
  const byType = typeStats.data?.by_type ?? {};
  const total = typeStats.data?.total ?? 0;

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

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <DocTypeCard
          label="All documents"
          value={total}
          active={documentType === "all"}
          loading={typeStats.isLoading}
          onClick={() => { setDocumentType("all"); setPage(1); }}
        />
        {ORDER_TYPES.map((t) => (
          <DocTypeCard
            key={t.value}
            label={t.label}
            value={byType[t.value] ?? 0}
            active={documentType === t.value}
            loading={typeStats.isLoading}
            onClick={() => { setDocumentType(t.value); setPage(1); }}
          />
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
          <Label className="text-xs">Document type</Label>
          <Select value={documentType} onValueChange={(v) => { setDocumentType(v); setPage(1); }}>
            <SelectTrigger className="h-9 w-[190px] text-sm"><SelectValue placeholder="Document type" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All document types</SelectItem>
              {ORDER_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        <div className="grid gap-1.5">
          <Label className="text-xs">Dates</Label>
          <Select value={datePreset} onValueChange={(v) => applyDatePreset(v as DatePresetId)}>
            <SelectTrigger className="h-9 w-[150px] text-sm"><SelectValue placeholder="Dates" /></SelectTrigger>
            <SelectContent>
              {DATE_PRESETS.filter((preset) => preset.id !== "last_30_days").map((preset) => (
                <SelectItem key={preset.id} value={preset.id}>{preset.id === "custom" ? "Date range" : preset.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="grid gap-1.5">
          <Label className="text-xs">From</Label>
          <Input type="date" value={dateFrom} onChange={(e) => { setDatePreset("custom"); setDateFrom(e.target.value); setPage(1); }} className="h-9 w-36 text-sm" />
        </div>
        <div className="grid gap-1.5">
          <Label className="text-xs">To</Label>
          <Input type="date" value={dateTo} onChange={(e) => { setDatePreset("custom"); setDateTo(e.target.value); setPage(1); }} className="h-9 w-36 text-sm" />
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
                  <td className="px-3 py-2 font-mono text-xs">
                    <OrderLink
                      customerId={order.customer_acumatica_id}
                      orderId={order.acumatica_order_nbr}
                    />
                  </td>
                  <td className="px-3 py-2">
                    <Badge variant={typeBadgeVariant(order.order_type)}>{order.order_type}</Badge>
                  </td>
                  <td className="px-3 py-2">
                    <CustomerLink
                      customerId={order.customer_acumatica_id}
                      customerName={order.customer_name}
                      showId
                    />
                  </td>
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
          currentPage={page}
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

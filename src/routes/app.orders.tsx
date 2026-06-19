import { createFileRoute } from "@tanstack/react-router";
import type { ColumnDef } from "@tanstack/react-table";
import { AlertTriangle, Download, Filter, MailX } from "lucide-react";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { DataTable } from "@/components/data-table";
import { StatusBadge, SlaBadge, PriorityBadge, ApprovalBadge } from "@/components/status-badge";
import { ORDERS, type Order } from "@/lib/demo-data";
import { formatDateTime, formatDuration, formatKES, formatRelative } from "@/lib/format";

export const Route = createFileRoute("/app/orders")({
  head: () => ({ meta: [{ title: "Orders — Kim-Fay OrderWatch" }] }),
  component: OrdersPage,
});

function OrdersPage() {
  const [customer, setCustomer] = useState<string>("all");
  const [status, setStatus] = useState<string>("all");
  const [selected, setSelected] = useState<Order | null>(null);

  const data = useMemo(
    () =>
      ORDERS.filter((o) => (customer === "all" || o.customer === customer) && (status === "all" || o.status === status)),
    [customer, status],
  );

  const customers = Array.from(new Set(ORDERS.map((o) => o.customer)));

  const columns = useMemo<ColumnDef<Order>[]>(
    () => [
      { accessorKey: "status", header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
      {
        id: "flags",
        header: "Flags",
        cell: ({ row }) => (
          <TooltipProvider delayDuration={150}>
            <div className="flex items-center gap-1">
              {row.original.missingInAcumatica ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex items-center gap-0.5 rounded border border-destructive/30 bg-destructive/10 px-1.5 py-0.5 text-[10px] font-medium text-destructive">
                      <AlertTriangle className="h-3 w-3" /> Acumatica
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>Not found in Acumatica — no matching Sales Order</TooltipContent>
                </Tooltip>
              ) : null}
              {row.original.missingEmail ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex items-center gap-0.5 rounded border border-warning/40 bg-warning/15 px-1.5 py-0.5 text-[10px] font-medium text-warning-foreground">
                      <MailX className="h-3 w-3" /> Email
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>Missing valid contact email on the source record</TooltipContent>
                </Tooltip>
              ) : null}
              {!row.original.missingInAcumatica && !row.original.missingEmail ? (
                <span className="text-xs text-muted-foreground">—</span>
              ) : null}
            </div>
          </TooltipProvider>
        ),
      },
      { accessorKey: "poNumber", header: "PO Number", cell: ({ row }) => <span className="font-mono text-xs">{row.original.poNumber}</span> },
      { accessorKey: "customer", header: "Customer" },
      { accessorKey: "emailSubject", header: "Email Subject", cell: ({ row }) => <span className="block max-w-[240px] truncate text-muted-foreground">{row.original.emailSubject}</span> },
      { accessorKey: "emailReceived", header: "Received", cell: ({ row }) => <span className="text-xs text-muted-foreground">{formatDateTime(row.original.emailReceived)}</span> },
      { accessorKey: "salesOrderNumber", header: "SO #", cell: ({ row }) => row.original.salesOrderNumber ? <span className="font-mono text-xs">{row.original.salesOrderNumber}</span> : <span className="text-xs text-muted-foreground">—</span> },
      {
        id: "emailToKeyIn",
        header: "Email → Key-in",
        cell: ({ row }) => (
          <TooltipProvider delayDuration={150}>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="font-mono text-xs tabular-nums text-muted-foreground">
                  {row.original.keyedInAt ? formatDuration(row.original.emailReceived, row.original.keyedInAt) : "—"}
                </span>
              </TooltipTrigger>
              <TooltipContent>Time between email received and manual key-in into Acumatica</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        ),
      },
      { accessorKey: "orderValue", header: "Value", cell: ({ row }) => <span className="font-mono tabular-nums">{formatKES(row.original.orderValue, { compact: true })}</span> },
      { accessorKey: "approvalStatus", header: "Approval", cell: ({ row }) => <ApprovalBadge status={row.original.approvalStatus} /> },
      {
        id: "keyInToApproval",
        header: "Key-in → Approval",
        cell: ({ row }) => (
          <TooltipProvider delayDuration={150}>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="font-mono text-xs tabular-nums text-muted-foreground">
                  {row.original.approvedAt && row.original.keyedInAt
                    ? formatDuration(row.original.keyedInAt, row.original.approvedAt)
                    : "—"}
                </span>
              </TooltipTrigger>
              <TooltipContent>Time from initial key-in to final approval</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        ),
      },
      { accessorKey: "slaStatus", header: "SLA", cell: ({ row }) => <SlaBadge status={row.original.slaStatus} /> },
      { accessorKey: "priority", header: "Priority", cell: ({ row }) => <PriorityBadge priority={row.original.priority} /> },
      { accessorKey: "assignedTo", header: "Assigned", cell: ({ row }) => row.original.assignedTo ?? <span className="text-xs text-muted-foreground">Unassigned</span> },
      { accessorKey: "lastUpdated", header: "Updated", cell: ({ row }) => <span className="text-xs text-muted-foreground">{formatRelative(row.original.lastUpdated)}</span> },
    ],
    [],
  );

  function exportCsv() {
    const headers = ["Status", "PO", "Customer", "Subject", "Received", "SO", "MissingInAcumatica", "MissingEmail", "EmailToKeyIn", "Value", "Approval", "KeyInToApproval", "SLA", "Priority", "Assigned"];
    const rows = data.map((o) => [
      o.status, o.poNumber, o.customer, `"${o.emailSubject}"`, o.emailReceived, o.salesOrderNumber ?? "",
      o.missingInAcumatica ? "Y" : "N", o.missingEmail ? "Y" : "N", formatDuration(o.emailReceived, o.keyedInAt),
      o.orderValue, o.approvalStatus, formatDuration(o.keyedInAt, o.approvedAt), o.slaStatus, o.priority, o.assignedTo ?? "",
    ]);
    const csv = [headers.join(","), ...rows.map((r) => r.join(","))].join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `kim-fay-orders-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success(`Exported ${data.length} orders`);
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Orders</h1>
          <p className="text-sm text-muted-foreground">Master reconciliation across Outlook captures and Acumatica sales orders.</p>
        </div>
      </div>

      <DataTable
        columns={columns}
        data={data}
        searchPlaceholder="Search PO, customer, subject…"
        onRowClick={setSelected}
        toolbar={
          <>
            <Select value={customer} onValueChange={setCustomer}>
              <SelectTrigger className="h-9 w-[160px] text-sm"><SelectValue placeholder="Customer" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All customers</SelectItem>
                {customers.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}
              </SelectContent>
            </Select>
            <Select value={status} onValueChange={setStatus}>
              <SelectTrigger className="h-9 w-[140px] text-sm"><SelectValue placeholder="Status" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All statuses</SelectItem>
                {["Matched", "Missing", "Delayed", "Duplicate", "Escalated"].map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
              </SelectContent>
            </Select>
            <Button variant="outline" size="sm" className="h-9" onClick={() => toast("Saved view created")}>
              <Filter className="mr-1 h-3.5 w-3.5" /> Save view
            </Button>
            <Button variant="outline" size="sm" className="h-9" onClick={exportCsv}>
              <Download className="mr-1 h-3.5 w-3.5" /> Export
            </Button>
          </>
        }
      />

      <Sheet open={!!selected} onOpenChange={(o) => !o && setSelected(null)}>
        <SheetContent className="w-full sm:max-w-lg overflow-y-auto">
          {selected && (
            <>
              <SheetHeader>
                <SheetTitle className="flex items-center gap-2">
                  <span className="font-mono text-base">{selected.poNumber}</span>
                  <StatusBadge status={selected.status} />
                </SheetTitle>
                <SheetDescription>{selected.customer} · {selected.branch}</SheetDescription>
              </SheetHeader>
              <div className="mt-6 grid grid-cols-2 gap-3 text-sm">
                {[
                  ["Order Value", formatKES(selected.orderValue)],
                  ["Priority", <PriorityBadge key="p" priority={selected.priority} />],
                  ["SLA", <SlaBadge key="s" status={selected.slaStatus} />],
                  ["Sales Order", selected.salesOrderNumber ?? "—"],
                  ["Salesperson", selected.salesperson],
                  ["Assigned", selected.assignedTo ?? "Unassigned"],
                  ["Received", formatDateTime(selected.emailReceived)],
                  ["Updated", formatRelative(selected.lastUpdated)],
                ].map(([k, v]) => (
                  <div key={k as string} className="rounded-md border bg-muted/30 p-2.5">
                    <div className="text-[10px] uppercase tracking-wide text-muted-foreground">{k}</div>
                    <div className="mt-0.5 font-medium">{v}</div>
                  </div>
                ))}
              </div>
              <div className="mt-6">
                <h4 className="mb-2 text-sm font-semibold">Email Preview</h4>
                <div className="rounded-md border bg-muted/30 p-3 text-sm">
                  <div className="text-xs text-muted-foreground">Subject</div>
                  <div className="font-medium">{selected.emailSubject}</div>
                  <div className="mt-2 text-xs text-muted-foreground">Body excerpt</div>
                  <p className="mt-0.5 text-sm text-foreground/90">
                    Kindly process the following order for {selected.customer} ({selected.branch}). PO Number: <span className="font-mono">{selected.poNumber}</span>. Total estimated value KES {selected.orderValue.toLocaleString()}…
                  </p>
                </div>
              </div>
              <div className="mt-6 flex gap-2">
                <Button size="sm" onClick={() => toast.success("Order reassigned")}>Reassign</Button>
                <Button size="sm" variant="outline" onClick={() => toast.success("Comment added")}>Comment</Button>
                <Button size="sm" variant="outline" onClick={() => toast.success("Escalated")}>Escalate</Button>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}

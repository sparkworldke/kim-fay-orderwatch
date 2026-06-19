import { createFileRoute } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { MoreHorizontal } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ORDERS, type Order } from "@/lib/demo-data";
import { formatKES, formatRelative } from "@/lib/format";
import { PriorityBadge } from "@/components/status-badge";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/discrepancies")({
  head: () => ({ meta: [{ title: "Discrepancies — Kim-Fay OrderWatch" }] }),
  component: DiscrepanciesPage,
});

type Column = "Outstanding" | "Warning" | "Critical" | "Escalated";

function bucketOf(o: Order): Column | null {
  if (o.status === "Matched") return null;
  if (o.status === "Escalated") return "Escalated";
  if (o.priority === "Critical" || o.slaStatus === "Breached") return "Critical";
  if (o.slaStatus === "Warning") return "Warning";
  return "Outstanding";
}

const COLUMN_META: Record<Column, { tone: string; desc: string }> = {
  Outstanding: { tone: "border-muted", desc: "Pending capture" },
  Warning: { tone: "border-warning/50", desc: "Approaching SLA" },
  Critical: { tone: "border-destructive/50", desc: "SLA breached or high value" },
  Escalated: { tone: "border-destructive", desc: "Manager attention" },
};

function DiscrepanciesPage() {
  const [board, setBoard] = useState(() => {
    const cols: Record<Column, Order[]> = { Outstanding: [], Warning: [], Critical: [], Escalated: [] };
    for (const o of ORDERS) {
      const b = bucketOf(o);
      if (b) cols[b].push(o);
    }
    return cols;
  });

  const totals = useMemo(
    () =>
      (Object.entries(board) as [Column, Order[]][]).reduce<Record<Column, number>>(
        (acc, [k, v]) => {
          acc[k] = v.reduce((s, o) => s + o.orderValue, 0);
          return acc;
        },
        { Outstanding: 0, Warning: 0, Critical: 0, Escalated: 0 },
      ),
    [board],
  );

  function moveCard(card: Order, from: Column, to: Column) {
    if (from === to) return;
    setBoard((prev) => ({
      ...prev,
      [from]: prev[from].filter((o) => o.id !== card.id),
      [to]: [{ ...card }, ...prev[to]],
    }));
    toast.success(`${card.poNumber} moved to ${to}`);
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Discrepancies</h1>
        <p className="text-sm text-muted-foreground">Exception management queue. Drag-equivalent actions via the row menu.</p>
      </div>

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        {(Object.keys(board) as Column[]).map((col) => {
          const items = board[col];
          const meta = COLUMN_META[col];
          return (
            <div key={col} className={cn("flex min-h-[400px] flex-col rounded-lg border-t-2 bg-card shadow-[var(--shadow-panel)]", meta.tone)}>
              <div className="flex items-start justify-between border-b px-3 py-2.5">
                <div>
                  <h3 className="text-sm font-semibold">{col}</h3>
                  <p className="text-[11px] text-muted-foreground">{meta.desc}</p>
                </div>
                <div className="text-right">
                  <div className="rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium tabular-nums">{items.length}</div>
                  <div className="mt-0.5 font-mono text-[10px] text-muted-foreground">{formatKES(totals[col], { compact: true })}</div>
                </div>
              </div>
              <div className="flex-1 space-y-2 overflow-y-auto p-2 max-h-[640px]">
                {items.length === 0 && (
                  <div className="rounded-md border border-dashed bg-muted/20 p-6 text-center text-xs text-muted-foreground">
                    Nothing here. Keep it that way.
                  </div>
                )}
                {items.slice(0, 30).map((o) => (
                  <div key={o.id} className="group rounded-md border bg-background p-2.5 shadow-sm">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="truncate text-sm font-medium">{o.customer}</div>
                        <div className="truncate font-mono text-[11px] text-muted-foreground">{o.poNumber}</div>
                      </div>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="h-6 w-6 opacity-60 group-hover:opacity-100">
                            <MoreHorizontal className="h-3.5 w-3.5" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => toast.success("Assigned to current agent")}>Assign to me</DropdownMenuItem>
                          <DropdownMenuItem onClick={() => toast.success("Comment added")}>Add comment</DropdownMenuItem>
                          {(["Outstanding", "Warning", "Critical", "Escalated"] as Column[])
                            .filter((c) => c !== col)
                            .map((c) => (
                              <DropdownMenuItem key={c} onClick={() => moveCard(o, col, c)}>
                                Move to {c}
                              </DropdownMenuItem>
                            ))}
                          <DropdownMenuItem onClick={() => moveCard(o, col, "Escalated")} className="text-destructive focus:text-destructive">
                            Escalate
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                    <div className="mt-1.5 flex items-center justify-between">
                      <span className="font-mono text-xs tabular-nums">{formatKES(o.orderValue, { compact: true })}</span>
                      <PriorityBadge priority={o.priority} />
                    </div>
                    <div className="mt-1.5 flex items-center justify-between text-[10px] text-muted-foreground">
                      <span>{o.assignedTo ?? "Unassigned"}</span>
                      <span>{formatRelative(o.emailReceived)}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

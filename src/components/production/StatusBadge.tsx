import { cn } from "@/lib/utils";
import type { StockStatus } from "@/types/Stock/inventory";
import type { TrendStatus } from "@/types/Stock/sales";
import { STATUS_LABEL, STATUS_LEGEND } from "@/utils/Stock/status";

/**
 * Solid chips so labels stay readable on white rows and on navy hover/selection
 * (DataTable forces white text on nested nodes — badges use !important colors).
 */
const STATUS_CLASS: Record<StockStatus, string> = {
  critical:
    "status-badge !bg-red-600 !text-white border border-red-500/80 shadow-sm",
  "at-risk":
    "status-badge !bg-amber-500 !text-white border border-amber-400/80 shadow-sm",
  healthy:
    "status-badge !bg-emerald-600 !text-white border border-emerald-500/80 shadow-sm",
};

const DOT_CLASS: Record<StockStatus, string> = {
  critical: "bg-red-600",
  "at-risk": "bg-amber-500",
  healthy: "bg-emerald-600",
};

export function StatusBadge({ status, className }: { status: StockStatus; className?: string }) {
  return (
    <span
      className={cn(
        "inline-flex items-center justify-center rounded-md px-2 py-0.5 text-[10px] font-bold tracking-wide whitespace-nowrap uppercase",
        STATUS_CLASS[status],
        className,
      )}
    >
      {STATUS_LABEL[status]}
    </span>
  );
}

const TREND_CLASS: Record<TrendStatus, string> = {
  growing: "status-badge !bg-emerald-600 !text-white border border-emerald-500/80",
  stable: "status-badge !bg-sky-600 !text-white border border-sky-500/80",
  declining: "status-badge !bg-red-600 !text-white border border-red-500/80",
};

const TREND_LABEL: Record<TrendStatus, string> = {
  growing: "Growing",
  stable: "Stable",
  declining: "Declining",
};

export function TrendBadge({ status }: { status: TrendStatus }) {
  return (
    <span
      className={cn(
        "inline-flex items-center justify-center rounded-md px-2 py-0.5 text-[10px] font-bold tracking-wide whitespace-nowrap uppercase",
        TREND_CLASS[status],
      )}
    >
      {TREND_LABEL[status]}
    </span>
  );
}

export function StatusLegend() {
  return (
    <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground">
      {STATUS_LEGEND.map((entry) => (
        <span key={entry.status} className="inline-flex items-center gap-1.5">
          <span className={cn("size-2 rounded-full", DOT_CLASS[entry.status])} />
          {entry.text}
        </span>
      ))}
    </div>
  );
}

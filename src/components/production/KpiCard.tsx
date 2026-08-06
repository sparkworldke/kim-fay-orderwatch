import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

export type KpiTone = "primary" | "critical" | "warning" | "success" | "neutral";

const TONE: Record<KpiTone, { icon: string; value: string }> = {
  primary: { icon: "bg-brand-soft text-primary", value: "text-primary" },
  critical: { icon: "bg-danger-soft text-destructive", value: "text-destructive" },
  warning: { icon: "bg-warning-soft text-warning", value: "text-warning" },
  success: { icon: "bg-success-soft text-success", value: "text-success" },
  neutral: { icon: "bg-secondary text-navy", value: "text-navy" },
};

export interface KpiCardProps {
  label: string;
  value: string;
  caption?: string;
  icon: LucideIcon;
  tone?: KpiTone;
  /** Compact card for single-screen executive layouts. */
  compact?: boolean;
}

function KpiCardSkeleton({ compact }: { compact: boolean }) {
  return (
    <div
      className={cn(
        "flex min-w-0 items-center gap-3 rounded-xl border border-border bg-card shadow-card",
        compact ? "gap-2.5 p-2.5" : "p-3 sm:p-4",
      )}
    >
      <span className={cn("shrink-0 animate-pulse rounded-xl bg-secondary", compact ? "size-9" : "size-10 sm:size-12")} />
      <div className="min-w-0 flex-1 space-y-1.5">
        <div className={cn("animate-pulse rounded bg-secondary", compact ? "h-2.5 w-14" : "h-3 w-20")} />
        <div className={cn("animate-pulse rounded bg-secondary", compact ? "h-4 w-10" : "h-5 w-14")} />
      </div>
    </div>
  );
}

export function KpiCard({ label, value, caption, icon: Icon, tone = "primary", compact = false }: KpiCardProps) {
  const styles = TONE[tone];
  return (
    <div
      className={cn(
        "flex min-w-0 items-center gap-3 rounded-xl border border-border bg-card shadow-card transition-shadow hover:shadow-pop",
        compact ? "gap-2.5 p-2.5" : "p-3 sm:p-4",
      )}
    >
      <span
        className={cn(
          "grid shrink-0 place-items-center rounded-xl",
          compact ? "size-9" : "size-10 sm:size-12",
          styles.icon,
        )}
      >
        <Icon className={compact ? "size-4" : "size-5"} />
      </span>
      <div className="min-w-0">
        <p className={cn("font-semibold leading-tight text-navy", compact ? "text-[11px]" : "text-xs")} title={label}>
          {label}
        </p>
        <p
          className={cn(
            "ui-value font-bold leading-tight break-words tabular-nums",
            compact ? "text-base" : "text-lg sm:text-xl",
            styles.value,
          )}
        >
          {value}
        </p>
        {caption ? <p className="truncate text-[11px] text-muted-foreground">{caption}</p> : null}
      </div>
    </div>
  );
}

export function KpiGrid({
  items,
  compact = false,
  loading = false,
}: {
  items: KpiCardProps[];
  compact?: boolean;
  loading?: boolean;
}) {
  return (
    <div className="grid grid-cols-2 gap-2 md:grid-cols-4 2xl:grid-cols-8">
      {loading
        ? items.map((item, i) => <KpiCardSkeleton key={item.label ?? i} compact={compact} />)
        : items.map((item) => <KpiCard key={item.label} {...item} compact={compact} />)}
    </div>
  );
}

import { ArrowDownRight, ArrowUpRight, Minus } from "lucide-react";
import type { ComponentType } from "react";
import { cn } from "@/lib/utils";

interface KpiCardProps {
  label: string;
  value: string;
  delta?: number;
  deltaSuffix?: string;
  icon?: ComponentType<{ className?: string }>;
  invertDelta?: boolean;
  hint?: string;
  bgClass?: string;
  borderClass?: string;
  textClass?: string;
}

export function KpiCard({ label, value, delta, deltaSuffix = "", icon: Icon, invertDelta, hint, bgClass, borderClass, textClass }: KpiCardProps) {
  const hasDelta = typeof delta === "number";
  const positive = hasDelta && (invertDelta ? delta! < 0 : delta! > 0);
  const negative = hasDelta && (invertDelta ? delta! > 0 : delta! < 0);
  return (
    <div className={cn("rounded-lg border p-4 shadow-[var(--shadow-panel)]", bgClass || "bg-card", borderClass || "border-border")}>
      <div className="flex items-center justify-between gap-2">
        <span className={cn("text-xs font-medium uppercase tracking-wide", textClass || "text-muted-foreground")}>{label}</span>
        {Icon ? <Icon className={cn("h-4 w-4", textClass || "text-muted-foreground")} /> : null}
      </div>
      <div className="mt-2 flex items-baseline gap-2">
        <span className={cn("font-mono text-2xl font-semibold tabular-nums", textClass || "text-foreground")}>{value}</span>
      </div>
      <div className="mt-1 flex items-center gap-1 text-xs">
        {hasDelta ? (
          <span
            className={cn(
              "inline-flex items-center gap-0.5 font-medium",
              positive && "text-success",
              negative && "text-destructive",
              !positive && !negative && (textClass || "text-muted-foreground"),
            )}
          >
            {positive ? <ArrowUpRight className="h-3 w-3" /> : negative ? <ArrowDownRight className="h-3 w-3" /> : <Minus className="h-3 w-3" />}
            {Math.abs(delta!)}
            {deltaSuffix}
          </span>
        ) : null}
        {hint ? <span className={cn("text-xs", textClass || "text-muted-foreground")}>{hint}</span> : null}
      </div>
    </div>
  );
}

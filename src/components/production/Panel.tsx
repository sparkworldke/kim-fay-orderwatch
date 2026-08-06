import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export function Panel({
  title,
  subtitle,
  icon: Icon,
  actions,
  children,
  className,
  bodyClassName,
  fill = false,
  compact = false,
}: {
  title: string;
  subtitle?: string;
  icon?: LucideIcon;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
  bodyClassName?: string;
  /** Fill available height inside a flex/grid cell (desktop). Body scrolls internally. */
  fill?: boolean;
  /** Reduce header + body padding for denser, single-screen layouts. */
  compact?: boolean;
}) {
  return (
    <section
      className={cn(
        "rounded-xl border border-border bg-card shadow-card",
        fill && "lg:flex lg:min-h-0 lg:flex-col",
        className,
      )}
    >
      <header
        className={cn(
          "grid shrink-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-border px-3 sm:px-4",
          compact ? "py-2" : "py-3",
        )}
      >
        <div className="flex min-w-0 items-center gap-2">
          {Icon ? <Icon className="size-4 shrink-0 text-primary" /> : null}
          <div className="min-w-0">
            <h2 className="truncate text-sm font-bold text-navy sm:text-base">{title}</h2>
            {subtitle ? (
              <p className="truncate text-[11px] text-muted-foreground">{subtitle}</p>
            ) : null}
          </div>
        </div>
        {actions ? <div className="flex items-center gap-2">{actions}</div> : <span />}
      </header>
      <div
        className={cn(
          compact ? "p-2.5 sm:p-3" : "p-3 sm:p-4",
          fill && "lg:min-h-0 lg:flex-1 lg:overflow-auto",
          bodyClassName,
        )}
      >
        {children}
      </div>
    </section>
  );
}

export function EmptyState({ title, description }: { title: string; description: string }) {
  return (
    <div className="grid place-items-center gap-1 px-4 py-12 text-center">
      <p className="ui-title text-sm font-semibold text-navy">{title}</p>
      <p className="max-w-sm text-xs text-muted-foreground">{description}</p>
    </div>
  );
}

export function TableSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <div className="space-y-2 p-4">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="h-9 animate-pulse rounded-md bg-secondary" />
      ))}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="grid place-items-center gap-2 px-4 py-12 text-center">
      <p className="ui-title text-sm font-semibold text-destructive">Something went wrong</p>
      <p className="max-w-sm text-xs text-muted-foreground">{message}</p>
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="mt-2 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground"
        >
          Try again
        </button>
      ) : null}
    </div>
  );
}

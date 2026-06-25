import { AlertTriangle, Boxes, Gauge, PackageX } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { useOperationsStatus } from "@/hooks/useOperations";
import { formatDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

function formatSyncAt(iso: string | null | undefined) {
  if (!iso) return "Never";
  return formatDateTime(iso);
}

export function OperationsSyncStatus({ className }: { className?: string }) {
  const { data, isLoading } = useOperationsStatus();

  if (isLoading) {
    return (
      <div className={cn("grid gap-3 sm:grid-cols-3", className)}>
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-16 w-full rounded-lg" />
        ))}
      </div>
    );
  }

  const items = [
    {
      label: "Last inventory sync",
      at: data?.last_inventory_sync_at,
      stale: data?.inventory_stale,
      icon: Boxes,
      sub: data?.last_inventory_sync_type === "inventory_stocks" ? "Stocks only" : undefined,
    },
    {
      label: "Last backorder check",
      at: data?.last_backorder_sync_at,
      stale: data?.backorders_stale,
      icon: PackageX,
    },
    {
      label: "Last fill rate check",
      at: data?.last_fill_rate_sync_at ?? data?.last_fill_rate_computed_at,
      stale: data?.fill_rate_stale,
      icon: Gauge,
      sub: data?.last_fill_rate_sync_at ? "Sync run" : data?.last_fill_rate_computed_at ? "Computed" : undefined,
    },
  ];

  return (
    <div className={cn("grid gap-3 sm:grid-cols-3", className)}>
      {items.map((item) => (
        <div
          key={item.label}
          className={cn(
            "rounded-lg border bg-card px-4 py-3 shadow-sm",
            item.stale && "border-amber-300/60 bg-amber-50/30 dark:bg-amber-950/10",
          )}
        >
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <item.icon className="h-3.5 w-3.5" />
            {item.label}
            {item.stale && (
              <Badge variant="secondary" className="ml-auto text-[10px] px-1.5 py-0">
                <AlertTriangle className="mr-0.5 h-2.5 w-2.5" />
                Stale
              </Badge>
            )}
          </div>
          <p className="mt-1 text-sm font-medium">{formatSyncAt(item.at)}</p>
          {item.sub && <p className="text-[10px] text-muted-foreground">{item.sub}</p>}
        </div>
      ))}
    </div>
  );
}
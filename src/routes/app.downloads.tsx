import { createFileRoute, Link } from "@tanstack/react-router";
import { Download, FileDown, Loader2, RefreshCw, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  downloadExportFile,
  useDeleteExportDownload,
  useExportDownloads,
  type ExportDownload,
} from "@/hooks/useExportDownloads";
import { formatDate } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/downloads")({
  head: () => ({ meta: [{ title: "Downloads — Kim-Fay Sight" }] }),
  component: DownloadsPage,
});

function statusBadge(status: string) {
  switch (status) {
    case "ready":
      return "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200";
    case "queued":
    case "processing":
      return "border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200";
    case "failed":
      return "border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200";
    case "expired":
      return "border-border bg-muted/50 text-muted-foreground";
    default:
      return "bg-muted text-muted-foreground";
  }
}

function formatBytes(n: number | null | undefined) {
  if (n == null || n <= 0) return "—";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function DownloadsPage() {
  const { data, isLoading, isFetching, refetch } = useExportDownloads(true);
  const remove = useDeleteExportDownload();
  const rows = data?.data ?? [];
  const active = rows.some((r) => r.status === "queued" || r.status === "processing");

  async function handleDownload(row: ExportDownload) {
    try {
      await downloadExportFile(row.id, row.filename);
      toast.success("Download started.");
      void refetch();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Unable to download file.");
    }
  }

  function handleDelete(row: ExportDownload) {
    remove.mutate(row.id, {
      onSuccess: () => toast.success("Removed."),
      onError: (e: Error) => toast.error(e.message),
    });
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Downloads</h1>
          <p className="text-sm text-muted-foreground">
            Background Excel exports for large Backorders, Fill Rate, and Inventory reports.
            Files stay available for about 3 days.
          </p>
        </div>
        <Button variant="outline" onClick={() => void refetch()} disabled={isFetching}>
          <RefreshCw className={cn("mr-2 h-4 w-4", isFetching && "animate-spin")} />
          Refresh
        </Button>
      </div>

      {active && (
        <div className="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
          <Loader2 className="mr-2 inline h-4 w-4 animate-spin" />
          An export is running in the background. This list updates every few seconds.
        </div>
      )}

      <div className="rounded-lg border bg-card shadow-sm">
        <div className="border-b px-4 py-3 text-sm font-medium">Your exports</div>
        {isLoading ? (
          <div className="space-y-2 p-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-12 text-center text-sm text-muted-foreground">
            <FileDown className="mx-auto mb-2 h-8 w-8 opacity-40" />
            No background downloads yet.
            <p className="mt-1">
              On Backorders, Fill Rate, or Inventory, choose{" "}
              <span className="font-medium text-foreground">Queue download</span> for large
              ranges.
            </p>
            <div className="mt-4 flex justify-center gap-2">
              <Button asChild variant="outline" size="sm">
                <Link to="/app/backorders">Backorders</Link>
              </Button>
              <Button asChild variant="outline" size="sm">
                <Link to="/app/fill-rate">Fill Rate</Link>
              </Button>
              <Button asChild variant="outline" size="sm">
                <Link to="/app/inventory">Inventory</Link>
              </Button>
            </div>
          </div>
        ) : (
          <div className="divide-y">
            {rows.map((row) => (
              <div
                key={row.id}
                className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="min-w-0 space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{row.type_label}</span>
                    <Badge variant="outline" className={cn("text-[11px] font-normal", statusBadge(row.status))}>
                      {row.status}
                    </Badge>
                    {row.filename && (
                      <span className="truncate text-xs text-muted-foreground">{row.filename}</span>
                    )}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    Queued {formatDate(row.created_at) ?? "—"}
                    {row.finished_at ? ` · Finished ${formatDate(row.finished_at)}` : ""}
                    {row.size_bytes != null ? ` · ${formatBytes(row.size_bytes)}` : ""}
                  </div>
                  {row.error_message && (
                    <p className="text-xs text-red-600 dark:text-red-400">{row.error_message}</p>
                  )}
                  {row.filters && Object.keys(row.filters).length > 0 && (
                    <p className="truncate text-[11px] text-muted-foreground">
                      Filters:{" "}
                      {Object.entries(row.filters)
                        .map(([k, v]) => `${k}=${String(v)}`)
                        .join(" · ")}
                    </p>
                  )}
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                  {row.ready && (
                    <Button size="sm" onClick={() => void handleDownload(row)}>
                      <Download className="mr-1.5 h-4 w-4" />
                      Download
                    </Button>
                  )}
                  {(row.status === "queued" || row.status === "processing") && (
                    <Button size="sm" variant="outline" disabled>
                      <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                      Working…
                    </Button>
                  )}
                  <Button
                    size="sm"
                    variant="ghost"
                    className="text-destructive"
                    onClick={() => handleDelete(row)}
                    disabled={remove.isPending}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

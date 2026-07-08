import { createFileRoute, Link } from "@tanstack/react-router";
import { getToken, useAuth } from "@/lib/auth";
import { canSeeAdminTab } from "@/lib/nav-permissions";
import { Activity, AlertTriangle, ArrowUpRight, Bot, Boxes, ChevronDown, ChevronRight, Clock, Copy, Database, Download, FlaskConical, Gauge, History, KeyRound, Mail, PackageX, Pencil, Play, RefreshCw, RotateCcw, Search, ShieldCheck, Sparkles, Timer, ToggleLeft, Trash2, Upload, UserPlus, Users, X } from "lucide-react";
import { useRef, useEffect, useState, type ChangeEvent, type ComponentType, type FormEvent, type ReactNode } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import {
  useAcumatica,
  useAcumaticaLookup,
  useAcumaticaCustomerSearch,
  useAdminHealth,
  useMailSettings,
  useAiKeys,
  useAuditLogs,
  useDeadLetters,
  useCronJobs,
  useCronRuns,
  useDailyReportConfig,
  useDailyReportRuns,
  useDiagnoseSyncHealth,
  useResendDailyReport,
  useTestDailyReport,
  useUpdateDailyReportConfig,
  useDeliverySlaConfig,
  useUpdateDeliverySlaConfig,
  useDeleteAiKey,
  useNotificationRules,
  usePermissions,
  usePreviewCustomer,
  usePreviewOrder,
  useReconciliation,
  useRoles,
  useTeamMembers,
  useCreateTeamMember,
  useUpdateUser,
  useRepCodeHistory,
  useResendWelcomeEmail,
  useToggleUserStatus,
  useDeleteUser,
  useAiPromptLogs,
  useAiPromptLogStats,
  useRunCronJob,
  useSaveAiKey,
  useSyncCustomerOrders,
  useSyncCustomers,
  useSyncLogs,
  useSyncOrders,
  useStopSyncLog,
  useToggleNotificationRule,
  useSendNotificationRulesConfig,
  useUpdateNotificationRuleRecipients,
  useUpdateMailSettings,
  useUpdateAcumatica,
  useUpdateCronJob,
  useUpdateReconciliationStatus,
  useValidateAcumatica,
  useRestoreRepCode,
} from "@/hooks/admin/useAdminSettings";
import { acumaticaSchema, aiKeySchema, type AcumaticaInput, type AiKeyInput } from "@/lib/admin-schemas";
import {
  formatOpsSyncToast,
  useSyncBackorders,
  useSyncFillRate,
  useSyncInventory,
  useSyncInventoryStocks,
} from "@/hooks/useOperations";
import type { AcumaticaCustomerSummary, AcumaticaLookupType, AcumaticaSyncLog, MailSettings, MailSettingsInput, NotificationRule, Role, TeamMember, RepCodeHistoryEntry } from "@/types/admin";
import { DATE_PRESETS, type DatePresetId, resolveDatePreset } from "@/lib/date-presets";
import { useConsultantOptions } from "@/hooks/useSalesConsultants";
import { API_BASE_URL } from "@/lib/api";

export const Route = createFileRoute("/app/administration")({
  head: () => ({ meta: [{ title: "Administration - Kim-Fay OrderWatch" }] }),
  component: AdminPage,
});

const ACTIVE_SYNC_WINDOW_MS = 2 * 60 * 1000;
const INVENTORY_IMPORT_WAREHOUSES = ["DTC", "FGS", "PRMS", "RMS1", "TRMS"] as const;

function isActiveSyncLog(log: AcumaticaSyncLog) {
  if (log.status !== "running" || log.ended_at) {
    return false;
  }

  const pulseAt = log.heartbeat_at ?? log.started_at;
  const pulseMs = pulseAt ? new Date(pulseAt).getTime() : Number.NaN;

  return Number.isFinite(pulseMs) && Date.now() - pulseMs <= ACTIVE_SYNC_WINDOW_MS;
}

function findActiveSyncRun(logs: AcumaticaSyncLog[] | undefined, syncTypes: string[]) {
  return logs?.find((log) => syncTypes.includes(log.sync_type) && isActiveSyncLog(log)) ?? null;
}

const ADMIN_TABS = [
  { value: "acumatica", label: "Acumatica", perm: "acumatica", panel: AcumaticaPanel },
  { value: "sync", label: "Sync Operations", perm: "sync", panel: SyncPanel },
  { value: "data-tools", label: "Data Tools", perm: "data-tools", panel: DataToolsPanel },
  { value: "reconciliation", label: "Reconciliation", perm: "reconciliation", panel: ReconciliationPanel },
  { value: "ai", label: "AI Keys", perm: "ai-keys", panel: AiKeysPanel },
  { value: "team", label: "Team Members", perm: "team", panel: TeamMembersPanel },
  { value: "roles", label: "Roles", perm: "roles", panel: RolesPanel },
  { value: "permissions", label: "Permissions", perm: "permissions", panel: PermissionsPanel },
  { value: "notifications", label: "Notification Rules", perm: "notifications", panel: NotificationRulesPanel },
  { value: "daily-notifications", label: "Daily Notifications", perm: "daily-notifications", panel: DailyNotificationsPanel },
  { value: "audit", label: "Audit Logs", perm: "audit", panel: AuditLogsPanel },
  { value: "cron-jobs", label: "Cron Jobs", perm: "cron-jobs", panel: CronJobsPanel },
  { value: "ai-logs", label: "AI Logs", perm: "ai-logs", panel: AiLogsPanel },
] as const;

function AdminPage() {
  const { session } = useAuth();
  const [isClient, setIsClient] = useState(false);

  useEffect(() => {
    setIsClient(true);
  }, []);

  // During server-side rendering or initial hydration, use all tabs to prevent mismatch
  const visibleTabs = isClient
    ? ADMIN_TABS.filter((tab) => canSeeAdminTab(session?.role, tab.perm))
    : ADMIN_TABS; // Show all tabs on server/initial hydration to prevent mismatch
  const defaultTab = visibleTabs[0]?.value ?? "sync";

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Administration</h1>
        <p className="text-sm text-muted-foreground">Manage connector settings, credentials, roles, rules and audit history.</p>
      </div>

      <HealthStrip />

      <Tabs defaultValue={defaultTab} className="space-y-4">
        <TabsList className="flex h-auto flex-wrap gap-1">
          {visibleTabs.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>{tab.label}</TabsTrigger>
          ))}
        </TabsList>

        {visibleTabs.map((tab) => {
          const Panel = tab.panel;
          return (
            <TabsContent key={tab.value} value={tab.value}>
              <Panel />
            </TabsContent>
          );
        })}
      </Tabs>
    </div>
  );
}

type ExportDataset = "all" | "fill_rate" | "backorders" | "consultants";

type ImportResult = {
  message: string;
  imported: number;
  failed: number;
  errors: Array<{ row: number; order_nbr?: string | null; errors: string[] }>;
  rep_code?: string | null;
};

const EXPORT_DATASETS: Array<{ value: ExportDataset; label: string; description: string }> = [
  { value: "all", label: "Comprehensive", description: "Fill rate, backorders, and consultant assignments" },
  { value: "fill_rate", label: "Fill Rate", description: "Tracked inventory items with order, shipment, and fill-rate metrics" },
  { value: "backorders", label: "Backorders", description: "Pending customer order lines and revenue-at-risk details" },
  { value: "consultants", label: "Sales Consultants", description: "Consultant account, assignment, and performance summary" },
];

function DataToolsPanel() {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const [dataset, setDataset] = useState<ExportDataset>("all");
  const [isExporting, setIsExporting] = useState(false);
  const [isImporting, setIsImporting] = useState(false);
  const [importResult, setImportResult] = useState<ImportResult | null>(null);
  const selectedDataset = EXPORT_DATASETS.find((item) => item.value === dataset) ?? EXPORT_DATASETS[0];

  async function exportCsv() {
    setIsExporting(true);
    try {
      const response = await fetch(`${API_BASE_URL}/admin/data-management/export?dataset=${encodeURIComponent(dataset)}`, {
        headers: {
          Accept: "text/csv",
          ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}),
        },
      });

      if (!response.ok) {
        throw new Error(await responseErrorMessage(response));
      }

      const blob = await response.blob();
      const disposition = response.headers.get("content-disposition") ?? "";
      const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] ?? `orderwatch-${dataset}-export.csv`;
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      toast.success("CSV export downloaded.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "CSV export failed.");
    } finally {
      setIsExporting(false);
    }
  }

  async function importSalesOrders(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0] ?? null;
    event.target.value = "";

    if (!file) return;

    const formData = new FormData();
    formData.append("file", file);
    setIsImporting(true);
    setImportResult(null);

    try {
      const response = await fetch(`${API_BASE_URL}/admin/data-management/sales-orders/import`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}),
        },
        body: formData,
      });
      const data = (await response.json().catch(() => null)) as ImportResult | { message?: string } | null;

      if (!response.ok) {
        const result = data && "errors" in data
          ? data as ImportResult
          : { message: data?.message ?? "Sales order import failed.", imported: 0, failed: 1, errors: [] };
        setImportResult(result);
        throw new Error(result.message);
      }

      const result = data as ImportResult;
      setImportResult(result);
      toast.success(result.message);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Sales order import failed.");
    } finally {
      setIsImporting(false);
    }
  }

  return (
    <div className="space-y-4">
      <Panel title="CSV Export" icon={Download}>
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
          <div className="grid max-w-xl gap-1.5">
            <Label htmlFor="data-export-dataset">Dataset</Label>
            <Select value={dataset} onValueChange={(value) => setDataset(value as ExportDataset)}>
              <SelectTrigger id="data-export-dataset">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {EXPORT_DATASETS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">{selectedDataset.description}</p>
          </div>
          <Button type="button" onClick={exportCsv} disabled={isExporting}>
            <Download className="mr-2 h-4 w-4" />
            {isExporting ? "Preparing..." : "Download CSV"}
          </Button>
        </div>
      </Panel>

      <Panel title="Sales Order CSV Import" icon={Upload}>
        <div className="space-y-4">
          <div className="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
            Required headers: <code>order_nbr</code>, <code>rep_code</code>, <code>customer_id</code>, <code>order_date</code>, <code>order_total</code>.
            Optional headers: <code>customer_name</code>, <code>customer_order</code>, <code>order_type</code>, <code>status</code>, <code>ship_date</code>, <code>requested_on</code>, <code>currency_id</code>.
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Input ref={fileInputRef} type="file" accept=".csv,text/csv,text/plain" className="hidden" onChange={importSalesOrders} />
            <Button type="button" variant="outline" disabled={isImporting} onClick={() => fileInputRef.current?.click()}>
              <Upload className="mr-2 h-4 w-4" />
              {isImporting ? "Validating..." : "Upload Sales Orders CSV"}
            </Button>
            <span className="text-xs text-muted-foreground">Rows are imported only after the entire file passes validation.</span>
          </div>

          {importResult && (
            <div className={`rounded-md border p-3 text-sm ${importResult.failed > 0 ? "border-destructive/30 bg-destructive/5" : "bg-muted/20"}`}>
              <div className="font-medium">{importResult.message}</div>
              <div className="mt-1 text-xs text-muted-foreground">
                Imported {importResult.imported}; failed {importResult.failed}.
              </div>
              {importResult.errors.length > 0 && (
                <div className="mt-3 max-h-56 overflow-auto rounded border bg-background">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/40 text-muted-foreground">
                      <tr>
                        <th className="px-2 py-1 text-left">Row</th>
                        <th className="px-2 py-1 text-left">Order</th>
                        <th className="px-2 py-1 text-left">Errors</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {importResult.errors.map((error, index) => (
                        <tr key={`${error.row}-${index}`}>
                          <td className="px-2 py-1">{error.row}</td>
                          <td className="px-2 py-1">{error.order_nbr ?? "-"}</td>
                          <td className="px-2 py-1">{error.errors.join("; ")}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </div>
      </Panel>
    </div>
  );
}

async function responseErrorMessage(response: Response): Promise<string> {
  const text = await response.text();
  if (!text) return `Request failed with HTTP ${response.status}.`;

  try {
    const data = JSON.parse(text) as { message?: string; error?: string };
    return data.message ?? data.error ?? `Request failed with HTTP ${response.status}.`;
  } catch {
    return text;
  }
}

function CronJobsPanel() {
  const jobs = useCronJobs();
  const [selectedJobId, setSelectedJobId] = useState<number | null>(null);
  const [filter, setFilter] = useState<"all" | "failures" | "successes">("all");
  const [expandedRunId, setExpandedRunId] = useState<number | null>(null);
  const update = useUpdateCronJob();
  const runNow = useRunCronJob();

  const allJobs = jobs.data ?? [];
  const selectedJob = allJobs.find(j => j.id === selectedJobId) ?? allJobs[0] ?? null;
  const runs = useCronRuns(selectedJob?.id ?? null, filter);

  if (jobs.isLoading) return <PanelSkeleton />;
  if (jobs.isError || !allJobs.length) return <ErrorBlock message="Cron configuration could not be loaded." onRetry={() => jobs.refetch()} />;

  const autoMatchJob = allJobs.find(j => j.job_key === "email-sales-order-auto-match") ?? null;

  function statusDot(status: string | null) {
    const color = status === "success" ? "bg-green-500"
      : status === "partial" ? "bg-yellow-400"
      : status === "failed" ? "bg-red-500"
      : "bg-muted-foreground/30";
    return <span className={`inline-block h-2 w-2 shrink-0 rounded-full ${color}`} />;
  }

  return (
    <div className="space-y-4">

      {/* ── All scheduled jobs ── */}
      <Panel title="Scheduled Jobs" icon={Activity}>
        <div className="mb-3 rounded-md border p-3">
          <div className="text-xs font-semibold uppercase text-muted-foreground">Production setup</div>
          <div className="mt-1 flex items-center gap-2">
            <code className="flex-1 overflow-x-auto rounded bg-muted px-2 py-1.5 text-xs">* * * * * php artisan schedule:run</code>
            <Button size="sm" variant="outline" onClick={() => navigator.clipboard.writeText("* * * * * php artisan schedule:run").then(() => toast.success("Copied."))}><Copy className="h-3.5 w-3.5" /></Button>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">Add to Windows Task Scheduler or server crontab. Runs every minute.</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs text-muted-foreground">
                <th className="pb-2 pr-3 font-medium w-0" />
                <th className="pb-2 pr-4 font-medium">Job</th>
                <th className="pb-2 pr-4 font-medium hidden sm:table-cell">Schedule</th>
                <th className="pb-2 pr-4 font-medium hidden xl:table-cell">Command</th>
                <th className="pb-2 pr-4 font-medium hidden md:table-cell">Last run</th>
                <th className="pb-2 pr-4 text-right font-medium hidden lg:table-cell">Next Due</th>
                <th className="pb-2 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {allJobs.map(j => (
                <tr
                  key={j.id}
                  className={`cursor-pointer transition-colors hover:bg-muted/40 ${selectedJob?.id === j.id ? "bg-muted/30" : ""}`}
                  onClick={() => { setSelectedJobId(j.id); setExpandedRunId(null); setFilter("all"); }}
                >
                  <td className="py-3 pr-3">{statusDot(j.last_run_status)}</td>
                  <td className="py-3 pr-4">
                    <div className="font-medium leading-tight">{j.name}</div>
                    <div className="text-xs text-muted-foreground">{j.frequency_label}</div>
                  </td>
                  <td className="py-3 pr-4 hidden sm:table-cell">
                    <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{j.cron_expression}</code>
                  </td>
                  <td className="py-3 pr-4 hidden xl:table-cell">
                    <code className="block max-w-[420px] whitespace-normal break-all rounded bg-muted px-2 py-1 text-xs">{j.command}</code>
                  </td>
                  <td className="py-3 pr-4 hidden md:table-cell">
                    <div className="text-xs text-muted-foreground">{j.last_run_at ? new Date(j.last_run_at).toLocaleString("en-KE") : "Never"}</div>
                    {j.last_run_status && (
                      <div className="mt-0.5 flex items-center gap-1.5">
                        <StatusBadge status={j.last_run_status} />
                        {j.last_duration_ms !== null && <span className="text-xs text-muted-foreground">{(j.last_duration_ms / 1000).toFixed(1)}s</span>}
                      </div>
                    )}
                  </td>
                  <td className="py-3 pr-4 hidden lg:table-cell text-right text-xs text-muted-foreground">
                    {j.next_run_at ? new Date(j.next_run_at).toLocaleString("en-KE") : "—"}
                  </td>
                  <td className="py-3" onClick={e => e.stopPropagation()}>
                    <div className="flex items-center justify-end gap-2">
                      <Switch checked={j.is_enabled} onCheckedChange={value => update.mutate({ id: j.id, is_enabled: value })} />
                      <Button size="sm" variant="outline" disabled={runNow.isPending || !j.is_enabled} onClick={() => runNow.mutate(j.id)} title="Run Now">
                        <Play className="h-3 w-3" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-2 text-xs text-muted-foreground">Click any row to view its run history below.</p>
      </Panel>

      {/* ── Run history for selected job ── */}
      <Panel title={`Run History — ${selectedJob?.name ?? "…"}`} icon={History}>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          {(["all", "failures", "successes"] as const).map(v => (
            <Button key={v} size="sm" variant={filter === v ? "default" : "outline"} onClick={() => setFilter(v)} className="capitalize">{v}</Button>
          ))}
          <Button size="sm" variant="ghost" onClick={() => runs.refetch()}><RefreshCw className={`mr-1 h-3.5 w-3.5 ${runs.isFetching ? "animate-spin" : ""}`} />Refresh</Button>
          {selectedJob && <code className="ml-auto hidden text-xs text-muted-foreground sm:block">{selectedJob.command}</code>}
        </div>
        {runs.isLoading && <PanelSkeleton />}
        {!runs.isLoading && runs.data?.data.length === 0 && <p className="text-sm text-muted-foreground">No runs recorded for this filter.</p>}
        <div className="space-y-2">
          {runs.data?.data.map(run => (
            <div key={run.id} className="rounded-md border text-sm">
              <button type="button" className="flex w-full flex-wrap items-center gap-3 p-3 text-left" onClick={() => setExpandedRunId(expandedRunId === run.id ? null : run.id)}>
                {expandedRunId === run.id ? <ChevronDown className="h-4 w-4 shrink-0" /> : <ChevronRight className="h-4 w-4 shrink-0" />}
                <StatusBadge status={run.status} />
                <span>{new Date(run.started_at).toLocaleString("en-KE")}</span>
                <Badge variant="outline" className="capitalize">{run.trigger_source}</Badge>
                <span className="ml-auto text-xs text-muted-foreground">{run.duration_ms !== null ? `${(run.duration_ms / 1000).toFixed(1)}s` : "Running…"}</span>
              </button>
              <div className="grid grid-cols-3 gap-1 border-t bg-muted/20 p-2 text-center text-xs sm:grid-cols-6">
                <div><strong>{run.emails_processed ?? 0}</strong><br />Emails</div>
                <div><strong>{run.sales_orders_processed ?? 0}</strong><br />Orders</div>
                <div><strong>{run.matches_created ?? 0}</strong><br />Matched</div>
                <div><strong>{run.needs_review_count ?? 0}</strong><br />Review</div>
                <div><strong>{run.unmatched_count ?? 0}</strong><br />Unmatched</div>
                <div><strong>{run.error_count ?? 0}</strong><br />Errors</div>
              </div>
              {expandedRunId === run.id && (
                <div className="space-y-3 border-t p-3">
                  {run.error_summary && <div className="whitespace-pre-wrap rounded border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:bg-red-950/30 dark:text-red-200">{run.error_summary}</div>}
                  {Object.entries(run.step_status || {}).map(([name, step]) => (
                    <div key={name} className="rounded border p-2">
                      <div className="flex items-center justify-between">
                        <span className="font-medium capitalize">{name.replaceAll("_", " ")}</span>
                        <span className="text-xs capitalize text-muted-foreground">{step.status} · {(step.duration_ms / 1000).toFixed(1)}s</span>
                      </div>
                      <div className="mt-1 flex flex-wrap gap-2">
                        {Object.entries(step.metrics || {}).filter(([, v]) => v !== null && v !== undefined && typeof v !== "object").map(([metric, count]) => (
                          <Badge key={metric} variant="secondary">{metric.replaceAll("_", " ")}: {String(count)}</Badge>
                        ))}
                      </div>
                      {step.errors?.map((error, i) => <div key={i} className="mt-1 text-xs text-destructive">{error}</div>)}
                    </div>
                  ))}
                  <div className="grid gap-1 text-xs sm:grid-cols-4">
                    <div>Discrepancies: {run.matched_with_discrepancies_count}</div>
                    <div>Skipped: {run.skipped_count}</div>
                    <div>Checked emails: {run.emails_checked}</div>
                    <div>Checked orders: {run.sales_orders_checked}</div>
                  </div>
                </div>
              )}
            </div>
          ))}
        </div>
      </Panel>

      {/* ── Auto-match pipeline settings ── */}
      {autoMatchJob && (() => {
        const s = autoMatchJob.settings;
        return (
          <Panel title="Email ↔ Sales Order Auto Match — Pipeline Settings" icon={Clock}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <StatusBadge status={autoMatchJob.last_run_status || "never run"} />
              <Badge variant="outline">{autoMatchJob.frequency_label}</Badge>
              <code className="rounded bg-muted px-2 py-1 text-xs">{autoMatchJob.cron_expression}</code>
              <div className="ml-auto flex gap-2">
                <Switch checked={autoMatchJob.is_enabled} onCheckedChange={value => update.mutate({ id: autoMatchJob.id, is_enabled: value })} />
                <Button disabled={runNow.isPending || !autoMatchJob.is_enabled} onClick={() => runNow.mutate(autoMatchJob.id)}>
                  <Play className="mr-1.5 h-3.5 w-3.5" />{runNow.isPending ? "Starting…" : "Run Now"}
                </Button>
              </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {([
                ["email_sync_enabled", "Outlook folder sync"],
                ["acumatica_sync_enabled", "Acumatica order sync"],
                ["matching_enabled", "Guarded matching"],
              ] as const).map(([key, label]) => (
                <label key={key} className="flex items-center gap-2 rounded border p-3 text-sm">
                  <Switch checked={s[key] as boolean} onCheckedChange={value => update.mutate({ id: autoMatchJob.id, settings: { [key]: value } })} />{label}
                </label>
              ))}
              <label className="rounded border p-2 text-xs">
                <span className="text-muted-foreground">SO lookback days</span>
                <Input className="mt-1 h-8" type="number" min={1} max={90} defaultValue={s.sales_order_lookback_days as number} onBlur={e => update.mutate({ id: autoMatchJob.id, settings: { sales_order_lookback_days: Number(e.target.value) } })} />
              </label>
            </div>
            <div className="mt-3 rounded border border-green-200 bg-green-50 p-2 text-xs text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200">
              Guardrail active: only exact deterministic customer PO matches auto-link. AI and contextual matches always require review.
            </div>
          </Panel>
        );
      })()}

    </div>
  );
}

// -------------------------------------------------------------------------
// Mail settings
// -------------------------------------------------------------------------

function MailSettingsPanel({
  settings,
  isAdmin,
  isSaving,
  onSave,
  lastUpdated,
}: {
  settings?: MailSettings;
  isAdmin: boolean;
  isSaving: boolean;
  onSave: (payload: MailSettingsInput) => void;
  lastUpdated: string | null;
}) {
  const mailer = settings?.mailer ?? "smtp";
  const [form, setForm] = useState({
    smtp_host: settings?.smtp_host ?? "",
    smtp_port: String(settings?.smtp_port ?? 587),
    smtp_scheme: (settings?.smtp_scheme ?? "tls") as "tls" | "ssl",
    smtp_username: settings?.smtp_username ?? "",
    smtp_password: "",
    from_address: settings?.from_address ?? "",
    from_name: settings?.from_name ?? "",
  });

  useEffect(() => {
    if (!settings) return;
    setForm({
      smtp_host: settings.smtp_host ?? "",
      smtp_port: String(settings.smtp_port ?? 587),
      smtp_scheme: (settings.smtp_scheme === "ssl" ? "ssl" : "tls"),
      smtp_username: settings.smtp_username ?? "",
      smtp_password: "",
      from_address: settings.from_address ?? "",
      from_name: settings.from_name ?? "",
    });
  }, [settings]);

  const handleSave = () => {
    onSave({
      mailer: "smtp",
      smtp_host: form.smtp_host.trim(),
      smtp_port: Number(form.smtp_port) || 587,
      smtp_scheme: form.smtp_scheme,
      smtp_username: form.smtp_username.trim(),
      smtp_password: form.smtp_password.trim() || undefined,
      from_address: form.from_address.trim(),
      from_name: form.from_name.trim(),
    });
    setForm((current) => ({ ...current, smtp_password: "" }));
  };

  return (
    <div className="mt-3 space-y-3">
      <div className="grid grid-cols-2 gap-1 rounded-md border bg-muted/30 p-1">
        {(["smtp", "resend"] as const).map((option) => (
          <Button
            key={option}
            type="button"
            size="sm"
            variant={mailer === option ? "default" : "ghost"}
            className="h-7 px-2 text-xs uppercase"
            disabled={!isAdmin || isSaving || option === "resend"}
            onClick={() => option === "smtp" && onSave({ mailer: "smtp" })}
            title={option === "resend" ? "Configure Resend via server env for now" : undefined}
          >
            {option}
          </Button>
        ))}
      </div>

      {mailer === "smtp" && (
        <div className="grid gap-3 md:grid-cols-2">
          <Field label="SMTP host" value={form.smtp_host} onChange={(smtp_host) => setForm((v) => ({ ...v, smtp_host }))} placeholder="smtp.office365.com" />
          <Field label="SMTP port" value={form.smtp_port} onChange={(smtp_port) => setForm((v) => ({ ...v, smtp_port }))} placeholder="587" />
          <div>
            <Label className="text-xs text-muted-foreground">Encryption</Label>
            <Select value={form.smtp_scheme} onValueChange={(smtp_scheme: "tls" | "ssl") => setForm((v) => ({ ...v, smtp_scheme }))} disabled={!isAdmin}>
              <SelectTrigger className="mt-1 h-9"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="tls">TLS (587)</SelectItem>
                <SelectItem value="ssl">SSL (465)</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <Field label="SMTP username" value={form.smtp_username} onChange={(smtp_username) => setForm((v) => ({ ...v, smtp_username }))} placeholder="noreply@kimfay.com" />
          <Field
            label={settings?.smtp_password_configured ? `SMTP password (${settings.smtp_password_preview ?? "saved"})` : "SMTP password"}
            value={form.smtp_password}
            onChange={(smtp_password) => setForm((v) => ({ ...v, smtp_password }))}
            placeholder={settings?.smtp_password_configured ? "Leave blank to keep current" : "Required for sending"}
            type="password"
          />
          <Field label="From address" value={form.from_address} onChange={(from_address) => setForm((v) => ({ ...v, from_address }))} placeholder="noreply@kimfay.com" />
          <Field label="From name" value={form.from_name} onChange={(from_name) => setForm((v) => ({ ...v, from_name }))} placeholder="Kim-Fay OrderWatch" />
        </div>
      )}

      <div className="flex items-center justify-between gap-3">
        <div className="text-xs text-muted-foreground">
          {settings?.smtp_configured ? "SMTP ready" : "SMTP incomplete"} · {formatDate(lastUpdated) || "Using env default"}
        </div>
        {isAdmin && mailer === "smtp" && (
          <Button type="button" size="sm" disabled={isSaving || !form.smtp_host.trim() || !form.from_address.trim()} onClick={handleSave}>
            {isSaving ? "Saving…" : "Save SMTP settings"}
          </Button>
        )}
      </div>
    </div>
  );
}

// -------------------------------------------------------------------------
// Health strip
// -------------------------------------------------------------------------

function HealthStrip() {
  const { session } = useAuth();
  const { data, isLoading, isError, refetch } = useAdminHealth();
  const mailSettings = useMailSettings();
  const updateMailSettings = useUpdateMailSettings();
  const isAdmin = session?.role === "Administrator";

  if (isLoading) return <Skeleton className="h-16 w-full" />;
  if (isError || !data) return <ErrorBlock message="Admin health status could not be loaded." onRetry={() => refetch()} />;

  return (
    <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-5">
      {Object.entries(data).map(([service, health]) => {
        if (service === "mail_delivery") {
          return (
            <div key={service} className="rounded-lg border bg-card p-3 md:col-span-2 xl:col-span-2">
              <div className="flex items-center justify-between gap-3">
                <div className="text-xs font-medium uppercase text-muted-foreground">Mail delivery</div>
                <StatusBadge status={health.status} />
              </div>
              <MailSettingsPanel
                settings={mailSettings.data}
                isAdmin={isAdmin}
                isSaving={updateMailSettings.isPending}
                onSave={(payload) => updateMailSettings.mutate(payload)}
                lastUpdated={mailSettings.data?.updated_at ?? health.last_checked_at}
              />
            </div>
          );
        }

        return (
          <div key={service} className="rounded-lg border bg-card p-3">
            <div className="flex items-center justify-between gap-3">
              <div className="text-xs font-medium uppercase text-muted-foreground">{service.replace("_", " ")}</div>
              <StatusBadge status={health.status} />
            </div>
            <div className="mt-1 text-xs text-muted-foreground">{formatDate(health.last_checked_at) || "Not checked yet"}</div>
          </div>
        );
      })}
    </div>
  );
}

// -------------------------------------------------------------------------
// Acumatica config panel
// -------------------------------------------------------------------------

function AcumaticaPanel() {
  const { data, isLoading, isError, refetch } = useAcumatica();
  const update = useUpdateAcumatica();
  const validate = useValidateAcumatica();
  const [form, setForm] = useState<AcumaticaInput>({
    base_url: "", endpoint: "", version: "", tenant: "",
    username: "", token_url: "", password: "", client_id: "", client_secret: "",
  });

  useEffect(() => {
    if (!data?.config) return;
    setForm({
      base_url: data.config.base_url,
      endpoint: data.config.endpoint,
      version: data.config.version,
      tenant: data.config.tenant,
      username: data.config.username,
      token_url: data.config.token_url,
      password: "",
      client_id: "",
      client_secret: "",
    });
  }, [data?.config]);

  if (isLoading) return <PanelSkeleton />;
  if (isError || !data) return <ErrorBlock message="Acumatica settings could not be loaded." onRetry={() => refetch()} />;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const parsed = acumaticaSchema.safeParse(form);
    if (!parsed.success) {
      toast.error(parsed.error.errors[0]?.message ?? "Check the Acumatica form.");
      return;
    }
    update.mutate(parsed.data);
  }

  return (
    <Panel title="Acumatica ERP" icon={Database}>
      <form onSubmit={submit} className="grid gap-3 md:grid-cols-2">
        <Field label="Base URL" value={form.base_url} onChange={(base_url) => setForm((v) => ({ ...v, base_url }))} />
        <Field label="Token URL" value={form.token_url} onChange={(token_url) => setForm((v) => ({ ...v, token_url }))} />
        <Field label="Endpoint" value={form.endpoint} onChange={(endpoint) => setForm((v) => ({ ...v, endpoint }))} />
        <Field label="Version" value={form.version} onChange={(version) => setForm((v) => ({ ...v, version }))} />
        <Field label="Tenant" value={form.tenant} onChange={(tenant) => setForm((v) => ({ ...v, tenant }))} />
        <Field label="Username" value={form.username} onChange={(username) => setForm((v) => ({ ...v, username }))} />
        <Field label={`Client ID ${data.config.client_id_preview ? `(${data.config.client_id_preview})` : ""}`} value={form.client_id ?? ""} onChange={(client_id) => setForm((v) => ({ ...v, client_id }))} placeholder="Leave blank to keep current" />
        <Field label={`Client Secret ${data.config.client_secret_preview ? `(${data.config.client_secret_preview})` : ""}`} value={form.client_secret ?? ""} onChange={(client_secret) => setForm((v) => ({ ...v, client_secret }))} placeholder="Leave blank to keep current" type="password" />
        <Field label={`Password ${data.config.password_preview ? `(${data.config.password_preview})` : ""}`} value={form.password ?? ""} onChange={(password) => setForm((v) => ({ ...v, password }))} placeholder="Leave blank to keep current" type="password" />
        <div className="flex items-end gap-2">
          <Button type="submit" disabled={update.isPending}>Save settings</Button>
          <Button type="button" variant="outline" onClick={() => validate.mutate()} disabled={validate.isPending}>
            Validate
          </Button>
        </div>
      </form>

      <div className="mt-4 flex flex-wrap items-center gap-2 text-sm">
        <StatusBadge status={data.config.health_status} />
        <span className="text-muted-foreground">Last validated: {formatDate(data.config.last_validated_at) || "Never"}</span>
      </div>
    </Panel>
  );
}

// -------------------------------------------------------------------------
// Sync operations panel
// -------------------------------------------------------------------------

function SyncPanel() {
  const syncLogs = useSyncLogs();
  const syncCustomers = useSyncCustomers();
  const syncOrders = useSyncOrders();
  const syncCustomerOrders = useSyncCustomerOrders();
  const stopSync = useStopSyncLog();

  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  // Customer search state for selective sync
  const [customerQ, setCustomerQ] = useState("");
  const [selectedCustomers, setSelectedCustomers] = useState<AcumaticaCustomerSummary[]>([]);
  const customerSearch = useAcumaticaCustomerSearch(customerQ, customerQ.length >= 2);
  const activeCustomerSync = findActiveSyncRun(syncLogs.data, ["customers"]);
  const activeOrderSync = findActiveSyncRun(syncLogs.data, ["sales_orders"]);
  const activeCustomerOrderSync = findActiveSyncRun(syncLogs.data, ["customer_orders"]);

  function handleOrderSync(e: FormEvent) {
    e.preventDefault();
    if (!dateFrom || !dateTo) { toast.error("Both dates are required"); return; }
    if (dateFrom > dateTo)    { toast.error("Start date must be before end date"); return; }
    syncOrders.mutate({ date_from: dateFrom, date_to: dateTo });
  }

  function toggleCustomer(c: AcumaticaCustomerSummary) {
    setSelectedCustomers((prev) =>
      prev.some((x) => x.acumatica_id === c.acumatica_id)
        ? prev.filter((x) => x.acumatica_id !== c.acumatica_id)
        : [...prev, c],
    );
  }

  return (
    <div className="space-y-4">
      {/* All-customer sync */}
      <Panel title="Customer Sync" icon={RefreshCw}>
        <p className="mb-3 text-sm text-muted-foreground">
          Pulls all customer records from Acumatica and upserts them locally. Runs validation and flags missing emails or addresses.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => syncCustomers.mutate()} disabled={syncCustomers.isPending || !!activeCustomerSync}>
            {syncCustomers.isPending || activeCustomerSync ? "Syncing…" : "Sync All Customers"}
          </Button>
          {activeCustomerSync && (
            <Button
              variant="outline"
              onClick={() => stopSync.mutate(activeCustomerSync.id)}
              disabled={stopSync.isPending}
            >
              Stop Sync
            </Button>
          )}
        </div>
      </Panel>

      {/* Date-range order sync */}
      <Panel title="Sales Order Sync — Date Range" icon={RefreshCw}>
        <p className="mb-3 text-sm text-muted-foreground">
          Import sales orders created within the specified date window. All order attributes and line items are captured.
        </p>
        <form onSubmit={handleOrderSync} className="flex flex-wrap items-end gap-3">
          <div className="grid gap-1.5">
            <Label>Start date</Label>
            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-40" />
          </div>
          <div className="grid gap-1.5">
            <Label>End date</Label>
            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-40" />
          </div>
          <Button type="submit" disabled={syncOrders.isPending || !!activeOrderSync}>
            {syncOrders.isPending || activeOrderSync ? "Syncing…" : "Sync Orders"}
          </Button>
          {activeOrderSync && (
            <Button
              type="button"
              variant="outline"
              onClick={() => stopSync.mutate(activeOrderSync.id)}
              disabled={stopSync.isPending}
            >
              Stop Sync
            </Button>
          )}
        </form>
      </Panel>

      {/* Selective customer order sync */}
      <Panel title="Selective Customer Order Sync" icon={Search}>
        <p className="mb-3 text-sm text-muted-foreground">
          Search for specific customers and sync all their historical and active sales orders including full line-item details.
        </p>
        <div className="space-y-3">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search customers by name or ID…"
              value={customerQ}
              onChange={(e) => setCustomerQ(e.target.value)}
              className="pl-9"
            />
          </div>

          {customerQ.length >= 2 && (
            <div className="rounded-md border bg-muted/30">
              {customerSearch.isLoading && <p className="p-3 text-sm text-muted-foreground">Searching…</p>}
              {customerSearch.isError  && <p className="p-3 text-sm text-destructive">Search failed</p>}
              {customerSearch.data && customerSearch.data.length === 0 && (
                <p className="p-3 text-sm text-muted-foreground">No customers found. Run a customer sync first.</p>
              )}
              {customerSearch.data && customerSearch.data.length > 0 && (
                <ul className="divide-y">
                  {customerSearch.data.map((c) => {
                    const selected = selectedCustomers.some((x) => x.acumatica_id === c.acumatica_id);
                    return (
                      <li key={c.acumatica_id}>
                        <button
                          type="button"
                          onClick={() => toggleCustomer(c)}
                          className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted/50 ${selected ? "bg-primary/5" : ""}`}
                        >
                          <span>
                            <span className="font-medium">{c.name}</span>
                            <span className="ml-2 font-mono text-xs text-muted-foreground">{c.acumatica_id}</span>
                            {c.customer_class && <span className="ml-2 text-xs text-muted-foreground">{c.customer_class}</span>}
                          </span>
                          {selected && <Badge variant="outline" className="text-[10px]">Selected</Badge>}
                        </button>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          )}

          {selectedCustomers.length > 0 && (
            <div className="space-y-2">
              <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                Selected ({selectedCustomers.length})
              </div>
              <div className="flex flex-wrap gap-1.5">
                {selectedCustomers.map((c) => (
                  <span
                    key={c.acumatica_id}
                    className="inline-flex items-center gap-1 rounded-full border bg-card px-2.5 py-0.5 text-xs font-medium"
                  >
                    {c.name}
                    <button type="button" onClick={() => toggleCustomer(c)} className="ml-0.5 text-muted-foreground hover:text-foreground">
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                ))}
              </div>
              <Button
                onClick={() => syncCustomerOrders.mutate({ customer_ids: selectedCustomers.map((c) => c.acumatica_id) })}
                disabled={syncCustomerOrders.isPending || !!activeCustomerOrderSync}
                size="sm"
              >
                {syncCustomerOrders.isPending || activeCustomerOrderSync ? "Syncing…" : `Sync Orders for ${selectedCustomers.length} customer${selectedCustomers.length > 1 ? "s" : ""}`}
              </Button>
              {activeCustomerOrderSync && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => stopSync.mutate(activeCustomerOrderSync.id)}
                  disabled={stopSync.isPending}
                >
                  Stop Sync
                </Button>
              )}
            </div>
          )}
        </div>
      </Panel>

      <SyncDiagnosticsPanel />

      <ConsultantOrderImportPanel />

      <OperationsUpdatePanel />

      <DeliverySlaConfigPanel />

      <AcumaticaProbePanel />

      {/* Sync log */}
      <Panel title="Sync Log" icon={History}>
        {syncLogs.isLoading && <PanelSkeleton />}
        {syncLogs.isError   && <ErrorBlock message="Sync logs could not be loaded." onRetry={() => syncLogs.refetch()} />}
        {syncLogs.data && (
          <MiniTable
            headers={["Type", "Status", "Records", "Success", "Failed", "Started", "Duration", "Action"]}
            rows={syncLogs.data.map((log) => [
              log.sync_type,
              log.stop_requested_at && log.status === "running" ? "stopping" : log.status,
              String(log.record_count),
              String(log.success_count),
              String(log.failed_count),
              formatDate(log.started_at) || "—",
              log.ended_at
                ? `${Math.round((new Date(log.ended_at).getTime() - new Date(log.started_at).getTime()) / 1000)}s`
                : log.status === "running" ? "Running…" : "—",
              isActiveSyncLog(log) ? "Stop available" : "—",
            ])}
            empty="No sync runs yet."
          />
        )}
      </Panel>
    </div>
  );
}

function DeliverySlaConfigPanel() {
  const { data = [], isLoading, refetch } = useDeliverySlaConfig();
  const save = useUpdateDeliverySlaConfig();
  const [draft, setDraft] = useState<typeof data>([]);

  useEffect(() => {
    if (data.length > 0) setDraft(data);
  }, [data]);

  function updateRule(index: number, field: string, value: string | number | boolean) {
    setDraft((prev) => prev.map((row, i) => (i === index ? { ...row, [field]: value } : row)));
  }

  return (
    <Panel title="Delivery SLA Configuration" icon={Timer}>
      <p className="mb-4 text-sm text-muted-foreground">
        Configure region-specific delivery SLA targets used by Fill Rate and Business Optimization.
        SLA clock start applies to all regions.
      </p>
      {isLoading && <PanelSkeleton />}
      {!isLoading && draft.length > 0 && (
        <div className="space-y-4">
          {draft.map((rule, index) => (
            <div key={rule.region_key} className="rounded-lg border bg-muted/20 p-4">
              <div className="mb-3 flex items-center justify-between gap-2">
                <h4 className="text-sm font-semibold">{rule.label}</h4>
                <Badge variant="outline">{rule.region_key}</Badge>
              </div>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Field label="Label" value={rule.label} onChange={(v) => updateRule(index, "label", v)} />
                <Field label="SLA hours" value={String(rule.sla_hours)} onChange={(v) => updateRule(index, "sla_hours", Number(v) || 0)} type="number" />
                <Field label="Warning hours" value={rule.warning_hours != null ? String(rule.warning_hours) : ""} onChange={(v) => updateRule(index, "warning_hours", v === "" ? null : Number(v))} type="number" />
                <Field label="Breach hours" value={String(rule.breach_hours)} onChange={(v) => updateRule(index, "breach_hours", Number(v) || 0)} type="number" />
                <Field label="Alert min orders" value={String(rule.alert_min_orders)} onChange={(v) => updateRule(index, "alert_min_orders", Number(v) || 0)} type="number" />
                <Field label="Alert delayed %" value={String(rule.alert_delayed_pct)} onChange={(v) => updateRule(index, "alert_delayed_pct", Number(v) || 0)} type="number" />
                <div>
                  <Label className="text-xs">Clock start</Label>
                  <Select value={rule.clock_start} onValueChange={(v) => updateRule(index, "clock_start", v)}>
                    <SelectTrigger className="mt-1 h-9"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="approved_at">Order approval</SelectItem>
                      <SelectItem value="order_date">Order date</SelectItem>
                      <SelectItem value="ship_date">Ship date</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex items-end gap-2">
                  <label className="flex items-center gap-2 text-sm">
                    <Switch checked={rule.is_metro} onCheckedChange={(v) => updateRule(index, "is_metro", v)} />
                    Metro (24h style)
                  </label>
                </div>
              </div>
            </div>
          ))}
          <div className="flex gap-2">
            <Button
              size="sm"
              onClick={() => save.mutate(draft)}
              disabled={save.isPending}
            >
              Save SLA settings
            </Button>
            <Button size="sm" variant="outline" onClick={() => refetch()}>
              Reset
            </Button>
          </div>
        </div>
      )}
    </Panel>
  );
}

// -------------------------------------------------------------------------
// AI-powered sync health diagnostics
// -------------------------------------------------------------------------

function SyncDiagnosticsPanel() {
  const diagnose = useDiagnoseSyncHealth();

  return (
    <Panel title="AI Sync Diagnostics" icon={Sparkles}>
      <p className="mb-3 text-sm text-muted-foreground">
        Reads the last 20 sync runs and asks OpenAI to explain failure patterns and suggest next steps. Falls
        back to a rule-based summary if no OpenAI key is configured or the call fails.
      </p>
      <Button onClick={() => diagnose.mutate()} disabled={diagnose.isPending}>
        <Sparkles className="mr-2 h-4 w-4" />
        {diagnose.isPending ? "Analyzing…" : "Diagnose Recent Activity"}
      </Button>

      {diagnose.data && (
        <div className="mt-4 space-y-3 rounded-md border p-3">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={diagnose.data.ai_status === "success" ? "default" : "secondary"}>
              {diagnose.data.ai_status === "success" ? "AI-generated" : "Rule-based (AI unavailable)"}
            </Badge>
            <span className="text-xs text-muted-foreground">
              Based on the last {diagnose.data.logs_considered} run{diagnose.data.logs_considered === 1 ? "" : "s"}
            </span>
          </div>
          <p className="text-sm">{diagnose.data.summary}</p>
          {diagnose.data.likely_causes.length > 0 && (
            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Likely causes</div>
              <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
                {diagnose.data.likely_causes.map((cause, i) => <li key={i}>{cause}</li>)}
              </ul>
            </div>
          )}
          {diagnose.data.next_steps.length > 0 && (
            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Next steps</div>
              <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
                {diagnose.data.next_steps.map((step, i) => <li key={i}>{step}</li>)}
              </ul>
            </div>
          )}
          {diagnose.data.ai_error && (
            <p className="text-xs text-destructive">AI call failed, showing fallback: {diagnose.data.ai_error}</p>
          )}
        </div>
      )}
    </Panel>
  );
}

// -------------------------------------------------------------------------
// Consultant-scoped sales order import
// -------------------------------------------------------------------------

function ConsultantOrderImportPanel() {
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const consultants = useConsultantOptions();
  const [repCode, setRepCode] = useState("");
  const [isImporting, setIsImporting] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);

  const selectedConsultant = consultants.data?.items.find((c) => c.rep_code === repCode);

  async function importForConsultant(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0] ?? null;
    event.target.value = "";

    if (!file) return;
    if (!repCode) {
      toast.error("Pick a consultant first.");
      return;
    }

    const formData = new FormData();
    formData.append("file", file);
    formData.append("rep_code", repCode);
    setIsImporting(true);
    setResult(null);

    try {
      const response = await fetch(`${API_BASE_URL}/admin/data-management/sales-orders/import`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}),
        },
        body: formData,
      });
      const data = (await response.json().catch(() => null)) as ImportResult | { message?: string } | null;

      if (!response.ok) {
        const failed = data && "errors" in data
          ? data as ImportResult
          : { message: data?.message ?? "Sales order import failed.", imported: 0, failed: 1, errors: [], rep_code: repCode };
        setResult(failed);
        throw new Error(failed.message);
      }

      const success = data as ImportResult;
      setResult(success);
      toast.success(success.message);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Sales order import failed.");
    } finally {
      setIsImporting(false);
    }
  }

  return (
    <Panel title="Import Sales Orders for a Consultant" icon={Upload}>
      <p className="mb-3 text-sm text-muted-foreground">
        Bulk-upload sales orders for one consultant. Every row is validated and forced to that Rep Code — the
        CSV's own <code>rep_code</code> column becomes optional.
      </p>
      <div className="grid gap-3 sm:grid-cols-[240px_auto] sm:items-end">
        <div className="grid gap-1.5">
          <Label htmlFor="consultant-import-picker">Consultant</Label>
          <Select value={repCode} onValueChange={setRepCode}>
            <SelectTrigger id="consultant-import-picker">
              <SelectValue placeholder="Select a consultant" />
            </SelectTrigger>
            <SelectContent>
              {consultants.data?.items.map((c) => (
                <SelectItem key={c.rep_code} value={c.rep_code}>{c.name} · {c.rep_code}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Input ref={fileInputRef} type="file" accept=".csv,text/csv,text/plain" className="hidden" onChange={importForConsultant} />
          <Button type="button" variant="outline" disabled={isImporting || !repCode} onClick={() => fileInputRef.current?.click()}>
            <Upload className="mr-2 h-4 w-4" />
            {isImporting ? "Validating..." : "Upload Sales Orders CSV"}
          </Button>
        </div>
      </div>

      {result && (
        <div className={`mt-4 rounded-md border p-3 text-sm ${result.failed > 0 ? "border-destructive/30 bg-destructive/5" : "bg-muted/20"}`}>
          <div className="font-medium">{result.message}</div>
          <div className="mt-1 text-xs text-muted-foreground">
            Imported {result.imported}; failed {result.failed}.
          </div>
          {result.errors.length > 0 && (
            <div className="mt-3 max-h-56 overflow-auto rounded border bg-background">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-muted-foreground">
                  <tr>
                    <th className="px-2 py-1 text-left">Row</th>
                    <th className="px-2 py-1 text-left">Order</th>
                    <th className="px-2 py-1 text-left">Errors</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {result.errors.map((error, index) => (
                    <tr key={`${error.row}-${index}`}>
                      <td className="px-2 py-1">{error.row}</td>
                      <td className="px-2 py-1">{error.order_nbr ?? "-"}</td>
                      <td className="px-2 py-1">{error.errors.join("; ")}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {result.imported > 0 && result.rep_code && (
            <Button asChild size="sm" variant="outline" className="mt-3">
              <Link to="/app/sales-consultants">
                View {selectedConsultant?.name ?? result.rep_code}'s orders
                <ArrowUpRight className="ml-1.5 h-3.5 w-3.5" />
              </Link>
            </Button>
          )}
        </div>
      )}
    </Panel>
  );
}

// -------------------------------------------------------------------------
// Operations data refresh (inventory, backorders, fill rate)
// -------------------------------------------------------------------------

function OperationsUpdatePanel() {
  const syncLogs = useSyncLogs();
  const stopSync = useStopSyncLog();
  const updateInventory = useSyncInventory();
  const updateInventoryStocks = useSyncInventoryStocks();
  const updateBackorders = useSyncBackorders();
  const updateFillRate = useSyncFillRate();
  const monthRange = resolveDatePreset("this_month");
  const [backorderDatePreset, setBackorderDatePreset] = useState<DatePresetId>("this_month");
  const [backorderDateFrom, setBackorderDateFrom] = useState(monthRange.from);
  const [backorderDateTo, setBackorderDateTo] = useState(monthRange.to);
  const [fillDatePreset, setFillDatePreset] = useState<DatePresetId>("this_month");
  const [fillDateFrom, setFillDateFrom] = useState(monthRange.from);
  const [fillDateTo, setFillDateTo] = useState(monthRange.to);
  const [inventoryWarehouseId, setInventoryWarehouseId] = useState("FGS");
  const activeInventorySync = findActiveSyncRun(syncLogs.data, ["inventory"]);
  const activeInventoryStocksSync = findActiveSyncRun(syncLogs.data, ["inventory_stocks"]);
  const activeBackordersSync = findActiveSyncRun(syncLogs.data, ["backorders"]);
  const activeFillRateSync = findActiveSyncRun(syncLogs.data, ["fill_rate"]);
  const hasActiveInventoryModule = !!activeInventorySync || !!activeInventoryStocksSync;
  const inventorySyncBody = inventoryWarehouseId !== "all" ? { warehouse_id: inventoryWarehouseId } : undefined;

  function runUpdate(
    label: string,
    mutation: ReturnType<typeof useSyncInventory>,
    body?: Record<string, string>,
  ) {
    mutation.mutate(body, {
      onSuccess: (res) => {
        if (res.sync_run.status === "completed") {
          toast.success(formatOpsSyncToast(label, res.sync_run));
        } else if (res.sync_run.status === "stopped") {
          toast.warning(formatOpsSyncToast(label, res.sync_run));
        } else if (res.sync_run.status === "running") {
          toast.info(formatOpsSyncToast(label, res.sync_run));
        } else {
          toast.error(formatOpsSyncToast(label, res.sync_run));
        }
      },
      onError: (e: Error) => toast.error(e.message),
    });
  }

  function handleFillRateUpdate() {
    if (!fillDateFrom || !fillDateTo) {
      toast.error("Set a date range for fill rate");
      return;
    }
    if (fillDateFrom > fillDateTo) {
      toast.error("Start date must be before end date");
      return;
    }
    runUpdate("Fill rate", updateFillRate, { date_from: fillDateFrom, date_to: fillDateTo });
  }

  function handleBackorderUpdate() {
    if (!backorderDateFrom || !backorderDateTo) {
      toast.error("Set a date range for backorders");
      return;
    }
    if (backorderDateFrom > backorderDateTo) {
      toast.error("Start date must be before end date");
      return;
    }
    runUpdate("Backorders", updateBackorders, { date_from: backorderDateFrom, date_to: backorderDateTo });
  }

  function applyOpsDatePreset(
    preset: DatePresetId,
    setPreset: (value: DatePresetId) => void,
    setFrom: (value: string) => void,
    setTo: (value: string) => void,
  ) {
    setPreset(preset);
    if (preset !== "custom") {
      const range = resolveDatePreset(preset);
      setFrom(range.from);
      setTo(range.to);
    }
  }

  return (
    <Panel title="Operations Data — Inventory, Backorders & Fill Rate" icon={RefreshCw}>
      <p className="mb-4 text-sm text-muted-foreground">
        Pull the latest data from Acumatica and upsert into OrderWatch. Existing rows are refreshed;
        new inventory items, backorder lines, and fill-rate snapshots are added. No local data is wiped.
      </p>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="rounded-lg border bg-muted/20 p-4">
          <div className="mb-2 flex items-center gap-2 text-sm font-medium">
            <Boxes className="h-4 w-4 text-muted-foreground" />
            Inventory
          </div>
          <p className="mb-3 text-xs text-muted-foreground">
            Full catalog sync adds new items. Stocks-only refreshes qty and UOM for items already in the catalog.
          </p>
          <div className="mb-3">
            <Label className="text-xs">Import warehouse</Label>
            <Select value={inventoryWarehouseId} onValueChange={setInventoryWarehouseId}>
              <SelectTrigger className="mt-1 h-9">
                <SelectValue placeholder="Warehouse" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All/default</SelectItem>
                {INVENTORY_IMPORT_WAREHOUSES.map((warehouse) => (
                  <SelectItem key={warehouse} value={warehouse}>{warehouse}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                updateInventoryStocks.mutate(inventorySyncBody, {
                  onSuccess: (res) => {
                    const msg = formatOpsSyncToast("Stocks", res.sync_run);
                    if (res.sync_run.status === "completed") {
                      res.sync_run.filters?.warning ? toast.warning(msg) : toast.success(msg);
                    } else if (res.sync_run.status === "stopped") {
                      toast.warning(msg);
                    } else if (res.sync_run.status === "running") {
                      toast.info(msg);
                    } else {
                      toast.error(msg);
                    }
                  },
                  onError: (e: Error) => toast.error(e.message),
                });
              }}
              disabled={updateInventoryStocks.isPending || updateInventory.isPending || hasActiveInventoryModule}
            >
              <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateInventoryStocks.isPending ? "animate-spin" : ""}`} />
              {updateInventoryStocks.isPending || activeInventoryStocksSync ? "Syncing…" : "Sync stocks only"}
            </Button>
            <Button
              size="sm"
              onClick={() => runUpdate("Inventory", updateInventory, inventorySyncBody)}
              disabled={updateInventory.isPending || updateInventoryStocks.isPending || hasActiveInventoryModule}
            >
              <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateInventory.isPending ? "animate-spin" : ""}`} />
              {updateInventory.isPending || activeInventorySync ? "Updating…" : "Update inventory"}
            </Button>
            {activeInventoryStocksSync && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => stopSync.mutate(activeInventoryStocksSync.id)}
                disabled={stopSync.isPending}
              >
                Stop Stocks Sync
              </Button>
            )}
            {activeInventorySync && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => stopSync.mutate(activeInventorySync.id)}
                disabled={stopSync.isPending}
              >
                Stop Inventory Sync
              </Button>
            )}
          </div>
        </div>

        <div className="rounded-lg border bg-muted/20 p-4">
          <div className="mb-2 flex items-center gap-2 text-sm font-medium">
            <PackageX className="h-4 w-4 text-muted-foreground" />
            Backorders
          </div>
          <p className="mb-3 text-xs text-muted-foreground">
            Open SO lines with unshipped quantity — refreshes revenue at risk.
          </p>
          <div className="mb-3 flex flex-wrap items-end gap-2">
            <div className="grid gap-1">
              <Label className="text-xs">Dates</Label>
              <Select value={backorderDatePreset} onValueChange={(value) => applyOpsDatePreset(value as DatePresetId, setBackorderDatePreset, setBackorderDateFrom, setBackorderDateTo)}>
                <SelectTrigger className="h-8 w-36 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {DATE_PRESETS.filter((preset) => preset.id !== "last_30_days").map((preset) => (
                    <SelectItem key={preset.id} value={preset.id}>{preset.id === "custom" ? "Date range" : preset.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-1">
              <Label className="text-xs">From</Label>
              <Input type="date" value={backorderDateFrom} onChange={(e) => { setBackorderDatePreset("custom"); setBackorderDateFrom(e.target.value); }} className="h-8 w-36 text-xs" />
            </div>
            <div className="grid gap-1">
              <Label className="text-xs">To</Label>
              <Input type="date" value={backorderDateTo} onChange={(e) => { setBackorderDatePreset("custom"); setBackorderDateTo(e.target.value); }} className="h-8 w-36 text-xs" />
            </div>
          </div>
          <Button
            size="sm"
            onClick={handleBackorderUpdate}
            disabled={updateBackorders.isPending || !!activeBackordersSync}
          >
            <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateBackorders.isPending ? "animate-spin" : ""}`} />
            {updateBackorders.isPending || activeBackordersSync ? "Updating…" : "Update backorders"}
          </Button>
          {activeBackordersSync && (
            <Button
              size="sm"
              variant="outline"
              onClick={() => stopSync.mutate(activeBackordersSync.id)}
              disabled={stopSync.isPending}
            >
              Stop Sync
            </Button>
          )}
        </div>

        <div className="rounded-lg border bg-muted/20 p-4">
          <div className="mb-2 flex items-center gap-2 text-sm font-medium">
            <Gauge className="h-4 w-4 text-muted-foreground" />
            Fill rate
          </div>
          <p className="mb-3 text-xs text-muted-foreground">
            Recomputes fill-rate snapshots for orders in the selected date range.
          </p>
          <div className="mb-3 flex flex-wrap items-end gap-2">
            <div className="grid gap-1">
              <Label className="text-xs">Dates</Label>
              <Select value={fillDatePreset} onValueChange={(value) => applyOpsDatePreset(value as DatePresetId, setFillDatePreset, setFillDateFrom, setFillDateTo)}>
                <SelectTrigger className="h-8 w-36 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {DATE_PRESETS.filter((preset) => preset.id !== "last_30_days").map((preset) => (
                    <SelectItem key={preset.id} value={preset.id}>{preset.id === "custom" ? "Date range" : preset.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-1">
              <Label className="text-xs">From</Label>
              <Input type="date" value={fillDateFrom} onChange={(e) => { setFillDatePreset("custom"); setFillDateFrom(e.target.value); }} className="h-8 w-36 text-xs" />
            </div>
            <div className="grid gap-1">
              <Label className="text-xs">To</Label>
              <Input type="date" value={fillDateTo} onChange={(e) => { setFillDatePreset("custom"); setFillDateTo(e.target.value); }} className="h-8 w-36 text-xs" />
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" onClick={handleFillRateUpdate} disabled={updateFillRate.isPending || !!activeFillRateSync}>
              <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateFillRate.isPending ? "animate-spin" : ""}`} />
              {updateFillRate.isPending || activeFillRateSync ? "Updating…" : "Update fill rate"}
            </Button>
            {activeFillRateSync && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => stopSync.mutate(activeFillRateSync.id)}
                disabled={stopSync.isPending}
              >
                Stop Sync
              </Button>
            )}
          </div>
        </div>
      </div>
    </Panel>
  );
}

// -------------------------------------------------------------------------
// Customer preview / connection test
// -------------------------------------------------------------------------

const ACUMATICA_LOOKUP_OPTIONS: Array<{ value: AcumaticaLookupType; label: string; placeholder: string }> = [
  { value: "customer_id", label: "Customer ID", placeholder: "e.g. CUST100002" },
  { value: "inventory_id", label: "Inventory ID", placeholder: "e.g. FAYWP0024" },
  { value: "rep_code", label: "Rep Code", placeholder: "e.g. P505" },
  { value: "consultant_id", label: "Consultant ID", placeholder: "e.g. P505" },
  { value: "salesperson_id", label: "SalesPerson ID", placeholder: "e.g. P505" },
  { value: "zone_id", label: "Zone ID", placeholder: "e.g. Z003" },
  { value: "route_code", label: "Route Code", placeholder: "e.g. 3G" },
  { value: "route_name", label: "Route Name", placeholder: "e.g. Langata" },
];

function AcumaticaProbePanel() {
  const [tab, setTab] = useState<"order" | "customer" | "lookup">("order");
  const [orderId, setOrderId] = useState("SO358387");
  const [customerId, setCustomerId] = useState("CUST103052");
  const [lookupType, setLookupType] = useState<AcumaticaLookupType>("rep_code");
  const [lookupId, setLookupId] = useState("P505");
  const orderProbe = usePreviewOrder();
  const customerProbe = usePreviewCustomer();
  const lookupProbe = useAcumaticaLookup();

  function handleOrderSubmit(e: FormEvent) {
    e.preventDefault();
    const id = orderId.trim();
    if (!id) { toast.error("Enter an order number"); return; }
    orderProbe.mutate(id);
  }

  function handleCustomerSubmit(e: FormEvent) {
    e.preventDefault();
    const id = customerId.trim();
    if (!id) { toast.error("Enter a customer ID"); return; }
    customerProbe.mutate(id);
  }

  function handleLookupSubmit(e: FormEvent) {
    e.preventDefault();
    const id = lookupId.trim();
    if (!id) { toast.error("Enter a lookup ID"); return; }
    lookupProbe.mutate({ type: lookupType, id });
  }

  function copyJson(payload: Record<string, unknown>) {
    navigator.clipboard
      .writeText(JSON.stringify(payload, null, 2))
      .then(() => toast.success("JSON copied."))
      .catch(() => toast.error("Could not copy JSON."));
  }

  const activeResult = tab === "order" ? orderProbe : tab === "customer" ? customerProbe : lookupProbe;
  const selectedLookup = ACUMATICA_LOOKUP_OPTIONS.find((option) => option.value === lookupType) ?? ACUMATICA_LOOKUP_OPTIONS[0];

  return (
    <Panel title="Acumatica Live Probe" icon={FlaskConical}>
      <p className="mb-3 text-sm text-muted-foreground">
        Fetch a live record directly from Acumatica to inspect the raw field structure.
      </p>

      {/* Tab selector */}
      <div className="mb-4 grid max-w-md grid-cols-3 gap-2 rounded-lg border bg-muted/40 p-1">
        {(["order", "customer", "lookup"] as const).map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => setTab(t)}
            className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize transition-all ${
              tab === t ? "bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {t === "order" ? "Sales Order" : t === "customer" ? "Customer" : "Lookup"}
          </button>
        ))}
      </div>

      {tab === "order" && (
        <form onSubmit={handleOrderSubmit} className="flex items-end gap-2">
          <div className="grid gap-1.5 flex-1 max-w-xs">
            <Label htmlFor="probe-order-id">Order Number</Label>
            <Input id="probe-order-id" value={orderId} onChange={(e) => setOrderId(e.target.value)} placeholder="e.g. SO358387" />
          </div>
          <Button type="submit" disabled={orderProbe.isPending}>{orderProbe.isPending ? "Fetching…" : "Fetch Order"}</Button>
          {orderProbe.data && <Button type="button" variant="ghost" size="sm" onClick={() => orderProbe.reset()}>Clear</Button>}
        </form>
      )}

      {tab === "customer" && (
        <form onSubmit={handleCustomerSubmit} className="flex items-end gap-2">
          <div className="grid gap-1.5 flex-1 max-w-xs">
            <Label htmlFor="probe-customer-id">Customer ID</Label>
            <Input id="probe-customer-id" value={customerId} onChange={(e) => setCustomerId(e.target.value)} placeholder="e.g. CUST103052" />
          </div>
          <Button type="submit" disabled={customerProbe.isPending}>{customerProbe.isPending ? "Fetching…" : "Fetch Customer"}</Button>
          {customerProbe.data && <Button type="button" variant="ghost" size="sm" onClick={() => customerProbe.reset()}>Clear</Button>}
        </form>
      )}

      {tab === "lookup" && (
        <form onSubmit={handleLookupSubmit} className="flex flex-wrap items-end gap-2">
          <div className="grid min-w-48 gap-1.5">
            <Label htmlFor="probe-lookup-type">Lookup Type</Label>
            <Select value={lookupType} onValueChange={(value) => setLookupType(value as AcumaticaLookupType)}>
              <SelectTrigger id="probe-lookup-type">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ACUMATICA_LOOKUP_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid min-w-56 flex-1 gap-1.5">
            <Label htmlFor="probe-lookup-id">{selectedLookup.label}</Label>
            <Input
              id="probe-lookup-id"
              value={lookupId}
              onChange={(e) => setLookupId(e.target.value)}
              placeholder={selectedLookup.placeholder}
            />
          </div>
          <Button type="submit" disabled={lookupProbe.isPending}>{lookupProbe.isPending ? "Fetching…" : "Fetch Lookup"}</Button>
          {lookupProbe.data && <Button type="button" variant="ghost" size="sm" onClick={() => lookupProbe.reset()}>Clear</Button>}
        </form>
      )}

      {activeResult.data && (
        <div className="mt-4 space-y-3">
          {tab === "order" && orderProbe.data && (
            <>
              {orderProbe.data.customer_details && (
                <div>
                  <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">CustomerDetails</div>
                  <pre className="max-h-48 overflow-auto rounded-md border bg-muted/40 p-3 text-xs leading-relaxed">
                    {JSON.stringify(orderProbe.data.customer_details, null, 2)}
                  </pre>
                </div>
              )}
              <div>
                <JsonResultHeader title="Full Raw Response" onCopy={() => copyJson(orderProbe.data.raw)} />
                <pre className="max-h-72 overflow-auto rounded-md border bg-muted/40 p-3 text-xs leading-relaxed">
                  {JSON.stringify(orderProbe.data.raw, null, 2)}
                </pre>
              </div>
            </>
          )}
          {tab === "customer" && customerProbe.data && (
            <div>
              <JsonResultHeader title={`Raw Response - ${customerProbe.data.customer_id}`} onCopy={() => copyJson(customerProbe.data.raw)} />
              <pre className="max-h-96 overflow-auto rounded-md border bg-muted/40 p-3 text-xs leading-relaxed">
                {JSON.stringify(customerProbe.data.raw, null, 2)}
              </pre>
            </div>
          )}
          {tab === "lookup" && lookupProbe.data && (
            <div>
              <JsonResultHeader
                title={`${lookupProbe.data.lookup_label} - ${lookupProbe.data.lookup_id} (${lookupProbe.data.entity}.${lookupProbe.data.field})`}
                onCopy={() => copyJson(lookupProbe.data.raw)}
              />
              <pre className="max-h-96 overflow-auto rounded-md border bg-muted/40 p-3 text-xs leading-relaxed">
                {JSON.stringify(lookupProbe.data.raw, null, 2)}
              </pre>
            </div>
          )}
        </div>
      )}
    </Panel>
  );
}

function JsonResultHeader({ title, onCopy }: { title: string; onCopy: () => void }) {
  return (
    <div className="mb-1 flex items-center justify-between gap-2">
      <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</div>
      <Button type="button" variant="ghost" size="sm" onClick={onCopy} title="Copy JSON">
        <Copy className="h-3.5 w-3.5" />
      </Button>
    </div>
  );
}

// -------------------------------------------------------------------------
// Reconciliation panel
// -------------------------------------------------------------------------

function ReconciliationPanel() {
  const reconciliation = useReconciliation();
  const deadLetters = useDeadLetters();
  const updateStatus = useUpdateReconciliationStatus();

  return (
    <div className="space-y-4">
      <Panel title="Reconciliation Issues" icon={AlertTriangle}>
        {reconciliation.isLoading && <PanelSkeleton />}
        {reconciliation.isError   && <ErrorBlock message="Reconciliation results could not be loaded." onRetry={() => reconciliation.refetch()} />}
        {reconciliation.data && reconciliation.data.data.length === 0 && (
          <p className="text-sm text-muted-foreground">No reconciliation issues found. Run a sync to populate.</p>
        )}
        {reconciliation.data && reconciliation.data.data.length > 0 && (
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
              <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
                <tr>
                  {["Type", "Resource", "Field", "Local", "Acumatica", "Severity", "Status", ""].map((h) => (
                    <th key={h} className="px-3 py-2 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {reconciliation.data.data.map((r) => (
                  <tr key={r.id} className="border-t">
                    <td className="px-3 py-2 text-xs">{r.resource_type}</td>
                    <td className="px-3 py-2 font-mono text-xs">{r.resource_id}</td>
                    <td className="px-3 py-2 font-mono text-xs">{r.field_name}</td>
                    <td className="px-3 py-2 text-xs text-muted-foreground">{r.local_value ?? "—"}</td>
                    <td className="px-3 py-2 text-xs text-muted-foreground">{r.acumatica_value ?? "—"}</td>
                    <td className="px-3 py-2"><SeverityBadge severity={r.severity} /></td>
                    <td className="px-3 py-2 capitalize text-xs">{r.remediation_status}</td>
                    <td className="px-3 py-2">
                      {r.remediation_status === "open" && (
                        <div className="flex gap-1">
                          <Button size="sm" variant="outline" className="h-6 px-2 text-[11px]" disabled={updateStatus.isPending}
                            onClick={() => updateStatus.mutate({ id: r.id, remediation_status: "resolved" })}>Resolve</Button>
                          <Button size="sm" variant="ghost" className="h-6 px-2 text-[11px]" disabled={updateStatus.isPending}
                            onClick={() => updateStatus.mutate({ id: r.id, remediation_status: "ignored" })}>Ignore</Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {reconciliation.data && (
          <div className="mt-2 text-xs text-muted-foreground">
            {reconciliation.data.total} issues · page {reconciliation.data.current_page} of {reconciliation.data.last_page || 1}
          </div>
        )}
      </Panel>

      <Panel title="Dead Letters" icon={AlertTriangle}>
        {deadLetters.isLoading && <PanelSkeleton />}
        {deadLetters.isError   && <ErrorBlock message="Dead letters could not be loaded." onRetry={() => deadLetters.refetch()} />}
        {deadLetters.data && (
          <MiniTable
            headers={["Type", "Resource", "Attempts", "Error", "Date"]}
            rows={deadLetters.data.data.map((d) => [
              d.resource_type,
              d.resource_id ?? "—",
              String(d.attempt_count),
              d.last_error.slice(0, 80) + (d.last_error.length > 80 ? "…" : ""),
              formatDate(d.created_at) || "—",
            ])}
            empty="No dead letters. All sync records processed successfully."
          />
        )}
        {deadLetters.data && (
          <div className="mt-2 text-xs text-muted-foreground">
            {deadLetters.data.total} dead letter{deadLetters.data.total !== 1 ? "s" : ""}
          </div>
        )}
      </Panel>
    </div>
  );
}

// -------------------------------------------------------------------------
// AI Keys panel
// -------------------------------------------------------------------------

function AiKeysPanel() {
  const { data, isLoading, isError, refetch } = useAiKeys();
  const save = useSaveAiKey();
  const remove = useDeleteAiKey();
  const [form, setForm] = useState<AiKeyInput>({ provider: "openai", key: "" });

  if (isLoading) return <PanelSkeleton />;
  if (isError || !data) return <ErrorBlock message="AI key settings could not be loaded." onRetry={() => refetch()} />;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const parsed = aiKeySchema.safeParse(form);
    if (!parsed.success) {
      toast.error(parsed.error.errors[0]?.message ?? "Check the AI key form.");
      return;
    }
    save.mutate(parsed.data, { onSuccess: () => setForm((v) => ({ ...v, key: "" })) });
  }

  return (
    <Panel title="AI Provider Keys" icon={KeyRound}>
      <div className="grid gap-3 md:grid-cols-2">
        {data.map((provider) => (
          <div key={provider.provider} className="rounded-md border p-3">
            <div className="flex items-center justify-between gap-3">
              <div className="font-medium capitalize">{provider.provider}</div>
              <StatusBadge status={provider.health_status} />
            </div>
            <div className="mt-2 text-sm text-muted-foreground">Source: {provider.source}</div>
            <div className="mt-1 font-mono text-xs">{provider.masked_preview || "No key configured"}</div>
            <Button className="mt-3" size="sm" variant="outline" disabled={!provider.id || remove.isPending} onClick={() => provider.id && remove.mutate(provider.id)}>
              Delete stored key
            </Button>
          </div>
        ))}
      </div>

      <form onSubmit={submit} className="mt-4 grid gap-3 md:grid-cols-[180px_1fr_auto]">
        <div className="grid gap-1.5">
          <Label>Provider</Label>
          <select
            className="h-9 rounded-md border bg-background px-3 text-sm"
            value={form.provider}
            onChange={(event) => setForm((v) => ({ ...v, provider: event.target.value as AiKeyInput["provider"] }))}
          >
            <option value="openai">OpenAI</option>
            <option value="anthropic">Anthropic</option>
          </select>
        </div>
        <Field label="API key" value={form.key} onChange={(key) => setForm((v) => ({ ...v, key }))} type="password" />
        <div className="flex items-end">
          <Button type="submit" disabled={save.isPending}>Save key</Button>
        </div>
      </form>
    </Panel>
  );
}

function TeamMembersPanel() {
  const { session } = useAuth();
  const members = useTeamMembers();
  const roles = useRoles();
  const create = useCreateTeamMember();
  const update = useUpdateUser();
  const resendWelcome = useResendWelcomeEmail();
  const toggleStatus = useToggleUserStatus();
  const deleteUser = useDeleteUser();
  
  const [form, setForm] = useState({
    name: "",
    email: "",
    role: "Customer Service Agent",
    phone_number: "",
    rep_code: "",
  });

  const [editingMember, setEditingMember] = useState<TeamMember | null>(null);
  const [historyMember, setHistoryMember] = useState<TeamMember | null>(null);

  const isAdmin = session?.role === "Administrator";
  const roleOptions = (roles.data ?? []).filter((role) => isAdmin || role.name === "Sales Consultant");
  const isSalesConsultant = form.role === "Sales Consultant";

  useEffect(() => {
    if (!isAdmin && form.role !== "Sales Consultant") {
      setForm((value) => ({ ...value, role: "Sales Consultant" }));
    }
  }, [form.role, isAdmin]);

  function handleCreate(e: FormEvent) {
    e.preventDefault();
    create.mutate(
      {
        name: form.name.trim(),
        email: form.email.trim(),
        role: form.role,
        phone_number: form.phone_number.trim() || undefined,
        rep_code: isSalesConsultant ? form.rep_code.trim() : undefined,
      },
      {
        onSuccess: () => setForm({
          name: "",
          email: "",
          role: isAdmin ? "Customer Service Agent" : "Sales Consultant",
          phone_number: "",
          rep_code: "",
        }),
      },
    );
  }

  if (members.isLoading || roles.isLoading) return <PanelSkeleton />;
  if (members.isError || roles.isError || !members.data || !roles.data) {
    return (
      <ErrorBlock
        message="Team members could not be loaded."
        onRetry={() => { members.refetch(); roles.refetch(); }}
      />
    );
  }

  return (
    <div className="space-y-4">
      <Panel title="Create Team Member" icon={UserPlus}>
        <p className="mb-4 text-sm text-muted-foreground">
          Add a new OrderWatch user. A welcome email with sign-in instructions is sent automatically.
        </p>
        <form className="grid gap-4 md:grid-cols-2" onSubmit={handleCreate}>
          <Field label="Full name" value={form.name} onChange={(name) => setForm((v) => ({ ...v, name }))} placeholder="Jane Wanjiru" />
          <Field label="Work email" value={form.email} onChange={(email) => setForm((v) => ({ ...v, email }))} placeholder="jane@kimfay.co.ke" />
          <div className="grid gap-1.5">
            <Label>Role</Label>
            <Select value={form.role} onValueChange={(role) => setForm((v) => ({ ...v, role }))}>
              <SelectTrigger><SelectValue placeholder="Select role" /></SelectTrigger>
              <SelectContent>
                {roleOptions.map((role) => (
                  <SelectItem key={role.id} value={role.name}>{role.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Field label="Phone (optional)" value={form.phone_number} onChange={(phone_number) => setForm((v) => ({ ...v, phone_number }))} placeholder="+254..." />
          {isSalesConsultant && (
            <Field
              label="Rep Code"
              value={form.rep_code}
              onChange={(rep_code) => setForm((v) => ({ ...v, rep_code }))}
              placeholder="P505"
            />
          )}
          <div className="md:col-span-2">
            <Button type="submit" disabled={create.isPending || !form.name.trim() || !form.email.trim() || (isSalesConsultant && !form.rep_code.trim())}>
              {create.isPending ? "Creating…" : "Create account & send email"}
            </Button>
          </div>
        </form>
      </Panel>

      <Panel title="Team Members" icon={Users}>
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
              <tr>
                <th className="px-3 py-2 text-left">Name</th>
                <th className="px-3 py-2 text-left">Email</th>
                <th className="px-3 py-2 text-left">Role</th>
                <th className="px-3 py-2 text-left">Rep Code</th>
                <th className="px-3 py-2 text-left">Status</th>
                <th className="px-3 py-2 text-left">Created</th>
                <th className="px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {members.data.map((member) => (
                <tr key={member.id} className="border-t">
                  <td className="px-3 py-2 text-xs font-medium">{member.name}</td>
                  <td className="px-3 py-2 text-xs">{member.email}</td>
                  <td className="px-3 py-2 text-xs">{member.role}</td>
                  <td className="px-3 py-2 text-xs font-mono">
                    {member.rep_code ?? "—"}
                    {member.role === "Sales Consultant" && (
                      <button
                        type="button"
                        title="View rep code history"
                        className="ml-1.5 inline-flex items-center text-muted-foreground hover:text-foreground"
                        onClick={() => setHistoryMember(member)}
                      >
                        <History className="h-3 w-3" />
                      </button>
                    )}
                  </td>
                  <td className="px-3 py-2 text-xs">
                    <StatusBadge status={member.is_active ? "active" : "inactive"} />
                  </td>
                  <td className="px-3 py-2 text-xs">
                    {new Date(member.created_at).toLocaleDateString("en-KE", { timeZone: "Africa/Nairobi" })}
                  </td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-6 px-2 text-[10px]"
                        onClick={() => setEditingMember(member)}
                        title="Edit member"
                      >
                        <Pencil className="mr-1 h-3 w-3" /> Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-6 px-2 text-[10px]"
                        onClick={() => {
                          if (confirm(`Resend welcome email to ${member.name}? This will generate a new password.`)) {
                            resendWelcome.mutate(member.id);
                          }
                        }}
                        disabled={resendWelcome.isPending}
                      >
                        <Mail className="mr-1 h-3 w-3" /> Resend Welcome
                      </Button>
                      <Button
                        size="sm"
                        variant={member.is_active ? "outline" : "default"}
                        className={`h-6 px-2 text-[10px] ${member.is_active ? "text-destructive border-destructive/20 hover:bg-destructive/10" : ""}`}
                        onClick={() => {
                          if (confirm(`Are you sure you want to ${member.is_active ? 'suspend' : 'reactivate'} ${member.name}?`)) {
                            toggleStatus.mutate(member.id);
                          }
                        }}
                        disabled={toggleStatus.isPending}
                      >
                        {member.is_active ? "Suspend" : "Reactivate"}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="h-6 px-2 text-[10px] text-destructive hover:bg-destructive/10"
                        onClick={() => {
                          if (confirm(`Are you sure you want to permanently delete ${member.name}?`)) {
                            deleteUser.mutate(member.id);
                          }
                        }}
                        disabled={deleteUser.isPending}
                        title="Delete Account"
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>

      {editingMember && (
        <AdminEditUserDialog
          member={editingMember}
          isAdmin={isAdmin}
          roleOptions={roles.data}
          onClose={() => setEditingMember(null)}
        />
      )}

      {historyMember && (
        <AdminRepCodeHistorySheet
          member={historyMember}
          onClose={() => setHistoryMember(null)}
        />
      )}
    </div>
  );
}

// ── Edit User Dialog (Administration tab) ────────────────────────────────────

function AdminEditUserDialog({
  member,
  isAdmin,
  roleOptions,
  onClose,
}: {
  member: TeamMember;
  isAdmin: boolean;
  roleOptions: Role[];
  onClose: () => void;
}) {
  const update = useUpdateUser();
  const [form, setForm] = useState({
    name: member.name,
    email: member.email,
    role: member.role,
    phone_number: member.phone_number ?? "",
    rep_code: member.rep_code ?? "",
    is_account_manager: member.is_account_manager,
    change_reason: "",
  });

  const isSalesConsultant = form.role === "Sales Consultant";
  const repCodeChanged = form.rep_code.trim().toUpperCase() !== (member.rep_code ?? "").toUpperCase();

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    update.mutate(
      {
        userId: member.id,
        name: form.name.trim(),
        email: form.email.trim(),
        role: form.role,
        phone_number: form.phone_number.trim() || null,
        rep_code: isSalesConsultant ? (form.rep_code.trim() || null) : null,
        is_account_manager: isAdmin ? form.is_account_manager : undefined,
        change_reason: repCodeChanged ? (form.change_reason.trim() || null) : undefined,
      },
      { onSuccess: onClose },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Edit Team Member</DialogTitle>
          <DialogDescription>Update details for {member.name}.</DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="grid gap-4 py-2 md:grid-cols-2">
          <Field label="Full name" value={form.name} onChange={(v) => setForm((s) => ({ ...s, name: v }))} placeholder="Jane Wanjiru" />
          <Field label="Work email" value={form.email} onChange={(v) => setForm((s) => ({ ...s, email: v }))} placeholder="jane@kimfay.co.ke" />
          <div className="grid gap-1.5">
            <Label>Role</Label>
            <Select value={form.role} onValueChange={(role) => setForm((s) => ({ ...s, role }))} disabled={!isAdmin}>
              <SelectTrigger><SelectValue placeholder="Select role" /></SelectTrigger>
              <SelectContent>
                {(isAdmin ? roleOptions : roleOptions.filter((r) => r.name === "Sales Consultant")).map((r) => (
                  <SelectItem key={r.id} value={r.name}>{r.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Field label="Phone (optional)" value={form.phone_number} onChange={(v) => setForm((s) => ({ ...s, phone_number: v }))} placeholder="+254..." />
          {isSalesConsultant && (
            <Field label="Rep Code" value={form.rep_code} onChange={(v) => setForm((s) => ({ ...s, rep_code: v }))} placeholder="P505" />
          )}
          {isSalesConsultant && repCodeChanged && (
            <div className="grid gap-1.5 md:col-span-2">
              <Label>Reason for rep code change (optional)</Label>
              <Input
                value={form.change_reason}
                onChange={(e) => setForm((s) => ({ ...s, change_reason: e.target.value }))}
                placeholder="e.g. Consultant transferred territories"
              />
            </div>
          )}
          {isAdmin && (
            <div className="flex items-center gap-2 md:col-span-2">
              <input
                id="admin-edit-is-account-manager"
                type="checkbox"
                className="h-4 w-4 rounded border"
                checked={form.is_account_manager}
                onChange={(e) => setForm((s) => ({ ...s, is_account_manager: e.target.checked }))}
              />
              <Label htmlFor="admin-edit-is-account-manager">Account Manager</Label>
            </div>
          )}
          <DialogFooter className="md:col-span-2">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={update.isPending || !form.name.trim() || !form.email.trim()}>
              {update.isPending ? "Saving…" : "Save changes"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

// ── Rep Code History Sheet (Administration tab) ───────────────────────────────

function AdminRepCodeHistorySheet({
  member,
  onClose,
}: {
  member: TeamMember;
  onClose: () => void;
}) {
  const history = useRepCodeHistory(member.id);
  const restore = useRestoreRepCode();

  function handleRestore(entry: RepCodeHistoryEntry) {
    if (!confirm(`Restore rep code "${entry.rep_code}" for ${member.name}?`)) return;
    restore.mutate(
      {
        userId: member.id,
        historyEntryId: entry.id,
      },
      {
        onSuccess: () => {
          toast.success(`Rep code restored to ${entry.rep_code}`);
          onClose();
        },
      },
    );
  }

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <History className="h-4 w-4" />
            Rep Code History
          </SheetTitle>
          <SheetDescription>{member.name} — past rep code values</SheetDescription>
        </SheetHeader>
        <div className="mt-4 space-y-2">
          <div className="rounded-md border bg-muted/20 px-3 py-2 text-sm">
            <span className="text-muted-foreground">Current: </span>
            <span className="font-mono font-semibold">{member.rep_code ?? "—"}</span>
          </div>
          {history.isLoading && (
            <div className="space-y-2 pt-2">
              {[1, 2, 3].map((i) => <Skeleton key={i} className="h-14 w-full" />)}
            </div>
          )}
          {history.isError && <p className="text-sm text-destructive">Failed to load history.</p>}
          {history.data && history.data.length === 0 && (
            <p className="py-6 text-center text-sm text-muted-foreground">No rep code changes recorded yet.</p>
          )}
          {history.data?.map((entry) => (
            <div key={entry.id} className="rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-mono font-semibold">{entry.rep_code ?? "(empty)"}</span>
                <Button
                  size="sm"
                  variant="outline"
                  className="h-7 px-2 text-xs"
                  disabled={update.isPending || entry.rep_code === member.rep_code}
                  onClick={() => handleRestore(entry)}
                >
                  <RotateCcw className="mr-1 h-3 w-3" />
                  Restore
                </Button>
              </div>
              <div className="mt-1 text-xs text-muted-foreground">
                {new Date(entry.changed_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}
                {entry.changed_by_name && <> · {entry.changed_by_name}</>}
              </div>
              {entry.change_reason && (
                <div className="mt-1 text-xs italic text-muted-foreground">"{entry.change_reason}"</div>
              )}
            </div>
          ))}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function RolesPanel() {
  const { data, isLoading, isError, refetch } = useRoles();

  if (isLoading) return <PanelSkeleton />;
  if (isError || !data) return <ErrorBlock message="Roles could not be loaded." onRetry={() => refetch()} />;

  return (
    <Panel title="Roles" icon={ShieldCheck}>
      <MiniTable
        headers={["Role", "Users", "Permissions"]}
        rows={data.map((role) => [
          role.name,
          String(role.users_count),
          role.permissions.map((permission) => permission.name).join(", ") || "No permissions",
        ])}
      />
    </Panel>
  );
}

function PermissionsPanel() {
  const roles = useRoles();
  const permissions = usePermissions();

  if (roles.isLoading || permissions.isLoading) return <PanelSkeleton />;
  if (roles.isError || permissions.isError || !roles.data || !permissions.data) {
    return <ErrorBlock message="Permissions could not be loaded." onRetry={() => { roles.refetch(); permissions.refetch(); }} />;
  }

  return (
    <Panel title="Permission Matrix" icon={ShieldCheck}>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
            <tr>
              <th className="px-3 py-2 text-left">Permission</th>
              {roles.data.map((role) => <th key={role.id} className="px-3 py-2 text-center">{role.name}</th>)}
            </tr>
          </thead>
          <tbody>
            {permissions.data.map((permission) => (
              <tr key={permission.id} className="border-t">
                <td className="px-3 py-2 font-mono text-xs">{permission.name}</td>
                {roles.data.map((role) => {
                  const enabled = role.permissions.some((item) => item.id === permission.id);
                  return <td key={role.id} className="px-3 py-2 text-center">{enabled ? "Yes" : "-"}</td>;
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

function DailyNotificationsPanel() {
  const config = useDailyReportConfig();
  const runs = useDailyReportRuns();
  const update = useUpdateDailyReportConfig();
  const testSend = useTestDailyReport();
  const resend = useResendDailyReport();
  const [sendToText, setSendToText] = useState("");
  const [ccText, setCcText] = useState("");

  useEffect(() => {
    const sendTo = config.data?.send_to ?? config.data?.reply_to ?? [];
    const cc = config.data?.cc ?? [];
    const legacyCc = (config.data?.recipients ?? []).filter((email) => !sendTo.includes(email));

    setSendToText(sendTo.join("\n"));
    setCcText((cc.length ? cc : legacyCc).join("\n"));
  }, [config.data?.send_to, config.data?.cc, config.data?.recipients, config.data?.reply_to]);

  if (config.isLoading) return <PanelSkeleton />;
  if (config.isError || !config.data) return <ErrorBlock message="Daily report settings could not be loaded." onRetry={() => config.refetch()} />;

  const data = config.data;
  const sendTo = data.send_to ?? data.reply_to ?? [];
  const cc = data.cc ?? (data.recipients ?? []).filter((email) => !sendTo.includes(email));

  return (
    <div className="space-y-4">
      <Panel title="Daily Management Email" icon={Mail}>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={data.is_enabled ? "active" : "paused"} />
              <Badge variant="outline">Daily at {data.send_time}</Badge>
              <Badge variant="outline">{data.timezone}</Badge>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">
              Sends a management briefing covering yesterday&apos;s order performance, MTD position, day-over-day comparison, and AI-generated insights.
            </p>
            <div className="mt-3 grid gap-2 text-xs text-muted-foreground sm:grid-cols-2 lg:grid-cols-3">
              <div>Last sent: {formatDate(data.last_sent_at) || "Never"}</div>
              <div>Last status: {data.last_sent_status || "—"} · Delivery: {data.last_delivery_status || "—"}</div>
              <div>Send To: {sendTo.length ? sendTo.join(", ") : "Not configured"}</div>
            </div>
          </div>
          <label className="flex items-center gap-2 rounded border px-3 py-2 text-sm">
            <Switch checked={data.is_enabled} onCheckedChange={(value) => update.mutate({ is_enabled: value })} />Enabled
          </label>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <Field label="Send time (HH:MM)" value={data.send_time} onChange={(value) => update.mutate({ send_time: value })} placeholder="08:00" />
          <Field label="Timezone" value={data.timezone} onChange={(value) => update.mutate({ timezone: value })} placeholder="Africa/Nairobi" />
          <div className="grid gap-1.5 sm:col-span-2">
            <Label>Subject template</Label>
            <Input value={data.subject_template} onBlur={(event) => update.mutate({ subject_template: event.target.value })} />
            <p className="text-xs text-muted-foreground">Use {"{report_date}"} for the formatted report date.</p>
          </div>
          <div className="grid gap-1.5 sm:col-span-2">
            <Label>Send To (primary recipients)</Label>
            <textarea
              className="min-h-[72px] w-full rounded-md border bg-background px-3 py-2 text-sm"
              value={sendToText}
              onChange={(event) => setSendToText(event.target.value)}
              onBlur={() => update.mutate({ send_to: sendToText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean) })}
              placeholder="Add one email per line"
            />
            <p className="text-xs text-muted-foreground">
              These addresses receive the report as primary recipients and are set as Reply-To. Reply All retains CC recipients for full visibility.
            </p>
          </div>
          <div className="grid gap-1.5 sm:col-span-2">
            <Label>CC (carbon copy recipients)</Label>
            <textarea
              className="min-h-[96px] w-full rounded-md border bg-background px-3 py-2 text-sm"
              value={ccText}
              onChange={(event) => setCcText(event.target.value)}
              onBlur={() => update.mutate({ cc: ccText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean) })}
            />
            <p className="text-xs text-muted-foreground">Management and operations contacts copied on each daily report.</p>
          </div>
          <div className="sm:col-span-2">
            <Button
              variant="outline"
              disabled={update.isPending}
              onClick={() => update.mutate({
                send_to: sendToText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean),
                cc: ccText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean),
              })}
            >
              {update.isPending ? "Saving…" : "Save email routing"}
            </Button>
          </div>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {([
            ["include_ai_insights", "AI insights"],
            ["include_mtd", "MTD snapshot"],
            ["include_comparison", "Day-over-day comparison"],
            ["include_customer_highlights", "Customer highlights"],
          ] as const).map(([key, label]) => (
            <label key={key} className="flex items-center gap-2 rounded border p-3 text-sm">
              <Switch checked={data[key]} onCheckedChange={(value) => update.mutate({ [key]: value })} />{label}
            </label>
          ))}
        </div>

        <div className="mt-4 flex flex-wrap gap-2">
          <Button
            variant="outline"
            disabled={testSend.isPending}
            onClick={() => testSend.mutate({
              send_to: sendToText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean),
              cc: ccText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean),
            })}
          >
            <FlaskConical className="mr-1.5 h-3.5 w-3.5" />{testSend.isPending ? "Sending…" : "Test Send"}
          </Button>
          <Button variant="outline" disabled={resend.isPending} onClick={() => resend.mutate()}>
            <RefreshCw className="mr-1.5 h-3.5 w-3.5" />{resend.isPending ? "Resending…" : "Resend Last Report"}
          </Button>
        </div>

        <div className="mt-4 rounded-md border p-3">
          <div className="text-xs font-semibold uppercase text-muted-foreground">Scheduler command</div>
          <div className="mt-1 flex items-center gap-2">
            <code className="flex-1 overflow-x-auto rounded bg-muted px-2 py-1.5 text-xs">{data.command_reference}</code>
            <Button size="sm" variant="outline" onClick={() => navigator.clipboard.writeText(data.command_reference).then(() => toast.success("Command copied."))}><Copy className="h-3.5 w-3.5" /></Button>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">{data.scheduler_reference}</p>
        </div>
      </Panel>

      <Panel title="Report Run History" icon={History}>
        {runs.isLoading && <PanelSkeleton />}
        {runs.data?.data.length === 0 && <p className="text-sm text-muted-foreground">No report runs yet.</p>}
        <MiniTable
          headers={["Report Date", "Status", "AI", "Delivery", "Recipients", "Duration", "Sent At"]}
          rows={(runs.data?.data ?? []).map((run) => [
            run.report_date ?? "—",
            run.status,
            run.ai_status ?? "—",
            run.delivery_status ?? "—",
            String(run.recipient_count),
            run.duration_ms !== null ? `${(run.duration_ms / 1000).toFixed(1)}s` : "—",
            formatDate(run.sent_at) || "—",
          ])}
        />
      </Panel>
    </div>
  );
}

function NotificationRulesPanel() {
  const { data, isLoading, isError, refetch } = useNotificationRules();
  const members = useTeamMembers();
  const roles = useRoles();
  const toggle = useToggleNotificationRule();
  const sendConfig = useSendNotificationRulesConfig();
  const { session } = useAuth();
  const isAdmin = session?.role === "Administrator";

  if (isLoading) return <PanelSkeleton />;
  if (isError || !data) return <ErrorBlock message="Notification rules could not be loaded." onRetry={() => refetch()} />;

  const activeMembers = (members.data ?? []).filter((member) => member.is_active);
  const roleOptions = roles.data ?? [];

  return (
    <Panel title="Notification Rules" icon={ToggleLeft}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3 border-b pb-3">
        <p className="text-xs text-muted-foreground">
          Email the current rules configuration to commercialtechlead@kimfay.com only.
        </p>
        <Button
          variant="outline"
          size="sm"
          disabled={sendConfig.isPending}
          onClick={() => sendConfig.mutate()}
        >
          <Mail className="mr-2 h-4 w-4" />
          {sendConfig.isPending ? "Sending…" : "Send configuration email"}
        </Button>
      </div>
      {data.map((rule) => (
        <div key={rule.id} className="flex items-center justify-between gap-4 border-t py-3 first:border-0">
          <div>
            <div className="font-medium">{rule.rule_key} - {rule.label}</div>
            <div className="text-xs text-muted-foreground">
              {rule.channels.join(", ")} · Last evaluated {formatDate(rule.last_evaluated_at) || "never"} · Last triggered {formatDate(rule.last_triggered_at) || "never"}
            </div>
          </div>
          <Switch checked={rule.is_enabled} disabled={toggle.isPending} onCheckedChange={(is_enabled) => toggle.mutate({ id: rule.id, is_enabled })} />
        </div>
      ))}
      {isAdmin && (
        <div className="mt-4 space-y-3 border-t pt-4">
          <div>
            <div className="text-sm font-medium">Recipient assignments</div>
            <p className="text-xs text-muted-foreground">Assign active users and roles to individual notification rules.</p>
          </div>
          {data.map((rule) => (
            <NotificationRuleRecipientEditor
              key={`recipients-${rule.id}`}
              rule={rule}
              activeMembers={activeMembers}
              roles={roleOptions}
            />
          ))}
        </div>
      )}
    </Panel>
  );
}

function NotificationRuleRecipientEditor({
  rule,
  activeMembers,
  roles,
}: {
  rule: NotificationRule;
  activeMembers: TeamMember[];
  roles: Role[];
}) {
  const saveRecipients = useUpdateNotificationRuleRecipients();
  const [recipientEmails, setRecipientEmails] = useState<string[]>(rule.recipient_emails ?? []);
  const [recipientRoles, setRecipientRoles] = useState<string[]>(rule.recipient_roles ?? []);

  useEffect(() => {
    setRecipientEmails(rule.recipient_emails ?? []);
    setRecipientRoles(rule.recipient_roles ?? []);
  }, [rule.recipient_emails, rule.recipient_roles]);

  const changed =
    JSON.stringify([...recipientEmails].sort()) !== JSON.stringify([...(rule.recipient_emails ?? [])].sort()) ||
    JSON.stringify([...recipientRoles].sort()) !== JSON.stringify([...(rule.recipient_roles ?? [])].sort());

  function selectedValues(select: HTMLSelectElement) {
    return Array.from(select.selectedOptions).map((option) => option.value);
  }

  return (
    <div className="rounded-md border bg-muted/10 p-3">
      <div className="mb-2 text-sm font-medium">{rule.rule_key} - {rule.label}</div>
      <div className="grid gap-2 md:grid-cols-2">
        <div className="grid gap-1">
          <Label className="text-xs">Email recipients</Label>
          <select
            multiple
            className="min-h-24 rounded-md border bg-background px-2 py-1 text-xs"
            value={recipientEmails}
            onChange={(event) => setRecipientEmails(selectedValues(event.currentTarget))}
          >
            {activeMembers.map((member) => (
              <option key={member.email} value={member.email}>{member.name} ({member.email})</option>
            ))}
          </select>
        </div>
        <div className="grid gap-1">
          <Label className="text-xs">Role recipients</Label>
          <select
            multiple
            className="min-h-24 rounded-md border bg-background px-2 py-1 text-xs"
            value={recipientRoles}
            onChange={(event) => setRecipientRoles(selectedValues(event.currentTarget))}
          >
            {roles.map((role) => (
              <option key={role.name} value={role.name}>{role.name}</option>
            ))}
          </select>
        </div>
      </div>
      <Button
        size="sm"
        variant="outline"
        className="mt-2"
        disabled={!changed || saveRecipients.isPending}
        onClick={() => saveRecipients.mutate({ id: rule.id, recipient_emails: recipientEmails, recipient_roles: recipientRoles })}
      >
        {saveRecipients.isPending ? "Saving..." : "Save recipients"}
      </Button>
    </div>
  );
}

function AuditLogsPanel() {
  const { data, isLoading, isError, refetch } = useAuditLogs();

  if (isLoading) return <PanelSkeleton />;
  if (isError || !data) return <ErrorBlock message="Audit logs could not be loaded." onRetry={() => refetch()} />;

  return (
    <Panel title="Audit Logs" icon={History}>
      <MiniTable
        headers={["Time", "Actor", "Action", "Resource"]}
        rows={data.data.map((entry) => [
          formatDate(entry.timestamp) || entry.timestamp,
          entry.actor_user_id ? `User #${entry.actor_user_id}` : "system",
          entry.action_type,
          `${entry.resource_type}${entry.resource_id ? ` #${entry.resource_id}` : ""}`,
        ])}
        empty="No audit entries yet."
      />
      <div className="mt-3 text-xs text-muted-foreground">Showing page {data.current_page} of {data.last_page || 1}, {data.total} total entries.</div>
    </Panel>
  );
}

// -------------------------------------------------------------------------
// Shared primitives
// -------------------------------------------------------------------------

function Panel({ title, icon: Icon, children }: { title: string; icon: ComponentType<{ className?: string }>; children: ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Icon className="h-4 w-4" />{title}</h3>
      {children}
    </div>
  );
}

function Field({ label, value, onChange, placeholder, type = "text" }: { label: string; value: string; onChange: (value: string) => void; placeholder?: string; type?: string }) {
  return (
    <div className="grid gap-1.5">
      <Label>{label}</Label>
      <Input type={type} value={value} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} />
    </div>
  );
}

function MiniTable({ headers, rows, empty = "No records found.", className = "" }: { headers: string[]; rows: string[][]; empty?: string; className?: string }) {
  return (
    <div className={`overflow-x-auto rounded-md border ${className}`}>
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
          <tr>{headers.map((header) => <th key={header} className="px-3 py-2 text-left">{header}</th>)}</tr>
        </thead>
        <tbody>
          {rows.length ? rows.map((row, index) => (
            <tr key={index} className="border-t">
              {row.map((cell, cellIndex) => <td key={`${index}-${cellIndex}`} className="px-3 py-2 align-top">{cell}</td>)}
            </tr>
          )) : (
            <tr><td className="px-3 py-4 text-muted-foreground" colSpan={headers.length}>{empty}</td></tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const normalized = status.toLowerCase();
  const tone = normalized === "connected" || normalized === "healthy" || normalized === "completed" || normalized === "success"
    ? "border-success/40 bg-success/10 text-success"
    : normalized === "error" || normalized === "failed"
      ? "border-destructive/40 bg-destructive/10 text-destructive"
      : normalized === "running"
        ? "border-blue-400/40 bg-blue-400/10 text-blue-600"
        : "border-warning/40 bg-warning/10 text-warning-foreground";

  return <Badge variant="outline" className={tone}>{status}</Badge>;
}

function SeverityBadge({ severity }: { severity: string }) {
  const tone = severity === "error"
    ? "border-destructive/40 bg-destructive/10 text-destructive"
    : severity === "warning"
      ? "border-warning/40 bg-warning/10 text-warning-foreground"
      : "border-muted bg-muted/30 text-muted-foreground";
  return <Badge variant="outline" className={`text-[10px] ${tone}`}>{severity}</Badge>;
}

function PanelSkeleton() {
  return (
    <div className="space-y-3 rounded-lg border bg-card p-4">
      <Skeleton className="h-5 w-48" />
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-2/3" />
    </div>
  );
}

function ErrorBlock({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <div className="flex items-center gap-2 text-sm font-medium"><Activity className="h-4 w-4" />{message}</div>
      <Button className="mt-3" variant="outline" onClick={onRetry}>Retry</Button>
    </div>
  );
}

function formatDate(value: string | null | undefined) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleString("en-KE", { timeZone: "Africa/Nairobi" });
}

// ── AI Logs Panel ──────────────────────────────────────────────────────────────

function AiLogsPanel() {
  const [intentFilter, setIntentFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState<"" | "success" | "failed">("");
  const [expanded, setExpanded] = useState<number | null>(null);

  const stats = useAiPromptLogStats();
  const logs  = useAiPromptLogs({
    intent: intentFilter || undefined,
    status: statusFilter || undefined,
  });

  const INTENT_LABELS: Record<string, string> = {
    order_summary:    "Orders",
    email_summary:    "Emails",
    match_summary:    "Matches",
    customer_summary: "Customers",
    cron_summary:     "Cron",
    comparison:       "Comparison",
    risk_summary:     "Risk",
    general:          "General",
  };

  return (
    <div className="space-y-4">
      <div className="rounded-lg border bg-card p-4 space-y-3">
        <div className="flex items-center gap-2">
          <Bot className="h-4 w-4 text-primary" />
          <h2 className="text-sm font-semibold">AI Prompt Logs</h2>
        </div>

        {/* Stats strip */}
        {stats.data && (
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            {[
              { label: "Total Prompts",   value: stats.data.total },
              { label: "Successful",      value: stats.data.success },
              { label: "Failed",          value: stats.data.failed },
              { label: "Avg Response",    value: `${stats.data.avg_response_ms}ms` },
            ].map(({ label, value }) => (
              <div key={label} className="rounded-md border bg-muted/30 px-3 py-2">
                <p className="text-[10px] text-muted-foreground uppercase tracking-wide">{label}</p>
                <p className="text-lg font-bold">{value}</p>
              </div>
            ))}
          </div>
        )}

        {/* Intent breakdown */}
        {stats.data && Object.keys(stats.data.by_intent).length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {Object.entries(stats.data.by_intent).map(([intent, count]) => (
              <Badge key={intent} variant="secondary" className="text-[10px]">
                {INTENT_LABELS[intent] ?? intent}: {count}
              </Badge>
            ))}
          </div>
        )}

        {/* Filters */}
        <div className="flex flex-wrap gap-2">
          <select
            className="rounded-md border bg-background px-2 py-1.5 text-sm"
            value={intentFilter}
            onChange={(e) => setIntentFilter(e.target.value)}
          >
            <option value="">All intents</option>
            {Object.entries(INTENT_LABELS).map(([val, label]) => (
              <option key={val} value={val}>{label}</option>
            ))}
          </select>
          <select
            className="rounded-md border bg-background px-2 py-1.5 text-sm"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as "" | "success" | "failed")}
          >
            <option value="">All statuses</option>
            <option value="success">Success</option>
            <option value="failed">Failed</option>
          </select>
        </div>
      </div>

      {/* Log table */}
      {logs.isLoading ? (
        <PanelSkeleton />
      ) : logs.isError ? (
        <ErrorBlock message="Failed to load AI logs." onRetry={() => logs.refetch()} />
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
              <tr>
                <th className="px-3 py-2 text-left">Time</th>
                <th className="px-3 py-2 text-left">User</th>
                <th className="px-3 py-2 text-left">Intent</th>
                <th className="px-3 py-2 text-left">Provider</th>
                <th className="px-3 py-2 text-left">Status</th>
                <th className="px-3 py-2 text-left">Response ms</th>
                <th className="px-3 py-2 text-left">Prompt</th>
              </tr>
            </thead>
            <tbody>
              {!logs.data?.data?.length ? (
                <tr>
                  <td className="px-3 py-4 text-muted-foreground" colSpan={7}>
                    No AI prompts logged yet.
                  </td>
                </tr>
              ) : logs.data.data.map((log) => (
                <>
                  <tr
                    key={log.id}
                    className="border-t hover:bg-muted/30 cursor-pointer"
                    onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                  >
                    <td className="px-3 py-2 whitespace-nowrap text-xs text-muted-foreground">
                      {formatDate(log.created_at)}
                    </td>
                    <td className="px-3 py-2 text-xs">
                      {log.user?.name ?? "—"}
                    </td>
                    <td className="px-3 py-2">
                      {log.intent ? (
                        <Badge variant="secondary" className="text-[10px]">
                          {INTENT_LABELS[log.intent] ?? log.intent}
                        </Badge>
                      ) : "—"}
                    </td>
                    <td className="px-3 py-2 text-xs capitalize">{log.provider ?? "—"}</td>
                    <td className="px-3 py-2">
                      <StatusBadge status={log.status} />
                    </td>
                    <td className="px-3 py-2 text-xs tabular-nums">
                      {log.response_time_ms ?? "—"}
                    </td>
                    <td className="px-3 py-2 max-w-[220px] truncate text-xs text-muted-foreground">
                      {log.prompt}
                    </td>
                  </tr>
                  {expanded === log.id && (
                    <tr key={`${log.id}-expanded`} className="border-t bg-muted/20">
                      <td colSpan={7} className="px-4 py-3 space-y-2">
                        <div>
                          <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground mb-0.5">Prompt</p>
                          <p className="text-sm">{log.prompt}</p>
                        </div>
                        {log.ai_message && (
                          <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground mb-0.5">AI Response</p>
                            <p className="text-sm whitespace-pre-wrap">{log.ai_message}</p>
                          </div>
                        )}
                        {log.domains && (
                          <div className="flex gap-1 flex-wrap">
                            <span className="text-[10px] text-muted-foreground mr-1">Domains:</span>
                            {log.domains.map((d) => (
                              <Badge key={d} variant="outline" className="text-[10px] h-4">{d}</Badge>
                            ))}
                          </div>
                        )}
                        {log.sources && (
                          <div className="flex gap-1 flex-wrap">
                            <span className="text-[10px] text-muted-foreground mr-1">Sources:</span>
                            {log.sources.map((s) => (
                              <Badge key={s} variant="outline" className="text-[10px] h-4">{s}</Badge>
                            ))}
                          </div>
                        )}
                        {log.error_message && (
                          <div>
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-destructive mb-0.5">Error</p>
                            <p className="text-sm text-destructive">{log.error_message}</p>
                          </div>
                        )}
                      </td>
                    </tr>
                  )}
                </>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

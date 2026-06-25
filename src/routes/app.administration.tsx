import { createFileRoute } from "@tanstack/react-router";
import { Activity, AlertTriangle, Bot, Boxes, ChevronDown, ChevronRight, Clock, Copy, Database, FlaskConical, Gauge, History, KeyRound, Mail, PackageX, Play, Plus, RefreshCw, Search, ShieldCheck, ToggleLeft, Trash2, X } from "lucide-react";
import { useEffect, useState, type ComponentType, type FormEvent, type ReactNode } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  useAcumatica,
  useAcumaticaCustomerSearch,
  useAdminHealth,
  useAiKeys,
  useAuditLogs,
  useDeadLetters,
  useCronJobs,
  useCronRuns,
  useDailyReportConfig,
  useDailyReportRuns,
  useResendDailyReport,
  useTestDailyReport,
  useUpdateDailyReportConfig,
  useDeleteAiKey,
  useDeleteEmailImportConfig,
  useEmailImportConfigs,
  useMatchHistory,
  useMatchOrders,
  useNotificationRules,
  usePermissions,
  usePendingMatchReviews,
  usePreviewCustomer,
  usePreviewOrder,
  useReconciliation,
  useRoles,
  useAiPromptLogs,
  useAiPromptLogStats,
  useRunCronJob,
  useReviewMatch,
  useSaveAiKey,
  useSaveEmailImportConfig,
  useSyncCustomerOrders,
  useSyncCustomers,
  useSyncLogs,
  useSyncOrders,
  useTestSender,
  useToggleNotificationRule,
  useUpdateAcumatica,
  useUpdateCronJob,
  useUpdateReconciliationStatus,
  useValidateAcumatica,
  type EmailImportConfig,
} from "@/hooks/admin/useAdminSettings";
import { acumaticaSchema, aiKeySchema, type AcumaticaInput, type AiKeyInput } from "@/lib/admin-schemas";
import {
  formatOpsSyncToast,
  useSyncBackorders,
  useSyncFillRate,
  useSyncInventory,
  useSyncInventoryStocks,
} from "@/hooks/useOperations";
import type { AcumaticaCustomerSummary } from "@/types/admin";

export const Route = createFileRoute("/app/administration")({
  head: () => ({ meta: [{ title: "Administration - Kim-Fay OrderWatch" }] }),
  component: AdminPage,
});

function AdminPage() {
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Administration</h1>
        <p className="text-sm text-muted-foreground">Manage connector settings, credentials, roles, rules and audit history.</p>
      </div>

      <HealthStrip />

      <Tabs defaultValue="acumatica" className="space-y-4">
        <TabsList className="flex h-auto flex-wrap gap-1">
          <TabsTrigger value="acumatica">Acumatica</TabsTrigger>
          <TabsTrigger value="sync">Sync Operations</TabsTrigger>
          <TabsTrigger value="reconciliation">Reconciliation</TabsTrigger>
          <TabsTrigger value="ai">AI Keys</TabsTrigger>
          <TabsTrigger value="roles">Roles</TabsTrigger>
          <TabsTrigger value="permissions">Permissions</TabsTrigger>
          <TabsTrigger value="notifications">Notification Rules</TabsTrigger>
          <TabsTrigger value="daily-notifications">Daily Notifications</TabsTrigger>
          <TabsTrigger value="email-import">Email Import</TabsTrigger>
          <TabsTrigger value="audit">Audit Logs</TabsTrigger>
          <TabsTrigger value="cron-jobs">Cron Jobs</TabsTrigger>
          <TabsTrigger value="ai-logs">AI Logs</TabsTrigger>
        </TabsList>

        <TabsContent value="acumatica"><AcumaticaPanel /></TabsContent>
        <TabsContent value="sync"><SyncPanel /></TabsContent>
        <TabsContent value="reconciliation"><ReconciliationPanel /></TabsContent>
        <TabsContent value="ai"><AiKeysPanel /></TabsContent>
        <TabsContent value="roles"><RolesPanel /></TabsContent>
        <TabsContent value="permissions"><PermissionsPanel /></TabsContent>
        <TabsContent value="notifications"><NotificationRulesPanel /></TabsContent>
        <TabsContent value="daily-notifications"><DailyNotificationsPanel /></TabsContent>
        <TabsContent value="email-import"><EmailImportPanel /></TabsContent>
        <TabsContent value="audit"><AuditLogsPanel /></TabsContent>
        <TabsContent value="cron-jobs"><CronJobsPanel /></TabsContent>
        <TabsContent value="ai-logs"><AiLogsPanel /></TabsContent>
      </Tabs>
    </div>
  );
}

function CronJobsPanel() {
  const jobs = useCronJobs();
  const job = jobs.data?.[0] ?? null;
  const [filter, setFilter] = useState<"all" | "failures" | "successes">("all");
  const [expanded, setExpanded] = useState<number | null>(null);
  const runs = useCronRuns(job?.id ?? null, filter);
  const update = useUpdateCronJob();
  const runNow = useRunCronJob();

  if (jobs.isLoading) return <PanelSkeleton />;
  if (jobs.isError || !job) return <ErrorBlock message="Cron configuration could not be loaded." onRetry={() => jobs.refetch()} />;
  const settings = job.settings;

  return (
    <div className="space-y-4">
      <Panel title="Hourly Email ↔ Sales Order Auto Match" icon={Clock}>
        <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={job.last_run_status || "never run"} />
              <Badge variant="outline">{job.frequency_label}</Badge>
              <code className="rounded bg-muted px-2 py-1 text-xs">{job.cron_expression}</code>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">{job.description}</p>
            <div className="mt-3 grid gap-2 text-xs text-muted-foreground sm:grid-cols-3">
              <div>Last run: {job.last_run_at ? new Date(job.last_run_at).toLocaleString("en-KE") : "Never"}</div>
              <div>Last duration: {job.last_duration_ms !== null ? `${(job.last_duration_ms / 1000).toFixed(1)}s` : "—"}</div>
              <div>Next expected: {job.next_run_at ? new Date(job.next_run_at).toLocaleString("en-KE") : "—"}</div>
            </div>
          </div>
          <div className="flex items-start gap-2">
            <label className="flex items-center gap-2 rounded border px-3 py-2 text-sm">
              <Switch checked={job.is_enabled} onCheckedChange={(value) => update.mutate({ id: job.id, is_enabled: value })} />Enabled
            </label>
            <Button disabled={runNow.isPending || !job.is_enabled} onClick={() => runNow.mutate(job.id)}>
              <Play className="mr-1.5 h-3.5 w-3.5" />{runNow.isPending ? "Starting…" : "Run Now"}
            </Button>
          </div>
        </div>

        <div className="mt-4 rounded-md border p-3">
          <div className="text-xs font-semibold uppercase text-muted-foreground">Scheduler command</div>
          <div className="mt-1 flex items-center gap-2">
            <code className="flex-1 overflow-x-auto rounded bg-muted px-2 py-1.5 text-xs">{job.command_reference}</code>
            <Button size="sm" variant="outline" onClick={() => navigator.clipboard.writeText(job.command_reference).then(() => toast.success("Command copied."))}><Copy className="h-3.5 w-3.5" /></Button>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">For production, invoke <code>php artisan schedule:run</code> every minute using Windows Task Scheduler or server cron.</p>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {([
            ["email_sync_enabled", "Outlook folder sync"],
            ["acumatica_sync_enabled", "Acumatica order sync"],
            ["matching_enabled", "Guarded matching"],
          ] as const).map(([key, label]) => (
            <label key={key} className="flex items-center gap-2 rounded border p-3 text-sm">
              <Switch checked={settings[key]} onCheckedChange={(value) => update.mutate({ id: job.id, settings: { [key]: value } })} />{label}
            </label>
          ))}
          <label className="rounded border p-2 text-xs">
            <span className="text-muted-foreground">Sales Order lookback days</span>
            <Input className="mt-1 h-8" type="number" min={1} max={90} defaultValue={settings.sales_order_lookback_days} onBlur={(event) => update.mutate({ id: job.id, settings: { sales_order_lookback_days: Number(event.target.value) } })} />
          </label>
        </div>
        <div className="mt-3 rounded border border-green-200 bg-green-50 p-2 text-xs text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-200">
          Guardrail active: only exact deterministic customer PO matches auto-link. AI and contextual matches always require review.
        </div>
      </Panel>

      <Panel title="Cron Run History" icon={History}>
        <div className="mb-3 flex flex-wrap gap-2">
          {(["all", "failures", "successes"] as const).map((value) => <Button key={value} size="sm" variant={filter === value ? "default" : "outline"} onClick={() => setFilter(value)} className="capitalize">{value}</Button>)}
          <Button size="sm" variant="ghost" onClick={() => runs.refetch()}><RefreshCw className={`mr-1 h-3.5 w-3.5 ${runs.isFetching ? "animate-spin" : ""}`} />Refresh</Button>
        </div>
        {runs.isLoading && <PanelSkeleton />}
        {runs.data?.data.length === 0 && <p className="text-sm text-muted-foreground">No cron runs for this filter.</p>}
        <div className="space-y-2">
          {runs.data?.data.map((run) => (
            <div key={run.id} className="rounded-md border text-sm">
              <button type="button" className="flex w-full flex-wrap items-center gap-3 p-3 text-left" onClick={() => setExpanded(expanded === run.id ? null : run.id)}>
                {expanded === run.id ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                <StatusBadge status={run.status} />
                <span>{new Date(run.started_at).toLocaleString("en-KE")}</span>
                <Badge variant="outline" className="capitalize">{run.trigger_source}</Badge>
                <span className="ml-auto text-xs text-muted-foreground">{run.duration_ms !== null ? `${(run.duration_ms / 1000).toFixed(1)}s` : "Running"}</span>
              </button>
              <div className="grid grid-cols-3 gap-1 border-t bg-muted/20 p-2 text-center text-xs sm:grid-cols-6">
                <div><strong>{run.emails_processed}</strong><br />Emails</div><div><strong>{run.sales_orders_processed}</strong><br />Orders</div><div><strong>{run.matches_created}</strong><br />Matched</div><div><strong>{run.needs_review_count}</strong><br />Review</div><div><strong>{run.unmatched_count}</strong><br />Unmatched</div><div><strong>{run.error_count}</strong><br />Errors</div>
              </div>
              {expanded === run.id && (
                <div className="space-y-3 border-t p-3">
                  {run.error_summary && <div className="whitespace-pre-wrap rounded border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:bg-red-950/30 dark:text-red-200">{run.error_summary}</div>}
                  {Object.entries(run.step_status || {}).map(([name, step]) => (
                    <div key={name} className="rounded border p-2">
                      <div className="flex items-center justify-between"><span className="font-medium capitalize">{name.replaceAll("_", " ")}</span><span className="text-xs capitalize text-muted-foreground">{step.status} · {(step.duration_ms / 1000).toFixed(1)}s</span></div>
                      <div className="mt-1 flex flex-wrap gap-2">{Object.entries(step.metrics || {}).map(([metric, count]) => <Badge key={metric} variant="secondary">{metric.replaceAll("_", " ")}: {count}</Badge>)}</div>
                      {step.errors?.map((error, index) => <div key={index} className="mt-1 text-xs text-destructive">{error}</div>)}
                    </div>
                  ))}
                  <div className="grid gap-1 text-xs sm:grid-cols-4"><div>Discrepancies: {run.matched_with_discrepancies_count}</div><div>Skipped: {run.skipped_count}</div><div>Checked emails: {run.emails_checked}</div><div>Checked orders: {run.sales_orders_checked}</div></div>
                </div>
              )}
            </div>
          ))}
        </div>
      </Panel>
    </div>
  );
}

// -------------------------------------------------------------------------
// Health strip
// -------------------------------------------------------------------------

function HealthStrip() {
  const { data, isLoading, isError, refetch } = useAdminHealth();

  if (isLoading) return <Skeleton className="h-16 w-full" />;
  if (isError || !data) return <ErrorBlock message="Admin health status could not be loaded." onRetry={() => refetch()} />;

  return (
    <div className="grid gap-2 md:grid-cols-4">
      {Object.entries(data).map(([service, health]) => (
        <div key={service} className="rounded-lg border bg-card p-3">
          <div className="flex items-center justify-between gap-3">
            <div className="text-xs font-medium uppercase text-muted-foreground">{service.replace("_", " ")}</div>
            <StatusBadge status={health.status} />
          </div>
          <div className="mt-1 text-xs text-muted-foreground">{formatDate(health.last_checked_at) || "Not checked yet"}</div>
        </div>
      ))}
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

  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  // Customer search state for selective sync
  const [customerQ, setCustomerQ] = useState("");
  const [selectedCustomers, setSelectedCustomers] = useState<AcumaticaCustomerSummary[]>([]);
  const customerSearch = useAcumaticaCustomerSearch(customerQ, customerQ.length >= 2);

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
        <Button onClick={() => syncCustomers.mutate()} disabled={syncCustomers.isPending}>
          {syncCustomers.isPending ? "Syncing…" : "Sync All Customers"}
        </Button>
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
          <Button type="submit" disabled={syncOrders.isPending}>
            {syncOrders.isPending ? "Syncing…" : "Sync Orders"}
          </Button>
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
                disabled={syncCustomerOrders.isPending}
                size="sm"
              >
                {syncCustomerOrders.isPending ? "Syncing…" : `Sync Orders for ${selectedCustomers.length} customer${selectedCustomers.length > 1 ? "s" : ""}`}
              </Button>
            </div>
          )}
        </div>
      </Panel>

      <OperationsUpdatePanel />

      <AcumaticaProbePanel />

      {/* Sync log */}
      <Panel title="Sync Log" icon={History}>
        {syncLogs.isLoading && <PanelSkeleton />}
        {syncLogs.isError   && <ErrorBlock message="Sync logs could not be loaded." onRetry={() => syncLogs.refetch()} />}
        {syncLogs.data && (
          <MiniTable
            headers={["Type", "Status", "Records", "Success", "Failed", "Started", "Duration"]}
            rows={syncLogs.data.map((log) => [
              log.sync_type,
              log.status,
              String(log.record_count),
              String(log.success_count),
              String(log.failed_count),
              formatDate(log.started_at) || "—",
              log.ended_at
                ? `${Math.round((new Date(log.ended_at).getTime() - new Date(log.started_at).getTime()) / 1000)}s`
                : log.status === "running" ? "Running…" : "—",
            ])}
            empty="No sync runs yet."
          />
        )}
      </Panel>
    </div>
  );
}

// -------------------------------------------------------------------------
// Operations data refresh (inventory, backorders, fill rate)
// -------------------------------------------------------------------------

function opsDateStartOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function opsDateToday() {
  return new Date().toISOString().slice(0, 10);
}

function OperationsUpdatePanel() {
  const updateInventory = useSyncInventory();
  const updateInventoryStocks = useSyncInventoryStocks();
  const updateBackorders = useSyncBackorders();
  const updateFillRate = useSyncFillRate();
  const [fillDateFrom, setFillDateFrom] = useState(opsDateStartOfMonth);
  const [fillDateTo, setFillDateTo] = useState(opsDateToday);

  function runUpdate(
    label: string,
    mutation: ReturnType<typeof useSyncInventory>,
    body?: Record<string, string>,
  ) {
    mutation.mutate(body, {
      onSuccess: (res) => {
        if (res.sync_run.status === "completed") {
          toast.success(formatOpsSyncToast(label, res.sync_run));
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
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                updateInventoryStocks.mutate(undefined, {
                  onSuccess: (res) => {
                    const msg = formatOpsSyncToast("Stocks", res.sync_run);
                    if (res.sync_run.status === "completed") {
                      res.sync_run.filters?.warning ? toast.warning(msg) : toast.success(msg);
                    } else {
                      toast.error(msg);
                    }
                  },
                  onError: (e: Error) => toast.error(e.message),
                });
              }}
              disabled={updateInventoryStocks.isPending || updateInventory.isPending}
            >
              <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateInventoryStocks.isPending ? "animate-spin" : ""}`} />
              {updateInventoryStocks.isPending ? "Syncing…" : "Sync stocks only"}
            </Button>
            <Button
              size="sm"
              onClick={() => runUpdate("Inventory", updateInventory)}
              disabled={updateInventory.isPending || updateInventoryStocks.isPending}
            >
              <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateInventory.isPending ? "animate-spin" : ""}`} />
              {updateInventory.isPending ? "Updating…" : "Update inventory"}
            </Button>
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
          <Button
            size="sm"
            onClick={() => runUpdate("Backorders", updateBackorders)}
            disabled={updateBackorders.isPending}
          >
            <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateBackorders.isPending ? "animate-spin" : ""}`} />
            {updateBackorders.isPending ? "Updating…" : "Update backorders"}
          </Button>
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
              <Label className="text-xs">From</Label>
              <Input type="date" value={fillDateFrom} onChange={(e) => setFillDateFrom(e.target.value)} className="h-8 w-36 text-xs" />
            </div>
            <div className="grid gap-1">
              <Label className="text-xs">To</Label>
              <Input type="date" value={fillDateTo} onChange={(e) => setFillDateTo(e.target.value)} className="h-8 w-36 text-xs" />
            </div>
          </div>
          <Button size="sm" onClick={handleFillRateUpdate} disabled={updateFillRate.isPending}>
            <RefreshCw className={`mr-2 h-3.5 w-3.5 ${updateFillRate.isPending ? "animate-spin" : ""}`} />
            {updateFillRate.isPending ? "Updating…" : "Update fill rate"}
          </Button>
        </div>
      </div>
    </Panel>
  );
}

// -------------------------------------------------------------------------
// Customer preview / connection test
// -------------------------------------------------------------------------

function AcumaticaProbePanel() {
  const [tab, setTab] = useState<"order" | "customer">("order");
  const [orderId, setOrderId] = useState("SO358387");
  const [customerId, setCustomerId] = useState("CUST103052");
  const orderProbe = usePreviewOrder();
  const customerProbe = usePreviewCustomer();

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

  const activeResult = tab === "order" ? orderProbe : customerProbe;

  return (
    <Panel title="Acumatica Live Probe" icon={FlaskConical}>
      <p className="mb-3 text-sm text-muted-foreground">
        Fetch a live record directly from Acumatica to inspect the raw field structure.
      </p>

      {/* Tab selector */}
      <div className="mb-4 grid grid-cols-2 gap-2 rounded-lg border bg-muted/40 p-1 max-w-xs">
        {(["order", "customer"] as const).map((t) => (
          <button
            key={t}
            type="button"
            onClick={() => setTab(t)}
            className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize transition-all ${
              tab === t ? "bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {t === "order" ? "Sales Order" : "Customer"}
          </button>
        ))}
      </div>

      {tab === "order" ? (
        <form onSubmit={handleOrderSubmit} className="flex items-end gap-2">
          <div className="grid gap-1.5 flex-1 max-w-xs">
            <Label htmlFor="probe-order-id">Order Number</Label>
            <Input id="probe-order-id" value={orderId} onChange={(e) => setOrderId(e.target.value)} placeholder="e.g. SO358387" />
          </div>
          <Button type="submit" disabled={orderProbe.isPending}>{orderProbe.isPending ? "Fetching…" : "Fetch Order"}</Button>
          {orderProbe.data && <Button type="button" variant="ghost" size="sm" onClick={() => orderProbe.reset()}>Clear</Button>}
        </form>
      ) : (
        <form onSubmit={handleCustomerSubmit} className="flex items-end gap-2">
          <div className="grid gap-1.5 flex-1 max-w-xs">
            <Label htmlFor="probe-customer-id">Customer ID</Label>
            <Input id="probe-customer-id" value={customerId} onChange={(e) => setCustomerId(e.target.value)} placeholder="e.g. CUST103052" />
          </div>
          <Button type="submit" disabled={customerProbe.isPending}>{customerProbe.isPending ? "Fetching…" : "Fetch Customer"}</Button>
          {customerProbe.data && <Button type="button" variant="ghost" size="sm" onClick={() => customerProbe.reset()}>Clear</Button>}
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
                <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Full Raw Response</div>
                <pre className="max-h-72 overflow-auto rounded-md border bg-muted/40 p-3 text-xs leading-relaxed">
                  {JSON.stringify(orderProbe.data.raw, null, 2)}
                </pre>
              </div>
            </>
          )}
          {tab === "customer" && customerProbe.data && (
            <div>
              <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Raw Response — {customerProbe.data.customer_id}</div>
              <pre className="max-h-96 overflow-auto rounded-md border bg-muted/40 p-3 text-xs leading-relaxed">
                {JSON.stringify(customerProbe.data.raw, null, 2)}
              </pre>
            </div>
          )}
        </div>
      )}
    </Panel>
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
  const [recipientsText, setRecipientsText] = useState("");
  const [replyToText, setReplyToText] = useState("");

  useEffect(() => {
    if (config.data?.recipients) {
      setRecipientsText(config.data.recipients.join("\n"));
    }
    if (config.data?.reply_to) {
      setReplyToText(config.data.reply_to.join("\n"));
    }
  }, [config.data?.recipients, config.data?.reply_to]);

  if (config.isLoading) return <PanelSkeleton />;
  if (config.isError || !config.data) return <ErrorBlock message="Daily report settings could not be loaded." onRetry={() => config.refetch()} />;

  const data = config.data;

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
              <div>Reply-To: {data.reply_to.length ? data.reply_to.join(", ") : "Not configured"}</div>
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
            <Label>Reply-To / To addresses</Label>
            <textarea
              className="min-h-[72px] w-full rounded-md border bg-background px-3 py-2 text-sm"
              value={replyToText}
              onChange={(event) => setReplyToText(event.target.value)}
              onBlur={() => update.mutate({ reply_to: replyToText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean) })}
              placeholder="Add one email per line"
            />
            <p className="text-xs text-muted-foreground">
              Primary recipients for the report. Replies are directed to these addresses. Configure any addresses here — nothing is hardcoded.
            </p>
          </div>
          <div className="grid gap-1.5 sm:col-span-2">
            <Label>CC recipients (one email per line)</Label>
            <textarea
              className="min-h-[96px] w-full rounded-md border bg-background px-3 py-2 text-sm"
              value={recipientsText}
              onChange={(event) => setRecipientsText(event.target.value)}
              onBlur={() => update.mutate({ recipients: recipientsText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean) })}
            />
            <p className="text-xs text-muted-foreground">Management and operations contacts copied on each daily report.</p>
          </div>
          <div className="sm:col-span-2">
            <Button
              variant="outline"
              disabled={update.isPending}
              onClick={() => update.mutate({
                reply_to: replyToText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean),
                recipients: recipientsText.split(/[\n,;]+/).map((email) => email.trim()).filter(Boolean),
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
          <Button variant="outline" disabled={testSend.isPending} onClick={() => testSend.mutate()}>
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
  const toggle = useToggleNotificationRule();

  if (isLoading) return <PanelSkeleton />;
  if (isError || !data) return <ErrorBlock message="Notification rules could not be loaded." onRetry={() => refetch()} />;

  return (
    <Panel title="Notification Rules" icon={ToggleLeft}>
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
    </Panel>
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
// Email Import Config panel
// -------------------------------------------------------------------------

const BLANK_CONFIG: Partial<EmailImportConfig> = {
  sender_pattern: "",
  is_wildcard: false,
  display_name: "",
  customer_class: "",
  po_patterns: [],
  po_extraction_source: "all",
  ai_fallback_enabled: true,
  is_active: true,
  notes: "",
};

function EmailImportPanel() {
  const { data, isLoading, isError, refetch } = useEmailImportConfigs();
  const save    = useSaveEmailImportConfig();
  const remove  = useDeleteEmailImportConfig();
  const test    = useTestSender();
  const history = useMatchHistory();
  const match   = useMatchOrders();

  const [editing, setEditing] = useState<Partial<EmailImportConfig> | null>(null);
  const [testEmail, setTestEmail] = useState("");
  const [poInput, setPoInput] = useState("");

  function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    save.mutate(editing, { onSuccess: () => setEditing(null) });
  }

  return (
    <div className="space-y-4">
      {/* Match Orders control */}
      <Panel title="Order Matching" icon={Mail}>
        <p className="mb-3 text-sm text-muted-foreground">
          Extracts PO numbers from all unprocessed emails then cross-references against Acumatica sales orders.
          Updates <strong>match_status</strong> and <strong>flag_source</strong> on every affected order.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => match.mutate()} disabled={match.isPending} className="bg-violet-600 hover:bg-violet-700 text-white">
            {match.isPending ? "Running…" : "Run Match Now"}
          </Button>
          <Button variant="outline" onClick={() => history.refetch()} disabled={history.isFetching}>
            <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${history.isFetching ? "animate-spin" : ""}`} />
            Refresh History
          </Button>
        </div>

        {history.data && history.data.length > 0 && (
          <div className="mt-4 overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
              <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
                <tr>
                  {["Started", "Status", "Emails", "PO Extracted", "Matched", "Duplicate", "Missing"].map((h) => (
                    <th key={h} className="px-3 py-2 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {history.data.map((r) => (
                  <tr key={r.id} className="border-t">
                    <td className="px-3 py-2 text-xs">{new Date(r.started_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}</td>
                    <td className="px-3 py-2"><StatusBadge status={r.status} /></td>
                    <td className="px-3 py-2 tabular-nums text-xs">{r.emails_processed}</td>
                    <td className="px-3 py-2 tabular-nums text-xs">{r.po_extracted}</td>
                    <td className="px-3 py-2 tabular-nums text-xs text-green-600">{r.matched}</td>
                    <td className="px-3 py-2 tabular-nums text-xs text-blue-600">{r.duplicate}</td>
                    <td className="px-3 py-2 tabular-nums text-xs text-red-600">{r.missing_in_acumatica}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <MatchReviewQueue />

      {/* Sender configurations */}
      <Panel title="Allowed Email Senders" icon={Mail}>
        <p className="mb-3 text-sm text-muted-foreground">
          Only emails from these addresses are processed for PO extraction.
          Use <code className="rounded bg-muted px-1 text-xs">*@domain.com</code> for wildcard (all subdomains).
        </p>

        <Button size="sm" onClick={() => setEditing({ ...BLANK_CONFIG })} className="mb-3">
          <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Sender
        </Button>

        {isLoading && <PanelSkeleton />}
        {isError   && <ErrorBlock message="Sender configs could not be loaded." onRetry={() => refetch()} />}

        {data && data.length > 0 && (
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
              <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
                <tr>
                  {["Sender", "Name", "Wildcard", "Source", "AI Fallback", "Active", ""].map((h) => (
                    <th key={h} className="px-3 py-2 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.map((cfg) => (
                  <tr key={cfg.id} className="border-t">
                    <td className="px-3 py-2 font-mono text-xs">{cfg.sender_pattern}</td>
                    <td className="px-3 py-2 font-medium text-xs">{cfg.display_name}</td>
                    <td className="px-3 py-2 text-xs">{cfg.is_wildcard ? "Yes" : "No"}</td>
                    <td className="px-3 py-2 text-xs capitalize">{cfg.po_extraction_source}</td>
                    <td className="px-3 py-2 text-xs">{cfg.ai_fallback_enabled ? "On" : "Off"}</td>
                    <td className="px-3 py-2">
                      <StatusBadge status={cfg.is_active ? "active" : "inactive"} />
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex gap-1">
                        <Button size="sm" variant="outline" className="h-6 px-2 text-[10px]" onClick={() => setEditing(cfg)}>Edit</Button>
                        <Button size="sm" variant="ghost" className="h-6 px-2 text-[10px] text-destructive" disabled={remove.isPending}
                          onClick={() => cfg.id && remove.mutate(cfg.id)}>
                          <Trash2 className="h-3 w-3" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {data && data.length === 0 && (
          <p className="text-sm text-muted-foreground">No senders configured. Add one above.</p>
        )}
      </Panel>

      {/* Edit / create form */}
      {editing && (
        <Panel title={editing.id ? "Edit Sender" : "Add Sender"} icon={Mail}>
          <form onSubmit={handleSave} className="grid gap-3 md:grid-cols-2">
            <Field label="Sender Pattern" value={editing.sender_pattern ?? ""} onChange={(v) => setEditing((e) => ({ ...e!, sender_pattern: v }))} placeholder="notification@naivas.net or *@quickmart.co.ke" />
            <Field label="Display Name" value={editing.display_name ?? ""} onChange={(v) => setEditing((e) => ({ ...e!, display_name: v }))} placeholder="Naivas" />
            <div className="flex items-center gap-3">
              <Switch id="cfg-wildcard" checked={!!editing.is_wildcard} onCheckedChange={(c) => setEditing((e) => ({ ...e!, is_wildcard: c }))} />
              <Label htmlFor="cfg-wildcard" className="cursor-pointer text-sm select-none">Wildcard domain (*@domain.com)</Label>
            </div>
            <div className="flex items-center gap-3">
              <Switch id="cfg-ai" checked={!!editing.ai_fallback_enabled} onCheckedChange={(c) => setEditing((e) => ({ ...e!, ai_fallback_enabled: c }))} />
              <Label htmlFor="cfg-ai" className="cursor-pointer text-sm select-none">AI fallback enabled</Label>
            </div>
            <div className="flex items-center gap-3">
              <Switch id="cfg-active" checked={!!editing.is_active} onCheckedChange={(c) => setEditing((e) => ({ ...e!, is_active: c }))} />
              <Label htmlFor="cfg-active" className="cursor-pointer text-sm select-none">Active</Label>
            </div>
            <Field label="Notes (optional)" value={editing.notes ?? ""} onChange={(v) => setEditing((e) => ({ ...e!, notes: v }))} placeholder="e.g. Chandarana head office" />
            <div className="col-span-full flex gap-2">
              <Button type="submit" disabled={save.isPending}>Save</Button>
              <Button type="button" variant="outline" onClick={() => setEditing(null)}>Cancel</Button>
            </div>
          </form>
        </Panel>
      )}

      {/* Sender test */}
      <Panel title="Test Sender Address" icon={Mail}>
        <p className="mb-3 text-sm text-muted-foreground">Verify whether an email address would match any active sender config.</p>
        <div className="flex gap-2">
          <Input value={testEmail} onChange={(e) => setTestEmail(e.target.value)} placeholder="orders@joska.quickmart.co.ke" className="max-w-sm" />
          <Button variant="outline" onClick={() => test.mutate(testEmail)} disabled={test.isPending || !testEmail}>Test</Button>
        </div>
        {test.data && (
          <div className={`mt-3 rounded-md border p-3 text-sm ${test.data.matched ? "border-green-300 bg-green-50 text-green-700" : "border-red-300 bg-red-50 text-red-700"}`}>
            {test.data.matched
              ? `✓ Matched — config: ${test.data.config?.display_name} (${test.data.config?.sender_pattern})`
              : "✗ No active config matches this sender"}
          </div>
        )}
      </Panel>
    </div>
  );
}

function MatchReviewQueue() {
  const reviews = usePendingMatchReviews();
  const review = useReviewMatch();
  const [reasons, setReasons] = useState<Record<number, string>>({});

  return (
    <Panel title="PO / Email Match Review" icon={ShieldCheck}>
      <p className="mb-3 text-sm text-muted-foreground">
        Guarded matches remain here until a reviewer records a decision and reason. Evidence is shown exactly as captured.
      </p>
      {reviews.isLoading && <PanelSkeleton />}
      {reviews.isError && <ErrorBlock message="Match reviews could not be loaded." onRetry={() => reviews.refetch()} />}
      {reviews.data?.data.length === 0 && <p className="text-sm text-muted-foreground">No matches need review.</p>}
      <div className="space-y-3">
        {reviews.data?.data.map((email) => (
          <div key={email.id} className="rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <div className="font-medium">{email.subject || "(no subject)"}</div>
                <div className="text-xs text-muted-foreground">{email.from_email || "Unknown sender"} · {email.received_at ? new Date(email.received_at).toLocaleString("en-KE") : "Unknown date"}</div>
              </div>
              <Badge variant="outline" className="capitalize">{email.match_classification.replaceAll("_", " ")}</Badge>
            </div>
            <div className="mt-3 grid gap-3 md:grid-cols-2">
              <div>
                <div className="text-xs font-semibold uppercase text-muted-foreground">Identifier evidence</div>
                <div className="mt-1 font-mono text-sm">{email.extracted_po_number || "No single PO selected"}</div>
                <div className="mt-1 flex flex-wrap gap-1">
                  {(email.match_sources || []).map((source) => <Badge key={source} variant="secondary">{source.replaceAll("_", " ")}</Badge>)}
                </div>
                {(email.match_evidence || []).map((item, index) => (
                  <div key={`${item.source}-${index}`} className="mt-2 rounded bg-muted/40 p-2 text-xs">
                    <span className="font-mono font-medium">{item.po_number}</span> · {item.source} · {item.confidence}%
                    <div className="mt-1 break-words text-muted-foreground">Raw: {item.raw_match}</div>
                  </div>
                ))}
              </div>
              <div>
                <div className="text-xs font-semibold uppercase text-muted-foreground">Conflicts and reasons</div>
                {(email.match_reason_codes || []).map((code) => <div key={code} className="mt-1 text-xs">• {code.replaceAll("_", " ")}</div>)}
                {(email.match_conflicts || []).map((conflict) => (
                  <div key={conflict.field} className="mt-2 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                    <strong>{conflict.field}</strong>: email “{conflict.email_value}” vs Acumatica “{conflict.acumatica_value}”
                  </div>
                ))}
                {(email.attachments || []).map((attachment) => (
                  <div key={attachment.id} className="mt-2 text-xs text-muted-foreground">
                    Attachment: {attachment.name || "unnamed"} · {attachment.extraction_status}
                    {attachment.extraction_error ? ` (${attachment.extraction_error})` : ""}
                  </div>
                ))}
              </div>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              <Input
                className="min-w-[260px] flex-1"
                placeholder="Required review reason"
                value={reasons[email.id] || ""}
                onChange={(event) => setReasons((current) => ({ ...current, [email.id]: event.target.value }))}
              />
              {(["approved", "acknowledged", "rejected"] as const).map((decision) => (
                <Button
                  key={decision}
                  size="sm"
                  variant={decision === "approved" ? "default" : "outline"}
                  disabled={review.isPending || (reasons[email.id] || "").trim().length < 3}
                  onClick={() => review.mutate({ emailId: email.id, decision, reason: reasons[email.id].trim() })}
                >
                  {decision[0].toUpperCase() + decision.slice(1)}
                </Button>
              ))}
            </div>
          </div>
        ))}
      </div>
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

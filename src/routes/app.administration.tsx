import { createFileRoute } from "@tanstack/react-router";
import { Activity, AlertTriangle, Database, FlaskConical, History, KeyRound, Mail, Plus, RefreshCw, Search, ShieldCheck, ToggleLeft, Trash2, X } from "lucide-react";
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
  useDeleteAiKey,
  useDeleteEmailImportConfig,
  useEmailImportConfigs,
  useMatchHistory,
  useMatchOrders,
  useNotificationRules,
  usePermissions,
  usePreviewCustomer,
  usePreviewOrder,
  useReconciliation,
  useRoles,
  useSaveAiKey,
  useSaveEmailImportConfig,
  useSyncCustomerOrders,
  useSyncCustomers,
  useSyncLogs,
  useSyncOrders,
  useTestSender,
  useToggleNotificationRule,
  useUpdateAcumatica,
  useUpdateReconciliationStatus,
  useValidateAcumatica,
  type EmailImportConfig,
} from "@/hooks/admin/useAdminSettings";
import { acumaticaSchema, aiKeySchema, type AcumaticaInput, type AiKeyInput } from "@/lib/admin-schemas";
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
          <TabsTrigger value="email-import">Email Import</TabsTrigger>
          <TabsTrigger value="audit">Audit Logs</TabsTrigger>
        </TabsList>

        <TabsContent value="acumatica"><AcumaticaPanel /></TabsContent>
        <TabsContent value="sync"><SyncPanel /></TabsContent>
        <TabsContent value="reconciliation"><ReconciliationPanel /></TabsContent>
        <TabsContent value="ai"><AiKeysPanel /></TabsContent>
        <TabsContent value="roles"><RolesPanel /></TabsContent>
        <TabsContent value="permissions"><PermissionsPanel /></TabsContent>
        <TabsContent value="notifications"><NotificationRulesPanel /></TabsContent>
        <TabsContent value="email-import"><EmailImportPanel /></TabsContent>
        <TabsContent value="audit"><AuditLogsPanel /></TabsContent>
      </Tabs>
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
  const tone = normalized === "connected" || normalized === "healthy" || normalized === "completed"
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

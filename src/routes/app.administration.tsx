import { createFileRoute } from "@tanstack/react-router";
import { Activity, Database, History, KeyRound, ShieldCheck, ToggleLeft } from "lucide-react";
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
  useAdminHealth,
  useAiKeys,
  useAuditLogs,
  useDeleteAiKey,
  useNotificationRules,
  usePermissions,
  useRoles,
  useSaveAiKey,
  useToggleNotificationRule,
  useUpdateAcumatica,
  useValidateAcumatica,
} from "@/hooks/admin/useAdminSettings";
import { acumaticaSchema, aiKeySchema, type AcumaticaInput, type AiKeyInput } from "@/lib/admin-schemas";

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
          <TabsTrigger value="ai">AI Keys</TabsTrigger>
          <TabsTrigger value="roles">Roles</TabsTrigger>
          <TabsTrigger value="permissions">Permissions</TabsTrigger>
          <TabsTrigger value="notifications">Notification Rules</TabsTrigger>
          <TabsTrigger value="audit">Audit Logs</TabsTrigger>
        </TabsList>

        <TabsContent value="acumatica"><AcumaticaPanel /></TabsContent>
        <TabsContent value="ai"><AiKeysPanel /></TabsContent>
        <TabsContent value="roles"><RolesPanel /></TabsContent>
        <TabsContent value="permissions"><PermissionsPanel /></TabsContent>
        <TabsContent value="notifications"><NotificationRulesPanel /></TabsContent>
        <TabsContent value="audit"><AuditLogsPanel /></TabsContent>
      </Tabs>
    </div>
  );
}

function HealthStrip() {
  const { data, isLoading, isError, refetch } = useAdminHealth();

  if (isLoading) {
    return <Skeleton className="h-16 w-full" />;
  }

  if (isError || !data) {
    return <ErrorBlock message="Admin health status could not be loaded." onRetry={() => refetch()} />;
  }

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

function AcumaticaPanel() {
  const { data, isLoading, isError, refetch } = useAcumatica();
  const update = useUpdateAcumatica();
  const validate = useValidateAcumatica();
  const [form, setForm] = useState<AcumaticaInput>({
    base_url: "",
    endpoint: "",
    version: "",
    tenant: "",
    username: "",
    token_url: "",
    password: "",
    client_id: "",
    client_secret: "",
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

      <MiniTable
        className="mt-4"
        headers={["Sync", "Status", "Records", "Started"]}
        rows={data.sync_logs.map((log) => [
          log.sync_type,
          log.status,
          String(log.record_count),
          formatDate(log.started_at) || "-",
        ])}
        empty="No Acumatica sync logs yet."
      />
    </Panel>
  );
}

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
  const tone = normalized === "connected" || normalized === "healthy"
    ? "border-success/40 bg-success/10 text-success"
    : normalized === "error"
      ? "border-destructive/40 bg-destructive/10 text-destructive"
      : "border-warning/40 bg-warning/10 text-warning-foreground";

  return <Badge variant="outline" className={tone}>{status}</Badge>;
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
  return date.toLocaleString();
}

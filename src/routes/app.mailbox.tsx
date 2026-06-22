import { createFileRoute, useSearch } from "@tanstack/react-router";
import {
  AlertTriangle,
  CheckCircle2,
  Filter,
  Inbox,
  Loader2,
  Mail,
  MailOpen,
  Plus,
  RefreshCw,
  Search,
  Settings,
  Trash2,
  Unplug,
  XCircle,
} from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  useCheckOAuth,
  useCreateEmailFilter,
  useDeleteEmailFilter,
  useDisconnectMailbox,
  useEmailFilters,
  useEmails,
  useMailboxAccounts,
  useStartOAuth,
  useSyncMailbox,
  useUpdateEmailFilter,
  type OAuthCheckResult,
} from "@/hooks/mailbox/useMailbox";
import type { CreateEmailFilterPayload, EmailFilter, EmailFilterType, EmailMessage, MailboxAccount } from "@/types/mailbox";

export const Route = createFileRoute("/app/mailbox")({
  head: () => ({ meta: [{ title: "Mailbox - Kim-Fay OrderWatch" }] }),
  validateSearch: (search: Record<string, string>) => ({
    connected: search.connected === "1" ? ("1" as const) : undefined,
    error: typeof search.error === "string" ? search.error : undefined,
  }),
  component: MailboxPage,
});

function MailboxPage() {
  const { connected, error } = useSearch({ from: "/app/mailbox" });

  useEffect(() => {
    if (connected === "1") {
      toast.success("Outlook account connected — initial sync queued.");
    }
    if (error) {
      toast.error(`OAuth error: ${error}`);
    }
  }, [connected, error]);

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Mailbox</h1>
        <p className="text-sm text-muted-foreground">
          Connect email accounts, browse incoming messages, and define smart filter rules.
        </p>
      </div>

      <Tabs defaultValue="inbox" className="space-y-4">
        <TabsList>
          <TabsTrigger value="inbox" className="gap-1.5">
            <Inbox className="h-3.5 w-3.5" />
            Inbox
          </TabsTrigger>
          <TabsTrigger value="filters" className="gap-1.5">
            <Filter className="h-3.5 w-3.5" />
            Filter Rules
          </TabsTrigger>
          <TabsTrigger value="accounts" className="gap-1.5">
            <Settings className="h-3.5 w-3.5" />
            Accounts
          </TabsTrigger>
        </TabsList>

        <TabsContent value="inbox">
          <InboxPanel />
        </TabsContent>
        <TabsContent value="filters">
          <FilterRulesPanel />
        </TabsContent>
        <TabsContent value="accounts">
          <AccountsPanel />
        </TabsContent>
      </Tabs>
    </div>
  );
}

// ─── Inbox Panel ─────────────────────────────────────────────────────────────

function InboxPanel() {
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [mailboxId, setMailboxId] = useState<number | undefined>();

  const { data: mailboxes } = useMailboxAccounts();
  const { data, isLoading, isError, refetch } = useEmails({
    search: debouncedSearch || undefined,
    mailbox_id: mailboxId,
  });

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 350);
    return () => clearTimeout(timer);
  }, [search]);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px]">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            className="pl-8"
            placeholder="Search subject, sender…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        {mailboxes && mailboxes.length > 1 && (
          <Select
            value={mailboxId !== undefined ? String(mailboxId) : "all"}
            onValueChange={(v) => setMailboxId(v === "all" ? undefined : Number(v))}
          >
            <SelectTrigger className="w-[200px]">
              <SelectValue placeholder="All mailboxes" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All mailboxes</SelectItem>
              {mailboxes.map((m) => (
                <SelectItem key={m.id} value={String(m.id)}>
                  {m.email}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}

        <Button variant="outline" size="icon" onClick={() => refetch()} title="Refresh">
          <RefreshCw className="h-4 w-4" />
        </Button>
      </div>

      {isLoading && (
        <div className="space-y-2">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-16 w-full rounded-md" />
          ))}
        </div>
      )}

      {isError && (
        <EmptyState icon={XCircle} title="Could not load emails" description="Check your connection and try again.">
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        </EmptyState>
      )}

      {!isLoading && !isError && data && (
        <>
          <p className="text-xs text-muted-foreground">
            {data.total} email{data.total !== 1 ? "s" : ""} found
            {debouncedSearch ? ` matching "${debouncedSearch}"` : ""}
          </p>

          {data.data.length === 0 ? (
            <EmptyState
              icon={Inbox}
              title="No emails yet"
              description={
                mailboxes && mailboxes.length === 0
                  ? "Connect an Outlook account in the Accounts tab to start syncing."
                  : "No emails match your current search."
              }
            />
          ) : (
            <div className="divide-y rounded-lg border bg-card">
              {data.data.map((email) => (
                <EmailRow key={email.id} email={email} />
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}

function EmailRow({ email }: { email: EmailMessage }) {
  const [expanded, setExpanded] = useState(false);

  return (
    <button
      type="button"
      className="w-full px-4 py-3 text-left transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
      onClick={() => setExpanded((v) => !v)}
    >
      <div className="flex items-start gap-3">
        <div className="mt-0.5 shrink-0">
          {email.is_read ? (
            <MailOpen className="h-4 w-4 text-muted-foreground" />
          ) : (
            <Mail className="h-4 w-4 text-primary" />
          )}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-baseline justify-between gap-2">
            <span className={`truncate text-sm ${email.is_read ? "text-muted-foreground" : "font-medium"}`}>
              {email.from_name || email.from_email || "Unknown sender"}
            </span>
            {email.received_at && (
              <span className="shrink-0 text-xs text-muted-foreground">
                {new Date(email.received_at).toLocaleString()}
              </span>
            )}
          </div>
          <div className={`truncate text-sm ${email.is_read ? "text-muted-foreground" : ""}`}>
            {email.subject || "(no subject)"}
          </div>
          {email.from_email && (
            <div className="mt-0.5 text-xs text-muted-foreground">{email.from_email}</div>
          )}
          {expanded && email.body_preview && (
            <p className="mt-2 text-xs text-muted-foreground leading-relaxed border-t pt-2">
              {email.body_preview}
            </p>
          )}
        </div>
      </div>
    </button>
  );
}

// ─── Filter Rules Panel ───────────────────────────────────────────────────────

function FilterRulesPanel() {
  const { data, isLoading, isError, refetch } = useEmailFilters();
  const [createOpen, setCreateOpen] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-4">
        <div>
          <p className="text-sm text-muted-foreground">
            Rules are evaluated against all synced emails. Each card shows a real-time match count.
          </p>
        </div>
        <Dialog open={createOpen} onOpenChange={setCreateOpen}>
          <DialogTrigger asChild>
            <Button size="sm" className="gap-1.5 shrink-0">
              <Plus className="h-3.5 w-3.5" />
              New Rule
            </Button>
          </DialogTrigger>
          <DialogContent>
            <CreateFilterDialog onSuccess={() => setCreateOpen(false)} />
          </DialogContent>
        </Dialog>
      </div>

      {isLoading && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-32 rounded-lg" />
          ))}
        </div>
      )}

      {isError && (
        <EmptyState icon={XCircle} title="Could not load filters" description="Try again.">
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        </EmptyState>
      )}

      {!isLoading && !isError && data && data.length === 0 && (
        <EmptyState
          icon={Filter}
          title="No filter rules yet"
          description="Create rules to track emails by sender domain, address, or subject keyword."
        >
          <Button size="sm" className="gap-1.5" onClick={() => setCreateOpen(true)}>
            <Plus className="h-3.5 w-3.5" />
            New Rule
          </Button>
        </EmptyState>
      )}

      {!isLoading && !isError && data && data.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.map((filter) => (
            <FilterRuleCard key={filter.id} filter={filter} />
          ))}
        </div>
      )}
    </div>
  );
}

const FILTER_TYPE_LABELS: Record<EmailFilterType, string> = {
  sender_email: "Sender Email",
  sender_domain: "Sender Domain",
  subject_keyword: "Subject Keyword",
};

function FilterRuleCard({ filter }: { filter: EmailFilter }) {
  const update = useUpdateEmailFilter();
  const remove = useDeleteEmailFilter();
  const [editOpen, setEditOpen] = useState(false);

  return (
    <Card className={filter.is_active ? "" : "opacity-60"}>
      <CardHeader className="pb-2">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <CardTitle className="text-sm font-medium leading-tight">{filter.name}</CardTitle>
            <Badge variant="outline" className="mt-1 text-[10px]">
              {FILTER_TYPE_LABELS[filter.type]}
            </Badge>
          </div>
          <div className="shrink-0 text-right">
            <div className="text-2xl font-bold tabular-nums text-primary">{filter.match_count}</div>
            <div className="text-[10px] text-muted-foreground leading-tight">matches</div>
          </div>
        </div>
      </CardHeader>
      <CardContent className="pt-0 space-y-2">
        <p className="font-mono text-xs text-muted-foreground break-all">{filter.value}</p>
        <div className="flex items-center justify-between gap-2 pt-1">
          <div className="flex items-center gap-1.5">
            <Switch
              checked={filter.is_active}
              disabled={update.isPending}
              onCheckedChange={(is_active) => update.mutate({ id: filter.id, is_active })}
            />
            <span className="text-xs text-muted-foreground">{filter.is_active ? "Active" : "Paused"}</span>
          </div>
          <div className="flex gap-1">
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
              <DialogTrigger asChild>
                <Button variant="ghost" size="icon" className="h-7 w-7">
                  <Settings className="h-3.5 w-3.5" />
                </Button>
              </DialogTrigger>
              <DialogContent>
                <EditFilterDialog filter={filter} onSuccess={() => setEditOpen(false)} />
              </DialogContent>
            </Dialog>
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7 text-destructive hover:text-destructive"
              disabled={remove.isPending}
              onClick={() => remove.mutate(filter.id)}
            >
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function CreateFilterDialog({ onSuccess }: { onSuccess: () => void }) {
  const create = useCreateEmailFilter();
  const [form, setForm] = useState<CreateEmailFilterPayload>({
    name: "",
    type: "sender_domain",
    value: "",
    is_active: true,
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    if (!form.name.trim() || !form.value.trim()) {
      toast.error("Name and value are required.");
      return;
    }
    create.mutate(form, { onSuccess });
  }

  return (
    <form onSubmit={submit}>
      <DialogHeader>
        <DialogTitle>New Filter Rule</DialogTitle>
      </DialogHeader>
      <div className="mt-4 grid gap-3">
        <FilterFormFields form={form} setForm={setForm} />
      </div>
      <DialogFooter className="mt-4">
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : null}
          Create Filter
        </Button>
      </DialogFooter>
    </form>
  );
}

function EditFilterDialog({ filter, onSuccess }: { filter: EmailFilter; onSuccess: () => void }) {
  const update = useUpdateEmailFilter();
  const [form, setForm] = useState<CreateEmailFilterPayload>({
    name: filter.name,
    type: filter.type,
    value: filter.value,
    is_active: filter.is_active,
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    update.mutate({ id: filter.id, ...form }, { onSuccess });
  }

  return (
    <form onSubmit={submit}>
      <DialogHeader>
        <DialogTitle>Edit Filter Rule</DialogTitle>
      </DialogHeader>
      <div className="mt-4 grid gap-3">
        <FilterFormFields form={form} setForm={setForm} />
      </div>
      <DialogFooter className="mt-4">
        <Button type="submit" disabled={update.isPending}>
          {update.isPending ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : null}
          Save Changes
        </Button>
      </DialogFooter>
    </form>
  );
}

function FilterFormFields({
  form,
  setForm,
}: {
  form: CreateEmailFilterPayload;
  setForm: React.Dispatch<React.SetStateAction<CreateEmailFilterPayload>>;
}) {
  const placeholders: Record<EmailFilterType, string> = {
    sender_email: "e.g. alerts@github.com",
    sender_domain: "e.g. gmail.com",
    subject_keyword: "e.g. invoice",
  };

  return (
    <>
      <div className="grid gap-1.5">
        <Label htmlFor="filter-name">Rule Name</Label>
        <Input
          id="filter-name"
          value={form.name}
          placeholder="e.g. Gmail Senders"
          onChange={(e) => setForm((v) => ({ ...v, name: e.target.value }))}
        />
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor="filter-type">Filter By</Label>
        <Select
          value={form.type}
          onValueChange={(v) => setForm((prev) => ({ ...prev, type: v as EmailFilterType }))}
        >
          <SelectTrigger id="filter-type">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="sender_email">Sender Email (exact)</SelectItem>
            <SelectItem value="sender_domain">Sender Domain</SelectItem>
            <SelectItem value="subject_keyword">Subject Keyword</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor="filter-value">Value</Label>
        <Input
          id="filter-value"
          value={form.value}
          placeholder={placeholders[form.type]}
          onChange={(e) => setForm((v) => ({ ...v, value: e.target.value }))}
        />
        <p className="text-xs text-muted-foreground">
          {form.type === "sender_email" && "Exact email address match (case-insensitive)."}
          {form.type === "sender_domain" && "Matches any email ending in @domain. Enter only the domain part."}
          {form.type === "subject_keyword" && "Matches subjects containing this word or phrase (case-insensitive)."}
        </p>
      </div>
      <div className="flex items-center gap-2">
        <Switch
          id="filter-active"
          checked={form.is_active}
          onCheckedChange={(is_active) => setForm((v) => ({ ...v, is_active }))}
        />
        <Label htmlFor="filter-active">Active</Label>
      </div>
    </>
  );
}

// ─── Accounts Panel ───────────────────────────────────────────────────────────

function AccountsPanel() {
  const { data, isLoading, isError, refetch } = useMailboxAccounts();
  const startOAuth = useStartOAuth();
  const sync       = useSyncMailbox();
  const disconnect = useDisconnectMailbox();
  const checkOAuth = useCheckOAuth();

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          Connect an Outlook account via Microsoft OAuth 2.0. Tokens are stored encrypted; emails sync automatically.
        </p>
        <div className="flex shrink-0 gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-1.5"
            disabled={checkOAuth.isPending}
            onClick={() => checkOAuth.mutate()}
          >
            {checkOAuth.isPending
              ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
              : <CheckCircle2 className="h-3.5 w-3.5" />}
            Check OAuth
          </Button>
          <Button
            size="sm"
            className="gap-1.5"
            disabled={startOAuth.isPending}
            onClick={() => startOAuth.mutate()}
          >
            {startOAuth.isPending
              ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
              : <Plus className="h-3.5 w-3.5" />}
            Connect Outlook
          </Button>
        </div>
      </div>

      {/* OAuth check results */}
      {checkOAuth.data && <OAuthCheckPanel result={checkOAuth.data} />}

      {isLoading && (
        <div className="space-y-2">
          {Array.from({ length: 2 }).map((_, i) => (
            <Skeleton key={i} className="h-20 rounded-lg" />
          ))}
        </div>
      )}

      {isError && (
        <EmptyState icon={XCircle} title="Could not load accounts" description="Try again.">
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        </EmptyState>
      )}

      {!isLoading && !isError && data && data.length === 0 && (
        <EmptyState
          icon={Mail}
          title="No accounts connected"
          description="Click 'Connect Outlook' to authenticate with Microsoft and start syncing emails."
        />
      )}

      {!isLoading && !isError && data && data.length > 0 && (
        <div className="divide-y rounded-lg border bg-card">
          {data.map((account) => (
            <MailboxAccountRow
              key={account.id}
              account={account}
              onSync={() => sync.mutate(account.id)}
              onDisconnect={() => disconnect.mutate(account.id)}
              syncPending={sync.isPending}
              disconnectPending={disconnect.isPending}
            />
          ))}
        </div>
      )}

      <ProviderRoadmap />
    </div>
  );
}

function MailboxAccountRow({
  account,
  onSync,
  onDisconnect,
  syncPending,
  disconnectPending,
}: {
  account: MailboxAccount;
  onSync: () => void;
  onDisconnect: () => void;
  syncPending: boolean;
  disconnectPending: boolean;
}) {
  return (
    <div className="flex items-center justify-between gap-4 px-4 py-3">
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <AccountStatusIcon status={account.status} />
          <span className="font-medium text-sm">{account.email}</span>
          {account.display_name && account.display_name !== account.email && (
            <span className="text-xs text-muted-foreground">({account.display_name})</span>
          )}
        </div>
        <div className="mt-0.5 text-xs text-muted-foreground">
          {account.last_synced_at
            ? `Last synced ${new Date(account.last_synced_at).toLocaleString()}`
            : "Never synced"}
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          className="gap-1.5"
          onClick={onSync}
          disabled={syncPending}
        >
          <RefreshCw className={`h-3.5 w-3.5 ${syncPending ? "animate-spin" : ""}`} />
          Sync
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="gap-1.5 text-destructive hover:text-destructive"
          onClick={onDisconnect}
          disabled={disconnectPending}
        >
          <Unplug className="h-3.5 w-3.5" />
          Disconnect
        </Button>
      </div>
    </div>
  );
}

function AccountStatusIcon({ status }: { status: MailboxAccount["status"] }) {
  if (status === "connected") return <CheckCircle2 className="h-4 w-4 text-green-500" />;
  if (status === "error") return <AlertTriangle className="h-4 w-4 text-destructive" />;
  return <XCircle className="h-4 w-4 text-muted-foreground" />;
}

function ProviderRoadmap() {
  const providers = [
    { name: "Outlook / Microsoft 365", status: "available" },
    { name: "Gmail / Google Workspace", status: "planned" },
    { name: "IMAP (generic)", status: "planned" },
  ];

  return (
    <div className="rounded-lg border bg-muted/30 p-4">
      <h4 className="mb-2 text-sm font-medium">Email Provider Support</h4>
      <div className="space-y-1.5">
        {providers.map((p) => (
          <div key={p.name} className="flex items-center justify-between text-sm">
            <span>{p.name}</span>
            <Badge variant={p.status === "available" ? "default" : "outline"} className="text-[10px]">
              {p.status === "available" ? "Available" : "Planned"}
            </Badge>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── OAuth check result panel ────────────────────────────────────────────────

function OAuthCheckPanel({ result }: { result: OAuthCheckResult }) {
  return (
    <div className={`rounded-lg border p-4 ${result.overall_ok ? "border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-950/30" : "border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30"}`}>
      <div className="mb-3 flex items-center gap-2">
        {result.overall_ok
          ? <CheckCircle2 className="h-4 w-4 text-green-600" />
          : <AlertTriangle className="h-4 w-4 text-amber-600" />}
        <span className="text-sm font-semibold">
          {result.overall_ok ? "OAuth configuration looks good" : "Issues found — see details below"}
        </span>
        <span className="ml-auto text-[10px] text-muted-foreground">
          Checked {new Date(result.checked_at).toLocaleTimeString()}
        </span>
      </div>

      {/* Credential checks */}
      <div className="space-y-2">
        {Object.entries(result.checks).map(([key, check]) => (
          <div key={key} className="flex items-start gap-2 text-sm">
            {check.ok
              ? <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-green-500" />
              : <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-red-500" />}
            <div>
              <span className="font-medium">{check.label}</span>
              <span className="ml-2 text-xs text-muted-foreground">{check.detail}</span>
            </div>
          </div>
        ))}
      </div>

      {/* Per-mailbox token checks */}
      {result.mailbox_tokens.length > 0 && (
        <div className="mt-3 border-t pt-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Connected mailbox tokens</p>
          <div className="space-y-1.5">
            {result.mailbox_tokens.map((m) => (
              <div key={m.email} className="flex items-start gap-2 text-sm">
                {m.ok
                  ? <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-green-500" />
                  : <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-red-500" />}
                <div>
                  <span className="font-medium">{m.email}</span>
                  <span className="ml-2 text-xs text-muted-foreground">{m.detail}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {result.mailbox_tokens.length === 0 && result.overall_ok && (
        <p className="mt-3 border-t pt-3 text-xs text-muted-foreground">
          No mailboxes connected yet — click <strong>Connect Outlook</strong> to start the OAuth flow.
        </p>
      )}
    </div>
  );
}

// ─── Shared helpers ───────────────────────────────────────────────────────────

function EmptyState({
  icon: Icon,
  title,
  description,
  children,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  description: string;
  children?: React.ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 rounded-lg border bg-card py-12 text-center">
      <Icon className="h-8 w-8 text-muted-foreground/50" />
      <div>
        <p className="font-medium text-sm">{title}</p>
        <p className="mt-1 text-xs text-muted-foreground max-w-xs">{description}</p>
      </div>
      {children}
    </div>
  );
}

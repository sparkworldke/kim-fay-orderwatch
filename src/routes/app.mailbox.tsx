import { createFileRoute, useSearch } from "@tanstack/react-router";
import {
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  Filter,
  FolderTree,
  Inbox,
  Loader2,
  Mail,
  MailOpen,
  Minus,
  OctagonX,
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
  useMailboxFolders,
  useDiscoverMailboxFolders,
  useUpdateMailboxFolder,
  useSaveFolderRule,
  useDeleteFolderRule,
  useTestMailboxFolder,
  useIngestionReviews,
  useReviewIngestion,
  useMailboxSyncLogs,
  useStartOAuth,
  useStopSync,
  useSyncMailbox,
  useSyncRule,
  useUpdateEmailFilter,
  type OAuthCheckResult,
} from "@/hooks/mailbox/useMailbox";
import { useAuth } from "@/lib/auth";
import { ApiError } from "@/lib/api";
import { useCustomers } from "@/hooks/useCustomers";
import type { CreateEmailFilterPayload, EmailFilter, EmailFilterCondition, EmailFilterType, EmailMessage, MailboxAccount, SyncLog } from "@/types/mailbox";

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
  const { session } = useAuth();
  const isAdmin = session?.role === "Administrator";

  useEffect(() => {
    if (connected === "1") {
      toast.success("Outlook account connected — initial sync completed.");
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
          {isAdmin && (
            <TabsTrigger value="folders" className="gap-1.5">
              <FolderTree className="h-3.5 w-3.5" />
              Folders & Rules
            </TabsTrigger>
          )}
          {isAdmin && (
            <TabsTrigger value="accounts" className="gap-1.5">
              <Settings className="h-3.5 w-3.5" />
              Accounts
            </TabsTrigger>
          )}
        </TabsList>

        <TabsContent value="inbox">
          <InboxPanel />
        </TabsContent>
        <TabsContent value="filters">
          <FilterRulesPanel />
        </TabsContent>
        {isAdmin && (
          <TabsContent value="folders">
            <FoldersRulesPanel />
          </TabsContent>
        )}
        {isAdmin && (
          <TabsContent value="accounts">
            <AccountsPanel />
          </TabsContent>
        )}
      </Tabs>
    </div>
  );
}

function FoldersRulesPanel() {
  const { data: mailboxes = [] } = useMailboxAccounts();
  const [mailboxId, setMailboxId] = useState<number | null>(null);
  const [ruleDrafts, setRuleDrafts] = useState<Record<number, string>>({});
  const [reviewReasons, setReviewReasons] = useState<Record<number, string>>({});
  useEffect(() => {
    if (mailboxId === null && mailboxes.length) setMailboxId(mailboxes[0].id);
  }, [mailboxId, mailboxes]);
  const folders = useMailboxFolders(mailboxId);
  const discover = useDiscoverMailboxFolders();
  const updateFolder = useUpdateMailboxFolder();
  const saveRule = useSaveFolderRule();
  const deleteRule = useDeleteFolderRule();
  const testFolder = useTestMailboxFolder();
  const customers = useCustomers({ per_page: 200 });
  const reviews = useIngestionReviews();
  const review = useReviewIngestion();

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <CardTitle className="text-base">Outlook folders and existing rules</CardTitle>
              <p className="mt-1 text-xs text-muted-foreground">Inbox is always synced. Other folders require explicit enablement; names are never trusted automatically.</p>
            </div>
            <div className="flex gap-2">
              <Select value={mailboxId ? String(mailboxId) : ""} onValueChange={(value) => setMailboxId(Number(value))}>
                <SelectTrigger className="w-56"><SelectValue placeholder="Select mailbox" /></SelectTrigger>
                <SelectContent>{mailboxes.map((mailbox) => <SelectItem key={mailbox.id} value={String(mailbox.id)}>{mailbox.email}</SelectItem>)}</SelectContent>
              </Select>
              <Button variant="outline" disabled={!mailboxId || discover.isPending} onClick={() => mailboxId && discover.mutate(mailboxId)}>
                <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${discover.isPending ? "animate-spin" : ""}`} />Discover folders
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {folders.isLoading && <Skeleton className="h-32" />}
          {folders.data?.length === 0 && <p className="text-sm text-muted-foreground">No folders discovered yet.</p>}
          <div className="space-y-3">
            {folders.data?.map((folder) => {
              const rule = folder.rules[0];
              const ruleName = ruleDrafts[folder.id] ?? rule?.existing_rule_name ?? "";
              const isInbox = folder.display_name.toLowerCase() === "inbox";
              return (
                <div key={folder.id} className="rounded-md border p-3">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2 font-medium">
                        {folder.parent_display_name && <span className="text-muted-foreground">{folder.parent_display_name} /</span>}{folder.display_name}
                        {folder.suggested_order_folder && <Badge variant="outline">Order-folder suggestion</Badge>}
                      </div>
                      <div className="mt-1 text-xs text-muted-foreground">{folder.total_item_count} messages · {folder.unread_item_count} unread · {folder.last_synced_at ? `synced ${new Date(folder.last_synced_at).toLocaleString("en-KE")}` : "never synced"}</div>
                      {folder.last_sync_error && <div className="mt-1 text-xs text-destructive">{folder.last_sync_error}</div>}
                    </div>
                    <Button size="sm" variant="ghost" disabled={testFolder.isPending} onClick={() => testFolder.mutate(folder.id)}>Test folder</Button>
                  </div>
                  <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <label className="flex items-center gap-2 text-xs"><Switch checked={folder.is_sync_enabled} disabled={isInbox} onCheckedChange={(value) => updateFolder.mutate({ id: folder.id, is_sync_enabled: value })} />Sync enabled</label>
                    <label className="flex items-center gap-2 text-xs"><Switch checked={folder.is_order_folder} onCheckedChange={(value) => updateFolder.mutate({ id: folder.id, is_order_folder: value })} />Order folder</label>
                    <Select value={folder.trust_level} onValueChange={(value) => updateFolder.mutate({ id: folder.id, trust_level: value as typeof folder.trust_level })}>
                      <SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="untrusted">Untrusted</SelectItem><SelectItem value="standard">Standard</SelectItem><SelectItem value="trusted_order">Trusted order</SelectItem></SelectContent>
                    </Select>
                    <Select value={folder.customer_id ? String(folder.customer_id) : "none"} onValueChange={(value) => updateFolder.mutate({ id: folder.id, customer_id: value === "none" ? null : Number(value) })}>
                      <SelectTrigger><SelectValue placeholder="Map customer" /></SelectTrigger><SelectContent><SelectItem value="none">No customer mapping</SelectItem>{customers.data?.data.map((customer) => <SelectItem key={customer.id} value={String(customer.id)}>{customer.name}</SelectItem>)}</SelectContent>
                    </Select>
                    <Input type="number" min={0} max={1000} value={folder.sync_priority} onChange={(event) => updateFolder.mutate({ id: folder.id, sync_priority: Number(event.target.value) })} title="Lower priority syncs first" />
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Input className="min-w-64 flex-1" placeholder="Existing Outlook rule name (manual metadata)" value={ruleName} onChange={(event) => setRuleDrafts((current) => ({ ...current, [folder.id]: event.target.value }))} />
                    {rule && <label className="flex items-center gap-2 text-xs"><Switch checked={rule.is_enabled} onCheckedChange={(value) => saveRule.mutate({ id: rule.id, mailbox_folder_id: folder.id, existing_rule_name: ruleName, is_enabled: value, is_trusted: rule.is_trusted })} />Enabled</label>}
                    <label className="flex items-center gap-2 text-xs"><Switch checked={rule?.is_trusted ?? false} onCheckedChange={(value) => rule && saveRule.mutate({ id: rule.id, mailbox_folder_id: folder.id, existing_rule_name: ruleName, is_enabled: rule.is_enabled, is_trusted: value })} />Trusted metadata</label>
                    <Button size="sm" disabled={ruleName.trim().length < 2 || saveRule.isPending} onClick={() => saveRule.mutate({ id: rule?.id, mailbox_folder_id: folder.id, existing_rule_name: ruleName.trim(), is_enabled: rule?.is_enabled ?? true, is_trusted: rule?.is_trusted ?? false })}>Save rule name</Button>
                    {rule && <Button size="sm" variant="ghost" className="text-destructive" disabled={deleteRule.isPending} onClick={() => deleteRule.mutate(rule.id)}><Trash2 className="h-3.5 w-3.5" /></Button>}
                  </div>
                </div>
              );
            })}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle className="text-base">Ingestion review</CardTitle></CardHeader>
        <CardContent className="space-y-2">
          {reviews.data?.data.length === 0 && <p className="text-sm text-muted-foreground">No folder-context emails need review.</p>}
          {reviews.data?.data.map((email) => (
            <div key={email.id} className="rounded border p-3 text-sm">
              <div className="font-medium">{email.subject || "(no subject)"}</div>
              <div className="text-xs text-muted-foreground">{email.mailbox_folder?.display_name || email.folder} · {(email.ingestion_reason_codes || []).join(", ")}</div>
              <div className="mt-2 flex flex-wrap gap-2"><Input className="min-w-64 flex-1" placeholder="Required review reason" value={reviewReasons[email.id] || ""} onChange={(event) => setReviewReasons((current) => ({ ...current, [email.id]: event.target.value }))} /><Button size="sm" disabled={(reviewReasons[email.id] || "").trim().length < 3} onClick={() => review.mutate({ emailId: email.id, decision: "approved", reason: reviewReasons[email.id] })}>Approve for PO processing</Button><Button size="sm" variant="outline" disabled={(reviewReasons[email.id] || "").trim().length < 3} onClick={() => review.mutate({ emailId: email.id, decision: "rejected", reason: reviewReasons[email.id] })}>Mark non-order</Button></div>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}

// ─── Inbox Panel ─────────────────────────────────────────────────────────────

function InboxPanel() {
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [mailboxId, setMailboxId] = useState<number | undefined>();
  const { session } = useAuth();
  const isAdmin = session?.role === "Administrator";

  const { data: mailboxes } = useMailboxAccounts(isAdmin);
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
                  ? isAdmin
                    ? "Connect an Outlook account in the Accounts tab to start syncing."
                    : "An administrator must connect a mailbox account before synced email import is available."
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
                {new Date(email.received_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}
              </span>
            )}
          </div>
          <div className={`truncate text-sm ${email.is_read ? "text-muted-foreground" : ""}`}>
            {email.subject || "(no subject)"}
          </div>
          {email.from_email && (
            <div className="mt-0.5 text-xs text-muted-foreground">{email.from_email}</div>
          )}
          <div className="mt-1 flex flex-wrap gap-1">
            <Badge variant="outline" className="text-[10px]">{email.folder}</Badge>
            {email.ingestion_classification && (
              <Badge variant={email.ingestion_classification === "po_processing" ? "default" : "secondary"} className="text-[10px]">
                {email.ingestion_classification.replaceAll("_", " ")}
              </Badge>
            )}
            {email.mailbox_folder?.rules?.[0] && <Badge variant="outline" className="text-[10px]">Rule: {email.mailbox_folder.rules[0].existing_rule_name}</Badge>}
          </div>
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
  const { session } = useAuth();
  const isAdmin = session?.role === "Administrator";
  const { data: mailboxes } = useMailboxAccounts(isAdmin);
  const sync = useSyncMailbox();
  const [createOpen, setCreateOpen] = useState(false);
  const [syncedIds, setSyncedIds] = useState<number[]>([]);

  function syncAll() {
    const accounts = mailboxes ?? [];
    setSyncedIds(accounts.map((a) => a.id));
    accounts.forEach((a) => sync.mutate(a.id, { onSettled: () => setSyncedIds((ids) => ids.filter((id) => id !== a.id)) }));
  }

  const hasSyncing = syncedIds.length > 0;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="space-y-0.5">
          <p className="text-sm text-muted-foreground">
            A normal inbox sync imports every email. Rules help organize stored messages; using a rule card&apos;s Sync button performs an intentionally filtered import.
          </p>
          <p className="text-xs text-muted-foreground">
            Each card shows a real-time count of already-synced emails that match the rule.
          </p>
        </div>
        <div className="flex shrink-0 gap-2">
          {isAdmin && (
            <Button
              variant="outline"
              size="sm"
              className="gap-1.5"
              disabled={hasSyncing || !mailboxes?.length}
              onClick={syncAll}
              title={!mailboxes?.length ? "No mailbox connected" : "Sync inbox now"}
            >
              <RefreshCw className={`h-3.5 w-3.5 ${hasSyncing ? "animate-spin" : ""}`} />
              {hasSyncing ? "Syncing…" : "Sync Inbox"}
            </Button>
          )}
          <Dialog open={createOpen} onOpenChange={setCreateOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-1.5 shrink-0">
                <Plus className="h-3.5 w-3.5" />
                New Rule
              </Button>
            </DialogTrigger>
            <DialogContent>
              <CreateFilterDialog onSuccess={() => { setCreateOpen(false); refetch(); }} />
            </DialogContent>
          </Dialog>
        </div>
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
  sender_email: "Exact Email",
  sender_domain: "Sender Domain",
  subject_keyword: "Subject Contains",
  received_date: "Received Date",
  date_range: "Date Range",
};

function displayConditionValue(type: EmailFilterType, value: string): string {
  if (type === "received_date") {
    return new Date(value + "T12:00:00").toLocaleDateString("en-KE", { dateStyle: "medium" });
  }
  if (type === "date_range") {
    const [from = "", to = ""] = value.split("|");
    const fmt = (d: string) =>
      new Date(d + "T12:00:00").toLocaleDateString("en-KE", { dateStyle: "medium" });
    return `${fmt(from)} – ${fmt(to)}`;
  }
  return value;
}

function FilterRuleCard({ filter }: { filter: EmailFilter }) {
  const update    = useUpdateEmailFilter();
  const remove    = useDeleteEmailFilter();
  const syncRule  = useSyncRule();
  const [editOpen, setEditOpen] = useState(false);

  return (
    <Card className={filter.is_active ? "" : "opacity-60"}>
      <CardHeader className="pb-2">
        <div className="flex items-start justify-between gap-2">
          <CardTitle className="text-sm font-medium leading-tight">{filter.name}</CardTitle>
          <div className="shrink-0 text-right">
            <div className="text-2xl font-bold tabular-nums text-primary">{filter.match_count}</div>
            <div className="text-[10px] text-muted-foreground leading-tight">matches</div>
          </div>
        </div>
      </CardHeader>
      <CardContent className="pt-0 space-y-2">
        <div className="rounded-sm border bg-muted/20 divide-y overflow-hidden">
          {normalizeConditions(filter).map((c, i) => (
            <div key={i} className="flex items-center gap-0 text-xs">
              <span className="shrink-0 bg-muted/50 px-2 py-1 text-[10px] font-medium text-muted-foreground uppercase tracking-wide w-28 border-r">
                {FILTER_TYPE_LABELS[c.type]}
              </span>
              <span className="font-mono px-2 py-1 text-muted-foreground break-all flex-1">
                {displayConditionValue(c.type, c.value)}
              </span>
            </div>
          ))}
        </div>
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
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 px-2 text-xs"
              disabled={syncRule.isPending || !filter.is_active}
              title={filter.is_active ? "Sync inbox using only this rule" : "Rule is paused"}
              onClick={() => syncRule.mutate(filter.id)}
            >
              <RefreshCw className={`h-3 w-3 ${syncRule.isPending ? "animate-spin" : ""}`} />
              Sync
            </Button>
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

// ─── Filter form types ───────────────────────────────────────────────────────

type ConditionRow = {
  type: EmailFilterType;
  value: string;
  value_to: string; // only used when type === "date_range"
};

type FilterFormState = {
  name: string;
  conditions: ConditionRow[];
  is_active: boolean;
};

type ConditionRowErrors = {
  type?: string;
  value?: string;
  value_to?: string;
};

type FilterFormErrors = {
  form?: string;
  name?: string;
  conditions: ConditionRowErrors[];
};

const DEFAULT_CONDITION: ConditionRow = { type: "sender_domain", value: "", value_to: "" };

function normalizeConditions(filter: EmailFilter): EmailFilterCondition[] {
  if (filter.conditions?.length) return filter.conditions;
  // Old server response: top-level type + value
  if (filter.type) return [{ type: filter.type, value: filter.value ?? "" }];
  return [];
}

function deserializeConditions(filter: EmailFilter): ConditionRow[] {
  const conditions = normalizeConditions(filter);
  if (!conditions.length) return [{ ...DEFAULT_CONDITION }];
  return conditions.map((c) => {
    if (c.type === "date_range") {
      const [from = "", to = ""] = c.value.split("|");
      return { type: c.type, value: from, value_to: to };
    }
    return { type: c.type, value: c.value, value_to: "" };
  });
}

function buildPayload(form: FilterFormState): CreateEmailFilterPayload {
  const conditions = form.conditions.map((c) => ({
    type: c.type,
    value: c.type === "date_range" ? `${c.value}|${c.value_to}` : c.value.trim(),
  }));

  const primaryCondition = conditions[0];

  return {
    name: form.name.trim(),
    conditions,
    ...(primaryCondition ? { type: primaryCondition.type, value: primaryCondition.value } : {}),
    is_active: form.is_active,
  };
}

function emptyFormErrors(form: FilterFormState): FilterFormErrors {
  return {
    conditions: form.conditions.map(() => ({})),
  };
}

function hasFormErrors(errors: FilterFormErrors): boolean {
  return Boolean(
    errors.form ||
    errors.name ||
    errors.conditions.some((condition) => condition.type || condition.value || condition.value_to),
  );
}

function validateForm(form: FilterFormState): FilterFormErrors {
  const errors = emptyFormErrors(form);

  if (!form.name.trim()) {
    errors.name = "Rule name is required.";
  }

  if (!form.conditions.length) {
    errors.form = "Add at least one condition.";
    return errors;
  }

  form.conditions.forEach((condition, index) => {
    const conditionErrors = errors.conditions[index] ?? {};

    if (!condition.type) {
      conditionErrors.type = "Condition type is required.";
    }

    if (condition.type === "date_range") {
      if (!condition.value) {
        conditionErrors.value = "From date is required.";
      }
      if (!condition.value_to) {
        conditionErrors.value_to = "To date is required.";
      }
      if (condition.value && condition.value_to && condition.value > condition.value_to) {
        conditionErrors.value_to = "To date must be on or after the from date.";
      }
    } else if (!condition.value.trim()) {
      conditionErrors.value = "Condition value is required.";
    }

    errors.conditions[index] = conditionErrors;
  });

  if (!errors.form && hasFormErrors(errors)) {
    errors.form = "Please fix the highlighted fields before submitting.";
  }

  return errors;
}

// ─── Dialogs ─────────────────────────────────────────────────────────────────

function CreateFilterDialog({ onSuccess }: { onSuccess: () => void }) {
  const create = useCreateEmailFilter();
  const [form, setForm] = useState<FilterFormState>({
    name: "",
    conditions: [{ ...DEFAULT_CONDITION }],
    is_active: true,
  });
  const [showErrors, setShowErrors] = useState(false);
  const validation = validateForm(form);
  const errors = showErrors ? validation : emptyFormErrors(form);

  function submit(e: FormEvent) {
    e.preventDefault();
    setShowErrors(true);
    if (hasFormErrors(validation)) {
      toast.error(validation.form ?? "Please fix the highlighted fields before submitting.");
      return;
    }
    create.mutate(buildPayload(form), { onSuccess });
  }

  return (
    <form onSubmit={submit}>
      <DialogHeader>
        <DialogTitle>New Filter Rule</DialogTitle>
      </DialogHeader>
      <div className="mt-4 space-y-4">
        <FilterFormFields form={form} setForm={setForm} errors={errors} />
      </div>
      <DialogFooter className="mt-4">
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : null}
          Create Rule
        </Button>
      </DialogFooter>
    </form>
  );
}

function EditFilterDialog({ filter, onSuccess }: { filter: EmailFilter; onSuccess: () => void }) {
  const update = useUpdateEmailFilter();
  const [form, setForm] = useState<FilterFormState>({
    name: filter.name,
    conditions: deserializeConditions(filter),
    is_active: filter.is_active,
  });
  const [showErrors, setShowErrors] = useState(false);
  const validation = validateForm(form);
  const errors = showErrors ? validation : emptyFormErrors(form);

  function submit(e: FormEvent) {
    e.preventDefault();
    setShowErrors(true);
    if (hasFormErrors(validation)) {
      toast.error(validation.form ?? "Please fix the highlighted fields before submitting.");
      return;
    }
    update.mutate({ id: filter.id, ...buildPayload(form) }, { onSuccess });
  }

  return (
    <form onSubmit={submit}>
      <DialogHeader>
        <DialogTitle>Edit Filter Rule</DialogTitle>
      </DialogHeader>
      <div className="mt-4 space-y-4">
        <FilterFormFields form={form} setForm={setForm} errors={errors} />
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

// ─── Form fields ─────────────────────────────────────────────────────────────

function FilterFormFields({
  form,
  setForm,
  errors,
}: {
  form: FilterFormState;
  setForm: React.Dispatch<React.SetStateAction<FilterFormState>>;
  errors: FilterFormErrors;
}) {
  function addCondition() {
    setForm((v) => ({ ...v, conditions: [...v.conditions, { ...DEFAULT_CONDITION }] }));
  }

  function removeCondition(i: number) {
    setForm((v) => ({ ...v, conditions: v.conditions.filter((_, idx) => idx !== i) }));
  }

  function updateCondition(i: number, patch: Partial<ConditionRow>) {
    setForm((v) => ({
      ...v,
      conditions: v.conditions.map((c, idx) =>
        idx === i ? { ...c, ...patch } : c
      ),
    }));
  }

  return (
    <>
      {errors.form && (
        <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive">
          {errors.form}
        </div>
      )}

      <div className="grid gap-1.5">
        <Label htmlFor="filter-name">Rule Name</Label>
        <Input
          id="filter-name"
          aria-invalid={Boolean(errors.name)}
          className={errors.name ? "border-destructive focus-visible:ring-destructive" : undefined}
          value={form.name}
          placeholder="e.g. Naivas Purchase Orders"
          onChange={(e) => setForm((v) => ({ ...v, name: e.target.value }))}
        />
        {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
      </div>

      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <Label>Conditions <span className="text-xs text-muted-foreground font-normal">(all must match)</span></Label>
          <Button type="button" variant="outline" size="sm" className="h-7 gap-1 text-xs" onClick={addCondition}>
            <Plus className="h-3 w-3" />
            Add
          </Button>
        </div>

        {form.conditions.map((condition, i) => (
          <ConditionRowField
            key={i}
            index={i}
            condition={condition}
            errors={errors.conditions[i] ?? {}}
            showRemove={form.conditions.length > 1}
            onChange={(patch) => updateCondition(i, patch)}
            onRemove={() => removeCondition(i)}
          />
        ))}
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

function ConditionRowField({
  index,
  condition,
  errors,
  showRemove,
  onChange,
  onRemove,
}: {
  index: number;
  condition: ConditionRow;
  errors: ConditionRowErrors;
  showRemove: boolean;
  onChange: (patch: Partial<ConditionRow>) => void;
  onRemove: () => void;
}) {
  const isDate = condition.type === "received_date" || condition.type === "date_range";

  return (
    <div className="rounded-md border bg-muted/30 p-3 space-y-2">
      <div className="flex items-center gap-2">
        <Select
          value={condition.type}
          onValueChange={(v) => onChange({ type: v as EmailFilterType, value: "", value_to: "" })}
        >
          <SelectTrigger
            aria-invalid={Boolean(errors.type)}
            className={`h-8 text-xs flex-1 ${errors.type ? "border-destructive focus:ring-destructive" : ""}`}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="sender_email">Exact Email Address</SelectItem>
            <SelectItem value="sender_domain">Sender Domain</SelectItem>
            <SelectItem value="subject_keyword">Subject Contains</SelectItem>
            <SelectItem value="received_date">Received Date</SelectItem>
            <SelectItem value="date_range">Date Range</SelectItem>
          </SelectContent>
        </Select>
        {showRemove && (
          <Button type="button" variant="ghost" size="icon" className="h-8 w-8 shrink-0 text-muted-foreground hover:text-destructive" onClick={onRemove}>
            <Minus className="h-3.5 w-3.5" />
          </Button>
        )}
      </div>
      {errors.type && <p className="text-xs text-destructive">{errors.type}</p>}

      {!isDate && (
        <>
          <Input
            aria-invalid={Boolean(errors.value)}
            className={`h-8 text-xs ${errors.value ? "border-destructive focus-visible:ring-destructive" : ""}`}
            value={condition.value}
            placeholder={
              condition.type === "sender_email" ? "e.g. orders@supplier.com" :
              condition.type === "sender_domain" ? "e.g. naivas.co.ke" :
              "e.g. purchase order"
            }
            onChange={(e) => onChange({ value: e.target.value })}
          />
          {errors.value && <p className="text-xs text-destructive">{errors.value}</p>}
        </>
      )}

      {condition.type === "received_date" && (
        <>
          <Input
            type="date"
            aria-invalid={Boolean(errors.value)}
            className={`h-8 text-xs ${errors.value ? "border-destructive focus-visible:ring-destructive" : ""}`}
            value={condition.value}
            onChange={(e) => onChange({ value: e.target.value })}
          />
          {errors.value && <p className="text-xs text-destructive">{errors.value}</p>}
        </>
      )}

      {condition.type === "date_range" && (
        <>
          <div className="flex items-center gap-2">
            <Input
              type="date"
              aria-invalid={Boolean(errors.value)}
              className={`h-8 text-xs ${errors.value ? "border-destructive focus-visible:ring-destructive" : ""}`}
              value={condition.value}
              onChange={(e) => onChange({ value: e.target.value })}
            />
            <span className="text-xs text-muted-foreground shrink-0">to</span>
            <Input
              type="date"
              aria-invalid={Boolean(errors.value_to)}
              className={`h-8 text-xs ${errors.value_to ? "border-destructive focus-visible:ring-destructive" : ""}`}
              value={condition.value_to}
              min={condition.value || undefined}
              onChange={(e) => onChange({ value_to: e.target.value })}
            />
          </div>
          {errors.value && <p className="text-xs text-destructive">{errors.value}</p>}
          {errors.value_to && <p className="text-xs text-destructive">{errors.value_to}</p>}

          {/* Warn when the end date is in the past — this causes ALL emails to be skipped */}
          {condition.value_to && condition.value_to < new Date().toISOString().slice(0, 10) && (
            <div className="flex items-start gap-2 rounded-md border border-amber-300/60 bg-amber-50/60 px-2.5 py-2 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">
              <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
              <div className="flex-1 text-[11px]">
                <span className="font-medium">This date range has expired.</span>
                {" "}Emails received after {condition.value_to} will all be skipped by this condition.
                {showRemove && (
                  <button
                    type="button"
                    className="ml-1 underline underline-offset-2 hover:no-underline"
                    onClick={onRemove}
                  >
                    Remove this condition
                  </button>
                )}
              </div>
            </div>
          )}
        </>
      )}

      <p className="text-[11px] text-muted-foreground">
        {condition.type === "sender_email" && "Exact address match (case-insensitive)."}
        {condition.type === "sender_domain" && "Matches any email from @domain — enter only the domain part."}
        {condition.type === "subject_keyword" && "Matches subjects containing this word or phrase."}
        {condition.type === "received_date" && "Emails received on this exact calendar date."}
        {condition.type === "date_range" && "Emails received between these dates (inclusive)."}
      </p>
    </div>
  );
}

// ─── Accounts Panel ───────────────────────────────────────────────────────────

function AccountsPanel() {
  const { session } = useAuth();
  const isAdmin = session?.role === "Administrator";
  const { data, isLoading, isError, error, refetch } = useMailboxAccounts(isAdmin);
  const startOAuth = useStartOAuth();
  const sync       = useSyncMailbox();
  const disconnect = useDisconnectMailbox();
  const checkOAuth = useCheckOAuth();
  const loadErrorMessage =
    error instanceof ApiError
      ? error.message
      : "Try again.";

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
            disabled={checkOAuth.isPending || !isAdmin}
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
            disabled={startOAuth.isPending || !isAdmin}
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

      {!isAdmin && (
        <EmptyState
          icon={XCircle}
          title="Administrator Access Required"
          description="Only administrators can view and manage connected mailbox accounts."
        />
      )}

      {isAdmin && isLoading && (
        <div className="space-y-2">
          {Array.from({ length: 2 }).map((_, i) => (
            <Skeleton key={i} className="h-20 rounded-lg" />
          ))}
        </div>
      )}

      {isAdmin && isError && (
        <EmptyState icon={XCircle} title="Could not load accounts" description={loadErrorMessage}>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        </EmptyState>
      )}

      {isAdmin && !isLoading && !isError && data && data.length === 0 && (
        <EmptyState
          icon={Mail}
          title="No accounts connected"
          description="Click 'Connect Outlook' to authenticate with Microsoft and start syncing emails."
        />
      )}

      {isAdmin && !isLoading && !isError && data && data.length > 0 && (
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
  const { data: logs } = useMailboxSyncLogs(account.id);
  const hasRunning = logs?.some((l) => l.status === "running") ?? false;

  return (
    <div className="px-4 py-3 space-y-3">
      {/* Account header */}
      <div className="flex items-center justify-between gap-4">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <AccountStatusIcon status={account.status} />
            <span className="font-medium text-sm">{account.email}</span>
            {account.display_name && account.display_name !== account.email && (
              <span className="text-xs text-muted-foreground">({account.display_name})</span>
            )}
            {hasRunning && (
              <span className="flex items-center gap-1 text-[11px] text-blue-500 font-medium">
                <Loader2 className="h-3 w-3 animate-spin" />
                Syncing…
              </span>
            )}
          </div>
          <div className="mt-0.5 text-xs text-muted-foreground">
            {account.last_synced_at
              ? `Last synced ${new Date(account.last_synced_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}`
              : "Never synced"}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-1.5"
            onClick={onSync}
            disabled={syncPending || hasRunning}
          >
            <RefreshCw className={`h-3.5 w-3.5 ${hasRunning ? "animate-spin" : ""}`} />
            {hasRunning ? "Syncing" : "Sync"}
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

      {/* Sync log */}
      <MailboxSyncLogs logs={logs ?? []} />
    </div>
  );
}

function MailboxSyncLogs({ logs }: { logs: SyncLog[] }) {
  if (!logs.length) {
    return (
      <p className="text-xs text-muted-foreground italic">
        No sync history yet — click Sync to start importing emails.
      </p>
    );
  }

  return (
    <div className="rounded-md border bg-muted/20 overflow-hidden">
      <div className="flex items-center gap-2 px-3 py-1.5 border-b bg-muted/40">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Sync History</span>
        <span className="text-[11px] text-muted-foreground">· {logs.length} run{logs.length !== 1 ? "s" : ""}</span>
      </div>
      <div className="divide-y">
        {logs.map((log) => (
          <SyncLogRow key={log.id} log={log} mailboxId={log.mailbox_account_id} />
        ))}
      </div>
    </div>
  );
}

function SyncLogRow({ log, mailboxId }: { log: SyncLog; mailboxId: number }) {
  const [expanded, setExpanded] = useState(false);
  const stopSync = useStopSync();
  const started = new Date(log.started_at);
  const duration =
    log.ended_at
      ? Math.round((new Date(log.ended_at).getTime() - started.getTime()) / 1000)
      : null;

  const fmtDate = started.toLocaleString("en-KE", {
    timeZone: "Africa/Nairobi",
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });

  return (
    <div className="text-xs">
      <div className="flex flex-wrap items-center gap-2 px-3 py-2">
      {log.status === "running"   && <Loader2      className="h-3 w-3 animate-spin text-blue-500 shrink-0" />}
      {log.status === "completed" && <CheckCircle2 className="h-3 w-3 text-green-500 shrink-0" />}
      {log.status === "failed"    && <XCircle      className="h-3 w-3 text-destructive shrink-0" />}
      {log.status === "stopped"   && <OctagonX     className="h-3 w-3 text-amber-500 shrink-0" />}

      <span className="shrink-0 text-muted-foreground w-32">{fmtDate}</span>

      <Badge variant="outline" className="h-5 text-[10px]">
        {log.sync_scope.type === "rule" ? log.sync_scope.filter_name || "Deleted rule" : "Full inbox"}
      </Badge>

      <span className="min-w-0 flex-1 text-muted-foreground">
        <span className="font-medium text-foreground">{log.emails_fetched} fetched</span>
        {" · "}{log.emails_created} created
        {" · "}{log.emails_updated} updated
        {" · "}<span className={log.emails_skipped ? "text-amber-600" : ""}>{log.emails_skipped} skipped</span>
        {log.emails_failed > 0 && <>{" · "}<span className="text-destructive">{log.emails_failed} failed</span></>}
      </span>

      {duration !== null && (
        <span className="shrink-0 text-muted-foreground">{duration}s</span>
      )}

      {log.status === "running" && (
        <Button
          variant="ghost"
          size="sm"
          className="h-6 gap-1 px-2 text-[10px] text-amber-600 hover:text-amber-700 hover:bg-amber-50"
          disabled={stopSync.isPending}
          onClick={() => stopSync.mutate({ mailboxId, logId: log.id })}
          title="Stop this sync after the current batch"
        >
          <OctagonX className="h-3 w-3" />
          {stopSync.isPending ? "Stopping…" : "Stop"}
        </Button>
      )}

      <Button variant="ghost" size="sm" className="h-6 gap-1 px-2 text-[10px]" onClick={() => setExpanded((value) => !value)}>
        {expanded ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
        {expanded ? "Hide details" : "View details"}
      </Button>
      </div>

      {log.status === "failed" && log.error_message && (
        <div className="border-t px-3 py-2 text-destructive">{log.error_message}</div>
      )}

      {expanded && (
        <div className="border-t bg-background/70 px-3 py-3">
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
            {[
              ["Fetched", log.emails_fetched],
              ["Created", log.emails_created],
              ["Updated", log.emails_updated],
              ["Skipped", log.emails_skipped],
              ["Deleted", log.emails_deleted],
              ["Failed", log.emails_failed],
            ].map(([label, count]) => (
              <div key={label} className="rounded border bg-muted/20 p-2 text-center">
                <div className="font-semibold tabular-nums">{count}</div>
                <div className="text-[10px] text-muted-foreground">{label}</div>
              </div>
            ))}
          </div>

          <div className="mt-3">
            <div className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Skipped reasons</div>
            {log.reason_counts.length > 0 ? (
              <div className="mt-1 space-y-1">
                {log.reason_counts.map((reason) => (
                  <div key={reason.code} className="flex items-center justify-between gap-3 rounded bg-muted/30 px-2 py-1.5">
                    <span>{reason.label}</span>
                    <Badge variant="secondary" className="tabular-nums">{reason.count}</Badge>
                  </div>
                ))}
              </div>
            ) : (
              <p className="mt-1 text-muted-foreground">No skipped emails in this run.</p>
            )}
          </div>
          {log.decision_counts.length > 0 && (
            <div className="mt-3">
              <div className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Ingestion decisions</div>
              <div className="mt-1 space-y-1">
                {log.decision_counts.map((decision) => (
                  <div key={`${decision.classification}-${decision.reason_code}-${decision.folder_name || "none"}`} className="flex items-center justify-between gap-3 rounded bg-muted/30 px-2 py-1.5">
                    <span>{decision.label}{decision.folder_name ? ` · ${decision.folder_name}` : ""}</span>
                    <Badge variant="secondary" className="tabular-nums">{decision.count}</Badge>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
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
          Checked {new Date(result.checked_at).toLocaleTimeString("en-KE", { timeZone: "Africa/Nairobi", hour: "2-digit", minute: "2-digit", hour12: false })}
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

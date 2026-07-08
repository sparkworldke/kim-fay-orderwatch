import { Mail, Plus, RefreshCw, ShieldCheck, Trash2 } from "lucide-react";
import type { ComponentType, ReactNode } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import {
  useApproveEmailImportConfig,
  useDeleteEmailImportConfig,
  useEmailImportConfigs,
  useEmailImportMetrics,
  useMatchHistory,
  useMatchOrders,
  usePendingMatchReviews,
  useReviewMatch,
  useSaveEmailImportConfig,
  useTestSender,
  type EmailImportConfig,
} from "@/hooks/admin/useAdminSettings";
import { useState } from "react";

// ─── Local primitives ─────────────────────────────────────────────────────────
// Self-contained presentation helpers so these panels have no hard dependency on
// Administration-specific rendering. Behavior and structure are preserved from the
// original Administration Email Import panel.

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

function MetricCard({ label, value, tone = "default" }: { label: string; value: string; tone?: "default" | "warn" | "danger" }) {
  const toneClass = tone === "danger"
    ? "border-red-300 bg-red-50 text-red-700"
    : tone === "warn"
      ? "border-amber-300 bg-amber-50 text-amber-700"
      : "border-border bg-muted/20";

  return (
    <div className={`rounded-md border p-3 ${toneClass}`}>
      <div className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</div>
      <div className="mt-1 text-lg font-semibold">{value}</div>
    </div>
  );
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
      <div className="text-sm font-medium">{message}</div>
      <Button className="mt-3" variant="outline" onClick={onRetry}>Retry</Button>
    </div>
  );
}

const BLANK_CONFIG: Partial<EmailImportConfig> = {
  sender_pattern: "",
  match_mode: "exact",
  is_wildcard: false,
  display_name: "",
  customer_id: null,
  branch_name: "",
  branch_tag_pattern: "",
  customer_class: "",
  po_patterns: [],
  po_extraction_source: "all",
  ai_fallback_enabled: true,
  is_active: true,
  notes: "",
};

// ─── Email Import tab: sender import configuration ────────────────────────────
// Sender rules, metrics, add/edit sender config, approvals, delete, and test sender address.

export function SenderImportPanel() {
  const { data, isLoading, isError, refetch } = useEmailImportConfigs();
  const metrics = useEmailImportMetrics();
  const approve = useApproveEmailImportConfig();
  const save    = useSaveEmailImportConfig();
  const remove  = useDeleteEmailImportConfig();
  const test    = useTestSender();

  const [editing, setEditing] = useState<Partial<EmailImportConfig> | null>(null);
  const [testEmail, setTestEmail] = useState("");

  function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    save.mutate(editing, { onSuccess: () => setEditing(null) });
  }

  return (
    <div className="space-y-4">
      <Panel title="Allowed Email Senders" icon={Mail}>
        <p className="mb-3 text-sm text-muted-foreground">
          Manage exact branch senders, wildcard domain rules, and regex-based Chandara aggregations with approval guardrails.
        </p>

        {metrics.data && (
          <div className="mb-4 grid gap-3 md:grid-cols-5">
            <MetricCard label="Imported 24h" value={String(metrics.data.imported_orders_last_24h)} />
            <MetricCard label="Unrecognized 24h" value={String(metrics.data.unrecognized_emails_last_24h)} tone={metrics.data.unrecognized_emails_last_24h > 0 ? "warn" : "default"} />
            <MetricCard label="Success Rate" value={`${metrics.data.success_rate.toFixed(2)}%`} tone={metrics.data.success_rate < 99 ? "danger" : "default"} />
            <MetricCard label="Pending Approvals" value={String(metrics.data.pending_approvals)} tone={metrics.data.pending_approvals > 0 ? "warn" : "default"} />
            <MetricCard label="Auto-Deactivated" value={String(metrics.data.auto_deactivated_configs)} />
          </div>
        )}

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
                  {["Sender", "Mode", "Customer", "Branch", "Approval", "Source", "Active", ""].map((h) => (
                    <th key={h} className="px-3 py-2 text-left">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.map((cfg) => (
                  <tr key={cfg.id} className="border-t">
                    <td className="px-3 py-2 font-mono text-xs">{cfg.sender_pattern}</td>
                    <td className="px-3 py-2 text-xs uppercase">{cfg.match_mode}</td>
                    <td className="px-3 py-2 text-xs">{cfg.customer?.name ?? cfg.display_name}</td>
                    <td className="px-3 py-2 text-xs">{cfg.branch_name || "—"}</td>
                    <td className="px-3 py-2 text-xs">
                      <Badge variant={cfg.approval_status === "approved" ? "default" : cfg.approval_status === "pending" ? "secondary" : "destructive"}>
                        {cfg.approval_status}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-xs capitalize">{cfg.po_extraction_source}</td>
                    <td className="px-3 py-2">
                      <StatusBadge status={cfg.is_active ? "active" : "inactive"} />
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex gap-1">
                        {cfg.approval_status === "pending" && (
                          <Button
                            size="sm"
                            variant="secondary"
                            className="h-6 px-2 text-[10px]"
                            onClick={() => approve.mutate(cfg.id)}
                            disabled={approve.isPending}
                          >
                            Approve
                          </Button>
                        )}
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
            <div className="grid gap-1.5">
              <Label>Match Mode</Label>
              <Select value={editing.match_mode ?? "exact"} onValueChange={(v) => setEditing((e) => ({ ...e!, match_mode: v as EmailImportConfig["match_mode"], is_wildcard: v !== "exact" }))}>
                <SelectTrigger><SelectValue placeholder="Select mode" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="exact">Exact branch sender</SelectItem>
                  <SelectItem value="wildcard">Wildcard domain</SelectItem>
                  <SelectItem value="regex">Regex pattern</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <Field label="Branch Name" value={editing.branch_name ?? ""} onChange={(v) => setEditing((e) => ({ ...e!, branch_name: v }))} placeholder="Chandarana Karen" />
            <Field label="Customer ID (optional)" value={String(editing.customer_id ?? "")} onChange={(v) => setEditing((e) => ({ ...e!, customer_id: v ? Number(v) : null }))} placeholder="Acumatica customer id row reference" />
            <Field label="Branch Tag Regex (optional)" value={editing.branch_tag_pattern ?? ""} onChange={(v) => setEditing((e) => ({ ...e!, branch_tag_pattern: v }))} placeholder="/^(.+)@chandara-supermarket\.com$/" />
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
              ? `✓ Matched — config: ${test.data.config?.display_name} (${test.data.config?.sender_pattern})${test.data.branch_tag ? ` · branch tag: ${test.data.branch_tag}` : ""}`
              : "✗ No active config matches this sender"}
          </div>
        )}
      </Panel>
    </div>
  );
}

// ─── Order Matching tab: run/history panel ────────────────────────────────────
// Extracts PO numbers from all unprocessed emails then cross-references against
// Acumatica sales orders. Updates match_status and flag_source on every affected order.

export function OrderMatchingPanel() {
  const history = useMatchHistory();
  const match   = useMatchOrders();

  return (
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
  );
}

// ─── PO / Email Match Review tab: guarded review queue ────────────────────────
// Decision and reason capture. Evidence is shown exactly as captured.

export function MatchReviewQueue() {
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

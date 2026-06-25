import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import {
  Check, ChevronDown, ChevronRight, Mail, RefreshCw, X,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  confidenceBadgeVariant,
  useAcceptMatch,
  useOrderMatchFolders,
  useOrderMatchQueue,
  useRejectMatch,
  useRunOrderMatchPipeline,
  useSyncOrderMatchFolder,
} from "@/hooks/useOrderMatch";

export const Route = createFileRoute("/app/order-match")({
  head: () => ({ meta: [{ title: "Order Match — Kim-Fay OrderWatch" }] }),
  component: OrderMatchPage,
});

function today() { return new Date().toISOString().slice(0, 10); }
function daysAgo(n: number) {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d.toISOString().slice(0, 10);
}

function OrderMatchPage() {
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [syncFolderId, setSyncFolderId] = useState<number | null>(null);
  const [dateFrom, setDateFrom] = useState(daysAgo(7));
  const [dateTo, setDateTo] = useState(today());
  const [queueStatus, setQueueStatus] = useState<"pending" | "accepted" | "rejected" | "all">("pending");

  const folders = useOrderMatchFolders();
  const queue = useOrderMatchQueue(queueStatus);
  const syncFolder = useSyncOrderMatchFolder();
  const runPipeline = useRunOrderMatchPipeline();
  const accept = useAcceptMatch();
  const reject = useRejectMatch();

  function toggleGroup(id: string) {
    setExpanded((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function handleSync() {
    if (!syncFolderId) {
      toast.error("Select a folder to sync");
      return;
    }
    syncFolder.mutate(
      { folderId: syncFolderId, from: dateFrom, to: dateTo },
      {
        onSuccess: () => toast.success("Folder sync completed"),
        onError: (e: Error) => toast.error(e.message),
      },
    );
  }

  function handleAccept(emailId: number, confidence?: number) {
    const needsConfirm = confidence !== undefined && confidence < 0.75;
    if (needsConfirm && !window.confirm("AI confidence is below 75%. Accept anyway?")) {
      return;
    }
    accept.mutate(
      { emailId, confirmLow: needsConfirm },
      {
        onSuccess: () => toast.success("Match accepted"),
        onError: (e: Error) => toast.error(e.message),
      },
    );
  }

  function handleReject(emailId: number) {
    const reason = window.prompt("Rejection reason (required):");
    if (!reason || reason.length < 3) return;
    reject.mutate(
      { emailId, reason },
      { onSuccess: () => toast.success("Match rejected"), onError: (e: Error) => toast.error(e.message) },
    );
  }

  const groups = queue.data?.groups ?? [];

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Order Match</h1>
          <p className="text-sm text-muted-foreground">
            AI-powered email-to-sales-order matching — admin accept required before any write
          </p>
        </div>
        <Button onClick={() => runPipeline.mutate(undefined, {
          onSuccess: (res) => {
            const ext = (res as { extraction?: { extracted?: number }; ai_scored?: number; ai_skipped?: number })?.extraction;
            const scored = (res as { ai_scored?: number })?.ai_scored ?? 0;
            const skipped = (res as { ai_skipped?: number })?.ai_skipped ?? 0;
            toast.success(
              `Pipeline complete: ${ext?.extracted ?? 0} POs extracted, ${scored} AI-scored${skipped ? ` (${skipped} deferred)` : ""}`,
            );
            queue.refetch();
          },
          onError: (e: Error) => toast.error(e.message),
        })} disabled={runPipeline.isPending}>
          <RefreshCw className={`mr-2 h-4 w-4 ${runPipeline.isPending ? "animate-spin" : ""}`} />
          Run match pipeline
        </Button>
      </div>

      <div className="rounded-lg border p-4">
        <h2 className="mb-3 font-medium">Folder sync</h2>
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[200px]">
            <Label>Folder</Label>
            <select
              className="flex h-9 w-full rounded-md border bg-background px-3 text-sm"
              value={syncFolderId ?? ""}
              onChange={(e) => {
                const id = e.target.value ? Number(e.target.value) : null;
                setSyncFolderId(id);
                if (id) {
                  const folder = (folders.data?.folders ?? []).find((f) => f.id === id);
                  const from = folder?.last_synced_at
                    ? folder.last_synced_at.slice(0, 10)
                    : daysAgo(7);
                  setDateFrom(from);
                  setDateTo(today());
                }
              }}
            >
              <option value="">Select folder…</option>
              {(folders.data?.folders ?? []).map((f) => (
                <option key={f.id} value={f.id}>{f.folder_name} ({f.mailbox_email})</option>
              ))}
            </select>
          </div>
          <div>
            <Label>From</Label>
            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          </div>
          <div>
            <Label>To</Label>
            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
          </div>
          <Button onClick={handleSync} disabled={syncFolder.isPending}>
            Sync now
          </Button>
        </div>
      </div>

      <div className="rounded-lg border">
        <div className="flex flex-wrap items-center gap-3 border-b px-4 py-3">
          <Mail className="h-4 w-4 text-muted-foreground" />
          <h2 className="font-medium">Review queue</h2>
          <Badge variant="outline">{queue.data?.total ?? 0} {queueStatus === "all" ? "total" : queueStatus}</Badge>
          <div className="ml-auto flex rounded-lg border bg-muted/40 p-1 gap-1">
            {(["pending", "accepted", "rejected", "all"] as const).map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => setQueueStatus(s)}
                className={`rounded-md px-3 py-1.5 text-sm font-medium capitalize transition-all ${
                  queueStatus === s ? "bg-background text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {s}
              </button>
            ))}
          </div>
        </div>

        {queue.isLoading && <div className="p-4"><Skeleton className="h-20 w-full" /></div>}

        {!queue.isLoading && groups.length === 0 && (
          <p className="p-6 text-center text-sm text-muted-foreground">No emails in the review queue</p>
        )}

        {groups.map((group) => {
          const open = expanded.has(group.main_account_id);
          return (
            <div key={group.main_account_id} className="border-b last:border-b-0">
              <button
                type="button"
                className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/30"
                onClick={() => toggleGroup(group.main_account_id)}
              >
                {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                <span className="flex-1 font-medium">{group.main_account_name}</span>
                <span className="text-sm text-muted-foreground">{group.email_count} emails</span>
                <span className="text-sm font-medium">KES {group.revenue_at_risk.toLocaleString()}</span>
              </button>

              {open && (
                <div className="divide-y bg-muted/10">
                  {group.emails.map((email) => (
                    <div key={email.id} className="flex flex-wrap items-start gap-3 px-6 py-3">
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{email.subject ?? "(no subject)"}</p>
                        <p className="text-xs text-muted-foreground">
                          {email.from_name ?? email.from_email} · PO: {email.canonical_po ?? "—"}
                        </p>
                        {email.duplicate_flag && (
                          <Badge variant="destructive" className="mt-1">{email.duplicate_flag}</Badge>
                        )}
                        {email.match_status === "auto_matched" && (
                          <Badge className="mt-1">AUTO-MATCH</Badge>
                        )}
                      </div>
                      {email.top_prediction && (
                        <Badge variant={confidenceBadgeVariant(email.top_prediction.label)}>
                          {email.top_prediction.order_nbr} · {(email.top_prediction.confidence * 100).toFixed(0)}%
                        </Badge>
                      )}
                      <div className="flex gap-1">
                        <Button size="sm" variant="outline" onClick={() => handleAccept(email.id, email.top_prediction?.confidence)}>
                          <Check className="h-3 w-3" />
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => handleReject(email.id)}>
                          <X className="h-3 w-3" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
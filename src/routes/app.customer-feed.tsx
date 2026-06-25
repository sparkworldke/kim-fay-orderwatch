import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import {
  Building2, ChevronDown, ChevronRight, Lightbulb, RefreshCw, Search, Users,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  formatCompletionTime,
  fillRateTone,
  useCustomerFeed,
  useCustomerFeedInsights,
  type CustomerFeedGroup,
  type CustomerFeedIssue,
} from "@/hooks/useCustomerFeed";
import { conflictAmountDelta, formatSignedAmount, type MatchConflict } from "@/lib/match-conflicts";

export const Route = createFileRoute("/app/customer-feed")({
  head: () => ({ meta: [{ title: "Customer Feed — Kim-Fay OrderWatch" }] }),
  component: CustomerFeedPage,
});

function today() {
  return new Date().toISOString().slice(0, 10);
}

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function CustomerFeedPage() {
  const [dateFrom, setDateFrom] = useState(startOfMonth);
  const [dateTo, setDateTo] = useState(today);
  const [q, setQ] = useState("");
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});
  const [insightGroup, setInsightGroup] = useState<CustomerFeedGroup | null>(null);

  const { data, isLoading, refetch, isFetching } = useCustomerFeed({
    date_from: dateFrom,
    date_to: dateTo,
    q: q || undefined,
  });

  function toggleExpanded(key: string) {
    setExpanded((prev) => ({ ...prev, [key]: !prev[key] }));
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Customer Feed</h1>
          <p className="text-sm text-muted-foreground">
            Customer performance grouped by account and branches — orders, emails, match rate, fulfilment
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <div className="grid gap-1">
            <Label className="text-xs">From</Label>
            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-8 w-36 text-xs" />
          </div>
          <div className="grid gap-1">
            <Label className="text-xs">To</Label>
            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-8 w-36 text-xs" />
          </div>
          <Button variant="outline" size="sm" className="h-8" onClick={() => refetch()} disabled={isFetching}>
            <RefreshCw className={`h-3.5 w-3.5 ${isFetching ? "animate-spin" : ""}`} />
          </Button>
        </div>
      </div>

      {data && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SummaryCard label="Customer groups" value={data.summary.group_count} />
          <SummaryCard label="Orders" value={data.summary.order_count} />
          <SummaryCard label="Emails" value={data.summary.email_count} />
          <SummaryCard label="Matched orders" value={data.summary.matched_orders} />
        </div>
      )}

      <div className="max-w-md">
        <Label htmlFor="cf-search">Search customers</Label>
        <div className="relative">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            id="cf-search"
            className="pl-8"
            placeholder="Customer name or ID…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
        </div>
      </div>

      <div className="rounded-lg border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-4 py-3 font-medium">Customer</th>
              <th className="px-3 py-3 font-medium text-right">Orders</th>
              <th className="px-3 py-3 font-medium text-right">Emails</th>
              <th className="px-3 py-3 font-medium text-right">Matched</th>
              <th className="px-3 py-3 font-medium text-right whitespace-nowrap">Avg complete</th>
              <th className="px-3 py-3 font-medium text-right whitespace-nowrap">Avg fill rate</th>
              <th className="px-4 py-3 font-medium text-right">Insights</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr>
                <td colSpan={7} className="px-4 py-6">
                  <Skeleton className="h-8 w-full" />
                </td>
              </tr>
            )}
            {!isLoading && (data?.groups ?? []).map((group) => (
              <CustomerFeedRows
                key={group.group_key}
                group={group}
                expanded={!!expanded[group.group_key]}
                onToggle={() => toggleExpanded(group.group_key)}
                onInsights={() => setInsightGroup(group)}
              />
            ))}
            {!isLoading && (data?.groups ?? []).length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">
                  No customer activity in this period
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <InsightsDialog
        group={insightGroup}
        dateFrom={dateFrom}
        dateTo={dateTo}
        onClose={() => setInsightGroup(null)}
      />
    </div>
  );
}

function CustomerFeedRows({
  group,
  expanded,
  onToggle,
  onInsights,
}: {
  group: CustomerFeedGroup;
  expanded: boolean;
  onToggle: () => void;
  onInsights: () => void;
}) {
  const rows = [
    <tr key={group.group_key} className="border-b hover:bg-muted/20">
      <td className="px-4 py-3">
        <div className="flex items-start gap-2">
          {group.is_grouped ? (
            <button type="button" onClick={onToggle} className="mt-0.5 text-muted-foreground hover:text-foreground">
              {expanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
            </button>
          ) : (
            <Users className="mt-0.5 h-4 w-4 text-muted-foreground" />
          )}
          <div>
            <div className="font-medium">{group.display_name}</div>
            <div className="text-xs text-muted-foreground">
              {group.is_grouped ? (
                <span className="inline-flex items-center gap-1">
                  <Building2 className="h-3 w-3" />
                  {group.branch_count + 1} accounts grouped
                </span>
              ) : (
                group.group_key
              )}
            </div>
          </div>
        </div>
      </td>
      <MetricCell value={group.order_count} />
      <MetricCell value={group.email_count} />
      <MetricCell value={group.matched_orders} />
      <td className="px-3 py-3 text-right tabular-nums text-muted-foreground">
        {formatCompletionTime(group.avg_completion_hours)}
      </td>
      <td className="px-3 py-3 text-right">
        <FillRateCell pct={group.avg_fill_rate_pct} />
      </td>
      <td className="px-4 py-3 text-right">
        <Button variant="outline" size="sm" onClick={onInsights}>
          <Lightbulb className="mr-1.5 h-3.5 w-3.5" />
          Insights
        </Button>
      </td>
    </tr>,
  ];

  if (group.is_grouped && expanded) {
    for (const branch of group.branches) {
      rows.push(
        <tr key={`${group.group_key}-${branch.acumatica_id}`} className="border-b bg-muted/10">
          <td className="px-4 py-2 pl-12 text-muted-foreground">
            <div className="font-medium text-foreground">{branch.name}</div>
            <div className="text-xs">{branch.acumatica_id}</div>
          </td>
          <MetricCell value={branch.order_count} muted />
          <MetricCell value={branch.email_count} muted />
          <MetricCell value={branch.matched_orders} muted />
          <td className="px-3 py-2 text-right tabular-nums text-muted-foreground">
            {formatCompletionTime(branch.avg_completion_hours)}
          </td>
          <td className="px-3 py-2 text-right">
            <FillRateCell pct={branch.avg_fill_rate_pct} />
          </td>
          <td className="px-4 py-2" />
        </tr>,
      );
    }
  }

  return <>{rows}</>;
}

function MetricCell({ value, muted }: { value: number; muted?: boolean }) {
  return (
    <td className={`px-3 py-3 text-right font-mono tabular-nums ${muted ? "text-muted-foreground" : "font-medium"}`}>
      {value > 0 ? value.toLocaleString() : <span className="text-muted-foreground/50">—</span>}
    </td>
  );
}

function FillRateCell({ pct }: { pct: number | null }) {
  const tone = fillRateTone(pct);
  if (pct == null) return <span className="text-muted-foreground/50">—</span>;

  const classes = {
    good: "text-green-700 bg-green-50 border-green-200 dark:text-green-300 dark:bg-green-950/40 dark:border-green-800",
    warn: "text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:border-amber-800",
    bad: "text-red-700 bg-red-50 border-red-200 dark:text-red-300 dark:bg-red-950/40 dark:border-red-800",
    muted: "",
  }[tone];

  return (
    <span className={`inline-flex rounded border px-1.5 py-0.5 font-semibold tabular-nums ${classes}`}>
      {pct.toFixed(1)}%
    </span>
  );
}

function SummaryCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
      <div className="mt-1 text-2xl font-bold tabular-nums">{value.toLocaleString()}</div>
    </div>
  );
}

function InsightsDialog({
  group,
  dateFrom,
  dateTo,
  onClose,
}: {
  group: CustomerFeedGroup | null;
  dateFrom: string;
  dateTo: string;
  onClose: () => void;
}) {
  const insights = useCustomerFeedInsights(group?.group_key ?? null, {
    date_from: dateFrom,
    date_to: dateTo,
  });

  return (
    <Dialog open={group !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Lightbulb className="h-5 w-5 text-amber-500" />
            {group?.display_name} — Insights
          </DialogTitle>
          <DialogDescription>
            Common issues for {dateFrom} to {dateTo}
            {group?.is_grouped ? ` across ${group.branch_count + 1} accounts` : ""}
          </DialogDescription>
        </DialogHeader>

        {insights.isLoading && (
          <div className="space-y-3 py-4">
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
          </div>
        )}

        {!insights.isLoading && insights.data && (
          <div className="space-y-4">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Badge variant="outline">{insights.data.issue_total} issue{insights.data.issue_total === 1 ? "" : "s"}</Badge>
              <span>from matched orders and fulfilment data</span>
            </div>

            {insights.data.issues.length === 0 ? (
              <p className="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                No issues detected for this customer in the selected period.
              </p>
            ) : (
              <div className="space-y-3">
                {insights.data.issues.map((issue) => (
                  <IssueCard key={issue.type} issue={issue} />
                ))}
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

function IssueCard({ issue }: { issue: CustomerFeedIssue }) {
  const tone =
    issue.type === "amount_discrepancy_positive" ? "border-green-200 bg-green-50/50 dark:border-green-900 dark:bg-green-950/20"
    : issue.type === "amount_discrepancy_negative" ? "border-red-200 bg-red-50/50 dark:border-red-900 dark:bg-red-950/20"
    : issue.type === "low_fill_rate" ? "border-amber-200 bg-amber-50/50 dark:border-amber-900 dark:bg-amber-950/20"
    : "border-muted bg-muted/20";

  return (
    <div className={`rounded-lg border p-4 ${tone}`}>
      <div className="flex items-center justify-between gap-3">
        <div className="font-medium">{issue.label}</div>
        <Badge>{issue.count}</Badge>
      </div>
      {issue.examples.length > 0 && (
        <ul className="mt-3 space-y-2 text-xs text-muted-foreground">
          {issue.examples.map((ex, i) => (
            <li key={i} className="rounded border bg-background/60 px-3 py-2">
              <IssueExample issue={issue} example={ex} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function IssueExample({ issue, example }: { issue: CustomerFeedIssue; example: Record<string, unknown> }) {
  if (issue.type === "low_fill_rate") {
    return (
      <span>
        <span className="font-medium text-foreground">{String(example.order_nbr ?? "Order")}</span>
        {" — "}
        {Number(example.fill_rate_pct ?? 0).toFixed(1)}% fill
        {" ("}
        {Number(example.shipped ?? 0).toLocaleString()} / {Number(example.ordered ?? 0).toLocaleString()}
        {")"}
      </span>
    );
  }

  const conflict: MatchConflict = {
    field: String(example.field ?? ""),
    email_value: String(example.email ?? ""),
    acumatica_value: String(example.acumatica ?? ""),
    reason: "",
    amount_delta: example.amount_delta != null ? String(example.amount_delta) : undefined,
  };

  const delta = conflictAmountDelta(conflict);
  const deltaText = delta !== null ? ` (${formatSignedAmount(delta)})` : "";

  return (
    <span>
      <span className="font-medium text-foreground">{String(example.order_nbr ?? "Order")}</span>
      {example.subject ? ` · ${String(example.subject).slice(0, 60)}` : ""}
      <br />
      Email {String(example.email ?? "—")} vs Acumatica {String(example.acumatica ?? "—")}
      {issue.type.startsWith("amount_discrepancy") ? deltaText : ""}
    </span>
  );
}
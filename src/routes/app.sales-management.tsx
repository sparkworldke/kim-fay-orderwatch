import { createFileRoute } from "@tanstack/react-router";
import type React from "react";
import { useState } from "react";
import { Check, Clock, RefreshCw, Search, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useCapabilities } from "@/hooks/useCapabilities";
import { useAuth } from "@/lib/auth";
import {
  useGenerateSalesManagementPrompts,
  useSalesManagementDashboard,
  useSalesManagementPrompts,
  useSalesPromptActions,
  type SalesManagementPrompt,
} from "@/hooks/useSalesManagement";
import {
  money,
  promptSeverityClass,
  SALES_PROMPT_STATUS_LABEL,
  SALES_PROMPT_TYPE_LABEL,
  shortDate,
} from "@/lib/sales-management";

export const Route = createFileRoute("/app/sales-management")({
  head: () => ({ meta: [{ title: "Sales Management - Kim-Fay OrderWatch" }] }),
  component: SalesManagementPage,
});

const TABS = [
  { value: "my", label: "My Prompts" },
  { value: "due", label: "Due / Overdue" },
  { value: "month_gap", label: "Month Close Gaps" },
  { value: "resolved", label: "Resolved" },
  { value: "all", label: "All" },
];

function SalesManagementPage() {
  const { session } = useAuth();
  const caps = useCapabilities();
  const permissions = caps.permissions ?? [];
  const canGenerate = session?.role === "Administrator" && permissions.includes("sales.management.manage");
  const [view, setView] = useState("due");
  const [q, setQ] = useState("");
  const dashboard = useSalesManagementDashboard();
  const prompts = useSalesManagementPrompts({ view, q, per_page: 75 });
  const generate = useGenerateSalesManagementPrompts();

  async function runGenerate(force = false) {
    try {
      const result = await generate.mutateAsync({ force });
      if (result.stale_blocked) {
        toast.error(result.stale_message ?? "Sales order sync is stale.");
        return;
      }
      toast.success(`Generated ${result.created}, updated ${result.updated}, skipped ${result.skipped}.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Prompt generation failed.");
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Sales Management</h1>
          <p className="text-sm text-muted-foreground">Order-cycle follow-ups and month-close gap prompts for sales teams.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={() => prompts.refetch()}>
            <RefreshCw className="mr-1 h-3.5 w-3.5" /> Refresh
          </Button>
          {canGenerate && (
            <>
              <Button size="sm" variant="outline" onClick={() => runGenerate(false)} disabled={generate.isPending}>
                Generate
              </Button>
              {dashboard.data?.sales_order_sync?.is_stale && (
                <Button size="sm" onClick={() => runGenerate(true)} disabled={generate.isPending}>
                  Force generate
                </Button>
              )}
            </>
          )}
        </div>
      </div>

      {dashboard.data?.sales_order_sync?.is_stale && (
        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
          {dashboard.data.sales_order_sync.message}
        </div>
      )}

      <div className="grid gap-3 sm:grid-cols-5">
        <Metric label="Open" value={dashboard.data?.total_open ?? 0} />
        <Metric label="Due" value={dashboard.data?.due ?? 0} />
        <Metric label="Overdue" value={dashboard.data?.overdue ?? 0} />
        <Metric label="Month gaps" value={dashboard.data?.month_gaps ?? 0} />
        <Metric label="Resolved 30d" value={dashboard.data?.resolved_30d ?? 0} />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Tabs value={view} onValueChange={setView}>
          <TabsList className="flex h-auto flex-wrap justify-start">
            {TABS.map((tab) => <TabsTrigger key={tab.value} value={tab.value}>{tab.label}</TabsTrigger>)}
          </TabsList>
        </Tabs>
        <div className="relative ml-auto min-w-[240px]">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search customer, consultant, reason" className="h-9 pl-8" />
        </div>
      </div>

      <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              <Head>Prompt</Head>
              <Head>Customer</Head>
              <Head>Consultant</Head>
              <Head>Signals</Head>
              <Head>Due</Head>
              <Head>Status</Head>
              <Head>Actions</Head>
            </tr>
          </thead>
          <tbody className="divide-y">
            {prompts.isLoading && Array.from({ length: 6 }).map((_, index) => (
              <tr key={index}><td colSpan={7} className="px-4 py-3"><Skeleton className="h-5 w-full" /></td></tr>
            ))}
            {!prompts.isLoading && (prompts.data?.data ?? []).length === 0 && (
              <tr><td colSpan={7} className="px-4 py-10 text-center text-sm text-muted-foreground">No sales prompts found.</td></tr>
            )}
            {(prompts.data?.data ?? []).map((prompt) => <PromptRow key={prompt.id} prompt={prompt} />)}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function PromptRow({ prompt }: { prompt: SalesManagementPrompt }) {
  const actions = useSalesPromptActions();

  async function resolve() {
    const note = window.prompt("Resolution note");
    if (!note) return;
    try {
      await actions.resolve.mutateAsync({ id: prompt.id, note });
      toast.success("Prompt resolved.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to resolve prompt.");
    }
  }

  async function snooze() {
    const snoozed_until = window.prompt("Snooze until date (YYYY-MM-DD)");
    if (!snoozed_until) return;
    try {
      await actions.snooze.mutateAsync({ id: prompt.id, snoozed_until });
      toast.success("Prompt snoozed.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to snooze prompt.");
    }
  }

  async function dismiss() {
    const reason = window.prompt("Dismiss reason");
    if (!reason) return;
    try {
      await actions.dismiss.mutateAsync({ id: prompt.id, reason });
      toast.success("Prompt dismissed.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to dismiss prompt.");
    }
  }

  const active = ["open", "snoozed"].includes(prompt.status);

  return (
    <tr className="hover:bg-muted/20">
      <td className="px-4 py-2.5">
        <Badge variant="outline" className={promptSeverityClass(prompt.severity)}>{prompt.severity}</Badge>
        <div className="mt-1 font-medium">{SALES_PROMPT_TYPE_LABEL[prompt.prompt_type]}</div>
        <div className="max-w-[360px] text-xs text-muted-foreground">{prompt.reason}</div>
      </td>
      <td className="px-4 py-2.5">
        <div className="font-medium">{prompt.customer_name ?? prompt.customer_acumatica_id}</div>
        <div className="font-mono text-[11px] text-muted-foreground">{prompt.customer_acumatica_id}</div>
      </td>
      <td className="px-4 py-2.5">
        <div>{prompt.consultant_name ?? "-"}</div>
        <div className="font-mono text-[11px] text-muted-foreground">{prompt.consultant_rep_code ?? "-"}</div>
      </td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">
        {prompt.prompt_type === "order_cycle_follow_up" ? (
          <>
            <div>{prompt.days_since_last_order ?? "-"} days since last SO</div>
            <div>Cycle {prompt.expected_cycle_days ?? "-"} days</div>
          </>
        ) : (
          <>
            <div>{prompt.order_count_snapshot} SO(s)</div>
            <div>{money(prompt.value_snapshot)}</div>
          </>
        )}
      </td>
      <td className="px-4 py-2.5 text-xs">{shortDate(prompt.due_date)}</td>
      <td className="px-4 py-2.5 text-xs">{SALES_PROMPT_STATUS_LABEL[prompt.status]}</td>
      <td className="px-4 py-2.5">
        {active ? (
          <div className="flex flex-wrap gap-1">
            <Button size="sm" variant="outline" onClick={snooze}><Clock className="h-3.5 w-3.5" /></Button>
            <Button size="sm" variant="outline" onClick={dismiss}><X className="h-3.5 w-3.5" /></Button>
            <Button size="sm" onClick={resolve}><Check className="h-3.5 w-3.5" /></Button>
          </div>
        ) : (
          <span className="text-xs text-muted-foreground">Closed</span>
        )}
      </td>
    </tr>
  );
}

function Head({ children }: { children: React.ReactNode }) {
  return <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">{children}</th>;
}

function Metric({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border bg-card p-3 shadow-sm">
      <div className="text-[11px] uppercase text-muted-foreground">{label}</div>
      <div className="mt-1 text-lg font-semibold">{value.toLocaleString("en-KE")}</div>
    </div>
  );
}

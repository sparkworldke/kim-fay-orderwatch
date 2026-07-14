import { Link, Outlet, createFileRoute, useRouterState } from "@tanstack/react-router";
import { useState } from "react";
import { FilePlus2, RefreshCw, Search } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useCapabilities } from "@/hooks/useCapabilities";
import { useFolList, type FolRequest } from "@/hooks/useFol";
import { FOL_STATUS_CLASS, FOL_STATUS_LABEL, formatFolDate } from "@/lib/fol";

export const Route = createFileRoute("/app/kp/fol")({
  head: () => ({ meta: [{ title: "KP FOL - Kim-Fay OrderWatch" }] }),
  component: FolRoute,
});

/**
 * Parent layout for /app/kp/fol and children (/new, /calendar, /$id).
 * Without an Outlet on child paths, those pages never render (same pattern as PCR).
 */
function FolRoute() {
  const pathname = useRouterState({ select: (state) => state.location.pathname });

  if (pathname !== "/app/kp/fol") {
    return <Outlet />;
  }

  return <FolListPage />;
}

const TABS = [
  { value: "my", label: "My Requests" },
  { value: "my_allocations", label: "My Allocations" },
  { value: "my_resolved", label: "Resolved by me" },
  { value: "pending_approval", label: "Pending Approval" },
  { value: "ready_for_invoicing", label: "Ready for Invoicing" },
  { value: "all", label: "All KP FOL" },
];

function FolListPage() {
  const caps = useCapabilities();
  const permissions = caps.permissions ?? [];
  const canCreate = permissions.includes("kp.fol.request");
  const canApprove = permissions.includes("kp.fol.approve");
  const canInvoice = permissions.includes("kp.fol.invoice");
  const canExecute = permissions.includes("kp.fol.install.execute");
  const canManageInstall = permissions.includes("kp.fol.install.manage");
  const canSeeAll = permissions.includes("kp.fol.report") || caps.department_role === "hod";
  const isTechnicianFirst = canExecute && !canCreate && !canApprove;

  const [view, setView] = useState(isTechnicianFirst ? "my_allocations" : "my");
  const [q, setQ] = useState("");
  const list = useFolList({ view, q });

  const visibleTabs = TABS.filter((tab) => {
    if (tab.value === "pending_approval") return canApprove;
    if (tab.value === "ready_for_invoicing") return canInvoice;
    if (tab.value === "all") return canSeeAll || canApprove;
    if (tab.value === "my_allocations" || tab.value === "my_resolved") return canExecute || canManageInstall;
    if (tab.value === "my") return canCreate || canApprove || canSeeAll;
    return true;
  });

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">KP FOL Requests</h1>
          <p className="text-sm text-muted-foreground">Free On Loan requisitions, approvals, and invoicing handoff.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => list.refetch()}>
            <RefreshCw className="mr-1 h-3.5 w-3.5" /> Refresh
          </Button>
          {(canExecute || canManageInstall) && (
            <Button asChild size="sm" variant="outline">
              <Link to="/app/kp/fol/calendar">Calendar</Link>
            </Button>
          )}
          {canCreate && (
            <Button asChild size="sm">
              <Link to="/app/kp/fol/new">
                <FilePlus2 className="mr-1 h-3.5 w-3.5" /> New FOL
              </Link>
            </Button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Tabs value={view} onValueChange={setView}>
          <TabsList className="flex h-auto flex-wrap justify-start">
            {visibleTabs.map((tab) => (
              <TabsTrigger key={tab.value} value={tab.value}>{tab.label}</TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
        <div className="relative ml-auto min-w-[220px]">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search FOL ref or customer" className="h-9 pl-8" />
        </div>
      </div>

      <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Request</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Customer</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Lines</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Stage</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Status</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Updated</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {list.isLoading && Array.from({ length: 6 }).map((_, index) => (
              <tr key={index}><td colSpan={6} className="px-4 py-3"><Skeleton className="h-5 w-full" /></td></tr>
            ))}
            {!list.isLoading && (list.data?.data ?? []).length === 0 && (
              <tr><td colSpan={6} className="px-4 py-10 text-center text-sm text-muted-foreground">No FOL requests found.</td></tr>
            )}
            {(list.data?.data ?? []).map((row) => <FolRow key={row.id} row={row} />)}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function FolRow({ row }: { row: FolRequest }) {
  return (
    <tr className="hover:bg-muted/20">
      <td className="px-4 py-2.5">
        <Link to="/app/kp/fol/$id" params={{ id: String(row.id) }} className="font-medium text-primary hover:underline">
          {row.public_ref}
        </Link>
        <div className="text-[11px] text-muted-foreground">{row.sales_consultant_email ?? "-"}</div>
      </td>
      <td className="px-4 py-2.5">
        <div className="font-medium">{row.customer_name}</div>
        <div className="font-mono text-[11px] text-muted-foreground">{row.customer_acumatica_id}</div>
      </td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">{row.lines?.length ?? 0} line(s)</td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">{row.current_stage_key ?? "-"}</td>
      <td className="px-4 py-2.5">
        <Badge variant="outline" className={FOL_STATUS_CLASS[row.status]}>{FOL_STATUS_LABEL[row.status]}</Badge>
      </td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">{formatFolDate(row.submitted_at ?? row.created_at)}</td>
    </tr>
  );
}

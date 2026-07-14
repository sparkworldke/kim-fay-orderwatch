import { Link, Outlet, createFileRoute, useRouterState } from "@tanstack/react-router";
import type React from "react";
import { useState } from "react";
import { FilePlus2, RefreshCw, Search } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useCapabilities } from "@/hooks/useCapabilities";
import {
  usePcrDashboard,
  usePcrList,
  type PriceChangeRequest,
} from "@/hooks/usePriceChangeRequests";
import { money, PCR_STATUS_CLASS, PCR_STATUS_LABEL, shortDate } from "@/lib/price-change";

export const Route = createFileRoute("/app/price-change-requests")({
  head: () => ({ meta: [{ title: "Price Change Requests - Kim-Fay OrderWatch" }] }),
  component: PriceChangeRequestsRoute,
});

const TABS = [
  { value: "my", label: "My Requests" },
  { value: "pending_approval", label: "Pending Approval" },
  { value: "pending_erp_apply", label: "Pending ERP" },
  { value: "all", label: "All PCR" },
];

function PriceChangeRequestsRoute() {
  const pathname = useRouterState({ select: (state) => state.location.pathname });

  if (pathname !== "/app/price-change-requests") {
    return <Outlet />;
  }

  return <PriceChangeListPage />;
}

function PriceChangeListPage() {
  const caps = useCapabilities();
  const permissions = caps.permissions ?? [];
  const canCreate = permissions.includes("pricing.pcr.create");
  const canApprove = permissions.includes("pricing.pcr.approve");
  const canApplyErp = permissions.includes("pricing.pcr.apply_erp");
  const canSeeAll =
    permissions.includes("pricing.pcr.approve_escalated") ||
    permissions.includes("pricing.pcr.config");
  const [view, setView] = useState("my");
  const [q, setQ] = useState("");
  const list = usePcrList({ view, q });
  const dashboard = usePcrDashboard();

  const visibleTabs = TABS.filter((tab) => {
    if (tab.value === "pending_approval") return canApprove;
    if (tab.value === "pending_erp_apply") return canApplyErp;
    if (tab.value === "all") return canSeeAll;
    return true;
  });

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Price Change Requests</h1>
          <p className="text-sm text-muted-foreground">
            Customer-scoped SKU price changes with approval and ERP handoff.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => list.refetch()}>
            <RefreshCw className="mr-1 h-3.5 w-3.5" /> Refresh
          </Button>
          {canCreate && (
            <Button asChild size="sm">
              <Link to="/app/price-change-requests/new">
                <FilePlus2 className="mr-1 h-3.5 w-3.5" /> New PCR
              </Link>
            </Button>
          )}
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-4">
        <Metric label="Total" value={dashboard.data?.total ?? 0} />
        <Metric label="Pending approval" value={dashboard.data?.pending_approval ?? 0} />
        <Metric label="Pending ERP" value={dashboard.data?.pending_erp_apply ?? 0} />
        <Metric label="Duplicate warnings" value={dashboard.data?.duplicates ?? 0} />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Tabs value={view} onValueChange={setView}>
          <TabsList className="flex h-auto flex-wrap justify-start">
            {visibleTabs.map((tab) => (
              <TabsTrigger key={tab.value} value={tab.value}>
                {tab.label}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
        <div className="relative ml-auto min-w-[220px]">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search ref, customer, SKU"
            className="h-9 pl-8"
          />
        </div>
      </div>

      <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              <Head>Request</Head>
              <Head>Customer</Head>
              <Head>SKU</Head>
              <Head>Current</Head>
              <Head>Proposed</Head>
              <Head>Status</Head>
              <Head>Submitted</Head>
            </tr>
          </thead>
          <tbody className="divide-y">
            {list.isLoading &&
              Array.from({ length: 6 }).map((_, index) => (
                <tr key={index}>
                  <td colSpan={7} className="px-4 py-3">
                    <Skeleton className="h-5 w-full" />
                  </td>
                </tr>
              ))}
            {!list.isLoading && (list.data?.data ?? []).length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-sm text-muted-foreground">
                  No price change requests found.
                </td>
              </tr>
            )}
            {(list.data?.data ?? []).map((row) => (
              <PcrRow key={row.id} row={row} />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function PcrRow({ row }: { row: PriceChangeRequest }) {
  return (
    <tr className="hover:bg-muted/20">
      <td className="px-4 py-2.5">
        <Link
          to="/app/price-change-requests/$id"
          params={{ id: String(row.id) }}
          className="font-medium text-primary hover:underline"
        >
          {row.public_ref}
        </Link>
        {row.duplicate_ack_required && !row.duplicate_acked_at && (
          <div className="text-[11px] text-amber-700">Duplicate warning</div>
        )}
      </td>
      <td className="px-4 py-2.5">
        <div className="font-medium">{row.customer_name ?? row.customer_acumatica_id}</div>
        <div className="font-mono text-[11px] text-muted-foreground">
          {row.customer_acumatica_id}
        </div>
      </td>
      <td className="px-4 py-2.5">
        <div className="font-mono text-xs">{row.inventory_id}</div>
        <div className="max-w-[240px] truncate text-[11px] text-muted-foreground">
          {row.product_description}
        </div>
      </td>
      <td className="px-4 py-2.5 text-xs">{money(row.current_selling_price)}</td>
      <td className="px-4 py-2.5 text-xs font-medium">{money(row.proposed_selling_price)}</td>
      <td className="px-4 py-2.5">
        <Badge variant="outline" className={PCR_STATUS_CLASS[row.status]}>
          {PCR_STATUS_LABEL[row.status]}
        </Badge>
      </td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">
        {shortDate(row.submitted_at ?? row.created_at)}
      </td>
    </tr>
  );
}

function Head({ children }: { children: React.ReactNode }) {
  return (
    <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">
      {children}
    </th>
  );
}

function Metric({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border bg-card p-3 shadow-sm">
      <div className="text-[11px] uppercase text-muted-foreground">{label}</div>
      <div className="mt-1 text-lg font-semibold">{value.toLocaleString()}</div>
    </div>
  );
}

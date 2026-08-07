import { createFileRoute, Link } from "@tanstack/react-router";
import { useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { Skeleton } from "@/components/ui/skeleton";
import { useCapabilities } from "@/hooks/useCapabilities";
import { useCommissionStatements, useCommissionTransition, useCreateCommissionPeriod } from "@/hooks/useCommissions";
import { usePagination } from "@/hooks/usePagination";
import { toast } from "sonner";

export const Route = createFileRoute("/app/kp/commissions")({
  head: () => ({ meta: [{ title: "Commissions" }] }),
  component: CommissionPage,
});

const money = (value: string | number) => `KES ${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;

function CommissionPage() {
  const { page, perPage, setPage, setPerPage } = usePagination(20);
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const list = useCommissionStatements(page, perPage);
  const createPeriod = useCreateCommissionPeriod();
  const transition = useCommissionTransition();
  const { permissions } = useCapabilities();
  const canReview = permissions.includes("commissions.review");
  const canApprove = permissions.includes("commissions.approve");
  const canLock = permissions.includes("commissions.lock");

  async function createDraft() {
    try { await createPeriod.mutateAsync({ period_month: month, shadow_mode: true }); toast.success("Shadow commission period calculated."); }
    catch (error) { toast.error(error instanceof Error ? error.message : "Calculation failed."); }
  }

  async function change(periodId: number, action: string) {
    try { await transition.mutateAsync({ periodId, action }); toast.success(`Period ${action} completed.`); }
    catch (error) { toast.error(error instanceof Error ? error.message : "Update failed."); }
  }

  return <div className="space-y-5">
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div><p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">KP Performance</p><h1 className="text-2xl font-semibold">Incentives & commissions</h1><p className="text-sm text-muted-foreground">Approved sales-order earnings with traceable rules, targets, and Finance review.</p></div>
      {canReview && <div className="flex items-center gap-2"><Input aria-label="Commission month" type="month" value={month} onChange={(e)=>setMonth(e.target.value)} className="w-40"/><Button onClick={createDraft} disabled={createPeriod.isPending}>Calculate shadow period</Button></div>}
    </div>
    {list.isLoading ? <Skeleton className="h-48 w-full"/> : list.isError ? <p className="text-sm text-destructive">{list.error instanceof Error ? list.error.message : "Commission statements could not be loaded."}</p> :
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{(list.data?.data ?? []).map((row)=><Card key={row.id}><CardHeader className="pb-2"><div className="flex items-center justify-between"><CardTitle className="text-base">{row.user.name}</CardTitle><Badge variant={row.period.status === "locked" ? "default" : "secondary"}>{row.period.shadow_mode ? "Shadow · " : ""}{row.period.status}</Badge></div><p className="text-xs text-muted-foreground">{row.period.period_month.slice(0,7)} · v{row.period.version}{row.user.rep_code ? ` · ${row.user.rep_code}` : ""}</p></CardHeader><CardContent className="space-y-3"><div className="grid grid-cols-2 gap-2 text-sm"><span className="text-muted-foreground">Eligible sales</span><b className="text-right">{money(row.eligible_sales)}</b><span className="text-muted-foreground">Target</span><b className="text-right">{money(row.target_amount)}</b><span className="text-muted-foreground">Attainment</span><b className="text-right">{Number(row.attainment_pct).toFixed(1)}%</b><span className="text-muted-foreground">Commission</span><b className="text-right">{money(row.net_commission)}</b></div><div className="flex flex-wrap gap-2"><Button asChild size="sm" variant="outline"><Link to="/app/kp/commissions/$statementId" params={{statementId:String(row.id)}}>Calculation details</Link></Button>{canApprove && row.period.status==="draft" && <Button size="sm" onClick={()=>change(row.period.id,"approve")}>Approve</Button>}{canLock && row.period.status==="approved" && <Button size="sm" onClick={()=>change(row.period.id,"lock")}>Lock</Button>}</div></CardContent></Card>)}</div>}
    {(list.data?.total ?? 0)>0 && <PaginationControls currentPage={list.data?.current_page ?? page} lastPage={list.data?.last_page ?? 1} total={list.data?.total ?? 0} perPage={perPage} onPageChange={setPage} onPerPageChange={setPerPage} />}
  </div>;
}

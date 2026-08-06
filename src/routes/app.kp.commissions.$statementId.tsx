import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

export const Route = createFileRoute("/app/kp/commissions/$statementId")({ component: Detail });
function Detail(){
  const {statementId}=Route.useParams();
  const q=useQuery({queryKey:["commission-statement",statementId],queryFn:()=>apiFetch<any>(`kp/commissions/statements/${statementId}`)});
  if(q.isLoading)return <Skeleton className="h-64 w-full"/>;
  if(q.isError)return <p className="text-destructive">{q.error instanceof Error?q.error.message:"Unable to load statement."}</p>;
  const s=q.data;
  return <div className="space-y-4"><div><h1 className="text-2xl font-semibold">Commission calculation</h1><p className="text-sm text-muted-foreground">{s.user.name} · {s.period.period_month.slice(0,7)} · version {s.period.version}</p></div><Card><CardHeader><CardTitle className="text-base">Order breakdown</CardTitle></CardHeader><CardContent className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b text-left"><th className="py-2">SO</th><th>Date</th><th className="text-right">Sales</th><th className="text-right">Rate</th><th className="text-right">Commission</th></tr></thead><tbody>{s.entries.map((e:any)=><tr key={e.id} className="border-b"><td className="py-2 font-medium">{e.order_nbr}</td><td>{String(e.order_date).slice(0,10)}</td><td className="text-right">KES {Number(e.sales_value).toLocaleString()}</td><td className="text-right">{Number(e.rate_pct)}%</td><td className="text-right">KES {Number(e.commission_amount).toLocaleString()}</td></tr>)}</tbody></table></CardContent></Card></div>;
}

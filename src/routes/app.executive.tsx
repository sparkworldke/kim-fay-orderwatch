import { createFileRoute, Link } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { AlertTriangle, Boxes, PackageCheck, PackageX, RefreshCw, ShoppingCart, TrendingUp } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useCapabilities } from "@/hooks/useCapabilities";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";

type Metric = { revenue: number; orders: number; revenue_change_pct: number | null; orders_change_pct: number | null };
type Pulse = { period: { from: string; to: string }; totals: Metric; segments: Array<Metric & { code: string }>; trend: Array<{ date: string; revenue: number; orders: number; segments: Record<string, { revenue: number; orders: number }> }>; gaps: { backorder_lines: number; revenue_at_risk: number; fill_rate_pct: number | null; critical_skus: number; not_delivered_qty: number }; focus: string | null };

export const Route = createFileRoute("/app/executive")({ head: () => ({ meta: [{ title: "Executive View - Kim-Fay Sight" }] }), component: ExecutivePage });

function localDate(date: Date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`; }
function money(value: number, masked: boolean) { if (masked) return "Restricted"; return `KES ${new Intl.NumberFormat("en-KE", { notation: "compact", maximumFractionDigits: 2 }).format(value || 0)}`; }
function change(value: number | null) { return value == null ? "No prior baseline" : `${value >= 0 ? "▲" : "▼"} ${Math.abs(value)}% vs prior`; }

function ExecutivePage() {
  const now = useMemo(() => new Date(), []);
  const [from, setFrom] = useState(localDate(new Date(now.getFullYear(), now.getMonth(), 1)));
  const [to, setTo] = useState(localDate(now));
  const [selection, setSelection] = useState<string | null>(null);
  const [measure, setMeasure] = useState<"revenue" | "orders">("revenue");
  const caps = useCapabilities();
  const query = useQuery({ queryKey: ["executive-pulse", from, to], queryFn: () => apiFetch<Pulse>(`executive/metrics?from=${from}&to=${to}`), enabled: caps.executive_view });
  const selectedLabel = selection === "COMPANY" ? "Company" : selection ?? "Company";
  const chart = (query.data?.trend ?? []).map((row) => ({ date: row.date.slice(5), revenue: selection && selection !== "COMPANY" ? row.segments[selection]?.revenue ?? 0 : row.revenue, orders: selection && selection !== "COMPANY" ? row.segments[selection]?.orders ?? 0 : row.orders }));
  if (!caps.isLoading && !caps.executive_view) return <div className="p-6"><Card><CardContent className="p-8 text-center text-muted-foreground">Executive View is restricted to executives and heads of department.</CardContent></Card></div>;
  return <div className="space-y-6 p-6">
    <div className="flex flex-wrap items-end justify-between gap-3"><div><h1 className="text-2xl font-semibold">Executive View</h1><p className="text-sm text-muted-foreground">Kim-Fay Sight · company pulse</p></div><div className="flex items-end gap-2"><label className="text-xs">From<Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label><label className="text-xs">To<Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label><Button variant="outline" size="icon" onClick={() => query.refetch()}><RefreshCw className="h-4 w-4" /></Button></div></div>
    {query.isLoading ? <Skeleton className="h-80" /> : query.isError || !query.data ? <Card><CardContent className="p-8 text-center text-destructive">Executive metrics could not be loaded.</CardContent></Card> : <>
      {query.data.focus && <Badge variant="secondary">Your focus: {query.data.focus}</Badge>}
      <section><h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Revenue & orders</h2><div className="grid gap-3 md:grid-cols-2"><PulseCard title="Total revenue" value={money(query.data.totals.revenue, caps.maskRevenue)} note={change(query.data.totals.revenue_change_pct)} onClick={() => { setSelection("COMPANY"); setMeasure("revenue"); }} /><PulseCard title="Total orders" value={query.data.totals.orders.toLocaleString()} note={change(query.data.totals.orders_change_pct)} onClick={() => { setSelection("COMPANY"); setMeasure("orders"); }} /></div></section>
      <section><h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">By segment</h2><div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">{query.data.segments.map((item) => <button key={item.code} onClick={() => setSelection(item.code)} className={`rounded-lg border bg-card p-3 text-left transition hover:border-primary ${query.data.focus === item.code || (query.data.focus === "MT" && item.code.startsWith("MT")) ? "ring-2 ring-primary" : ""}`}><p className="font-semibold">{item.code.replace("ECOMMERCE", "E-commerce").replace("DTC_DTB", "DTC / DTB")}</p><p className="mt-2 text-lg font-bold">{money(item.revenue, caps.maskRevenue)}</p><p className="text-xs text-muted-foreground">{item.orders} orders</p></button>)}</div></section>
      {selection !== null && <Card><CardHeader className="flex flex-row items-center justify-between"><CardTitle className="text-base">{selectedLabel} · {measure === "revenue" ? "Revenue" : "Orders"}</CardTitle><div className="flex gap-1"><Button size="sm" variant={measure === "revenue" ? "default" : "outline"} onClick={() => setMeasure("revenue")}>Revenue</Button><Button size="sm" variant={measure === "orders" ? "default" : "outline"} onClick={() => setMeasure("orders")}>Orders</Button></div></CardHeader><CardContent className="h-64"><ResponsiveContainer width="100%" height="100%"><AreaChart data={chart}><CartesianGrid strokeDasharray="3 3" vertical={false} /><XAxis dataKey="date" /><YAxis /><Tooltip /><Area type="monotone" dataKey={measure} stroke="hsl(var(--primary))" fill="hsl(var(--primary))" fillOpacity={0.15} /></AreaChart></ResponsiveContainer></CardContent></Card>}
      <section className={query.data.focus === "GAPS" ? "rounded-lg ring-2 ring-primary" : ""}><h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Production & supply gaps</h2><div className="grid grid-cols-2 gap-2 lg:grid-cols-5"><GapCard to="/app/backorders" icon={PackageX} label="Backorder lines" value={query.data.gaps.backorder_lines.toLocaleString()} /><GapCard to="/app/backorders" icon={AlertTriangle} label="Revenue at risk" value={money(query.data.gaps.revenue_at_risk, caps.maskRevenue)} /><GapCard to="/app/fill-rate" icon={PackageCheck} label="Fill rate" value={query.data.gaps.fill_rate_pct == null ? "—" : `${query.data.gaps.fill_rate_pct}%`} /><GapCard to="/app/production" icon={Boxes} label="Critical SKUs" value={query.data.gaps.critical_skus.toLocaleString()} /><GapCard to="/app/products-not-delivered" icon={ShoppingCart} label="Not delivered qty" value={query.data.gaps.not_delivered_qty.toLocaleString()} /></div></section>
    </>}
  </div>;
}
function PulseCard({ title, value, note, onClick }: { title: string; value: string; note: string; onClick: () => void }) { return <button onClick={onClick} className="rounded-lg border bg-card p-5 text-left hover:border-primary"><div className="flex items-center gap-2 text-sm text-muted-foreground"><TrendingUp className="h-4 w-4" />{title}</div><p className="mt-3 text-3xl font-bold">{value}</p><p className="mt-1 text-xs text-muted-foreground">{note} · click for trend</p></button>; }
function GapCard({ to, icon: Icon, label, value }: { to: string; icon: typeof Boxes; label: string; value: string }) { return <Link to={to} className="rounded-lg border bg-card p-3 hover:border-primary"><Icon className="h-4 w-4 text-muted-foreground" /><p className="mt-2 text-xs text-muted-foreground">{label}</p><p className="text-xl font-bold">{value}</p></Link>; }

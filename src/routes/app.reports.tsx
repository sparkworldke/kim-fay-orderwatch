import { createFileRoute } from "@tanstack/react-router";
import { Download, FileSpreadsheet, FileText, Sheet as SheetIcon } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { TREND, ORDERS } from "@/lib/demo-data";
import { formatKES, formatNumber } from "@/lib/format";

export const Route = createFileRoute("/app/reports")({
  head: () => ({ meta: [{ title: "Reports — Kim-Fay OrderWatch" }] }),
  component: ReportsPage,
});

function ReportsPage() {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Reports</h1>
          <p className="text-sm text-muted-foreground">Operational, revenue and SLA reports. Export to Excel, CSV or PDF.</p>
        </div>
        <div className="flex gap-1.5">
          <Button size="sm" variant="outline" onClick={() => toast.success("Excel export queued")}><FileSpreadsheet className="mr-1 h-3.5 w-3.5" />Excel</Button>
          <Button size="sm" variant="outline" onClick={() => toast.success("CSV downloaded")}><SheetIcon className="mr-1 h-3.5 w-3.5" />CSV</Button>
          <Button size="sm" variant="outline" onClick={() => toast.success("PDF export queued")}><FileText className="mr-1 h-3.5 w-3.5" />PDF</Button>
        </div>
      </div>

      <Tabs defaultValue="daily">
        <TabsList>
          <TabsTrigger value="daily">Daily</TabsTrigger>
          <TabsTrigger value="weekly">Weekly</TabsTrigger>
          <TabsTrigger value="monthly">Monthly</TabsTrigger>
        </TabsList>

        {(["daily", "weekly", "monthly"] as const).map((period) => (
          <TabsContent key={period} value={period} className="space-y-3">
            <ReportTable period={period} />
          </TabsContent>
        ))}
      </Tabs>
    </div>
  );
}

function ReportTable({ period }: { period: "daily" | "weekly" | "monthly" }) {
  const rows =
    period === "daily"
      ? TREND
      : period === "weekly"
      ? aggregate(TREND, 7)
      : aggregate(TREND, 14);

  return (
    <div className="rounded-lg border bg-card shadow-[var(--shadow-panel)]">
      <div className="flex items-center justify-between border-b px-4 py-3">
        <h3 className="text-sm font-semibold capitalize">{period} report — last {rows.length} period(s)</h3>
        <Button size="sm" variant="ghost" onClick={() => toast.success("Report scheduled")}>
          <Download className="mr-1 h-3.5 w-3.5" /> Schedule
        </Button>
      </div>
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
          <tr>
            <th className="px-4 py-2 text-left">Period</th>
            <th className="px-4 py-2 text-right">Received</th>
            <th className="px-4 py-2 text-right">Captured</th>
            <th className="px-4 py-2 text-right">Capture %</th>
            <th className="px-4 py-2 text-right">Revenue</th>
            <th className="px-4 py-2 text-right">Revenue At Risk</th>
            <th className="px-4 py-2 text-right">SLA %</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.date} className="border-t">
              <td className="px-4 py-2 font-medium">{r.date}</td>
              <td className="px-4 py-2 text-right font-mono tabular-nums">{formatNumber(r.received)}</td>
              <td className="px-4 py-2 text-right font-mono tabular-nums">{formatNumber(r.captured)}</td>
              <td className="px-4 py-2 text-right font-mono tabular-nums">{r.captureRate.toFixed(1)}%</td>
              <td className="px-4 py-2 text-right font-mono tabular-nums">{formatKES(r.revenue, { compact: true })}</td>
              <td className="px-4 py-2 text-right font-mono tabular-nums text-destructive">{formatKES(r.revenueAtRisk, { compact: true })}</td>
              <td className="px-4 py-2 text-right font-mono tabular-nums">{r.sla.toFixed(1)}%</td>
            </tr>
          ))}
          <tr className="border-t bg-muted/30 font-semibold">
            <td className="px-4 py-2">Total · {ORDERS.length} orders</td>
            <td className="px-4 py-2 text-right font-mono tabular-nums">{formatNumber(sum(rows, "received"))}</td>
            <td className="px-4 py-2 text-right font-mono tabular-nums">{formatNumber(sum(rows, "captured"))}</td>
            <td className="px-4 py-2 text-right font-mono tabular-nums">{(rows.reduce((s, r) => s + r.captureRate, 0) / rows.length).toFixed(1)}%</td>
            <td className="px-4 py-2 text-right font-mono tabular-nums">{formatKES(sum(rows, "revenue"), { compact: true })}</td>
            <td className="px-4 py-2 text-right font-mono tabular-nums">{formatKES(sum(rows, "revenueAtRisk"), { compact: true })}</td>
            <td className="px-4 py-2 text-right font-mono tabular-nums">{(rows.reduce((s, r) => s + r.sla, 0) / rows.length).toFixed(1)}%</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

function aggregate(trend: typeof TREND, size: number) {
  const out: typeof TREND = [];
  for (let i = 0; i < trend.length; i += size) {
    const slice = trend.slice(i, i + size);
    if (!slice.length) continue;
    out.push({
      date: `${slice[0]!.date} → ${slice[slice.length - 1]!.date}`,
      received: sum(slice, "received"),
      captured: sum(slice, "captured"),
      captureRate: slice.reduce((s, r) => s + r.captureRate, 0) / slice.length,
      revenue: sum(slice, "revenue"),
      revenueAtRisk: sum(slice, "revenueAtRisk"),
      sla: slice.reduce((s, r) => s + r.sla, 0) / slice.length,
    });
  }
  return out;
}

function sum<T extends Record<K, number>, K extends string>(arr: T[], key: K): number {
  return arr.reduce((s, r) => s + r[key], 0);
}

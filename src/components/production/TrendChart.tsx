import { TrendingUp } from "lucide-react";
import {
  CartesianGrid,
  Line,
  LineChart,
  ReferenceDot,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Panel } from "./Panel";
import { InfoTip } from "@/components/info-tip";
import type { MonthlySales } from "@/types/Stock/inventory";
import { buildTrendAnnotations } from "@/utils/Stock/annotations";
import { compactNumber, formatNumber } from "@/utils/Stock/format";
import type { ReactNode } from "react";

const TONE_COLOR: Record<string, string> = {
  critical: "var(--destructive)",
  warning: "var(--warning)",
  success: "var(--success)",
  info: "var(--primary)",
};

export function TrendChart({
  series,
  title = "Demand & Stock Trend",
  subtitle,
  actions,
}: {
  series: MonthlySales[];
  title?: string;
  subtitle?: string;
  actions?: ReactNode;
}) {
  const annotations = buildTrendAnnotations(series);
  const byMonth = new Map(series.map((s) => [s.month, s]));

  return (
    <Panel title={title} subtitle={subtitle} icon={TrendingUp} actions={actions} compact>
      <div className="mb-1 flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-full bg-warning" /> Ordered Quantity
          <InfoTip text="Customer demand: the total quantity placed on sales orders during the month, whether or not it was fulfilled." />
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-full bg-primary" /> Shipped Quantity
          <InfoTip text="Actual fulfilment: the quantity physically shipped to customers during the month. Use this for stock consumption and production run-rate planning." />
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-full bg-destructive" /> Missed Opportunity
          <InfoTip text="Customer demand that was not delivered: ordered quantity minus the greater of shipped quantity and quantity already on shipments." />
        </span>
      </div>
      <div className="production-chart h-[clamp(220px,28vh,300px)] w-full">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={series} margin={{ top: 32, right: 36, bottom: 8, left: 4 }}>
            <CartesianGrid stroke="var(--border)" vertical={false} />
            <XAxis
              dataKey="month"
              tickLine={false}
              axisLine={false}
              padding={{ left: 18, right: 18 }}
              tick={{ fontSize: 9, fill: "var(--muted-foreground)" }}
              interval="preserveStartEnd"
            />
            <YAxis
              tickFormatter={compactNumber}
              tickLine={false}
              axisLine={false}
              width={44}
              tick={{ fontSize: 9, fill: "var(--muted-foreground)" }}
            />
            <Tooltip
              contentStyle={{
                borderRadius: 10,
                border: "1px solid var(--border)",
                fontSize: 9,
                boxShadow: "var(--shadow-pop)",
              }}
              formatter={(value: number, name) => [formatNumber(value), name]}
            />
            <Line
              type="monotone"
              dataKey="orderedQuantity"
              name="Ordered Quantity"
              stroke="var(--warning)"
              strokeWidth={2}
              connectNulls
              dot={{ r: 2.5 }}
            />
            <Line
              type="monotone"
              dataKey="quantity"
              name="Shipped Quantity"
              stroke="var(--primary)"
              strokeWidth={2}
              connectNulls
              dot={{ r: 2.5 }}
            />
            <Line
              type="monotone"
              dataKey="missedOpportunityQuantity"
              name="Missed Opportunity"
              stroke="var(--destructive)"
              strokeWidth={2}
              connectNulls
              dot={{ r: 2.5 }}
            />
            {annotations.map((a) => {
              const point = byMonth.get(a.month);
              if (!point) return null;
              const useStock = a.label === "Low stock" || a.label === "Stockout" || a.label === "Replenishment";
              return (
                <ReferenceDot
                  key={`${a.month}-${a.label}`}
                  x={a.month}
                  y={useStock && Number.isFinite(point.stockAvailable) ? point.stockAvailable : point.quantity}
                  r={4}
                  fill={TONE_COLOR[a.tone]}
                  stroke="var(--card)"
                  strokeWidth={2}
                  label={{
                    value: a.label,
                    position: "top",
                    fontSize: 9,
                    fill: TONE_COLOR[a.tone],
                  }}
                />
              );
            })}
          </LineChart>
        </ResponsiveContainer>
      </div>
    </Panel>
  );
}

/**
 * SalesHistoryChart
 *
 * Recharts ComposedChart combining historical shipped quantities (blue bars)
 * with AI predicted quantities (amber dashed line) per calendar month.
 *
 * Shows "No sales data available for this period" when `monthly_sales` is
 * empty — no empty chart is rendered.
 *
 * Requirements: 2.5, 2.7, 2.11
 */

import { Bar, CartesianGrid, ComposedChart, Line, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from "@/components/ui/chart";
import type { SkuDetailResponse } from "@/hooks/useSkuDetail";

type MonthlySale = SkuDetailResponse["monthly_sales"][number];

export type SalesHistoryChartProps = {
  /** Combined historical + prediction-period monthly rows. */
  monthlySales: MonthlySale[];
};

const chartConfig = {
  shipped_qty: { label: "Actual Units", color: "#2563eb" },
  predicted_qty: { label: "Predicted Units", color: "#f59e0b" },
} satisfies ChartConfig;

export function SalesHistoryChart({ monthlySales }: SalesHistoryChartProps) {
  if (monthlySales.length === 0) {
    return (
      <p className="py-6 text-center text-sm text-muted-foreground">
        No sales data available for this period.
      </p>
    );
  }

  return (
    <ChartContainer config={chartConfig} className="aspect-auto h-[280px] w-full">
      <ComposedChart data={monthlySales} margin={{ left: 4, right: 12, top: 8, bottom: 0 }}>
        <CartesianGrid vertical={false} strokeDasharray="3 3" className="stroke-border/50" />
        <XAxis
          dataKey="month_label"
          tickLine={false}
          axisLine={false}
          tickMargin={8}
          minTickGap={24}
        />
        <YAxis
          tickLine={false}
          axisLine={false}
          width={40}
          allowDecimals={false}
        />
        <ChartTooltip content={<ChartTooltipContent />} />
        <Bar
          dataKey="shipped_qty"
          fill="var(--color-shipped_qty)"
          radius={4}
          name="Actual Units"
        />
        <Line
          dataKey="predicted_qty"
          type="monotone"
          stroke="var(--color-predicted_qty)"
          strokeWidth={2}
          strokeDasharray="5 5"
          dot={false}
          name="Predicted Units"
        />
      </ComposedChart>
    </ChartContainer>
  );
}

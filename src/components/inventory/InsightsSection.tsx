/**
 * InsightsSection
 *
 * Renders AI-generated insights for a SKU as a list of finding cards. Each
 * finding displays a type badge (`Promotion Impact` / `Price Change Impact` /
 * `Unexplained Variance`) and the finding text.
 *
 * Behaviour:
 * - While loading: Skeleton loader.
 * - On error: inline error message with a Retry button. The chart and
 *   comparison table remain unaffected (this section is independent).
 * - On success: finding cards, plus a note listing unavailable data sources
 *   when `data_gaps` is non-empty.
 *
 * Requirements: 3.4, 3.8, 3.9, 3.11
 */

import { AlertCircle, Lightbulb, RefreshCw, Tag, TrendingUp } from "lucide-react";
import { useSkuInsights, type InsightFindingType } from "@/hooks/useSkuInsights";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";

export type InsightsSectionProps = {
  inventoryId: string;
  dateRange: { from: Date; to: Date };
};

const FINDING_BADGE: Record<InsightFindingType, { label: string; variant: "default" | "secondary" | "outline" }> = {
  promotion_impact: { label: "Promotion Impact", variant: "default" },
  price_change_impact: { label: "Price Change Impact", variant: "secondary" },
  unexplained_variance: { label: "Unexplained Variance", variant: "outline" },
};

function toIsoDate(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function InsightsSection({ inventoryId, dateRange }: InsightsSectionProps) {
  const { data, isLoading, isError, error, refetch, isFetching } = useSkuInsights(
    inventoryId,
    toIsoDate(dateRange.from),
    toIsoDate(dateRange.to),
  );

  if (isLoading) {
    return (
      <div className="space-y-3" aria-busy="true" aria-label="Loading insights">
        {Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="rounded-lg border p-4">
            <Skeleton className="mb-2 h-5 w-40" />
            <Skeleton className="h-4 w-full" />
          </div>
        ))}
      </div>
    );
  }

  if (isError) {
    return (
      <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4">
        <div className="flex items-center gap-2 text-sm text-destructive">
          <AlertCircle className="h-4 w-4" />
          <span className="font-medium">Unable to load insights</span>
        </div>
        <p className="mt-1 text-xs text-muted-foreground">
          {error instanceof Error ? error.message : "The AI insight request failed."}
        </p>
        <Button
          variant="outline"
          size="sm"
          className="mt-3"
          onClick={() => refetch()}
          disabled={isFetching}
        >
          <RefreshCw className={`mr-2 h-3.5 w-3.5 ${isFetching ? "animate-spin" : ""}`} />
          Retry
        </Button>
      </div>
    );
  }

  const insights = data?.insights ?? [];
  const dataGaps = data?.data_gaps ?? [];

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2 text-sm font-medium">
        <Lightbulb className="h-4 w-4 text-amber-500" />
        AI Insights
      </div>

      {insights.length === 0 && (
        <p className="rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
          No notable insights for this period.
        </p>
      )}

      <ul className="space-y-3">
        {insights.map((finding, idx) => {
          const badge = FINDING_BADGE[finding.type] ?? FINDING_BADGE.unexplained_variance;
          return (
            <li key={`${finding.type}-${finding.month ?? idx}`} className="rounded-lg border p-4">
              <div className="mb-2 flex flex-wrap items-center gap-2">
                <Badge variant={badge.variant}>{badge.label}</Badge>
                {finding.month && (
                  <span className="text-xs text-muted-foreground">{finding.month}</span>
                )}
                {finding.promotion_name && (
                  <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                    <Tag className="h-3 w-3" />
                    {finding.promotion_name}
                  </span>
                )}
                {finding.price_direction && (
                  <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                    <TrendingUp className="h-3 w-3" />
                    {finding.price_direction}
                    {finding.price_magnitude != null ? ` (${finding.price_magnitude}%)` : ""}
                  </span>
                )}
              </div>
              <p className="text-sm leading-relaxed">{finding.text}</p>
            </li>
          );
        })}
      </ul>

      {dataGaps.length > 0 && (
        <div className="rounded-lg border border-amber-500/40 bg-amber-50 p-3 dark:bg-amber-950/20">
          <p className="text-xs font-medium text-amber-700 dark:text-amber-400">
            Some data sources were unavailable during analysis:
          </p>
          <ul className="mt-1 list-inside list-disc text-xs text-amber-700 dark:text-amber-400">
            {dataGaps.map((gap) => (
              <li key={gap}>{gap}</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

import { CheckCircle2, Lightbulb } from "lucide-react";
import { Panel } from "./Panel";

export function InsightCard({ insights }: { insights: string[] }) {
  return (
    <Panel title="Key Insights" icon={Lightbulb} subtitle="Rules-based observations from the selected data">
      <ul className="space-y-2.5">
        {insights.map((insight) => (
          <li key={insight} className="flex items-start gap-2 text-sm text-foreground">
            <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" />
            <span className="min-w-0">{insight}</span>
          </li>
        ))}
        {insights.length === 0 ? (
          <li className="text-sm text-muted-foreground">No insights for the current selection.</li>
        ) : null}
      </ul>
    </Panel>
  );
}
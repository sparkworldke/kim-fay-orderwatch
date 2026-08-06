import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { apiFetch } from "@/lib/api";
import { CustomerLink } from "@/components/entity-links";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { MaskedKES } from "@/components/MaskedCurrency";

export const Route = createFileRoute("/app/kp/items-not-ordered")({
  component: Page,
  head: () => ({ meta: [{ title: "Items not ordered - Kim-Fay Sight" }] }),
});

type FlatItem = {
  customer_id: string;
  customer_name: string;
  inventory_id: string;
  description: string | null;
  avg_interval_days: number;
  days_overdue: number;
  risk: string;
  opportunity_value: number;
  last_order_date?: string;
  order_count?: number;
};

type Group = {
  customer_id: string;
  customer_name: string;
  item_count: number;
  label: string;
  opportunity_value: number;
  items: FlatItem[];
};

function Page() {
  const [bucket, setBucket] = useState(30);
  const [q, setQ] = useState("");

  const result = useQuery({
    queryKey: ["items-not-ordered", bucket, q],
    queryFn: () =>
      apiFetch<{
        data: FlatItem[];
        grouped?: Group[];
        summary: {
          opportunity_value: number;
          customer_count: number;
          item_count: number;
        };
        calculation_basis?: string;
        data_as_of?: string;
      }>(`kp/items-not-ordered?bucket=${bucket}&q=${encodeURIComponent(q)}`),
  });

  const groups: Group[] = useMemo(() => {
    if (result.data?.grouped?.length) {
      return result.data.grouped.map((g) => ({
        ...g,
        label:
          g.label ||
          `${g.customer_name} · ${g.item_count} ${g.item_count === 1 ? "item" : "items"}`,
      }));
    }
    const map = new Map<string, Group>();
    for (const row of result.data?.data ?? []) {
      if (!map.has(row.customer_id)) {
        map.set(row.customer_id, {
          customer_id: row.customer_id,
          customer_name: row.customer_name,
          item_count: 0,
          label: "",
          opportunity_value: 0,
          items: [],
        });
      }
      const g = map.get(row.customer_id)!;
      g.items.push(row);
      g.item_count++;
      g.opportunity_value += Number(row.opportunity_value || 0);
    }
    return [...map.values()]
      .map((g) => ({
        ...g,
        label: `${g.customer_name} · ${g.item_count} ${g.item_count === 1 ? "item" : "items"}`,
      }))
      .sort((a, b) => b.item_count - a.item_count);
  }, [result.data]);

  return (
    <div className="space-y-5">
      <div>
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
          KP CRM
        </p>
        <h1 className="text-2xl font-semibold">Items not ordered</h1>
        <p className="text-sm text-muted-foreground">
          Recurring products overdue on each account’s order cycle —{" "}
          <strong>grouped by customer</strong> (expand a row for SKUs).
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Input
          className="max-w-xs"
          placeholder="Search customer"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        {[30, 60, 90].map((n) => (
          <Button
            key={n}
            variant={bucket === n ? "default" : "outline"}
            onClick={() => setBucket(n)}
          >
            {n}+ days
          </Button>
        ))}
      </div>

      {result.isLoading ? (
        <Skeleton className="h-64" />
      ) : result.isError ? (
        <p className="text-destructive">
          {result.error instanceof Error
            ? result.error.message
            : "Unable to load opportunities."}
        </p>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <Card>
              <CardContent className="pt-5">
                <p className="text-xs text-muted-foreground">Opportunity</p>
                <p className="text-xl font-semibold">
                  <MaskedKES value={result.data!.summary.opportunity_value} />
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-5">
                <p className="text-xs text-muted-foreground">Customers</p>
                <p className="text-xl font-semibold">
                  {result.data!.summary.customer_count}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="pt-5">
                <p className="text-xs text-muted-foreground">Overdue SKUs</p>
                <p className="text-xl font-semibold">{result.data!.summary.item_count}</p>
              </CardContent>
            </Card>
          </div>

          {groups.length === 0 ? (
            <p className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
              No overdue recurring items for this filter.
            </p>
          ) : (
            <Accordion type="multiple" className="rounded-lg border bg-card">
              {groups.map((g) => (
                <AccordionItem
                  key={g.customer_id}
                  value={g.customer_id}
                  className="border-b px-3 last:border-b-0"
                >
                  <AccordionTrigger className="hover:no-underline">
                    <div className="flex w-full flex-wrap items-center justify-between gap-2 pr-2 text-left">
                      <span className="text-sm font-semibold text-foreground">
                        {g.customer_name}
                      </span>
                      <span className="flex items-center gap-2">
                        <Badge variant="secondary" className="tabular-nums">
                          {g.item_count} {g.item_count === 1 ? "item" : "items"}
                        </Badge>
                        {g.opportunity_value > 0 && (
                          <span className="text-xs text-muted-foreground">
                            <MaskedKES value={g.opportunity_value} />
                          </span>
                        )}
                      </span>
                    </div>
                  </AccordionTrigger>
                  <AccordionContent>
                    <div className="mb-2 flex items-center gap-2 text-xs text-muted-foreground">
                      <CustomerLink
                        customerId={g.customer_id}
                        customerName={g.customer_name}
                        className="text-xs"
                      />
                      <span>· open account</span>
                    </div>
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="border-b bg-muted/30 text-left text-xs text-muted-foreground">
                            <th className="p-2 font-medium">SKU</th>
                            <th className="p-2 font-medium">Cycle</th>
                            <th className="p-2 font-medium">Overdue</th>
                            <th className="p-2 font-medium">Risk</th>
                            <th className="p-2 text-right font-medium">Opportunity</th>
                          </tr>
                        </thead>
                        <tbody>
                          {g.items.map((x) => (
                            <tr
                              key={`${x.customer_id}-${x.inventory_id}`}
                              className="border-b last:border-0"
                            >
                              <td className="p-2">
                                <span className="font-mono text-xs">{x.inventory_id}</span>
                                {x.description && (
                                  <span className="block text-xs text-muted-foreground">
                                    {x.description}
                                  </span>
                                )}
                              </td>
                              <td className="p-2 text-muted-foreground">
                                {x.avg_interval_days} days
                              </td>
                              <td className="p-2 tabular-nums">{x.days_overdue} days</td>
                              <td className="p-2">
                                <Badge
                                  variant={
                                    x.risk === "lost_sales" ? "destructive" : "secondary"
                                  }
                                >
                                  {String(x.risk).replace("_", " ")}
                                </Badge>
                              </td>
                              <td className="p-2 text-right font-medium tabular-nums">
                                <MaskedKES value={x.opportunity_value} />
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </AccordionContent>
                </AccordionItem>
              ))}
            </Accordion>
          )}

          <p className="text-xs text-muted-foreground">
            {result.data?.calculation_basis}{" "}
            {result.data?.data_as_of
              ? `Data as of ${new Date(result.data.data_as_of).toLocaleString()}.`
              : null}
          </p>
        </>
      )}
    </div>
  );
}

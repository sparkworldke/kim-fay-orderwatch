import { createFileRoute, Link, useParams } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import { useState } from "react";
import {
  BrandTable,
  MetricStrip,
  ReasonList,
  type CustomerBrandRow,
} from "@/components/customer-brand-report";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { MaskedKES } from "@/components/MaskedCurrency";
import { Skeleton } from "@/components/ui/skeleton";
import { apiFetch } from "@/lib/api";

const isoToday = () => new Date().toISOString().slice(0, 10);
const monthStart = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
};

export const Route = createFileRoute("/app/customer-brands/$customerId")({
  head: () => ({ meta: [{ title: "Customer Brands - Kim-Fay Sight" }] }),
  component: CustomerBrandDetailPage,
});

function CustomerBrandDetailPage() {
  const { customerId } = useParams({ from: "/app/customer-brands/$customerId" });
  const [dateFrom, setDateFrom] = useState(monthStart);
  const [dateTo, setDateTo] = useState(isoToday);
  const [category, setCategory] = useState("all");
  const [segment, setSegment] = useState("all");
  const categoryParam = category === "all" ? "" : `&business_category=${category}`;
  const segmentParam = segment === "all" ? "" : `&segment=${segment}`;
  const query = useQuery({
    queryKey: ["customer-brand-detail", customerId, dateFrom, dateTo, category, segment],
    queryFn: () =>
      apiFetch<CustomerBrandRow & { date_from: string; date_to: string }>(
        `dashboard/customer-brands/${encodeURIComponent(customerId)}?date_from=${dateFrom}&date_to=${dateTo}${categoryParam}${segmentParam}`,
      ),
  });
  const data = query.data;
  return (
    <div className="space-y-5">
      <div>
        <Link
          to="/app"
          className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Dashboard
        </Link>
        <h1 className="mt-2 text-2xl font-semibold">{data?.customer.name ?? customerId}</h1>
        <p className="font-mono text-xs text-muted-foreground">{customerId}</p>
      </div>
      <div className="flex flex-wrap gap-3">
        <div>
          <Label>From</Label>
          <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
        </div>
        <div>
          <Label>To</Label>
          <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
        </div>
        <div>
          <Label>Brand category</Label>
          <select
            className="flex h-10 rounded-md border bg-background px-3 text-sm"
            value={category}
            onChange={(event) => setCategory(event.target.value)}
          >
            <option value="all">All brands</option>
            <option value="manufactured">Kim-Fay Brands</option>
            <option value="trading">Trading Brands</option>
          </select>
        </div>
        <div>
          <Label>Customer segment</Label>
          <select
            className="flex h-10 rounded-md border bg-background px-3 text-sm"
            value={segment}
            onChange={(event) => setSegment(event.target.value)}
          >
            <option value="all">All segments</option>
            <option value="CS">CS</option>
            <option value="KP">KP</option>
          </select>
        </div>
      </div>
      {query.isLoading ? (
        <Skeleton className="h-56" />
      ) : query.isError ? (
        <div className="rounded border border-destructive p-4 text-destructive">
          Unable to load customer brand performance.
        </div>
      ) : data?.totals ? (
        <>
          <MetricStrip metrics={data.totals} />
          <section className="rounded border bg-card p-4">
            <h2 className="mb-3 text-lg font-semibold">Brand performance</h2>
            <BrandTable rows={data.brands} />
          </section>
          <section className="rounded border bg-card p-4">
            <h2 className="mb-3 text-lg font-semibold">Undelivered reasons</h2>
            <ReasonList rows={data.undelivered_reasons} />
          </section>
          <section className="rounded border bg-card p-4">
            <h2 className="mb-3 text-lg font-semibold">Sales orders</h2>
            <Accordion type="multiple">
              {data.sales_orders.map((order) => (
                <AccordionItem key={order.id} value={String(order.id)}>
                  <AccordionTrigger>
                    <div className="grid flex-1 grid-cols-2 gap-2 text-left md:grid-cols-5">
                      <span className="font-mono font-semibold">{order.order_nbr}</span>
                      <span>{order.order_date ?? "—"}</span>
                      <Badge variant="outline" className="w-fit">
                        {order.status ?? "Unknown"}
                      </Badge>
                      <span>Sold {order.sold_qty.toLocaleString("en-KE")}</span>
                      <span className="text-amber-700">
                        Open {order.undelivered_qty.toLocaleString("en-KE")}
                      </span>
                    </div>
                  </AccordionTrigger>
                  <AccordionContent>
                    <div className="grid gap-2 text-sm sm:grid-cols-3">
                      <div className="sm:col-span-3">
                        <BrandTable rows={order.brands} />
                      </div>
                      <div>
                        Sold value: <MaskedKES value={order.sold_value} />
                      </div>
                      <div>
                        At risk: <MaskedKES value={order.undelivered_value} />
                      </div>
                      <div className="sm:col-span-3">
                        Reasons: {order.reasons.join(", ") || "None"}
                      </div>
                    </div>
                  </AccordionContent>
                </AccordionItem>
              ))}
            </Accordion>
          </section>
        </>
      ) : (
        <div className="rounded border p-8 text-center text-muted-foreground">
          No sales-order activity for this customer in the selected period.
        </div>
      )}
    </div>
  );
}

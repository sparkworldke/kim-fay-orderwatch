import { createFileRoute, useNavigate } from "@tanstack/react-router";
import type React from "react";
import { useState } from "react";
import { Search, Send } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  useCreatePcr,
  usePcrCustomers,
  usePcrInventory,
  useResolvePcrPrice,
  type PcrCustomer,
  type PcrInventory,
} from "@/hooks/usePriceChangeRequests";
import { money, pct } from "@/lib/price-change";

export const Route = createFileRoute("/app/price-change-requests/new")({
  head: () => ({ meta: [{ title: "New Price Change Request - Kim-Fay OrderWatch" }] }),
  component: NewPriceChangePage,
});

function NewPriceChangePage() {
  const navigate = useNavigate();
  const createPcr = useCreatePcr();
  const [customerQ, setCustomerQ] = useState("");
  const [skuQ, setSkuQ] = useState("");
  const [customer, setCustomer] = useState<PcrCustomer | null>(null);
  const [sku, setSku] = useState<PcrInventory | null>(null);
  const [proposed, setProposed] = useState("");
  const [justification, setJustification] = useState("");
  const [effectiveDate, setEffectiveDate] = useState("");

  const customers = usePcrCustomers(customerQ);
  const inventory = usePcrInventory(skuQ);
  const resolved = useResolvePcrPrice(customer?.acumatica_id ?? "", sku?.inventory_id ?? "", Number(proposed));
  const canSubmit = !!customer && !!sku && Number(proposed) > 0 && justification.trim().length >= 10;

  async function submit() {
    if (!customer || !sku) return;
    try {
      const created = await createPcr.mutateAsync({
        customer_acumatica_id: customer.acumatica_id,
        inventory_id: sku.inventory_id,
        proposed_selling_price: Number(proposed),
        justification,
        effective_date_requested: effectiveDate || null,
      });
      toast.success("Price change request submitted.");
      void navigate({ to: "/app/price-change-requests/$id", params: { id: String(created.id) } });
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to submit price change request.");
    }
  }

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">New Price Change Request</h1>
          <p className="text-sm text-muted-foreground">Select a customer and SKU, then submit the proposed selling price for approval.</p>
        </div>
        <Button onClick={submit} disabled={!canSubmit || createPcr.isPending}>
          <Send className="mr-1 h-4 w-4" /> Submit
        </Button>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        <section className="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
          <div className="grid gap-4 md:grid-cols-2">
            <SearchBox
              label="Customer"
              value={customerQ}
              placeholder="Search customer"
              onChange={setCustomerQ}
              results={customers.data ?? []}
              render={(item) => <><span className="font-medium">{item.name}</span><span className="ml-2 font-mono text-xs text-muted-foreground">{item.acumatica_id}</span></>}
              onPick={(item) => { setCustomer(item); setCustomerQ(""); }}
            />
            <SearchBox
              label="SKU"
              value={skuQ}
              placeholder="Search SKU"
              onChange={setSkuQ}
              results={inventory.data ?? []}
              render={(item) => <><span className="font-mono font-medium">{item.inventory_id}</span><span className="ml-2 text-muted-foreground">{item.description}</span></>}
              onPick={(item) => { setSku(item); setSkuQ(""); }}
            />
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            <Snapshot label="Selected customer" value={customer ? `${customer.name ?? customer.acumatica_id} (${customer.acumatica_id})` : "None"} />
            <Snapshot label="Selected SKU" value={sku ? `${sku.inventory_id} - ${sku.description ?? ""}` : "None"} />
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="proposed">Proposed selling price</Label>
              <Input id="proposed" type="number" min="0" step="0.01" value={proposed} onChange={(e) => setProposed(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="effective-date">Requested effective date</Label>
              <Input id="effective-date" type="date" value={effectiveDate} onChange={(e) => setEffectiveDate(e.target.value)} />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="justification">Justification</Label>
            <Textarea id="justification" value={justification} onChange={(e) => setJustification(e.target.value)} rows={6} />
          </div>
        </section>

        <aside className="space-y-3 rounded-lg border bg-card p-4 shadow-sm">
          <h2 className="text-sm font-semibold">System price snapshot</h2>
          <Snapshot label="Current selling price" value={resolved.data ? money(resolved.data.current_selling_price as number) : "-"} />
          <Snapshot label="Source" value={resolved.data?.current_price_source ? String(resolved.data.current_price_source) : "-"} />
          {"base_price_snapshot" in (resolved.data ?? {}) && (
            <>
              <Snapshot label="Base price" value={money(resolved.data?.base_price_snapshot as number)} />
              <Snapshot label="Margin %" value={pct(resolved.data?.margin_pct_snapshot as number)} />
              <Snapshot label="Margin KES" value={money(resolved.data?.margin_kes_snapshot as number)} />
            </>
          )}
        </aside>
      </div>
    </div>
  );
}

function SearchBox<T extends { [key: string]: unknown }>({
  label,
  value,
  placeholder,
  onChange,
  results,
  render,
  onPick,
}: {
  label: string;
  value: string;
  placeholder: string;
  onChange: (value: string) => void;
  results: T[];
  render: (item: T) => React.ReactNode;
  onPick: (item: T) => void;
}) {
  return (
    <div className="relative space-y-1.5">
      <Label>{label}</Label>
      <div className="relative">
        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
        <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="pl-8" />
      </div>
      {value.length >= 1 && results.length > 0 && (
        <div className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover shadow">
          {results.map((item, index) => (
            <button key={index} type="button" onClick={() => onPick(item)} className="block w-full px-3 py-2 text-left text-sm hover:bg-muted">
              {render(item)}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function Snapshot({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border bg-muted/20 p-3">
      <div className="text-[11px] uppercase text-muted-foreground">{label}</div>
      <div className="mt-1 break-words text-sm font-medium">{value}</div>
    </div>
  );
}

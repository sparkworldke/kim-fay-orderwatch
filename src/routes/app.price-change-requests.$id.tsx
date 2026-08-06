import { Link, createFileRoute, useParams } from "@tanstack/react-router";
import { useState, type ReactNode } from "react";
import { Check, RotateCcw, Split, X } from "lucide-react";
import { toast } from "sonner";
import { CustomerLink } from "@/components/entity-links";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  useAckPcrDuplicate,
  useMarkPcrApplied,
  usePcrDecision,
  usePcrRequest,
  usePcrRespondCounter,
} from "@/hooks/usePriceChangeRequests";
import { money, pct, PCR_STATUS_CLASS, PCR_STATUS_LABEL, shortDate } from "@/lib/price-change";

export const Route = createFileRoute("/app/price-change-requests/$id")({
  head: () => ({ meta: [{ title: "Price Change Request - Kim-Fay Sight" }] }),
  component: PriceChangeDetailPage,
});

function PriceChangeDetailPage() {
  const { id } = useParams({ from: "/app/price-change-requests/$id" });
  const pcr = usePcrRequest(id);
  const decision = usePcrDecision(id);
  const respondCounter = usePcrRespondCounter(id);
  const ack = useAckPcrDuplicate(id);
  const applyErp = useMarkPcrApplied(id);
  const [comment, setComment] = useState("");
  const [counterMode, setCounterMode] = useState(false);
  const [revisedPrice, setRevisedPrice] = useState("");
  const row = pcr.data;

  async function decide(next: "approved" | "rejected") {
    try {
      await decision.mutateAsync({ decision: next, comment });
      setComment("");
      toast.success(next === "approved" ? "Stage approved." : "Request rejected.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to record decision.");
    }
  }

  async function submitCounter() {
    const price = Number(revisedPrice);
    if (!Number.isFinite(price) || price <= 0) {
      toast.error("Enter a valid revised price.");
      return;
    }
    if (!comment.trim()) {
      toast.error("A comment is required when countering.");
      return;
    }
    try {
      await decision.mutateAsync({ decision: "countered", comment, revised_price: price });
      setComment("");
      setRevisedPrice("");
      setCounterMode(false);
      toast.success("Counter sent to requester.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to send counter.");
    }
  }

  async function respondToCounter(action: "accept" | "withdraw") {
    try {
      await respondCounter.mutateAsync({ action });
      toast.success(action === "accept" ? "Revised price accepted — resubmitted for approval." : "Request withdrawn.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to respond to counter.");
    }
  }

  async function acknowledge() {
    try {
      await ack.mutateAsync();
      toast.success("Duplicate warning acknowledged.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to acknowledge duplicate.");
    }
  }

  async function markApplied() {
    try {
      await applyErp.mutateAsync();
      toast.success("Marked applied in ERP.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to mark applied.");
    }
  }

  if (pcr.isLoading) return <div className="text-sm text-muted-foreground">Loading price change request...</div>;
  if (!row) return <div className="text-sm text-muted-foreground">Price change request not found.</div>;

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link to="/app/price-change-requests" className="text-xs text-muted-foreground hover:underline">
            Back to price changes
          </Link>
          <h1 className="mt-1 text-xl font-semibold tracking-tight">{row.public_ref}</h1>
          <p className="text-sm text-muted-foreground">
            <CustomerLink customerId={row.customer_acumatica_id} customerName={row.customer_name} /> · {row.inventory_id}
          </p>
        </div>
        <Badge variant="outline" className={PCR_STATUS_CLASS[row.status]}>{PCR_STATUS_LABEL[row.status]}</Badge>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <section className="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
          <h2 className="text-sm font-semibold">Snapshot</h2>
          <div className="grid gap-3 md:grid-cols-3">
            <Snapshot
              label="Customer"
              value={
                <CustomerLink customerId={row.customer_acumatica_id} customerName={row.customer_name} showId />
              }
            />
            <Snapshot label="Price class" value={row.customer_price_class ?? "-"} />
            <Snapshot label="Payment terms" value={row.customer_payment_terms ?? "-"} />
            <Snapshot label="SKU" value={`${row.inventory_id} - ${row.product_description ?? ""}`} />
            <Snapshot label="Current price" value={money(row.current_selling_price)} />
            <Snapshot label="Proposed price" value={money(row.proposed_selling_price)} />
            {row.discount_pct != null && (
              <Snapshot
                label={row.discount_pct >= 0 ? "Discount asked" : "Increase asked"}
                value={pct(Math.abs(row.discount_pct))}
              />
            )}
            {"base_price_snapshot" in row && <Snapshot label="Base price" value={money(row.base_price_snapshot)} />}
            {"margin_pct_snapshot" in row && <Snapshot label="Margin %" value={pct(row.margin_pct_snapshot)} />}
            {"margin_kes_snapshot" in row && <Snapshot label="Margin KES" value={money(row.margin_kes_snapshot)} />}
          </div>
          <div className="rounded-md border bg-muted/20 p-3">
            <div className="text-[11px] uppercase text-muted-foreground">Justification</div>
            <p className="mt-1 whitespace-pre-wrap text-sm">{row.justification}</p>
          </div>

          {row.status === "countered" && (
            <div className="rounded-md border border-orange-200 bg-orange-50 p-3">
              <div className="text-[11px] uppercase text-orange-700">Approver countered</div>
              <p className="mt-1 text-sm text-orange-900">
                Revised price: <span className="font-semibold">{money(row.revised_price)}</span>
                {row.countered_at && <span className="ml-2 text-xs text-orange-700">{shortDate(row.countered_at)}</span>}
              </p>
              {row.can_actor_respond_counter && (
                <div className="mt-2 grid grid-cols-2 gap-2">
                  <Button variant="outline" size="sm" onClick={() => respondToCounter("withdraw")} disabled={respondCounter.isPending}>
                    Withdraw
                  </Button>
                  <Button size="sm" onClick={() => respondToCounter("accept")} disabled={respondCounter.isPending}>
                    Accept revised price
                  </Button>
                </div>
              )}
            </div>
          )}

          {!!row.lowest_prices?.length && (
            <div className="rounded-md border">
              <div className="border-b bg-muted/40 px-3 py-2 text-[11px] font-semibold uppercase text-muted-foreground">
                Lowest 5 selling prices — {row.inventory_id}
              </div>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/20 text-left text-[11px] uppercase text-muted-foreground">
                    <th className="px-3 py-1.5">Customer</th>
                    <th className="px-3 py-1.5 text-right">Selling price</th>
                  </tr>
                </thead>
                <tbody>
                  {row.lowest_prices.map((lp) => (
                    <tr key={lp.customer_acumatica_id} className="border-b last:border-0">
                      <td className="px-3 py-1.5">
                        <CustomerLink customerId={lp.customer_acumatica_id} customerName={lp.customer_name} />
                      </td>
                      <td className="px-3 py-1.5 text-right tabular-nums">{money(lp.selling_price)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <aside className="space-y-4">
          <section className="space-y-3 rounded-lg border bg-card p-4 shadow-sm">
            <h2 className="text-sm font-semibold">Actions</h2>
            {row.duplicate_ack_required && !row.duplicate_acked_at && (
              <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Duplicate request warning for the same customer and SKU in the last 48 hours.
                {row.can_actor_ack_duplicate && (
                  <Button type="button" size="sm" variant="outline" className="mt-2 w-full" onClick={acknowledge}>
                    Acknowledge duplicate
                  </Button>
                )}
              </div>
            )}
            {row.can_actor_approve && (
              <>
                <Textarea placeholder="Required approval, rejection, or counter comment" value={comment} onChange={(e) => setComment(e.target.value)} />
                {counterMode ? (
                  <>
                    <Input
                      type="number"
                      min={0.01}
                      step="0.01"
                      placeholder="Revised price"
                      value={revisedPrice}
                      onChange={(e) => setRevisedPrice(e.target.value)}
                    />
                    <div className="grid grid-cols-2 gap-2">
                      <Button variant="outline" onClick={() => setCounterMode(false)} disabled={decision.isPending}>
                        Cancel
                      </Button>
                      <Button onClick={submitCounter} disabled={decision.isPending}>
                        Send counter
                      </Button>
                    </div>
                  </>
                ) : (
                  <div className="grid grid-cols-3 gap-2">
                    <Button variant="outline" onClick={() => decide("rejected")} disabled={decision.isPending}>
                      <X className="mr-1 h-4 w-4" /> Reject
                    </Button>
                    <Button variant="outline" onClick={() => setCounterMode(true)} disabled={decision.isPending}>
                      <Split className="mr-1 h-4 w-4" /> Counter
                    </Button>
                    <Button onClick={() => decide("approved")} disabled={decision.isPending}>
                      <Check className="mr-1 h-4 w-4" /> Approve
                    </Button>
                  </div>
                )}
              </>
            )}
            {row.can_actor_apply_erp && row.status === "pending_erp_apply" && (
              <Button className="w-full" onClick={markApplied} disabled={applyErp.isPending}>
                <RotateCcw className="mr-1 h-4 w-4" /> Mark applied in ERP
              </Button>
            )}
            {!row.can_actor_approve &&
              !(row.can_actor_apply_erp && row.status === "pending_erp_apply") &&
              !(row.status === "countered" && row.can_actor_respond_counter) && (
                <p className="text-sm text-muted-foreground">No action is currently available for your account.</p>
              )}
          </section>

          <section className="space-y-3 rounded-lg border bg-card p-4 shadow-sm">
            <h2 className="text-sm font-semibold">Timeline</h2>
            {(row.events ?? []).length === 0 && <p className="text-sm text-muted-foreground">No events yet.</p>}
            {(row.events ?? []).map((event) => (
              <div key={event.id} className="border-l pl-3 text-sm">
                <div className="font-medium">{event.event_type.replace(/_/g, " ")}</div>
                <div className="text-xs text-muted-foreground">{shortDate(event.created_at)}</div>
                {event.comment && <div className="mt-1 text-xs">{event.comment}</div>}
              </div>
            ))}
          </section>
        </aside>
      </div>
    </div>
  );
}

function Snapshot({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="rounded-md border bg-muted/20 p-3">
      <div className="text-[11px] uppercase text-muted-foreground">{label}</div>
      <div className="mt-1 break-words text-sm font-medium">{value}</div>
    </div>
  );
}

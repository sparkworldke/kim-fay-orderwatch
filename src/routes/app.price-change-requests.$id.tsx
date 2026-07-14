import { Link, createFileRoute, useParams } from "@tanstack/react-router";
import { useState } from "react";
import { Check, RotateCcw, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import {
  useAckPcrDuplicate,
  useMarkPcrApplied,
  usePcrDecision,
  usePcrRequest,
} from "@/hooks/usePriceChangeRequests";
import { money, pct, PCR_STATUS_CLASS, PCR_STATUS_LABEL, shortDate } from "@/lib/price-change";

export const Route = createFileRoute("/app/price-change-requests/$id")({
  head: () => ({ meta: [{ title: "Price Change Request - Kim-Fay OrderWatch" }] }),
  component: PriceChangeDetailPage,
});

function PriceChangeDetailPage() {
  const { id } = useParams({ from: "/app/price-change-requests/$id" });
  const pcr = usePcrRequest(id);
  const decision = usePcrDecision(id);
  const ack = useAckPcrDuplicate(id);
  const applyErp = useMarkPcrApplied(id);
  const [comment, setComment] = useState("");
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
          <p className="text-sm text-muted-foreground">{row.customer_name ?? row.customer_acumatica_id} · {row.inventory_id}</p>
        </div>
        <Badge variant="outline" className={PCR_STATUS_CLASS[row.status]}>{PCR_STATUS_LABEL[row.status]}</Badge>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <section className="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
          <h2 className="text-sm font-semibold">Snapshot</h2>
          <div className="grid gap-3 md:grid-cols-3">
            <Snapshot label="Customer" value={`${row.customer_name ?? row.customer_acumatica_id} (${row.customer_acumatica_id})`} />
            <Snapshot label="Customer class" value={row.customer_price_class ?? "-"} />
            <Snapshot label="Payment terms" value={row.customer_payment_terms ?? "-"} />
            <Snapshot label="SKU" value={`${row.inventory_id} - ${row.product_description ?? ""}`} />
            <Snapshot label="Current price" value={money(row.current_selling_price)} />
            <Snapshot label="Proposed price" value={money(row.proposed_selling_price)} />
            {"base_price_snapshot" in row && <Snapshot label="Base price" value={money(row.base_price_snapshot)} />}
            {"margin_pct_snapshot" in row && <Snapshot label="Margin %" value={pct(row.margin_pct_snapshot)} />}
            {"margin_kes_snapshot" in row && <Snapshot label="Margin KES" value={money(row.margin_kes_snapshot)} />}
          </div>
          <div className="rounded-md border bg-muted/20 p-3">
            <div className="text-[11px] uppercase text-muted-foreground">Justification</div>
            <p className="mt-1 whitespace-pre-wrap text-sm">{row.justification}</p>
          </div>
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
                <Textarea placeholder="Required approval or rejection comment" value={comment} onChange={(e) => setComment(e.target.value)} />
                <div className="grid grid-cols-2 gap-2">
                  <Button variant="outline" onClick={() => decide("rejected")} disabled={decision.isPending}>
                    <X className="mr-1 h-4 w-4" /> Reject
                  </Button>
                  <Button onClick={() => decide("approved")} disabled={decision.isPending}>
                    <Check className="mr-1 h-4 w-4" /> Approve
                  </Button>
                </div>
              </>
            )}
            {row.can_actor_apply_erp && row.status === "pending_erp_apply" && (
              <Button className="w-full" onClick={markApplied} disabled={applyErp.isPending}>
                <RotateCcw className="mr-1 h-4 w-4" /> Mark applied in ERP
              </Button>
            )}
            {!row.can_actor_approve && !(row.can_actor_apply_erp && row.status === "pending_erp_apply") && (
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

function Snapshot({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border bg-muted/20 p-3">
      <div className="text-[11px] uppercase text-muted-foreground">{label}</div>
      <div className="mt-1 break-words text-sm font-medium">{value}</div>
    </div>
  );
}

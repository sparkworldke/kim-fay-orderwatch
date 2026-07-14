import { Link, createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { ArrowLeft, CheckCircle2, Link2, RefreshCw, UserRoundCog, XCircle } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { useCapabilities } from "@/hooks/useCapabilities";
import { useAssignFolTechnician, useFolDecision, useFolPoLink, useFolRequest, useFolSoLink, useFolTechnicians, useResolveFolTechnician } from "@/hooks/useFol";
import { useAuth } from "@/lib/auth";
import { FOL_STATUS_CLASS, FOL_STATUS_LABEL, formatFolDate } from "@/lib/fol";

export const Route = createFileRoute("/app/kp/fol/$id")({
  head: () => ({ meta: [{ title: "KP FOL Request - Kim-Fay OrderWatch" }] }),
  component: FolDetailPage,
});

function FolDetailPage() {
  const { id } = Route.useParams();
  const { session } = useAuth();
  const caps = useCapabilities();
  const request = useFolRequest(id);
  const decision = useFolDecision(id);
  const soLink = useFolSoLink(id);
  const poLink = useFolPoLink(id);
  const assignTechnician = useAssignFolTechnician(id);
  const resolveTechnician = useResolveFolTechnician(id);
  const [comment, setComment] = useState("");
  const [soNbr, setSoNbr] = useState("");
  const [poNumber, setPoNumber] = useState("");
  const [technicianId, setTechnicianId] = useState("");
  const permissions = caps.permissions ?? [];
  const fol = request.data;
  const canApprove = permissions.includes("kp.fol.approve") && fol && ["submitted", "in_approval"].includes(fol.status);
  const canInvoice = permissions.includes("kp.fol.invoice") && fol && ["ready_for_invoicing", "so_linked", "invoiced", "fulfilled"].includes(fol.status);
  const canPoMatch = fol && ["ready_for_invoicing", "so_linked", "invoiced", "fulfilled"].includes(fol.status);
  const canAssignTechnician = permissions.includes("kp.fol.install.manage");
  const isAssignedTech =
    !!fol?.assigned_technician_user_id &&
    session?.id === fol.assigned_technician_user_id &&
    permissions.includes("kp.fol.install.execute");
  const canResolve =
    fol &&
    fol.status !== "fulfilled" &&
    fol.status !== "rejected" &&
    (isAssignedTech || canAssignTechnician) &&
    !!fol.assigned_technician_user_id;
  const technicians = useFolTechnicians(Boolean(canAssignTechnician));

  async function decide(next: "approved" | "rejected") {
    try {
      await decision.mutateAsync({ decision: next, comment });
      setComment("");
      toast.success(next === "approved" ? "Approval recorded." : "Rejection recorded.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to record decision.");
    }
  }

  async function linkSo() {
    try {
      await soLink.mutateAsync({ acumatica_order_nbr: soNbr });
      setSoNbr("");
      toast.success("Sales order linked.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to link sales order.");
    }
  }

  async function linkPo() {
    try {
      await poLink.mutateAsync({ po_number: poNumber });
      setPoNumber("");
      toast.success("Customer PO matched to sales order.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to match customer PO.");
    }
  }

  async function saveTechnician() {
    try {
      await assignTechnician.mutateAsync({ technician_user_id: Number(technicianId) });
      toast.success("Technician assigned.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to assign technician.");
    }
  }

  async function markResolved() {
    try {
      await resolveTechnician.mutateAsync({ comment: "Resolved from FOL detail" });
      toast.success("Allocation marked resolved.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to resolve allocation.");
    }
  }

  if (request.isLoading) {
    return <div className="space-y-3">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>;
  }

  if (!fol) {
    return <div className="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground">FOL request could not be loaded.</div>;
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="space-y-1">
          <Button asChild variant="ghost" size="sm" className="-ml-2">
            <Link to="/app/kp/fol"><ArrowLeft className="mr-1 h-4 w-4" /> Back</Link>
          </Button>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold tracking-tight">{fol.public_ref}</h1>
            <Badge variant="outline" className={FOL_STATUS_CLASS[fol.status]}>{FOL_STATUS_LABEL[fol.status]}</Badge>
          </div>
          <p className="text-sm text-muted-foreground">{fol.customer_name} · {fol.customer_acumatica_id}</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => request.refetch()}>
          <RefreshCw className="mr-1 h-3.5 w-3.5" /> Refresh
        </Button>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
        <main className="space-y-4">
          <section className="rounded-lg border bg-card p-4 shadow-sm">
            <h2 className="text-sm font-semibold">Request snapshot</h2>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Info label="Requestor" value={`${fol.requestor_first_name} ${fol.requestor_last_name}`} />
              <Info label="Phone" value={fol.requestor_phone} />
              <Info label="Email" value={fol.requestor_email} />
              <Info label="Origin" value={fol.request_origin.replaceAll("_", " ")} />
              <Info label="Last purchase" value={fol.consumables_last_purchase_date ?? "-"} />
              <Info label="6m sales" value={`KES ${Number(fol.consumables_sales_6m_kes ?? 0).toLocaleString()}`} />
              <Info label="6m volume" value={Number(fol.consumables_volume_6m ?? 0).toLocaleString()} />
              <Info label="Stage" value={fol.current_stage_key ?? "-"} />
              <Info label="Technician" value={fol.assigned_technician?.name ?? "-"} />
            </div>
            <div className="mt-4 grid gap-4 md:grid-cols-2">
              <TextBlock label="Reason" value={fol.reason_text} />
              <TextBlock label="Debt explanation" value={fol.debt_explanation} />
            </div>
          </section>

          <section className="overflow-x-auto rounded-lg border bg-card shadow-sm">
            <table className="w-full text-sm">
              <thead className="bg-muted/40"><tr><th className="px-4 py-2.5 text-left">SKU</th><th className="px-4 py-2.5 text-left">Description</th><th className="px-4 py-2.5 text-left">Qty</th><th className="px-4 py-2.5 text-left">Prior issued</th></tr></thead>
              <tbody className="divide-y">
                {fol.lines.map((line) => (
                  <tr key={line.inventory_id}>
                    <td className="px-4 py-2.5 font-mono text-xs">{line.inventory_id}</td>
                    <td className="px-4 py-2.5">{line.product_description ?? "-"}</td>
                    <td className="px-4 py-2.5">{line.qty_requested}</td>
                    <td className="px-4 py-2.5 text-muted-foreground">{line.qty_previously_issued ?? 0} · {line.date_last_issue ?? "never"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>

          <section className="rounded-lg border bg-card p-4 shadow-sm">
            <h2 className="text-sm font-semibold">Attachments</h2>
            <div className="mt-3 flex flex-wrap gap-2">
              {fol.attachments.length === 0 && <span className="text-sm text-muted-foreground">No attachments.</span>}
              {fol.attachments.map((file) => <Badge key={file.id} variant="outline">{file.original_name}</Badge>)}
            </div>
          </section>
        </main>

        <aside className="space-y-4">
          {canApprove && (
            <section className="rounded-lg border bg-card p-4 shadow-sm">
              <h2 className="text-sm font-semibold">Approval decision</h2>
              <Textarea className="mt-3" placeholder="Required comment" value={comment} onChange={(e) => setComment(e.target.value)} />
              <div className="mt-3 grid grid-cols-2 gap-2">
                <Button variant="outline" onClick={() => decide("rejected")} disabled={decision.isPending}>
                  <XCircle className="mr-1 h-4 w-4" /> Reject
                </Button>
                <Button onClick={() => decide("approved")} disabled={decision.isPending}>
                  <CheckCircle2 className="mr-1 h-4 w-4" /> Approve
                </Button>
              </div>
            </section>
          )}

          {canInvoice && (
            <section className="rounded-lg border bg-card p-4 shadow-sm">
              <h2 className="text-sm font-semibold">Match by SO number</h2>
              <Label className="mt-3 block">Acumatica SO number</Label>
              <div className="mt-1 flex gap-2">
                <Input value={soNbr} onChange={(e) => setSoNbr(e.target.value)} placeholder="SO..." />
                <Button onClick={linkSo} disabled={!soNbr || soLink.isPending}>
                  <Link2 className="h-4 w-4" />
                </Button>
              </div>
            </section>
          )}

          {canPoMatch && (
            <section className="rounded-lg border bg-card p-4 shadow-sm">
              <h2 className="text-sm font-semibold">Match by customer PO</h2>
              <Label className="mt-3 block">Customer PO number</Label>
              <div className="mt-1 flex gap-2">
                <Input value={poNumber} onChange={(e) => setPoNumber(e.target.value)} placeholder="PO..." />
                <Button onClick={linkPo} disabled={!poNumber || poLink.isPending}>
                  <Link2 className="h-4 w-4" />
                </Button>
              </div>
            </section>
          )}

          {(fol.so_links ?? []).length > 0 && (
            <section className="rounded-lg border bg-card p-4 shadow-sm">
              <h2 className="text-sm font-semibold">Linked sales orders</h2>
              <div className="mt-3 flex flex-wrap gap-1.5">
                {(fol.so_links ?? []).map((link) => (
                  <Badge key={link.id} variant="outline" className="gap-1">
                    {link.acumatica_order_nbr}
                    <span className="text-muted-foreground">· {link.link_type.replaceAll("_", " ")}</span>
                    {link.po_number && <span className="text-muted-foreground">· PO {link.po_number}</span>}
                  </Badge>
                ))}
              </div>
            </section>
          )}

          {canAssignTechnician && (
            <section className="rounded-lg border bg-card p-4 shadow-sm">
              <h2 className="text-sm font-semibold">Technician assignment</h2>
              <div className="mt-3 rounded-md border bg-muted/30 p-2 text-sm">
                <div className="text-[10px] uppercase text-muted-foreground">Current</div>
                <div className="mt-1 font-medium">{fol.assigned_technician?.name ?? "Unassigned"}</div>
              </div>
              <div className="mt-3 flex gap-2">
                <Select value={technicianId} onValueChange={setTechnicianId} disabled={technicians.isLoading}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select technician" />
                  </SelectTrigger>
                  <SelectContent>
                    {(technicians.data ?? []).map((tech) => (
                      <SelectItem key={tech.id} value={String(tech.id)}>
                        {tech.name} {tech.email ? `· ${tech.email}` : ""}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Button onClick={saveTechnician} disabled={!technicianId || assignTechnician.isPending}>
                  <UserRoundCog className="h-4 w-4" />
                </Button>
              </div>
            </section>
          )}

          {canResolve && (
            <section className="rounded-lg border border-amber-500/30 bg-card p-4 shadow-sm">
              <h2 className="text-sm font-semibold">Technician resolve</h2>
              <p className="mt-1 text-xs text-muted-foreground">
                Mark this allocation complete when the account install / service is done.
              </p>
              <Button
                className="mt-3 w-full"
                onClick={() => void markResolved()}
                disabled={resolveTechnician.isPending}
              >
                <CheckCircle2 className="mr-1 h-4 w-4" />
                {resolveTechnician.isPending ? "Saving…" : "Mark resolved"}
              </Button>
            </section>
          )}

          {fol.status === "fulfilled" && fol.assigned_technician && (
            <section className="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4 shadow-sm">
              <h2 className="text-sm font-semibold text-emerald-800 dark:text-emerald-200">Resolved</h2>
              <p className="mt-1 text-xs text-muted-foreground">
                Fulfilled · Technician {fol.assigned_technician.name}
              </p>
            </section>
          )}

          <section className="rounded-lg border bg-card p-4 shadow-sm">
            <h2 className="text-sm font-semibold">Timeline</h2>
            <div className="mt-3 space-y-3">
              {(fol.events ?? []).map((event) => (
                <div key={event.id} className="border-l-2 border-muted pl-3">
                  <div className="text-sm font-medium">{event.event_type.replaceAll("_", " ")}</div>
                  <div className="text-xs text-muted-foreground">{formatFolDate(event.created_at)}</div>
                  {event.comment && <div className="mt-1 text-xs">{event.comment}</div>}
                </div>
              ))}
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return <div className="rounded-md border bg-muted/30 p-2"><div className="text-[10px] uppercase text-muted-foreground">{label}</div><div className="mt-1 text-sm font-medium">{value}</div></div>;
}

function TextBlock({ label, value }: { label: string; value: string }) {
  return <div><div className="text-xs font-semibold uppercase text-muted-foreground">{label}</div><p className="mt-1 whitespace-pre-wrap text-sm">{value}</p></div>;
}

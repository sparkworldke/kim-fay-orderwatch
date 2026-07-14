import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import { Plus, Save, Search, Send, Trash2, Upload } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  useCreateFol,
  useFolCustomers,
  useFolInventory,
  useFolMetrics,
  submitFolRequest,
  uploadFolAttachments,
  type FolCustomer,
  type FolInput,
  type FolInventoryItem,
  type FolLine,
} from "@/hooks/useFol";

export const Route = createFileRoute("/app/kp/fol/new")({
  head: () => ({ meta: [{ title: "New KP FOL - Kim-Fay OrderWatch" }] }),
  component: NewFolPage,
});

const ISSUE_TYPES = [
  ["new_dispenser", "New dispenser"],
  ["fol_batteries", "FOL batteries"],
  ["maintenance_parts", "Maintenance parts"],
  ["replacement", "Replacement"],
] as const;

function NewFolPage() {
  const navigate = useNavigate();
  const createFol = useCreateFol();
  const [customerQ, setCustomerQ] = useState("");
  const [skuQ, setSkuQ] = useState("");
  const [customer, setCustomer] = useState<FolCustomer | null>(null);
  const [selectedSku, setSelectedSku] = useState<FolInventoryItem | null>(null);
  const [files, setFiles] = useState<File[]>([]);
  const [draftId, setDraftId] = useState<number | null>(null);
  const [form, setForm] = useState<FolInput>({
    customer_acumatica_id: "",
    request_origin: "sales_consultant_visit",
    requestor_first_name: "",
    requestor_last_name: "",
    requestor_phone: "",
    requestor_email: "",
    issue_types: [],
    reason_text: "",
    installation_required: false,
    customer_has_submitted_po: false,
    consumables_metrics_source: "system_so",
    debt_explanation: "",
    lines: [],
  });

  const customers = useFolCustomers(customerQ);
  const inventory = useFolInventory(skuQ);
  const metrics = useFolMetrics(form.customer_acumatica_id, form.lines.map((line) => line.inventory_id));

  const canSubmit = files.length > 0;

  function pickCustomer(next: FolCustomer) {
    setCustomer(next);
    setCustomerQ("");
    setForm((prev) => ({ ...prev, customer_acumatica_id: next.acumatica_id }));
  }

  function addLine() {
    if (!selectedSku) return;
    const prior = metrics.data?.prior_issued?.[selectedSku.inventory_id];
    const line: FolLine = {
      inventory_id: selectedSku.inventory_id,
      product_description: selectedSku.description,
      qty_requested: 1,
      qty_previously_issued: prior?.qty ?? 0,
      date_last_issue: prior?.date ?? null,
    };
    setForm((prev) => ({ ...prev, lines: [...prev.lines, line] }));
    setSelectedSku(null);
    setSkuQ("");
  }

  async function saveDraft() {
    try {
      const metricsData = metrics.data?.metrics;
      const payload: FolInput = {
        ...form,
        consumables_last_purchase_date: form.consumables_last_purchase_date ?? metricsData?.last_purchase_date ?? null,
        consumables_sales_6m_kes: form.consumables_sales_6m_kes ?? metricsData?.sales_6m_kes ?? 0,
        consumables_volume_6m: form.consumables_volume_6m ?? metricsData?.volume_6m ?? 0,
      };
      const created = await createFol.mutateAsync(payload);
      setDraftId(created.id);
      if (files.length > 0) await uploadFolAttachments(created.id, files);
      toast.success("FOL draft saved.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to save FOL draft.");
    }
  }

  async function submit() {
    try {
      let id = draftId;
      if (id === null) {
        const created = await createFol.mutateAsync(form);
        id = created.id;
        setDraftId(id);
      }
      if (files.length > 0) await uploadFolAttachments(id, files);
      await submitFolRequest(id);
      toast.success("FOL request submitted.");
      void navigate({ to: "/app/kp/fol/$id", params: { id: String(id) } });
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to submit FOL request.");
    }
  }

  const metricCards = useMemo(() => metrics.data?.metrics, [metrics.data]);

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">New KP FOL Request</h1>
          <p className="text-sm text-muted-foreground">Create a portfolio-scoped FOL requisition with SO-backed metrics.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={saveDraft} disabled={createFol.isPending}>
            <Save className="mr-1 h-4 w-4" /> Save Draft
          </Button>
          <Button onClick={submit} disabled={createFol.isPending || !canSubmit}>
            <Send className="mr-1 h-4 w-4" /> Submit
          </Button>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
        <section className="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
          <h2 className="text-sm font-semibold">Customer and contact</h2>
          <div className="relative">
            <Label>KP account</Label>
            <div className="relative mt-1">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input value={customerQ} onChange={(e) => setCustomerQ(e.target.value)} placeholder="Search KP customer" className="pl-8" />
            </div>
            {customers.data && customerQ.length >= 2 && (
              <div className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover shadow">
                {customers.data.map((item) => (
                  <button key={item.acumatica_id} type="button" onClick={() => pickCustomer(item)} className="block w-full px-3 py-2 text-left text-sm hover:bg-muted">
                    <span className="font-medium">{item.name}</span>
                    <span className="ml-2 font-mono text-xs text-muted-foreground">{item.acumatica_id}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
          {customer && (
            <div className="rounded-md border bg-muted/30 p-3 text-sm">
              <div className="font-medium">{customer.name}</div>
              <div className="font-mono text-xs text-muted-foreground">{customer.acumatica_id} · {customer.customer_class ?? "KP"}</div>
            </div>
          )}
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="First name" value={form.requestor_first_name} onChange={(v) => setForm({ ...form, requestor_first_name: v })} />
            <Field label="Last name" value={form.requestor_last_name} onChange={(v) => setForm({ ...form, requestor_last_name: v })} />
            <Field label="Phone" value={form.requestor_phone} onChange={(v) => setForm({ ...form, requestor_phone: v })} />
            <Field label="Email" value={form.requestor_email} onChange={(v) => setForm({ ...form, requestor_email: v })} />
          </div>
        </section>

        <section className="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
          <h2 className="text-sm font-semibold">SO-backed metrics</h2>
          <div className="grid grid-cols-3 gap-2">
            <Metric label="Last purchase" value={metricCards?.last_purchase_date ?? "-"} />
            <Metric label="6m sales" value={`KES ${(metricCards?.sales_6m_kes ?? 0).toLocaleString()}`} />
            <Metric label="6m volume" value={(metricCards?.volume_6m ?? 0).toLocaleString()} />
          </div>
          <Label>Metrics source</Label>
          <Select value={form.consumables_metrics_source} onValueChange={(v) => setForm({ ...form, consumables_metrics_source: v as "system_so" | "manual_override" })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="system_so">Use system SO figures</SelectItem>
              <SelectItem value="manual_override">Manual override</SelectItem>
            </SelectContent>
          </Select>
          {form.consumables_metrics_source === "manual_override" && (
            <Textarea placeholder="Reason for overriding SO figures" value={form.consumables_override_reason ?? ""} onChange={(e) => setForm({ ...form, consumables_override_reason: e.target.value })} />
          )}
          <Textarea placeholder="Debt explanation" value={form.debt_explanation} onChange={(e) => setForm({ ...form, debt_explanation: e.target.value })} />
        </section>
      </div>

      <section className="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
        <h2 className="text-sm font-semibold">FOL lines</h2>
        <div className="grid gap-2 md:grid-cols-[1fr_auto]">
          <div className="relative">
            <Input value={skuQ} onChange={(e) => setSkuQ(e.target.value)} placeholder="Search FOL-eligible SKU" />
            {inventory.data && skuQ.length >= 1 && (
              <div className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover shadow">
                {inventory.data.map((item) => (
                  <button key={item.inventory_id} type="button" onClick={() => setSelectedSku(item)} className="block w-full px-3 py-2 text-left text-sm hover:bg-muted">
                    <span className="font-mono font-medium">{item.inventory_id}</span>
                    <span className="ml-2 text-muted-foreground">{item.description}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
          <Button type="button" onClick={addLine} disabled={!selectedSku}>
            <Plus className="mr-1 h-4 w-4" /> Add line
          </Button>
        </div>
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <thead className="bg-muted/40"><tr><th className="px-3 py-2 text-left">SKU</th><th className="px-3 py-2 text-left">Qty</th><th className="px-3 py-2 text-left">Prior</th><th /></tr></thead>
            <tbody className="divide-y">
              {form.lines.length === 0 && <tr><td colSpan={4} className="px-3 py-8 text-center text-muted-foreground">Add at least one FOL line.</td></tr>}
              {form.lines.map((line, index) => (
                <tr key={`${line.inventory_id}-${index}`}>
                  <td className="px-3 py-2"><div className="font-mono">{line.inventory_id}</div><div className="text-xs text-muted-foreground">{line.product_description}</div></td>
                  <td className="px-3 py-2"><Input type="number" min={1} value={line.qty_requested} onChange={(e) => {
                    const next = [...form.lines]; next[index] = { ...line, qty_requested: Number(e.target.value) }; setForm({ ...form, lines: next });
                  }} className="h-8 w-24" /></td>
                  <td className="px-3 py-2 text-xs text-muted-foreground">{line.qty_previously_issued ?? 0} · {line.date_last_issue ?? "never"}</td>
                  <td className="px-3 py-2 text-right"><Button variant="ghost" size="icon" onClick={() => setForm({ ...form, lines: form.lines.filter((_, i) => i !== index) })}><Trash2 className="h-4 w-4" /></Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="grid gap-4 rounded-lg border bg-card p-4 shadow-sm md:grid-cols-2">
        <div className="space-y-3">
          <h2 className="text-sm font-semibold">Issue and site</h2>
          <Select value={form.request_origin} onValueChange={(v) => setForm({ ...form, request_origin: v })}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="sales_consultant_visit">Sales consultant visit</SelectItem>
              <SelectItem value="customer_call">Customer call</SelectItem>
              <SelectItem value="email">Email</SelectItem>
              <SelectItem value="other">Other</SelectItem>
            </SelectContent>
          </Select>
          <div className="flex flex-wrap gap-2">
            {ISSUE_TYPES.map(([value, label]) => (
              <label key={value} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                <Checkbox checked={form.issue_types.includes(value)} onCheckedChange={(checked) => {
                  setForm({ ...form, issue_types: checked ? [...form.issue_types, value] : form.issue_types.filter((item) => item !== value) });
                }} />
                {label}
              </label>
            ))}
          </div>
          <Textarea placeholder="Reason for request" value={form.reason_text} onChange={(e) => setForm({ ...form, reason_text: e.target.value })} />
        </div>
        <div className="space-y-3">
          <h2 className="text-sm font-semibold">Attachments and install</h2>
          <label className="flex cursor-pointer items-center justify-center rounded-md border border-dashed p-6 text-sm text-muted-foreground hover:bg-muted/40">
            <Upload className="mr-2 h-4 w-4" />
            Upload PDF, Excel, CSV, JPG, or PNG
            <input type="file" multiple className="hidden" onChange={(e) => setFiles(Array.from(e.target.files ?? []))} />
          </label>
          <div className="flex flex-wrap gap-1">
            {files.map((file) => <Badge key={file.name} variant="outline">{file.name}</Badge>)}
          </div>
          <label className="flex items-center gap-2 text-sm">
            <Checkbox checked={form.installation_required} onCheckedChange={(checked) => setForm({ ...form, installation_required: Boolean(checked) })} />
            Installation required
          </label>
          {form.installation_required && (
            <Textarea placeholder="Installation location" value={form.installation_location ?? ""} onChange={(e) => setForm({ ...form, installation_location: e.target.value })} />
          )}
          <label className="flex items-center gap-2 text-sm">
            <Checkbox checked={form.customer_has_submitted_po} onCheckedChange={(checked) => setForm({ ...form, customer_has_submitted_po: Boolean(checked) })} />
            Customer has submitted PO
          </label>
        </div>
      </section>
    </div>
  );
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return <div><Label>{label}</Label><Input className="mt-1" value={value} onChange={(e) => onChange(e.target.value)} /></div>;
}

function Metric({ label, value }: { label: string; value: string | number }) {
  return <div className="rounded-md border bg-muted/30 p-2"><div className="text-[10px] uppercase text-muted-foreground">{label}</div><div className="mt-1 text-sm font-semibold">{value}</div></div>;
}

import { createFileRoute } from "@tanstack/react-router";
import type { ColumnDef } from "@tanstack/react-table";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { Pencil, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DataTable } from "@/components/data-table";
import { CUSTOMERS } from "@/lib/demo-data";

export const Route = createFileRoute("/app/customers")({
  head: () => ({ meta: [{ title: "Customers — Kim-Fay OrderWatch" }] }),
  component: CustomersPage,
});

interface Rule {
  name: string;
  subjectPattern: string;
  poPattern: string;
  slaTarget: number;
  alertThreshold: number;
  active: boolean;
}

function CustomersPage() {
  const [rules, setRules] = useState<Rule[]>(() =>
    CUSTOMERS.map((c) => ({
      name: c.name,
      subjectPattern: `Purchase order Confirmation: P######### - <Branch>`,
      poPattern: `P#########`,
      slaTarget: c.sla,
      alertThreshold: Math.max(2, Math.floor(c.sla / 2)),
      active: true,
    })),
  );

  const columns = useMemo<ColumnDef<Rule>[]>(
    () => [
      { accessorKey: "name", header: "Customer", cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
      { accessorKey: "subjectPattern", header: "Subject Pattern", cell: ({ row }) => <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px]">{row.original.subjectPattern}</code> },
      { accessorKey: "poPattern", header: "PO Pattern", cell: ({ row }) => <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px]">{row.original.poPattern}</code> },
      { accessorKey: "slaTarget", header: "SLA (h)", cell: ({ row }) => <span className="font-mono tabular-nums">{row.original.slaTarget}h</span> },
      { accessorKey: "alertThreshold", header: "Alert at (h)", cell: ({ row }) => <span className="font-mono tabular-nums">{row.original.alertThreshold}h</span> },
      {
        accessorKey: "active",
        header: "Status",
        cell: ({ row }) => (
          <Switch
            checked={row.original.active}
            onCheckedChange={(v) => {
              setRules((r) => r.map((x) => (x.name === row.original.name ? { ...x, active: v } : x)));
              toast.success(`${row.original.name} ${v ? "enabled" : "disabled"}`);
            }}
          />
        ),
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          <EditRuleDialog rule={row.original} onSave={(r) => setRules((all) => all.map((x) => (x.name === r.name ? r : x)))} />
        ),
      },
    ],
    [],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Customers</h1>
          <p className="text-sm text-muted-foreground">Detection rules used to capture and match orders from Outlook.</p>
        </div>
        <Button size="sm" onClick={() => toast("New customer rule — coming soon")}>
          <Plus className="mr-1 h-3.5 w-3.5" /> New customer
        </Button>
      </div>
      <DataTable columns={columns} data={rules} searchPlaceholder="Search customers…" pageSize={20} />
    </div>
  );
}

function EditRuleDialog({ rule, onSave }: { rule: Rule; onSave: (r: Rule) => void }) {
  const [draft, setDraft] = useState(rule);
  const [open, setOpen] = useState(false);
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="icon" variant="ghost" className="h-7 w-7">
          <Pencil className="h-3.5 w-3.5" />
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit rule · {rule.name}</DialogTitle>
          <DialogDescription>Configure how Outlook emails for this customer are detected and matched.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-3 py-2">
          <div className="grid gap-1.5">
            <Label>Subject pattern</Label>
            <Input value={draft.subjectPattern} onChange={(e) => setDraft({ ...draft, subjectPattern: e.target.value })} />
          </div>
          <div className="grid gap-1.5">
            <Label>PO pattern</Label>
            <Input value={draft.poPattern} onChange={(e) => setDraft({ ...draft, poPattern: e.target.value })} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-1.5">
              <Label>SLA target (hours)</Label>
              <Input type="number" value={draft.slaTarget} onChange={(e) => setDraft({ ...draft, slaTarget: +e.target.value })} />
            </div>
            <div className="grid gap-1.5">
              <Label>Alert threshold (hours)</Label>
              <Input type="number" value={draft.alertThreshold} onChange={(e) => setDraft({ ...draft, alertThreshold: +e.target.value })} />
            </div>
          </div>
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
          <Button onClick={() => { onSave(draft); setOpen(false); toast.success("Rule saved"); }}>Save changes</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

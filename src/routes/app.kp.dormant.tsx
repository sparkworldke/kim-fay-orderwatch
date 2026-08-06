import { Link, createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { CheckCircle2, ClipboardList, History, Moon, PhoneCall, RefreshCw, Search, Send, UserRound, XCircle } from "lucide-react";
import { CustomerLink } from "@/components/entity-links";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  useHandoffKpDormant,
  useKpDormantAttempts,
  useLogKpDormantFeedback,
  type KpDormantCustomer,
} from "@/hooks/useKpCrm";
import { useCustomerContacts } from "@/hooks/useCustomerContacts";
import { useKpDormantCustomers } from "@/hooks/useKpCrm";

export const Route = createFileRoute("/app/kp/dormant")({
  head: () => ({ meta: [{ title: "Dormant Customers - Kim-Fay Sight" }] }),
  component: KpDormantPage,
});

function formatDate(value: string | null | undefined): string {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value.slice(0, 10);
  return d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

function KpDormantPage() {
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [activeCustomer, setActiveCustomer] = useState<KpDormantCustomer | null>(null);

  const list = useKpDormantCustomers({ q, page, per_page: perPage, months: 3 });
  const rows = list.data?.data ?? [];
  const total = list.data?.total ?? 0;
  const lastPage = list.data?.last_page ?? 1;
  const windowLabel = list.data?.window?.label;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">KP CRM</p>
          <h1 className="text-xl font-semibold tracking-tight">Dormant customers</h1>
          <p className="text-sm text-muted-foreground">
            KP customers with <strong>no sales order in the last 3 calendar months</strong> (measured from the start
            of the current month, not today’s date).
            {windowLabel ? ` ${windowLabel}.` : ""}
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={() => list.refetch()} disabled={list.isFetching}>
          <RefreshCw className={`mr-1 h-3.5 w-3.5 ${list.isFetching ? "animate-spin" : ""}`} />
          Refresh
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-[240px] max-w-md flex-1">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPage(1);
            }}
            placeholder="Search dormant account name, ID, email…"
            className="h-9 pl-8"
          />
        </div>
        <Badge variant="secondary" className="tabular-nums">
          {total.toLocaleString()} dormant
        </Badge>
        {list.data?.window?.from && (
          <Badge variant="outline" className="font-mono text-[10px]">
            No SO since {list.data.window.from}
          </Badge>
        )}
      </div>

      <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
        <table className="w-full text-sm">
          <thead className="bg-muted/40">
            <tr>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Account</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Class</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Last SO</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">Idle</th>
              <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase text-muted-foreground">
                Attached to
              </th>
              <th className="px-4 py-2.5 text-right text-[11px] font-semibold uppercase text-muted-foreground">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {list.isLoading &&
              Array.from({ length: 6 }).map((_, i) => (
                <tr key={i}>
                  <td colSpan={6} className="px-4 py-3">
                    <Skeleton className="h-5 w-full" />
                  </td>
                </tr>
              ))}

            {!list.isLoading && rows.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">
                  <Moon className="mx-auto mb-2 h-8 w-8 opacity-30" />
                  <p className="text-sm font-medium text-foreground">No dormant KP customers</p>
                  <p className="mt-1 text-xs">Everyone in scope has ordered within the last 3 calendar months.</p>
                </td>
              </tr>
            )}

            {rows.map((row) => (
              <tr key={row.acumatica_id} className="hover:bg-muted/20">
                <td className="px-4 py-3">
                  <CustomerLink customerId={row.acumatica_id} customerName={row.name} className="block">
                    <div className="font-medium leading-tight">{row.name}</div>
                    <div className="font-mono text-[11px] text-muted-foreground">{row.acumatica_id}</div>
                  </CustomerLink>
                </td>
                <td className="px-4 py-3">
                  <Badge variant="outline" className="font-mono text-[10px]">
                    {row.customer_class ?? "KP"}
                  </Badge>
                </td>
                <td className="px-4 py-3 text-xs text-muted-foreground">
                  {row.never_ordered ? (
                    <span className="text-amber-700">Never ordered</span>
                  ) : (
                    formatDate(row.last_order_date)
                  )}
                  {!row.never_ordered && row.lifetime_order_count > 0 && (
                    <div className="text-[10px]">{row.lifetime_order_count} lifetime SO</div>
                  )}
                </td>
                <td className="px-4 py-3 text-xs">
                  {row.months_idle != null ? (
                    <Badge variant="secondary" className="tabular-nums">
                      {row.months_idle}+ mo
                    </Badge>
                  ) : (
                    "—"
                  )}
                </td>
                <td className="px-4 py-3">
                  {row.assignee ? (
                    <div className="flex items-start gap-1.5">
                      <UserRound className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <div>
                        <div className="text-sm font-medium">{row.assignee.label}</div>
                        {row.assignee.source === "assignment" && (
                          <div className="text-[10px] text-muted-foreground">Portfolio assignment</div>
                        )}
                        {row.assignee.source.startsWith("last_so") && (
                          <div className="text-[10px] text-muted-foreground">From last SO rep code</div>
                        )}
                      </div>
                    </div>
                  ) : (
                    <span className="text-xs text-muted-foreground">Unassigned</span>
                  )}
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex flex-wrap justify-end gap-1">
                    <Button size="sm" variant="outline" className="h-8" onClick={() => setActiveCustomer(row)}>
                      <PhoneCall className="mr-1 h-3.5 w-3.5" /> Log contact
                    </Button>
                    <Button asChild size="sm" variant="outline" className="h-8">
                      <Link to="/app/customer-orders/$customerId" params={{ customerId: row.acumatica_id }}>
                        <History className="mr-1 h-3.5 w-3.5" /> History
                      </Link>
                    </Button>
                    <Button asChild size="sm" variant="outline" className="h-8">
                      <Link
                        to="/app/kp/fol/new"
                        search={{ customer: row.acumatica_id, name: row.name }}
                      >
                        <ClipboardList className="mr-1 h-3.5 w-3.5" /> FOL
                      </Link>
                    </Button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {(lastPage > 1 || total > 0) && (
        <PaginationControls
          currentPage={page}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={(size) => {
            setPerPage(size);
            setPage(1);
          }}
        />
      )}

      <Sheet open={!!activeCustomer} onOpenChange={(open) => !open && setActiveCustomer(null)}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
          {activeCustomer && <DormantFeedbackPanel customer={activeCustomer} onHandedOff={() => setActiveCustomer(null)} />}
        </SheetContent>
      </Sheet>
    </div>
  );
}

function DormantFeedbackPanel({ customer, onHandedOff }: { customer: KpDormantCustomer; onHandedOff: () => void }) {
  const [contacted, setContacted] = useState(true);
  const [contactId, setContactId] = useState<string>("");
  const [outcome, setOutcome] = useState("");
  const [comments, setComments] = useState("");

  const contacts = useCustomerContacts(customer.acumatica_id);
  const attempts = useKpDormantAttempts(customer.acumatica_id);
  const logFeedback = useLogKpDormantFeedback(customer.acumatica_id);
  const handoff = useHandoffKpDormant(customer.acumatica_id);

  const primaryContact = contacts.data?.data.find((c) => c.is_primary && c.is_active);
  const canHandoffLocally = !!primaryContact && !!(primaryContact.phone || primaryContact.email) && (attempts.data?.length ?? 0) > 0;

  async function submit() {
    if (!outcome.trim()) return;
    await logFeedback.mutateAsync({
      contacted,
      customer_contact_id: contactId ? Number(contactId) : null,
      outcome: outcome.trim(),
      comments: comments.trim() || null,
    });
    setOutcome("");
    setComments("");
  }

  async function submitHandoff() {
    try {
      await handoff.mutateAsync();
      onHandedOff();
    } catch {
      // toast already shown by the mutation's onError
    }
  }

  return (
    <div className="space-y-5">
      <SheetHeader>
        <SheetTitle>{customer.name}</SheetTitle>
        <SheetDescription>
          {customer.acumatica_id} · {customer.months_idle != null ? `${customer.months_idle}+ months idle` : "Never ordered"}
        </SheetDescription>
      </SheetHeader>

      <div className="space-y-3 rounded-md border p-3">
        <h3 className="text-sm font-semibold">Log contact attempt</h3>
        <div className="flex gap-2">
          <Button type="button" size="sm" variant={contacted ? "default" : "outline"} className="flex-1" onClick={() => setContacted(true)}>
            <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Contacted
          </Button>
          <Button type="button" size="sm" variant={!contacted ? "default" : "outline"} className="flex-1" onClick={() => setContacted(false)}>
            <XCircle className="mr-1 h-3.5 w-3.5" /> No response
          </Button>
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs">Contact reached (optional)</Label>
          <Select value={contactId} onValueChange={setContactId}>
            <SelectTrigger className="h-9">
              <SelectValue placeholder={contacts.isLoading ? "Loading contacts…" : "Select a contact"} />
            </SelectTrigger>
            <SelectContent>
              {(contacts.data?.data ?? []).map((c) => (
                <SelectItem key={c.id} value={String(c.id)}>
                  {c.full_name} — {c.designation_label}
                  {c.is_primary ? " (primary)" : ""}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {!contacts.isLoading && (contacts.data?.data.length ?? 0) === 0 && (
            <p className="text-xs text-amber-700">No contacts on file for this account yet.</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs">Outcome</Label>
          <Input value={outcome} onChange={(e) => setOutcome(e.target.value)} placeholder="e.g. Will reorder next month" />
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs">Comments</Label>
          <Textarea value={comments} onChange={(e) => setComments(e.target.value)} rows={3} />
        </div>
        <Button className="w-full" onClick={submit} disabled={!outcome.trim() || logFeedback.isPending}>
          <Send className="mr-1 h-3.5 w-3.5" /> Save attempt
        </Button>
      </div>

      <div className="space-y-2 rounded-md border p-3">
        <h3 className="text-sm font-semibold">Attempt history</h3>
        {attempts.isLoading && <Skeleton className="h-16 w-full" />}
        {!attempts.isLoading && (attempts.data?.length ?? 0) === 0 && (
          <p className="text-sm text-muted-foreground">No contact attempts logged yet.</p>
        )}
        {(attempts.data ?? []).map((a) => (
          <div key={a.id} className="border-l-2 pl-3 text-sm">
            <div className="flex items-center gap-1.5 font-medium">
              {a.contacted ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" /> : <XCircle className="h-3.5 w-3.5 text-muted-foreground" />}
              {a.outcome}
            </div>
            <div className="text-xs text-muted-foreground">{new Date(a.attempted_at).toLocaleString()}</div>
            {a.comments && <div className="mt-0.5 text-xs">{a.comments}</div>}
          </div>
        ))}
      </div>

      <div className="space-y-2 rounded-md border p-3">
        <h3 className="text-sm font-semibold">Hand off to Calltronix</h3>
        <p className="text-xs text-muted-foreground">
          Requires a primary contact with a phone or email, plus at least one logged attempt.
          {!canHandoffLocally && !contacts.isLoading && !attempts.isLoading && (
            <span className="mt-1 block text-amber-700">Not yet eligible — add a primary contact and log an attempt first.</span>
          )}
        </p>
        <Button className="w-full" variant="outline" onClick={submitHandoff} disabled={handoff.isPending}>
          Hand off
        </Button>
      </div>
    </div>
  );
}

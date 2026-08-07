import { createFileRoute } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Save, Search } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { usePagination } from "@/hooks/usePagination";
import { apiFetch } from "@/lib/api";

type ClassificationData = {
  category_rules: Array<{ id: number; customer_category: string; sales_channel_code: string; priority: number; is_active: boolean }>;
  customer_overrides: Array<{ id: number; customer_acumatica_id: string; sales_channel_code: string; change_reason: string; updated_at: string }>;
  channels: Array<{ code: string; name: string }>;
};

export const Route = createFileRoute("/app/channel-classification")({
  head: () => ({ meta: [{ title: "Customer Channel Classification - Kim-Fay Sight" }] }),
  component: ChannelClassificationPage,
});

function ChannelClassificationPage() {
  const client = useQueryClient();
  const [customerId, setCustomerId] = useState("");
  const [channel, setChannel] = useState("");
  const [reason, setReason] = useState("");
  const [search, setSearch] = useState("");
  const query = useQuery({
    queryKey: ["channel-classification"],
    queryFn: () => apiFetch<ClassificationData>("admin/portfolio/channel-classification"),
  });
  const save = useMutation({
    mutationFn: () => apiFetch("admin/portfolio/customer-channel-overrides", {
      method: "POST",
      body: JSON.stringify({
        customer_acumatica_id: customerId.trim().toUpperCase(),
        sales_channel_code: channel,
        change_reason: reason.trim(),
      }),
    }),
    onSuccess: async () => {
      toast.success("Customer channel override saved.");
      setCustomerId("");
      setReason("");
      await client.invalidateQueries({ queryKey: ["channel-classification"] });
    },
    onError: (error) => toast.error(error instanceof Error ? error.message : "Unable to save override."),
  });

  const rows = (query.data?.customer_overrides ?? []).filter((row) => {
    const needle = search.trim().toLowerCase();
    return !needle || row.customer_acumatica_id.toLowerCase().includes(needle) || row.sales_channel_code.toLowerCase().includes(needle);
  });
  const { page, perPage, setPage, setPerPage } = usePagination(20);
  const pageCount = Math.max(1, Math.ceil(rows.length / perPage));
  const safePage = Math.min(page, pageCount);
  const pagedRows = rows.slice((safePage - 1) * perPage, safePage * perPage);

  return (
    <div className="space-y-5">
      <header className="border-b pb-4">
        <h1 className="text-xl font-semibold">Customer Channel Classification</h1>
        <p className="text-sm text-muted-foreground">Exact customer-ID overrides take precedence over category rules.</p>
      </header>

      <section className="grid gap-3 border bg-card p-4 md:grid-cols-[1fr_220px_1.5fr_auto]">
        <div><Label>Acumatica customer ID</Label><Input className="mt-1" value={customerId} onChange={(event) => setCustomerId(event.target.value)} placeholder="CUST000123" /></div>
        <div>
          <Label>Sales channel</Label>
          <Select value={channel} onValueChange={setChannel}>
            <SelectTrigger className="mt-1"><SelectValue placeholder="Select channel" /></SelectTrigger>
            <SelectContent>{(query.data?.channels ?? []).map((item) => <SelectItem key={item.code} value={item.code}>{item.name}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div><Label>Change reason</Label><Input className="mt-1" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Approved channel assignment" /></div>
        <Button className="self-end" disabled={!customerId.trim() || !channel || reason.trim().length < 5 || save.isPending} onClick={() => save.mutate()}>
          <Save className="mr-1 h-4 w-4" /> Save
        </Button>
      </section>

      <section className="space-y-2">
        <h2 className="text-sm font-semibold">Category defaults</h2>
        <div className="flex flex-wrap gap-2">
          {(query.data?.category_rules ?? []).map((rule) => (
            <span key={rule.id} className="border bg-muted/20 px-2 py-1 text-xs">
              <b>{rule.customer_category}</b> → {rule.sales_channel_code}
            </span>
          ))}
        </div>
      </section>

      <section className="space-y-2">
        <div className="flex items-center justify-between gap-3">
          <h2 className="text-sm font-semibold">Customer-ID overrides</h2>
          <div className="relative w-72 max-w-full"><Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" /><Input className="pl-8" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search customer ID or channel" /></div>
        </div>
        <div className="overflow-x-auto border">
          <table className="w-full text-sm">
            <thead className="bg-muted/40"><tr><th className="px-3 py-2 text-left">Customer ID</th><th className="px-3 py-2 text-left">Channel</th><th className="px-3 py-2 text-left">Reason</th><th className="px-3 py-2 text-left">Updated</th></tr></thead>
            <tbody className="divide-y">
              {pagedRows.map((row) => <tr key={row.id}><td className="px-3 py-2 font-mono">{row.customer_acumatica_id}</td><td className="px-3 py-2">{row.sales_channel_code}</td><td className="px-3 py-2">{row.change_reason}</td><td className="px-3 py-2">{row.updated_at}</td></tr>)}
              {rows.length === 0 && <tr><td colSpan={4} className="px-3 py-8 text-center text-muted-foreground">No customer overrides found.</td></tr>}
            </tbody>
          </table>
        </div>
        {rows.length > 0 && (
          <PaginationControls
            currentPage={safePage}
            lastPage={pageCount}
            total={rows.length}
            perPage={perPage}
            onPageChange={setPage}
            onPerPageChange={setPerPage}
          />
        )}
      </section>
    </div>
  );
}

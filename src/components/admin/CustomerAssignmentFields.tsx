import { useEffect, useRef, useState } from "react";
import { Upload, X } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
  type CustomerAssignmentBatch,
  useApplyCustomerAssignmentBatch,
  useCustomerAssignmentSources,
  useCustomerAssignments,
  useCustomerSearch,
  usePreviewCustomerAssignmentMatch,
  useSyncCustomerAssignments,
  useUploadCustomerAssignments,
} from "@/hooks/admin/useAdminSettings";
import type { TeamMember } from "@/types/admin";

export function CustomerAssignmentFields({ member }: { member: TeamMember }) {
  const assignments = useCustomerAssignments(member.id);
  const sync = useSyncCustomerAssignments();
  const sources = useCustomerAssignmentSources();
  const preview = usePreviewCustomerAssignmentMatch();
  const upload = useUploadCustomerAssignments();
  const applyBatch = useApplyCustomerAssignmentBatch();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState<string[]>([]);
  const [source, setSource] = useState<"so_match" | "customer_endpoint" | "upload">("so_match");
  const [batch, setBatch] = useState<CustomerAssignmentBatch | null>(null);
  const searchResults = useCustomerSearch(search);

  useEffect(() => {
    if (assignments.data) {
      setSelected(assignments.data.map((a) => a.customer_acumatica_id));
    }
  }, [assignments.data]);

  if (!member.is_consultant && member.org_level !== "sales") {
    return null;
  }

  function addCustomer(id: string) {
    if (!selected.includes(id)) {
      setSelected((s) => [...s, id]);
    }
    setSearch("");
  }

  function removeCustomer(id: string) {
    setSelected((s) => s.filter((c) => c !== id));
  }

  function save() {
    sync.mutate({ userId: member.id, customer_acumatica_ids: selected });
  }

  async function runPreview() {
    try {
      const result = await preview.mutateAsync({ userId: member.id, source });
      setBatch(result);
      toast.success(`Preview ready: ${result.stats_json?.valid ?? 0} valid, ${result.stats_json?.errors ?? 0} error(s).`);
    } catch {
      // hook handles toast
    }
  }

  async function runApply() {
    if (!batch) return;
    try {
      const result = await applyBatch.mutateAsync(batch.id);
      setBatch(result);
    } catch {
      // hook handles toast
    }
  }

  async function uploadFile(file: File | undefined) {
    if (!file) return;
    try {
      const result = await upload.mutateAsync(file);
      setBatch(result);
      setSource("upload");
      toast.success(`Upload preview ready: ${result.stats_json?.valid ?? 0} valid, ${result.stats_json?.errors ?? 0} error(s).`);
    } catch {
      // hook handles toast
    } finally {
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  }

  const endpointAvailable = sources.data?.customer_endpoint.available ?? false;
  const validRows = batch?.rows.filter((row) => row.status === "valid") ?? [];
  const errorRows = batch?.rows.filter((row) => row.status === "error") ?? [];

  return (
    <div className="grid gap-2 md:col-span-2">
      <div className="flex items-center justify-between gap-2">
        <Label>Customer / outlet assignments</Label>
        <Button type="button" size="sm" disabled={sync.isPending} onClick={save}>
          {sync.isPending ? "Saving..." : "Save customers"}
        </Button>
      </div>

      <div className="grid gap-2 rounded-md border bg-muted/20 p-2">
        <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
          <Select value={source} onValueChange={(value) => setSource(value as typeof source)}>
            <SelectTrigger>
              <SelectValue placeholder="Choose import source" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="so_match">Import from SO</SelectItem>
              <SelectItem value="customer_endpoint" disabled={!endpointAvailable}>
                Customer Endpoint
              </SelectItem>
              <SelectItem value="upload">Upload Customer Data</SelectItem>
            </SelectContent>
          </Select>

          {source === "upload" ? (
            <>
              <Input
                ref={fileInputRef}
                type="file"
                accept=".csv,.txt,.xlsx,.xls"
                className="hidden"
                onChange={(event) => uploadFile(event.target.files?.[0])}
              />
              <Button type="button" variant="outline" disabled={upload.isPending} onClick={() => fileInputRef.current?.click()}>
                <Upload className="mr-1 h-3.5 w-3.5" /> {upload.isPending ? "Checking..." : "Upload"}
              </Button>
            </>
          ) : (
            <Button type="button" variant="outline" disabled={preview.isPending || (source === "customer_endpoint" && !endpointAvailable)} onClick={runPreview}>
              {preview.isPending ? "Previewing..." : "Preview"}
            </Button>
          )}

          <Button type="button" disabled={!batch || batch.status === "applied" || validRows.length === 0 || applyBatch.isPending} onClick={runApply}>
            {applyBatch.isPending ? "Applying..." : "Apply valid rows"}
          </Button>
        </div>

        <p className="text-xs text-muted-foreground">
          {source === "customer_endpoint" && !endpointAvailable
            ? "Customer endpoint has no rep field; use SO import or upload."
            : "Upload columns: rep_code and customer_id. Rep codes are read from the Excel file and must match one active user; customer IDs must exist in the Acumatica master."}
        </p>

        {batch && (
          <div className="overflow-x-auto rounded-md border bg-background">
            <div className="flex flex-wrap gap-3 border-b px-3 py-2 text-xs text-muted-foreground">
              <span>Rows: {batch.stats_json?.rows ?? batch.rows.length}</span>
              <span>Valid: {validRows.length}</span>
              <span>Errors: {errorRows.length}</span>
              <span>Status: {batch.status.replaceAll("_", " ")}</span>
            </div>
            <table className="w-full text-xs">
              <thead className="bg-muted/40">
                <tr>
                  <th className="px-3 py-2 text-left">Customer</th>
                  <th className="px-3 py-2 text-left">Rep</th>
                  <th className="px-3 py-2 text-left">Action</th>
                  <th className="px-3 py-2 text-left">Result</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {batch.rows.slice(0, 12).map((row) => (
                  <tr key={row.id}>
                    <td className="px-3 py-2">
                      <div className="font-mono">{row.customer_acumatica_id ?? "-"}</div>
                      <div className="text-muted-foreground">{row.customer_name ?? ""}</div>
                    </td>
                    <td className="px-3 py-2 font-mono">{row.rep_code ?? "-"}</td>
                    <td className="px-3 py-2">{row.action}</td>
                    <td className={row.status === "error" ? "px-3 py-2 text-destructive" : "px-3 py-2 text-muted-foreground"}>
                      {row.message ?? row.status}
                    </td>
                  </tr>
                ))}
                {batch.rows.length > 12 && (
                  <tr>
                    <td className="px-3 py-2 text-muted-foreground" colSpan={4}>
                      Showing first 12 rows.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Input
        placeholder="Search customer name or Acumatica ID..."
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />

      {search.length >= 2 && searchResults.data && searchResults.data.length > 0 && (
        <div className="max-h-32 overflow-y-auto rounded-md border text-sm">
          {searchResults.data.map((c) => (
            <button
              key={c.acumatica_id}
              type="button"
              className="block w-full px-3 py-1.5 text-left hover:bg-muted/50"
              onClick={() => addCustomer(c.acumatica_id)}
            >
              <span className="font-medium">{c.name}</span>
              <span className="ml-2 text-xs text-muted-foreground">{c.acumatica_id}</span>
            </button>
          ))}
        </div>
      )}

      <div className="flex flex-wrap gap-2">
        {selected.map((id) => (
          <span
            key={id}
            className="inline-flex items-center gap-1 rounded-full border bg-muted/30 px-2 py-0.5 text-xs font-mono"
          >
            {id}
            <button type="button" onClick={() => removeCustomer(id)} aria-label={`Remove ${id}`}>
              <X className="h-3 w-3" />
            </button>
          </span>
        ))}
        {selected.length === 0 && (
          <span className="text-xs text-muted-foreground">No customers attached.</span>
        )}
      </div>
    </div>
  );
}

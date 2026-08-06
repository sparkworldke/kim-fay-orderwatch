import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ClipboardPen, Download, Trash2, Upload } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { apiFetch } from "@/lib/api";
import type { InventoryItem } from "@/types/Stock/inventory";

type FormState = {
  ownership: "manufactured" | "partner";
  business_line: string;
  site: string;
  machine: string;
  machines: string;
  msi: string;
  safety_stock: string;
  buffer_stock: string;
  export_msi: string;
  export_requirement: string;
};

const emptyForm = (item?: InventoryItem): FormState => ({
  ownership: item?.brandOwnership ?? "manufactured",
  business_line: item?.businessLine ?? "",
  site: item?.site ?? "",
  machine: item?.machine ?? "",
  machines: item?.machines?.join(", ") ?? item?.machine ?? "",
  msi: item?.msiConfigured ? String(item.msi) : "",
  safety_stock: item?.safetyStockConfigured ? String(item.safetyStock) : "",
  buffer_stock: item?.bufferStockConfigured ? String(item.bufferStock) : "",
  export_msi: item?.msiExport == null ? "" : String(item.msiExport),
  export_requirement: item?.exportRequirement == null ? "" : String(item.exportRequirement),
});

const nullableNumber = (value: string) => (value.trim() === "" ? null : Number(value));

type MsiImportResult = {
  created: number;
  updated: number;
  unmatched: number;
  skipped?: number;
  errors: Array<{ line: number; inventory_id: string | null; message: string }>;
};

const parseCsvLine = (line: string) => {
  const cells: string[] = [];
  let value = "";
  let quoted = false;
  for (let index = 0; index < line.length; index++) {
    const char = line[index];
    if (char === '"' && quoted && line[index + 1] === '"') {
      value += '"';
      index++;
    } else if (char === '"') quoted = !quoted;
    else if (char === "," && !quoted) {
      cells.push(value.trim());
      value = "";
    } else value += char;
  }
  cells.push(value.trim());
  return cells;
};

const normalizeHeader = (header: string) =>
  header
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_|_$/g, "");

/** Map common spreadsheet headers → API field names. */
const HEADER_ALIASES: Record<string, string> = {
  inventory_id: "inventory_id",
  inventoryid: "inventory_id",
  sku: "inventory_id",
  item: "inventory_id",
  item_id: "inventory_id",
  msi: "msi",
  min_stock: "msi",
  minimum_stock: "msi",
  safety_stock: "safety_stock",
  safe_stock: "safety_stock",
  safetystock: "safety_stock",
  safety: "safety_stock",
  buffer_stock: "buffer_stock",
  bufferstock: "buffer_stock",
  buffer: "buffer_stock",
  export_msi: "export_msi",
  export_requirement: "export_requirement",
  machines: "machines",
  machine: "machines",
};

export function ProductionPlanningManager({ items }: { items: InventoryItem[] }) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const filtered = useMemo(
    () =>
      items.filter((item) =>
        `${item.inventoryId} ${item.productName}`.toLowerCase().includes(search.toLowerCase()),
      ),
    [items, search],
  );
  const [inventoryId, setInventoryId] = useState("");
  const selected = items.find((item) => item.inventoryId === inventoryId);
  const [form, setForm] = useState<FormState>(() => emptyForm());
  const fileInput = useRef<HTMLInputElement>(null);
  const [importErrors, setImportErrors] = useState<MsiImportResult["errors"]>([]);

  useEffect(() => setForm(emptyForm(selected)), [selected]);

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ["inventory", "production"] });
    await queryClient.invalidateQueries({ queryKey: ["sales", "production"] });
    await queryClient.invalidateQueries({ queryKey: ["production-summary"] });
  };

  const save = useMutation({
    mutationFn: () =>
      apiFetch(`operations/production/plans${selected?.planId ? `/${selected.planId}` : ""}`, {
        method: selected?.planId ? "PUT" : "POST",
        body: {
          inventory_id: inventoryId,
          ...form,
          machines: form.machines
            .split(/[,;|]+/)
            .map((value) => value.trim())
            .filter(Boolean),
          msi: nullableNumber(form.msi),
          safety_stock: nullableNumber(form.safety_stock),
          buffer_stock: nullableNumber(form.buffer_stock),
          export_msi: nullableNumber(form.export_msi),
          export_requirement: nullableNumber(form.export_requirement),
        },
      }),
    onSuccess: async () => {
      toast.success("Production planning record saved.");
      await refresh();
    },
    onError: (error) =>
      toast.error(error instanceof Error ? error.message : "Unable to save planning record."),
  });

  const remove = useMutation({
    mutationFn: () => apiFetch(`operations/production/plans/${selected?.planId}`, { method: "DELETE" }),
    onSuccess: async () => {
      toast.success("Planning record deleted; synced SKU data was kept.");
      await refresh();
    },
    onError: (error) =>
      toast.error(error instanceof Error ? error.message : "Unable to delete planning record."),
  });

  const importMsi = useMutation({
    mutationFn: (rows: Array<Record<string, string>>) =>
      apiFetch<MsiImportResult>("operations/production/plans/bulk-msi", {
        method: "POST",
        body: { rows },
      }),
    onSuccess: async (result) => {
      setImportErrors(result.errors);
      toast.success(
        `Bulk stock levels: ${result.created} created, ${result.updated} updated, ${result.unmatched} unmatched` +
          (result.skipped ? `, ${result.skipped} skipped` : "") +
          ".",
      );
      await refresh();
    },
    onError: (error) =>
      toast.error(error instanceof Error ? error.message : "Unable to import stock levels."),
  });

  const uploadCsv = async (file?: File) => {
    if (!file) return;
    const lines = (await file.text())
      .replace(/^\uFEFF/, "")
      .split(/\r?\n/)
      .filter((line) => line.trim());
    const headers = parseCsvLine(lines[0] ?? "").map(normalizeHeader);
    const fieldIndexes: Partial<Record<string, number>> = {};
    headers.forEach((header, index) => {
      const field = HEADER_ALIASES[header];
      if (field && fieldIndexes[field] === undefined) fieldIndexes[field] = index;
    });

    if (lines.length < 2 || fieldIndexes.inventory_id === undefined) {
      toast.error('CSV requires an "Inventory ID" (or SKU) column plus at least one data row.');
      return;
    }

    const stockFields = ["msi", "safety_stock", "buffer_stock", "export_msi", "export_requirement"] as const;
    const hasAnyStockColumn = stockFields.some((field) => fieldIndexes[field] !== undefined);
    if (!hasAnyStockColumn && fieldIndexes.machines === undefined) {
      toast.error(
        "CSV must include at least one of: MSI, Safety Stock / Safe Stock, Buffer Stock (optional Machines).",
      );
      return;
    }

    importMsi.mutate(
      lines.slice(1).map(parseCsvLine).map((cells) => {
        const row: Record<string, string> = {
          inventory_id: cells[fieldIndexes.inventory_id!] ?? "",
        };
        for (const field of [...stockFields, "machines"] as const) {
          const index = fieldIndexes[field];
          if (index !== undefined) row[field] = cells[index] ?? "";
        }
        return row;
      }),
    );
    if (fileInput.current) fileInput.current.value = "";
  };

  const downloadTemplate = () => {
    const url = URL.createObjectURL(
      new Blob(
        [
          "Inventory ID,MSI,Safety Stock,Buffer Stock,Export MSI,Machines\r\n" +
            'COSTP0030,10000,2500,1500,500,"4 DECK; PERINI"\r\n' +
            "COSTP0024,8000,2000,1200,,\r\n",
        ],
        { type: "text/csv" },
      ),
    );
    const link = document.createElement("a");
    link.href = url;
    link.download = "production-msi-safety-buffer-template.csv";
    link.click();
    URL.revokeObjectURL(url);
  };

  const field = (key: keyof FormState, label: string, type = "text") => (
    <label className="grid gap-1 text-[9px] font-semibold">
      {label}
      <Input
        type={type}
        min={type === "number" ? 0 : undefined}
        value={form[key]}
        onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}
      />
    </label>
  );

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm" className="h-8 gap-1.5">
          <ClipboardPen className="size-4" />
          Manage MSI / stock levels
        </Button>
      </SheetTrigger>
      <SheetContent hideOverlay className="w-full overflow-y-auto sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>Production SKU planning</SheetTitle>
          <SheetDescription>
            COO, store managers, production managers, and admins can set MSI, safety stock, and
            buffer stock — per SKU or in bulk via CSV.
          </SheetDescription>
        </SheetHeader>
        <div className="mt-4 grid gap-3">
          <section className="rounded-md border border-primary/20 bg-primary/5 p-3">
            <p className="text-xs font-semibold">Bulk upload MSI · Safety stock · Buffer stock</p>
            <p className="mb-2 text-[10px] text-muted-foreground">
              CSV columns (header row required): <b>Inventory ID</b> (or SKU), plus any of{" "}
              <b>MSI</b>, <b>Safety Stock</b> / Safe Stock, <b>Buffer Stock</b>, optional Export MSI
              and Machines. Leave a cell blank to keep the existing value. Max 10,000 rows.
            </p>
            <input
              ref={fileInput}
              type="file"
              accept=".csv,text/csv"
              className="hidden"
              onChange={(event) => void uploadCsv(event.target.files?.[0])}
            />
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" size="sm" onClick={downloadTemplate}>
                <Download className="mr-1 size-3.5" />
                Download template
              </Button>
              <Button
                size="sm"
                disabled={importMsi.isPending}
                onClick={() => fileInput.current?.click()}
              >
                <Upload className="mr-1 size-3.5" />
                {importMsi.isPending ? "Importing…" : "Upload CSV"}
              </Button>
            </div>
            {importErrors.length ? (
              <div className="mt-2 max-h-28 overflow-y-auto rounded border bg-background p-2 text-[10px]">
                {importErrors.slice(0, 50).map((error) => (
                  <p key={`${error.line}-${error.inventory_id ?? ""}-${error.message}`}>
                    Line {error.line}
                    {error.inventory_id ? ` · ${error.inventory_id}` : ""}: {error.message}
                  </p>
                ))}
                {importErrors.length > 50 ? (
                  <p className="text-muted-foreground">…and {importErrors.length - 50} more</p>
                ) : null}
              </div>
            ) : null}
          </section>

          <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            Single SKU
          </p>
          <Input
            placeholder="Search SKU or description…"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
          <select
            className="h-8 rounded-md border bg-background px-2"
            value={inventoryId}
            onChange={(event) => setInventoryId(event.target.value)}
          >
            <option value="">Select a SKU</option>
            {filtered.map((item) => (
              <option key={item.inventoryId} value={item.inventoryId}>
                {item.inventoryId} — {item.productName}
              </option>
            ))}
          </select>
          {selected ? (
            <>
              <label className="grid gap-1 text-[9px] font-semibold">
                Ownership
                <select
                  className="h-8 rounded-md border bg-background px-2"
                  value={form.ownership}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      ownership: event.target.value as FormState["ownership"],
                    }))
                  }
                >
                  <option value="manufactured">Manufactured</option>
                  <option value="partner">Partner</option>
                </select>
              </label>
              {field("business_line", "Business line")}
              {field("site", "Site")}
              {field("machines", "Machines (comma-separated)")}
              <div className="grid grid-cols-2 gap-2">
                {field("msi", "MSI", "number")}
                {field("safety_stock", "Safety stock (safe stock)", "number")}
                {field("buffer_stock", "Buffer stock", "number")}
                {field("export_msi", "Export MSI", "number")}
                {field("export_requirement", "Export requirement", "number")}
              </div>
              <div className="flex justify-end gap-2">
                {selected.planId ? (
                  <Button
                    variant="destructive"
                    disabled={remove.isPending}
                    onClick={() => remove.mutate()}
                  >
                    <Trash2 className="mr-1 size-4" />
                    Delete
                  </Button>
                ) : null}
                <Button disabled={!inventoryId || save.isPending} onClick={() => save.mutate()}>
                  Save planning
                </Button>
              </div>
            </>
          ) : null}
        </div>
      </SheetContent>
    </Sheet>
  );
}

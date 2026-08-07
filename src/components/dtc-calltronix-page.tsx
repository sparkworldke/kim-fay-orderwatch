/* eslint-disable @typescript-eslint/no-explicit-any */
import { useEffect, useMemo, useRef, useState } from "react";
import { Link } from "@tanstack/react-router";
import { Download, FileText, RefreshCw, ShoppingCart, Upload } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { CustomerLink } from "@/components/entity-links";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { usePagination } from "@/hooks/usePagination";
import {
  useDtcActions,
  useDtcCustomers,
  useDtcMeta,
  useDtcPrices,
  useDtcPriceImportJobs,
  useDtcQuotes,
  useDtcSalesOrders,
  type DtcQuote,
  type DtcCustomer,
  type DtcSalesOrder,
} from "@/hooks/useDtcCalltronix";
import { downloadApiFile } from "@/lib/api";

export type DtcPage = "quotes" | "sales-orders" | "price-list" | "customers";
const tabs = [
  { key: "quotes", label: "Quotes", path: "/app/kp/dtc-calltronix/quotes" },
  { key: "sales-orders", label: "Sales Orders", path: "/app/kp/dtc-calltronix/sales-orders" },
  { key: "price-list", label: "Price List", path: "/app/kp/dtc-calltronix/price-list" },
  { key: "customers", label: "Customers", path: "/app/kp/dtc-calltronix/customers" },
] as const;

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}
function todayIso() {
  return new Date().toISOString().slice(0, 10);
}
function kes(n: number) {
  return `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
}
function formatAcumaticaDate(value?: string | null): string {
  if (!value) return "—";
  const raw = value.slice(0, 10);
  const [y, m, d] = raw.split("-").map(Number);
  if (!y || !m || !d) return raw;
  return new Date(y, m - 1, d).toLocaleDateString(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

export function DtcCalltronixPage({ page }: { page: DtcPage }) {
  const [q, setQ] = useState("");
  const [statsFrom, setStatsFrom] = useState(monthStart());
  const [statsTo, setStatsTo] = useState(todayIso());
  const meta = useDtcMeta(statsFrom, statsTo);
  const stats = meta.data?.stats;

  return (
    <div className="space-y-5">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">
          KP · DTCACCOUNT
        </p>
        <h1 className="text-2xl font-semibold">DTC/DTB Calltronix</h1>
        <p className="text-sm text-muted-foreground">
          Acumatica quotes (QT), POS sales orders, DTCACCOUNT price list, and direct-trade customers.
        </p>
      </div>
      <div className="flex flex-wrap gap-2 border-b pb-3">
        {tabs.map((t) => (
          <Button key={t.key} asChild size="sm" variant={page === t.key ? "default" : "ghost"}>
            <Link to={t.path}>{t.label}</Link>
          </Button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div>
          <Label className="text-xs text-muted-foreground">Stats from</Label>
          <Input type="date" className="h-9 w-40" value={statsFrom} onChange={(e) => setStatsFrom(e.target.value)} />
        </div>
        <div>
          <Label className="text-xs text-muted-foreground">Stats to</Label>
          <Input type="date" className="h-9 w-40" value={statsTo} onChange={(e) => setStatsTo(e.target.value)} />
        </div>
        <span className="pb-2 text-xs text-muted-foreground">
          Totals use the range above (order / submit date).
        </span>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="QT orders"
          subtitle={kes(stats?.quotes.total_amount ?? 0)}
          value={String(stats?.qt_count ?? stats?.quotes.count ?? 0)}
          tone="emerald"
        />
        <StatCard
          title="SO / POS orders"
          subtitle={kes(stats?.pos_orders.total_amount ?? 0)}
          value={String(stats?.so_count ?? stats?.pos_orders.count ?? 0)}
          tone="sky"
        />
        <StatCard
          title="DTCACCOUNT prices"
          subtitle={`${stats?.prices.matched ?? 0} in catalog`}
          value={String(stats?.prices.count ?? 0)}
          tone="amber"
        />
        <StatCard
          title="POS account"
          subtitle="Fixed Calltronix customer"
          value={meta.data?.pos_order_customer_id ?? "CUST101470"}
          tone="slate"
        />
      </div>

      {page !== "price-list" && (
        <div className="flex gap-2">
          <Input
            className="max-w-sm"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder={`Search ${tabs.find((t) => t.key === page)?.label.toLowerCase()}…`}
          />
          {meta.data?.last_price_sync && (
            <span className="self-center text-xs text-muted-foreground">
              Prices synced {new Date(meta.data.last_price_sync.started_at).toLocaleString()}
            </span>
          )}
        </div>
      )}
      {page === "quotes" && <Quotes q={q} />}
      {page === "sales-orders" && <SalesOrders q={q} />}
      {page === "price-list" && <Prices q={q} />}
      {page === "customers" && <Customers q={q} />}
    </div>
  );
}

function StatCard({
  title,
  subtitle,
  value,
  tone,
}: {
  title: string;
  subtitle: string;
  value: string;
  tone: "emerald" | "sky" | "amber" | "slate";
}) {
  const ring =
    tone === "emerald"
      ? "border-emerald-200 bg-emerald-50/50 dark:bg-emerald-950/20"
      : tone === "sky"
        ? "border-sky-200 bg-sky-50/50 dark:bg-sky-950/20"
        : tone === "amber"
          ? "border-amber-200 bg-amber-50/50 dark:bg-amber-950/20"
          : "border-border bg-card";
  return (
    <div className={`rounded-lg border p-4 shadow-sm ${ring}`}>
      <div className="text-xs font-medium text-muted-foreground">{title}</div>
      <div className="mt-1 text-xl font-semibold tracking-tight">{value}</div>
      <div className="mt-0.5 text-[11px] text-muted-foreground">{subtitle}</div>
    </div>
  );
}
function Quotes({ q }: { q: string }) {
  const { page, perPage, setPage, setPerPage } = usePagination(20);
  const data = useDtcQuotes(q, "", page, perPage);
  const meta = useDtcMeta();
  const actions = useDtcActions();
  const [open, setOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [importScope, setImportScope] = useState<"range" | "all">("range");
  const [importFrom, setImportFrom] = useState(monthStart());
  const [importTo, setImportTo] = useState(todayIso());
  const [view, setView] = useState<DtcQuote | null>(null);
  const customers = useDtcCustomers();
  const prices = useDtcPrices();
  const [customer, setCustomer] = useState("");
  const [description, setDescription] = useState("");
  const [lines, setLines] = useState<Array<{ inventory_id: string; quantity: number }>>([]);
  const available = useMemo(() => prices.data?.data ?? [], [prices.data?.data]);
  const total = useMemo(
    () =>
      lines.reduce(
        (s, l) =>
          s +
          l.quantity * Number(available.find((p) => p.inventory_id === l.inventory_id)?.price ?? 0),
        0,
      ),
    [lines, available],
  );
  const add = () => setLines([...lines, { inventory_id: "", quantity: 1 }]);
  return (
    <>
      <div className="flex justify-end gap-2">
        {meta.data?.can_import_quotes && (
          <Button variant="outline" onClick={() => setImportOpen(true)}>
            <RefreshCw className="mr-1 h-4 w-4" />
            Import QT (date range)
          </Button>
        )}
        {meta.data?.can_create && (
          <Button onClick={() => setOpen(true)}>
            <FileText className="mr-1 h-4 w-4" />
            New quote
          </Button>
        )}
      </div>
      <CardTable headers={["Quote", "Customer", "Total", "Status", "Acumatica date", "Actions"]}>
        {data.data?.data.map((x) => (
          <tr key={x.id} className="border-b">
            <Td>
              <button
                className="font-medium text-emerald-700 hover:underline"
                onClick={() => setView(x)}
              >
                {x.acumatica_quote_nbr ?? x.public_ref}
              </button>
            </Td>
            <Td><CustomerLink customerId={x.customer_acumatica_id} customerName={x.customer_name} /></Td>
            <Td>KES {Number(x.quoted_total).toLocaleString()}</Td>
            <Td>
              <Badge variant="outline" className="capitalize">
                {x.status}
              </Badge>
            </Td>
            <Td>{formatAcumaticaDate(x.acumatica_date ?? x.submitted_at ?? x.created_at)}</Td>
            <Td>
              <div className="flex gap-1">
                {x.status === "draft" && (
                  <Button size="sm" variant="outline" onClick={() => actions.submit.mutate(x.id)}>
                    Submit QT
                  </Button>
                )}
                {x.status === "submitted" && meta.data?.can_convert && (
                  <Button
                    size="sm"
                    onClick={() =>
                      confirm(
                        `Create an Acumatica PosOrder for fixed customer ${meta.data?.pos_order_customer_id ?? "CUST101470"} from this QT?`,
                      ) && actions.convert.mutate(x.id)
                    }
                  >
                    Convert to SO
                  </Button>
                )}
              </div>
            </Td>
          </tr>
        ))}
      </CardTable>
      {data.data && data.data.total > 0 && (
        <PaginationControls
          currentPage={data.data.current_page}
          lastPage={data.data.last_page}
          total={data.data.total}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={setPerPage}
        />
      )}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>New DTC quote</DialogTitle>
          </DialogHeader>
          <Label>Customer</Label>
          <select
            className="h-9 rounded-md border bg-background px-3"
            value={customer}
            onChange={(e) => setCustomer(e.target.value)}
          >
            <option value="">Select DTCACCOUNT customer</option>
            {customers.data?.data.map((c) => (
              <option key={c.acumatica_id} value={c.acumatica_id}>
                {c.name}
              </option>
            ))}
          </select>
          <Label>Description</Label>
          <Input value={description} onChange={(e) => setDescription(e.target.value)} />
          <div className="space-y-2">
            {lines.map((l, i) => (
              <div className="grid grid-cols-[1fr_24rem_8rem_auto] gap-2" key={i}>
                <span className="self-center text-xs">{i + 1}</span>
                <select
                  className="h-9 rounded-md border bg-background px-2 text-sm"
                  value={l.inventory_id}
                  onChange={(e) =>
                    setLines(
                      lines.map((x, j) => (j === i ? { ...x, inventory_id: e.target.value } : x)),
                    )
                  }
                >
                  <option value="">Select product</option>
                  {available
                    .filter((p) => Number(p.qty_available) > 0)
                    .map((p) => (
                      <option key={p.inventory_id} value={p.inventory_id}>
                        {p.inventory_id} · {p.description} · KES {Number(p.price).toLocaleString()}
                      </option>
                    ))}
                </select>
                <Input
                  type="number"
                  min={1}
                  value={l.quantity}
                  onChange={(e) =>
                    setLines(
                      lines.map((x, j) =>
                        j === i ? { ...x, quantity: Number(e.target.value) } : x,
                      ),
                    )
                  }
                />
                <Button variant="ghost" onClick={() => setLines(lines.filter((_, j) => j !== i))}>
                  ×
                </Button>
              </div>
            ))}
            <Button variant="outline" size="sm" onClick={add}>
              Add product
            </Button>
          </div>
          <div className="text-right font-semibold">Quote total: KES {total.toLocaleString()}</div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={!customer || !lines.length || actions.create.isPending}
              onClick={() =>
                actions.create.mutate(
                  { customer_acumatica_id: customer, description, lines },
                  {
                    onSuccess: () => {
                      setOpen(false);
                      setLines([]);
                    },
                  },
                )
              }
            >
              Save draft
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog open={importOpen} onOpenChange={setImportOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Import QT (date range)</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            Pull Acumatica quotes (OrderType QT) for POS customer{" "}
            <b>{meta.data?.pos_order_customer_id ?? "CUST101470"}</b>.
          </p>
          <div className="space-y-3">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                checked={importScope === "range"}
                onChange={() => setImportScope("range")}
              />
              Date range
            </label>
            {importScope === "range" && (
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label className="text-xs">From</Label>
                  <Input type="date" value={importFrom} onChange={(e) => setImportFrom(e.target.value)} />
                </div>
                <div>
                  <Label className="text-xs">To</Label>
                  <Input type="date" value={importTo} onChange={(e) => setImportTo(e.target.value)} />
                </div>
              </div>
            )}
            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                checked={importScope === "all"}
                onChange={() => setImportScope("all")}
              />
              All quotes
            </label>
            {importScope === "all" && (
              <p className="text-xs text-amber-700">
                Full Acumatica QT history for the POS customer — may take several minutes.
              </p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setImportOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={
                actions.importQuotes.isPending ||
                (importScope === "range" && (!importFrom || !importTo || importFrom > importTo))
              }
              onClick={() =>
                actions.importQuotes.mutate(
                  importScope === "range"
                    ? { scope: "range", date_from: importFrom, date_to: importTo }
                    : { scope: "all" },
                  { onSuccess: () => setImportOpen(false) },
                )
              }
            >
              {actions.importQuotes.isPending ? "Importing…" : "Import QT"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <QuoteDetail quote={view} close={() => setView(null)} />
    </>
  );
}
function QuoteDetail({ quote, close }: { quote: DtcQuote | null; close: () => void }) {
  const actions = useDtcActions();
  const [editing, setEditing] = useState(false);
  const details = quote?.conversion?.customer_details_snapshot ?? {};
  const [form, setForm] = useState({ name: "", email: "", phone: "", address_line1: "", address_line2: "" });
  useEffect(() => setForm({ name: details.name ?? quote?.customer_name ?? "", email: details.email ?? "", phone: details.phone ?? "", address_line1: details.address_line1 ?? "", address_line2: details.address_line2 ?? "" }), [quote]);
  return (
    <Dialog open={!!quote} onOpenChange={(v) => !v && close()}>
      <DialogContent className="max-w-2xl print:border-0">
        <DialogHeader>
          <DialogTitle>Quote {quote?.acumatica_quote_nbr ?? quote?.public_ref}</DialogTitle>
        </DialogHeader>
        <div className="grid grid-cols-2 gap-2 text-sm">
          <span>Customer</span>
          <b><CustomerLink customerId={quote?.customer_acumatica_id} customerName={quote?.customer_name} showId /></b>
          {details.address_line1 && <><span>Billing address</span><b>{[details.address_line1, details.address_line2].filter(Boolean).join(", ")}</b></>}
          <span>Status</span>
          <b className="capitalize">{quote?.status}</b>
          {quote?.conversion?.acumatica_order_nbr && (
            <>
              <span>Sales order</span>
              <b>{quote.conversion.acumatica_order_nbr}</b>
              <span>Price variance</span>
              <b>KES {Number(quote.conversion.price_variance ?? 0).toLocaleString()}</b>
            </>
          )}
        </div>
        <CardTable headers={["SKU", "Description", "Qty", "Unit price", "Total"]}>
          {quote?.lines.map((l) => (
            <tr className="border-b" key={l.id}>
              <Td>{l.inventory_id}</Td>
              <Td>{l.description}</Td>
              <Td>{Number(l.quantity)}</Td>
              <Td>{Number(l.unit_price).toLocaleString()}</Td>
              <Td>{Number(l.line_total).toLocaleString()}</Td>
            </tr>
          ))}
        </CardTable>
        <div className="text-right text-lg font-bold">
          QT total: KES {Number(quote?.quoted_total ?? 0).toLocaleString()}
        </div>
        {quote?.conversion?.pos_lines_snapshot?.length ? <><h3 className="font-semibold">POS products and prices</h3><CardTable headers={["SKU","Product","Qty","POS unit price","POS total"]}>{quote.conversion.pos_lines_snapshot.map((l,i)=><tr className="border-b" key={l.id??`${l.inventory_id}-${i}`}><Td>{l.inventory_id}</Td><Td>{l.description??"—"}</Td><Td>{l.quantity}</Td><Td>{Number(l.unit_price).toLocaleString()}</Td><Td>{Number(l.line_total).toLocaleString()}</Td></tr>)}</CardTable><div className="text-right font-bold">POS total: KES {Number(quote.conversion.order_total??0).toLocaleString()}</div></>:null}
        {editing && <div className="grid grid-cols-2 gap-2 rounded border p-3">{([['name','Customer name'],['email','Email'],['phone','Phone'],['address_line1','Billing address line 1'],['address_line2','Billing address line 2']] as const).map(([key,label])=><div key={key} className={key.startsWith('address')?'col-span-2':''}><Label>{label}</Label><Input value={form[key]} onChange={e=>setForm({...form,[key]:e.target.value})}/></div>)}</div>}
        <DialogFooter>
          {quote?.conversion?.status === "success" && (!editing ? <Button variant="outline" onClick={()=>setEditing(true)}>Update customer details</Button> : <Button disabled={!form.name||actions.updateConvertedCustomer.isPending} onClick={()=>actions.updateConvertedCustomer.mutate({id:quote.id,...form},{onSuccess:()=>setEditing(false)})}>Save to POS</Button>)}
          <Button variant="outline" onClick={() => window.print()}>
            Print / PDF
          </Button>
          <Button onClick={close}>Close</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
const DEFAULT_PRICE_WAREHOUSES = ["DTC", "FGS"];

function Prices({ q }: { q: string }) {
  const { page, perPage, setPage, setPerPage } = usePagination(20);
  const [search, setSearch] = useState(q);
  const [brand, setBrand] = useState("");
  const [taxation, setTaxation] = useState("");
  const [productType, setProductType] = useState("");
  const [warehouses, setWarehouses] = useState<string[]>(DEFAULT_PRICE_WAREHOUSES);
  const [stock, setStock] = useState("in_stock");
  const [priced, setPriced] = useState("yes");
  const fileRef = useRef<HTMLInputElement | null>(null);
  const completedImportRef = useRef<number | null>(null);

  useEffect(() => {
    setSearch(q);
  }, [q]);

  const data = useDtcPrices({
    q: search || undefined,
    page,
    per_page: perPage,
    brand: brand || undefined,
    taxation: taxation || undefined,
    product_type: productType || undefined,
    warehouses: warehouses.length ? warehouses.join(",") : undefined,
    stock: stock || undefined,
    priced: priced || undefined,
  });
  const meta = useDtcMeta();
  const importJobs = useDtcPriceImportJobs();
  const actions = useDtcActions();
  useEffect(() => {
    const terminal = importJobs.data?.data.find((job) => ["completed", "partial", "failed"].includes(job.status));
    if (!terminal || completedImportRef.current === terminal.id) return;
    completedImportRef.current = terminal.id;
    if (terminal.status === "completed" || terminal.status === "partial") {
      void data.refetch();
      void meta.refetch();
    }
  }, [importJobs.data, data, meta]);
  // Deliberately NOT gated on a "running" import job from the server list: a crashed/orphaned
  // job can sit in "running" indefinitely (the backend only reaps it on the *next* upload
  // attempt), which would otherwise permanently disable the file picker. The backend still
  // guards against genuine concurrent imports (409) and surfaces that via a toast.
  const busy =
    actions.syncProducts.isPending ||
    actions.sync.isPending ||
    actions.syncAll.isPending ||
    actions.importExcel.isPending;
  const filters = meta.data?.price_filters;
  // Only DTC + FGS are offered in the multi-select (other warehouses hidden).
  const warehouseOptions = DEFAULT_PRICE_WAREHOUSES;

  useEffect(() => {
    setPage(1);
  }, [search, brand, taxation, productType, warehouses, stock, priced]);

  const toggleWarehouse = (wh: string) => {
    setWarehouses((prev) =>
      prev.includes(wh) ? prev.filter((x) => x !== wh) : [...prev, wh],
    );
  };

  const resetFilters = () => {
    setSearch("");
    setBrand("");
    setTaxation("");
    setProductType("");
    setWarehouses([...DEFAULT_PRICE_WAREHOUSES]);
    setStock("in_stock");
    setPriced("yes");
  };

  const rows = data.data?.data ?? [];
  const total = data.data?.total ?? 0;
  const lastPage = data.data?.last_page ?? 1;
  const pdfParams = new URLSearchParams();
  if (search) pdfParams.set("q", search);
  if (brand) pdfParams.set("brand", brand);
  if (taxation) pdfParams.set("taxation", taxation);
  if (productType) pdfParams.set("product_type", productType);
  if (warehouses.length) pdfParams.set("warehouses", warehouses.join(","));
  if (stock) pdfParams.set("stock", stock);
  if (priced) pdfParams.set("priced", priced);

  return (
    <>
      {/* Always-visible Excel/CSV import — matches Acumatica "DTC Sales Prices" export */}
      <div className="rounded-lg border-2 border-dashed border-emerald-300 bg-emerald-50/60 p-4 dark:border-emerald-800 dark:bg-emerald-950/20">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex items-center gap-2 font-medium text-emerald-900 dark:text-emerald-100">
              <Upload className="h-4 w-4 shrink-0" />
              Import price file
            </div>
            <p className="text-sm text-muted-foreground">
              Upload <b>DTC Sales Prices</b> Excel (.xlsx) or CSV. Rows match on{" "}
              <b>Inventory ID</b>; <b>Tax Category = TAXABLE</b> adds 16% VAT on the sell price.
            </p>
            <p className="text-xs text-muted-foreground">
              Expected columns: Inventory ID, Price, Tax Category, Description, UOM, …
              {meta.data?.last_excel_import && (
                <>
                  {" "}
                  · Last import:{" "}
                  {new Date(meta.data.last_excel_import.started_at).toLocaleString()}
                  {meta.data.last_excel_import.records_processed != null
                    ? ` (${meta.data.last_excel_import.records_processed} rows)`
                    : ""}
                </>
              )}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <input
              ref={fileRef}
              id="dtc-price-excel-input"
              type="file"
              accept=".xlsx,.xls,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
              className="block w-full max-w-xs text-sm file:mr-3 file:rounded-md file:border-0 file:bg-emerald-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-emerald-800"
              disabled={busy}
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) actions.importExcel.mutate(file);
                e.target.value = "";
              }}
            />
            <Button disabled={busy} onClick={() => fileRef.current?.click()}>
              <Upload
                className={`mr-1 h-4 w-4 ${actions.importExcel.isPending ? "animate-pulse" : ""}`}
              />
              {actions.importExcel.isPending ? "Uploading & importing…" : "Choose file"}
            </Button>
          </div>
        </div>
      </div>

      {(importJobs.data?.data?.length ?? 0) > 0 && (
        <div className="rounded-lg border p-3">
          <div className="mb-2 text-sm font-medium">Recent price imports</div>
          <div className="space-y-2">
            {importJobs.data!.data.slice(0, 5).map((job) => (
              <div key={job.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span className="min-w-0 truncate font-medium">
                  {job.original_filename ?? `Import #${job.id}`}
                  <span className="ml-2 text-xs font-normal text-muted-foreground">
                    {job.progress?.rows_read ?? 0} rows · {job.records_processed ?? 0} saved
                  </span>
                </span>
                <Badge variant={job.status === "failed" ? "destructive" : "secondary"}>{job.status}</Badge>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="space-y-1 text-sm text-muted-foreground">
          <p>
            DTC Price List
            {data.isLoading ? " · Loading…" : ` · ${total.toLocaleString()} products`}
            {" · default: DTC + FGS · with price · in stock"}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            onClick={() => downloadApiFile(
              `kp/dtc-calltronix/prices/export.pdf?${pdfParams.toString()}`,
              `Kim-Fay-DTC-Price-List-${todayIso()}.pdf`,
            )}
          >
            <Download className="mr-1 h-4 w-4" /> Download PDF
          </Button>
        {(meta.data?.can_sync_prices || meta.data?.is_admin) && (
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              onClick={() => actions.syncProducts.mutate()}
              disabled={busy}
            >
              <RefreshCw
                className={`mr-1 h-4 w-4 ${actions.syncProducts.isPending ? "animate-spin" : ""}`}
              />
              {actions.syncProducts.isPending ? "Syncing products…" : "Sync products"}
            </Button>
            <Button variant="outline" onClick={() => actions.sync.mutate()} disabled={busy}>
              <RefreshCw className={`mr-1 h-4 w-4 ${actions.sync.isPending ? "animate-spin" : ""}`} />
              {actions.sync.isPending ? "Importing prices…" : "Import DTCACCOUNT prices"}
            </Button>
            <Button onClick={() => actions.syncAll.mutate()} disabled={busy}>
              <RefreshCw
                className={`mr-1 h-4 w-4 ${actions.syncAll.isPending ? "animate-spin" : ""}`}
              />
              {actions.syncAll.isPending ? "Full sync…" : "Sync all"}
            </Button>
          </div>
        )}
        </div>
      </div>

      <div className="space-y-3 rounded-md border bg-muted/30 p-3">
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <div className="grid gap-1 sm:col-span-2 lg:col-span-1">
            <Label className="text-xs text-muted-foreground">Search inventory ID or product name</Label>
            <Input
              className="h-9 bg-background"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="e.g. AIROM0001 or Air Freshener"
            />
          </div>
          <div className="grid gap-1">
            <Label className="text-xs text-muted-foreground">Brand</Label>
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={brand}
              onChange={(e) => setBrand(e.target.value)}
            >
              <option value="">All brands</option>
              {(filters?.brands ?? []).map((b) => (
                <option key={b} value={b}>
                  {b}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1">
            <Label className="text-xs text-muted-foreground">Taxation</Label>
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={taxation}
              onChange={(e) => setTaxation(e.target.value)}
            >
              <option value="">All</option>
              {(filters?.taxations ?? []).map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1">
            <Label className="text-xs text-muted-foreground">Product type</Label>
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={productType}
              onChange={(e) => setProductType(e.target.value)}
            >
              <option value="">All</option>
              {(filters?.product_types ?? []).map((t) => (
                <option key={t} value={t}>
                  {t}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1">
            <Label className="text-xs text-muted-foreground">Stock</Label>
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={stock}
              onChange={(e) => setStock(e.target.value)}
            >
              <option value="">All</option>
              <option value="in_stock">In stock</option>
              <option value="out_of_stock">Out of stock</option>
            </select>
          </div>
          <div className="grid gap-1">
            <Label className="text-xs text-muted-foreground">Price</Label>
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={priced}
              onChange={(e) => setPriced(e.target.value)}
            >
              <option value="">All</option>
              <option value="yes">With price</option>
              <option value="no">Missing price</option>
            </select>
          </div>
        </div>

        <div className="grid gap-1.5">
          <Label className="text-xs text-muted-foreground">
            Warehouses <span className="font-normal">(DTC + FGS only)</span>
          </Label>
          <div className="flex flex-wrap gap-1.5">
            {warehouseOptions.map((wh) => {
              const on = warehouses.includes(wh);
              return (
                <button
                  key={wh}
                  type="button"
                  onClick={() => toggleWarehouse(wh)}
                  className={[
                    "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
                    on
                      ? "border-emerald-600 bg-emerald-600 text-white"
                      : "border-border bg-background text-muted-foreground hover:border-emerald-400",
                  ].join(" ")}
                >
                  {wh}
                </button>
              );
            })}
            {warehouses.length > 0 && (
              <button
                type="button"
                onClick={() => setWarehouses([])}
                className="rounded-full border border-border px-3 py-1 text-xs text-muted-foreground hover:bg-muted"
              >
                All warehouses
              </button>
            )}
          </div>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={resetFilters}>
            Reset defaults
          </Button>
        </div>
      </div>

      <CardTable
        headers={[
          "Product",
          "Brand",
          "UOM",
          "Price (ex VAT)",
          "Price incl. VAT",
          "Taxation",
          "Available",
          "Warehouse",
          "Stock",
        ]}
      >
        {rows.length === 0 && !data.isLoading && (
          <tr>
            <Td colSpan={9} className="py-6 text-center text-muted-foreground">
              No products match current filters (default: DTC + FGS, with price, in stock). Try
              Reset defaults or clear warehouses / stock / price filters.
            </Td>
          </tr>
        )}
        {rows.map((x) => {
          const exVat = Number(x.price_ex_vat ?? x.dtc_price ?? 0);
          const inclVat = Number(x.price);
          const vatAmt = Number(x.vat_amount ?? 0);
          const taxable = x.is_taxable ?? String(x.taxation ?? "").toUpperCase() === "TAXABLE";
          return (
            <tr key={x.id} className="border-b">
              <Td>
                <div className="leading-tight">
                  <div className="font-mono text-xs font-medium">{x.inventory_id}</div>
                  <div className="text-sm text-muted-foreground">{x.description ?? "—"}</div>
                </div>
              </Td>
              <Td>{x.brand ?? "—"}</Td>
              <Td>{x.uom}</Td>
              <Td>
                {exVat > 0 ? (
                  <div className="leading-tight">
                    <div>KES {exVat.toLocaleString()}</div>
                    {taxable && vatAmt > 0 ? (
                      <div className="text-xs text-amber-700">
                        VAT 16%: KES {vatAmt.toLocaleString()}
                      </div>
                    ) : (
                      <div className="text-xs text-muted-foreground">No VAT</div>
                    )}
                  </div>
                ) : (
                  "—"
                )}
              </Td>
              <Td className="font-medium">
                {inclVat > 0 ? `KES ${inclVat.toLocaleString()}` : "—"}
              </Td>
              <Td>
                <Badge variant={taxable ? "outline" : "secondary"}>
                  {x.taxation ?? x.tax ?? "—"}
                  {taxable ? " · +16%" : ""}
                </Badge>
              </Td>
              <Td>
                {x.qty_available != null ? Number(x.qty_available).toLocaleString() : "—"}
              </Td>
              <Td>{x.default_warehouse_id ?? "—"}</Td>
              <Td>
                <Badge variant={Number(x.qty_available) > 0 ? "outline" : "destructive"}>
                  {Number(x.qty_available) > 0 ? "In stock" : "Out of stock"}
                </Badge>
              </Td>
            </tr>
          );
        })}
      </CardTable>
      {total > 0 && (
        <PaginationControls
          currentPage={data.data?.current_page ?? page}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={setPerPage}
        />
      )}
    </>
  );
}
function Customers({ q }: { q: string }) {
  const { page, perPage, setPage, setPerPage } = usePagination(20);
  const data = useDtcCustomers(q, page, perPage);
  const [view,setView]=useState<DtcCustomer|null>(null);
  return (
    <><CardTable headers={["Customer", "Billing address", "Contact", "Quotes", "POS orders", "Last order", ""]}>
      {data.data?.data.map((x) => (
        <tr key={x.customer_key} className="border-b">
          <Td>
            <CustomerLink customerId={x.acumatica_id} customerName={x.name} showId className="font-semibold" />
            <div className="text-xs text-muted-foreground">QT: {x.acumatica_id} · POS account: {x.accounting_customer_id}</div>
          </Td>
          <Td>{[x.billing_address?.address_line1,x.billing_address?.address_line2].filter(Boolean).join(', ')||"—"}</Td>
          <Td>{x.email ?? x.phone ?? "—"}</Td>
          <Td>{x.quote_count}</Td>
          <Td>{x.so_count}</Td>
          <Td>{x.last_order_date?.slice(0, 10) ?? "—"}</Td>
          <Td><Button size="sm" variant="outline" onClick={()=>setView(x)}>View orders</Button></Td>
        </tr>
      ))}
    </CardTable>
    {data.data && data.data.total > 0 && (
      <PaginationControls
        currentPage={data.data.current_page}
        lastPage={data.data.last_page}
        total={data.data.total}
        perPage={perPage}
        onPageChange={setPage}
        onPerPageChange={setPerPage}
      />
    )}
    <CustomerOrders customer={view} close={()=>setView(null)}/></>
  );
}
function CustomerOrders({customer,close}:{customer:DtcCustomer|null;close:()=>void}) { return <Dialog open={!!customer} onOpenChange={v=>!v&&close()}><DialogContent className="max-w-3xl"><DialogHeader><DialogTitle>{customer?.name}</DialogTitle></DialogHeader><p className="text-sm text-muted-foreground">{customer?.email} {customer?.phone} · {[customer?.billing_address?.address_line1,customer?.billing_address?.address_line2].filter(Boolean).join(', ')}</p><h3 className="font-semibold">Quotes</h3><CardTable headers={["QT","Status","QT price"]}>{customer?.quotes.map(x=><tr className="border-b" key={x.id}><Td>{x.number}</Td><Td>{x.status}</Td><Td>KES {Number(x.total).toLocaleString()}</Td></tr>)}</CardTable><h3 className="font-semibold">POS orders</h3>{customer?.pos_orders.map(pos=><div className="space-y-2 rounded border p-3" key={pos.order_nbr}><div className="flex justify-between font-semibold"><span>{pos.order_nbr}</span><span>POS total: KES {Number(pos.total).toLocaleString()}</span></div><CardTable headers={["SKU","Product","Qty","POS unit price","Total"]}>{pos.lines.map((l,i)=><tr className="border-b" key={l.id??i}><Td>{l.inventory_id}</Td><Td>{l.description??"—"}</Td><Td>{l.quantity}</Td><Td>{Number(l.unit_price).toLocaleString()}</Td><Td>{Number(l.line_total).toLocaleString()}</Td></tr>)}</CardTable></div>)}<DialogFooter><Button onClick={close}>Close</Button></DialogFooter></DialogContent></Dialog> }
function SalesOrders({ q }: { q: string }) {
  const { page, perPage, setPage, setPerPage } = usePagination(20);
  const data = useDtcSalesOrders(q, page, perPage);
  const meta = useDtcMeta();
  const actions = useDtcActions();
  const [view, setView] = useState<DtcSalesOrder | null>(null);
  const [importOpen, setImportOpen] = useState(false);
  const [importFrom, setImportFrom] = useState(monthStart());
  const [importTo, setImportTo] = useState(todayIso());

  return (
    <>
      <div className="flex justify-end gap-2">
        {(meta.data?.can_import_pos || meta.data?.can_import_quotes) && (
          <Button variant="outline" onClick={() => setImportOpen(true)}>
            <ShoppingCart className="mr-1 h-4 w-4" />
            Import POS (date range)
          </Button>
        )}
      </div>
      <CardTable headers={["SO number", "Customer", "Acumatica date", "Status", "POS value", "Originating QT", ""]}>
        {data.data?.data.map((x) => (
          <tr key={x.id} className="border-b">
            <Td>
              <b>{x.acumatica_order_nbr}</b>
            </Td>
            <Td><CustomerLink customerId={x.customer_acumatica_id} customerName={x.customer_name} /></Td>
            <Td>{formatAcumaticaDate(x.acumatica_date ?? x.order_date)}</Td>
            <Td>
              <Badge variant="outline">{x.status}</Badge>
            </Td>
            <Td>KES {Number(x.order_total).toLocaleString()}</Td>
            <Td>
              {x.dtc_conversion?.quote?.acumatica_quote_nbr ??
                x.dtc_conversion?.quote?.public_ref ??
                "—"}
            </Td>
            <Td>
              <Button size="sm" variant="outline" onClick={() => setView(x)}>
                View POS
              </Button>
            </Td>
          </tr>
        ))}
      </CardTable>
      {data.data && data.data.total > 0 && (
        <PaginationControls
          currentPage={data.data.current_page}
          lastPage={data.data.last_page}
          total={data.data.total}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={setPerPage}
        />
      )}
      <PosOrderDetail order={view} close={() => setView(null)} />
      <Dialog open={importOpen} onOpenChange={setImportOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Import POS orders</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            Pull Acumatica sales orders (OrderType SO) for POS customer{" "}
            <b>{meta.data?.pos_order_customer_id ?? "CUST101470"}</b> into OrderWatch.
          </p>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <Label className="text-xs">From</Label>
              <Input type="date" value={importFrom} onChange={(e) => setImportFrom(e.target.value)} />
            </div>
            <div>
              <Label className="text-xs">To</Label>
              <Input type="date" value={importTo} onChange={(e) => setImportTo(e.target.value)} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setImportOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={
                actions.importPos.isPending || !importFrom || !importTo || importFrom > importTo
              }
              onClick={() =>
                actions.importPos.mutate(
                  { date_from: importFrom, date_to: importTo },
                  { onSuccess: () => setImportOpen(false) },
                )
              }
            >
              {actions.importPos.isPending ? "Importing…" : "Import POS"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
function PosOrderDetail({order,close}:{order:DtcSalesOrder|null;close:()=>void}) { const conversion=order?.dtc_conversion as any; const lines=conversion?.pos_lines_snapshot??order?.lines??[]; return <Dialog open={!!order} onOpenChange={v=>!v&&close()}><DialogContent className="max-w-3xl"><DialogHeader><DialogTitle>POS {order?.acumatica_order_nbr}</DialogTitle></DialogHeader><div className="grid grid-cols-2 text-sm"><span>Customer</span><b>{conversion?.customer_details_snapshot?.name??order?.customer_name}</b><span>Originating QT</span><b>{conversion?.quote?.acumatica_quote_nbr??conversion?.quote?.public_ref??"—"}</b></div><CardTable headers={["SKU","Product","Qty","Unit price","Total"]}>{lines.map((l:any,i:number)=><tr className="border-b" key={l.id??i}><Td>{l.inventory_id}</Td><Td>{l.description??"—"}</Td><Td>{Number(l.quantity??l.order_qty)}</Td><Td>{Number(l.unit_price).toLocaleString()}</Td><Td>{Number(l.line_total??l.ext_cost).toLocaleString()}</Td></tr>)}</CardTable><div className="text-right font-bold">POS total: KES {Number(order?.order_total??0).toLocaleString()}</div><DialogFooter><Button onClick={close}>Close</Button></DialogFooter></DialogContent></Dialog> }
function CardTable({ headers, children }: { headers: string[]; children: any }) {
  return (
    <div className="overflow-x-auto rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/40 text-left text-xs uppercase text-muted-foreground">
            {headers.map((h) => (
              <th key={h} className="px-3 py-2">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}
function Td({ children, className, colSpan }: { children: any; className?: string; colSpan?: number }) {
  return (
    <td className={["px-3 py-3", className].filter(Boolean).join(" ")} colSpan={colSpan}>
      {children}
    </td>
  );
}

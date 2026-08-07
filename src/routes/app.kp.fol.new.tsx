import { Link, createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  ArrowLeft,
  Check,
  ClipboardList,
  Clock,
  DollarSign,
  FileText,
  History,
  Info,
  MapPin,
  Package,
  Paperclip,
  Phone,
  Save,
  Search,
  Send,
  ShoppingCart,
  Trash2,
  TrendingUp,
  Upload,
  User,
  Wallet,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { CONTACT_DESIGNATIONS } from "@/lib/contact-designations";
import { useCustomerContacts, type CustomerContact } from "@/hooks/useCustomerContacts";
import { FolOrderHistoryDrawer } from "@/components/fol/FolOrderHistoryDrawer";
import {
  useCreateFol,
  useFolCustomers,
  useFolInventory,
  useFolMetrics,
  useFolRequest,
  deleteFolAttachment,
  submitFolRequest,
  updateFolDraft,
  uploadFolAttachments,
  folLinePricing,
  FOL_VAT_RATE,
  type FolAttachment,
  type FolCustomer,
  type FolInput,
  type FolInventoryItem,
  type FolLine,
  type FolRequest,
} from "@/hooks/useFol";
import { cn } from "@/lib/utils";

type NewFolSearch = {
  customer?: string;
  name?: string;
  /** Existing draft FOL id to load and edit. */
  draft?: string;
};

export const Route = createFileRoute("/app/kp/fol/new")({
  head: () => ({ meta: [{ title: "New KP FOL - Kim-Fay Sight" }] }),
  validateSearch: (search: Record<string, unknown>): NewFolSearch => ({
    customer: typeof search.customer === "string" ? search.customer : undefined,
    name: typeof search.name === "string" ? search.name : undefined,
    draft: typeof search.draft === "string" ? search.draft : typeof search.draft === "number" ? String(search.draft) : undefined,
  }),
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
  const search = Route.useSearch();
  const editDraftId = search.draft?.trim() || "";
  const existingDraft = useFolRequest(editDraftId || undefined);
  const [customerQ, setCustomerQ] = useState("");
  const [skuQ, setSkuQ] = useState("");
  const [skuPurpose, setSkuPurpose] = useState<"fol_item" | "consumable">("fol_item");
  const [skuPickerOpen, setSkuPickerOpen] = useState(false);
  const [customer, setCustomer] = useState<FolCustomer | null>(null);
  const prefillApplied = useRef(false);
  const draftHydrated = useRef(false);
  const [files, setFiles] = useState<File[]>([]);
  const [draftId, setDraftId] = useState<number | null>(editDraftId ? Number(editDraftId) : null);
  const [existingAttachments, setExistingAttachments] = useState<FolAttachment[]>([]);
  const [publicRef, setPublicRef] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [requestorMode, setRequestorMode] = useState<"existing" | "new">("new");
  const [historyDrawerOpen, setHistoryDrawerOpen] = useState(false);
  const [topProductsExpanded, setTopProductsExpanded] = useState(false);
  const [form, setForm] = useState<FolInput>({
    customer_acumatica_id: "",
    request_origin: "sales_consultant_visit",
    requestor_first_name: "",
    requestor_last_name: "",
    requestor_phone: "",
    requestor_email: "",
    requestor_contact_id: null,
    save_requestor_as_contact: true,
    requestor_designation_key: "head_procurement",
    requestor_designation_label: "",
    issue_types: [],
    reason_text: "",
    installation_required: false,
    customer_has_submitted_po: false,
    consumable_inventory_ids: [],
    consumables_metrics_source: "system_so",
    debt_explanation: "",
    lines: [],
  });

  const customers = useFolCustomers(customerQ);
  // Browse eligible SKUs when picker is open (empty q = first page of FOL products).
  const inventory = useFolInventory(skuQ, skuPickerOpen, skuPurpose);
  const accountContacts = useCustomerContacts(form.customer_acumatica_id || null, !!form.customer_acumatica_id);
  const metrics = useFolMetrics(form.customer_acumatica_id, form.consumable_inventory_ids ?? []);
  /** Auto-apply primary (or first) contact once per selected account. */
  const autoFilledForCustomer = useRef<string | null>(null);
  const skuPickerRef = useRef<HTMLDivElement | null>(null);
  const skuInputRef = useRef<HTMLInputElement | null>(null);
  const cartLineIds = useMemo(
    () => new Set(form.lines.map((line) => line.inventory_id)),
    [form.lines],
  );
  const consumableIds = useMemo(
    () => new Set(form.consumable_inventory_ids ?? []),
    [form.consumable_inventory_ids],
  );

  const isEditMode = draftId !== null || !!editDraftId;
  const contactList = accountContacts.data?.data ?? [];
  const busy = saving || createFol.isPending || existingDraft.isLoading;

  // Live required-field validation — drives asterisks, field highlighting, and Submit gating.
  const requiredFieldErrors = useMemo(() => ({
    customer: !form.customer_acumatica_id,
    requestor_first_name: !form.requestor_first_name.trim(),
    requestor_last_name: !form.requestor_last_name.trim(),
    requestor_phone: !form.requestor_phone.trim(),
    requestor_email: !form.requestor_email.trim(),
    request_origin: !form.request_origin,
  }), [
    form.customer_acumatica_id,
    form.requestor_first_name,
    form.requestor_last_name,
    form.requestor_phone,
    form.requestor_email,
    form.request_origin,
  ]);
  const canSubmit = !Object.values(requiredFieldErrors).some(Boolean);

  function hydrateFromDraft(fol: FolRequest) {
    if (fol.status !== "draft") {
      toast.error("Only draft FOL requests can be edited.");
      void navigate({ to: "/app/kp/fol/$id", params: { id: String(fol.id) } });
      return;
    }

    setDraftId(fol.id);
    setPublicRef(fol.public_ref);
    setExistingAttachments(fol.attachments ?? []);
    setCustomer({
      acumatica_id: fol.customer_acumatica_id,
      name: fol.customer_name,
      customer_class: "KP",
      status: null,
      email: null,
      phone: null,
      payment_terms: null,
    });
    autoFilledForCustomer.current = fol.customer_acumatica_id;
    setRequestorMode(fol.requestor_contact_id ? "existing" : "new");
    setForm({
      customer_acumatica_id: fol.customer_acumatica_id,
      request_origin: fol.request_origin || "sales_consultant_visit",
      requestor_first_name: fol.requestor_first_name ?? "",
      requestor_last_name: fol.requestor_last_name ?? "",
      requestor_phone: fol.requestor_phone ?? "",
      requestor_email: fol.requestor_email ?? "",
      requestor_contact_id: fol.requestor_contact_id ?? null,
      save_requestor_as_contact: false,
      requestor_designation_key: "head_procurement",
      requestor_designation_label: "",
      issue_types: Array.isArray(fol.issue_types) ? fol.issue_types : [],
      reason_text: fol.reason_text ?? "",
      installation_required: !!fol.installation_required,
      installation_location: fol.installation_location ?? "",
      customer_has_submitted_po: !!fol.customer_has_submitted_po,
      consumable_inventory_ids: fol.consumable_inventory_ids ?? [],
      consumables_last_purchase_date: fol.consumables_last_purchase_date
        ? String(fol.consumables_last_purchase_date).slice(0, 10)
        : null,
      consumables_sales_3m_kes: Number(fol.consumables_sales_3m_kes ?? 0),
      consumables_volume_3m: Number(fol.consumables_volume_3m ?? 0),
      consumables_sales_6m_kes: Number(fol.consumables_sales_6m_kes ?? 0),
      consumables_volume_6m: Number(fol.consumables_volume_6m ?? 0),
      consumables_metrics_source: fol.consumables_metrics_source ?? "system_so",
      consumables_override_reason: fol.consumables_override_reason ?? "",
      debt_explanation: fol.debt_explanation ?? "",
      lines: (fol.lines ?? []).map((line) => ({
        inventory_id: line.inventory_id,
        product_description: line.product_description ?? null,
        qty_requested: Number(line.qty_requested || 1),
        qty_previously_issued: Number(line.qty_previously_issued ?? 0),
        date_last_issue: line.date_last_issue ? String(line.date_last_issue).slice(0, 10) : null,
        fol_date: line.fol_date ? String(line.fol_date).slice(0, 10) : null,
        unit_price: Number(line.unit_price ?? 0),
      })),
    });
  }

  // Load existing draft when opened via ?draft=id
  useEffect(() => {
    if (!editDraftId || draftHydrated.current) return;
    if (existingDraft.isLoading) return;
    if (existingDraft.data) {
      draftHydrated.current = true;
      hydrateFromDraft(existingDraft.data);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editDraftId, existingDraft.isLoading, existingDraft.data]);

  function applyContact(contact: CustomerContact) {
    setRequestorMode("existing");
    setForm((prev) => ({
      ...prev,
      requestor_contact_id: contact.id,
      requestor_first_name: contact.first_name,
      requestor_last_name: contact.last_name,
      requestor_phone: contact.phone ?? "",
      requestor_email: contact.email ?? "",
      requestor_designation_key: contact.designation_key,
      requestor_designation_label: contact.designation_label,
      save_requestor_as_contact: false,
    }));
  }

  function pickCustomer(next: FolCustomer) {
    setCustomer(next);
    setCustomerQ("");
    setRequestorMode("new");
    autoFilledForCustomer.current = null;
    setForm((prev) => ({
      ...prev,
      customer_acumatica_id: next.acumatica_id,
      requestor_contact_id: null,
      requestor_first_name: "",
      requestor_last_name: "",
      requestor_phone: "",
      requestor_email: "",
      save_requestor_as_contact: true,
    }));
  }

  // Prefill KP account when opened from Accounts → Request FOL.
  useEffect(() => {
    if (prefillApplied.current) return;
    const id = search.customer?.trim();
    if (!id) return;
    prefillApplied.current = true;
    const name = search.name?.trim() || id;
    pickCustomer({
      acumatica_id: id,
      name,
      customer_class: "KP",
      status: null,
      email: null,
      phone: null,
      payment_terms: null,
    });
    // One-shot prefill from URL search params
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search.customer, search.name]);

  function pickContact(contactId: string) {
    if (contactId === "__new__") {
      setRequestorMode("new");
      setForm((prev) => ({
        ...prev,
        requestor_contact_id: null,
        save_requestor_as_contact: true,
      }));
      return;
    }
    const contact = contactList.find((c) => String(c.id) === contactId);
    if (!contact) return;
    applyContact(contact);
  }

  // When account contacts load, prefer primary then first contact for requestor.
  useEffect(() => {
    const customerId = form.customer_acumatica_id;
    if (!customerId || accountContacts.isLoading) return;
    if (autoFilledForCustomer.current === customerId) return;
    if (form.requestor_contact_id) {
      autoFilledForCustomer.current = customerId;
      return;
    }
    const list = accountContacts.data?.data ?? [];
    if (list.length === 0) {
      autoFilledForCustomer.current = customerId;
      return;
    }
    const preferred = list.find((c) => c.is_primary) ?? list[0];
    applyContact(preferred);
    autoFilledForCustomer.current = customerId;
  }, [form.customer_acumatica_id, form.requestor_contact_id, accountContacts.isLoading, accountContacts.data]);

  // Close SKU picker when clicking outside.
  useEffect(() => {
    if (!skuPickerOpen) return;
    function onPointerDown(event: MouseEvent) {
      const el = skuPickerRef.current;
      if (el && !el.contains(event.target as Node)) {
        setSkuPickerOpen(false);
      }
    }
    document.addEventListener("mousedown", onPointerDown);
    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [skuPickerOpen]);

  /** Add SKU to cart (or +1 qty if already in cart). */
  function addSkuToCart(item: FolInventoryItem) {
    if (skuPurpose === "consumable") {
      setForm((prev) => ({
        ...prev,
        consumable_inventory_ids: Array.from(new Set([...(prev.consumable_inventory_ids ?? []), item.inventory_id])),
      }));
      toast.success(`${item.inventory_id} added as supporting consumable`, { duration: 1600 });
      setSkuQ("");
      skuInputRef.current?.focus();
      return;
    }
    const prior = metrics.data?.prior_issued?.[item.inventory_id];
    const unitPrice = Number(item.sales_price) || 0;
    setForm((prev) => {
      const existingIndex = prev.lines.findIndex((line) => line.inventory_id === item.inventory_id);
      if (existingIndex >= 0) {
        const next = [...prev.lines];
        const existing = next[existingIndex];
        next[existingIndex] = {
          ...existing,
          qty_requested: Number(existing.qty_requested || 0) + 1,
          // Keep / refresh unit price from latest inventory snapshot.
          unit_price: unitPrice > 0 ? unitPrice : existing.unit_price ?? 0,
        };
        return { ...prev, lines: next };
      }
      const line: FolLine = {
        inventory_id: item.inventory_id,
        product_description: item.description,
        qty_requested: 1,
        qty_previously_issued: prior?.qty ?? 0,
        date_last_issue: prior?.date ?? null,
        unit_price: unitPrice,
      };
      return { ...prev, lines: [...prev.lines, line] };
    });
    toast.success(`${item.inventory_id} added to cart`, { duration: 1600 });
    // Keep picker open so multiple items can be added; clear search for next pick.
    setSkuQ("");
    skuInputRef.current?.focus();
  }

  function removeLine(index: number) {
    setForm((prev) => ({ ...prev, lines: prev.lines.filter((_, i) => i !== index) }));
  }

  function updateLineQty(index: number, qty: number) {
    setForm((prev) => {
      const next = [...prev.lines];
      next[index] = { ...next[index], qty_requested: Math.max(1, qty) };
      return { ...prev, lines: next };
    });
  }

  function updateLineUnitPrice(index: number, unitPrice: number) {
    setForm((prev) => {
      const next = [...prev.lines];
      next[index] = { ...next[index], unit_price: Math.max(0, unitPrice) };
      return { ...prev, lines: next };
    });
  }

  function updateLineFolDate(index: number, folDate: string) {
    setForm((prev) => {
      const next = [...prev.lines];
      next[index] = { ...next[index], fol_date: folDate || null };
      return { ...prev, lines: next };
    });
  }

  const cartQtyTotal = form.lines.reduce((sum, line) => sum + Number(line.qty_requested || 0), 0);
  const cartPricing = useMemo(() => {
    return form.lines.reduce(
      (acc, line) => {
        const p = folLinePricing(line);
        acc.subtotal += p.subtotal;
        acc.vat += p.vat;
        acc.total += p.total;
        return acc;
      },
      { subtotal: 0, vat: 0, total: 0 },
    );
  }, [form.lines]);

  function buildPayload(): FolInput {
    const metricsData = metrics.data?.metrics;
    return {
      ...form,
      consumables_last_purchase_date: form.consumables_last_purchase_date ?? metricsData?.last_purchase_date ?? null,
      consumables_sales_3m_kes: form.consumables_sales_3m_kes ?? metricsData?.sales_3m_kes ?? 0,
      consumables_volume_3m: form.consumables_volume_3m ?? metricsData?.volume_3m ?? 0,
      consumables_sales_6m_kes: form.consumables_sales_6m_kes ?? metricsData?.sales_6m_kes ?? 0,
      consumables_volume_6m: form.consumables_volume_6m ?? metricsData?.volume_6m ?? 0,
    };
  }

  async function persistDraft(): Promise<number> {
    const payload = buildPayload();
    if (draftId !== null) {
      const updated = await updateFolDraft(draftId, payload);
      setPublicRef(updated.public_ref);
      setExistingAttachments(updated.attachments ?? []);
      if (files.length > 0) {
        const withFiles = await uploadFolAttachments(updated.id, files);
        setExistingAttachments(withFiles.attachments ?? []);
        setFiles([]);
      }
      return updated.id;
    }

    const created = await createFol.mutateAsync(payload);
    setDraftId(created.id);
    setPublicRef(created.public_ref);
    setExistingAttachments(created.attachments ?? []);
    if (files.length > 0) {
      const withFiles = await uploadFolAttachments(created.id, files);
      setExistingAttachments(withFiles.attachments ?? []);
      setFiles([]);
    }
    // Keep URL in sync so refresh continues editing the same draft.
    void navigate({
      to: "/app/kp/fol/new",
      search: { draft: String(created.id) },
      replace: true,
    });
    return created.id;
  }

  async function saveDraft() {
    try {
      setSaving(true);
      await persistDraft();
      toast.success(isEditMode ? "Draft updated." : "FOL draft saved.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to save FOL draft.");
    } finally {
      setSaving(false);
    }
  }

  async function submit() {
    try {
      setSaving(true);
      const id = await persistDraft();
      await submitFolRequest(id);
      toast.success("FOL request submitted.");
      void navigate({ to: "/app/kp/fol/$id", params: { id: String(id) } });
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to submit FOL request.");
    } finally {
      setSaving(false);
    }
  }

  async function removeExistingAttachment(attachmentId: number) {
    try {
      setSaving(true);
      const updated = await deleteFolAttachment(attachmentId);
      setExistingAttachments(updated.attachments ?? []);
      toast.success("Attachment removed.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to remove attachment.");
    } finally {
      setSaving(false);
    }
  }

  const metricCards = useMemo(() => metrics.data?.metrics, [metrics.data]);
  const priorFol = metricCards?.prior_fol ?? metrics.data?.prior_fol ?? null;

  // Top SO-backed products (3M) by revenue — derived client-side from already-fetched
  // per-SKU evidence; scope is cart lines when present, else all FOL-eligible SKUs.
  const topProducts = useMemo(() => {
    const map = metricCards?.line_last_purchases ?? {};
    const rows = Object.entries(map)
      .map(([inventoryId, v]) => ({
        inventory_id: inventoryId,
        product_description: form.lines.find((l) => l.inventory_id === inventoryId)?.product_description ?? null,
        value_3m: Number(v?.value_3m ?? 0),
      }))
      .filter((row) => row.value_3m > 0)
      .sort((a, b) => b.value_3m - a.value_3m);
    const total = rows.reduce((sum, row) => sum + row.value_3m, 0);
    return { rows, total };
  }, [metricCards, form.lines]);

  if (editDraftId && existingDraft.isLoading) {
    return (
      <div className="mx-auto max-w-6xl space-y-3">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (editDraftId && existingDraft.isError) {
    return (
      <div className="mx-auto max-w-6xl space-y-4">
        <div className="rounded-lg border bg-card p-6 text-sm text-muted-foreground">
          Could not load draft FOL #{editDraftId}. It may have been submitted or you may not have access.
        </div>
        <Button asChild variant="outline" size="sm">
          <Link to="/app/kp/fol"><ArrowLeft className="mr-1 h-3.5 w-3.5" /> Back to FOL list</Link>
        </Button>
      </div>
    );
  }

  const latestOrder = metricCards?.recent_orders?.[0] ?? null;

  return (
    <div className="mx-auto max-w-6xl space-y-5 pb-24">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Button asChild variant="ghost" size="sm" className="-ml-2 mb-1">
            {draftId ? (
              <Link to="/app/kp/fol/$id" params={{ id: String(draftId) }}>
                <ArrowLeft className="mr-1 h-3.5 w-3.5" /> Back
              </Link>
            ) : (
              <Link to="/app/kp/fol">
                <ArrowLeft className="mr-1 h-3.5 w-3.5" /> Back
              </Link>
            )}
          </Button>
          <h1 className="text-xl font-semibold tracking-tight">
            {isEditMode ? "Edit FOL draft" : "New KP FOL Request"}
          </h1>
          <p className="text-sm text-muted-foreground">
            {isEditMode
              ? `Update ${publicRef ?? `draft #${draftId}`} before submitting for approval.`
              : "Create a portfolio-scoped FOL requisition with SO-backed metrics."}
          </p>
        </div>
        <Button
          variant="outline"
          onClick={() => setHistoryDrawerOpen(true)}
          disabled={!form.customer_acumatica_id}
        >
          <History className="mr-1.5 h-4 w-4" /> View Order History
        </Button>
      </div>

      <div className="grid items-start gap-4 lg:grid-cols-[1.2fr_0.8fr]">
        {/* Left column — the form, in numbered sections */}
        <div className="space-y-4">
          <SectionCard number={1} icon={User} title="Customer & site requester" description="KP account is the customer company. Requestor is the customer-side contact (site), not the sales consultant.">
            <div className="relative">
              <Label>
                KP account <RequiredMark />
              </Label>
              <div className="relative mt-1">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  value={customerQ}
                  onChange={(e) => setCustomerQ(e.target.value)}
                  placeholder="Search KP customer"
                  className={cn("pl-8", requiredFieldErrors.customer && "border-red-400 focus-visible:ring-red-400/40 bg-red-50/40 dark:bg-red-950/10")}
                />
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

            {form.customer_acumatica_id && (
              <div className="space-y-2">
                <Label>
                  Requestor contact <RequiredMark />
                </Label>
                <Select
                  value={form.requestor_contact_id ? String(form.requestor_contact_id) : "__new__"}
                  onValueChange={pickContact}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select contact or enter new" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__new__">Enter new requestor…</SelectItem>
                    {contactList.map((c) => (
                      <SelectItem key={c.id} value={String(c.id)}>
                        {c.designation_label} — {c.full_name}
                        {c.is_primary ? " (Primary)" : ""}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {accountContacts.isLoading && (
                  <p className="text-xs text-muted-foreground">Loading account contacts…</p>
                )}
                {!accountContacts.isLoading && contactList.length === 0 && (
                  <p className="text-xs text-muted-foreground">
                    No saved contacts on this account yet. Enter details below — they can be saved to the account.
                  </p>
                )}
              </div>
            )}
          </SectionCard>

          <SectionCard number={2} icon={Phone} title="Requestor contact details">
            {requestorMode === "new" && form.customer_acumatica_id && (
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                  <Label>Designation</Label>
                  <Select
                    value={form.requestor_designation_key ?? "head_procurement"}
                    onValueChange={(v) => setForm({ ...form, requestor_designation_key: v })}
                  >
                    <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {CONTACT_DESIGNATIONS.map((d) => (
                        <SelectItem key={d.key} value={d.key}>{d.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                {form.requestor_designation_key === "custom" && (
                  <div className="sm:col-span-2">
                    <Field
                      label="Custom job title"
                      value={form.requestor_designation_label ?? ""}
                      onChange={(v) => setForm({ ...form, requestor_designation_label: v })}
                    />
                  </div>
                )}
              </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
              <Field
                label="First name"
                required
                error={requiredFieldErrors.requestor_first_name}
                value={form.requestor_first_name}
                onChange={(v) => setForm({ ...form, requestor_first_name: v, requestor_contact_id: requestorMode === "existing" ? form.requestor_contact_id : null })}
              />
              <Field
                label="Last name"
                required
                error={requiredFieldErrors.requestor_last_name}
                value={form.requestor_last_name}
                onChange={(v) => setForm({ ...form, requestor_last_name: v })}
              />
              <Field
                label="Phone"
                required
                error={requiredFieldErrors.requestor_phone}
                value={form.requestor_phone}
                onChange={(v) => setForm({ ...form, requestor_phone: v })}
              />
              <Field
                label="Email"
                required
                error={requiredFieldErrors.requestor_email}
                value={form.requestor_email}
                onChange={(v) => setForm({ ...form, requestor_email: v })}
              />
            </div>
            {requestorMode === "new" && form.customer_acumatica_id && (
              <label className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={!!form.save_requestor_as_contact}
                  onCheckedChange={(checked) => setForm({ ...form, save_requestor_as_contact: Boolean(checked) })}
                />
                Save this requestor as a contact on the account
              </label>
            )}
          </SectionCard>

          <SectionCard
            number={3}
            icon={ShoppingCart}
            title="FOL lines"
            description="Click the field to browse FOL-eligible SKUs, search, then click an item to add it to the cart. Mark products eligible in KP CRM → FOL Settings → FOL Products."
            action={
              <Badge variant="secondary" className="gap-1.5 tabular-nums">
                <ShoppingCart className="h-3.5 w-3.5" />
                {form.lines.length} item{form.lines.length === 1 ? "" : "s"}
                {cartQtyTotal > 0 ? ` · qty ${cartQtyTotal}` : ""}
              </Badge>
            }
          >
            {/* Searchable product picker — opens on focus/click, click row adds to cart */}
            <div ref={skuPickerRef} className="relative">
              <div className="mb-2 flex flex-wrap items-end justify-between gap-2">
                <Label className="text-xs">Add inventory item</Label>
                <Select
                  value={skuPurpose}
                  onValueChange={(value) => {
                    setSkuPurpose(value as "fol_item" | "consumable");
                    setSkuQ("");
                    setSkuPickerOpen(false);
                  }}
                >
                  <SelectTrigger className="h-8 w-[250px]"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="fol_item">FOL item (free equipment)</SelectItem>
                    <SelectItem value="consumable">Consumable (sales evidence)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="relative mt-1">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  ref={skuInputRef}
                  value={skuQ}
                  onChange={(e) => {
                    setSkuQ(e.target.value);
                    setSkuPickerOpen(true);
                  }}
                  onFocus={() => setSkuPickerOpen(true)}
                  onClick={() => setSkuPickerOpen(true)}
                  onKeyDown={(e) => {
                    if (e.key === "Escape") {
                      setSkuPickerOpen(false);
                      skuInputRef.current?.blur();
                    }
                  }}
                  placeholder={skuPurpose === "fol_item" ? "Search free FOL equipment…" : "Search supporting consumables…"}
                  className="h-10 pl-8 pr-9"
                  autoComplete="off"
                  aria-expanded={skuPickerOpen}
                  aria-controls="fol-sku-dropdown"
                  role="combobox"
                />
                {skuQ && (
                  <button
                    type="button"
                    className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    onClick={() => {
                      setSkuQ("");
                      setSkuPickerOpen(true);
                      skuInputRef.current?.focus();
                    }}
                    aria-label="Clear search"
                  >
                    <X className="h-4 w-4" />
                  </button>
                )}
              </div>

              {skuPickerOpen && (
                <div
                  id="fol-sku-dropdown"
                  className="absolute z-30 mt-1 w-full overflow-hidden rounded-md border bg-popover shadow-lg"
                  role="listbox"
                >
                  <div className="flex items-center justify-between border-b bg-muted/40 px-3 py-1.5 text-[11px] text-muted-foreground">
                    <span>
                      {skuQ.trim()
                        ? `Results for “${skuQ.trim()}”`
                        : "FOL-eligible products — type to filter"}
                    </span>
                    <button
                      type="button"
                      className="font-medium text-foreground hover:underline"
                      onClick={() => setSkuPickerOpen(false)}
                    >
                      Close
                    </button>
                  </div>
                  <div className="max-h-72 overflow-auto">
                    {inventory.isLoading || inventory.isFetching ? (
                      <div className="px-3 py-6 text-center text-sm text-muted-foreground">Loading products…</div>
                    ) : !inventory.data || inventory.data.length === 0 ? (
                      <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                        {skuQ.trim()
                          ? "No FOL-eligible SKU matches."
                          : "No FOL-eligible products yet. Mark SKUs in FOL Settings → FOL Products."}
                      </div>
                    ) : (
                      inventory.data.map((item) => {
                        const inCart = skuPurpose === "fol_item"
                          ? cartLineIds.has(item.inventory_id)
                          : consumableIds.has(item.inventory_id);
                        const cartLine = form.lines.find((l) => l.inventory_id === item.inventory_id);
                        return (
                          <button
                            key={item.inventory_id}
                            type="button"
                            role="option"
                            aria-selected={inCart}
                            onClick={() => addSkuToCart(item)}
                            className={cn(
                              "flex w-full items-start gap-3 border-b border-border/60 px-3 py-2.5 text-left text-sm last:border-b-0 transition-colors",
                              "hover:bg-primary/5 focus-visible:bg-primary/10 focus-visible:outline-none",
                              inCart && "bg-primary/5",
                            )}
                          >
                            <div className={cn(
                              "mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md border",
                              inCart ? "border-primary bg-primary text-primary-foreground" : "border-muted-foreground/25 bg-muted/40 text-muted-foreground",
                            )}>
                              {inCart ? <Check className="h-3.5 w-3.5" /> : <Package className="h-3.5 w-3.5" />}
                            </div>
                            <div className="min-w-0 flex-1">
                              <div className="flex flex-wrap items-center gap-1.5">
                                <span className="font-mono text-xs font-semibold tracking-tight">{item.inventory_id}</span>
                                {item.fol_category && (
                                  <Badge variant="outline" className="h-5 text-[10px] capitalize">{item.fol_category}</Badge>
                                )}
                                {inCart && (
                                  <Badge className="h-5 text-[10px]">In cart · qty {cartLine?.qty_requested ?? 1}</Badge>
                                )}
                              </div>
                              <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                {item.description || "No description"}
                              </p>
                              <p className="hidden mt-0.5 text-[11px] tabular-nums text-foreground/80" aria-hidden="true">
                                {Number(item.sales_price) > 0
                                  ? `KES ${Number(item.sales_price).toLocaleString()} / ${item.default_uom || "unit"} · ex-VAT${item.price_source === "last_so" ? " (last SO)" : ""}`
                                  : "Price not set — enter unit price in cart"}
                              </p>
                            </div>
                            <span className="shrink-0 self-center text-[11px] font-medium text-primary">
                              {inCart ? (skuPurpose === "fol_item" ? "+1" : "Selected") : "Add"}
                            </span>
                          </button>
                        );
                      })
                    )}
                  </div>
                </div>
              )}
            </div>

            <div className="rounded-md border bg-muted/20 p-3">
              <div className="mb-2 flex items-center justify-between gap-2">
                <div>
                  <p className="text-sm font-medium">Supporting consumables</p>
                  <p className="text-xs text-muted-foreground">These paid items supply the 3- and 6-month sales evidence; they are not issued free.</p>
                </div>
                <Badge variant="outline">{(form.consumable_inventory_ids ?? []).length}</Badge>
              </div>
              {(form.consumable_inventory_ids ?? []).length === 0 ? (
                <p className="text-xs text-muted-foreground">No supporting consumables selected.</p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {(form.consumable_inventory_ids ?? []).map((inventoryId) => (
                    <Badge key={inventoryId} variant="secondary" className="gap-1 font-mono">
                      {inventoryId}
                      <button
                        type="button"
                        aria-label={`Remove ${inventoryId}`}
                        onClick={() => setForm((prev) => ({
                          ...prev,
                          consumable_inventory_ids: (prev.consumable_inventory_ids ?? []).filter((id) => id !== inventoryId),
                        }))}
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </Badge>
                  ))}
                </div>
              )}
            </div>

            {/* Shopping cart */}
            <div className="overflow-hidden rounded-md border">
              <div className="flex items-center justify-between gap-2 border-b bg-muted/40 px-3 py-2">
                <div className="flex items-center gap-2 text-sm font-medium">
                  <ShoppingCart className="h-4 w-4 text-muted-foreground" />
                  Cart
                </div>
                {form.lines.length > 0 && (
                  <button
                    type="button"
                    className="text-xs text-muted-foreground hover:text-destructive hover:underline"
                    onClick={() => setForm((prev) => ({ ...prev, lines: [] }))}
                  >
                    Clear cart
                  </button>
                )}
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-muted/20">
                    <tr>
                      <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">SKU</th>
                      <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">Description</th>
                      <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">Qty</th>
                      <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">FOL Date</th>
                      <th className="px-3 py-2 text-left text-[11px] font-semibold uppercase text-muted-foreground">Prior FOL</th>
                      <th className="px-3 py-2" />
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {form.lines.length === 0 && (
                      <tr>
                        <td colSpan={6} className="px-3 py-10 text-center text-muted-foreground">
                          <ShoppingCart className="mx-auto mb-2 h-8 w-8 opacity-30" />
                          <p className="text-sm">Cart is empty</p>
                          <p className="mt-1 text-xs">Click the product field above and select SKUs to add.</p>
                        </td>
                      </tr>
                    )}
                    {form.lines.map((line, index) => {
                      const pricing = folLinePricing(line);
                      return (
                        <tr key={`${line.inventory_id}-${index}`} className="bg-card">
                          <td className="px-3 py-2.5 font-mono text-xs font-semibold">{line.inventory_id}</td>
                          <td className="px-3 py-2.5 text-xs text-muted-foreground">{line.product_description}</td>
                          <td className="px-3 py-2.5">
                            <Input
                              type="number"
                              min={1}
                              value={line.qty_requested}
                              onChange={(e) => updateLineQty(index, Number(e.target.value))}
                              className="h-8 w-20"
                            />
                          </td>
                          <td className="px-3 py-2.5">
                            <Input
                              type="date"
                              value={line.fol_date ?? ""}
                              onChange={(e) => updateLineFolDate(index, e.target.value)}
                              className="h-8 w-36"
                            />
                          </td>
                          <td className="hidden px-3 py-2.5 text-right" aria-hidden="true">
                            <Input
                              type="number"
                              min={0}
                              step="0.01"
                              value={line.unit_price ?? 0}
                              onChange={(e) => updateLineUnitPrice(index, Number(e.target.value))}
                              className="ml-auto h-8 w-28 text-right tabular-nums"
                              title="Unit price (ex-VAT)"
                            />
                          </td>
                          <td className="hidden px-3 py-2.5 text-right text-xs tabular-nums font-medium" aria-hidden="true">
                            {formatKesMoney(pricing.subtotal)}
                          </td>
                          <td className="hidden px-3 py-2.5 text-right text-xs tabular-nums text-muted-foreground" aria-hidden="true">
                            {formatKesMoney(pricing.vat)}
                          </td>
                          <td className="hidden px-3 py-2.5 text-right text-xs tabular-nums font-semibold" aria-hidden="true">
                            {formatKesMoney(pricing.total)}
                          </td>
                          <td className="px-3 py-2.5 text-xs text-muted-foreground">
                            {line.qty_previously_issued ?? 0}
                            {line.date_last_issue ? ` · ${line.date_last_issue}` : ""}
                          </td>
                          <td className="px-3 py-2.5 text-right">
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8 text-muted-foreground hover:text-destructive"
                              onClick={() => removeLine(index)}
                              aria-label={`Remove ${line.inventory_id}`}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                  {form.lines.length > 0 && (
                    <tfoot className="border-t bg-muted/30">
                      <tr>
                        <td colSpan={4} className="px-3 py-2.5 text-xs font-semibold uppercase text-muted-foreground">
                          Cart total · {cartQtyTotal} unit{cartQtyTotal === 1 ? "" : "s"} · VAT {(FOL_VAT_RATE * 100).toFixed(0)}%
                        </td>
                        <td colSpan={2} className="px-3 py-2.5 text-right text-sm tabular-nums font-bold">
                          {formatKesMoney(cartPricing.total)}
                        </td>
                      </tr>
                    </tfoot>
                  )}
                </table>
              </div>
            </div>
          </SectionCard>

          <SectionCard number={4} icon={MapPin} title="Issue and site">
            <Label>
              Visit <RequiredMark />
            </Label>
            <Select value={form.request_origin} onValueChange={(v) => setForm({ ...form, request_origin: v })}>
              <SelectTrigger className={cn(requiredFieldErrors.request_origin && "border-red-400 focus-visible:ring-red-400/40 bg-red-50/40 dark:bg-red-950/10")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="sales_consultant_visit">Visit</SelectItem>
                <SelectItem value="customer_call">Phone</SelectItem>
                <SelectItem value="email">Email</SelectItem>
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
          </SectionCard>

          <SectionCard number={5} icon={Paperclip} title="Attachments and install">
            <label className="flex cursor-pointer items-center justify-center rounded-md border border-dashed p-6 text-sm text-muted-foreground hover:bg-muted/40">
              <Upload className="mr-2 h-4 w-4" />
              Upload PDF, Excel, CSV, JPG, or PNG — you can upload multiple files
              <input type="file" multiple className="hidden" onChange={(e) => setFiles(Array.from(e.target.files ?? []))} />
            </label>
            {existingAttachments.length > 0 && (
              <div className="space-y-1.5">
                <p className="text-xs font-medium text-muted-foreground">Saved on draft</p>
                <div className="flex flex-wrap gap-1.5">
                  {existingAttachments.map((file) => (
                    <Badge key={file.id} variant="secondary" className="gap-1.5 pr-1">
                      <span className="max-w-[180px] truncate">{file.original_name}</span>
                      <button
                        type="button"
                        className="rounded p-0.5 hover:bg-muted"
                        onClick={() => void removeExistingAttachment(file.id)}
                        disabled={busy}
                        aria-label={`Remove ${file.original_name}`}
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </Badge>
                  ))}
                </div>
              </div>
            )}
            {files.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {files.map((file, idx) => (
                  <Badge key={file.name} variant="outline" className="gap-1.5 pr-1">
                    <span className="max-w-[180px] truncate">{file.name}</span>
                    <button
                      type="button"
                      className="rounded p-0.5 hover:bg-muted"
                      onClick={() => setFiles((prev) => prev.filter((_, i) => i !== idx))}
                      aria-label={`Remove ${file.name}`}
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </Badge>
                ))}
              </div>
            )}
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
          </SectionCard>

          <SectionCard
            number={6}
            icon={Clock}
            title="Evidence & consumables"
            description={
              <>
                <strong>Previous FOL issued</strong> comes from approved OrderWatch FOL requests.
                <strong> SO-backed products</strong> come from sales orders with FOL-eligible SKUs
                (last {metricCards?.lookback_months ?? 3} months).
              </>
            }
          >
            {/* Previous FOL purchases */}
            {form.customer_acumatica_id && (
              <div className="space-y-2 rounded-md border border-violet-200/80 bg-violet-50/40 p-3 dark:border-violet-900 dark:bg-violet-950/20">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="text-xs font-semibold text-violet-950 dark:text-violet-100">Previous FOL purchased / issued</p>
                    <p className="text-[11px] text-muted-foreground">
                      Lifetime approved FOLs for this account (ready for invoicing, SO-linked, invoiced, fulfilled).
                    </p>
                  </div>
                  {priorFol && priorFol.request_count > 0 && priorFol.last_public_ref && (
                    <Badge variant="outline" className="border-violet-300 bg-white font-mono text-[11px] text-violet-900">
                      Last {priorFol.last_public_ref}
                    </Badge>
                  )}
                </div>

                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                  <Metric
                    label="Last FOL issue"
                    value={
                      metrics.isLoading && !priorFol
                        ? "…"
                        : priorFol?.last_issue_date
                          ? `${priorFol.last_issue_date}${priorFol.last_public_ref ? ` · ${priorFol.last_public_ref}` : ""}`
                          : "None yet"
                    }
                    hint="Most recent approved FOL decision date for this customer."
                  />
                  <Metric
                    label="Total FOL qty issued"
                    value={
                      metrics.isLoading && !priorFol
                        ? "…"
                        : (priorFol?.total_qty_issued ?? 0).toLocaleString()
                    }
                    hint="Sum of qty on all approved FOL lines for this customer (all time)."
                  />
                  <Metric
                    label="Approved FOL requests"
                    value={
                      metrics.isLoading && !priorFol
                        ? "…"
                        : String(priorFol?.request_count ?? 0)
                    }
                    hint="Count of FOL requests that reached post-approval status."
                  />
                </div>

                {(priorFol?.recent?.length ?? 0) > 0 && (
                  <div className="overflow-hidden rounded-md border bg-white/80 dark:bg-background/60">
                    <div className="border-b bg-muted/30 px-3 py-1.5">
                      <p className="text-[11px] font-medium text-muted-foreground">Recent FOL issues</p>
                    </div>
                    <div className="overflow-x-auto">
                      <table className="w-full text-xs">
                        <thead className="bg-muted/20">
                          <tr>
                            <th className="px-2.5 py-1.5 text-left font-semibold text-muted-foreground">Ref</th>
                            <th className="px-2.5 py-1.5 text-left font-semibold text-muted-foreground">Date</th>
                            <th className="px-2.5 py-1.5 text-right font-semibold text-muted-foreground">Qty</th>
                            <th className="px-2.5 py-1.5 text-left font-semibold text-muted-foreground">Lines</th>
                            <th className="px-2.5 py-1.5 text-left font-semibold text-muted-foreground">Status</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y">
                          {(priorFol?.recent ?? []).map((fol) => (
                            <tr key={fol.id} className="hover:bg-muted/20">
                              <td className="px-2.5 py-1.5 font-mono font-semibold">
                                <Link to="/app/kp/fol/$id" params={{ id: String(fol.id) }} className="text-primary hover:underline">
                                  {fol.public_ref}
                                </Link>
                              </td>
                              <td className="px-2.5 py-1.5 text-muted-foreground">
                                {fol.decided_at ?? fol.submitted_at ?? "—"}
                              </td>
                              <td className="px-2.5 py-1.5 text-right tabular-nums font-medium">
                                {fol.total_qty.toLocaleString()}
                              </td>
                              <td className="max-w-[180px] truncate px-2.5 py-1.5 text-muted-foreground" title={fol.lines.map((l) => `${l.inventory_id}×${l.qty}`).join(", ")}>
                                {fol.lines.slice(0, 3).map((l) => l.inventory_id).join(", ")}
                                {fol.line_count > 3 ? ` +${fol.line_count - 3}` : ""}
                              </td>
                              <td className="px-2.5 py-1.5">
                                <Badge variant="outline" className="text-[10px] capitalize">
                                  {fol.status.replaceAll("_", " ")}
                                </Badge>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}

                {/* By SKU: cart lines first, else top previously issued SKUs */}
                {(() => {
                  const cartIds = form.lines.map((l) => l.inventory_id);
                  const skuMap = priorFol?.by_sku ?? metrics.data?.prior_issued ?? {};
                  const rows =
                    cartIds.length > 0
                      ? cartIds.map((id) => {
                          const row = (skuMap as Record<string, { qty?: number; date?: string | null; public_ref?: string | null; product_description?: string | null }>)[id];
                          return {
                            inventory_id: id,
                            qty: Number(row?.qty ?? 0),
                            date: row?.date ?? null,
                            public_ref: row?.public_ref ?? null,
                            product_description: row?.product_description ?? form.lines.find((l) => l.inventory_id === id)?.product_description ?? null,
                          };
                        })
                      : Object.values(priorFol?.by_sku ?? {}).slice(0, 12);

                  if (rows.length === 0) {
                    return (
                      <p className="text-[11px] text-muted-foreground">
                        {metrics.isLoading
                          ? "Loading previous FOL history…"
                          : "No previous FOL issues for this customer yet."}
                      </p>
                    );
                  }

                  return (
                    <div className="rounded-md border bg-white/80 p-2 dark:bg-background/60">
                      <p className="mb-1 text-[11px] font-medium text-muted-foreground">
                        {cartIds.length > 0
                          ? "Previous FOL issued by cart line"
                          : "Top previously issued FOL SKUs"}
                      </p>
                      <ul className="space-y-1 text-xs text-muted-foreground">
                        {rows.map((row) => (
                          <li key={row.inventory_id} className="flex flex-wrap justify-between gap-2">
                            <span className="font-mono text-foreground">
                              {row.inventory_id}
                              {row.product_description ? (
                                <span className="ml-1 font-sans text-[11px] text-muted-foreground">
                                  · {row.product_description}
                                </span>
                              ) : null}
                            </span>
                            <span>
                              {row.qty > 0
                                ? `qty ${Number(row.qty).toLocaleString()}${row.date ? ` · ${row.date}` : ""}${row.public_ref ? ` · ${row.public_ref}` : ""}`
                                : "never issued"}
                            </span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  );
                })()}
              </div>
            )}

            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
              <Metric
                label="Last purchase (FOL SKUs)"
                value={
                  metricCards?.last_purchase_date
                    ? `${metricCards.last_purchase_date}${metricCards.last_purchase_order_nbr ? ` · ${metricCards.last_purchase_order_nbr}` : ""}`
                    : "—"
                }
                hint="Most recent SO date among FOL-eligible lines for this customer."
              />
              <Metric
                label="Consumable sales 3M"
                value={`KES ${(metricCards?.sales_3m_kes ?? 0).toLocaleString()}`}
                hint="Ordered value for the selected supporting consumables in the previous three months."
              />
              <Metric
                label="Consumable volume 3M"
                value={(metricCards?.volume_3m ?? 0).toLocaleString()}
                hint="Ordered quantity for the selected supporting consumables in the previous three months."
              />
              <Metric
                label={`Total last purchased ${metricCards?.lookback_months ?? 3}M (value)`}
                value={`KES ${(metricCards?.sales_6m_kes ?? 0).toLocaleString()}`}
                hint={`${metricCards?.lookback_months ?? 3} months of ordered value (order_qty × unit_price) for FOL-eligible SKUs only — not the full invoice book.`}
              />
              <Metric
                label={`Total last purchased ${metricCards?.lookback_months ?? 3}M (volume)`}
                value={(metricCards?.volume_6m ?? 0).toLocaleString()}
                hint={`${metricCards?.lookback_months ?? 3} months of ordered quantity for FOL-eligible SKUs only.`}
              />
            </div>
            {(form.consumable_inventory_ids ?? []).length > 0 && metricCards?.line_last_purchases && (
              <div className="rounded-md border bg-muted/20 p-2">
                <p className="mb-1 text-xs font-medium">SO-backed evidence by supporting consumable</p>
                <ul className="space-y-1 text-xs text-muted-foreground">
                  {(form.consumable_inventory_ids ?? []).map((inventoryId) => {
                    const lp = metricCards.line_last_purchases?.[inventoryId];
                    return (
                      <li key={inventoryId} className="flex flex-wrap justify-between gap-2">
                        <span className="font-mono text-foreground">{inventoryId}</span>
                        <span>
                          {lp?.date ?? "no SO in window"}
                          {lp?.order_nbr ? ` · ${lp.order_nbr}` : ""}
                          {lp ? ` · qty ${lp.qty.toLocaleString()}` : ""}
                        </span>
                      </li>
                    );
                  })}
                </ul>
              </div>
            )}
            <Label>Metrics source</Label>
            <Select value={form.consumables_metrics_source} onValueChange={(v) => setForm({ ...form, consumables_metrics_source: v as "system_so" | "manual_override" })}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="system_so">Use Acumatica SO figures for selected consumables</SelectItem>
                <SelectItem value="manual_override">Manual override</SelectItem>
              </SelectContent>
            </Select>
            {form.consumables_metrics_source === "manual_override" && (
              <Textarea placeholder="Reason for overriding SO figures" value={form.consumables_override_reason ?? ""} onChange={(e) => setForm({ ...form, consumables_override_reason: e.target.value })} />
            )}
            <Textarea placeholder="Debt explanation" value={form.debt_explanation} onChange={(e) => setForm({ ...form, debt_explanation: e.target.value })} />
          </SectionCard>
        </div>

        {/* Right column — 3M snapshot + top products, sticky while the left form scrolls */}
        <div className="space-y-4 lg:sticky lg:top-4">
          <div className="rounded-lg border bg-card p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between gap-2">
              <h2 className="text-sm font-semibold">3M customer snapshot</h2>
              <Badge variant="outline" className="text-[10px]">Last 3 months</Badge>
            </div>
            {!form.customer_acumatica_id ? (
              <p className="text-xs text-muted-foreground">Select a KP account to see its snapshot.</p>
            ) : (
              <div className="space-y-2.5">
                <SnapshotTile
                  icon={DollarSign}
                  label="3M revenue"
                  value={metrics.isLoading && !metricCards?.customer_6m ? "…" : formatKesCompact(metricCards?.customer_6m?.revenue_total ?? 0)}
                  hint={metricCards?.customer_6m ? `From ${metricCards.customer_6m.order_count} orders` : undefined}
                />
                <SnapshotTile
                  icon={TrendingUp}
                  label="Order frequency"
                  value={metricCards?.customer_6m?.frequency_label ?? (metrics.isLoading ? "…" : "—")}
                  hint={
                    metricCards?.customer_6m?.avg_days_between_orders != null && metricCards.customer_6m.order_count > 1
                      ? `Avg ${metricCards.customer_6m.avg_days_between_orders}d between SOs`
                      : undefined
                  }
                />
                <SnapshotTile
                  icon={Wallet}
                  label="Average order value"
                  value={metrics.isLoading && !metricCards?.customer_6m ? "…" : formatKesCompact(metricCards?.customer_6m?.aov ?? 0)}
                />
                <SnapshotTile
                  icon={ClipboardList}
                  label="Number of orders"
                  value={metricCards?.customer_6m ? String(metricCards.customer_6m.order_count) : metrics.isLoading ? "…" : "—"}
                  hint={metricCards?.customer_6m ? `${metricCards.customer_6m.orders_per_month}/mo` : undefined}
                />
                <SnapshotTile
                  icon={FileText}
                  label="Latest order"
                  value={latestOrder?.order_nbr ?? (metrics.isLoading ? "…" : "—")}
                  hint={latestOrder ? `${latestOrder.order_date ?? ""} · KES ${Number(latestOrder.order_total ?? 0).toLocaleString()}` : undefined}
                />
              </div>
            )}
          </div>

          {form.customer_acumatica_id && topProducts.rows.length > 0 && (
            <div className="rounded-lg border bg-card p-4 shadow-sm">
              <div className="mb-1 flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">Top SO-backed products (3M)</h2>
              </div>
              <p className="mb-3 text-[11px] text-muted-foreground">By revenue</p>
              <ul className="space-y-2">
                {(topProductsExpanded ? topProducts.rows.slice(0, 12) : topProducts.rows.slice(0, 3)).map((row, idx) => (
                  <li key={row.inventory_id} className="flex items-start gap-2.5">
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                      {idx + 1}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-xs font-medium">
                        {row.product_description || row.inventory_id}
                      </p>
                      <p className="text-[11px] text-muted-foreground">
                        {formatKesCompact(row.value_3m)}
                        {topProducts.total > 0 ? ` (${((row.value_3m / topProducts.total) * 100).toFixed(1)}%)` : ""}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
              {topProducts.rows.length > 3 && (
                <button
                  type="button"
                  className="mt-3 text-xs font-medium text-primary hover:underline"
                  onClick={() => setTopProductsExpanded((v) => !v)}
                >
                  {topProductsExpanded ? "Show less" : "View all products"}
                </button>
              )}
            </div>
          )}

          <div className="flex items-start gap-2.5 rounded-lg border bg-muted/30 p-4">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <p className="text-xs text-muted-foreground">
              All metrics are based on SO-backed orders from the last three months.
            </p>
          </div>
        </div>
      </div>

      {/* Sticky bottom action bar */}
      <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <p className="text-xs text-muted-foreground">Your changes are saved automatically</p>
          <div className="flex gap-2">
            <Button variant="outline" onClick={saveDraft} disabled={busy}>
              <Save className="mr-1 h-4 w-4" /> {isEditMode ? "Save changes" : "Save Draft"}
            </Button>
            <Button onClick={submit} disabled={busy || !canSubmit}>
              <Send className="mr-1 h-4 w-4" /> Submit
            </Button>
          </div>
        </div>
      </div>

      {form.customer_acumatica_id && (
        <FolOrderHistoryDrawer
          customerId={form.customer_acumatica_id}
          open={historyDrawerOpen}
          onOpenChange={setHistoryDrawerOpen}
        />
      )}
    </div>
  );
}

/** Compact KES for one-line stats: 400k, 1.2M, 10k */
function formatKesCompact(amount: number): string {
  const n = Number(amount) || 0;
  const abs = Math.abs(n);
  if (abs >= 1_000_000) {
    const m = n / 1_000_000;
    return `KES ${m % 1 === 0 ? m.toFixed(0) : m.toFixed(1)}M`;
  }
  if (abs >= 1_000) {
    const k = n / 1_000;
    return `KES ${k % 1 === 0 ? k.toFixed(0) : k.toFixed(1)}k`;
  }
  return `KES ${n.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function formatKesMoney(amount: number): string {
  return `KES ${(Number(amount) || 0).toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })}`;
}

function RequiredMark() {
  return <span className="text-red-500">*</span>;
}

function Field({
  label,
  value,
  onChange,
  required,
  error,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
  error?: boolean;
}) {
  return (
    <div>
      <Label>
        {label} {required && <RequiredMark />}
      </Label>
      <Input
        className={cn("mt-1", error && "border-red-400 focus-visible:ring-red-400/40 bg-red-50/40 dark:bg-red-950/10")}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}

function Metric({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <div className="rounded-md border bg-muted/30 p-2" title={hint}>
      <div className="text-[10px] uppercase text-muted-foreground">{label}</div>
      <div className="mt-1 text-sm font-semibold tabular-nums">{value}</div>
      {hint && <p className="mt-1 text-[10px] leading-snug text-muted-foreground">{hint}</p>}
    </div>
  );
}

function SnapshotTile({
  icon: Icon,
  label,
  value,
  hint,
}: {
  icon: LucideIcon;
  label: string;
  value: string;
  hint?: string;
}) {
  return (
    <div className="flex items-start gap-2.5">
      <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
        <Icon className="h-4 w-4" />
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-[11px] text-muted-foreground">{label}</p>
        <p className="text-sm font-semibold tabular-nums">{value}</p>
        {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
      </div>
    </div>
  );
}

function SectionCard({
  number,
  icon: Icon,
  title,
  description,
  action,
  children,
}: {
  number: number;
  icon: LucideIcon;
  title: string;
  description?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="space-y-4 rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="flex items-start gap-2.5">
          <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
            <Icon className="h-4 w-4" />
          </div>
          <div>
            <h2 className="text-sm font-semibold">{number}. {title}</h2>
            {description && <p className="text-xs text-muted-foreground">{description}</p>}
          </div>
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}

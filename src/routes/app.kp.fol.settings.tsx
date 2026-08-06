import { Link, createFileRoute, redirect } from "@tanstack/react-router";
import { useEffect, useState, type ComponentType, type ReactNode } from "react";
import { ArrowLeft, Mail, Package, ShieldCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { getToken, useAuth } from "@/lib/auth";
import {
  useBulkUploadFolProducts,
  useFolProducts,
  useFolSettings,
  useUpdateFolProduct,
  useUpdateFolSettings,
  useUpdateFolStages,
  type FolApprovalStageConfig,
  type FolSettings,
} from "@/hooks/admin/useAdminSettings";

export const Route = createFileRoute("/app/kp/fol/settings")({
  head: () => ({ meta: [{ title: "FOL Settings - Kim-Fay Sight" }] }),
  beforeLoad: () => {
    // Client-only guard; SSR keeps the route shell.
    if (typeof window === "undefined") return;
    const token = getToken();
    if (!token) {
      throw redirect({ to: "/auth" });
    }
  },
  component: FolSettingsPage,
});

/** FOL product category options (shown on eligible SKUs). */
const FOL_CATEGORY_OPTIONS = [
  { value: "fol_item", label: "FOL item (free equipment)" },
  { value: "consumable", label: "FOL consumable (sales evidence)" },
  { value: "both", label: "Both (choose purpose per request)" },
] as const;

function FolSettingsPage() {
  const { session } = useAuth();
  const isAdmin = session?.role === "Administrator";

  if (session && !isAdmin) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg border bg-card p-6 text-sm text-muted-foreground">
          Only Administrators can manage FOL settings. Contact an admin if you need stages, mail, or eligible products updated.
        </div>
        <Button asChild variant="outline" size="sm">
          <Link to="/app/kp/fol">
            <ArrowLeft className="mr-1 h-3.5 w-3.5" /> Back to KP FOL
          </Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">KP CRM</p>
          <h1 className="text-xl font-semibold tracking-tight">FOL Settings</h1>
          <p className="text-sm text-muted-foreground">
            Approval stages, mail, attachments, and FOL-eligible products for KP Free On Loan.
          </p>
        </div>
        <Button asChild variant="outline" size="sm">
          <Link to="/app/kp/fol">
            <ArrowLeft className="mr-1 h-3.5 w-3.5" /> Back to KP FOL
          </Link>
        </Button>
      </div>

      <FolSettingsPanel />
    </div>
  );
}

function FolProductsPanel() {
  const [q, setQ] = useState("");
  const [eligibleOnly, setEligibleOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [quickSku, setQuickSku] = useState("");
  const [quickCategory, setQuickCategory] = useState<string>("fol_item");
  const products = useFolProducts({ q, eligible_only: eligibleOnly, page });
  const updateProduct = useUpdateFolProduct();
  const bulkUpload = useBulkUploadFolProducts();

  return (
    <Panel title="FOL Products (eligible SKUs)" icon={Package}>
      <p className="mb-3 text-sm text-muted-foreground">
        Only inventory marked <strong>FOL eligible</strong> appears in &quot;Search FOL-eligible SKU&quot; on new FOL requests.
        Example: mark <code className="text-xs">DISPE0136</code> eligible so consultants can add it as a FOL line.
        Classify each SKU as free FOL equipment, a supporting consumable, or both. SKUs must already exist in Acumatica inventory sync.
      </p>

      <div className="mb-4 grid gap-3 rounded-md border bg-muted/20 p-3 sm:grid-cols-2">
        <div>
          <Label className="text-xs">Quick enable / disable SKU</Label>
          <div className="mt-1 flex flex-wrap gap-2">
            <Input
              className="h-9 min-w-[8rem] flex-1 font-mono"
              placeholder="e.g. DISPE0136"
              value={quickSku}
              onChange={(e) => setQuickSku(e.target.value.toUpperCase())}
            />
            <Select value={quickCategory} onValueChange={setQuickCategory}>
              <SelectTrigger className="h-9 w-[11rem]">
                <SelectValue placeholder="Category" />
              </SelectTrigger>
              <SelectContent>
                {FOL_CATEGORY_OPTIONS.map((opt) => (
                  <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              size="sm"
              className="h-9 shrink-0"
              disabled={!quickSku.trim() || updateProduct.isPending}
              onClick={() =>
                updateProduct.mutate({
                  inventoryId: quickSku.trim(),
                  is_fol_eligible: true,
                  fol_category: quickCategory,
                })
              }
            >
              Make FOL
            </Button>
            <Button
              size="sm"
              variant="outline"
              className="h-9 shrink-0"
              disabled={!quickSku.trim() || updateProduct.isPending}
              onClick={() =>
                updateProduct.mutate({
                  inventoryId: quickSku.trim(),
                  is_fol_eligible: false,
                })
              }
            >
              Remove
            </Button>
          </div>
        </div>
        <div>
          <Label className="text-xs">Bulk upload CSV</Label>
          <p className="mb-1 text-[11px] text-muted-foreground">
            Columns: <code>inventory_id</code>, optional <code>is_fol_eligible</code> (1/0), optional <code>fol_category</code>
            {" "}(use <code>fol_item</code>, <code>consumable</code>, or <code>both</code>)
          </p>
          <Input
            type="file"
            accept=".csv,text/csv"
            className="h-9 cursor-pointer text-xs"
            disabled={bulkUpload.isPending}
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) bulkUpload.mutate(file);
              e.target.value = "";
            }}
          />
        </div>
      </div>

      <div className="mb-2 flex flex-wrap items-center gap-2">
        <Input
          className="h-8 max-w-xs"
          placeholder="Search inventory ID or description…"
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setPage(1);
          }}
        />
        <label className="flex items-center gap-1.5 text-xs">
          <Switch checked={eligibleOnly} onCheckedChange={(v) => { setEligibleOnly(v); setPage(1); }} />
          FOL eligible only
        </label>
      </div>

      {products.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <thead className="bg-muted/40 text-left">
              <tr>
                <th className="px-3 py-2 font-medium">SKU</th>
                <th className="px-3 py-2 font-medium">Description</th>
                <th className="px-3 py-2 font-medium">Category</th>
                <th className="px-3 py-2 font-medium">FOL eligible</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {(products.data?.data ?? []).length === 0 && (
                <tr>
                  <td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">
                    No inventory rows. Sync inventory first, then mark SKUs as FOL eligible.
                  </td>
                </tr>
              )}
              {(products.data?.data ?? []).map((row) => (
                <tr key={row.inventory_id}>
                  <td className="px-3 py-2 font-mono text-xs">{row.inventory_id}</td>
                  <td className="px-3 py-2">{row.description ?? "—"}</td>
                  <td className="px-3 py-2">
                    <Select
                      value={["fol_item", "consumable", "both"].includes(row.fol_category || "") ? row.fol_category! : "fol_item"}
                      onValueChange={(cat) =>
                        updateProduct.mutate({
                          inventoryId: row.inventory_id,
                          is_fol_eligible: row.is_fol_eligible,
                          fol_category: cat,
                        })
                      }
                      disabled={updateProduct.isPending}
                    >
                      <SelectTrigger className="h-8 w-[10.5rem] text-xs">
                        <SelectValue placeholder="Category" />
                      </SelectTrigger>
                      <SelectContent>
                        {FOL_CATEGORY_OPTIONS.map((opt) => (
                          <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </td>
                  <td className="px-3 py-2">
                    <Switch
                      checked={!!row.is_fol_eligible}
                      disabled={updateProduct.isPending}
                      onCheckedChange={(v) =>
                        updateProduct.mutate({
                          inventoryId: row.inventory_id,
                          is_fol_eligible: v,
                          fol_category: row.fol_category || quickCategory,
                        })
                      }
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {products.data && products.data.last_page > 1 && (
        <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
          <span>{products.data.total} items</span>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
            <span>Page {products.data.current_page} of {products.data.last_page}</span>
            <Button size="sm" variant="outline" disabled={page >= products.data.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
          </div>
        </div>
      )}
    </Panel>
  );
}

function FolSettingsPanel() {
  const { data, isLoading, refetch } = useFolSettings();
  const saveSettings = useUpdateFolSettings();
  const saveStages = useUpdateFolStages();
  const [settings, setSettings] = useState<Partial<FolSettings>>({});
  const [stages, setStages] = useState<FolApprovalStageConfig[]>([]);
  const [mimesText, setMimesText] = useState("");
  const [ccText, setCcText] = useState("");

  useEffect(() => {
    if (!data) return;
    setSettings({
      mail_from_address: data.mail_from_address,
      mail_from_name: data.mail_from_name,
      max_attachment_kb: data.max_attachment_kb,
      attachment_mimes: data.attachment_mimes,
      invoicing_roles: data.invoicing_roles,
      cc_watcher_emails: data.cc_watcher_emails,
      duplicate_policy: data.duplicate_policy,
      consumables_months: data.consumables_months,
      require_attachment: data.require_attachment,
      allow_admin_on_all_stages: data.allow_admin_on_all_stages,
    });
    setStages(data.stages.map((s) => ({ ...s, role_names: [...s.role_names], user_ids: [...s.user_ids] })));
    setMimesText(data.attachment_mimes.join(", "));
    setCcText((data.cc_watcher_emails ?? []).join(", "));
  }, [data]);

  function updateStage(index: number, patch: Partial<FolApprovalStageConfig>) {
    setStages((prev) => prev.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  }

  function addStage() {
    const n = stages.length + 1;
    setStages((prev) => [
      ...prev,
      {
        key: `stage_${n}`,
        name: `Stage ${n}`,
        sort_order: n,
        is_active: true,
        assignee_mode: "role",
        role_names: ["Administrator"],
        user_ids: [],
        require_comment: true,
        sla_hours: 48,
      },
    ]);
  }

  function removeStage(index: number) {
    setStages((prev) => prev.filter((_, i) => i !== index).map((s, i) => ({ ...s, sort_order: i + 1 })));
  }

  function toggleRole(index: number, role: string) {
    setStages((prev) =>
      prev.map((s, i) => {
        if (i !== index) return s;
        const has = s.role_names.includes(role);
        return {
          ...s,
          role_names: has ? s.role_names.filter((r) => r !== role) : [...s.role_names, role],
        };
      }),
    );
  }

  function toggleInvoicingRole(role: string) {
    setSettings((prev) => {
      const list = prev.invoicing_roles ?? [];
      const has = list.includes(role);
      return {
        ...prev,
        invoicing_roles: has ? list.filter((r) => r !== role) : [...list, role],
      };
    });
  }

  if (isLoading || !data) return <PanelSkeleton />;

  const roles = data.available_roles ?? [];

  return (
    <div className="space-y-4">
      <Panel title="FOL Mail & Attachments" icon={Mail}>
        <p className="mb-4 text-sm text-muted-foreground">
          Runtime FOL config is stored in the database. Change stages and recipients here without redeploying code.
          Defaults fall back to <code className="text-xs">config/fol.php</code> / env when unset.
          Stage approval emails go to <strong>attached stage users only</strong> (not everyone in a role), plus admin CC emails below.
        </p>
        {data.mail_testing_mode && (
          <div className="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
            <strong>Testing mode on:</strong> all FOL workflow emails are redirected to{" "}
            <code className="text-xs">
              {data.mail_testing_recipient ?? "commercialtechlead@kimfay.com"}
            </code>{" "}
            only. Set <code className="text-xs">FOL_MAIL_TESTING_MODE=false</code> on the server when ready for production.
          </div>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label="Mail from address"
            value={settings.mail_from_address ?? ""}
            onChange={(v) => setSettings((s) => ({ ...s, mail_from_address: v }))}
            placeholder="kp@fayshop.co.ke"
          />
          <Field
            label="Mail from name"
            value={settings.mail_from_name ?? ""}
            onChange={(v) => setSettings((s) => ({ ...s, mail_from_name: v }))}
            placeholder="FOL KP Approvals"
          />
          <Field
            label="Max attachment KB"
            type="number"
            value={String(settings.max_attachment_kb ?? 15360)}
            onChange={(v) => setSettings((s) => ({ ...s, max_attachment_kb: Number(v) || 15360 }))}
          />
          <Field
            label="Consumables lookback (months)"
            type="number"
            value={String(settings.consumables_months ?? 6)}
            onChange={(v) => setSettings((s) => ({ ...s, consumables_months: Number(v) || 6 }))}
          />
          <div className="sm:col-span-2">
            <Field
              label="Allowed attachment extensions (comma-separated)"
              value={mimesText}
              onChange={setMimesText}
              placeholder="pdf, xlsx, jpg, png"
            />
          </div>
          <div className="sm:col-span-2">
            <Field
              label="Admin CC emails (always receive every FOL step; add extras here)"
              value={ccText}
              onChange={setCcText}
              placeholder="commercialtechlead@kimfay.com"
            />
            <p className="mt-1 text-xs text-muted-foreground">
              Not a role blast — only these addresses plus users you attach on each stage.
            </p>
          </div>
          <div>
            <Label className="text-xs">Duplicate open FOL policy</Label>
            <Select
              value={settings.duplicate_policy ?? "warn"}
              onValueChange={(v) => setSettings((s) => ({ ...s, duplicate_policy: v as FolSettings["duplicate_policy"] }))}
            >
              <SelectTrigger className="mt-1 h-9"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="warn">Warn</SelectItem>
                <SelectItem value="block">Block</SelectItem>
                <SelectItem value="allow">Allow</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-col justify-end gap-2 pb-1">
            <label className="flex items-center gap-2 text-sm">
              <Switch
                checked={!!settings.require_attachment}
                onCheckedChange={(v) => setSettings((s) => ({ ...s, require_attachment: v }))}
              />
              Require attachment on submit
            </label>
            <label className="flex items-center gap-2 text-sm">
              <Switch
                checked={!!settings.allow_admin_on_all_stages}
                onCheckedChange={(v) => setSettings((s) => ({ ...s, allow_admin_on_all_stages: v }))}
              />
              Admin can approve any stage (testing / break-glass)
            </label>
          </div>
        </div>

        <div className="mt-4">
          <Label className="text-xs">Invoicing roles (capability / future use)</Label>
          <p className="mt-1 text-xs text-muted-foreground">
            N5 invoicing emails currently go to Admin CC emails above only (not every user in these roles).
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            {roles.map((role) => {
              const on = (settings.invoicing_roles ?? []).includes(role);
              return (
                <button
                  key={role}
                  type="button"
                  onClick={() => toggleInvoicingRole(role)}
                  className={`rounded-full border px-2.5 py-1 text-xs ${on ? "border-primary bg-primary text-primary-foreground" : "bg-background"}`}
                >
                  {role}
                </button>
              );
            })}
          </div>
        </div>

        <div className="mt-4 flex gap-2">
          <Button
            size="sm"
            disabled={saveSettings.isPending}
            onClick={() =>
              saveSettings.mutate({
                ...settings,
                attachment_mimes: mimesText.split(/[,\s]+/).map((s) => s.trim().toLowerCase()).filter(Boolean),
                cc_watcher_emails: ccText.split(/[,\s]+/).map((s) => s.trim().toLowerCase()).filter(Boolean),
              })
            }
          >
            {saveSettings.isPending ? "Saving…" : "Save FOL settings"}
          </Button>
          <Button size="sm" variant="outline" onClick={() => refetch()}>Reset</Button>
        </div>
      </Panel>

      <FolProductsPanel />

      <Panel title="FOL Approval Stages" icon={ShieldCheck}>
        <p className="mb-4 text-sm text-muted-foreground">
          Dynamic multi-step chain (e.g. HOD → CCO). Order by sort. Each active stage needs roles and/or specific users.
          Administrators can always act on every stage when the toggle above is on.
        </p>
        <div className="space-y-3">
          {stages.map((stage, index) => (
            <div key={`${stage.key}-${index}`} className="rounded-lg border bg-muted/20 p-4">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-sm font-semibold">Stage {stage.sort_order}: {stage.name}</h4>
                <div className="flex items-center gap-2">
                  <label className="flex items-center gap-1.5 text-xs">
                    <Switch checked={stage.is_active} onCheckedChange={(v) => updateStage(index, { is_active: v })} />
                    Active
                  </label>
                  <Button size="sm" variant="ghost" className="h-7 text-destructive" onClick={() => removeStage(index)} disabled={stages.length <= 1}>
                    Remove
                  </Button>
                </div>
              </div>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Field label="Key" value={stage.key} onChange={(v) => updateStage(index, { key: v.replace(/\s+/g, "_").toLowerCase() })} />
                <Field label="Display name" value={stage.name} onChange={(v) => updateStage(index, { name: v })} />
                <Field label="Sort order" type="number" value={String(stage.sort_order)} onChange={(v) => updateStage(index, { sort_order: Number(v) || 1 })} />
                <Field
                  label="SLA hours"
                  type="number"
                  value={stage.sla_hours != null ? String(stage.sla_hours) : ""}
                  onChange={(v) => updateStage(index, { sla_hours: v === "" ? null : Number(v) || null })}
                />
              </div>
              <div className="mt-3">
                <Label className="text-xs">Assignee roles</Label>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                  {roles.map((role) => {
                    const on = stage.role_names.includes(role);
                    return (
                      <button
                        key={role}
                        type="button"
                        onClick={() => toggleRole(index, role)}
                        className={`rounded-full border px-2 py-0.5 text-[11px] ${on ? "border-primary bg-primary text-primary-foreground" : "bg-background"}`}
                      >
                        {role}
                      </button>
                    );
                  })}
                </div>
              </div>
              <div className="mt-3">
                <Label className="text-xs">Specific users (optional)</Label>
                <Select
                  value="__add__"
                  onValueChange={(v) => {
                    if (v === "__add__") return;
                    const id = Number(v);
                    if (!stage.user_ids.includes(id)) {
                      updateStage(index, { user_ids: [...stage.user_ids, id] });
                    }
                  }}
                >
                  <SelectTrigger className="mt-1 h-9"><SelectValue placeholder="Add user…" /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__add__">Add user…</SelectItem>
                    {(data.users ?? []).map((u) => (
                      <SelectItem key={u.id} value={String(u.id)}>{u.name} ({u.email})</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <div className="mt-1.5 flex flex-wrap gap-1">
                  {stage.user_ids.map((id) => {
                    const u = data.users?.find((x) => x.id === id);
                    return (
                      <Badge key={id} variant="secondary" className="gap-1 text-[10px]">
                        {u?.name ?? id}
                        <button type="button" className="ml-0.5" onClick={() => updateStage(index, { user_ids: stage.user_ids.filter((x) => x !== id) })}>×</button>
                      </Badge>
                    );
                  })}
                </div>
              </div>
              <label className="mt-3 flex items-center gap-2 text-xs">
                <Switch checked={stage.require_comment} onCheckedChange={(v) => updateStage(index, { require_comment: v })} />
                Require comment on decision
              </label>
            </div>
          ))}
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={addStage}>Add stage</Button>
          <Button size="sm" disabled={saveStages.isPending} onClick={() => saveStages.mutate(stages)}>
            {saveStages.isPending ? "Saving…" : "Save approval stages"}
          </Button>
        </div>
      </Panel>
    </div>
  );
}

function Panel({ title, icon: Icon, children }: { title: string; icon: ComponentType<{ className?: string }>; children: ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold"><Icon className="h-4 w-4" />{title}</h3>
      {children}
    </div>
  );
}

function Field({ label, value, onChange, placeholder, type = "text" }: { label: string; value: string; onChange: (value: string) => void; placeholder?: string; type?: string }) {
  return (
    <div className="grid gap-1.5">
      <Label>{label}</Label>
      <Input type={type} value={value} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} />
    </div>
  );
}

function PanelSkeleton() {
  return (
    <div className="space-y-3 rounded-lg border bg-card p-4">
      <Skeleton className="h-5 w-48" />
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-full" />
      <Skeleton className="h-10 w-2/3" />
    </div>
  );
}

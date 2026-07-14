import { useMemo, useState, type Dispatch, type SetStateAction } from "react";
import { Check, ChevronsUpDown, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import { Label } from "@/components/ui/label";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { cn } from "@/lib/utils";
import type { DataScopeMode, Department, OrgLevel, ProductTypeScope, SectorScope, TeamMember } from "@/types/admin";

export interface OrgFormState {
  department_id: string;
  department_ids: string[];
  department_role: string;
  org_level: OrgLevel;
  reports_to_user_id: string;
  product_type_scope: ProductTypeScope;
  data_scope_mode: DataScopeMode;
  sector_scopes: SectorScope[];
}

export const defaultOrgFormState = (): OrgFormState => ({
  department_id: "",
  department_ids: [],
  department_role: "member",
  org_level: "sales",
  reports_to_user_id: "",
  product_type_scope: "both",
  data_scope_mode: "scoped",
  sector_scopes: [],
});

const ORG_LEVELS: { value: OrgLevel; label: string }[] = [
  { value: "executive", label: "Executive" },
  { value: "c_suite", label: "C-Suite" },
  { value: "hod", label: "Head of Department" },
  { value: "sales", label: "Sales / Consultant" },
  { value: "brandsops", label: "Brand Operations" },
  { value: "operations", label: "Operations (org-wide)" },
  { value: "gap", label: "Gap / unconfigured" },
];

const SECTORS: SectorScope[] = ["GT", "MT", "KP", "ALL"];

/** Build reportee subtree ids under root (for cycle-safe manager filtering). */
export function reportingDescendantIds(
  members: Array<Pick<TeamMember, "id" | "reports_to_user_id">>,
  rootId: number,
): number[] {
  const childrenByManager = new Map<number, number[]>();
  for (const m of members) {
    if (m.reports_to_user_id == null) continue;
    const list = childrenByManager.get(m.reports_to_user_id) ?? [];
    list.push(m.id);
    childrenByManager.set(m.reports_to_user_id, list);
  }

  const out: number[] = [];
  const queue = [...(childrenByManager.get(rootId) ?? [])];
  const seen = new Set<number>();

  while (queue.length > 0) {
    const id = queue.shift()!;
    if (seen.has(id)) continue;
    seen.add(id);
    out.push(id);
    for (const child of childrenByManager.get(id) ?? []) {
      queue.push(child);
    }
  }

  return out;
}

/**
 * Dynamic reports-to candidates: any user (active or inactive) except self + reportee subtree.
 * No role / org-level shortlist — hierarchy is free-form. Inactive managers remain selectable.
 */
export function eligibleReportManagers(
  reportOptions: TeamMember[],
  excludeUserId?: number,
): TeamMember[] {
  const blocked = new Set<number>();
  if (excludeUserId != null) {
    blocked.add(excludeUserId);
    for (const id of reportingDescendantIds(reportOptions, excludeUserId)) {
      blocked.add(id);
    }
  }

  return reportOptions
    .filter((m) => !blocked.has(m.id))
    .slice()
    .sort((a, b) => {
      // Active first, then name
      const aActive = a.is_active === false ? 1 : 0;
      const bActive = b.is_active === false ? 1 : 0;
      if (aActive !== bActive) return aActive - bActive;
      return a.name.localeCompare(b.name) || a.email.localeCompare(b.email);
    });
}

export function orgPayloadFromForm(form: OrgFormState) {
  const departmentId = form.department_id ? Number(form.department_id) : null;
  const departmentIds = form.department_ids.map(Number).filter(Boolean);
  const mergedIds = departmentId && !departmentIds.includes(departmentId)
    ? [...departmentIds, departmentId]
    : departmentIds.length > 0 ? departmentIds : departmentId ? [departmentId] : [];

  return {
    department_id: departmentId,
    department_ids: mergedIds.length > 0 ? mergedIds : undefined,
    department_role: form.department_role,
    org_level: form.org_level,
    reports_to_user_id: form.reports_to_user_id ? Number(form.reports_to_user_id) : null,
    product_type_scope: form.product_type_scope,
    data_scope_mode:
      form.org_level === "operations" || form.org_level === "executive" || form.org_level === "c_suite"
        ? "org_wide"
        : form.org_level === "gap"
          ? "deny_all"
          : form.data_scope_mode,
    sector_scopes: form.sector_scopes.length > 0 ? form.sector_scopes : undefined,
  };
}

export function orgFormFromMember(member: TeamMember): OrgFormState {
  const departmentIds = (member.department_ids ?? member.departments?.map((d) => d.id) ?? []).map(String);
  return {
    department_id: member.department_id ? String(member.department_id) : "",
    department_ids: departmentIds,
    department_role: member.department_role ?? "member",
    org_level: (member.org_level ?? "sales") as OrgLevel,
    reports_to_user_id: member.reports_to_user_id ? String(member.reports_to_user_id) : "",
    product_type_scope: (member.product_type_scope ?? "both") as ProductTypeScope,
    data_scope_mode: (member.data_scope_mode ?? "scoped") as DataScopeMode,
    sector_scopes: (member.sector_scopes ?? []) as SectorScope[],
  };
}

function toggleSector(current: SectorScope[], sector: SectorScope): SectorScope[] {
  return current.includes(sector) ? current.filter((s) => s !== sector) : [...current, sector];
}

function toggleDepartment(current: string[], id: string): string[] {
  return current.includes(id) ? current.filter((d) => d !== id) : [...current, id];
}

function ReportsToPicker({
  value,
  onChange,
  managers,
  currentLabel,
}: {
  value: string;
  onChange: (id: string) => void;
  managers: TeamMember[];
  currentLabel?: string | null;
}) {
  const [open, setOpen] = useState(false);
  const selected = managers.find((m) => String(m.id) === value);
  const display =
    selected != null
      ? `${selected.name} (${selected.email})`
      : value
        ? (currentLabel ?? `User #${value}`)
        : "No manager";

  return (
    <div className="grid gap-1.5">
      <Label>Reports to</Label>
      <p className="text-[11px] text-muted-foreground leading-snug">
        Any user can be selected (including inactive managers). Search by name or email. Self and people who already report into this user are excluded (cycle guardrail).
      </p>
      <div className="flex gap-1.5">
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              role="combobox"
              aria-expanded={open}
              className="h-9 flex-1 justify-between font-normal"
            >
              <span className="truncate text-left">{display}</span>
              <ChevronsUpDown className="ml-2 h-3.5 w-3.5 shrink-0 opacity-50" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
            <Command>
              <CommandInput placeholder="Search name or email…" />
              <CommandList>
                <CommandEmpty>No matching users.</CommandEmpty>
                <CommandGroup>
                  <CommandItem
                    value="none __clear__"
                    onSelect={() => {
                      onChange("");
                      setOpen(false);
                    }}
                  >
                    <Check className={cn("mr-2 h-3.5 w-3.5", !value ? "opacity-100" : "opacity-0")} />
                    No manager
                  </CommandItem>
                  {managers.map((m) => {
                    const itemValue = `${m.name} ${m.email} ${m.role ?? ""} ${m.org_level ?? ""}`;
                    return (
                      <CommandItem
                        key={m.id}
                        value={itemValue}
                        onSelect={() => {
                          onChange(String(m.id));
                          setOpen(false);
                        }}
                      >
                        <Check
                          className={cn(
                            "mr-2 h-3.5 w-3.5",
                            value === String(m.id) ? "opacity-100" : "opacity-0",
                          )}
                        />
                        <span className="flex min-w-0 flex-col">
                          <span className="truncate font-medium">
                            {m.name}
                            {m.is_active === false ? (
                              <span className="ml-1 text-[10px] font-normal text-amber-700 dark:text-amber-400">
                                (inactive)
                              </span>
                            ) : null}
                          </span>
                          <span className="truncate text-[11px] text-muted-foreground">
                            {m.email}
                            {m.role ? ` · ${m.role}` : ""}
                            {m.org_level ? ` · ${m.org_level}` : ""}
                          </span>
                        </span>
                      </CommandItem>
                    );
                  })}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
        {value ? (
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="h-9 w-9 shrink-0"
            title="Clear manager"
            onClick={() => onChange("")}
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        ) : null}
      </div>
    </div>
  );
}

export function OrgConfigFields({
  form,
  setForm,
  departments,
  reportOptions,
  excludeUserId,
}: {
  form: OrgFormState;
  setForm: Dispatch<SetStateAction<OrgFormState>>;
  departments: Department[];
  reportOptions: TeamMember[];
  excludeUserId?: number;
}) {
  const managers = useMemo(
    () => eligibleReportManagers(reportOptions, excludeUserId),
    [reportOptions, excludeUserId],
  );

  const currentManagerLabel = useMemo(() => {
    if (!form.reports_to_user_id) return null;
    const fromOptions = reportOptions.find((m) => String(m.id) === form.reports_to_user_id);
    if (fromOptions) return `${fromOptions.name} (${fromOptions.email})`;
    return null;
  }, [form.reports_to_user_id, reportOptions]);

  // If current manager is inactive / filtered out, still allow displaying them.
  const managersWithCurrent = useMemo(() => {
    if (!form.reports_to_user_id) return managers;
    if (managers.some((m) => String(m.id) === form.reports_to_user_id)) return managers;
    const current = reportOptions.find((m) => String(m.id) === form.reports_to_user_id);
    return current ? [current, ...managers] : managers;
  }, [managers, form.reports_to_user_id, reportOptions]);

  return (
    <>
      <div className="grid gap-1.5">
        <Label>Primary department / team</Label>
        <Select
          value={form.department_id || "none"}
          onValueChange={(v) => {
            const id = v === "none" ? "" : v;
            setForm((s) => ({
              ...s,
              department_id: id,
              department_ids: id && !s.department_ids.includes(id) ? [...s.department_ids, id] : s.department_ids,
            }));
          }}
        >
          <SelectTrigger>
            <SelectValue placeholder="No department" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="none">No department</SelectItem>
            {departments.map((d) => (
              <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="grid gap-1.5 md:col-span-2">
        <Label>Additional teams (multi-department membership)</Label>
        <div className="flex flex-wrap gap-3 rounded-md border p-3">
          {departments.map((d) => (
            <label key={d.id} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border"
                checked={form.department_ids.includes(String(d.id))}
                onChange={() =>
                  setForm((s) => ({
                    ...s,
                    department_ids: toggleDepartment(s.department_ids, String(d.id)),
                  }))
                }
              />
              {d.name}
            </label>
          ))}
        </div>
      </div>

      <div className="grid gap-1.5">
        <Label>Org level</Label>
        <Select
          value={form.org_level}
          onValueChange={(org_level) =>
            setForm((s) => ({
              ...s,
              org_level: org_level as OrgLevel,
              data_scope_mode:
                org_level === "operations" || org_level === "executive" || org_level === "c_suite"
                  ? "org_wide"
                  : org_level === "gap"
                    ? "deny_all"
                    : s.data_scope_mode,
              sector_scopes: org_level === "operations" || org_level === "executive" || org_level === "c_suite"
                ? ["ALL"]
                : s.sector_scopes,
            }))
          }
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {ORG_LEVELS.map((o) => (
              <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="md:col-span-2">
        <ReportsToPicker
          value={form.reports_to_user_id}
          onChange={(id) => setForm((s) => ({ ...s, reports_to_user_id: id }))}
          managers={managersWithCurrent}
          currentLabel={currentManagerLabel}
        />
      </div>

      <div className="grid gap-1.5">
        <Label>Department role</Label>
        <Select
          value={form.department_role}
          onValueChange={(role) => setForm((s) => ({ ...s, department_role: role }))}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="member">Member</SelectItem>
            <SelectItem value="hod">Head of Department</SelectItem>
            <SelectItem value="executive">Executive</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="grid gap-1.5">
        <Label>Product type scope</Label>
        <Select
          value={form.product_type_scope}
          onValueChange={(v) => setForm((s) => ({ ...s, product_type_scope: v as ProductTypeScope }))}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="both">Manufactured + Trading</SelectItem>
            <SelectItem value="manufactured">Manufactured only</SelectItem>
            <SelectItem value="trading">Trading / partner brands only</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="grid gap-1.5 md:col-span-2">
        <Label>Sector scope</Label>
        <div className="flex flex-wrap gap-3 rounded-md border p-3">
          {SECTORS.map((sector) => (
            <label key={sector} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border"
                checked={form.sector_scopes.includes(sector)}
                onChange={() =>
                  setForm((s) => ({
                    ...s,
                    sector_scopes: toggleSector(s.sector_scopes, sector),
                  }))
                }
              />
              {sector}
            </label>
          ))}
        </div>
      </div>
    </>
  );
}

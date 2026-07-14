import { createFileRoute } from "@tanstack/react-router";
import { useAuth } from "@/lib/auth";
import { Eye, Mail, Pencil, Trash2, UserPlus, Users, History, Clock, RotateCcw } from "lucide-react";
import { UserSessionsSheet } from "@/components/admin/UserSessionsSheet";
import {
  OrgConfigFields,
  defaultOrgFormState,
  orgFormFromMember,
  orgPayloadFromForm,
  type OrgFormState,
} from "@/components/admin/OrgConfigFields";
import { BrandAssignmentFields } from "@/components/admin/BrandAssignmentFields";
import { CustomerAssignmentFields } from "@/components/admin/CustomerAssignmentFields";
import { StaffImportPanel } from "@/components/admin/StaffImportPanel";
import { useEffect, useMemo, useState, type ComponentType, type FormEvent, type ReactNode } from "react";
import { toast } from "sonner";
import { StatusBadge as SharedStatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import {
  useTeamMembers,
  useRoles,
  useDepartments,
  useCreateTeamMember,
  useUpdateUser,
  useResendWelcomeEmail,
  useToggleUserStatus,
  useDeleteUser,
  useRepCodeHistory,
  useRestoreRepCode,
} from "@/hooks/admin/useAdminSettings";
import type { TeamMember, RepCodeHistoryEntry } from "@/types/admin";

export const Route = createFileRoute("/app/team")({
  head: () => ({ meta: [{ title: "Team Members — Kim-Fay OrderWatch" }] }),
  component: TeamPage,
});

function TeamPage() {
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Team Members</h1>
        <p className="text-sm text-muted-foreground">
          Manage OrderWatch user accounts, roles, and rep code assignments.
        </p>
      </div>
      <TeamMembersPanel />
    </div>
  );
}

// ── Shared primitives (self-contained copy so this route is standalone) ───────

function Panel({ title, icon: Icon, children }: { title: string; icon: ComponentType<{ className?: string }>; children: ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
        <Icon className="h-4 w-4" />
        {title}
      </h3>
      {children}
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  placeholder,
  type = "text",
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  type?: string;
}) {
  return (
    <div className="grid gap-1">
      <Label className="text-xs">{label}</Label>
      <Input
        type={type}
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}

function AdditionalRoles({
  roles,
  primaryRole,
  selectedRoleIds,
  onChange,
}: {
  roles: Array<{ id: number; name: string }>;
  primaryRole: string;
  selectedRoleIds: number[];
  onChange: (roleIds: number[]) => void;
}) {
  const primaryRoleId = roles.find((role) => role.name === primaryRole)?.id;
  const choices = roles.filter((role) => role.id !== primaryRoleId);

  return (
    <div className="grid gap-1.5 sm:col-span-2 lg:col-span-3">
      <Label className="text-xs">Additional roles</Label>
      <div className="flex flex-wrap gap-2 rounded-md border bg-muted/10 p-2">
        {choices.map((role) => {
          const checked = selectedRoleIds.includes(role.id);
          return (
            <label key={role.id} className="flex items-center gap-2 rounded border bg-background px-2 py-1 text-xs">
              <input
                type="checkbox"
                className="h-3.5 w-3.5"
                checked={checked}
                onChange={(event) => {
                  onChange(
                    event.target.checked
                      ? [...selectedRoleIds, role.id]
                      : selectedRoleIds.filter((id) => id !== role.id),
                  );
                }}
              />
              {role.name}
            </label>
          );
        })}
      </div>
      <p className="text-[11px] text-muted-foreground">
        Use this for capability packs such as Technician Manager or Technician without changing the primary menu role.
      </p>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  return <SharedStatusBadge status={status} />;
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

function ErrorBlock({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-sm font-medium">{message}</p>
      <Button className="mt-3" variant="outline" onClick={onRetry}>
        Retry
      </Button>
    </div>
  );
}

function memberDepartments(member: TeamMember): string {
  return (member.departments?.map((d) => d.name) ?? [member.department?.name])
    .filter(Boolean)
    .join(", ") || "-";
}

function listValue(values: Array<string | null | undefined> | undefined): string {
  return values?.filter(Boolean).join(", ") || "-";
}

function DetailItem({ label, value, mono = false }: { label: string; value: ReactNode; mono?: boolean }) {
  return (
    <div className="rounded-md border bg-muted/10 px-3 py-2">
      <div className="text-[11px] font-medium uppercase text-muted-foreground">{label}</div>
      <div className={`mt-1 text-sm ${mono ? "font-mono" : ""}`}>{value}</div>
    </div>
  );
}

function ReporteesTable({
  reportees,
  onView,
}: {
  reportees: TeamMember[];
  onView: (member: TeamMember) => void;
}) {
  if (reportees.length === 0) {
    return (
      <div className="rounded-md border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
        No direct reportees.
      </div>
    );
  }

  return (
    <div className="overflow-x-auto rounded-md border">
      <table className="w-full text-sm">
        <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
          <tr>
            <th className="px-3 py-2 text-left">Name</th>
            <th className="px-3 py-2 text-left">Role</th>
            <th className="px-3 py-2 text-left">Email</th>
            <th className="px-3 py-2 text-left">Rep Code</th>
            <th className="px-3 py-2 text-left">Status</th>
            <th className="px-3 py-2 text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          {reportees.map((member) => (
            <tr key={member.id} className="border-t">
              <td className="px-3 py-2 text-xs font-medium">{member.name}</td>
              <td className="px-3 py-2 text-xs">{member.role}</td>
              <td className="px-3 py-2 text-xs">{member.email}</td>
              <td className="px-3 py-2 text-xs font-mono">{member.rep_code ?? "-"}</td>
              <td className="px-3 py-2 text-xs">
                <StatusBadge status={member.is_active ? "active" : "inactive"} />
              </td>
              <td className="px-3 py-2 text-right">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="h-6 px-2 text-[10px]"
                  onClick={() => onView(member)}
                >
                  <Eye className="mr-1 h-3 w-3" />
                  View
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function TeamMemberDetailSheet({
  member,
  members,
  onClose,
  onViewMember,
}: {
  member: TeamMember;
  members: TeamMember[];
  onClose: () => void;
  onViewMember: (member: TeamMember) => void;
}) {
  const reportees = useMemo(
    () => members.filter((item) => item.reports_to_user_id === member.id),
    [member.id, members],
  );
  const hasReportees = reportees.length > 0;

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-2xl">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <Eye className="h-4 w-4" />
            {member.name}
          </SheetTitle>
          <SheetDescription>{member.role} - {member.email}</SheetDescription>
        </SheetHeader>

        <Tabs defaultValue="overview" className="mt-4">
          <TabsList>
            <TabsTrigger value="overview">Overview</TabsTrigger>
            {hasReportees && <TabsTrigger value="team">Team</TabsTrigger>}
          </TabsList>

          <TabsContent value="overview" className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={member.is_active ? "active" : "inactive"} />
              {member.is_consultant && (
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">Consultant</span>
              )}
              {member.is_account_manager && (
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">Account Manager</span>
              )}
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <DetailItem label="Name" value={member.name} />
              <DetailItem label="Email" value={member.email} />
              <DetailItem label="Role" value={member.role} />
              <DetailItem label="Org level" value={member.org_level ?? "-"} />
              <DetailItem label="Manager" value={member.reports_to?.name ?? "-"} />
              <DetailItem label="Departments" value={memberDepartments(member)} />
              <DetailItem label="Rep code" value={member.rep_code ?? "-"} mono />
              <DetailItem label="Employee number" value={member.employee_number ?? "-"} mono />
              <DetailItem label="Customer assignments" value={member.customer_assignment_count ?? 0} />
              <DetailItem label="Sectors" value={listValue(member.sector_scopes)} />
              <DetailItem label="Brands" value={listValue(member.brand_assignments)} />
              <DetailItem
                label="Created"
                value={new Date(member.created_at).toLocaleDateString("en-KE", { timeZone: "Africa/Nairobi" })}
              />
            </div>
          </TabsContent>

          {hasReportees && (
            <TabsContent value="team" className="space-y-4">
              <div className="grid gap-3 sm:grid-cols-3">
                <DetailItem label="Direct reportees" value={reportees.length} />
                <DetailItem
                  label="Active"
                  value={reportees.filter((item) => item.is_active).length}
                />
                <DetailItem
                  label="Consultants"
                  value={reportees.filter((item) => item.is_consultant || item.role === "Sales Consultant").length}
                />
              </div>
              <ReporteesTable reportees={reportees} onView={onViewMember} />
            </TabsContent>
          )}
        </Tabs>
      </SheetContent>
    </Sheet>
  );
}

function ByTeamView({
  members,
  childrenByManager,
  onViewMember,
}: {
  members: TeamMember[];
  childrenByManager: Map<number, TeamMember[]>;
  onViewMember: (member: TeamMember) => void;
}) {
  const managers = members.filter((member) => (childrenByManager.get(member.id)?.length ?? 0) > 0);

  if (managers.length === 0) {
    return (
      <div className="rounded-md border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
        No team reporting relationships found.
      </div>
    );
  }

  return (
    <div className="grid gap-3 xl:grid-cols-2">
      {managers.map((manager) => {
        const reportees = childrenByManager.get(manager.id) ?? [];
        return (
          <div key={manager.id} className="rounded-md border bg-background p-3">
            <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
              <div>
                <button
                  type="button"
                  className="text-left text-sm font-semibold underline-offset-2 hover:underline"
                  onClick={() => onViewMember(manager)}
                >
                  {manager.name}
                </button>
                <div className="text-xs text-muted-foreground">{manager.role} - {managerDepartments(manager)}</div>
              </div>
              <div className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">
                {reportees.length} direct
              </div>
            </div>
            <ReporteesTable reportees={reportees} onView={onViewMember} />
          </div>
        );
      })}
    </div>
  );
}

// ── Edit User Dialog ──────────────────────────────────────────────────────────

function EditUserDialog({
  member,
  isAdmin,
  roleOptions,
  allMembers,
  onClose,
}: {
  member: TeamMember;
  isAdmin: boolean;
  roleOptions: Array<{ id: number; name: string }>;
  allMembers: TeamMember[];
  onClose: () => void;
}) {
  const update = useUpdateUser();
  const departments = useDepartments();

  const [form, setForm] = useState({
    name: member.name,
    email: member.email,
    role: member.role,
    role_ids: member.role_ids ?? [],
    phone_number: member.phone_number ?? "",
    rep_code: member.rep_code ?? "",
    employee_number: member.employee_number ?? "",
    is_consultant: member.is_consultant,
    is_account_manager: member.is_account_manager,
    is_active: member.is_active,
    change_reason: "",
  });
  const [orgForm, setOrgForm] = useState<OrgFormState>(() => orgFormFromMember(member));

  const isSalesConsultant = form.role === "Sales Consultant";
  const repCodeChanged = form.rep_code.trim().toUpperCase() !== (member.rep_code ?? "").toUpperCase();

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    update.mutate(
      {
        userId: member.id,
        name: form.name.trim(),
        email: form.email.trim(),
        role: form.role,
        role_ids: form.role_ids,
        phone_number: form.phone_number.trim() || null,
        rep_code: isSalesConsultant ? (form.rep_code.trim() || null) : null,
        employee_number: form.employee_number.trim() || null,
        ...(isAdmin ? orgPayloadFromForm(orgForm) : {}),
        is_consultant: form.is_consultant,
        is_account_manager: isAdmin ? form.is_account_manager : undefined,
        is_active: form.is_active,
        change_reason: repCodeChanged ? (form.change_reason.trim() || null) : undefined,
      },
      { onSuccess: onClose },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="flex max-h-[min(92vh,900px)] w-[calc(100%-1.25rem)] max-w-4xl flex-col gap-0 overflow-hidden p-0 sm:max-w-4xl">
        <DialogHeader className="shrink-0 border-b px-5 py-3 pr-12 text-left">
          <DialogTitle className="text-base">Edit Team Member</DialogTitle>
          <DialogDescription className="text-xs">
            Update details for {member.name}. Scroll if needed — actions stay visible at the bottom.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto px-5 py-3">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Field label="Full name" value={form.name} onChange={(v) => setForm((s) => ({ ...s, name: v }))} placeholder="Jane Wanjiru" />
              <Field label="Work email" value={form.email} onChange={(v) => setForm((s) => ({ ...s, email: v }))} placeholder="jane@kimfay.co.ke" />
              <div className="grid gap-1.5">
                <Label className="text-xs">Role</Label>
                <Select
                  value={form.role}
                  onValueChange={(role) => setForm((s) => ({ ...s, role }))}
                  disabled={!isAdmin}
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue placeholder="Select role" />
                  </SelectTrigger>
                  <SelectContent>
                    {(isAdmin ? roleOptions : roleOptions.filter((r) => r.name === "Sales Consultant")).map((r) => (
                      <SelectItem key={r.id} value={r.name}>{r.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              {isAdmin && (
                <AdditionalRoles
                  roles={roleOptions}
                  primaryRole={form.role}
                  selectedRoleIds={form.role_ids}
                  onChange={(role_ids) => setForm((s) => ({ ...s, role_ids }))}
                />
              )}
              <Field
                label="Phone (optional)"
                value={form.phone_number}
                onChange={(v) => setForm((s) => ({ ...s, phone_number: v }))}
                placeholder="+254..."
              />
              <Field
                label="Employee number"
                value={form.employee_number}
                onChange={(v) => setForm((s) => ({ ...s, employee_number: v }))}
                placeholder="e.g. P505"
              />
              {isSalesConsultant && (
                <Field
                  label="Rep Code"
                  value={form.rep_code}
                  onChange={(v) => setForm((s) => ({ ...s, rep_code: v }))}
                  placeholder="P505"
                />
              )}
              {isAdmin && (
                <OrgConfigFields
                  form={orgForm}
                  setForm={setOrgForm}
                  departments={departments.data ?? []}
                  reportOptions={allMembers}
                  excludeUserId={member.id}
                />
              )}
              {isAdmin && (
                <>
                  <div className="sm:col-span-2 lg:col-span-3">
                    <BrandAssignmentFields member={member} />
                  </div>
                  <div className="sm:col-span-2 lg:col-span-3">
                    <CustomerAssignmentFields member={member} />
                  </div>
                </>
              )}
              {isSalesConsultant && repCodeChanged && (
                <div className="grid gap-1.5 sm:col-span-2 lg:col-span-3">
                  <Label className="text-xs">Reason for rep code change (optional)</Label>
                  <Input
                    value={form.change_reason}
                    onChange={(e) => setForm((s) => ({ ...s, change_reason: e.target.value }))}
                    placeholder="e.g. Consultant transferred territories"
                  />
                </div>
              )}
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2 sm:col-span-2 lg:col-span-3">
                {isAdmin && (
                  <>
                    <label className="flex items-center gap-2 text-xs">
                      <input
                        id="edit-is-consultant"
                        type="checkbox"
                        className="h-3.5 w-3.5 rounded border"
                        checked={form.is_consultant}
                        onChange={(e) => setForm((s) => ({ ...s, is_consultant: e.target.checked }))}
                      />
                      Consultant designation
                    </label>
                    <label className="flex items-center gap-2 text-xs">
                      <input
                        id="edit-is-account-manager"
                        type="checkbox"
                        className="h-3.5 w-3.5 rounded border"
                        checked={form.is_account_manager}
                        onChange={(e) => setForm((s) => ({ ...s, is_account_manager: e.target.checked }))}
                      />
                      Account Manager
                    </label>
                  </>
                )}
                <label className="flex items-center gap-2 text-xs font-medium">
                  <input
                    id="edit-is-active"
                    type="checkbox"
                    className="h-3.5 w-3.5 rounded border"
                    checked={form.is_active}
                    onChange={(e) => setForm((s) => ({ ...s, is_active: e.target.checked }))}
                  />
                  Account active
                  <span className="font-normal text-muted-foreground">
                    ({form.is_active ? "can sign in" : "suspended"})
                  </span>
                </label>
              </div>
            </div>
          </div>
          <DialogFooter className="shrink-0 gap-2 border-t bg-background px-5 py-3 sm:justify-end">
            <Button type="button" variant="outline" size="sm" className="h-8 text-xs" onClick={onClose}>
              Cancel
            </Button>
            <Button
              type="submit"
              size="sm"
              className="h-8 text-xs"
              disabled={update.isPending || !form.name.trim() || !form.email.trim()}
            >
              {update.isPending ? "Saving…" : "Save changes"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

// ── Rep Code History Sheet ────────────────────────────────────────────────────

function RepCodeHistorySheet({
  member,
  onClose,
}: {
  member: TeamMember;
  onClose: () => void;
}) {
  const history = useRepCodeHistory(member.id);
  const restore = useRestoreRepCode();

  function handleRestore(entry: RepCodeHistoryEntry) {
    if (!confirm(`Restore rep code "${entry.rep_code}" for ${member.name}?`)) return;
    restore.mutate(
      {
        userId: member.id,
        historyEntryId: entry.id,
      },
      {
        onSuccess: () => {
          toast.success(`Rep code restored to ${entry.rep_code}`);
          onClose();
        },
      },
    );
  }

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <History className="h-4 w-4" />
            Rep Code History
          </SheetTitle>
          <SheetDescription>{member.name} — past rep code values</SheetDescription>
        </SheetHeader>

        <div className="mt-4 space-y-2">
          <div className="rounded-md border bg-muted/20 px-3 py-2 text-sm">
            <span className="text-muted-foreground">Current rep code: </span>
            <span className="font-mono font-semibold">{member.rep_code ?? "—"}</span>
          </div>

          {history.isLoading && (
            <div className="space-y-2 pt-2">
              {[1, 2, 3].map((i) => <Skeleton key={i} className="h-14 w-full" />)}
            </div>
          )}

          {history.isError && (
            <p className="text-sm text-destructive">Failed to load history.</p>
          )}

          {history.data && history.data.length === 0 && (
            <p className="py-6 text-center text-sm text-muted-foreground">
              No rep code changes recorded yet.
            </p>
          )}

          {history.data?.map((entry) => (
            <div key={entry.id} className="rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-mono font-semibold">{entry.rep_code ?? "(empty)"}</span>
                <Button
                  size="sm"
                  variant="outline"
                  className="h-7 px-2 text-xs"
                  disabled={restore.isPending || entry.rep_code === member.rep_code}
                  onClick={() => handleRestore(entry)}
                >
                  <RotateCcw className="mr-1 h-3 w-3" />
                  Restore
                </Button>
              </div>
              <div className="mt-1 text-xs text-muted-foreground">
                Changed {new Date(entry.changed_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}
                {entry.changed_by_name && <> by {entry.changed_by_name}</>}
              </div>
              {entry.change_reason && (
                <div className="mt-1 text-xs italic text-muted-foreground">"{entry.change_reason}"</div>
              )}
            </div>
          ))}
        </div>
      </SheetContent>
    </Sheet>
  );
}

// ── Main TeamMembersPanel ─────────────────────────────────────────────────────

function TeamMembersPanel() {
  const { session } = useAuth();
  const members = useTeamMembers();
  const roles = useRoles();
  const departments = useDepartments();
  const create = useCreateTeamMember();
  const resendWelcome = useResendWelcomeEmail();
  const toggleStatus = useToggleUserStatus();
  const deleteUser = useDeleteUser();

  const [form, setForm] = useState({
    name: "",
    email: "",
    role: "Customer Service Agent",
    role_ids: [],
    phone_number: "",
    rep_code: "",
    employee_number: "",
    is_consultant: false,
    is_active: true,
  });
  const [orgForm, setOrgForm] = useState<OrgFormState>(defaultOrgFormState);

  const [editingMember, setEditingMember] = useState<TeamMember | null>(null);
  const [viewingMember, setViewingMember] = useState<TeamMember | null>(null);
  const [historyMember, setHistoryMember] = useState<TeamMember | null>(null);
  const [sessionsMember, setSessionsMember] = useState<TeamMember | null>(null);

  const isAdmin = session?.role === "Administrator";
  const roleOptions = (roles.data ?? []).filter((r) => isAdmin || r.name === "Sales Consultant");
  const isSalesConsultant = form.role === "Sales Consultant";
  const teamMembers = members.data ?? [];
  const childrenByManager = useMemo(() => {
    const map = new Map<number, TeamMember[]>();

    for (const member of teamMembers) {
      if (member.reports_to_user_id == null) continue;

      const reportees = map.get(member.reports_to_user_id) ?? [];
      reportees.push(member);
      map.set(member.reports_to_user_id, reportees);
    }

    for (const reportees of map.values()) {
      reportees.sort((a, b) => a.name.localeCompare(b.name));
    }

    return map;
  }, [teamMembers]);

  useEffect(() => {
    if (!isAdmin && form.role !== "Sales Consultant") {
      setForm((v) => ({ ...v, role: "Sales Consultant" }));
    }
  }, [form.role, isAdmin]);

  function handleCreate(e: FormEvent) {
    e.preventDefault();
    create.mutate(
      {
        name: form.name.trim(),
        email: form.email.trim(),
        role: form.role,
        role_ids: form.role_ids,
        phone_number: form.phone_number.trim() || undefined,
        rep_code: isSalesConsultant ? form.rep_code.trim() : undefined,
        employee_number: form.employee_number.trim() || undefined,
        ...(isAdmin ? orgPayloadFromForm(orgForm) : {}),
        is_consultant: form.is_consultant,
        is_active: form.is_active,
      },
      {
        onSuccess: () => {
          setForm({
            name: "",
            email: "",
            role: isAdmin ? "Customer Service Agent" : "Sales Consultant",
            role_ids: [],
            phone_number: "",
            rep_code: "",
            employee_number: "",
            is_consultant: false,
            is_active: true,
          });
          setOrgForm(defaultOrgFormState());
        },
      },
    );
  }

  if (members.isLoading || roles.isLoading || departments.isLoading) return <PanelSkeleton />;
  if (members.isError || roles.isError || departments.isError || !members.data || !roles.data) {
    return (
      <ErrorBlock
        message="Team members could not be loaded."
        onRetry={() => {
          members.refetch();
          roles.refetch();
        }}
      />
    );
  }

  return (
    <div className="space-y-4">
      {isAdmin && <StaffImportPanel />}
      <Panel title="Create Team Member" icon={UserPlus}>
        <p className="mb-4 text-sm text-muted-foreground">
          Add a new OrderWatch user. A welcome email with sign-in instructions is sent automatically.
        </p>
        <form className="grid gap-4 md:grid-cols-2" onSubmit={handleCreate}>
          <Field label="Full name" value={form.name} onChange={(v) => setForm((s) => ({ ...s, name: v }))} placeholder="Jane Wanjiru" />
          <Field label="Work email" value={form.email} onChange={(v) => setForm((s) => ({ ...s, email: v }))} placeholder="jane@kimfay.co.ke" />
          <div className="grid gap-1.5">
            <Label>Role</Label>
            <Select value={form.role} onValueChange={(role) => setForm((v) => ({ ...v, role }))}>
              <SelectTrigger>
                <SelectValue placeholder="Select role" />
              </SelectTrigger>
              <SelectContent>
                {roleOptions.map((r) => (
                  <SelectItem key={r.id} value={r.name}>{r.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {isAdmin && (
            <AdditionalRoles
              roles={roleOptions}
              primaryRole={form.role}
              selectedRoleIds={form.role_ids}
              onChange={(role_ids) => setForm((v) => ({ ...v, role_ids }))}
            />
          )}
          <Field
            label="Phone (optional)"
            value={form.phone_number}
            onChange={(v) => setForm((s) => ({ ...s, phone_number: v }))}
            placeholder="+254..."
          />
          <Field
            label="Employee number"
            value={form.employee_number}
            onChange={(v) => setForm((s) => ({ ...s, employee_number: v }))}
            placeholder="e.g. P505"
          />
          {isAdmin && (
            <OrgConfigFields
              form={orgForm}
              setForm={setOrgForm}
              departments={departments.data ?? []}
                  reportOptions={teamMembers}
            />
          )}
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 md:col-span-2">
            {isAdmin && (
              <label className="flex items-center gap-2 text-xs">
                <input
                  id="create-is-consultant"
                  type="checkbox"
                  className="h-3.5 w-3.5 rounded border"
                  checked={form.is_consultant}
                  onChange={(e) => setForm((s) => ({ ...s, is_consultant: e.target.checked }))}
                />
                Consultant designation
              </label>
            )}
            <label className="flex items-center gap-2 text-xs font-medium">
              <input
                id="create-is-active"
                type="checkbox"
                className="h-3.5 w-3.5 rounded border"
                checked={form.is_active}
                onChange={(e) => setForm((s) => ({ ...s, is_active: e.target.checked }))}
              />
              Activate account
              <span className="font-normal text-muted-foreground">
                ({form.is_active ? "can sign in after welcome email" : "created as suspended"})
              </span>
            </label>
          </div>
          {isSalesConsultant && (
            <Field
              label="Rep Code"
              value={form.rep_code}
              onChange={(v) => setForm((s) => ({ ...s, rep_code: v }))}
              placeholder="P505"
            />
          )}
          <div className="md:col-span-2">
            <Button
              type="submit"
              disabled={
                create.isPending ||
                !form.name.trim() ||
                !form.email.trim() ||
                (isSalesConsultant && !form.rep_code.trim())
              }
            >
              {create.isPending ? "Creating…" : "Create account & send email"}
            </Button>
          </div>
        </form>
      </Panel>

      <Panel title="Team Members" icon={Users}>
        <Tabs defaultValue="directory">
          <TabsList>
            <TabsTrigger value="directory">Directory</TabsTrigger>
            <TabsTrigger value="by-team">By Team</TabsTrigger>
          </TabsList>

          <TabsContent value="directory">
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
              <tr>
                <th className="px-3 py-2 text-left">Name</th>
                <th className="px-3 py-2 text-left">Email</th>
                <th className="px-3 py-2 text-left">Role</th>
                <th className="px-3 py-2 text-left hidden lg:table-cell">Org / Teams</th>
                <th className="px-3 py-2 text-left">Rep Code</th>
                <th className="px-3 py-2 text-left hidden md:table-cell">Consultant</th>
                <th className="px-3 py-2 text-left">Status</th>
                <th className="px-3 py-2 text-left hidden md:table-cell">Created</th>
                <th className="px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {teamMembers.map((member) => (
                <tr key={member.id} className="border-t">
                  <td className="px-3 py-2 text-xs font-medium">{member.name}</td>
                  <td className="px-3 py-2 text-xs">{member.email}</td>
                  <td className="px-3 py-2 text-xs">{member.role}</td>
                  <td className="px-3 py-2 text-xs hidden lg:table-cell">
                    <div>{member.org_level ?? "—"}</div>
                    <div className="text-muted-foreground">
                      {(member.departments?.map((d) => d.name) ?? [member.department?.name]).filter(Boolean).join(", ") || "—"}
                    </div>
                  </td>
                  <td className="px-3 py-2 text-xs font-mono">
                    {member.rep_code ?? "—"}
                    {member.role === "Sales Consultant" && (
                      <button
                        type="button"
                        title="View rep code history"
                        className="ml-1.5 inline-flex items-center text-muted-foreground hover:text-foreground"
                        onClick={() => setHistoryMember(member)}
                      >
                        <History className="h-3 w-3" />
                      </button>
                    )}
                  </td>
                  <td className="px-3 py-2 text-xs hidden md:table-cell">
                    {member.is_consultant ? "Yes" : "—"}
                  </td>
                  <td className="px-3 py-2 text-xs">
                    <StatusBadge status={member.is_active ? "active" : "inactive"} />
                  </td>
                  <td className="px-3 py-2 text-xs hidden md:table-cell">
                    {new Date(member.created_at).toLocaleDateString("en-KE", { timeZone: "Africa/Nairobi" })}
                  </td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-6 px-2 text-[10px]"
                        onClick={() => setViewingMember(member)}
                        title="View member"
                      >
                        <Eye className="mr-1 h-3 w-3" /> View
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-6 px-2 text-[10px]"
                        onClick={() => setEditingMember(member)}
                        title="Edit member"
                      >
                        <Pencil className="mr-1 h-3 w-3" /> Edit
                      </Button>
                      {isAdmin && (
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-6 px-2 text-[10px]"
                          onClick={() => setSessionsMember(member)}
                          title="View session history"
                        >
                          <Clock className="mr-1 h-3 w-3" /> Sessions
                        </Button>
                      )}
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-6 px-2 text-[10px]"
                        onClick={() => {
                          if (confirm(`Resend welcome email to ${member.name}?`)) {
                            resendWelcome.mutate(member.id);
                          }
                        }}
                        disabled={resendWelcome.isPending}
                        title="Resend welcome email"
                      >
                        <Mail className="mr-1 h-3 w-3" /> Resend
                      </Button>
                      <Button
                        size="sm"
                        variant={member.is_active ? "outline" : "default"}
                        className={`h-6 px-2 text-[10px] ${member.is_active ? "text-destructive border-destructive/20 hover:bg-destructive/10" : ""}`}
                        onClick={() => {
                          if (confirm(`${member.is_active ? "Suspend" : "Reactivate"} ${member.name}?`)) {
                            toggleStatus.mutate(member.id);
                          }
                        }}
                        disabled={toggleStatus.isPending}
                      >
                        {member.is_active ? "Suspend" : "Reactivate"}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="h-6 px-2 text-[10px] text-destructive hover:bg-destructive/10"
                        onClick={() => {
                          if (confirm(`Permanently delete ${member.name}?`)) {
                            deleteUser.mutate(member.id);
                          }
                        }}
                        disabled={deleteUser.isPending}
                        title="Delete account"
                      >
                        <Trash2 className="h-3 w-3" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
          </TabsContent>

          <TabsContent value="by-team">
            <ByTeamView
              members={teamMembers}
              childrenByManager={childrenByManager}
              onViewMember={setViewingMember}
            />
          </TabsContent>
        </Tabs>
      </Panel>

      {viewingMember && (
        <TeamMemberDetailSheet
          member={viewingMember}
          members={teamMembers}
          onClose={() => setViewingMember(null)}
          onViewMember={setViewingMember}
        />
      )}

      {editingMember && (
        <EditUserDialog
          member={editingMember}
          isAdmin={isAdmin}
          roleOptions={roles.data}
          allMembers={teamMembers}
          onClose={() => setEditingMember(null)}
        />
      )}

      {historyMember && (
        <RepCodeHistorySheet
          member={historyMember}
          onClose={() => setHistoryMember(null)}
        />
      )}

      {sessionsMember && (
        <UserSessionsSheet
          member={sessionsMember}
          onClose={() => setSessionsMember(null)}
        />
      )}
    </div>
  );
}

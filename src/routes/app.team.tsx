import { createFileRoute } from "@tanstack/react-router";
import { useAuth } from "@/lib/auth";
import { Mail, Pencil, Trash2, UserPlus, Users, History, X, RotateCcw } from "lucide-react";
import { useEffect, useState, type ComponentType, type FormEvent, type ReactNode } from "react";
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
    <div className="grid gap-1.5">
      <Label>{label}</Label>
      <Input
        type={type}
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
      />
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

// ── Edit User Dialog ──────────────────────────────────────────────────────────

function EditUserDialog({
  member,
  isAdmin,
  roleOptions,
  onClose,
}: {
  member: TeamMember;
  isAdmin: boolean;
  roleOptions: Array<{ id: number; name: string }>;
  onClose: () => void;
}) {
  const update = useUpdateUser();
  const [form, setForm] = useState({
    name: member.name,
    email: member.email,
    role: member.role,
    phone_number: member.phone_number ?? "",
    rep_code: member.rep_code ?? "",
    is_account_manager: member.is_account_manager,
    change_reason: "",
  });

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
        phone_number: form.phone_number.trim() || null,
        rep_code: isSalesConsultant ? (form.rep_code.trim() || null) : null,
        is_account_manager: isAdmin ? form.is_account_manager : undefined,
        change_reason: repCodeChanged ? (form.change_reason.trim() || null) : undefined,
      },
      { onSuccess: onClose },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Edit Team Member</DialogTitle>
          <DialogDescription>Update details for {member.name}.</DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="grid gap-4 py-2 md:grid-cols-2">
          <Field label="Full name" value={form.name} onChange={(v) => setForm((s) => ({ ...s, name: v }))} placeholder="Jane Wanjiru" />
          <Field label="Work email" value={form.email} onChange={(v) => setForm((s) => ({ ...s, email: v }))} placeholder="jane@kimfay.co.ke" />
          <div className="grid gap-1.5">
            <Label>Role</Label>
            <Select
              value={form.role}
              onValueChange={(role) => setForm((s) => ({ ...s, role }))}
              disabled={!isAdmin}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select role" />
              </SelectTrigger>
              <SelectContent>
                {(isAdmin ? roleOptions : roleOptions.filter((r) => r.name === "Sales Consultant")).map((r) => (
                  <SelectItem key={r.id} value={r.name}>{r.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Field
            label="Phone (optional)"
            value={form.phone_number}
            onChange={(v) => setForm((s) => ({ ...s, phone_number: v }))}
            placeholder="+254..."
          />
          {isSalesConsultant && (
            <Field
              label="Rep Code"
              value={form.rep_code}
              onChange={(v) => setForm((s) => ({ ...s, rep_code: v }))}
              placeholder="P505"
            />
          )}
          {isSalesConsultant && repCodeChanged && (
            <div className="grid gap-1.5 md:col-span-2">
              <Label>Reason for rep code change (optional)</Label>
              <Input
                value={form.change_reason}
                onChange={(e) => setForm((s) => ({ ...s, change_reason: e.target.value }))}
                placeholder="e.g. Consultant transferred territories"
              />
            </div>
          )}
          {isAdmin && (
            <div className="flex items-center gap-2 md:col-span-2">
              <input
                id="edit-is-account-manager"
                type="checkbox"
                className="h-4 w-4 rounded border"
                checked={form.is_account_manager}
                onChange={(e) => setForm((s) => ({ ...s, is_account_manager: e.target.checked }))}
              />
              <Label htmlFor="edit-is-account-manager">Account Manager</Label>
            </div>
          )}
          <DialogFooter className="md:col-span-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={update.isPending || !form.name.trim() || !form.email.trim()}>
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
  const create = useCreateTeamMember();
  const resendWelcome = useResendWelcomeEmail();
  const toggleStatus = useToggleUserStatus();
  const deleteUser = useDeleteUser();

  const [form, setForm] = useState({
    name: "",
    email: "",
    role: "Customer Service Agent",
    phone_number: "",
    rep_code: "",
  });

  const [editingMember, setEditingMember] = useState<TeamMember | null>(null);
  const [historyMember, setHistoryMember] = useState<TeamMember | null>(null);

  const isAdmin = session?.role === "Administrator";
  const roleOptions = (roles.data ?? []).filter((r) => isAdmin || r.name === "Sales Consultant");
  const isSalesConsultant = form.role === "Sales Consultant";

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
        phone_number: form.phone_number.trim() || undefined,
        rep_code: isSalesConsultant ? form.rep_code.trim() : undefined,
      },
      {
        onSuccess: () =>
          setForm({
            name: "",
            email: "",
            role: isAdmin ? "Customer Service Agent" : "Sales Consultant",
            phone_number: "",
            rep_code: "",
          }),
      },
    );
  }

  if (members.isLoading || roles.isLoading) return <PanelSkeleton />;
  if (members.isError || roles.isError || !members.data || !roles.data) {
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
          <Field
            label="Phone (optional)"
            value={form.phone_number}
            onChange={(v) => setForm((s) => ({ ...s, phone_number: v }))}
            placeholder="+254..."
          />
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
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm">
            <thead className="bg-muted/30 text-[11px] uppercase text-muted-foreground">
              <tr>
                <th className="px-3 py-2 text-left">Name</th>
                <th className="px-3 py-2 text-left">Email</th>
                <th className="px-3 py-2 text-left">Role</th>
                <th className="px-3 py-2 text-left">Rep Code</th>
                <th className="px-3 py-2 text-left">Status</th>
                <th className="px-3 py-2 text-left hidden md:table-cell">Created</th>
                <th className="px-3 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {members.data.map((member) => (
                <tr key={member.id} className="border-t">
                  <td className="px-3 py-2 text-xs font-medium">{member.name}</td>
                  <td className="px-3 py-2 text-xs">{member.email}</td>
                  <td className="px-3 py-2 text-xs">{member.role}</td>
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
                        onClick={() => setEditingMember(member)}
                        title="Edit member"
                      >
                        <Pencil className="mr-1 h-3 w-3" /> Edit
                      </Button>
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
      </Panel>

      {editingMember && (
        <EditUserDialog
          member={editingMember}
          isAdmin={isAdmin}
          roleOptions={roles.data}
          onClose={() => setEditingMember(null)}
        />
      )}

      {historyMember && (
        <RepCodeHistorySheet
          member={historyMember}
          onClose={() => setHistoryMember(null)}
        />
      )}
    </div>
  );
}

import { useState } from "react";
import { Pencil, Plus, Star, Trash2, UserRound } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { CONTACT_DESIGNATIONS } from "@/lib/contact-designations";
import {
  useCreateCustomerContact,
  useCustomerContacts,
  useDeactivateCustomerContact,
  useUpdateCustomerContact,
  type CustomerContact,
  type CustomerContactInput,
} from "@/hooks/useCustomerContacts";

const emptyForm = (): CustomerContactInput => ({
  designation_key: "ceo_md",
  designation_label: "",
  first_name: "",
  last_name: "",
  phone: "",
  email: "",
  is_primary: false,
});

function contactToForm(c: CustomerContact): CustomerContactInput {
  return {
    designation_key: c.designation_key,
    designation_label: c.designation_key === "custom" ? c.designation_label : "",
    first_name: c.first_name,
    last_name: c.last_name,
    phone: c.phone ?? "",
    email: c.email ?? "",
    is_primary: c.is_primary,
  };
}

function validateContactForm(form: CustomerContactInput): string | null {
  if (!form.first_name.trim()) return "First name is required.";
  if (!form.last_name.trim()) return "Last name is required.";
  if (form.designation_key === "custom" && !form.designation_label?.trim()) {
    return "Custom designation requires a job title.";
  }
  if (form.email?.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
    return "Enter a valid email address.";
  }
  return null;
}

export function CustomerContactsCard({ customerId }: { customerId: string }) {
  const contacts = useCustomerContacts(customerId);
  const create = useCreateCustomerContact(customerId);
  const update = useUpdateCustomerContact(customerId);
  const deactivate = useDeactivateCustomerContact(customerId);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<CustomerContact | null>(null);
  const [form, setForm] = useState<CustomerContactInput>(emptyForm());
  const [removeTarget, setRemoveTarget] = useState<CustomerContact | null>(null);

  function openCreate() {
    setEditing(null);
    setForm(emptyForm());
    setDialogOpen(true);
  }

  function openEdit(c: CustomerContact) {
    setEditing(c);
    setForm(contactToForm(c));
    setDialogOpen(true);
  }

  function submit() {
    const error = validateContactForm(form);
    if (error) {
      toast.error(error);
      return;
    }

    const payload: CustomerContactInput = {
      ...form,
      first_name: form.first_name.trim(),
      last_name: form.last_name.trim(),
      phone: form.phone?.trim() || null,
      email: form.email?.trim() || null,
      designation_label:
        form.designation_key === "custom" ? form.designation_label?.trim() || null : null,
    };

    if (editing) {
      update.mutate(
        { id: editing.id, ...payload },
        {
          onSuccess: () => {
            setDialogOpen(false);
            setEditing(null);
            setForm(emptyForm());
          },
        },
      );
    } else {
      create.mutate(payload, {
        onSuccess: () => {
          setDialogOpen(false);
          setForm(emptyForm());
        },
      });
    }
  }

  const rows = contacts.data?.data ?? [];
  const saving = create.isPending || update.isPending;

  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="flex flex-row items-center justify-between gap-2 pb-3">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <UserRound className="h-4 w-4 text-muted-foreground" />
            Contacts
          </CardTitle>
          <p className="text-sm text-muted-foreground">
            CRM contacts on this account (CEO/MD, Finance, Procurement, custom roles).
            Reused as site requestors on New FOL.
          </p>
        </div>
        <Button size="sm" onClick={openCreate}>
          <Plus className="mr-1 h-4 w-4" /> Add contact
        </Button>
      </CardHeader>
      <CardContent>
        {contacts.isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : contacts.isError ? (
          <p className="text-sm text-destructive">
            Could not load contacts.{" "}
            <button type="button" className="underline" onClick={() => void contacts.refetch()}>
              Retry
            </button>
          </p>
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No contacts yet. Add site requestors for FOL and CRM.
          </p>
        ) : (
          <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-left">
                <tr>
                  <th className="px-3 py-2 font-medium">Designation</th>
                  <th className="px-3 py-2 font-medium">Name</th>
                  <th className="px-3 py-2 font-medium">Phone</th>
                  <th className="px-3 py-2 font-medium">Email</th>
                  <th className="px-3 py-2 font-medium text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rows.map((c) => (
                  <tr key={c.id}>
                    <td className="px-3 py-2">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <span>{c.designation_label}</span>
                        {c.is_primary && (
                          <Badge variant="secondary" className="gap-1 text-[10px]">
                            <Star className="h-3 w-3" /> Primary
                          </Badge>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2 font-medium">{c.full_name}</td>
                    <td className="px-3 py-2 text-muted-foreground">{c.phone ?? "—"}</td>
                    <td className="px-3 py-2 text-muted-foreground">{c.email ?? "—"}</td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex justify-end gap-0.5">
                        <Button
                          size="icon"
                          variant="ghost"
                          className="h-8 w-8"
                          title="Edit contact"
                          onClick={() => openEdit(c)}
                        >
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button
                          size="icon"
                          variant="ghost"
                          className="h-8 w-8 text-destructive"
                          title="Remove contact"
                          disabled={deactivate.isPending}
                          onClick={() => setRemoveTarget(c)}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>

      <Dialog
        open={dialogOpen}
        onOpenChange={(open) => {
          setDialogOpen(open);
          if (!open) {
            setEditing(null);
            setForm(emptyForm());
          }
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{editing ? "Edit account contact" : "Add account contact"}</DialogTitle>
          </DialogHeader>
          <ContactFormFields form={form} onChange={setForm} />
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={submit} disabled={saving}>
              {saving ? "Saving…" : editing ? "Update contact" : "Save contact"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!removeTarget} onOpenChange={(open) => !open && setRemoveTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove contact?</AlertDialogTitle>
            <AlertDialogDescription>
              {removeTarget
                ? `${removeTarget.full_name} will be deactivated on this account and no longer appear as a FOL requestor option.`
                : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => {
                if (!removeTarget) return;
                deactivate.mutate(removeTarget.id, {
                  onSuccess: () => setRemoveTarget(null),
                });
              }}
            >
              Remove
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}

export function ContactFormFields({
  form,
  onChange,
}: {
  form: CustomerContactInput;
  onChange: (next: CustomerContactInput) => void;
}) {
  return (
    <div className="grid gap-3">
      <div>
        <Label>Designation</Label>
        <Select
          value={form.designation_key}
          onValueChange={(key) => onChange({ ...form, designation_key: key })}
        >
          <SelectTrigger className="mt-1">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {CONTACT_DESIGNATIONS.map((d) => (
              <SelectItem key={d.key} value={d.key}>
                {d.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      {form.designation_key === "custom" && (
        <div>
          <Label>Custom job title</Label>
          <Input
            className="mt-1"
            value={form.designation_label ?? ""}
            onChange={(e) => onChange({ ...form, designation_label: e.target.value })}
            placeholder="e.g. Site Manager"
          />
        </div>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <Label>First name</Label>
          <Input
            className="mt-1"
            value={form.first_name}
            onChange={(e) => onChange({ ...form, first_name: e.target.value })}
          />
        </div>
        <div>
          <Label>Last name</Label>
          <Input
            className="mt-1"
            value={form.last_name}
            onChange={(e) => onChange({ ...form, last_name: e.target.value })}
          />
        </div>
        <div>
          <Label>Phone</Label>
          <Input
            className="mt-1"
            value={form.phone ?? ""}
            onChange={(e) => onChange({ ...form, phone: e.target.value })}
          />
        </div>
        <div>
          <Label>Email</Label>
          <Input
            className="mt-1"
            type="email"
            value={form.email ?? ""}
            onChange={(e) => onChange({ ...form, email: e.target.value })}
          />
        </div>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox
          checked={!!form.is_primary}
          onCheckedChange={(checked) => onChange({ ...form, is_primary: Boolean(checked) })}
        />
        Primary contact for this account
      </label>
    </div>
  );
}

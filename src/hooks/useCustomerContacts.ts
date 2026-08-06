import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch } from "@/lib/api";
import type { ContactDesignationKey } from "@/lib/contact-designations";

export type CustomerContact = {
  id: number;
  customer_acumatica_id: string;
  designation_key: ContactDesignationKey | string;
  designation_label: string;
  first_name: string;
  last_name: string;
  full_name: string;
  phone: string | null;
  email: string | null;
  is_primary: boolean;
  is_active: boolean;
  notes: string | null;
  created_at?: string;
  updated_at?: string;
};

export type CustomerContactInput = {
  designation_key: string;
  designation_label?: string | null;
  first_name: string;
  last_name: string;
  phone?: string | null;
  email?: string | null;
  is_primary?: boolean;
  notes?: string | null;
};

export function useCustomerContacts(customerId: string | null | undefined, enabled = true) {
  return useQuery({
    queryKey: ["customer-contacts", customerId],
    queryFn: () =>
      apiFetch<{ data: CustomerContact[]; designations: Array<{ key: string; label: string }> }>(
        `customers/${encodeURIComponent(customerId!)}/contacts`,
      ),
    enabled: !!customerId && enabled,
  });
}

export function useCreateCustomerContact(customerId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CustomerContactInput) =>
      apiFetch<CustomerContact>(`customers/${encodeURIComponent(customerId)}/contacts`, {
        method: "POST",
        body,
      }),
    onSuccess: () => {
      toast.success("Contact saved on account");
      qc.invalidateQueries({ queryKey: ["customer-contacts", customerId] });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateCustomerContact(customerId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...body }: CustomerContactInput & { id: number }) =>
      apiFetch<CustomerContact>(`contacts/${id}`, { method: "PUT", body }),
    onSuccess: () => {
      toast.success("Contact updated");
      qc.invalidateQueries({ queryKey: ["customer-contacts", customerId] });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useDeactivateCustomerContact(customerId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch(`contacts/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Contact removed");
      qc.invalidateQueries({ queryKey: ["customer-contacts", customerId] });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

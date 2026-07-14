import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export type UserCapabilities = {
  permissions: string[];
  menus: string[];
  hidden_menus: string[];
  mask_revenue: boolean;
  department: {
    id: number;
    slug: string;
    name: string;
    is_customer_facing: boolean;
  } | null;
  department_role: string;
  is_consultant: boolean;
  employee_number: string | null;
  idle_timeout_minutes: number;
};

const FALLBACK: UserCapabilities = {
  permissions: [],
  menus: [],
  hidden_menus: [],
  mask_revenue: false,
  department: null,
  department_role: "member",
  is_consultant: false,
  employee_number: null,
  idle_timeout_minutes: 60,
};

export function useCapabilities() {
  const token = getToken();
  const query = useQuery({
    queryKey: ["auth-capabilities"],
    // Path is relative to VITE_API_BASE_URL (already ends with /api in production).
    queryFn: () => apiFetch<UserCapabilities>("auth/capabilities"),
    enabled: !!token,
    staleTime: 5 * 60 * 1000,
  });

  const caps = query.data ?? FALLBACK;

  return {
    ...caps,
    isLoading: query.isLoading,
    canSeeMenu: (slug: string) => !caps.hidden_menus.includes(slug),
    maskRevenue: caps.mask_revenue,
    idleTimeoutMinutes: caps.idle_timeout_minutes,
  };
}
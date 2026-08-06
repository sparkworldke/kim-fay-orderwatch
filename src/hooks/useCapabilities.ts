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
  org_level?: string;
  is_consultant: boolean;
  employee_number: string | null;
  has_reportees: boolean;
  sales_intelligence_channels: string[];
  /** COO / admin / store mgr / production mgr — MSI, safety & buffer bulk upload. */
  can_manage_production_planning?: boolean;
  can_manage_users: boolean;
  unrestricted_business_access: boolean;
  executive_view: boolean;
  idle_timeout_minutes: number;
};

const FALLBACK: UserCapabilities = {
  permissions: [],
  menus: [],
  hidden_menus: [],
  mask_revenue: false,
  department: null,
  department_role: "member",
  org_level: undefined,
  is_consultant: false,
  employee_number: null,
  has_reportees: false,
  sales_intelligence_channels: ["portfolio"],
  can_manage_production_planning: false,
  can_manage_users: false,
  unrestricted_business_access: false,
  executive_view: false,
  idle_timeout_minutes: 60,
};

export function useCapabilities() {
  const token = getToken();
  const query = useQuery({
    queryKey: ["auth-capabilities"],
    // Path is relative to VITE_API_BASE_URL (already ends with /api in production).
    queryFn: () => apiFetch<UserCapabilities>("auth/capabilities"),
    enabled: !!token,
    staleTime: 10 * 60 * 1000,
    gcTime: 60 * 60 * 1000,
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

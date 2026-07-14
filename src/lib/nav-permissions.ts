import type { Role } from "@/lib/auth";

const ADMIN_ONLY_URLS = new Set([
  "/app/administration",
  "/app/roles",
]);

export const ALL_AUTHENTICATED_URLS = new Set([
  "/app",
  "/app/orders",
  "/app/business-optimization",
  "/app/ai-intelligence",
  "/app/customer-feed",
  "/app/credit-notes-more",
  "/app/inventory",
  "/app/backorders",
  "/app/fill-rate",
  "/app/zones",
  "/app/customers",
  "/app/so-imports",
  "/app/kp/fol",
  "/app/kp/fol/calendar",
  "/app/price-change-requests",
  "/app/sales-management",
  "/app/profile",
]);

const ADMIN_ONLY_ADMIN_TABS = new Set([
  "acumatica",
  "ai-keys",
  "data-tools",
  "roles",
  "permissions",
  "notifications",
  "fol",
  "impersonation",
]);

const ADMIN_MANAGER_ADMIN_TABS = new Set([
  "team",
  "consultants",
]);

const CS_AND_ADMIN_URLS = new Set([
  "/app/mailbox",
  "/app/order-match",
  "/app/administration",
]);

const SALES_CONSULTANT_URLS = new Set([
  "/app/sales-consultants",
]);

// Team page: admin + CS Manager
const ADMIN_MANAGER_URLS = new Set([
  "/app/team",
]);

const PRIVILEGED_ROLES: Role[] = ["Administrator", "Customer Service Manager", "Customer Service Agent"];
const SALES_CONSULTANT_ROLES: Role[] = [
  "Administrator",
  "Customer Service Manager",
  "Executive",
  "Sales Operations",
  "Sales Consultant",
];

export function isPrivilegedRole(role: Role | undefined): boolean {
  return role != null && PRIVILEGED_ROLES.includes(role);
}

export const MENU_SLUG_BY_URL: Record<string, string> = {
  "/app": "dashboard",
  "/app/orders": "orders",
  "/app/business-optimization": "business-optimization",
  "/app/ai-intelligence": "ai-intelligence",
  "/app/customer-feed": "customer-feed",
  "/app/credit-notes-more": "credit-notes",
  "/app/inventory": "inventory",
  "/app/backorders": "backorders",
  "/app/fill-rate": "fill-rate",
  "/app/zones": "zones",
  "/app/customers": "customers",
  "/app/sales-consultants": "sales-consultants",
  "/app/order-match": "order-match",
  "/app/mailbox": "mailbox",
  "/app/administration": "administration",
  "/app/team": "team",
  "/app/roles": "roles",
  "/app/so-imports": "so-imports",
  "/app/kp/fol": "kp-fol",
  "/app/kp/fol/calendar": "kp-fol",
  "/app/price-change-requests": "price-change-requests",
  "/app/sales-management": "sales-management",
  "/app/profile": "profile",
};

export function menuSlugForUrl(url: string): string | undefined {
  if (MENU_SLUG_BY_URL[url]) return MENU_SLUG_BY_URL[url];
  if (url.startsWith("/app/sales-consultants/")) return "sales-consultants";
  if (url.startsWith("/app/kp/fol")) return "kp-fol";
  if (url.startsWith("/app/price-change-requests")) return "price-change-requests";
  if (url.startsWith("/app/sales-management")) return "sales-management";
  return undefined;
}

export function canAccessNavItem(
  role: Role | undefined,
  url: string,
  hiddenMenus: string[] = [],
): boolean {
  if (!role) return false;

  const slug = menuSlugForUrl(url);
  if (slug && hiddenMenus.includes(slug)) return false;

  if (ALL_AUTHENTICATED_URLS.has(url)) return true;
  if (role === "Administrator") return true;

  const isCs = role === "Customer Service Manager" || role === "Customer Service Agent";
  const isCsManager = role === "Customer Service Manager";

  if (ADMIN_ONLY_URLS.has(url)) return false;
  if (ADMIN_MANAGER_URLS.has(url)) return isCsManager;
  if (CS_AND_ADMIN_URLS.has(url) && !isCs) return false;
  if (SALES_CONSULTANT_URLS.has(url)) return SALES_CONSULTANT_ROLES.includes(role);
  if (url.startsWith("/app/sales-consultants/")) return SALES_CONSULTANT_ROLES.includes(role);

  return true;
}

export function canSeeAdminTab(role: Role | undefined, tab: string): boolean {
  if (!role) return false;
  if (role === "Administrator") return true;
  if (ADMIN_ONLY_ADMIN_TABS.has(tab)) return false;
  if (ADMIN_MANAGER_ADMIN_TABS.has(tab)) return role === "Customer Service Manager";
  return role === "Customer Service Manager" || role === "Customer Service Agent";
}

export function canTriggerSync(role: Role | undefined): boolean {
  return isPrivilegedRole(role);
}

export function canSyncMailboxes(role: Role | undefined): boolean {
  return isPrivilegedRole(role);
}

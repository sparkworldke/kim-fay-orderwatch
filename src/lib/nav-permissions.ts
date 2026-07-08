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
  "/app/profile",
]);

const ADMIN_ONLY_ADMIN_TABS = new Set([
  "acumatica",
  "ai-keys",
  "data-tools",
  "roles",
  "permissions",
  "notifications",
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

export function canAccessNavItem(role: Role | undefined, url: string): boolean {
  if (!role) return false;
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

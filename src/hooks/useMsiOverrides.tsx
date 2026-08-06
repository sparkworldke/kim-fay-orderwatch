import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from "react";

export const ROLES = ["Executive Viewer", "Super Admin", "Production Manager", "COO"] as const;
export type Role = (typeof ROLES)[number];

const EDIT_ROLES: Role[] = ["Super Admin", "Production Manager", "COO"];

export interface MsiAuditEntry {
  inventoryId: string;
  productName: string;
  changedBy: Role;
  previousMsi: number;
  newMsi: number;
  reason: string;
  timestamp: string;
}

interface Ctx {
  role: Role;
  setRole: (role: Role) => void;
  canEditMsi: boolean;
  overrides: Record<string, number>;
  auditLog: MsiAuditEntry[];
  updateMsi: (args: {
    inventoryId: string;
    productName: string;
    previousMsi: number;
    newMsi: number;
    reason: string;
  }) => void;
}

const MsiContext = createContext<Ctx | null>(null);

const STORAGE_KEY = "kim-fay-msi-overrides";

export function MsiProvider({ children }: { children: ReactNode }) {
  const [role, setRole] = useState<Role>("Executive Viewer");
  const [overrides, setOverrides] = useState<Record<string, number>>(() => {
    if (typeof window === "undefined") return {};
    try {
      return JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? "{}");
    } catch {
      return {};
    }
  });
  const [auditLog, setAuditLog] = useState<MsiAuditEntry[]>([]);

  const updateMsi: Ctx["updateMsi"] = useCallback(
    ({ inventoryId, productName, previousMsi, newMsi, reason }) => {
      setOverrides((prev) => {
        const next = { ...prev, [inventoryId]: newMsi };
        try {
          window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch {
          /* ignore */
        }
        return next;
      });
      setAuditLog((prev) => [
        {
          inventoryId,
          productName,
          changedBy: role,
          previousMsi,
          newMsi,
          reason,
          timestamp: new Date().toLocaleString("en-GB"),
        },
        ...prev,
      ]);
    },
    [role],
  );

  const value = useMemo(
    () => ({
      role,
      setRole,
      canEditMsi: EDIT_ROLES.includes(role),
      overrides,
      auditLog,
      updateMsi,
    }),
    [role, overrides, auditLog, updateMsi],
  );

  return <MsiContext.Provider value={value}>{children}</MsiContext.Provider>;
}

export function useMsi() {
  const ctx = useContext(MsiContext);
  if (!ctx) throw new Error("useMsi must be used inside MsiProvider");
  return ctx;
}
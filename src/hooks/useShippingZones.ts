import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch } from "@/lib/api";
import type { AcumaticaShippingZone, AcumaticaSyncLog } from "@/types/admin";

export function isMetroShippingZone(
  zoneId: string | null | undefined,
  description: string | null | undefined,
  region?: string | null | undefined,
): boolean {
  const normalizedRegion = (region ?? "").trim().toLowerCase();
  if (normalizedRegion === "nairobi" || normalizedRegion === "coast") {
    return true;
  }

  const haystack = `${zoneId ?? ""} ${description ?? ""}`.toLowerCase();
  if (!haystack.trim()) return false;
  return haystack.includes("nairobi") || haystack.includes("nairi") || haystack.includes("mombasa");
}

export function shippingZoneSlaLabel(
  zoneId: string | null | undefined,
  description: string | null | undefined,
  region?: string | null | undefined,
): string {
  return isMetroShippingZone(zoneId, description, region) ? "24h metro" : "48–72h regional";
}

export function shippingZoneLabel(zone: Pick<AcumaticaShippingZone, "acumatica_id" | "name" | "region" | "description">): string {
  const name = zone.name?.trim();
  const region = zone.region?.trim();

  if (name && region) {
    return `${zone.acumatica_id} — ${name} (${region})`;
  }

  if (name) {
    return `${zone.acumatica_id} — ${name}`;
  }

  if (zone.description?.trim()) {
    return `${zone.acumatica_id} — ${zone.description}`;
  }

  return zone.acumatica_id;
}

export function useShippingZones() {
  return useQuery({
    queryKey: ["shipping-zones"],
    queryFn: () => apiFetch<AcumaticaShippingZone[]>("customers/shipping-zones"),
  });
}

export function useSyncShippingZones() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () =>
      apiFetch<{ sync_run: AcumaticaSyncLog }>("admin/acumatica/sync/shipping-zones", { method: "POST" }),
    onSuccess: (result) => {
      const run = result.sync_run;
      if (run.status === "completed") {
        const source = typeof run.filters === "object" && run.filters && "source" in run.filters
          ? String((run.filters as { source?: string }).source ?? "master")
          : "master";
        toast.success(`Zone sync complete (${source}) — ${run.success_count}/${run.record_count} synced`);
        if (run.error_message) {
          toast.warning(run.error_message);
        }
      } else if (run.status === "stopped") {
        toast.warning(run.error_message ?? "Zone sync stopped.");
      } else {
        toast.error(`Zone sync failed: ${run.error_message ?? "Unknown error"}`);
      }
      queryClient.invalidateQueries({ queryKey: ["shipping-zones"] });
      queryClient.invalidateQueries({ queryKey: ["customers"] });
      queryClient.invalidateQueries({ queryKey: ["admin", "sync-logs"] });
    },
    onError: (error: Error) => toast.error(error.message),
  });
}
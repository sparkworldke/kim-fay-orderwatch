import { Link, createFileRoute } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import { Gauge, MapPin, RefreshCw, Search, Users } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useCustomers } from "@/hooks/useCustomers";
import {
  isMetroShippingZone,
  shippingZoneLabel,
  shippingZoneSlaLabel,
  useShippingZones,
  useSyncShippingZones,
} from "@/hooks/useShippingZones";
import { canTriggerSync } from "@/lib/nav-permissions";
import { useAuth } from "@/lib/auth";
import type { AcumaticaShippingZone } from "@/types/admin";

export const Route = createFileRoute("/app/zones")({
  head: () => ({ meta: [{ title: "Zones — Kim-Fay OrderWatch" }] }),
  component: ZonesPage,
});

function ZonesPage() {
  const { session } = useAuth();
  const [search, setSearch] = useState("");
  const [selectedZone, setSelectedZone] = useState<AcumaticaShippingZone | null>(null);

  const { data: zones = [], isLoading, error, refetch, isFetching } = useShippingZones();
  const syncZones = useSyncShippingZones();
  const canSync = canTriggerSync(session?.role);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return zones;
    return zones.filter((zone) =>
      zone.acumatica_id.toLowerCase().includes(q)
      || (zone.name ?? "").toLowerCase().includes(q)
      || (zone.region ?? "").toLowerCase().includes(q)
      || (zone.description ?? "").toLowerCase().includes(q),
    );
  }, [zones, search]);

  const stats = useMemo(() => {
    const metro = zones.filter((z) => isMetroShippingZone(z.acumatica_id, z.description, z.region)).length;
    const customersAssigned = zones.reduce((sum, z) => sum + (z.customer_count ?? 0), 0);
    return {
      total: zones.length,
      metro,
      regional: zones.length - metro,
      customersAssigned,
    };
  }, [zones]);

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Zones</h1>
          <p className="text-sm text-muted-foreground">
            Acumatica shipping zones used for customer routing and Fill Rate delivery SLA.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => refetch()} disabled={isFetching}>
            <RefreshCw className={`mr-2 h-4 w-4 ${isFetching ? "animate-spin" : ""}`} />
            Refresh
          </Button>
          {canSync && (
            <Button size="sm" onClick={() => syncZones.mutate()} disabled={syncZones.isPending}>
              <RefreshCw className={`mr-2 h-4 w-4 ${syncZones.isPending ? "animate-spin" : ""}`} />
              Sync from Acumatica
            </Button>
          )}
        </div>
      </div>

      {error && (
        <div className="rounded border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {error instanceof Error ? error.message : "Unable to load shipping zones."}
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total zones" value={stats.total} loading={isLoading} />
        <StatCard label="Metro SLA (24h)" value={stats.metro} loading={isLoading} accent="metro" />
        <StatCard label="Regional SLA (48–72h)" value={stats.regional} loading={isLoading} accent="regional" />
        <StatCard label="Customers assigned" value={stats.customersAssigned} loading={isLoading} />
      </div>

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="flex flex-col gap-3 pb-3 sm:flex-row sm:items-center sm:justify-between">
          <CardTitle className="text-base">Zone directory</CardTitle>
          <div className="relative w-full sm:max-w-xs">
            <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search zone ID, name, or region…"
              className="pl-9"
            />
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : filtered.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-12 text-center text-sm text-muted-foreground">
              <MapPin className="h-8 w-8 opacity-40" />
              <p>{zones.length === 0 ? "No zones synced yet." : "No zones match your search."}</p>
              {canSync && zones.length === 0 && (
                <Button size="sm" variant="outline" onClick={() => syncZones.mutate()} disabled={syncZones.isPending}>
                  Run zone sync
                </Button>
              )}
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Zone ID</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Region</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Delivery SLA</TableHead>
                  <TableHead className="text-right">Customers</TableHead>
                  <TableHead>Fill rate</TableHead>
                  <TableHead>Last synced</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filtered.map((zone) => {
                  const metro = isMetroShippingZone(zone.acumatica_id, zone.description, zone.region);
                  return (
                    <TableRow
                      key={zone.acumatica_id}
                      className="cursor-pointer"
                      onClick={() => setSelectedZone(zone)}
                    >
                      <TableCell className="font-mono text-sm">{zone.acumatica_id}</TableCell>
                      <TableCell>{zone.name ?? <span className="text-muted-foreground">—</span>}</TableCell>
                      <TableCell>
                        {zone.region ? (
                          <Badge variant="outline">{zone.region}</Badge>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell>{zone.description ?? <span className="text-muted-foreground">—</span>}</TableCell>
                      <TableCell>
                        <Badge variant={metro ? "default" : "secondary"}>
                          {shippingZoneSlaLabel(zone.acumatica_id, zone.description, zone.region)}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {zone.customer_count ?? 0}
                      </TableCell>
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <Button variant="ghost" size="sm" className="h-8 px-2" asChild>
                          <Link
                            to="/app/fill-rate"
                            search={{ shipping_zone_id: zone.acumatica_id }}
                          >
                            <Gauge className="mr-1.5 h-3.5 w-3.5" />
                            View orders
                          </Link>
                        </Button>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {zone.synced_at ? formatDate(zone.synced_at) : "—"}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <ZoneCustomersSheet zone={selectedZone} onClose={() => setSelectedZone(null)} />
    </div>
  );
}

function ZoneCustomersSheet({
  zone,
  onClose,
}: {
  zone: AcumaticaShippingZone | null;
  onClose: () => void;
}) {
  const { data, isLoading } = useCustomers({
    shipping_zone_id: zone?.acumatica_id,
    per_page: 50,
    page: 1,
  });

  const customers = data?.data ?? [];

  return (
    <Sheet open={zone !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>{zone ? shippingZoneLabel(zone) : "Zone"}</SheetTitle>
          <SheetDescription>
            {zone?.description ?? "No Acumatica description"}
            {" · "}
            {shippingZoneSlaLabel(zone?.acumatica_id, zone?.description, zone?.region)}
          </SheetDescription>
        </SheetHeader>

        <div className="mt-6 space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Users className="h-4 w-4" />
              {zone?.customer_count ?? 0} customers in this zone
            </div>
            {zone && (
              <Button variant="outline" size="sm" asChild>
                <Link to="/app/fill-rate" search={{ shipping_zone_id: zone.acumatica_id }} onClick={onClose}>
                  <Gauge className="mr-1.5 h-3.5 w-3.5" />
                  Fill rate orders
                </Link>
              </Button>
            )}
          </div>

          {isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : customers.length === 0 ? (
            <p className="text-sm text-muted-foreground">No customers assigned to this zone yet.</p>
          ) : (
            <ul className="divide-y rounded-md border">
              {customers.map((customer) => (
                <li key={customer.acumatica_id} className="px-3 py-2.5">
                  <Link
                    to="/app/customers"
                    className="text-sm font-medium hover:underline"
                    onClick={onClose}
                  >
                    {customer.name}
                  </Link>
                  <p className="font-mono text-xs text-muted-foreground">{customer.acumatica_id}</p>
                </li>
              ))}
            </ul>
          )}

          {(data?.total ?? 0) > customers.length && (
            <p className="text-xs text-muted-foreground">
              Showing {customers.length} of {data?.total} customers.
            </p>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function StatCard({
  label,
  value,
  loading,
  accent,
}: {
  label: string;
  value: number;
  loading: boolean;
  accent?: "metro" | "regional";
}) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardContent className="flex flex-col gap-1 p-4">
        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</span>
        {loading ? (
          <Skeleton className="h-8 w-16" />
        ) : (
          <span
            className={`text-2xl font-semibold tabular-nums ${
              accent === "metro" ? "text-primary" : accent === "regional" ? "text-foreground" : ""
            }`}
          >
            {value.toLocaleString()}
          </span>
        )}
      </CardContent>
    </Card>
  );
}

function formatDate(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
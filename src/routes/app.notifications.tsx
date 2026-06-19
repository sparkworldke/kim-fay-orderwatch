import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { toast } from "sonner";
import { AlertTriangle, BellRing, ServerCog, Wallet } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { NOTIFICATIONS } from "@/lib/demo-data";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/notifications")({
  head: () => ({ meta: [{ title: "Notifications — Kim-Fay OrderWatch" }] }),
  component: NotificationsPage,
});

const ICONS = {
  Alert: AlertTriangle,
  Escalation: BellRing,
  "Revenue Risk": Wallet,
  System: ServerCog,
} as const;

function NotificationsPage() {
  const [items, setItems] = useState(NOTIFICATIONS);
  const [filter, setFilter] = useState<string>("all");

  const filtered = filter === "all" ? items : items.filter((n) => n.category === filter);
  const unread = items.filter((n) => !n.read).length;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Notifications</h1>
          <p className="text-sm text-muted-foreground">{unread} unread · alerts, escalations and revenue risk events.</p>
        </div>
        <Button size="sm" variant="outline" onClick={() => { setItems(items.map((i) => ({ ...i, read: true }))); toast.success("Marked all as read"); }}>
          Mark all as read
        </Button>
      </div>

      <Tabs value={filter} onValueChange={setFilter}>
        <TabsList>
          <TabsTrigger value="all">All</TabsTrigger>
          <TabsTrigger value="Alert">Alerts</TabsTrigger>
          <TabsTrigger value="Escalation">Escalations</TabsTrigger>
          <TabsTrigger value="Revenue Risk">Revenue Risk</TabsTrigger>
          <TabsTrigger value="System">System</TabsTrigger>
        </TabsList>
      </Tabs>

      <div className="rounded-lg border bg-card shadow-[var(--shadow-panel)]">
        <ul className="divide-y">
          {filtered.length === 0 && (
            <li className="px-4 py-10 text-center text-sm text-muted-foreground">No notifications.</li>
          )}
          {filtered.map((n) => {
            const Icon = ICONS[n.category as keyof typeof ICONS] ?? BellRing;
            return (
              <li
                key={n.id}
                className={cn(
                  "flex items-start gap-3 px-4 py-3 cursor-pointer hover:bg-muted/40",
                  !n.read && "bg-primary/5",
                )}
                onClick={() => setItems(items.map((i) => (i.id === n.id ? { ...i, read: true } : i)))}
              >
                <div className={cn("mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md", n.category === "Alert" || n.category === "Escalation" ? "bg-destructive/10 text-destructive" : n.category === "Revenue Risk" ? "bg-warning/15 text-warning-foreground" : "bg-info/10 text-info")}>
                  <Icon className="h-3.5 w-3.5" />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] uppercase tracking-wide text-muted-foreground">{n.category}</span>
                    {!n.read && <span className="h-1.5 w-1.5 rounded-full bg-primary" />}
                  </div>
                  <div className="text-sm font-medium">{n.title}</div>
                </div>
                <span className="font-mono text-xs text-muted-foreground">{n.time}</span>
              </li>
            );
          })}
        </ul>
      </div>
    </div>
  );
}

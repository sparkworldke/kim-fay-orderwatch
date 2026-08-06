import { createFileRoute, Link } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import { CalendarDays, ChevronLeft, ChevronRight, ExternalLink, RefreshCw } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useKpCalendar, type KpCalendarEvent } from "@/hooks/useKpCrm";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/kp/calendar")({
  head: () => ({ meta: [{ title: "KP Calendar - Kim-Fay Sight" }] }),
  component: KpCalendarPage,
});

function monthKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function parseMonth(ym: string): Date {
  const [y, m] = ym.split("-").map(Number);
  return new Date(y, (m || 1) - 1, 1);
}

function KpCalendarPage() {
  const [month, setMonth] = useState(() => monthKey(new Date()));
  const cal = useKpCalendar(month);
  const monthDate = parseMonth(month);

  const daysInMonth = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();
  const startWeekday = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1).getDay(); // 0 Sun

  const eventsByDay = useMemo(() => {
    const map = new Map<number, KpCalendarEvent[]>();
    for (const ev of cal.data?.events ?? []) {
      if (!ev.starts_at) continue;
      const d = new Date(ev.starts_at);
      if (d.getMonth() !== monthDate.getMonth() || d.getFullYear() !== monthDate.getFullYear()) continue;
      const day = d.getDate();
      const list = map.get(day) ?? [];
      list.push(ev);
      map.set(day, list);
    }
    return map;
  }, [cal.data?.events, monthDate]);

  function shiftMonth(delta: number) {
    const d = parseMonth(month);
    d.setMonth(d.getMonth() + delta);
    setMonth(monthKey(d));
  }

  const cells: Array<number | null> = [
    ...Array.from({ length: startWeekday }, () => null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];
  while (cells.length % 7 !== 0) cells.push(null);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">KP CRM</p>
          <h1 className="text-xl font-semibold tracking-tight">Calendar</h1>
          <p className="text-sm text-muted-foreground">
            OrderWatch meetings plus Outlook calendar events (when mailbox is connected with calendar access).
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button asChild size="sm" variant="outline">
            <Link to="/app/kp/meetings">Manage meetings</Link>
          </Button>
          <Button size="sm" variant="outline" onClick={() => cal.refetch()} disabled={cal.isFetching}>
            <RefreshCw className={`mr-1 h-3.5 w-3.5 ${cal.isFetching ? "animate-spin" : ""}`} />
            Sync
          </Button>
        </div>
      </div>

      {cal.data?.outlook && (
        <div
          className={cn(
            "rounded-md border px-3 py-2 text-xs",
            cal.data.outlook.error
              ? "border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
              : "bg-muted/30 text-muted-foreground",
          )}
        >
          <div className="flex flex-wrap items-center gap-2">
            <CalendarDays className="h-3.5 w-3.5" />
            {cal.data.outlook.connected ? (
              <span>
                Outlook: <strong>{cal.data.outlook.mailbox}</strong>
                {cal.data.outlook.error ? ` — ${cal.data.outlook.error}` : " · events loaded"}
              </span>
            ) : (
              <span>Outlook not connected</span>
            )}
            <Badge variant="outline" className="text-[10px]">
              {(cal.data.events ?? []).filter((e) => e.source === "outlook").length} Outlook
            </Badge>
            <Badge variant="secondary" className="text-[10px]">
              {(cal.data.events ?? []).filter((e) => e.source === "orderwatch").length} OrderWatch
            </Badge>
          </div>
          {cal.data.outlook.hint && <p className="mt-1">{cal.data.outlook.hint}</p>}
        </div>
      )}

      <div className="flex items-center justify-between gap-2">
        <Button size="icon" variant="outline" className="h-8 w-8" onClick={() => shiftMonth(-1)}>
          <ChevronLeft className="h-4 w-4" />
        </Button>
        <h2 className="text-base font-semibold">
          {monthDate.toLocaleString(undefined, { month: "long", year: "numeric" })}
        </h2>
        <Button size="icon" variant="outline" className="h-8 w-8" onClick={() => shiftMonth(1)}>
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>

      {cal.isLoading ? (
        <Skeleton className="h-96 w-full" />
      ) : (
        <div className="overflow-hidden rounded-lg border bg-card">
          <div className="grid grid-cols-7 border-b bg-muted/40 text-center text-[11px] font-semibold uppercase text-muted-foreground">
            {["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].map((d) => (
              <div key={d} className="px-1 py-2">
                {d}
              </div>
            ))}
          </div>
          <div className="grid grid-cols-7">
            {cells.map((day, idx) => {
              const events = day ? eventsByDay.get(day) ?? [] : [];
              return (
                <div
                  key={idx}
                  className={cn(
                    "min-h-[92px] border-b border-r p-1.5 align-top",
                    !day && "bg-muted/10",
                    day === new Date().getDate() &&
                      month === monthKey(new Date()) &&
                      "bg-primary/5",
                  )}
                >
                  {day && (
                    <>
                      <div className="mb-1 text-[11px] font-semibold tabular-nums text-muted-foreground">{day}</div>
                      <div className="space-y-0.5">
                        {events.slice(0, 3).map((ev) => (
                          <div
                            key={ev.id}
                            className={cn(
                              "truncate rounded px-1 py-0.5 text-[10px] leading-tight",
                              ev.source === "outlook"
                                ? "bg-sky-100 text-sky-900 dark:bg-sky-950/50 dark:text-sky-100"
                                : "bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100",
                            )}
                            title={ev.title}
                          >
                            {ev.source === "outlook" && ev.web_link ? (
                              <a href={ev.web_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 hover:underline">
                                {ev.title}
                                <ExternalLink className="h-2.5 w-2.5" />
                              </a>
                            ) : (
                              ev.title
                            )}
                          </div>
                        ))}
                        {events.length > 3 && (
                          <div className="text-[10px] text-muted-foreground">+{events.length - 3} more</div>
                        )}
                      </div>
                    </>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      <div className="space-y-2">
        <h3 className="text-sm font-semibold">Events this month</h3>
        <div className="divide-y rounded-lg border bg-card">
          {(cal.data?.events ?? []).length === 0 && (
            <p className="px-4 py-8 text-center text-sm text-muted-foreground">No events in this month.</p>
          )}
          {(cal.data?.events ?? []).map((ev) => (
            <div key={ev.id} className="flex flex-wrap items-start justify-between gap-2 px-4 py-3 text-sm">
              <div>
                <div className="font-medium">{ev.title}</div>
                <div className="text-xs text-muted-foreground">
                  {ev.starts_at ? new Date(ev.starts_at).toLocaleString() : "—"}
                  {ev.location ? ` · ${ev.location}` : ""}
                  {ev.customer_name ? ` · ${ev.customer_name}` : ""}
                </div>
              </div>
              <Badge variant={ev.source === "outlook" ? "outline" : "secondary"} className="text-[10px]">
                {ev.source}
              </Badge>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

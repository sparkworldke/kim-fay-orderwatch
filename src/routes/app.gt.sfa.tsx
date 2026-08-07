import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Activity, DatabaseZap, Link2, MapPin } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { apiFetch } from "@/lib/api";

export const Route = createFileRoute("/app/gt/sfa")({
  head: () => ({ meta: [{ title: "GT SFA Data - Kim-Fay Sight" }] }),
  component: SfaDataPage,
});

function SfaDataPage() {
  const { data, isLoading, isError } = useQuery({ queryKey: ["sfa-dashboard", "GT"], queryFn: () => apiFetch<SfaDashboard>("sfa/dashboard"), refetchInterval: 60_000 });
  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-2xl font-semibold">SFA Data</h1><p className="text-sm text-muted-foreground">General Trade field activity reconciled with Sight ERP orders.</p></div>
        <Badge variant="secondary">GT only</Badge>
      </div>
      <div className="grid gap-3 md:grid-cols-3">
        <StatusCard icon={Activity} title="Field visits" text={isLoading ? "Loading…" : `${data?.summary.visits ?? 0} visits today`} />
        <StatusCard icon={Link2} title="Successful visits" text={isLoading ? "Loading…" : `${data?.summary.successful_visits ?? 0} productive visits`} />
        <StatusCard icon={MapPin} title="GT representatives" text={isLoading ? "Loading…" : `${data?.summary.reps ?? 0} synced representatives`} />
      </div>
      <Card className={data?.performances.length ? "" : "border-dashed"}><CardContent className="flex min-h-64 flex-col items-center justify-center text-center">
        <DatabaseZap className="mb-4 h-10 w-10 text-muted-foreground" />
        {isLoading ? <Skeleton className="h-20 w-full max-w-xl" /> : <><h2 className="font-semibold">{isError ? "SFA data is unavailable" : data?.performances.length ? `GT activity for ${data.date}` : "Waiting for today’s field activity"}</h2>
        <p className="mt-2 max-w-xl text-sm text-muted-foreground">{isError ? "Please ask an administrator to check the SFA connection." : `Sales captured: KES ${(data?.summary.sales ?? 0).toLocaleString()}. MT data remains hidden until its team view is launched.`}</p></>}
      </CardContent></Card>
    </div>
  );
}

type SfaDashboard = { team: "GT"; date: string; summary: { reps: number; visits: number; successful_visits: number; sales: number }; performances: unknown[] };

function StatusCard({ icon: Icon, title, text }: { icon: typeof Activity; title: string; text: string }) {
  return <Card><CardHeader className="pb-2"><CardTitle className="flex items-center gap-2 text-base"><Icon className="h-4 w-4" />{title}</CardTitle></CardHeader><CardContent className="text-sm text-muted-foreground">{text}</CardContent></Card>;
}

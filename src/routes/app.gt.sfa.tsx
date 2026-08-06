import { createFileRoute } from "@tanstack/react-router";
import { Activity, DatabaseZap, Link2, MapPin } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

export const Route = createFileRoute("/app/gt/sfa")({
  head: () => ({ meta: [{ title: "GT SFA Data - Kim-Fay Sight" }] }),
  component: SfaDataPage,
});

function SfaDataPage() {
  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-2xl font-semibold">SFA Data</h1><p className="text-sm text-muted-foreground">General Trade field activity reconciled with Sight ERP orders.</p></div>
        <Badge variant="secondary">Integration ready</Badge>
      </div>
      <div className="grid gap-3 md:grid-cols-3">
        <StatusCard icon={Activity} title="Field visits" text="Visits and outlet coverage will appear here." />
        <StatusCard icon={Link2} title="Matched activity" text="SFA outlets and orders matched to Sight customers." />
        <StatusCard icon={MapPin} title="Coverage gaps" text="Unmatched outlets, visits and ERP order gaps." />
      </div>
      <Card className="border-dashed"><CardContent className="flex min-h-64 flex-col items-center justify-center text-center">
        <DatabaseZap className="mb-4 h-10 w-10 text-muted-foreground" />
        <h2 className="font-semibold">Waiting for the SFA connection</h2>
        <p className="mt-2 max-w-xl text-sm text-muted-foreground">SFA data will appear here once connected. The integration contract supports visits, outlet identifiers, representative identity, captured orders and match status.</p>
      </CardContent></Card>
    </div>
  );
}

function StatusCard({ icon: Icon, title, text }: { icon: typeof Activity; title: string; text: string }) {
  return <Card><CardHeader className="pb-2"><CardTitle className="flex items-center gap-2 text-base"><Icon className="h-4 w-4" />{title}</CardTitle></CardHeader><CardContent className="text-sm text-muted-foreground">{text}</CardContent></Card>;
}

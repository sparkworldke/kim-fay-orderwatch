import { createFileRoute } from "@tanstack/react-router";
import { DtcCalltronixPage } from "@/components/dtc-calltronix-page";
export const Route = createFileRoute("/app/kp/dtc-calltronix/quotes")({
  head: () => ({ meta: [{ title: "DTC Quotes - OrderWatch" }] }),
  component: () => <DtcCalltronixPage page="quotes" />,
});

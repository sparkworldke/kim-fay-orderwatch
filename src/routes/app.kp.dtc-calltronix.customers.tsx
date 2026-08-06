import { createFileRoute } from "@tanstack/react-router";
import { DtcCalltronixPage } from "@/components/dtc-calltronix-page";
export const Route = createFileRoute("/app/kp/dtc-calltronix/customers")({
  head: () => ({ meta: [{ title: "DTC Customers - OrderWatch" }] }),
  component: () => <DtcCalltronixPage page="customers" />,
});

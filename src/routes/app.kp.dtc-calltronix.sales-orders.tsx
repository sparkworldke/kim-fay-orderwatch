import { createFileRoute } from "@tanstack/react-router";
import { DtcCalltronixPage } from "@/components/dtc-calltronix-page";
export const Route = createFileRoute("/app/kp/dtc-calltronix/sales-orders")({
  head: () => ({ meta: [{ title: "DTC Sales Orders - OrderWatch" }] }),
  component: () => <DtcCalltronixPage page="sales-orders" />,
});

import { createFileRoute } from "@tanstack/react-router";
import { DtcCalltronixPage } from "@/components/dtc-calltronix-page";
export const Route = createFileRoute("/app/kp/dtc-calltronix/price-list")({
  head: () => ({ meta: [{ title: "DTC Price List - OrderWatch" }] }),
  component: () => <DtcCalltronixPage page="price-list" />,
});

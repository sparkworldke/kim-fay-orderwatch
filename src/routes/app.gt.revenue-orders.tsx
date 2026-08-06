import { createFileRoute } from "@tanstack/react-router";
import { SalesIntelligenceView } from "@/routes/app.sales-intelligence";

export const Route = createFileRoute("/app/gt/revenue-orders")({
  head: () => ({ meta: [{ title: "GT Revenue and Orders - Kim-Fay Sight" }] }),
  component: () => <SalesIntelligenceView channel="GT" />,
});

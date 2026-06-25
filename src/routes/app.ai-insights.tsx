import { createFileRoute, redirect } from "@tanstack/react-router";

export const Route = createFileRoute("/app/ai-insights")({
  beforeLoad: () => {
    throw redirect({ to: "/app/ai-intelligence" });
  },
});
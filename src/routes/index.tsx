import { createFileRoute, redirect } from "@tanstack/react-router";

export const Route = createFileRoute("/")({
  beforeLoad: () => {
    if (typeof window !== "undefined") {
      const session = window.localStorage.getItem("kf_session");
      throw redirect({ to: session ? "/app" : "/auth" });
    }
    throw redirect({ to: "/auth" });
  },
});


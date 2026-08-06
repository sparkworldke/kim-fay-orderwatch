import { QueryClient } from "@tanstack/react-query";
import { createRouter } from "@tanstack/react-router";
import { routeTree } from "./routeTree.gen";
import { ApiError } from "./lib/api";

export const getRouter = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        // Don't retry on 401 — the token is genuinely invalid and retrying just
        // fires more auth checks. Any other error (5xx, network) retries once.
        retry: (failureCount, error) => {
          if (error instanceof ApiError && error.status === 401) return false;
          return failureCount < 1;
        },
        // Keep cached data fresh for 60 s by default so filter changes don't
        // immediately re-fetch data that was just loaded.
        staleTime: 60_000,
      },
    },
  });

  const router = createRouter({
    routeTree,
    context: { queryClient },
    scrollRestoration: true,
    defaultPreloadStaleTime: 0,
  });

  return router;
};

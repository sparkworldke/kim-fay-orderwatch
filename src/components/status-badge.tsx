import { cn } from "@/lib/utils";
import type { OrderStatus, SLAStatus, Priority, ApprovalStatus } from "@/lib/demo-data";

// Get consistent status styles for any status string
export function getStatusStyle(status: string): string {
  const s = status.toLowerCase();
  // Green: Complete / Completed / Active / Approved
  if (s.includes("complete") || s === "active" || s === "approved") {
    return "bg-success/15 text-success border-success/30 dark:bg-success/20 dark:border-success/40";
  }
  // Red: Disabled / Deleted / Missing / Escalated / Rejected / Inactive / Breached
  if (s.includes("disable") || s.includes("delete") || s.includes("missing") || s.includes("escalate") || s === "rejected" || s === "inactive" || s === "breached") {
    return "bg-destructive/15 text-destructive border-destructive/30 dark:bg-destructive/20 dark:border-destructive/40";
  }
  // Yellow/Orange: Warning, Delayed, On Hold, In Review
  if (s.includes("warning") || s.includes("delay") || s.includes("hold") || s.includes("review")) {
    return "bg-warning/15 text-warning-foreground border-warning/40 dark:bg-warning/20 dark:border-warning/50";
  }
  // Blue: Info, Duplicate, Matched, Pending
  if (s.includes("info") || s === "duplicate" || s === "matched" || s === "pending") {
    return "bg-info/15 text-info border-info/30 dark:bg-info/20 dark:border-info/40";
  }
  // Default
  return "bg-muted text-muted-foreground border-border dark:bg-muted/50";
}

const STATUS_STYLES: Record<OrderStatus, string> = {
  Matched: "bg-success/15 text-success border-success/30",
  Missing: "bg-destructive/15 text-destructive border-destructive/30",
  Delayed: "bg-warning/15 text-warning-foreground border-warning/40",
  Duplicate: "bg-info/15 text-info border-info/30",
  Escalated: "bg-destructive/20 text-destructive border-destructive/40",
};

const SLA_STYLES: Record<SLAStatus, string> = {
  "On Track": "bg-success/15 text-success border-success/30",
  Warning: "bg-warning/15 text-warning-foreground border-warning/40",
  Breached: "bg-destructive/15 text-destructive border-destructive/30",
};

const PRIORITY_STYLES: Record<Priority, string> = {
  Low: "bg-muted text-muted-foreground border-border",
  Medium: "bg-info/15 text-info border-info/30",
  High: "bg-warning/15 text-warning-foreground border-warning/40",
  Critical: "bg-destructive/15 text-destructive border-destructive/30",
};

export function StatusBadge({ status }: { status: OrderStatus | string }) {
  return (
    <span className={cn("inline-flex items-center rounded border px-1.5 py-0.5 text-[11px] font-medium", typeof status === 'string' ? getStatusStyle(status) : STATUS_STYLES[status])}>
      {status}
    </span>
  );
}

export function SlaBadge({ status }: { status: SLAStatus }) {
  return (
    <span className={cn("inline-flex items-center rounded border px-1.5 py-0.5 text-[11px] font-medium", SLA_STYLES[status])}>
      {status}
    </span>
  );
}

export function PriorityBadge({ priority }: { priority: Priority }) {
  return (
    <span className={cn("inline-flex items-center rounded border px-1.5 py-0.5 text-[11px] font-medium", PRIORITY_STYLES[priority])}>
      {priority}
    </span>
  );
}

const APPROVAL_STYLES: Record<ApprovalStatus, string> = {
  Pending: "bg-muted text-muted-foreground border-border",
  "In Review": "bg-info/15 text-info border-info/30",
  Approved: "bg-success/15 text-success border-success/30",
  Rejected: "bg-destructive/15 text-destructive border-destructive/30",
};

export function ApprovalBadge({ status }: { status: ApprovalStatus }) {
  return (
    <span className={cn("inline-flex items-center rounded border px-1.5 py-0.5 text-[11px] font-medium", APPROVAL_STYLES[status])}>
      {status}
    </span>
  );
}

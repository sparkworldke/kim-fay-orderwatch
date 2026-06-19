import { cn } from "@/lib/utils";
import type { OrderStatus, SLAStatus, Priority, ApprovalStatus } from "@/lib/demo-data";

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

export function StatusBadge({ status }: { status: OrderStatus }) {
  return (
    <span className={cn("inline-flex items-center rounded border px-1.5 py-0.5 text-[11px] font-medium", STATUS_STYLES[status])}>
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

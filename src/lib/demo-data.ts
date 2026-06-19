// Deterministic seeded demo data for Kim-Fay OrderWatch.

function mulberry32(seed: number) {
  let a = seed;
  return function () {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const rnd = mulberry32(20260618);
const pick = <T,>(arr: readonly T[]) => arr[Math.floor(rnd() * arr.length)]!;
const range = (min: number, max: number) => min + Math.floor(rnd() * (max - min + 1));

export const CUSTOMERS = [
  { name: "Naivas", code: "NVS", sla: 4 },
  { name: "Quickmart", code: "QM", sla: 6 },
  { name: "Carrefour", code: "CRF", sla: 4 },
  { name: "Chandarana", code: "CHN", sla: 8 },
  { name: "Eastmatt", code: "EMT", sla: 8 },
  { name: "Magunas", code: "MGN", sla: 12 },
  { name: "Khetias", code: "KHT", sla: 12 },
  { name: "Mathai", code: "MTH", sla: 12 },
] as const;

export type OrderStatus = "Matched" | "Missing" | "Delayed" | "Duplicate" | "Escalated";
export type SLAStatus = "On Track" | "Warning" | "Breached";
export type Priority = "Low" | "Medium" | "High" | "Critical";
export type ApprovalStatus = "Pending" | "In Review" | "Approved" | "Rejected";

export interface Order {
  id: string;
  poNumber: string;
  customer: string;
  emailSubject: string;
  emailReceived: string;
  salesOrderNumber: string | null;
  orderValue: number;
  status: OrderStatus;
  slaStatus: SLAStatus;
  assignedTo: string | null;
  lastUpdated: string;
  priority: Priority;
  branch: string;
  salesperson: string;
  missingInAcumatica: boolean;
  missingEmail: boolean;
  keyedInAt: string | null;
  approvalStatus: ApprovalStatus;
  approvedAt: string | null;
}

const AGENTS = ["Wanjiru K.", "Otieno M.", "Achieng' L.", "Kamau J.", "Nyokabi P.", "Mwangi D."];
const BRANCHES = ["Imara Daima", "Nairobi CBD", "Westlands", "Mombasa Rd", "Karen", "Thika Rd", "Ruaka", "Kilimani", "Lavington", "Nakuru", "Eldoret", "Kisumu"];
const SALESPEOPLE = ["S. Kariuki", "B. Wairimu", "G. Onyango", "F. Njoroge"];

function priorityFor(value: number): Priority {
  if (value >= 1_000_000) return "Critical";
  if (value >= 250_000) return "High";
  if (value >= 50_000) return "Medium";
  return "Low";
}

function makePO() {
  // Kim-Fay PO format: P followed by 9 digits, e.g. P042534275
  return `P${String(range(10_000_000, 999_999_999)).padStart(9, "0")}`;
}

function genOrder(i: number): Order {
  const c = pick(CUSTOMERS);
  const value = [
    range(8_000, 49_000),
    range(50_000, 249_000),
    range(250_000, 999_000),
    range(1_000_000, 3_200_000),
  ][Math.min(3, Math.floor(rnd() * 4))]!;
  const ageHrs = range(0, 96);
  const received = new Date(Date.now() - ageHrs * 3600_000);
  const statusRoll = rnd();
  let status: OrderStatus;
  if (statusRoll < 0.62) status = "Matched";
  else if (statusRoll < 0.8) status = "Missing";
  else if (statusRoll < 0.9) status = "Delayed";
  else if (statusRoll < 0.96) status = "Escalated";
  else status = "Duplicate";

  const slaTarget = c.sla;
  let slaStatus: SLAStatus = "On Track";
  if (ageHrs > slaTarget * 1.5) slaStatus = "Breached";
  else if (ageHrs > slaTarget) slaStatus = "Warning";

  const matchedSO = status === "Matched" || status === "Delayed" || status === "Duplicate";
  const salesOrderNumber = matchedSO ? `SO-${range(800000, 899999)}` : null;
  const missingInAcumatica = !salesOrderNumber;
  const missingEmail = rnd() < 0.08;

  // Key-in happens after email received (15 min – 8 hours later) for matched/delayed/dup
  const keyedInAt =
    matchedSO && rnd() > 0.05
      ? new Date(received.getTime() + range(15, 480) * 60_000).toISOString()
      : null;

  // Approval flow
  let approvalStatus: ApprovalStatus = "Pending";
  let approvedAt: string | null = null;
  if (keyedInAt) {
    const aRoll = rnd();
    if (aRoll < 0.6) {
      approvalStatus = "Approved";
      approvedAt = new Date(new Date(keyedInAt).getTime() + range(10, 600) * 60_000).toISOString();
    } else if (aRoll < 0.85) {
      approvalStatus = "In Review";
    } else {
      approvalStatus = "Rejected";
    }
  }

  const poNumber = makePO();
  const branch = pick(BRANCHES);

  return {
    id: `ORD-${String(i).padStart(5, "0")}`,
    poNumber,
    customer: c.name,
    emailSubject: `Purchase order Confirmation: ${poNumber} - ${branch}`,
    emailReceived: received.toISOString(),
    salesOrderNumber,
    orderValue: value,
    status,
    slaStatus,
    assignedTo: rnd() > 0.25 ? pick(AGENTS) : null,
    lastUpdated: new Date(Date.now() - range(0, 4) * 3600_000).toISOString(),
    priority: priorityFor(value),
    branch,
    salesperson: pick(SALESPEOPLE),
    missingInAcumatica,
    missingEmail,
    keyedInAt,
    approvalStatus,
    approvedAt,
  };
}

export const ORDERS: Order[] = Array.from({ length: 248 }, (_, i) => genOrder(i + 1));

// 14-day trend
export const TREND = Array.from({ length: 14 }, (_, i) => {
  const day = new Date();
  day.setDate(day.getDate() - (13 - i));
  const received = range(85, 165);
  const captured = received - range(2, 14);
  const revenue = range(4_500_000, 12_000_000);
  const atRisk = range(300_000, 1_800_000);
  return {
    date: day.toISOString().slice(5, 10),
    received,
    captured,
    captureRate: +((captured / received) * 100).toFixed(1),
    revenue,
    revenueAtRisk: atRisk,
    sla: +(88 + rnd() * 11).toFixed(1),
  };
});

export function getKpis() {
  const today = TREND[TREND.length - 1]!;
  const yest = TREND[TREND.length - 2]!;
  const outstanding = ORDERS.filter((o) => o.status !== "Matched");
  const critical = ORDERS.filter((o) => o.priority === "Critical" && o.status !== "Matched");
  const revCaptured = ORDERS.filter((o) => o.status === "Matched").reduce((s, o) => s + o.orderValue, 0);
  const revAtRisk = outstanding.reduce((s, o) => s + o.orderValue, 0);
  const aov = Math.round(ORDERS.reduce((s, o) => s + o.orderValue, 0) / ORDERS.length);
  return {
    received: today.received,
    receivedDelta: today.received - yest.received,
    captured: today.captured,
    capturedDelta: today.captured - yest.captured,
    captureRate: today.captureRate,
    captureRateDelta: +(today.captureRate - yest.captureRate).toFixed(1),
    revenueAtRisk: revAtRisk,
    revenueCaptured: revCaptured,
    outstanding: outstanding.length,
    critical: critical.length,
    aov,
  };
}

export const ACTIVITY = [
  { time: "12:04", text: "AI insight cycle completed (12:00 run)", type: "ai" as const },
  { time: "11:52", text: "Naivas PO NVS-PO-44213 matched to SO-842117", type: "match" as const },
  { time: "11:31", text: "Escalation raised for Carrefour PO CRF-7740912", type: "escalate" as const },
  { time: "11:18", text: "Acumatica sync completed (412 sales orders)", type: "sync" as const },
  { time: "10:47", text: "Quickmart PO QM/2026/00871 flagged Missing", type: "alert" as const },
  { time: "10:22", text: "Outlook mailbox orders@kim-fay.com refreshed", type: "sync" as const },
  { time: "09:58", text: "Magunas SLA target updated to 12h by admin", type: "config" as const },
];

export const ESCALATIONS = [
  { id: "ESC-2041", po: "CRF-7740912", customer: "Carrefour", value: 1_240_000, reason: "Missing > 6h", owner: "Wanjiru K." },
  { id: "ESC-2040", po: "NVS-PO-44102", customer: "Naivas", value: 880_000, reason: "PO Customer Mismatch", owner: "Otieno M." },
  { id: "ESC-2039", po: "QM/2026/00744", customer: "Quickmart", value: 412_000, reason: "Duplicate PO", owner: "Kamau J." },
  { id: "ESC-2038", po: "CHN-PO-2210", customer: "Chandarana", value: 295_000, reason: "SLA Breached", owner: "Achieng' L." },
];

export const AI_RECOMMENDATIONS = [
  {
    title: "Reassign 3 Carrefour POs to Wanjiru K.",
    rationale: "Carrefour breach rate up 38% w/w; Wanjiru clears Carrefour 2.1× faster than team avg.",
    impact: "KES 2.4M revenue protected",
  },
  {
    title: "Tighten Naivas subject pattern",
    rationale: "12 Naivas emails failed PO extraction this week due to 'Re: Re:' prefixes.",
    impact: "+4 pts capture rate",
  },
  {
    title: "Investigate Acumatica 15:00 sync gap",
    rationale: "Last 3 days the 15:00 Acumatica sync returned 0 new orders; suspect timeout.",
    impact: "Restores hourly visibility",
  },
];

export const NOTIFICATIONS = [
  { id: 1, category: "Alert", title: "5 Critical orders pending > 4h", time: "12:14", read: false },
  { id: 2, category: "Escalation", title: "ESC-2041 escalated to manager", time: "11:31", read: false },
  { id: 3, category: "Revenue Risk", title: "Revenue at risk crossed KES 5M", time: "10:08", read: false },
  { id: 4, category: "System", title: "OpenAI insight cycle scheduled at 15:00", time: "09:02", read: true },
  { id: 5, category: "Alert", title: "Quickmart SLA warning (3 orders)", time: "08:44", read: true },
  { id: 6, category: "System", title: "Acumatica sync completed", time: "08:00", read: true },
];

export const AUDIT_LOGS = [
  { time: "12:14", actor: "admin@kim-fay.com", action: "Updated Magunas SLA target", target: "Customer Rule" },
  { time: "11:48", actor: "csm@kim-fay.com", action: "Resolved ESC-2037", target: "Escalation" },
  { time: "11:02", actor: "system", action: "Scheduled OpenAI insight run", target: "AI Insights" },
  { time: "09:33", actor: "ops@kim-fay.com", action: "Exported daily orders CSV", target: "Reports" },
  { time: "08:00", actor: "system", action: "Acumatica sync completed", target: "Integration" },
];

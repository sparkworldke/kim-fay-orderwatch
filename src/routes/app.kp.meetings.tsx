/* eslint-disable @typescript-eslint/no-explicit-any */
import { createFileRoute } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import {
  CalendarCheck,
  CheckCircle2,
  Clock3,
  Plus,
  RefreshCw,
  Search,
  Target,
  Trash2,
  Users,
  XCircle,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { usePagination } from "@/hooks/usePagination";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  useCreateKpMeeting,
  useCreateKpActivity,
  useDeleteKpMeeting,
  useKpMeetingCustomerSearch,
  useKpMeetings,
  useKpActivities,
  useKpActivitiesDashboard,
  useKpActivityFollowUps,
  useKpMeetingsDashboard,
  useKpMeetingsMeta,
  useRespondToKpMeeting,
  useSaveKpTarget,
  useUpdateKpMeeting,
  useUpdateKpActivity,
  type KpMeeting,
  type KpMeetingCustomer,
} from "@/hooks/useKpCrm";

export const Route = createFileRoute("/app/kp/meetings")({
  head: () => ({ meta: [{ title: "KP Meetings - Kim-Fay Sight" }] }),
  component: KpMeetingsPage,
});
const currentMonth = () =>
  new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Nairobi",
    year: "numeric",
    month: "2-digit",
  })
    .format(new Date())
    .slice(0, 7);
const emptyForm = {
  title: "",
  activity_type: "meeting" as "phone" | "email" | "meeting" | "visit",
  purpose_id: "",
  meeting_mode: "physical",
  is_internal: false,
  is_planned: true,
  customer_acumatica_id: "",
  customer_name: "",
  starts_at: "",
  ends_at: "",
  location: "",
  previous_notes: "",
  current_notes: "",
  outcome: "",
  follow_up_date: "",
  no_follow_up_reason: "",
  participant_user_ids: [] as number[],
  actions: [] as Array<{
    category_id: string;
    description: string;
    owner_user_id: string;
    due_date: string;
    status: "open" | "completed";
  }>,
  stakeholder_role: "",
  site_branch: "",
  opportunity_need: "",
  decision_process: "",
  budget_timeline: "",
  competitor_presence: "",
  product_fit: "",
  next_commitment: "",
  questionnaire_id: "",
  questionnaire_answers: {} as Record<string, unknown>,
};
const fmt = (v?: string | null) =>
  v
    ? new Date(v).toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "—";

function KpMeetingsPage() {
  const [view, setView] = useState<"activities" | "meetings">("activities");
  const [scope, setScope] = useState("self");
  const [activityType, setActivityType] = useState("");
  const [department, setDepartment] = useState<number | undefined>();
  const [sector, setSector] = useState("");
  const [month, setMonth] = useState(currentMonth());
  const [q, setQ] = useState("");
  const [owner, setOwner] = useState<number | undefined>();
  const [status, setStatus] = useState("");
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<KpMeeting | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [targetOpen, setTargetOpen] = useState(false);
  const [target, setTarget] = useState(0);
  const meta = useKpMeetingsMeta();
  const { page, perPage, setPage, setPerPage } = usePagination(20);
  const list = useKpMeetings({ month, q, owner_user_id: owner, status: status || undefined, page, per_page: perPage });
  const activityList = useKpActivities({ month, q, owner_user_id: owner, status: status || undefined, activity_type: activityType || undefined, department_id: department, sector: sector || undefined });
  const dashboard = useKpMeetingsDashboard({ month, owner_user_id: owner });
  const activityDashboard = useKpActivitiesDashboard({ month, scope, owner_user_id: owner, activity_type: activityType || undefined, department_id: department, sector: sector || undefined });
  const followUps = useKpActivityFollowUps(owner);
  const create = useCreateKpMeeting();
  const createActivity = useCreateKpActivity();
  const update = useUpdateKpMeeting();
  const updateActivity = useUpdateKpActivity();
  const del = useDeleteKpMeeting();
  const respond = useRespondToKpMeeting();
  const saveTarget = useSaveKpTarget();
  const meetings = (view === "activities" ? activityList.data?.data : list.data?.data) ?? [];
  const metrics: any = view === "activities" ? activityDashboard.data?.metrics : dashboard.data?.metrics;
  const purpose = meta.data?.purposes.find((p) => p.id === Number(form.purpose_id));
  function editMeeting(m: KpMeeting) {
    setEditing(m);
    setForm({
      ...emptyForm,
      title: m.title,
      activity_type: m.activity_type ?? "meeting",
      purpose_id: String(m.purpose_id ?? ""),
      meeting_mode: m.meeting_mode,
      is_internal: m.is_internal,
      is_planned: m.is_planned,
      customer_acumatica_id: m.customer_acumatica_id ?? "",
      customer_name: m.customer_name ?? "",
      starts_at: m.starts_at?.slice(0, 16) ?? "",
      ends_at: m.ends_at?.slice(0, 16) ?? "",
      location: m.location ?? "",
      previous_notes: m.previous_notes ?? "",
      current_notes: m.current_notes ?? m.notes ?? "",
      outcome: m.outcome ?? "",
      follow_up_date: m.follow_up_date?.slice(0, 10) ?? "",
      no_follow_up_reason: m.no_follow_up_reason ?? "",
      participant_user_ids:
        m.participants?.filter((p) => p.status !== "declined").map((p) => p.user_id) ?? [],
      actions:
        m.actions?.map((a) => ({
          category_id: String(a.category_id ?? ""),
          description: a.description,
          owner_user_id: String(a.owner_user_id ?? ""),
          due_date: a.due_date?.slice(0, 10) ?? "",
          status: a.status,
        })) ?? [],
      stakeholder_role: m.b2b_details?.stakeholder_role ?? "",
      site_branch: m.b2b_details?.site_branch ?? "",
      opportunity_need: m.b2b_details?.opportunity_need ?? "",
      decision_process: m.b2b_details?.decision_process ?? "",
      budget_timeline: m.b2b_details?.budget_timeline ?? "",
      competitor_presence: m.b2b_details?.competitor_presence ?? "",
      product_fit: m.b2b_details?.product_fit ?? "",
      next_commitment: m.b2b_details?.next_commitment ?? "",
      questionnaire_id: String(m.questionnaire_id ?? ""),
      questionnaire_answers: m.questionnaire_answers ?? {},
    });
    setOpen(true);
  }
  function payload(statusValue?: string) {
    return {
      title: form.title,
      activity_type: form.activity_type,
      purpose_id: Number(form.purpose_id),
      meeting_mode: form.meeting_mode as "physical" | "virtual",
      is_internal: form.is_internal,
      is_planned: form.is_planned,
      customer_acumatica_id: form.is_internal ? null : form.customer_acumatica_id,
      customer_name: form.is_internal ? null : form.customer_name || null,
      starts_at: form.starts_at,
      ends_at: form.ends_at || null,
      location: form.location || null,
      previous_notes: form.previous_notes || null,
      current_notes: form.current_notes || null,
      outcome: form.outcome || null,
      follow_up_date: form.follow_up_date || null,
      no_follow_up_reason: form.no_follow_up_reason || null,
      status: statusValue,
      participant_user_ids: form.participant_user_ids,
      actions: form.actions
        .filter((a) => a.description.trim())
        .map((a) => ({
          category_id: a.category_id ? Number(a.category_id) : null,
          description: a.description,
          owner_user_id: a.owner_user_id ? Number(a.owner_user_id) : null,
          due_date: a.due_date || null,
          status: a.status,
        })),
      b2b_details: {
        stakeholder_role: form.stakeholder_role,
        site_branch: form.site_branch,
        opportunity_need: form.opportunity_need,
        decision_process: form.decision_process,
        budget_timeline: form.budget_timeline,
        competitor_presence: form.competitor_presence,
        product_fit: form.product_fit,
        next_commitment: form.next_commitment,
      },
      questionnaire_id: form.questionnaire_id ? Number(form.questionnaire_id) : null,
      questionnaire_answers: form.questionnaire_answers,
    };
  }
  function submit(statusValue?: string) {
    const body = payload(statusValue);
    if (!body.title || !body.purpose_id || !body.starts_at) return;
    const done = () => {
      setOpen(false);
      setEditing(null);
      setForm(emptyForm);
    };
    if (view === "activities") {
      if (editing) updateActivity.mutate({ id: editing.id, ...body }, { onSuccess: done });
      else createActivity.mutate(body, { onSuccess: done });
    } else if (editing) update.mutate({ id: editing.id, ...body }, { onSuccess: done });
    else create.mutate(body, { onSuccess: done });
  }
  const pendingInvites = meetings.flatMap((m) =>
    (m.participants ?? [])
      .filter((p) => p.status === "pending" && p.user_id === meta.data?.current_user_id)
      .map((p) => ({ m, p })),
  );
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            KP Sales Execution
          </p>
          <h1 className="text-2xl font-semibold">Activities & Meetings</h1>
          <p className="text-sm text-muted-foreground">
            Record calls, emails, meetings and visits with accountable follow-through.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              list.refetch();
              activityList.refetch();
              followUps.refetch();
              dashboard.refetch();
            }}
          >
            <RefreshCw className="mr-1 h-4 w-4" />
            Refresh
          </Button>
          <Button
            size="sm"
            disabled={meta.data?.can_edit === false}
            onClick={() => {
              setEditing(null);
              setForm(emptyForm);
              setOpen(true);
            }}
          >
            <Plus className="mr-1 h-4 w-4" />
            New {view === "activities" ? "activity" : "meeting"}
          </Button>
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-card p-2">
        <div className="flex gap-1">
          <Button size="sm" variant={view === "activities" ? "default" : "ghost"} onClick={() => setView("activities")}>My Activities</Button>
          <Button size="sm" variant={view === "meetings" ? "default" : "ghost"} onClick={() => { setView("meetings"); setActivityType(""); }}>Meetings only</Button>
        </div>
        {view === "activities" && <div className="flex flex-wrap gap-2">
          <select className="h-9 rounded-md border bg-background px-3 text-sm" value={activityType} onChange={(e) => setActivityType(e.target.value)}>
            <option value="">All activity types</option><option value="phone">Phone</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="visit">Visit</option>
          </select>
          <select className="h-9 rounded-md border bg-background px-3 text-sm" value={scope} onChange={(e) => setScope(e.target.value)}>
            {(meta.data?.allowed_scopes ?? ["self"]).map((x) => <option key={x} value={x}>{x === "organization" ? "Executive · Organization" : x[0].toUpperCase() + x.slice(1)}</option>)}
          </select>
          {scope === "organization" && <select className="h-9 rounded-md border bg-background px-3 text-sm" value={department ?? ""} onChange={(e) => setDepartment(e.target.value ? Number(e.target.value) : undefined)}><option value="">All departments</option>{meta.data?.departments?.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>}
          {scope === "organization" && <select className="h-9 rounded-md border bg-background px-3 text-sm" value={sector} onChange={(e) => setSector(e.target.value)}><option value="">All sectors</option>{meta.data?.sectors?.map((x) => <option key={x}>{x}</option>)}</select>}
        </div>}
      </div>
      <div className="flex flex-wrap gap-2">
        <Input
          type="month"
          className="w-40"
          value={month}
          onChange={(e) => setMonth(e.target.value)}
        />
        <Input
          className="max-w-xs"
          placeholder="Search customer or activity"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <select
          className="h-9 rounded-md border bg-background px-3 text-sm"
          value={owner ?? ""}
          onChange={(e) => setOwner(e.target.value ? Number(e.target.value) : undefined)}
        >
          <option value="">My/default scope</option>
          {meta.data?.consultants.map((x) => (
            <option key={x.id} value={x.id}>
              {x.name}
            </option>
          ))}
        </select>
        <select
          className="h-9 rounded-md border bg-background px-3 text-sm"
          value={status}
          onChange={(e) => setStatus(e.target.value)}
        >
          <option value="">All statuses</option>
          <option value="scheduled">Scheduled</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
        {meta.data?.is_admin && (
          <Button
            variant="outline"
            onClick={() => {
              setTarget(metrics?.target ?? 0);
              setTargetOpen(true);
            }}
          >
            Set target
          </Button>
        )}
      </div>
      {pendingInvites.length > 0 && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">
          <div className="mb-2 font-medium">Accompaniment invitations</div>
          {pendingInvites.map(({ m, p }) => (
            <div key={p.id} className="flex items-center justify-between gap-3 py-1">
              <span>
                {m.title} · {fmt(m.starts_at)}
              </span>
              <span className="flex gap-1">
                <Button size="sm" onClick={() => respond.mutate({ id: p.id, status: "accepted" })}>
                  Accept
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => respond.mutate({ id: p.id, status: "declined" })}
                >
                  Decline
                </Button>
              </span>
            </div>
          ))}
        </div>
      )}
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Stat
          icon={Target}
          label="Target vs achieved"
          value={`${metrics?.achieved ?? 0} / ${metrics?.target ?? 0}`}
          sub={`${metrics?.unique_customers ?? 0} unique customers`}
        />
        <Stat
          icon={CalendarCheck}
          label="Plan adherence"
          value={`${metrics?.adherence_percent ?? 0}%`}
          sub={`${metrics?.completed_planned ?? 0} completed · ${metrics?.missed ?? 0} missed`}
        />
        <Stat
          icon={Users}
          label="Repeat visits"
          value={String(metrics?.repeat_visits ?? 0)}
          sub={`${metrics?.unplanned ?? 0} unplanned visits`}
        />
        <Stat
          icon={Clock3}
          label="Meetings this month"
          value={String(metrics?.total ?? 0)}
          sub={`${metrics?.cancelled ?? 0} cancelled`}
        />
      </div>
      {view === "activities" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel title="Follow-up queue"><FollowUpList data={followUps.data} /></Panel>
          <Panel title={scope === "organization" ? "Executive organization overview" : "Team adherence"}>
            <div className="space-y-2">
              {(activityDashboard.data?.departments ?? []).map((x: any) => (
                <div key={x.name} className="flex items-center justify-between rounded-md border p-2 text-sm">
                  <span>{x.name}</span><span>{x.completed}/{x.planned} · {x.adherence_percent}%</span>
                </div>
              ))}
              {!activityDashboard.data?.departments.length && <Empty />}
            </div>
          </Panel>
        </div>
      )}
      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title={`${view === "activities" ? "Activity" : "Meeting"} split by purpose`}>
          {(view === "activities" ? activityDashboard.data?.purpose_split : dashboard.data?.purpose_split)?.length ? (
            <div className="space-y-2">
              {(view === "activities" ? activityDashboard.data?.purpose_split : dashboard.data?.purpose_split)?.map((x) => (
                <div key={x.name} className="flex justify-between text-sm">
                  <span>{x.name}</span>
                  <Badge variant="secondary">{x.count}</Badge>
                </div>
              ))}
            </div>
          ) : (
            <Empty />
          )}
        </Panel>
        <Panel title="Top actions closed">
          {dashboard.data?.main_actions.length ? (
            <div className="grid grid-cols-2 gap-3">
              {dashboard.data.main_actions.map((x) => (
                <div className="rounded-md border p-3" key={x.id}>
                  <div className="text-sm font-medium">{x.name}</div>
                  <div className="mt-2 text-xs text-muted-foreground">
                    <span className="text-emerald-600">{x.closed} closed</span> · {x.open} open
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <Empty />
          )}
        </Panel>
      </div>
      <Panel title={view === "activities" ? "Monthly activity log" : "Monthly meeting plan"}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                <th className="py-2">Date</th>
                <th>Activity / customer</th>
                <th>Purpose</th>
                <th>Mode</th>
                <th>Plan</th>
                <th>Actions</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {list.isLoading && (
                <tr>
                  <td colSpan={8}>
                    <Skeleton className="my-3 h-8 w-full" />
                  </td>
                </tr>
              )}
              {meetings.map((m) => (
                <tr key={m.id} className="border-b hover:bg-muted/20">
                  <td className="py-3 whitespace-nowrap">{fmt(m.starts_at)}</td>
                  <td>
                    <button
                      className="text-left font-medium hover:underline"
                      onClick={() => meta.data?.can_edit !== false && editMeeting(m)}
                    >
                      {m.title}
                    </button>
                    <div className="text-xs text-muted-foreground">
                      {m.is_internal ? "Internal" : m.customer_name}
                    </div>
                  </td>
                  <td>{m.purpose?.name ?? "—"}{view === "activities" && <Badge variant="secondary" className="ml-2 capitalize">{m.activity_type}</Badge>}</td>
                  <td className="capitalize">{m.meeting_mode}</td>
                  <td>
                    <Badge variant="outline">{m.is_planned ? "Planned" : "Unplanned"}</Badge>
                  </td>
                  <td>
                    {m.actions?.filter((a) => a.status === "completed").length ?? 0}/
                    {m.actions?.length ?? 0}
                  </td>
                  <td>
                    <Status value={m.status} />
                  </td>
                  <td>
                    <Button size="icon" variant="ghost" onClick={() => del.mutate(m.id)}>
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!list.isLoading && !meetings.length && (
            <div className="py-10 text-center text-sm text-muted-foreground">
              No {view === "activities" ? "activities" : "meetings"} in this month.
            </div>
          )}
        </div>
      </Panel>
      <Panel title="Customer visits vs purchase pattern">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                <th className="py-2">Customer</th>
                <th>Visits</th>
                <th>Repeat</th>
                <th>Orders</th>
                <th>Order value</th>
                <th>Last order</th>
                <th>Next follow-up</th>
              </tr>
            </thead>
            <tbody>
              {dashboard.data?.purchase_patterns.map((x) => (
                <tr key={x.customer_acumatica_id} className="border-b">
                  <td className="py-3 font-medium">{x.customer_name}</td>
                  <td>{x.visits}</td>
                  <td>{x.repeat_visits}</td>
                  <td>{x.month_order_count}</td>
                  <td>{x.month_order_value.toLocaleString()}</td>
                  <td>{x.last_order_date?.slice(0, 10) ?? "—"}</td>
                  <td>{x.next_follow_up ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {!dashboard.data?.purchase_patterns.length && <Empty />}
        </div>
      </Panel>
      <MeetingDialog
        open={open}
        setOpen={setOpen}
        form={form}
        setForm={setForm}
        meta={meta.data}
        purposeAllowsInternal={purpose?.allows_internal ?? false}
        editing={editing}
        pending={create.isPending || update.isPending || createActivity.isPending || updateActivity.isPending}
        activityMode={view === "activities"}
        submit={submit}
      />
      <Dialog open={targetOpen} onOpenChange={setTargetOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Monthly meeting target</DialogTitle>
          </DialogHeader>
          <Label>Target for {month}</Label>
          <Input
            type="number"
            min={0}
            value={target}
            onChange={(e) => setTarget(Number(e.target.value))}
          />
          <DialogFooter>
            <Button
              onClick={() =>
                owner &&
                saveTarget.mutate(
                  { user_id: owner, month, target },
                  { onSuccess: () => setTargetOpen(false) },
                )
              }
              disabled={!owner}
            >
              Save for selected consultant
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function MeetingDialog({
  open,
  setOpen,
  form,
  setForm,
  meta,
  purposeAllowsInternal,
  editing,
  pending,
  activityMode,
  submit,
}: any) {
  const set = (key: string, value: any) => setForm((f: any) => ({ ...f, [key]: value }));
  const [customerQ, setCustomerQ] = useState("");
  const [pickedCustomer, setPickedCustomer] = useState<KpMeetingCustomer | null>(null);
  const customerResults = useKpMeetingCustomerSearch(customerQ);
  useEffect(() => {
    if (!open) return;
    if (editing?.customer_acumatica_id) {
      setCustomerQ(editing.customer_name ?? editing.customer_acumatica_id);
    } else {
      setCustomerQ("");
      setPickedCustomer(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, editing?.id]);
  useEffect(() => {
    if (!editing?.customer_acumatica_id || pickedCustomer) return;
    const match = customerResults.data?.find(
      (c) => c.acumatica_id === editing.customer_acumatica_id,
    );
    if (match) setPickedCustomer(match);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerResults.data, editing?.id]);
  function pickCustomer(c: KpMeetingCustomer) {
    setPickedCustomer(c);
    setCustomerQ("");
    set("customer_acumatica_id", c.acumatica_id);
    set("customer_name", c.name);
  }
  const questionnaire = meta?.questionnaires?.find((q: any) => q.activity_type === form.activity_type && (q.purpose_id === Number(form.purpose_id) || q.purpose_id === null));
  useEffect(() => {
    if (!activityMode || !questionnaire || form.questionnaire_id) return;
    set("questionnaire_id", String(questionnaire.id));
  }, [activityMode, questionnaire?.id, form.questionnaire_id]);
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{editing ? "Update activity" : activityMode ? "Log an activity" : "Plan a meeting"}</DialogTitle>
        </DialogHeader>
        <div className="grid gap-4 py-2">
          {activityMode && <Field label="Activity type">
            <select className="h-9 w-full rounded-md border bg-background px-3 text-sm" value={form.activity_type} onChange={(e) => { set("activity_type", e.target.value); set("purpose_id", ""); set("questionnaire_id", ""); set("questionnaire_answers", {}); }}>
              <option value="phone">Phone</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="visit">Visit</option>
            </select>
          </Field>}
          <div className="grid gap-3 md:grid-cols-2">
            <Field label={activityMode ? "Activity title" : "Meeting title"}>
              <Input value={form.title} onChange={(e) => set("title", e.target.value)} />
            </Field>
            <Field label="Purpose">
              <select
                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                value={form.purpose_id}
                onChange={(e) => { set("purpose_id", e.target.value); set("questionnaire_id", ""); set("questionnaire_answers", {}); }}
              >
                <option value="">Select purpose</option>
                {meta?.purposes
                  .filter((p: any) => (p.is_active || p.id === editing?.purpose_id) && (!activityMode || !p.activity_types?.length || p.activity_types.includes(form.activity_type)))
                  .map((p: any) => (
                    <option value={p.id} key={p.id}>
                      {p.name}
                    </option>
                  ))}
              </select>
            </Field>
          </div>
          <div className="flex flex-wrap gap-5 text-sm">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.is_planned}
                onChange={(e) => set("is_planned", e.target.checked)}
              />
              Planned (PJP)
            </label>
            {purposeAllowsInternal && (
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={form.is_internal}
                  onChange={(e) => set("is_internal", e.target.checked)}
                />
                Internal meeting
              </label>
            )}
            {(!activityMode || ["meeting", "visit"].includes(form.activity_type)) && <label className="flex items-center gap-2">
              <input
                type="radio"
                checked={form.meeting_mode === "physical"}
                onChange={() => set("meeting_mode", "physical")}
              />
              Physical
            </label>}
            {(!activityMode || ["meeting", "visit"].includes(form.activity_type)) && <label className="flex items-center gap-2">
              <input
                type="radio"
                checked={form.meeting_mode === "virtual"}
                onChange={() => set("meeting_mode", "virtual")}
              />
              Virtual
            </label>}
          </div>
          {activityMode && questionnaire && <div className="rounded-lg border p-3">
            <div className="mb-3 font-medium">Required activity details</div>
            <div className="grid gap-3 md:grid-cols-2">
              {questionnaire.questions.map((question: any) => <QuestionField key={question.key} question={question} value={form.questionnaire_answers?.[question.key]} onChange={(value: unknown) => set("questionnaire_answers", {...form.questionnaire_answers, [question.key]: value})} />)}
            </div>
          </div>}
          {!form.is_internal && (
            <Field label="Assigned customer">
              <div className="relative">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  className="pl-8"
                  placeholder="Search your assigned customers"
                  value={customerQ}
                  onChange={(e) => {
                    setCustomerQ(e.target.value);
                    if (pickedCustomer) {
                      setPickedCustomer(null);
                      set("customer_acumatica_id", "");
                      set("customer_name", "");
                    }
                  }}
                />
                {customerResults.data && customerQ.trim().length >= 2 && (
                  <div className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover shadow">
                    {customerResults.data.map((c: KpMeetingCustomer) => (
                      <button
                        key={c.acumatica_id}
                        type="button"
                        onClick={() => pickCustomer(c)}
                        className="block w-full px-3 py-2 text-left text-sm hover:bg-muted"
                      >
                        <span className="font-medium">{c.name}</span>
                        <span className="ml-2 font-mono text-xs text-muted-foreground">
                          {c.acumatica_id}
                        </span>
                      </button>
                    ))}
                    {!customerResults.data.length && !customerResults.isLoading && (
                      <div className="px-3 py-2 text-sm text-muted-foreground">
                        No matching customers in your scope.
                      </div>
                    )}
                  </div>
                )}
              </div>
              {pickedCustomer && (
                <div className="mt-2 rounded-md border bg-muted/30 p-3 text-sm">
                  <div className="flex items-center justify-between">
                    <span className="font-medium">{pickedCustomer.name}</span>
                    <Badge variant="secondary">{pickedCustomer.customer_class ?? "—"}</Badge>
                  </div>
                  <div className="mt-1 grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
                    <div>
                      Customer ID: <span className="font-mono">{pickedCustomer.acumatica_id}</span>
                    </div>
                    <div>Status: {pickedCustomer.status ?? "—"}</div>
                    <div>Location: {pickedCustomer.location ?? "—"}</div>
                    <div>Payment terms: {pickedCustomer.payment_terms ?? "—"}</div>
                    <div>Phone: {pickedCustomer.phone ?? "—"}</div>
                    <div>Email: {pickedCustomer.email ?? "—"}</div>
                    <div className="sm:col-span-2">
                      Last meeting:{" "}
                      {pickedCustomer.last_meeting
                        ? `${fmt(pickedCustomer.last_meeting.starts_at)} · ${pickedCustomer.last_meeting.title} (${pickedCustomer.last_meeting.status}${pickedCustomer.last_meeting.owner_name ? ` · ${pickedCustomer.last_meeting.owner_name}` : ""})`
                        : "No prior meetings on record"}
                    </div>
                  </div>
                </div>
              )}
            </Field>
          )}
          <div className="grid gap-3 md:grid-cols-3">
            <Field label="Starts">
              <Input
                type="datetime-local"
                value={form.starts_at}
                onChange={(e) => set("starts_at", e.target.value)}
              />
            </Field>
            <Field label="Ends">
              <Input
                type="datetime-local"
                value={form.ends_at}
                onChange={(e) => set("ends_at", e.target.value)}
              />
            </Field>
            <Field label={form.meeting_mode === "virtual" ? "Meeting link" : "Location / site"}>
              <Input value={form.location} onChange={(e) => set("location", e.target.value)} />
            </Field>
          </div>
          <Field label="Accompanying consultants (acceptance required)">
            <div className="grid grid-cols-2 gap-2">
              {meta?.consultants.map((u: any) => (
                <label key={u.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={form.participant_user_ids.includes(u.id)}
                    onChange={(e) =>
                      set(
                        "participant_user_ids",
                        e.target.checked
                          ? [...form.participant_user_ids, u.id]
                          : form.participant_user_ids.filter((id: number) => id !== u.id),
                      )
                    }
                  />
                  {u.name}
                </label>
              ))}
            </div>
          </Field>
          <div className="grid gap-3 md:grid-cols-2">
            <Field label="Previous notes">
              <Textarea
                rows={3}
                value={form.previous_notes}
                onChange={(e) => set("previous_notes", e.target.value)}
              />
            </Field>
            <Field label="Current notes">
              <Textarea
                rows={3}
                value={form.current_notes}
                onChange={(e) => set("current_notes", e.target.value)}
              />
            </Field>
          </div>
          <Field label="Outcome">
            <Textarea
              rows={2}
              value={form.outcome}
              onChange={(e) => set("outcome", e.target.value)}
            />
          </Field>
          <div className="grid gap-3 md:grid-cols-2">
            <Field label="Follow-up date">
              <Input
                type="date"
                value={form.follow_up_date}
                onChange={(e) => set("follow_up_date", e.target.value)}
              />
            </Field>
            <Field label="Or no-follow-up reason">
              <Input
                value={form.no_follow_up_reason}
                onChange={(e) => set("no_follow_up_reason", e.target.value)}
              />
            </Field>
          </div>
          <div className="rounded-lg border p-3">
            <div className="mb-3 font-medium">B2B preparation & closure</div>
            <div className="grid gap-3 md:grid-cols-2">
              {[
                ["stakeholder_role", "Stakeholder role / seniority"],
                ["site_branch", "Site / branch"],
                ["opportunity_need", "Opportunity / need"],
                ["decision_process", "Decision process"],
                ["budget_timeline", "Budget / timeline"],
                ["competitor_presence", "Competitor presence"],
                ["product_fit", "Product / service fit"],
                ["next_commitment", "Next commitment"],
              ].map(([k, l]) => (
                <Field key={k} label={l}>
                  <Input value={form[k]} onChange={(e) => set(k, e.target.value)} />
                </Field>
              ))}
            </div>
          </div>
          <div className="rounded-lg border p-3">
            <div className="mb-2 flex items-center justify-between">
              <span className="font-medium">Meeting actions</span>
              <Button
                size="sm"
                variant="outline"
                onClick={() =>
                  set("actions", [
                    ...form.actions,
                    {
                      category_id: "",
                      description: "",
                      owner_user_id: "",
                      due_date: "",
                      status: "open",
                    },
                  ])
                }
              >
                <Plus className="mr-1 h-3 w-3" />
                Add action
              </Button>
            </div>
            {form.actions.map((a: any, i: number) => (
              <div key={i} className="mb-2 grid gap-2 md:grid-cols-[1fr_2fr_1fr_1fr_auto_auto]">
                <select
                  className="h-9 rounded-md border bg-background px-2 text-sm"
                  value={a.category_id}
                  onChange={(e) =>
                    set(
                      "actions",
                      form.actions.map((x: any, j: number) =>
                        j === i ? { ...x, category_id: e.target.value } : x,
                      ),
                    )
                  }
                >
                  <option value="">Other</option>
                  {meta?.action_categories
                    .filter((x: any) => x.is_active)
                    .map((x: any) => (
                      <option key={x.id} value={x.id}>
                        {x.name}
                      </option>
                    ))}
                </select>
                <Input
                  placeholder="Action description"
                  value={a.description}
                  onChange={(e) =>
                    set(
                      "actions",
                      form.actions.map((x: any, j: number) =>
                        j === i ? { ...x, description: e.target.value } : x,
                      ),
                    )
                  }
                />
                <Input
                  type="date"
                  value={a.due_date}
                  onChange={(e) =>
                    set(
                      "actions",
                      form.actions.map((x: any, j: number) =>
                        j === i ? { ...x, due_date: e.target.value } : x,
                      ),
                    )
                  }
                />
                <select
                  className="h-9 rounded-md border bg-background px-2 text-sm"
                  value={a.owner_user_id}
                  onChange={(e) =>
                    set(
                      "actions",
                      form.actions.map((x: any, j: number) =>
                        j === i ? { ...x, owner_user_id: e.target.value } : x,
                      ),
                    )
                  }
                >
                  <option value="">Meeting owner</option>
                  {meta?.consultants.map((u: any) => (
                    <option key={u.id} value={u.id}>
                      {u.name}
                    </option>
                  ))}
                </select>
                <label className="flex h-9 items-center gap-1 text-xs">
                  <input
                    type="checkbox"
                    checked={a.status === "completed"}
                    onChange={(e) =>
                      set(
                        "actions",
                        form.actions.map((x: any, j: number) =>
                          j === i ? { ...x, status: e.target.checked ? "completed" : "open" } : x,
                        ),
                      )
                    }
                  />
                  Closed
                </label>
                <Button
                  size="icon"
                  variant="ghost"
                  onClick={() =>
                    set(
                      "actions",
                      form.actions.filter((_: any, j: number) => j !== i),
                    )
                  }
                >
                  <XCircle className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          {editing && (
            <Button variant="secondary" onClick={() => submit("cancelled")}>
              Cancel activity
            </Button>
          )}
          <Button variant="outline" onClick={() => submit("scheduled")} disabled={pending}>
            Save planned
          </Button>
          <Button onClick={() => submit("completed")} disabled={pending}>
            <CheckCircle2 className="mr-1 h-4 w-4" />
            Close activity
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
function Field({ label, children }: { label: string; children: any }) {
  return (
    <div>
      <Label className="mb-1 block">{label}</Label>
      {children}
    </div>
  );
}
function Panel({ title, children }: { title: string; children: any }) {
  return (
    <section className="rounded-lg border bg-card p-4 shadow-sm">
      <h2 className="mb-3 font-semibold">{title}</h2>
      {children}
    </section>
  );
}
function Stat({
  icon: Icon,
  label,
  value,
  sub,
}: {
  icon: any;
  label: string;
  value: string;
  sub: string;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-xs uppercase text-muted-foreground">
        <Icon className="h-4 w-4" />
        {label}
      </div>
      <div className="mt-2 text-2xl font-semibold">{value}</div>
      <div className="mt-1 text-xs text-muted-foreground">{sub}</div>
    </div>
  );
}
function Status({ value }: { value: string }) {
  return (
    <Badge variant={value === "completed" ? "default" : "outline"} className="capitalize">
      {value}
    </Badge>
  );
}
function Empty() {
  return (
    <div className="py-6 text-center text-sm text-muted-foreground">No data for this month.</div>
  );
}

function QuestionField({ question, value, onChange }: { question: any; value: unknown; onChange: (value: unknown) => void }) {
  const label = `${question.label}${question.required ? " *" : ""}`;
  if (question.type === "boolean") return <Field label={label}><label className="flex h-9 items-center gap-2"><input type="checkbox" checked={Boolean(value)} onChange={(e) => onChange(e.target.checked)} /> Yes</label></Field>;
  if (question.type === "select") return <Field label={label}><select className="h-9 w-full rounded-md border bg-background px-3 text-sm" value={String(value ?? "")} onChange={(e) => onChange(e.target.value)}><option value="">Select</option>{(question.options ?? []).map((option: string) => <option key={option}>{option}</option>)}</select></Field>;
  if (question.type === "multi_select") return <Field label={label}><div className="flex flex-wrap gap-2">{(question.options ?? []).map((option: string) => { const selected = Array.isArray(value) ? value : []; return <label key={option} className="flex items-center gap-1 text-sm"><input type="checkbox" checked={selected.includes(option)} onChange={(e) => onChange(e.target.checked ? [...selected, option] : selected.filter((x) => x !== option))} />{option}</label>; })}</div></Field>;
  return <Field label={label}><Input type={question.type === "number" ? "number" : question.type === "date" ? "date" : "text"} value={String(value ?? "")} onChange={(e) => onChange(question.type === "number" && e.target.value !== "" ? Number(e.target.value) : e.target.value)} /></Field>;
}

function FollowUpList({ data }: { data: any }) {
  const groups = [["Overdue", data?.overdue ?? [], "text-red-600"], ["Due today", data?.due_today ?? [], "text-amber-600"], ["Upcoming", data?.upcoming ?? [], "text-emerald-600"]] as const;
  if (!groups.some(([, rows]) => rows.length)) return <Empty />;
  return <div className="space-y-3">{groups.map(([label, rows, colour]) => rows.length > 0 && <div key={label}><div className={`mb-1 text-xs font-semibold uppercase ${colour}`}>{label} · {rows.length}</div>{rows.slice(0, 5).map((action: any) => <div key={action.id} className="flex justify-between gap-3 border-b py-2 text-sm"><div><div className="font-medium">{action.description}</div><div className="text-xs text-muted-foreground">{action.meeting?.customer_name ?? action.meeting?.title ?? "Activity"} · {action.owner?.name ?? "Unassigned"}</div></div><span className="whitespace-nowrap text-xs">{action.due_date?.slice(0, 10) ?? "No date"}</span></div>)}</div>)}</div>;
}

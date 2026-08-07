import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { toast } from "sonner";

export type KpAssignee = {
  user_id: number | null;
  rep_code: string | null;
  name: string | null;
  email: string | null;
  label: string;
  source: string;
};

export type KpDormantCustomer = {
  acumatica_id: string;
  name: string;
  customer_class: string | null;
  status: string | null;
  email: string | null;
  phone: string | null;
  last_order_date: string | null;
  lifetime_order_count: number;
  months_idle: number | null;
  never_ordered: boolean;
  assignee: KpAssignee | null;
};

export type KpDormantPage = {
  data: KpDormantCustomer[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
  window: {
    months: number;
    from: string;
    current_month_start: string;
    label: string;
  };
};

export function useKpDormantCustomers(params: {
  q?: string;
  page?: number;
  per_page?: number;
  months?: number;
}) {
  const qs = new URLSearchParams();
  if (params.q?.trim()) qs.set("q", params.q.trim());
  if (params.page) qs.set("page", String(params.page));
  if (params.per_page) qs.set("per_page", String(params.per_page));
  if (params.months) qs.set("months", String(params.months));

  return useQuery({
    queryKey: ["kp-dormant", params],
    queryFn: () => apiFetch<KpDormantPage>(`kp/dormant-customers?${qs}`),
  });
}

export type KpDormantAttempt = {
  id: number;
  customer_acumatica_id: string;
  contacted: boolean;
  customer_contact_id: number | null;
  outcome: string;
  comments: string | null;
  attempted_at: string;
  actor_user_id: number;
};

export function useKpDormantAttempts(customerId: string | null, enabled = true) {
  return useQuery({
    queryKey: ["kp-dormant-attempts", customerId],
    queryFn: () => apiFetch<KpDormantAttempt[]>(`kp/dormant-customers/${encodeURIComponent(customerId!)}/attempts`),
    enabled: !!customerId && enabled,
  });
}

export function useLogKpDormantFeedback(customerId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: {
      contacted: boolean;
      customer_contact_id?: number | null;
      outcome: string;
      comments?: string | null;
      attempted_at?: string | null;
    }) =>
      apiFetch<KpDormantAttempt>(`kp/dormant-customers/${encodeURIComponent(customerId)}/feedback`, {
        method: "POST",
        body,
      }),
    onSuccess: () => {
      toast.success("Contact attempt logged");
      qc.invalidateQueries({ queryKey: ["kp-dormant-attempts", customerId] });
    },
    onError: (e: Error) => toast.error(e.message || "Unable to log contact attempt"),
  });
}

export function useHandoffKpDormant(customerId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch(`kp/dormant-customers/${encodeURIComponent(customerId)}/handoff`, { method: "POST" }),
    onSuccess: () => {
      toast.success("Handed off to Calltronix");
      qc.invalidateQueries({ queryKey: ["kp-dormant"] });
    },
    onError: (e: Error) => toast.error(e.message || "Unable to hand off — check contact and attempt history."),
  });
}

export type KpMeeting = {
  id: number;
  title: string;
  activity_type: "phone" | "email" | "meeting" | "visit";
  notes: string | null;
  purpose_id: number | null;
  purpose?: KpMeetingPurpose | null;
  meeting_mode: "virtual" | "physical";
  is_internal: boolean;
  is_planned: boolean;
  previous_notes: string | null;
  current_notes: string | null;
  outcome: string | null;
  follow_up_date: string | null;
  no_follow_up_reason: string | null;
  b2b_details?: Record<string, string> | null;
  questionnaire_id?: number | null;
  questionnaire_version?: number | null;
  questionnaire_answers?: Record<string, unknown> | null;
  questionnaire?: KpActivityQuestionnaire | null;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  starts_at: string;
  ends_at: string | null;
  location: string | null;
  status: string;
  owner_user_id: number | null;
  owner?: { id: number; name: string; email: string | null; rep_code: string | null } | null;
  participants?: KpMeetingParticipant[];
  actions?: KpMeetingAction[];
};

export type KpMeetingPurpose = {
  id: number;
  name: string;
  activity_types?: Array<"phone" | "email" | "meeting" | "visit"> | null;
  allows_internal: boolean;
  customer_required?: boolean;
  is_active: boolean;
  sort_order: number;
};
export type KpActivityQuestion = { key: string; label: string; type: "text" | "select" | "multi_select" | "number" | "date" | "boolean"; required?: boolean; options?: string[] };
export type KpActivityQuestionnaire = { id: number; purpose_id: number | null; activity_type: KpMeeting["activity_type"]; version: number; questions: KpActivityQuestion[]; is_active: boolean };
export type KpActionCategory = {
  id: number;
  name: string;
  is_main: boolean;
  is_active: boolean;
  sort_order: number;
};
export type KpMeetingAction = {
  id: number;
  category_id: number | null;
  category_snapshot: string;
  description: string;
  owner_user_id: number | null;
  due_date: string | null;
  status: "open" | "completed";
  owner?: { id: number; name: string } | null;
};
export type KpMeetingParticipant = {
  id: number;
  user_id: number;
  role: string;
  status: "pending" | "accepted" | "declined" | "cancelled";
  user?: { id: number; name: string; email: string | null };
};

export type KpMeetingInput = {
  title: string;
  activity_type?: KpMeeting["activity_type"];
  notes?: string | null;
  purpose_id: number;
  meeting_mode: "virtual" | "physical";
  is_internal: boolean;
  is_planned: boolean;
  previous_notes?: string | null;
  current_notes?: string | null;
  outcome?: string | null;
  follow_up_date?: string | null;
  no_follow_up_reason?: string | null;
  b2b_details?: Record<string, string>;
  questionnaire_id?: number | null;
  questionnaire_answers?: Record<string, unknown>;
  owner_user_id?: number;
  participant_user_ids?: number[];
  actions?: Array<{
    category_id?: number | null;
    description: string;
    owner_user_id?: number | null;
    due_date?: string | null;
    status?: string;
  }>;
  customer_acumatica_id?: string | null;
  customer_name?: string | null;
  starts_at: string;
  ends_at?: string | null;
  location?: string | null;
  status?: string;
};

export function useKpMeetings(
  params: {
    q?: string;
    from?: string;
    to?: string;
    month?: string;
    owner_user_id?: number;
    status?: string;
    purpose_id?: number;
    page?: number;
    per_page?: number;
  } = {},
) {
  const qs = new URLSearchParams();
  if (params.q?.trim()) qs.set("q", params.q.trim());
  if (params.from) qs.set("from", params.from);
  if (params.to) qs.set("to", params.to);
  if (params.month) qs.set("month", params.month);
  if (params.owner_user_id) qs.set("owner_user_id", String(params.owner_user_id));
  if (params.status) qs.set("status", params.status);
  if (params.purpose_id) qs.set("purpose_id", String(params.purpose_id));
  if (params.page) qs.set("page", String(params.page));
  if (params.per_page) qs.set("per_page", String(params.per_page));

  return useQuery({
    queryKey: ["kp-meetings", params],
    queryFn: () =>
      apiFetch<{
        data: KpMeeting[];
        current_page: number;
        last_page: number;
        total: number;
      }>(`kp/meetings?${qs}`),
  });
}

export function useCreateKpMeeting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: KpMeetingInput) =>
      apiFetch<KpMeeting>("kp/meetings", { method: "POST", body }),
    onSuccess: () => {
      toast.success("Meeting scheduled");
      qc.invalidateQueries({ queryKey: ["kp-meetings"] });
      qc.invalidateQueries({ queryKey: ["kp-meetings-dashboard"] });
      qc.invalidateQueries({ queryKey: ["kp-calendar"] });
    },
    onError: (e: Error) => toast.error(e.message || "Unable to save meeting"),
  });
}

export function useUpdateKpMeeting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...body }: Partial<KpMeetingInput> & { id: number }) =>
      apiFetch<KpMeeting>(`kp/meetings/${id}`, { method: "PUT", body }),
    onSuccess: () => {
      toast.success("Meeting updated");
      qc.invalidateQueries({ queryKey: ["kp-meetings"] });
      qc.invalidateQueries({ queryKey: ["kp-meetings-dashboard"] });
      qc.invalidateQueries({ queryKey: ["kp-calendar"] });
    },
    onError: (e: Error) => toast.error(e.message || "Unable to update meeting"),
  });
}

export type KpMeetingsMeta = {
  purposes: KpMeetingPurpose[];
  questionnaires?: KpActivityQuestionnaire[];
  action_categories: KpActionCategory[];
  consultants: Array<{
    id: number;
    name: string;
    email: string | null;
    role: string;
    org_level: string | null;
  }>;
  is_admin: boolean;
  can_edit?: boolean;
  allowed_scopes?: Array<"self" | "team" | "department" | "organization">;
  departments?: Array<{ id: number; name: string; slug: string }>;
  sectors?: string[];
  current_user_id: number;
};

export function useKpActivities(params: { q?: string; month?: string; owner_user_id?: number; status?: string; activity_type?: string; department_id?: number; sector?: string } = {}) {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([key,value]) => { if (value !== undefined && value !== "") qs.set(key,String(value)); });
  return useQuery({ queryKey:["kp-activities",params], queryFn:()=>apiFetch<{data:KpMeeting[];current_page:number;last_page:number;total:number}>(`kp/activities?${qs}`) });
}

export function useCreateKpActivity() {
  const qc=useQueryClient();
  return useMutation({ mutationFn:(body:KpMeetingInput)=>apiFetch<KpMeeting>("kp/activities",{method:"POST",body}), onSuccess:()=>{toast.success("Activity saved");qc.invalidateQueries({queryKey:["kp-activities"]});qc.invalidateQueries({queryKey:["kp-activities-dashboard"]});qc.invalidateQueries({queryKey:["kp-activity-follow-ups"]});}, onError:(e:Error)=>toast.error(e.message||"Unable to save activity") });
}
export function useUpdateKpActivity() {
  const qc=useQueryClient();
  return useMutation({ mutationFn:({id,...body}:Partial<KpMeetingInput>&{id:number})=>apiFetch<KpMeeting>(`kp/activities/${id}`,{method:"PUT",body}), onSuccess:()=>{toast.success("Activity updated");qc.invalidateQueries({queryKey:["kp-activities"]});qc.invalidateQueries({queryKey:["kp-activities-dashboard"]});qc.invalidateQueries({queryKey:["kp-activity-follow-ups"]});}, onError:(e:Error)=>toast.error(e.message||"Unable to update activity") });
}
export type KpActivityDashboard = { month:string;scope:string;allowed_scopes:string[];metrics:{total:number;planned:number;completed:number;missed:number;cancelled:number;unplanned:number;adherence_percent:number;active_consultants:number;customers_engaged:number;overdue_follow_ups:number};activity_split:Array<{name:string;count:number}>;purpose_split:Array<{name:string;count:number}>;consultants:Array<Record<string,number|string>>;departments:Array<Record<string,number|string>> };
export function useKpActivitiesDashboard(params:{month:string;scope:string;owner_user_id?:number;activity_type?:string;department_id?:number;sector?:string}) { const qs=new URLSearchParams({month:params.month,scope:params.scope});Object.entries(params).forEach(([key,value])=>{if(value!==undefined&&value!=="")qs.set(key,String(value));});return useQuery({queryKey:["kp-activities-dashboard",params],queryFn:()=>apiFetch<KpActivityDashboard>(`kp/activities/dashboard?${qs}`)}); }
export type KpActivityFollowUps={overdue:KpMeetingAction[];due_today:KpMeetingAction[];upcoming:KpMeetingAction[];completed:KpMeetingAction[]};
export function useKpActivityFollowUps(owner_user_id?:number){const qs=owner_user_id?`?owner_user_id=${owner_user_id}`:"";return useQuery({queryKey:["kp-activity-follow-ups",owner_user_id],queryFn:()=>apiFetch<KpActivityFollowUps>(`kp/activities/follow-ups${qs}`)});}

export type KpMeetingCustomer = {
  acumatica_id: string;
  name: string;
  customer_class: string | null;
  status: string | null;
  email: string | null;
  phone: string | null;
  payment_terms: string | null;
  location: string | null;
  last_meeting: {
    starts_at: string;
    title: string;
    status: string;
    owner_name: string | null;
  } | null;
};

export function useKpMeetingCustomerSearch(q: string) {
  return useQuery({
    queryKey: ["kp-meetings-customer-search", q],
    queryFn: () =>
      apiFetch<KpMeetingCustomer[]>(`kp/meetings-customer-search?q=${encodeURIComponent(q)}`),
    enabled: q.trim().length >= 2,
  });
}

export type KpMeetingDashboard = {
  month: string;
  metrics: {
    target: number;
    achieved: number;
    total: number;
    unique_customers: number;
    repeat_visits: number;
    planned: number;
    completed_planned: number;
    missed: number;
    cancelled: number;
    unplanned: number;
    adherence_percent: number;
  };
  purpose_split: Array<{ name: string; count: number }>;
  main_actions: Array<{ id: number; name: string; open: number; closed: number }>;
  purchase_patterns: Array<{
    customer_acumatica_id: string;
    customer_name: string;
    visits: number;
    repeat_visits: number;
    last_visit: string | null;
    next_follow_up: string | null;
    month_order_count: number;
    month_order_value: number;
    last_order_date: string | null;
  }>;
  rankings: Array<{ user_id: number; name: string; completed: number; planned: number }>;
  overdue_actions: KpMeetingAction[];
};

export function useKpMeetingsMeta() {
  return useQuery({
    queryKey: ["kp-meetings-meta"],
    queryFn: () => apiFetch<KpMeetingsMeta>("kp/meetings-meta"),
  });
}
export function useKpMeetingsDashboard(params: { month: string; owner_user_id?: number }) {
  const qs = new URLSearchParams({ month: params.month });
  if (params.owner_user_id) qs.set("owner_user_id", String(params.owner_user_id));
  return useQuery({
    queryKey: ["kp-meetings-dashboard", params],
    queryFn: () => apiFetch<KpMeetingDashboard>(`kp/meetings-dashboard?${qs}`),
  });
}
export function useRespondToKpMeeting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: "accepted" | "declined" }) =>
      apiFetch(`kp/meeting-participants/${id}/respond`, { method: "POST", body: { status } }),
    onSuccess: () => {
      toast.success("Invitation updated");
      qc.invalidateQueries({ queryKey: ["kp-meetings"] });
      qc.invalidateQueries({ queryKey: ["kp-meetings-dashboard"] });
    },
  });
}
export function useSaveKpTarget() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { user_id: number; month: string; target: number }) =>
      apiFetch("kp/meeting-target", { method: "PUT", body }),
    onSuccess: () => {
      toast.success("Target saved");
      qc.invalidateQueries({ queryKey: ["kp-meetings-dashboard"] });
    },
  });
}

export function useDeleteKpMeeting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch(`kp/meetings/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Meeting deleted");
      qc.invalidateQueries({ queryKey: ["kp-meetings"] });
      qc.invalidateQueries({ queryKey: ["kp-meetings-dashboard"] });
      qc.invalidateQueries({ queryKey: ["kp-calendar"] });
    },
  });
}

export type KpCalendarEvent = {
  id: string;
  source: "orderwatch" | "outlook" | string;
  title: string;
  starts_at: string | null;
  ends_at: string | null;
  location: string | null;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  status: string | null;
  notes: string | null;
  web_link?: string | null;
};

export type KpCalendarResponse = {
  month: string;
  from: string;
  to: string;
  events: KpCalendarEvent[];
  outlook: {
    connected: boolean;
    mailbox: string | null;
    error: string | null;
    hint: string | null;
  };
};

export function useKpCalendar(month: string) {
  return useQuery({
    queryKey: ["kp-calendar", month],
    queryFn: () => apiFetch<KpCalendarResponse>(`kp/calendar?month=${encodeURIComponent(month)}`),
  });
}

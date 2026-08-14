/**
 * API client — all requests to /api/v1/*.
 *
 * Base URL is read from VITE_API_URL (default: http://localhost:8000).
 * The Sanctum token is stored in localStorage under "auth_token" and
 * attached as a Bearer header to every request after login.
 */

const BASE = (import.meta.env.VITE_API_URL ?? "http://localhost:8000") + "/api/v1";

// ── Token helpers ─────────────────────────────────────────────────────────────

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem("auth_token");
}

export function setToken(token: string): void {
  localStorage.setItem("auth_token", token);
}

export function clearToken(): void {
  localStorage.removeItem("auth_token");
}

// ── Error type ────────────────────────────────────────────────────────────────

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors?: Record<string, string[]>,
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** Return the first validation error message for a given field, or null. */
  fieldError(field: string): string | null {
    return this.errors?.[field]?.[0] ?? null;
  }

  /** Return a single readable string covering all validation errors. */
  get summary(): string {
    if (!this.errors) return this.message;
    return Object.values(this.errors).flat().join(" · ");
  }
}

// ── Core fetch wrapper ────────────────────────────────────────────────────────

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  isFormData = false,
): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {
    Accept: "application/json",
  };
  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }
  if (body && !isFormData) {
    headers["Content-Type"] = "application/json";
  }

  const res = await fetch(`${BASE}${path}`, {
    method,
    headers,
    body: isFormData
      ? (body as FormData)
      : body !== undefined
        ? JSON.stringify(body)
        : undefined,
  });

  if (res.status === 204) return undefined as T;

  const json = await res.json().catch(() => ({}));

  if (!res.ok) {
    throw new ApiError(
      res.status,
      json.message ?? `HTTP ${res.status}`,
      json.errors ?? undefined,
    );
  }

  return json as T;
}

// Convenience helpers
const get = <T>(path: string) => request<T>("GET", path);
const post = <T>(path: string, body?: unknown) => request<T>("POST", path, body);
const postForm = <T>(path: string, body: FormData) => request<T>("POST", path, body, true);
const patch = <T>(path: string, body?: unknown) => request<T>("PATCH", path, body);

// ── Auth ──────────────────────────────────────────────────────────────────────

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: "client" | "freelancer" | "admin";
  created_at: string;
}

export interface AuthResponse {
  user: AuthUser;
  token: string;
}

export const auth = {
  register: (data: {
    name: string;
    email: string;
    password: string;
    role: "client" | "freelancer";
  }) => post<AuthResponse>("/register", data),

  login: (data: { email: string; password: string }) =>
    post<AuthResponse>("/login", data),

  logout: () => post<{ message: string }>("/logout"),

  me: () => get<AuthUser>("/me"),
};

// ── Contracts ─────────────────────────────────────────────────────────────────

export interface ApiContract {
  id: string;
  client_id: number;
  freelancer_id: number;
  title: string;
  scope: string;
  status: string;
  total_amount: string;
  currency: string;
  start_date: string | null;
  end_date: string | null;
  terms: string | null;
  created_at: string;
  updated_at: string;
  client?: ApiUser;
  freelancer?: ApiUser;
  milestones?: ApiMilestone[];
  signatures?: ApiSignature[];
  disputes?: ApiDispute[];
}

export interface ApiUser {
  id: number;
  name: string;
  email: string;
  role: string;
}

export interface ApiSignature {
  id: number;
  contract_id: string;
  user_id: number;
  signed_name: string;
  ip_address: string;
  signed_at: string;
  user?: ApiUser;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export const contracts = {
  list: (params?: { status?: string; page?: number }) => {
    const qs = new URLSearchParams();
    if (params?.status) qs.set("status", params.status);
    if (params?.page) qs.set("page", String(params.page));
    const q = qs.toString();
    return get<PaginatedResponse<ApiContract>>(`/dashboard${q ? `?${q}` : ""}`);
  },

  create: (data: {
    freelancer_id: number;
    title: string;
    scope: string;
    total_amount: number;
    currency?: string;
    start_date?: string;
    end_date?: string;
    terms?: string;
    milestones?: Array<{
      title: string;
      description?: string;
      amount: number;
      due_date?: string;
    }>;
  }) => post<ApiContract>("/contracts", data),

  show: (id: string) =>
    get<ApiContract>(`/contracts/${id}`),

  send: (id: string) =>
    post<{ message: string; contract: ApiContract }>(`/contracts/${id}/send`),

  sign: (id: string, signed_name: string) =>
    post<{ message: string; signature: ApiSignature; contract: ApiContract }>(
      `/contracts/${id}/sign`,
      { signed_name },
    ),
};

// ── Milestones ────────────────────────────────────────────────────────────────

export interface ApiMilestone {
  id: string;
  contract_id: string;
  title: string;
  description: string | null;
  amount: string;
  due_date: string | null;
  order: number;
  status: string;
  submitted_at: string | null;
  approved_at: string | null;
  submission_notes: string | null;
}

export const milestones = {
  submit: (id: string, data?: { submission_notes?: string }) =>
    post<{ message: string; milestone: ApiMilestone }>(`/milestones/${id}/submit`, data),

  approve: (id: string) =>
    post<{ message: string; milestone: ApiMilestone }>(`/milestones/${id}/approve`),

  dispute: (id: string, reason: string) =>
    post<{ message: string; dispute: ApiDispute }>(`/milestones/${id}/dispute`, { reason }),
};

// ── Disputes ──────────────────────────────────────────────────────────────────

export interface ApiDispute {
  id: string;
  contract_id: string;
  milestone_id: string | null;
  raised_by: number;
  status: string;
  reason: string;
  resolution_notes: string | null;
  resolved_at: string | null;
  created_at: string;
  contract?: { id: string; title: string; status: string };
  milestone?: { id: string; title: string; amount: string };
  raisedBy?: ApiUser;
  mediator?: ApiUser | null;
  evidence?: ApiEvidence[];
}

export interface ApiEvidence {
  id: number;
  dispute_id: string;
  user_id: number;
  message: string | null;
  file_name: string | null;
  file_mime: string | null;
  file_size: number | null;
  created_at: string;
  user?: ApiUser;
}

export const disputes = {
  show: (id: string) => get<ApiDispute>(`/disputes/${id}`),

  submitEvidence: (id: string, data: { message?: string; file?: File }) => {
    const form = new FormData();
    if (data.message) form.append("message", data.message);
    if (data.file) form.append("file", data.file);
    return postForm<{ message: string; evidence: ApiEvidence }>(
      `/disputes/${id}/evidence`,
      form,
    );
  },

  resolve: (id: string, data: { status: string; resolution_notes: string }) =>
    patch<{ message: string; dispute: ApiDispute }>(`/disputes/${id}/resolve`, data),
};

// ── Transactions ──────────────────────────────────────────────────────────────

export interface ApiTransaction {
  id: string;
  contract_id: string;
  milestone_id: string | null;
  type: string;
  amount: string;
  currency: string;
  status: string;
  notes: string | null;
  created_at: string;
  contract?: { id: string; title: string };
  milestone?: { id: string; title: string } | null;
}

export const transactions = {
  list: (params?: { contract_id?: string; type?: string; page?: number }) => {
    const qs = new URLSearchParams();
    if (params?.contract_id) qs.set("contract_id", params.contract_id);
    if (params?.type) qs.set("type", params.type);
    if (params?.page) qs.set("page", String(params.page));
    const q = qs.toString();
    return get<PaginatedResponse<ApiTransaction>>(`/transactions${q ? `?${q}` : ""}`);
  },
};

// ── Profiles ──────────────────────────────────────────────────────────────────

export interface ApiProfile {
  id: number;
  name: string;
  role: string;
  member_since: string;
  verified_types: string[];
  reputation: {
    completed_count: number;
    disputed_count: number;
    on_time_rate: string;
    avg_rating: string;
    total_earned: string;
    total_spent: string;
  } | null;
}

export const profiles = {
  show: (userId: number | string) => get<ApiProfile>(`/users/${userId}/profile`),
};
